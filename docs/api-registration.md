# App registration and existing Cliniko verification

Implemented 8 October 2026. Open **API Access → Patient login → Registration**. All signup methods are off by default and independent of login/publishing settings.

## What the patient experiences

1. They register in the app using a website password, Google/Apple, or the configured external OAuth service.
2. The app collects first name, last name and explicit acceptance of the clinic privacy policy.
3. WordPress creates a **pending** patient account with the server-selected low-privilege patient role.
4. The plugin runs its existing `PatientService::syncUser` / `PatientLinkService::beginVerification` flow and sends the existing WordPress patient-verification email.
5. The patient opens the email's existing website verification link. The existing verifier checks email ownership and Cliniko matching. It binds an existing unique matching Cliniko patient, or creates the new Cliniko patient after verification. Ambiguous matches, email changes, failed email delivery or unresolved approval cannot grant patient access.
6. The app checks onboarding progress; once verification and the existing approval rules are complete, the patient signs in separately. **Registration never returns a patient JWT or authorizes protected data.**

The existing Ultimate Member/Cliniko activation rules remain authoritative. This is not a new manual-approval policy, registration form renderer, password-reset feature or OAuth authorization server.

## Enable deliberately

- Confirm patient sync is enabled, the clinic verification email works and the configured patient roles are correct.
- Choose a role with only `read` / `level_0` capabilities; administrator, editor and elevated custom roles are excluded. The app cannot supply roles/capabilities or a Cliniko patient ID.
- Review registration-form, privacy-consent, CAPTCHA/anti-spam and approval requirements. API creation runs WordPress creation hooks but does not execute the website's interactive registration form. A server integration can deny signup through `wp_cliniko_api_registration_allowed`; do not use API registration to bypass required checks.
- Choose website-account, Google/Apple and/or external OAuth signup and save Registration settings. Existing login methods, route publication and session settings remain unchanged.
- Give the appropriate approved **Patient app** or **OAuth app** `account:register` permission. A trusted server key cannot register WordPress patient accounts. Google/Apple also need their sign-in methods and per-app IDs configured. External OAuth must be enabled and grant `account:register` in the provider token as well.
- Publish the API separately. Configured flags are not evidence that email delivery or a real provider registration works.

## Routes

All paths below are relative to the base address shown in Overview. Send JSON over HTTPS. These authentication operations reject an `Authorization` header; send the specified proof in the JSON body.

| Route | Proof / required input | Successful HTTP status |
| --- | --- | --- |
| `POST /auth/register` | Internal Patient App ID, `email`, `password`, `first_name`, `last_name`, `accept_privacy_policy: true` | 202 |
| `POST /auth/register/social` | Internal Patient App ID, `provider`, registration `challenge_id`, verified `id_token`, names and consent | 202 |
| `POST /auth/register/oauth` | Internal OAuth App ID, provider `access_token`, names and consent | 202 |
| `POST /auth/registration/status` | Original approved App ID and private `registration_token` | 200 |
| `POST /auth/registration/resend` | Original approved App ID and private `registration_token` | 202 |

### Website password

```http
POST <API-BASE>/auth/register
Content-Type: application/json

{
  "client_id": "<internal-24-character-Patient-App-ID>",
  "email": "new@example.test",
  "password": "<new-password-with-12-to-128-characters>",
  "first_name": "New",
  "last_name": "Patient",
  "accept_privacy_policy": true
}
```

The website assigns a random internal WordPress username; patients can sign in with email. WordPress hashes the password. The API does not log it or store plaintext. Existing accounts are not updated, even if a duplicate signup supplies a new password/name. Use signup only with apps trusted to receive the patient's website password; external OAuth avoids that password-sharing tradeoff.

### Google / Apple

Request `POST /auth/challenge` with the approved Patient App ID, `provider: "google"` or `"apple"`, and **`purpose: "register"`**. Registration and login challenges are separate and cannot be substituted. Include the returned nonce in the official provider flow; Apple's nonce is already SHA-256 transformed.

Submit the signed ID token, the same provider/app/challenge, first/last names and explicit consent to `/auth/register/social`. The website verifies signature, issuer, per-app audience/presenter, expiry and single-use nonce using the existing social verifier. Signup additionally requires the provider's verified `email` and `email_verified` claim. The API never accepts a caller-selected different clinic email.

A genuinely new account is linked to that proven external subject, but remains pending clinic email verification. **An existing WordPress email or already-linked subject never causes an automatic account merge.** Existing patients should use normal login or have their identities linked by the clinic.

