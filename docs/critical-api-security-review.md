# Critical API Security Review

**Plugin:** WordPress Cliniko Stripe Plugin  
**Review date:** 7 October 2026  
**Audience:** Management, product owners, engineering, operations, and privacy/compliance  
**Review type:** Static source-code review  
**Important scope note:** `docs/openapi.yaml` was intentionally excluded because it is out of date. This report is based on the routes and handlers currently registered by the PHP source code.

**Implementation update — 8 October 2026:** This report preserves the findings from the original review. The subsequent API Access implementation in the working tree retires the affected legacy operations with HTTP 410, removes patient-profile updates from queued legacy form work and booking finalization, and removes the patient-history bearer-token handoff. Regression tests cover the containment. This does not establish that the changes have been deployed or that historical exploitation did not occur. Deployment/migration details are in [API Access setup](api-access-setup.md).

## Executive summary

The plugin exposes a working API through WordPress at:

```text
https://<website>/wp-json/v1/...
https://<website>/wp-json/v2/...
```

This API can support website and mobile-app features such as availability searches, bookings, payment processing, patient history, and patient accounts. However, the review found **three critical security issues** in publicly reachable flows:

| ID | Finding | Business risk | Recommended immediate decision |
|---|---|---|---|
| SEC-01 | A public legacy form route can update an existing Cliniko patient before ownership is checked | Patient information can be corrupted without authentication; possible clinical, privacy, and operational consequences | Block the legacy route and pause/review its pending jobs until fixed |
| SEC-02 | A public legacy Tyro route accepts an unverified transaction ID as payment | Paid appointments can potentially be booked without payment | Block the legacy route and use only the verified v2 Tyro flow |
| SEC-03 | The patient-history handoff can return a patient's bearer token to someone who chose the request ID | Exposure of appointment history, patient details, and previous form contents | Disable the status-token handoff until it is redesigned |

These findings confirm that the vulnerable code paths exist. They do **not**, by themselves, prove that an attacker has used them. A separate review of web, WordPress, Action Scheduler, Cliniko, Stripe, and Tyro records is required to determine whether exploitation has occurred.

## Recommended management response

### Within 24 hours

1. Temporarily block these routes at WordPress or the web application firewall:

   ```text
   POST /wp-json/v1/send-patient-form
   POST /wp-json/v1/tyrohealth/charge
   GET  /wp-json/v2/patient-access/request-status
   POST /wp-json/v2/patient-access/request-complete
   ```

2. Preserve relevant logs before normal retention or cleanup removes them.
3. Review pending `cliniko_async_create_patient_form` Action Scheduler jobs before allowing them to run. Do not delete them without first preserving enough information for audit and legitimate-customer recovery.
4. Compare recent Tyro-based paid bookings with transactions confirmed directly by Tyro.
5. Ask the privacy/compliance owner to determine whether an incident assessment is required, particularly for patient-history and patient-record access.

### Within 2–7 days

1. Implement the fixes described under each finding.
2. Add automated security tests for all three attack scenarios.
3. Deploy to a staging environment and verify the fixes against test Cliniko and payment accounts.
4. Rotate credentials only if log review indicates possible credential exposure. These findings do not directly expose the Cliniko, Stripe, or Tyro administrator secrets.

### Within 2–4 weeks

1. Retire the legacy v1 mutation/payment flows after confirming all frontends use v2.
2. Publish a new, code-derived API contract for mobile and third-party developers.
3. Use a plugin-specific REST namespace, for example `/wp-json/wp-cliniko/v2`, to reduce route collisions and make ownership clear.
4. Establish an API security checklist covering authentication, patient ownership, payment verification, replay protection, rate limiting, logging, and deprecation.

---

## SEC-01 — Public route can modify an existing Cliniko patient

**Severity:** Critical  
**Affected route:** `POST /wp-json/v1/send-patient-form`  
**Authentication required:** No  
**Status:** Confirmed in the current source code

### Plain-language explanation

The route is intended to submit a patient form. The background worker looks up a Cliniko patient using the email address supplied by the caller. It then updates that patient's Cliniko record using caller-supplied data.

