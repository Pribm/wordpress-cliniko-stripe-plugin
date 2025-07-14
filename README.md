
# 📦 Cliniko Stripe Integration Plugin for WordPress

This plugin provides a seamless integration between **Cliniko** and **Stripe**, designed for medical or health-oriented websites built with **WordPress** and **Elementor**.

It includes a fully customizable Elementor widget for displaying Cliniko appointment types with booking capabilities and Stripe payment integration.

---

## 🚀 Features

- 🔗 **Cliniko Integration**: Fetches real-time data from Cliniko API (e.g., appointment types).
- 💳 **Stripe Payment Integration**: Easily collect payments before booking.
- 🧱 **Elementor Widget**: Drag-and-drop card with:
  - Icon
  - Appointment type
  - Price
  - Description
  - Booking button
- 🎨 **Fully Customizable**: Control layout, typography, paddings, icon, colors, and hover behavior.
- ⚡ **Cached API Responses**: Performance optimized using WordPress transients.
- 🛠️ **Developer-friendly**: Clean architecture with client, contracts, services, DTOs, and templates.

---

## 🧰 Requirements

- WordPress >= 5.9
- PHP >= 7.4
- Elementor >= 3.10
- Cliniko API Key
- Stripe API Key

---

## 📦 Installation

1. Clone or download the plugin:
   ```bash
   git clone https://github.com/Pribm/wordpress-cliniko-stripe-plugin.git
   ```

2. Upload it to `/wp-content/plugins/`

3. Activate the plugin in the WordPress admin panel.

4. Go to **Settings > Cliniko Stripe Integration** to connect your API keys.

---

## 🧩 Elementor Widget: `Cliniko: Appointment Type Card`

After activation, search for `Cliniko: Appointment Type Card` in the Elementor editor.

### Available Controls

#### Content
- **Appointment Type** (dropdown from Cliniko)
- **Icon** (FontAwesome or custom SVG)
- **Price Label**
- **Button Text**
- **Button Link**

#### Layout & Style
- Card paddings, border radius, background
- Icon color and size
- Button text alignment, icon toggle
- Button border radius, padding, color
- **Price position:** top-left, top-right, bottom-left, bottom-right
- **Custom button class** (for advanced animations)

---

## 🎨 Hover Slide Animation (Optional)

Add the following class in the widget settings:
```text
button-hover-slide
```

Then define this CSS globally:
```css
.button-hover-slide {
  background: linear-gradient(to left, var(--e-global-color-primary) 50%, var(--e-global-color-secondary) 50%);
  background-size: 200% 100%;
  background-position: right;
  transition: background-position 0.4s ease, color 0.4s ease;
  color: white;
}
.button-hover-slide:hover {
  background-position: left;
  color: black;
}
```

---

## 🧪 Development

- Namespace: `App\`
- Widget class: `App\Widgets\AppointmentTypeCard\Widget`
- Controls defined in `controls.php`
- Render template: `render.phtml`
- API access via `src/Client/Cliniko/Client.php`
- Stripe integration handled in `wp-easyscripts-payment-api.php`

---

## 📃 License

This plugin is open-source and licensed under the MIT License. See [`LICENSE`](LICENSE) for details.

---

## 🙋‍♂️ Contributing

Feel free to fork the repo and submit PRs. Issues and feature suggestions are welcome!

