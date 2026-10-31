# Patient-only bookings in the managed API

Updated 29 October 2026. This policy applies only to API Access's managed namespace and its migration aliases. It does not change website/shortcode guest booking routes. Use the current base URL shown in API Access, not the outdated `openapi.yaml`.

## Access rules

| Operation | Credentials required |
| --- | --- |
| `POST /me/booking-attempts` | Active verified patient's Bearer access token; `booking:create` |
| `POST /payments/tyro-sdk-token` | Active verified patient's Bearer access token; `booking:create` |
| `POST /booking-attempts/{id}/stripe-payment` | Original verified patient's Bearer access token **and** `X-ES-Attempt-Token`; `booking:create` |
| `POST /booking-attempts/{id}/tyro-payment` | Same two credentials and ownership; `booking:create` |
| `POST /booking-attempts/{id}/finalize` | Same two credentials and ownership; `booking:create`; also `Idempotency-Key` for safe retries |
| `GET /booking-attempts/{id}` | Same two credentials and ownership; `booking:read` |
| `POST /booking-attempts` | Not registered; no guest creation operation |

A Bearer token can be a plugin-issued patient JWT or an access token from the configured external OAuth provider. It must identify an approved app and active verified patient and grant the operation's scope. Attempt operations require the same original app, WordPress patient account and linked Cliniko patient. A new valid token for that original app/patient can be used after refresh; the original token string is not required. App revocation, lost scope, account suspension, failed verification or changed Cliniko identity blocks access.

Neither an attempt secret alone, a WordPress browser cookie, a public app ID, a server API key, a social ID token nor an onboarding receipt grants booking access. Server keys are limited to `catalog:read` and `availability:read`; older keys with saved booking permissions cannot authorize bookings either.

Published services, practitioners, availability, blank templates and system metadata remain public read-only operations. Registration is a separate, explicitly enabled pending-account flow: it sends the existing verification email and grants no patient access before verification/approval and subsequent login.

## Configure the dashboard

1. Configure a supported patient login and approve the app under Applications.
2. Give it `booking:create` and `booking:read`; for external OAuth grant those scopes at the provider too.
3. Enable **Authenticated patient booking** and **Booking payment and progress**. Publish the permitted appointment types and form templates.
4. Use Endpoint documentation's access badges and expanded instructions, or Requests & responses / Tutorials, to hand off the requests to your developer.

The payment/progress group's internal key remains `booking`, preserving existing saved settings. Its name no longer suggests guest access. Settings cannot re-enable anonymous booking creation.

## Create, pay, finish

Sign in first, then send a request like this with staging IDs, an available time and the actual form answers:

```http
POST /wp-json/cliniko-api/v1/me/booking-attempts
Authorization: Bearer <patient-access-token>
Content-Type: application/json

{
  "moduleId": "123",
  "patient_form_template_id": "456",
  "gateway": "stripe",
  "patient": {
    "practitioner_id": "789",
    "appointment_start": "2026-11-01T10:00:00+11:00"
  },
  "content": { "sections": [] }
}
```

The `patient` object accepts scheduling fields only. Do not send names, email, a patient ID or another user's identity: the schema rejects them and the service resolves the linked identity server-side. The service uses that existing patient rather than guest name/email matching or patient creation. It still creates a patient form before payment; requiring identity does not delay that write or reserve a slot.

Store the returned `data.attempt.id` and `data.attempt.token` privately. Pay if required; the website calculates price and verifies payment with the provider. Free services still require patient authentication to finalize.

For Stripe, preflight returns `data.payment.client_secret` for a server-created PaymentIntent associated with the linked patient's Stripe Customer. The Customer includes the patient's name, email, phone, and address from the linked Cliniko record. Confirm the intent in the patient app with Stripe's SDK (for example, `confirmCardPayment`), then call `POST /booking-attempts/{id}/stripe-payment` with the same Bearer and attempt-token headers. Do not send card numbers or a single-use Stripe token to WordPress. The server retrieves the PaymentIntent and checks that it succeeded for the expected amount, currency, Customer, and attempt before recording payment. This Customer association is for identifying the payment; saving a payment method for later off-session charges is not enabled by this change.

```http
POST /wp-json/cliniko-api/v1/booking-attempts/<attempt-id>/finalize
Authorization: Bearer <patient-access-token>
X-ES-Attempt-Token: <attempt-secret>
Idempotency-Key: booking-finalize-example-001
```

Use both authentication headers on payment and status calls too. Reuse the same Idempotency-Key for the same finalization retry. It is a retry control, not proof of identity. Attempt access lasts up to 24 hours; unfinished attempts remain subject to the existing scheduled cleanup (nominally 30 minutes). Never blindly retry a finalization requiring clinic review.

## Existing integrations and attempts

- Migrate API guest-creation clients to patient signup/verification/login and `/me/booking-attempts`.
- Update payment/status clients to send **both** headers. An old guest attempt cannot be converted into a patient attempt by presenting a login token, even if its contact details match.
- Old API guest attempts are denied, not deleted by this change. Existing abandoned-attempt cleanup is unchanged. Review paid or interrupted guest attempts with the clinic before rollout; do not assume they can complete through the managed API or retry payment.
- Authenticated attempts with complete ownership metadata continue to work for the original app/patient. Attempts missing app, patient mode, user or Cliniko ownership fail closed.
- Website booking attempt flows remain separate from the managed API, and the managed API master switch does not disable them. Website Stripe attempts use PaymentIntents; the deprecated v1 payment endpoint remains available for backward compatibility.

## Verification

Tests cover removed guest creation in current/alias namespaces, patient-only creation and SDK access, missing credentials, server keys, guest/website attempts, scope and app ownership, original patient identity, changed Cliniko links, suspended accounts, expiry, combined OpenAPI security and documentation categories. WordPress's actual validators check required scheduling fields and reject submitted patient identity fields. Registration and existing website booking/payment suites are checked separately. Browser checks cover filters, access badges and narrow screens.

Automated checks use synthetic patients/provider tokens and mocked downstream calls. No live Cliniko booking, charge or verification email is submitted during these checks. Authentication does not eliminate spam by valid accounts; retain request limits, approval and operational monitoring.
