# API-only, structure-only booking flows

Implemented 9 October 2026. Applies to the managed API only. Existing website/shortcode guest behavior is unchanged.

## Setup for clinic staff

1. Open **API Access → Booking flows** and create an API flow. Give it a staff-facing name and a stable identifier such as `initial-consultation`. Choose the appointment type, blank Cliniko form, scheduling policy and payment method directly here. **No shortcode setup is required.**
2. Leave **Enable this API flow** unticked and save a draft. Open **Edit API flow** to see the actual form questions. Optional answer checks include character limits, dates, allowed-answer lists, numbers and fixed-format references, using the shared website booking-rule helper. Required questions and choice-question options remain controlled by Cliniko.
3. Open **Publish API**. Approve that appointment type and form template, and enable **Authenticated patient booking** and **Booking payment and progress**. Configure patient login and approve the app for `booking:read` and `booking:create`. Enable discovery and its scopes when the app needs the linked practitioners/availability routes.
4. Return to the flow and enable it. Enabling checks the live resources, supported questions and payment configuration. Selecting a resource in the flow does not publish it in the API allowlist.
5. Give your developer the API base URL and identifier. **Endpoint documentation → Published booking structures** lists configured URLs, origin/status, request fields, credentials, schemas and examples.

Saved flows appear as compact rows, with **Edit**, **Disable** and **Remove** side by side on the right. A disabled flow keeps Disable visible but inactive; legacy rows offer **Make API-only** instead of Edit. Expand the row using its chevron/name, then open **Appointment type** or **Form template**. Appointment details show its saved ID, reference duration, scheduling and payment method. Form details have nested section accordions for blank questions, required/optional answers, choices and validation checks. These previews never contain completed patient answers and do not change publication or booking settings. Getting Started is collapsed by default.

The editor has no multistep/single-page option, calendar-visibility setting, patient-details review, success/failure message, redirect or custom CSS/JavaScript. The app controls those screens and handles the API success/error responses. Patient identity always comes from the verified login, not configuration or submitted patient details.

## Share the flow list with a frontend developer

In **API Access → Booking flows**, use **Copy flow list (JSON)** to paste the complete saved list into a message or development fixture. **Download JSON** saves the same content as `booking-flows-developer.json`. **View developer JSON** shows a read-only preview; if clipboard access is blocked, Copy opens it and selects all text for Ctrl+C / Command+C. These controls do not save editor fields, publish routes, make bookings or send anything to an external service.

The bundle has `format: cliniko-booking-flow-developer-bundle`, `format_version: 1`, a UTC `generated_at`, API base URL, API/group flags, usage notes and a `flows` array. It includes disabled drafts and legacy website-linked flows, not just enabled flows. It is prepared from saved settings and the already-loaded reference data at page load; reload after changing settings elsewhere. Unsaved form edits are excluded.

Each flow includes:

- Identifier, staff-facing name, origin (`api` or `web`), enabled flag and saved publication status.
- Read/create URLs, methods, scopes and explicit verified-patient/approved-app authentication requirements.
- Appointment ID, reference name and duration; form reference and the structural `form.sections[].questions[]` object used by the API, including required answers, options and validation checks.
- Scheduling mode and required selection fields; practitioner/availability links.
- Configured payment gateway and currency. `payment.amount` and `payment.required` are **null / unknown**, never an invented price or an assumption that the booking is free.
- `submission_template.body` with empty scalar answers, unselected choices and only the requested scheduling fields. Automatic scheduling has `patient: {}`. Its `definition_revision` is an explicit placeholder, not a usable live revision.
- `preview_complete` and `warnings`. Missing or unsupported form details produce `form: null` and `submission_template: null`; no questions are invented. A complete preview does not mean the flow is enabled or eligible for live booking.

Use the structural fields to build screens and choose deliberately synthetic values for development mocks. This bundle is **not the live `{ ok, data, meta }` response**, a backup/import file, a price quotation or authorization. In a real app, fetch `GET /booking-flows/{identifier}` after patient login and use its current `payment`, `definition_revision` and `submission.body`; never post the copied placeholders unchanged. Only existing protected endpoints are used—no new public export endpoint is added.

No patient profiles, completed answers, access/attempt tokens, signing keys, application/provider credentials, website code or redirects are included. The bundle still contains clinic-specific service/questionnaire metadata and draft IDs, so share it only with the intended developer. Downloading/copying never imports or changes flows.