The worker checks the booking only **after** the patient update. If the booking does not belong to the patient, the form operation fails, but the patient update has already happened.

### Example attack scenario

1. An attacker learns a patient's email address through ordinary means such as an email leak, social media, correspondence, or prior contact.
2. The attacker sends a request to the public form route using that email address.
3. The request contains modified patient details such as name, phone, address, date of birth, or Medicare-related fields.
4. The plugin returns that the work has been queued.
5. The background worker finds the patient by email and updates their Cliniko record.
6. The later booking check may reject the form, but it does not undo the earlier patient update.

### Potential impact

- Incorrect contact, identity, address, date-of-birth, or Medicare information in Cliniko.
- Messages or appointment information being sent to the wrong contact details.
- Staff making decisions based on corrupted patient information.
- Patient safety and continuity-of-care concerns where incorrect identity or administrative information affects treatment workflows.
- Privacy, regulatory, contractual, and reputational consequences.
- Manual recovery work to identify the authoritative data and restore affected records.

### Technical tracking information

- Route registration: `src/Routes/PublicGuestRoutes.php`, route `/send-patient-form`.
- Request handler: `src/Controller/ClinikoController.php`, method `createPatientForm()`.
- Vulnerable ordering: `src/Workers/ClinikoPatientFormWorker.php`:
  - patient lookup by submitted email;
  - `Patient::update(...)` is called;
  - booking/patient ownership is checked afterwards.
- The form payload sanitizer focuses on form content and does not provide a strict allowlist for the patient update in this legacy flow.

### Immediate containment

1. Block `POST /wp-json/v1/send-patient-form`.
2. Identify which website components, embeds, or third-party clients still use it.
3. Review queued and recently completed `cliniko_async_create_patient_form` jobs.
4. Preserve request and worker logs for investigation.
5. If business operations require a temporary submission route, use a flow that does not update an existing patient until ownership has been proven.

### Permanent action plan

1. Perform the booking/patient ownership check before any patient update.
2. Do not treat knowledge of an email address as proof of authority over a patient record.
3. Require a signed, short-lived, booking-specific authorization token or an authenticated verified-patient session.
4. Allowlist every patient field that the route is permitted to change. Drop all unknown properties.
5. Separate “submit a form” from “update a patient profile”; a form-submission endpoint should not silently become a profile-update endpoint.
6. Use the v2 booking-attempt flow for new clients and retire the legacy route.
7. Where feasible, make updates reversible or record before/after values in an audit trail.

### Fix verification checklist

- A request containing a real patient's email but no valid booking authority cannot change any Cliniko patient field.
- A failed booking check leaves the Cliniko patient unchanged.
- Unknown or sensitive fields are rejected rather than forwarded.
- Valid, authorized form submissions continue to work.
- Tests confirm that authorization happens before every external write.

### Investigation guidance

Review:

- Web access logs for calls to `/wp-json/v1/send-patient-form`.
- WordPress/debug logs containing dispatch and worker activity.
- Action Scheduler history for `cliniko_async_create_patient_form`.
- Cliniko patient audit/history records for unexpected changes following those calls.
- Repeated requests involving the same email, unusual IP addresses, or form failures followed by patient-data changes.

---

## SEC-02 — Legacy Tyro route can bypass payment verification

**Severity:** Critical  
**Affected route:** `POST /wp-json/v1/tyrohealth/charge`  
**Authentication required:** No  
**Status:** Confirmed in the current source code

### Plain-language explanation

For a paid appointment, the legacy route checks only whether the caller supplied a transaction ID. It does not ask Tyro whether that transaction exists, succeeded, belongs to this merchant, matches the expected amount, or matches the booking reference.

Any non-empty value can therefore be passed to the scheduling worker as though payment had occurred.

### Example attack scenario

1. An attacker reads public appointment-type, price, practitioner, and availability information from the plugin's public routes.
2. The attacker submits a paid booking to `/v1/tyrohealth/charge`.
3. Instead of completing a Tyro payment, the attacker supplies a fabricated value such as `fake-transaction-123`.
4. The legacy handler treats the value as a payment reference and queues scheduling.
5. The worker creates the patient, appointment, and form without independently confirming the payment with Tyro.

