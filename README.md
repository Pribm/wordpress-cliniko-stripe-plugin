# WordPress Cliniko Payment Integration

Production-ready WordPress plugin that connects Cliniko bookings and patient forms with payment flows in Stripe and Tyro Health, with Elementor widgets for custom booking experiences.

## Version
- Current plugin version: `1.5.3`

## Overview
This plugin supports two booking approaches:
- `Cliniko Embed`: Cliniko handles the booking UI in an iframe.
- `Custom Form`: your Elementor form collects answers, schedules appointments, and routes payment through the selected gateway.

For custom form mode, appointment scheduling can use:
- `Next Available Time`
- `Calendar Selection` with practitioner-aware availability

## What Is New in 1.5.3
- Added backend sanitization for choice questions so `selected: false` is stripped from `radiobuttons` and `checkboxes` answers before dispatch to Cliniko.
- Applied sanitization consistently across all form intake routes (`send-patient-form`, `payments/charge`, `tyrohealth/charge`).
- Reduced Cliniko payload validation failures caused by explicit false-selection flags.

## Core Features
- Shard-aware Cliniko API integration.
- Elementor widgets for appointment cards and booking forms.
- Multi-step booking flow with custom step sequencing.
- Async scheduling pipeline through Action Scheduler (WP-Cron fallback).
- Validation pipeline for patient form payloads.
- Gateway handling for Stripe and Tyro Health.

## Requirements
- WordPress `>= 5.9`
- PHP `>= 7.4` (tested up to 8.2)
- Elementor `>= 3.10`
- Cliniko API key
- Stripe keys (publishable and secret) for Stripe mode

## Installation
1. Install the plugin in `/wp-content/plugins/` or upload the ZIP from WordPress admin.
2. Activate the plugin.
3. Open `Settings -> Cliniko Stripe Integration`.
4. Configure credentials:
   - Cliniko API Key
   - Cliniko App Name
   - Cliniko Shard (for example `au4`)
   - Stripe Publishable Key
   - Stripe Secret Key
5. Click `Connect to Cliniko` and select business, practitioner, and appointment type.

Cliniko app and shard can be derived from your Cliniko URL:
- Example: `https://my-clinic.au4.cliniko.com/...`
- App Name: `my-clinic`
- Shard: `au4`

## Elementor Widgets

### Cliniko: Appointment Type Card
Displays appointment type details with configurable icon, price presentation, and CTA.

Main controls:
- Appointment type
- Icon and colors
- Price position
- Button text/icon/link
- Optional custom CSS class

### Cliniko: Stripe Booking Form
Main booking wizard widget.

Main capabilities:
- Multi-step form flow
- Cliniko embed mode support
- Custom form mode support
- Optional payment step depending on gateway mode

## Custom Form Flow
For custom form mode, the flow generally includes:
1. Booking questions and patient details
2. Appointment scheduling (`Next Available` or `Calendar Selection`)
3. Payment handoff (if gateway enabled)
4. Async scheduling and form processing in workers

Calendar mode behavior:
- Calendar step displays date grid and available slots.
- Practitioner selection is tied to calendar scheduling context.
- Date buttons with no availability are disabled.
- Slot selection updates `patient[appointment_start]`.

Gateway behavior:
- Final wizard action should continue to payment flow (not direct browser submit).
- Wizard UI can be hidden while payment UI is active.

## Headless Mode (Custom Form)
Headless mode renders no form UI. The Cliniko template is exposed so you can build your own UI while keeping the payment step intact.

Where the template is exposed:
- `formHandlerData.sections` (global JS object)
- `.cliniko-form-headless .cliniko-form-template-json` (JSON script tag)

Submission-ready skeleton:
- `formHandlerData.submission_template`
- `.cliniko-form-headless .cliniko-form-submission-template-json` (JSON script tag)

Headless calendar (build your own UI):
- Helper: `window.ClinikoHeadlessCalendar` (available only in headless mode).
- Defaults: if you omit `appointmentTypeId`, it falls back to `formHandlerData.module_id`.
- Date format: use `YYYY-MM-DD` for `dateKey`, `from`, and `to`.

How to submit:
1. Build a payload with `patient` and `content` from your UI.
2. Expose it as `window.clinikoHeadlessPayload` or `window.clinikoGetHeadlessPayload()`.
3. Show the payment UI when ready.