**Changing the form template resets its old index-based answer checks.** Save and reopen to review the new questions. If the same template cannot be loaded, existing checks are preserved rather than silently discarded. Saving submitted answer checks fails if their template cannot be verified. The separate **Disable API flow** action requires no Cliniko connection and stops new structure reads/bookings immediately. Existing attempts retain their protected payment/status/finalization routes.

## Storage and existing website flows

| Record | Storage | Origin |
| --- | --- | --- |
| New independent API flow | `wp_cliniko_api_booking_flows[identifier]`, containing its minimal `configuration` | `flow_type: api`; patient mode only |
| Saved website form | `wp_cliniko_patient_booking_forms[id]` | `flow_type: web` on save; old unmarked records are treated as web |
| Historical API publication link | Existing API-flow option with `configuration_id` | `flow_type: web`; labelled **Legacy website-linked flow** |

API-only configurations are not inserted into the shortcode option, cannot appear as website shortcodes, and do not depend on a website form being present. They reuse booking settings, question-rule normalization, answer validation, scheduling and execution; they do not execute or scrape a shortcode.

Existing website-linked publications are not silently migrated or deleted. They continue reading their original patient configuration, preserving existing addresses and revision behavior. Choose **Make API-only** to explicitly copy only their booking settings and answer rules into the same API identifier. The website form is neither changed nor deleted. The conversion changes the definition revision, so apps must reload before submitting. Future website changes/deletion no longer affect the detached API definition. Legacy guest or missing sources cannot be copied or opened through the protected API.

Drafts do not expose a structure. API-only edits affect their own definition only; legacy web links still follow their source. Disabled/unpublished/archived/invalid resources fail closed. Removing an API flow does not remove a website form or an existing booking attempt.

Saved-state status in the dashboard is not live Cliniko certification. Endpoint documentation reads local configuration only; it never fetches patient records or templates. Booking flows loads active resource names through the existing account-specific five-minute cache, and blank questionnaires for the read-only previews, with each distinct questionnaire fetched only once per page. Opening the API editor loads its questionnaire to render answer checks. Failed/archived template previews show a warning and retain saved IDs. Enabling and patient requests validate live resources independently of these admin previews. With JavaScript unavailable, flow details remain readable rather than being hidden; action forms still work.

## Routes and authentication

| Method and path | Permission | Purpose |
| --- | --- | --- |
| `GET /booking-flows/{slug}` | `booking:read` | Read the current structural contract; no booking writes |
| `POST /booking-flows/{slug}/booking-attempts` | `booking:create` | Resolve that saved setup and create the signed-in patient's booking attempt |

Both require `Authorization: Bearer <patient-access-token>` from an approved application and an active verified linked patient. JWT and configured external OAuth access tokens follow the existing policy. Cookies, app IDs, server keys, registration receipts and guest access are insufficient. External OAuth permissions must be granted both locally and at the provider.

`{slug}` is the API publication identifier, not a Cliniko ID, shortcode alias, query-string override or arbitrary shortcode expression. It contains 2–64 lowercase letters/numbers/hyphens and begins with a letter. No changes to the configured namespace/version are required.

## What GET returns

The usual `{ ok, data, meta: { request_id } }` envelope. `data` contains:

- `identifier`: the publication identifier.
- `appointment_type`: ID, name and duration.
- `form`: ID, name and ordered sections/questions. Each question has `name`, `type`, `required`, `options`, `other_allowed` and `validation`.
- `scheduling`: `manual` or `next_available`, and required scheduling fields.
- `payment`: payment required, gateway, amount in cents and currency. Prices come from Cliniko, not app input. A paid setup configured without a payment gateway is rejected, not silently treated as free.
- `definition_revision`: a fingerprint of the source and current structural contract, including template, rules, scheduling and price.
- `submission`: POST path, required scope and a body skeleton to fill.
- `resources`: relative practitioner/availability paths. These existing discovery routes require their group to be enabled; supplied tokens need the corresponding discovery scopes.

There are **no presentation hints**: no styles, CSS/JavaScript, rendering markup, input widgets, date display preferences, calendar visibility flags, multistep/layout settings, website redirects or branding. Names/descriptions identify the actual resources and questions. No patient profile, previous answers, verification link, card details or server credential is included.

Supported question types are `text`, `textarea`, `paragraph`, `email`, `date`, `checkboxes` and `radiobuttons`. Other types, including signature, return a configuration error; they are not silently omitted. Duplicate/invalid choice options are also rejected.

`validation` contains `max_length` (configured limit or a 5,000-character safety cap), and where applicable `enum`, `format` (`date` or `email`) and `pattern`. Dropdown overrides become an enum, not a UI instruction. Mask rules become constraints generated by the existing shortcode rule helper. API date answers use `YYYY-MM-DD`, independent of the shortcode display format.