### Potential impact

- Paid services booked without payment.
- Lost revenue and staff time.
- Appointment inventory consumed by fraudulent bookings.
- False payment references stored in internal records.
- Confusing customer disputes and reconciliation failures.
- Increased risk of deliberate booking spam or denial of appointment availability.

### Technical tracking information

- Route registration: `src/Routes/PublicGuestRoutes.php`, route `/tyrohealth/charge`.
- Vulnerable handler: `src/Controller/TyroController.php`, method `charge()`.
- For paid appointments, the handler checks that a transaction ID is present and then calls `SchedulingDispatchService::dispatch(...)` without a Tyro lookup.
- The safer v2 flow already demonstrates the required pattern in `BookingAttemptService::confirmTyroPayment()` by fetching the transaction and checking payment status, amount, and invoice reference.

### Immediate containment

1. Block `POST /wp-json/v1/tyrohealth/charge`.
2. Confirm that production website clients use the v2 booking-attempt flow.
3. Reconcile recent Tyro bookings against Tyro's authoritative transaction records.
4. Flag bookings whose stored payment reference does not exist or does not match the expected amount/reference.

### Permanent action plan

1. Remove the legacy route or make it call the same verified payment service used by v2.
2. Verify all of the following server-side before scheduling:
   - the transaction exists;
   - the final status is paid/successful;
   - the merchant/application is correct;
   - the amount and currency match the server-calculated price;
   - the invoice reference matches the booking attempt;
   - the transaction has not already been used.
3. Bind payment confirmation to a server-created booking attempt and its secret attempt token.
4. Enforce idempotency so repeated requests cannot create duplicate bookings.
5. Keep the price authoritative on the server; never accept an amount supplied by the client.

### Fix verification checklist

- A random or nonexistent transaction ID is rejected and no job is queued.
- A real transaction with the wrong amount is rejected.
- A real transaction with the wrong invoice reference is rejected.
- A valid transaction cannot be reused for a second booking.
- A verified transaction creates only one appointment despite retries.
- The v2 Stripe and Tyro flows continue to work.

### Investigation guidance

Review:

- Access logs for `/wp-json/v1/tyrohealth/charge`.
- Action Scheduler jobs and scheduling ledger entries created through the route.
- Stored `payment_reference` values compared with Tyro transaction records.
- Multiple bookings from the same network/client, repeated fake-looking references, or references absent from Tyro.
- Paid appointments created shortly after unsuccessful or missing Tyro transactions.

---

## SEC-03 — Patient-history token can be retrieved using an attacker-chosen request ID

**Severity:** Critical when the patient-history/magic-link feature is enabled  
**Affected routes:**

```text
POST /wp-json/v2/patient-access/request
GET  /wp-json/v2/patient-access/request-status
POST /wp-json/v2/patient-access/request-complete
GET  /wp-json/v2/patient-access/appointments
GET  /wp-json/v2/patient-access/appointments/{booking_id}/prefill
GET  /wp-json/v2/patient-access/latest
```

**Authentication required:** The initial request and status polling are public. Patient-data routes use the resulting bearer token.  
**Status:** Confirmed design weakness in the current source code

### Plain-language explanation

The feature supports opening an email link in one browser or device and returning the verified session to the original browser. It uses a `request_id` to connect those two browser sessions.

The problem is that the initial caller is allowed to choose this ID. After the patient opens the emailed secure link, the plugin stores the patient's bearer token under that ID. The public status route then returns the bearer token to anyone who knows the ID.

Because an attacker can choose the ID, knowing it is not proof that the status caller is the legitimate original browser.

### Example attack scenario

1. An attacker knows a patient's email and a relevant appointment type.
2. The attacker calls `/patient-access/request` and supplies an ID chosen and known by the attacker.
3. The real patient receives a genuine email from the clinic containing a secure link.
4. The patient clicks the link, believing they are completing a normal clinic process.
5. The plugin associates the patient's 15-minute access token with the attacker-known ID.
6. The attacker polls `/patient-access/request-status` using that ID and receives the bearer token.
7. The attacker uses the token to retrieve the patient's appointments, profile prefill data, and previous patient-form content.