Payment UI notes:
- Stripe: call `showStripePaymentForm()` or set `#payment_form` to `display:flex` and let the payment button handle submission.
- Tyro Health: show `#payment_form`. Ensure your headless patient fields map to the IDs/names read by `tyrohealth.js` (for example `#patient-first-name`, `#patient-last-name`, `#patient-email`), or adjust `tyrohealth.js` to your field IDs.

Headless calendar flow (recommended):
1. Load practitioners (optional).
2. Load the current month grid via `fetchCalendar()` and render it (you can use `grid_html` or your own UI).
3. On date click, load times via `fetchAllTimesForDate()`.
4. Group the times with `groupTimesByPeriod()` for morning/afternoon/evening.
5. When the user selects a time, call `updateHeadlessPatient({ appointment_start, practitioner_id })`.
6. If you use `clinikoGetHeadlessPayload()`, write these fields into the returned payload yourself (the helper can’t mutate a computed payload).

Headless calendar example (minimal):
```js
const cal = window.ClinikoHeadlessCalendar;
const practitioners = await cal.fetchPractitioners();
const practitionerId = practitioners?.[0]?.id || "";
const monthKey = cal.getMonthKeyFromDate(new Date());
const calendar = await cal.fetchCalendar({ practitionerId, monthKey });
// calendar.grid_html, calendar.month_label, calendar.month_key

const dateKey = "2026-02-10";
const times = await cal.fetchAllTimesForDate({ dateKey, practitionerId });
const buckets = cal.groupTimesByPeriod(times);
// render buckets.morning / buckets.afternoon / buckets.evening

cal.updateHeadlessPatient({ appointment_start: times[0], practitioner_id: practitionerId });
```


Minimal payload shape:
```json
{
  "moduleId": "appointment_type_id",
  "patient_form_template_id": "form_template_id",
  "patient": {
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane@example.com",
    "phone": "0400 000 000",
    "medicare": "1234 56789",
    "medicare_reference_number": "1"
  },
  "content": {
    "sections": [
      {
        "name": "Section Name",
        "questions": [
          {
            "name": "Question Label",
            "type": "text",
            "required": true,
            "answer": "Free text answer"
          },
          {
            "name": "Options Question",
            "type": "radiobuttons",
            "required": true,
            "answers": [
              { "value": "Yes", "selected": true },
              { "value": "No" }
            ]
          }
        ]
      }
    ]
  }
}
```

### Headless Payload Details
The widget exposes a submission template with these shapes. You can use it directly or clone it and fill the answers.

Patient object fields (all string values):
- `patient.first_name`
- `patient.last_name`
- `patient.email`
- `patient.phone`
- `patient.medicare`
- `patient.medicare_reference_number`
- `patient.address_1`
- `patient.address_2`
- `patient.city`
- `patient.state`
- `patient.post_code`
- `patient.country`
- `patient.date_of_birth`
- `patient.appointment_start` (ISO 8601 string)
- `patient.practitioner_id`

Content schema notes:
- `content.sections` is an array of sections from the Cliniko template.
- `content.sections[].questions[].name` is the stable key and label.
- `content.sections[].questions[].type` can be `text`, `textarea`, `checkboxes`, or `radiobuttons`.
- `content.sections[].questions[].required` is a boolean.
- For `text`/`textarea`, send `answer` (string).
- For `checkboxes`/`radiobuttons`, send `answers` (array of `{ value, selected }`).
- For `radiobuttons`, only one `answers[].selected` can be `true`.
- `selected: false` entries are sanitized server-side (removed before Cliniko payload dispatch).
- If the template enables "other", include `other` as `{ enabled: true, selected: true|false, value: "..." }`.
- `signature` questions are not allowed in payloads.

## Headless API Reference
These are the REST endpoints the headless helpers call. They are registered under the WordPress REST API and are publicly accessible by default.

Base path:
`/wp-json/v1`

Response conventions:
- Cliniko data endpoints return `{ success: true, data: ... }` on success.
- Payment endpoints return `{ status: "success", payment: ..., scheduling: ... }` on success.
- Errors return a `message` and may include an `errors` array with `{ field, label, code, detail }`.

### GET /practitioners
Lists practitioners for an appointment type.

Query params:
- `appointment_type_id` (required). Aliases: `module_id`, `moduleId`.

Example request:
`GET /wp-json/v1/practitioners?appointment_type_id=123`

Returns:
- `success` boolean
- `data.appointment_type_id` string
- `data.practitioners` array of `{ id, name }` (inactive/hidden practitioners are filtered when possible)

