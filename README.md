# 📦 WordPress Cliniko ⇆ Stripe Integration

A production-ready WordPress plugin that connects **Cliniko** (appointments, patient forms) with **Stripe** (payments), with **Elementor** widgets for a clean multi-step booking flow. Ideal for telehealth/clinics: collect patient info, take payment, then create/sync bookings.

---

## ✨ Highlights

- **Cliniko API (shard-aware)**: Works with any region (e.g. `au4`, `us1`).
- **Stripe Payments**: Pre-pay or pay-to-confirm with Stripe Elements.
- **Elementor Widgets**:
  - `Cliniko: Appointment Type Card`
  - `Cliniko: Stripe Booking Form`
- **Smart Flow**:
  - If Cliniko Embed is configured → **Patient step** is **penultimate**, **Embed** is **final**.
  - If not configured → **Patient** is the final step.
- **PostMessage Handlers**: Responds to `cliniko-bookings-resize:*` and `cliniko-bookings-page:*`.
- **Action Scheduler**: Async job queue (falls back to WP-Cron if unavailable).
- **Hardened**: Extra escaping/sanitization for rendered attributes.

---

## 🧰 Requirements

- **WordPress** ≥ 5.9
- **PHP** ≥ 7.4 (tested up to 8.2)
- **Elementor** ≥ 3.10
- API keys:
  - **Cliniko** (from *My Info → API Keys*)
  - **Stripe** (Publishable + Secret)

---

## 🚀 Installation

1. Upload to `/wp-content/plugins/` (or install as ZIP).
2. Activate in **Plugins**.
3. Go to **Settings → Cliniko Stripe Integration** and fill:
   - **Cliniko API Key**
   - **Cliniko App Name** (e.g. `lorem-ipsum`)
   - **Cliniko Shard** (e.g. `au4`)
   - **Stripe Publishable Key**
   - **Stripe Secret Key**
4. Click **Connect to Cliniko** to fetch **Businesses**, then select **Practitioner** and **Appointment Type**.

> 🧭 **Where do I find App Name + Shard?**  
> From your Cliniko embed URL, e.g.  
> `https://lorem-ipsum.au4.cliniko.com/bookings?...`  
> App Name = `lorem-ipsum` • Shard = `au4`.

---

## 🧩 Elementor Widgets

### 1) Cliniko: Appointment Type Card
A stylable card for a single appointment type (name, description, price, CTA).

**Controls**
- Select Appointment Type
- Icon / color
- Price label + position
- Button text/link/icon
- Custom classes (e.g. Tailwind)

**Optional hover helper**
```css
.button-hover-slide {
  background: linear-gradient(to left, var(--e-global-color-primary) 50%, var(--e-global-color-secondary) 50%);
  background-size: 200% 100%;
  background-position: right;
  transition: background-position 0.4s ease;
  color: #fff;
}
.button-hover-slide:hover {
  background-position: left;
  color: #000;
}
```

### 2) Cliniko: Stripe Booking Form
A multi-step flow that can include:
1. Pre-form (optional)
2. Patient Form (Cliniko template)
3. Stripe payment
4. (Optional) Cliniko Embed as the final step

**Controls**
- Appointment Type / Practitioner
- Toggle multi-step
- Map basic identity fields
- Enable/disable patient fields
- Enable **Cliniko Embed** (iframe)

**What happens behind the scenes**
- Loads **Patient Form Template** from Cliniko
- Uses **Stripe Elements** to securely capture payment
- Submits patient + booking + notes to Cliniko
- In **Embed mode**, listens for `cliniko-bookings-page:*` and `cliniko-bookings-resize:*` events and requires a `patient_booked_time` to sync

---

## 🔌 Endpoints & Payloads (Frontend → WP)

The booking submitter builds a JSON payload like:

```json
{
  "content": { "sections": [/* Cliniko-like structured Q&A */] },
  "patient": {
    "first_name": "Paulo",
    "last_name": "Monteiro",
    "email": "monteiro.paulovinicius@gmail.com",
    "mobile_number": "0405637928"
  },
  "moduleId": "1747505259153991532",
  "patient_form_template_id": "1739522739649127472",
  "stripeToken": null
}
```

**Embed mode (Cliniko iframe)**: one extra requirement

- You **must** include a `patient_booked_time` (ISO string) and it will be merged into `patient` by the controller:
  ```js
  submitBookingForm(null, null, /* isClinikoIframe */ true, {
    patientBookedTime: new Date().toISOString()
  });
  ```

The controller validates via `PatientFormValidator`, persists a job payload, and enqueues the async worker using **Action Scheduler** (group: `wp-cliniko`). If Action Scheduler is missing, it falls back to **WP‑Cron**.

---

## 🧱 Action Scheduler (Queue)

This plugin prefers **Action Scheduler**. If available, we enqueue with:
```php
as_schedule_single_action($when, 'cliniko_async_create_patient_form', [['payload_key' => $key]], 'wp-cliniko');
```
If not present, we fallback to:
```php
wp_schedule_single_event($when, 'cliniko_async_create_patient_form', [['payload_key' => $key]]);
```

**Troubleshooting**
- Make sure Action Scheduler is bundled/loaded before enqueues (e.g., `includes/action-scheduler/action-scheduler.php`).
- Check the **Scheduled Actions** admin page (if you use the separate plugin) or WP-Cron health.
- Confirm your hooks are registered early enough.

---

## 📨 PostMessage Events (Cliniko Embed)
When **Cliniko Embed** is enabled, we listen for:
- `cliniko-bookings-resize:<height>` → used to size the iframe wrapper
- `cliniko-bookings-page:<name>` → e.g., `schedule`, `patient`, `confirmed`

**Example**
```js
window.addEventListener('message', (e) => {
  if (e.origin !== 'https://<app>.<shard>.cliniko.com') return;
  if (typeof e.data !== 'string') return;

  if (e.data.startsWith('cliniko-bookings-resize:')) {
    const height = Number(e.data.split(':')[1]);
    iframe.style.height = height + 'px';
  }

  if (e.data.startsWith('cliniko-bookings-page:')) {
    // update internal step state, toggle local buttons, etc.
  }

  if (e.data === 'cliniko-bookings-page:confirmed') {
    // hide iframe, call submitBookingForm(..., true, { patientBookedTime: ... })
  }
});
```

---

## 🔐 Security Notes
- Store API keys in WordPress options with proper capability checks.
- Escape all attributes and HTML output from widget controls.
- Validate all incoming REST payloads via `PatientFormValidator`.
- Never expose Stripe **Secret** key to the browser — only Publishable key in frontend.

---

## 🧭 Roadmap
- Webhook-based reconciliation (where possible)
- More robust i18n and date/time handling
- Per-appointment custom notes/metadata mapper
- CLI commands for queue replays and health checks

---

## 📝 Changelog
See **[CHANGELOG.md](./CHANGELOG.md)** for full release notes. Latest: **1.3.4**

---

## 📜 License
MIT

---

## 🤝 Contributing
PRs are welcome! Please:
- Open an issue first for major changes
- Keep code formatted and typed (where applicable)
- Add context to commits and test changes in WP/Elementor

---

## 🧪 Dev Quick Ref

**Namespace**
```
App\
```

**Key Classes**
- Widgets
  - `App\Widgets\AppointmentTypeCard\Widget`
  - `App\Widgets\ClinikoStripeWidget`
- Clients
  - `App\Client\Cliniko\Client`
  - `Stripe\Client`
- Infra
  - `App\Infra\JobDispatcher` (Action Scheduler → WP-Cron fallback)

**Build/Debug**
- Enable `WP_DEBUG` and `WP_DEBUG_LOG`
- Xdebug (VSCode) recommended for step debugging