Apple names may need to be collected by the app, and private-relay email must be supported by the clinic's registered outbound email sources. A relay address may not match the existing real-email Cliniko record; do not silently substitute another address. Use website-account registration or a clinic-reviewed linking/email-change workflow when needed. See [Apple's email/environment requirements](https://developer.apple.com/documentation/signinwithapple/configuring-your-environment-for-sign-in-with-apple) and [Google identity validation](https://developers.google.com/identity/sign-in/web/backend-auth).

### External OAuth

Submit the dashboard's **internal OAuth App ID**—not its external provider client ID—plus the provider access token, names and consent to `/auth/register/oauth`. The configured introspection service must identify that same approved app, return the usual valid token claims, grant `account:register`, and additionally include `email` and `email_verified: true`.

Only a newly created pending WordPress account is mapped to the verified issuer + subject. Existing emails/subjects are not overwritten. The website sends its own clinic verification email even when the provider reports verified email. After clinic verification, the app obtains/uses a provider access token for normal patient requests; this route does not issue or refresh provider tokens.

## Pending response and app waiting screen

```json
{
  "ok": true,
  "data": {
    "status": "verification_pending",
    "message": "If registration can proceed, check your email to verify the patient account. Existing users should sign in or contact the clinic. Patient API access requires completed verification and approval.",
    "registration_token": "<private-reg_-prefixed-onboarding-receipt>",
    "expires_in": 86400
  },
  "meta": { "request_id": "<support-reference>" }
}
```

HTTP 202 means the request was accepted, **not** that verification, email delivery or account activation succeeded. Existing-email and already-linked-identity submissions receive the same generic pending envelope and an inert receipt; no existing-user details or notifications are disclosed. A dummy receipt stays pending and cannot expose an existing account's status or change it.

Store the real receipt securely. It lasts 24 hours, is bound to the original approved app and the new account's email, and grants only progress/resend access. It is **not** a patient bearer token; sending it to `/me` is explicitly rejected before OAuth introspection. No user/Cliniko IDs, verification URL or patient data are returned through onboarding.

Send the receipt in JSON to `/auth/registration/status`. The app receives `verification_pending` or `ready_to_sign_in`, plus a message. On readiness, perform the chosen normal login—not an automatic token exchange through the receipt. After receipt expiry, the patient can still use a valid verification email and sign in normally; contact the clinic if recovery is needed. This feature does not provide a public email-address-only account-status lookup.

Check progress when the patient returns from the verification email or explicitly taps Check again. Do not poll rapidly: onboarding requests use the normal authentication IP/app budgets, and the app must back off on HTTP 429.

Resend uses the existing email template and verifier, rotates the existing verification challenge according to its current rules, waits at least 60 seconds between sends and allows three requests per email receipt bucket per ten minutes. It does not send after verification or while patient sync/eligibility is disabled. The generic response does not claim that mail was delivered. Normal authentication IP/app limits also apply; signup is limited to three attempts per normalized email per ten minutes. Handle HTTP 409 lock contention and HTTP 429 limits; a consumed social challenge requires a fresh provider flow on retry.

## Implementation and validation

- `PatientRegistration` performs low-privilege pending creation and receipt handling; `PatientAuthentication` verifies social/OAuth proofs before passing a trusted identity.
- `PatientService::withDeferredRegistrationSync` temporarily postpones this plugin's registration/identity-metadata sync hooks while the complete WordPress account is inserted. Normal WordPress hooks still run. The existing sync service then runs once with complete names, role and consent metadata. Other registration flows are unchanged.
- Shared database locks serialize registration by email/subject and mapping writes. Mapping caches are refreshed inside the write lock; registration cannot overwrite a different identity mapping.
- Receipts persist only keyed token/email hashes and IDs/expiry in expiring, non-autoloaded WordPress transients. No provider tokens, passwords or email verification links are stored in the receipt or API audit log.
- Registration audit events record only identifiers/status metadata. Failures stay pending; no email/Cliniko failure grants protected access.
- Live route registration, schemas, documentation and active-only OpenAPI JSON include all five new operations and their actual 202/200 response codes. `docs/openapi.yaml` remains non-authoritative.
- Run `php tests/api_registration_test.php` for onboarding coverage, alongside the managed API suite and browser/schema checks. Tests use synthetic WordPress/provider/Cliniko/email fixtures but exercise the real signup and existing binding/verifier services. They are not live-provider or mail-delivery certification.

Current checks: 18 registration scenarios and 52 managed API/dashboard scenarios pass, along with browser interactions, actual WordPress schema validation, the real WordPress registration/documentation smoke check for 27 operations, PHP syntax and PHPStan (2 GB analysis limit). The older standalone `ultimate_member_patient_approval_test.php` still lacks its pre-existing `get_option` mock and fails before its assertions; it was not changed by this task. Real Ultimate Member approval/email delivery and provider configurations still need staging validation.

Before rollout, test on staging with a new email, existing WordPress email, unique/ambiguous Cliniko email matches, actual Google/Apple/OAuth registrations, mail delivery, receipt expiry/resend and denied patient access before verification. Confirm your privacy, anti-spam and approval integrations and SMTP/private-relay configuration.