## Filling and submitting the returned body

Read the structure, copy `data.submission.body`, and fill its answers. Preserve section/question order and template metadata. The server reconstructs names, types, required flags and choice definitions from the template; caller-supplied changes are rejected.

For scalar questions, fill `answer` with a string. For choice questions, retain the complete `answers` array and set boolean `selected` values. A required choice needs at least one selection; radio questions permit only one. Where `other_allowed` is true, select `other` and provide its nonempty `value` instead of inventing another option. Dates are real ISO dates; required values, enums, numbers, masks, email and character limits are checked server-side with the shared shortcode rule helpers.

For manual scheduling, send both `patient.practitioner_id` and `patient.appointment_start` from the available times. For automatic scheduling, send `patient: {}`: the shared next-available policy chooses a slot on submission. GET does not reserve a slot, and final booking still uses the existing availability checks.

Example, where this configured template contains the shown single question:

```http
POST /wp-json/cliniko-api/v1/booking-flows/initial-consultation/booking-attempts
Authorization: Bearer <patient-access-token>
Content-Type: application/json

{
  "definition_revision": "<exact revision returned by GET>",
  "patient": {
    "practitioner_id": "789",
    "appointment_start": "2026-11-01T10:00:00+11:00"
  },
  "content": {
    "sections": [{
      "name": "Details",
      "description": "Required information",
      "questions": [{
        "name": "Symptoms",
        "type": "text",
        "required": true,
        "answer": "Pain"
      }]
    }]
  }
}
```

Only `definition_revision`, `patient` and `content` are accepted. Do not supply service/form IDs, gateway, amount, configuration parameters or patient identity. Identity comes from the verified account; the saved API definition (or an explicitly labelled legacy web source) supplies booking configuration. The existing direct `/me/booking-attempts` contract is unchanged and remains a separate authorized way to book published resources.

Success returns the normal attempt ID/token and payment requirements. Check the returned authoritative amount before asking the patient to authorize payment. Continue through existing Stripe/Tyro/finalization/status routes with **both** the patient's Bearer token and `X-ES-Attempt-Token`. Finalization also needs `Idempotency-Key`; reuse it for the same retry. See [patient-only booking](api-patient-booking.md).

## Errors and changes

- `404 booking_flow_not_found`: unavailable, disabled, missing, guest-mode or unpublished source; archived live resources also fail closed.
- `409 booking_definition_changed`: source/template/rules/price changed since GET. Reload the structure and review the changed questions or price; do not blindly replay old answers by index.
- `400 unknown_fields` / `invalid_booking_selection`: extra properties, missing manual selection or caller scheduling in automatic mode.
- `422 invalid_booking_answers`: answers do not satisfy the authoritative structure/rules. Validation errors include a field path in error metadata.
- `422 unsupported_booking_question` / `invalid_booking_options` / `invalid_booking_payment_configuration` / `booking_slot_unavailable`: review the source or current availability.
- `503`: upstream data could not be checked; no successful booking is assumed.

Usual authentication, app/scope, publication, HTTPS, origin and request-limit checks remain in effect. Disabling a publication stops new structure reads and booking creation. Existing authenticated attempts continue using their original protected attempt routes; they are not rewritten or deleted by a publication edit.

## Reused implementation and verification

The web and API editors share `PatientBookingConfiguration::parameters` for core settings and `questionRules` for normalization. They share scheduling-mode resolution and next-available lookup, while keeping their saved definitions and presentation separate. Existing `ShortcodeFormInputRules` provides scalar validation/mask constraints. `BookingAttemptService::preflightApiPatient` still handles linked-patient resolution and the existing booking execution. Small shortcode delegations preserve existing rendering and guest behavior; the API never executes or scrapes a shortcode.

Validation uses synthetic data, with checks for structure-only output, patient-only access, independent API storage, origin markers, explicit legacy detachment, publication eligibility, answer tampering, changed/deleted sources, stale revisions, automatic scheduling, full controller-to-booking-service submission, documentation, OpenAPI, actual WordPress schemas and narrow browser layouts. No live clinic booking, payment or email is submitted by these checks.

```text
php tests/api_booking_structure_test.php
php tests/api_patient_booking_flow_test.php
php tests/booking_configuration_origin_test.php
php tests/managed_api_test.php
php tests/shortcode_form_input_rules_test.php
node tests/shortcode_form_input_rules_test.js
node tests/api_access_help_browser_test.mjs
```
