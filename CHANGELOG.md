# 📦 Cliniko Stripe Integration — v1.1.0

**Release Date:** 2025-07-14

## ✨ New Features

- **New Widget**: Created `Cliniko: Appointment Type Card` Elementor widget.
  - Dynamically fetches Appointment Types from Cliniko.
  - Includes custom icon, description, price, and booking button.
  - Fully customizable layout and styles via Elementor controls.
- **Price Position Control**: Allow price label positioning (top-left, top-right, bottom-left, bottom-right).
- **Button Icon Toggle**: Toggle to show/hide icon inside the booking button.
- **Button Text Alignment**: Responsive alignment control for the button text (left, center, right).
- **Card Height Control**: Define fixed, percentage, or viewport height for uniform layout.

## 🎨 Style Enhancements

- **Default Paddings**:
  - Card: `20px` on all sides
  - Button: `12px` vertical, `20px` horizontal
- **Button Styling**:
  - Default background: theme’s primary color (via `var(--e-global-color-primary)`)
  - Default text color: white (`#fff`)
  - Rounded corners (8px radius)
- **SVG Icon Support**:
  - Added `svg` selectors to support custom uploaded icons with color and sizing controls

## ⚙️ Performance

- **Client Caching**:
  - Introduced `CachedClientDecorator` using WordPress `transient` API
  - Transparent caching of all GET requests to Cliniko API
  - TTL configurable (default 5 minutes)
  - Helper `cliniko_client($withCache)` to switch between cached and raw clients
- **Editor Mode Optimization**:
  - In Elementor editor, data is loaded from cache via `get_transient()` to prevent unnecessary API calls

## 🧹 Internal Improvements

- Moved widget rendering into `.phtml` template
- Organized controls into logical sections (Content, Layout, Style, Typography)
- Namespaced widget under `App\Widgets\AppointmentTypeCard`
- Updated widget `get_title()` and `get_name()` to clearly identify it as a **Cliniko** integration
- Default link for button set to `#` to prevent errors when left blank