Example response:
```json
{
  "success": true,
  "data": {
    "appointment_type_id": "123",
    "practitioners": [
      { "id": "456", "name": "Jane Smith" }
    ]
  }
}
```

### GET /appointment-calendar
Returns an HTML calendar grid (plus labels) for a given appointment type and optional practitioner.

Query params:
- `appointment_type_id` (required). Aliases: `module_id`, `moduleId`.
- `practitioner_id` (optional). If omitted, the first practitioner for the appointment type is used.
- `month` (optional). Format: `YYYY-MM`. Defaults to the current month.

Example request:
`GET /wp-json/v1/appointment-calendar?appointment_type_id=123&practitioner_id=456&month=2026-02`

Returns:
- `success` boolean
- `data.month_label` string (human-readable month)
- `data.month_key` string (format `YYYY-MM`)
- `data.grid_html` string (calendar day grid HTML)
- `data.practitioner_id` string
- `data.appointment_type_id` string

Example response:
```json
{
  "success": true,
  "data": {
    "month_label": "February 2026",
    "month_key": "2026-02",
    "grid_html": "<div class=\"calendar-day ...\">...</div>",
    "practitioner_id": "456",
    "appointment_type_id": "123"
  }
}
```

### GET /available-times
Returns available appointment start times for a given date range.

Query params:
- `appointment_type_id` (required). Aliases: `module_id`, `moduleId`.
- `from` (required). Format: `YYYY-MM-DD`.
- `to` (required). Format: `YYYY-MM-DD`.
- `practitioner_id` (optional). If omitted, the first practitioner for the appointment type is used.
- `page` (optional). Defaults to `1`.
- `per_page` (optional). Defaults to `100`, max `100`.

Example request:
`GET /wp-json/v1/available-times?appointment_type_id=123&from=2026-02-10&to=2026-02-10&practitioner_id=456`

Returns:
- `success` boolean
- `data.available_times` array of `{ appointment_start }`
- `data.total_entries` integer
- `data.links.self|next|previous` strings
- `data.appointment_type_id` string
- `data.practitioner_id` string
- `data.from` string
- `data.to` string

Example response:
```json
{
  "success": true,
  "data": {
    "available_times": [
      { "appointment_start": "2026-02-10T01:30:00Z" }
    ],
    "total_entries": 12,
    "links": {
      "self": "https://example.com/wp-json/v1/available-times?...",
      "next": null,
      "previous": null
    },
    "appointment_type_id": "123",
    "practitioner_id": "456",
    "from": "2026-02-10",
    "to": "2026-02-10"
  }
}
```

### POST /send-patient-form
Queues a patient form submission to Cliniko (no payment). This is also the endpoint used for the Cliniko iframe flow.

Body (JSON):
- `patient_form_template_id` (required)
- `patient.email` (required)
- `patient.patient_booked_time` (required). ISO 8601 UTC string (example: `2026-02-10T01:30:00Z`)
- `content.sections` (required). Use the same structure you get from the headless template JSON.
- `moduleId` (optional)

Example request body:
```json
{
  "moduleId": "123",
  "patient_form_template_id": "999",
  "patient": {
    "email": "jane@example.com",
    "patient_booked_time": "2026-02-10T01:30:00Z"
  },
  "content": {
    "sections": [
      {
        "name": "Section Name",
        "questions": [
          {
            "name": "Question Label",
            "type": "text",
            "required": true,
            "answer": "Free text answer"
          }
        ]
      }
    ]
  }
}
```

Notes:
- `signature` questions are not allowed in `content`.
- Required questions must include valid answers. For radiobuttons, only one option may be selected.
- `selected: false` values in `checkboxes`/`radiobuttons` are stripped server-side before Cliniko submission.
- If an "other" option is selected, it must include a non-empty `other.value`.

Success response (HTTP 202):
```json
{
  "success": true,
  "message": "Patient form creation has been queued.",
  "queued": {
    "payload_key": "cliniko_pf_job_payload_...",
    "status": "queued"
  }
}
```

Error response (HTTP 400):
```json
{
  "success": false,
  "message": "Invalid request parameters.",
  "errors": [
    { "field": "patient.email", "label": "Email", "code": "invalid", "detail": "Email is invalid or missing." }
  ]
}
```

### POST /payments/charge (Stripe)
Charges Stripe and queues scheduling. Also handles zero-cost bookings (no Stripe token required when payment is not required).