The patient must interact with the legitimate email link for this attack path, but the email is genuinely sent by the clinic, making the request more credible to the recipient.

### Potential impact

- Exposure of patient identity and contact information.
- Exposure of appointment history and practitioner information.
- Exposure of answers from previous patient forms, which may contain health information.
- Privacy breach notification, regulatory, contractual, and reputational consequences.
- Loss of patient trust.

### Technical tracking information

- `PatientAccessService::requestAccessCode()` accepts a caller-provided `request_id` and includes it in the magic-link flow.
- `PatientAccessRequestStateService::complete()` stores a valid patient-access token against the supplied ID. It can create a completed record even when no matching pending record exists.
- `PatientAccessRequestStateService::getStatus()` returns `access_token` when the record is completed.
- `/patient-access/request-status` does not require a patient token or a separate polling secret.
- The access token lasts 15 minutes and can be used through the `X-ES-Patient-Access-Token` header.

### Immediate containment

1. Disable the request-status/request-complete handoff feature.
2. Continue using the six-digit email-code exchange only if operationally required and separately verified.
3. Ensure frontend code no longer polls the status route or expects it to return a bearer token.
4. Consider shortening or revoking outstanding access-token sessions during incident response, understanding that current tokens are stateless and may require rotating the signing/encryption salt to invalidate globally. Such a rotation can affect other WordPress security functions and must be planned carefully.

### Permanent action plan

Preferred design:

1. Generate the request ID on the server, not from client input.
2. Return a separate high-entropy polling secret to the initiating client.
3. Store only a hash of the polling secret.
4. Require that secret for status polling.
5. Never return the reusable patient bearer token directly from a public status response.
6. Return a one-time exchange code and require the initiating client to redeem it once over HTTPS.
7. Bind the request to the expected email, appointment type, expiry, and original pending state.
8. Reject completion if a matching, unexpired pending request does not already exist.
9. Delete or permanently mark the exchange as consumed immediately after redemption.
10. Apply rate limits to creation, polling, completion, and token exchange.

Simpler alternative: remove cross-device handoff and require the patient to enter the emailed six-digit code in the same client that requested access.

### Fix verification checklist

- A client cannot choose or predict the server request ID.
- Knowing only the request ID cannot reveal a token or patient data.
- Completion fails when no matching pending request exists.
- A valid exchange can be consumed only once.
- The status endpoint never returns a reusable bearer token.
- Requests expire promptly and cannot be extended by polling.
- A magic link initiated by an attacker cannot deliver access back to the attacker after the patient clicks it.

### Investigation guidance

Review:

- Requests to all `/wp-json/v2/patient-access/*` routes.
- Repeated status polling associated with request IDs created from different IP addresses or user agents.
- A request created from one network followed by completion by the patient and token use from the original, different network.
- High volumes of access emails sent to the same patient or appointment type.
- Access to `appointments`, `latest`, or `prefill` immediately after cross-device completion.

Logging may currently be insufficient to reconstruct every token handoff. Absence of evidence in the logs should not automatically be treated as evidence that the issue was never exploited.

---

## Cross-cutting API concerns

These concerns support the three critical findings but are not presented as separate critical vulnerabilities in this report.

### The public request guard is not authentication

The public guard checks `Origin` and `Referer` only when those headers are present. A native app, command-line client, or server can omit both headers and be allowed. These headers are browser controls, not proof of identity.

Consequently, every route marked “public” below should be considered reachable from the internet.

### Rate limits may be bypassable

The rate-limit fingerprint accepts `CF-Connecting-IP` or `X-Forwarded-For` without confirming that the request arrived through a trusted proxy. Depending on hosting configuration, a caller may be able to change these headers and receive a new rate-limit identity.

Recommended action:

