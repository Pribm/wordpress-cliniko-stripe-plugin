# WordPress Cliniko Payment Integration

Production-ready WordPress plugin that connects Cliniko bookings and patient forms with payment flows in Stripe and Tyro Health, with Elementor widgets for custom booking experiences.

## Version
- Current plugin version: `1.5.1`

## Overview
This plugin supports two booking approaches:
- `Cliniko Embed`: Cliniko handles the booking UI in an iframe.
- `Custom Form`: your Elementor form collects answers, schedules appointments, and routes payment through the selected gateway.

For custom form mode, appointment scheduling can use:
- `Next Available Time`
- `Calendar Selection` with practitioner-aware availability

## What Is New in 1.5.1
- Avoided duplicate Cliniko template fetches during widget rendering.
- Prevented duplicate external library and CSS injections when multiple widgets are on the same page.
- Added early payload validation for payment requests (fail fast on invalid input).
- Added optional content-section validation toggle to support payment flow prechecks.

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
