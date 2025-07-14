
# 📦 Cliniko Stripe Integration Plugin for WordPress

This plugin integrates **Cliniko** and **Stripe** into your WordPress site using Elementor widgets and a custom backend connection. Designed for medical practices, it allows patients to book and pay for appointments in a smooth, customizable interface.

---

## 🚀 Features

- 🔗 **Cliniko API Integration** – Load real-time appointment types and patient forms.
- 💳 **Stripe Integration** – Process payments before confirming appointments.
- 🧱 **Elementor Widgets**:
  - `Appointment Type Card` – Display single appointment type in card layout
  - `Cliniko Stripe Booking Form` – Collect patient data, show dynamic form and payment step
- 🧠 **Smart Flow** – Load patient forms from Cliniko and auto-send form data after payment.
- ⚡ **API Caching** – Reduce API calls using WordPress transients
- 🎨 **Customizable** – All widgets are styleable with Elementor controls and dynamic classes

---

## 🧰 Requirements

- WordPress >= 5.9
- PHP >= 7.4
- Elementor >= 3.10
- Valid API keys for:
  - [Cliniko](https://api.cliniko.com/v1)
  - [Stripe](https://dashboard.stripe.com/apikeys)

---

## 📦 Installation

1. Upload the plugin folder to `/wp-content/plugins/` or install via zip.
2. Activate it via WordPress admin.
3. Go to **Settings > Cliniko Stripe Integration**.
4. Fill in:
   - **Cliniko API Key**
   - **Stripe Publishable Key**
   - **Stripe Secret Key**
   - **Business ID (Cliniko)** – used to load resources like appointment types and practitioners

---

## 🧩 Elementor Widgets

### 1. `Cliniko: Appointment Type Card`

Displays a single appointment type with name, description, price, and a CTA button.

**Key Controls:**
- Select appointment type
- Set icon and icon color
- Price label and position (top-left, top-right, etc.)
- Button text, link, and icon
- Custom class support (e.g., for Tailwind or hover transitions)

**Advanced:**
- Use `button-hover-slide` class for a hover animation:
  ```css
  .button-hover-slide {
    background: linear-gradient(to left, var(--e-global-color-primary) 50%, var(--e-global-color-secondary) 50%);
    background-size: 200% 100%;
    background-position: right;
    transition: background-position 0.4s ease;
    color: white;
  }
  .button-hover-slide:hover {
    background-position: left;
    color: black;
  }
  ```

---

### 2. `Cliniko: Stripe Booking Form`

Creates a full booking workflow:
1. Pre-form (optional)
2. Cliniko dynamic patient form (from template)
3. Stripe payment form
4. Auto-creation of appointment in Cliniko after payment

**Key Controls:**
- Select Appointment Type
- Select Practitioner
- Map input fields for name, email, phone (optional)
- Toggle multi-step form
- Enable or disable patient data fields

**Behind the scenes:**
- Patient form templates are fetched from Cliniko
- Payment is securely handled with Stripe Elements
- After payment, patient + appointment + notes are created in Cliniko via API

---

## 🔐 How to Connect Cliniko and Stripe

### Cliniko:
1. Log in to [Cliniko](https://www.cliniko.com/)
2. Go to **My Info > API Keys**
3. Generate a key and copy it.
4. Paste it in **Settings > Cliniko Stripe Integration > Cliniko API Key**

> This key allows the plugin to fetch appointment types, practitioners, and patient forms, and create patients/appointments.

---

### Stripe:
1. Log in to [Stripe Dashboard](https://dashboard.stripe.com/apikeys)
2. Copy your **Publishable** and **Secret** keys
3. Paste them into the plugin settings

> The plugin uses Stripe Elements to securely process credit cards before submitting booking data to Cliniko.

---

## 🛠 Developer Notes

- Namespace: `App\`
- Widget Classes:
  - `App\Widgets\AppointmentTypeCard\Widget`
  - `App\Widgets\ClinikoStripeWidget`
- Controls are defined in PHP and rendered using `.phtml` templates
- API access via `src/Client/Cliniko/Client.php` and `Stripe\Client`
- Payment endpoint: `wp-easyscripts-payment-api.php`

---

## 📃 License

This plugin is open-source and licensed under the MIT License.

---

## 🙋‍♂️ Contributing

Fork the repo and send PRs, or open issues with feature requests or bug reports.