- Trust forwarded IP headers only when the immediate connection came from a configured proxy/CDN.
- Add edge/WAF rate limits in addition to WordPress transients.
- Apply tighter limits to email, payment, form submission, and token routes.
- Consider bot protection for unauthenticated booking and email workflows.

### Bearer tokens are accepted in query parameters

Patient-access tokens can be accepted from headers, request bodies, or URL parameters. Tokens in URLs can appear in server logs, analytics, browser history, screenshots, support tooling, and referrer data.

Recommended action: accept patient and booking bearer tokens only through authorization-style headers or secure request bodies, never through query strings.

---

## Route and flow inventory

This appendix gives management and engineering a common view of what third-party applications can currently consume.

### Public v1 routes

| Method | Route | Purpose | Security note |
|---|---|---|---|
| POST | `/wp-json/v1/send-patient-form` | Legacy patient-form submission | Block immediately; SEC-01 |
| GET | `/wp-json/v1/available-times` | Appointment availability | Public booking metadata |
| GET | `/wp-json/v1/practitioners` | Practitioner IDs and names | Public booking metadata |
| GET | `/wp-json/v1/appointment-type` | Description, duration, price, payment requirement | Public booking metadata |
| GET | `/wp-json/v1/patient-form-template` | Patient-form structure and questions | Confirm that all exposed templates are intended to be public |
| GET | `/wp-json/v1/appointment-calendar` | Calendar data and rendered HTML | Public booking metadata |
| GET | `/wp-json/v1/next-available-times` | Next slot per practitioner | Public booking metadata |
| POST | `/wp-json/v1/payments/charge` | Legacy Stripe charge and scheduling | Plan retirement in favour of v2 |
| POST | `/wp-json/v1/tyrohealth/sdk-token` | Short-lived frontend Tyro SDK token | Public by design; protect against abuse |
| POST | `/wp-json/v1/tyrohealth/invoice` | Server-calculated Tyro amount/reference | Public booking flow |
| POST | `/wp-json/v1/tyrohealth/charge` | Legacy Tyro scheduling | Block immediately; SEC-02 |

### Public and token-based v2 routes

| Method | Route | Purpose | Required authority |
|---|---|---|---|
| POST | `/wp-json/v2/booking-attempts/preflight` | Validate form/slot and create an attempt | Public, rate-limited |
| POST | `/wp-json/v2/booking-attempts/charge-stripe` | Charge Stripe for an attempt | Attempt ID and attempt token |
| POST | `/wp-json/v2/booking-attempts/confirm-tyro` | Verify Tyro payment | Attempt ID and attempt token |
| POST | `/wp-json/v2/booking-attempts/finalize` | Create the appointment | Attempt ID and attempt token; verified/skipped payment |
| GET | `/wp-json/v2/booking-attempts/status` | Read attempt progress | Attempt ID and attempt token |
| POST | `/wp-json/v2/patient-access/request` | Email patient access code/link | Public; email and appointment type |
| POST | `/wp-json/v2/patient-access/verify` | Exchange email code for access token | Email, code, challenge token |
| GET | `/wp-json/v2/patient-access/request-status` | Cross-device access handoff | Block/redesign; SEC-03 |
| POST | `/wp-json/v2/patient-access/request-complete` | Complete cross-device handoff | Block/redesign; SEC-03 |
| GET | `/wp-json/v2/patient-access/appointments` | Recent completed appointments | Patient-access token |
| GET | `/wp-json/v2/patient-access/appointments/{booking_id}/prefill` | Historical form and patient prefill data | Patient-access token |
| GET | `/wp-json/v2/patient-access/latest` | Latest authorized prefill data | Patient-access token |
| GET/POST | `/wp-json/v2/patient-link/verify` | Verify a portal patient link | Email verification token |
| GET/POST | `/wp-json/v2/patient/email-change/verify` | Confirm an email change | Email-change token |

Attempt tokens use this header:

```http
X-ES-Attempt-Token: <token>
```

Patient-history tokens use this header:

```http
X-ES-Patient-Access-Token: <token>
```

### WordPress-authenticated patient routes

These routes require a WordPress login, the configured patient role, completed patient-email verification, and a resolvable linked Cliniko patient.

