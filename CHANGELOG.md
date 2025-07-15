## [1.1.2] - 2025-07-14

### Added
- Styled error message display below payment form (instead of alert)
- Graceful fallback if Stripe fails to initialize.

### Changed
- All backend and frontend errors now properly hide the loading overlay
- Improved user feedback for booking errors from backend or Stripe

### Fixed
- Loader would not hide on token or submission failures
- Alerts were replaced with inline error messages for better user experience

# 📦 Cliniko Stripe Integration — v1.1.1

**Release Date:** 2025-07-14

## ✨ Enhancements

- **Custom Button Class**: Added control to assign dynamic CSS classes to the booking button.
- **Hover Slide Effect**: Enabled optional right-to-left hover background transition using Elementor global colors (`--e-global-color-primary` → `--e-global-color-secondary`).
- **Improved Flexibility**: Users can now apply custom animations, Tailwind classes, or raw CSS using the dynamic class feature.

## 🧰 Developer Notes

- Updated button HTML in `render.phtml` to support dynamic class injection.
- Added CSS guidance and usage examples for hover transitions based on global variables.


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