Body (JSON):
- `moduleId` (required)
- `patient_form_template_id` (required)
- `stripeToken` (required if payment is required; must start with `tok_` or `pm_`)
- `patient` (required)
- `patient.first_name`, `patient.last_name`, `patient.email` (required)
- `patient.practitioner_id` (required, numeric Cliniko ID)
- `patient.medicare` (required, 9 digits)
- `patient.medicare_reference_number` (required, single digit 1-9)
- `patient.date_of_birth` (optional, `YYYY-MM-DD` if provided)
- `patient.appointment_start` (optional, ISO 8601 datetime if provided)
- `patient.appointment_date` (optional, `YYYY-MM-DD` if provided)
- `content` (required; validated to match Cliniko patient form structure)
- `signature_attachment_id` (optional)

Example request body:
```json
{
  "moduleId": "123",
  "patient_form_template_id": "999",
  "stripeToken": "tok_visa",
  "patient": {
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane@example.com",
    "practitioner_id": "1547537765724333824",
    "medicare": "1234 56789",
    "medicare_reference_number": "1",
    "date_of_birth": "1992-02-23",
    "appointment_start": "2026-02-12T05:50:00Z"
  },
  "content": {
    "sections": []
  }
}
```

Success response:
```json
{
  "status": "success",
  "payment": {
    "id": "ch_...",
    "amount": 12500,
    "currency": "aud",
    "receipt_url": "https://...",
    "card_last4": "4242",
    "brand": "visa"
  },
  "scheduling": { "status": "queued" }
}
```

Notes:
- If the appointment type does not require payment, `stripeToken` can be omitted and the response will include a `null` payment id with amount `0`.
- `content` is accepted as-is; keep its structure aligned with the template.

### POST /tyrohealth/sdk-token
Returns a short-lived Tyro Health Partner SDK token.

Request body: none

Success response:
```json
{ "token": "..." }
```

### POST /tyrohealth/invoice
Returns pricing metadata for the Tyro Health SDK.

Body (JSON):
- `moduleId` (required)

Example request body:
```json
{
  "moduleId": "123"
}
```

Success response:
```json
{
  "success": true,
  "data": {
    "chargeAmount": "125.00",
    "invoiceReference": "Appointment Type Name",
    "providerNumber": "123456"
  }
}
```

### POST /tyrohealth/charge
Queues scheduling after a Tyro Health transaction.

Body (JSON):
- `moduleId` (required)
- `patient_form_template_id` (required)
- `tyroTransactionId` or `transactionId` (required when payment is required)
- `invoiceReference` (optional)
- `patient` (required)
- `content` (optional; accepted but not validated here)
- `signature_attachment_id` (optional)

Example request body:
```json
{
  "moduleId": "123",
  "patient_form_template_id": "999",
  "tyroTransactionId": "txn_abc123",
  "patient": {
    "first_name": "Jane",
    "last_name": "Doe",
    "email": "jane@example.com"
  },
  "content": {
    "sections": []
  }
}
```

Success response:
```json
{
  "status": "success",
  "payment": {
    "id": "txn_...",
    "amount": 12500,
    "currency": "aud",
    "receipt_url": null
  },
  "scheduling": { "status": "queued" }
}
```

Notes:
- If the appointment type does not require payment, `tyroTransactionId` can be omitted and the response will include a `null` payment id with amount `0`.
- `content` is accepted as-is; keep its structure aligned with the template.

## Async Processing
The plugin uses Action Scheduler for background jobs.

Primary pattern:
- Frontend submits structured payload.
- Controller validates and persists payload metadata.
- Worker processes booking and patient form tasks.

If Action Scheduler is not available, WP-Cron is used as fallback.

## Security Notes
- API credentials are stored in WordPress options with capability checks.
- Inputs are sanitized/validated before processing.
- Widget output uses escaped attributes/content.
- Stripe secret key is never exposed in frontend payloads.

## Troubleshooting

### Calendar not visible in custom form wizard
- Confirm appointment source is `custom_form`.
- Confirm scheduling mode is `calendar`.
- Confirm the wizard includes the `[data-appointment-selection]` block.

### Payment step does not open
- Confirm selected gateway is enabled in widget settings.
- Confirm final wizard action triggers gateway flow instead of direct form submit.
- Confirm only the selected gateway assets are loaded.

### Practitioner not populated
- Confirm practitioner endpoint is reachable from site context.
- Confirm appointment type is correctly configured.

## Changelog
See `CHANGELOG.md` for release history.

## Contributing
Pull requests are welcome. For major changes, open an issue first with:
- use case
- expected behavior
- implementation notes

## License
MIT