| Method | Route | Purpose |
|---|---|---|
| POST | `/wp-json/v2/patient-link/request` | Start/resend account-to-Cliniko linking |
| GET | `/wp-json/v2/patient-link/status` | Read linking status |
| GET | `/wp-json/v2/patient/me` | Read current patient profile |
| PUT/PATCH | `/wp-json/v2/patient/me` | Update current patient profile |
| GET | `/wp-json/v2/patient/me/appointments` | List own appointments |
| GET | `/wp-json/v2/patient/me/appointments/{appointment_id}` | Read own appointment detail |
| POST | `/wp-json/v2/patient/me/email-change` | Begin verified email change |
| GET/POST | `/wp-json/v2/patient/me/attachments` | List/create own attachments |
| POST | `/wp-json/v2/patient/me/attachments/presign` | Obtain attachment upload details |
| POST | `/wp-json/v2/patient/me/attachments/upload` | Upload an attachment |
| PATCH/DELETE | `/wp-json/v2/patient/me/attachments/{attachment_id}` | Update/archive own attachment |
| GET/POST | `/wp-json/v2/patient/me/communications` | Read/create own communications |
| GET | `/wp-json/v2/patient/me/communications/unread` | Read unread summary |
| POST | `/wp-json/v2/patient/me/communications/read` | Mark communications read |
| POST | `/wp-json/v2/patient-booking-attempts/preflight` | Authenticated patient booking preflight |
| POST | `/wp-json/v2/cliniko-form-booking-attempts/preflight` | Authenticated widget booking preflight |

The reviewed appointment, attachment, and communication handlers include patient-ownership checks. No critical cross-patient IDOR was identified in those authenticated handlers during this review.

For a native mobile app, WordPress-authenticated routes need a separate supported authentication method. The plugin does not issue mobile JWT/session tokens. WordPress Application Passwords over HTTPS or a purpose-built mobile authentication broker would need a separate security and product decision.

---

## Proposed ownership and completion tracker

| Action | Suggested owner | Priority | Completion evidence |
|---|---|---|---|
| Block the four immediately affected route operations | Operations / platform | Immediate | External requests receive a controlled denial; legitimate replacement flow verified |
| Preserve logs and queued-job evidence | Operations / security | Immediate | Time-bounded evidence archive with access controls |
| Reconcile Tyro bookings and transactions | Finance / operations / engineering | Immediate | Signed-off reconciliation report and exceptions list |
| Assess privacy/incident obligations | Privacy/compliance | Immediate | Documented assessment and decision |
| Correct patient-update ordering and authorization | Backend engineering | Urgent | Security tests and staging evidence |
| Remove or secure legacy Tyro charge | Backend/payment engineering | Urgent | Server-side verification tests and reconciliation test |
| Redesign patient-history handoff | Backend + frontend engineering | Urgent | Threat model, one-time exchange tests, and staging demonstration |
| Migrate all consumers from legacy v1 mutations | Product + engineering | High | Consumer inventory and migration sign-off |
| Publish current API documentation | Engineering | High | Code-derived API contract matching registered routes |
| Conduct post-fix security review | Independent reviewer/security | High | Written retest report with all three findings closed |

## Closure criteria

This review should be considered closed only when:

1. The affected legacy routes are removed or securely replaced.
2. Each attack scenario has an automated regression test.
3. Payment reconciliation confirms the server is authoritative for payment status and amount.
4. No patient mutation occurs before authorization and ownership checks.
5. Patient-history handoff cannot reveal a reusable token to someone who knows or selected a request ID.
6. Production monitoring can identify unusual use of public mutation, payment, and patient-access routes.
7. A follow-up review confirms the fixes in deployed code, not only in documentation.

## Review limitations

- This was a source-code review, not a live penetration test.
- Production configuration, reverse-proxy behavior, WAF rules, and external service settings were not validated.
- Production logs and Cliniko/Tyro audit data were not reviewed.
- The findings demonstrate exploitable code paths but do not establish whether historical compromise occurred.
- The out-of-date `docs/openapi.yaml` was not used as evidence.
