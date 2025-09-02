## [1.2.8] - 2025-09-02

### Added
- **Exit & Draft Save System** for patient forms:
  - Automatically saves appointment progress (all answers + step index) into `localStorage`.
  - Patient-friendly **exit modal** when leaving mid-form:
    - Options: *Stay on this page*, *Leave without saving*, *Save & Leave*.
  - **Restore bar** displayed at the top of the form if a saved draft exists, with *Continue* or *Start Over* actions.
- **Unique draft keys per form/page**:
  - Each booking page (e.g. `/book/erectile-disfunction`, `/prescriptions`) maintains its own independent draft state.
- **Stripe integration awareness**:
  - Draft save/leave modal is skipped when patient completes final payment.
  - Draft is automatically cleared after successful payment and booking confirmation.

### Changed
- **Toasts**:
  - Unified with the existing `showToast()` helper.
  - Language adapted to be more patient-friendly (avoids technical terms like *draft*):
    - On restore: *“Your appointment form has been restored. You can continue where you left off.”*
    - On auto-save: *“Don’t worry, your appointment details will be saved automatically if you leave.”*
    - On submit: *“We’ve saved your appointment details while confirming your booking.”*
- **Navigation guard**:
  - In-form links now trigger the save/leave modal before navigation.
  - Browser refresh/close now triggers silent auto-save (no intrusive prompts).

### Internal
- Introduced versioned storage key schema: `clinikoFormProgress:v9:{pathname}`.
- Exposed `window.currentStep` globally for use across scripts.
- Preserves and restores **signature pad** input during draft restore.
- Hardened event binding to prevent duplicate observers in Stripe and exit-save logic.


## [1.2.4] - 2025-08-24

### Added
- **NotificationService**:
  - Centralized handling of success/failure emails
  - Prevents duplicates via transient lock
  - Supports Elementor-driven templates with placeholder substitution
- **Elementor widget controls**:
  - `send_email_on_success` / `send_email_on_failure` toggles
  - `success_email_template` / `failure_email_template` raw HTML inputs
  - Templates synced to WordPress options via new `ElementorTemplateSync`
- **Success toast style** on frontend:
  - Green checkmark icon, light green background
  - Distinguishable from error toast (red)

### Changed
- **ClinikoSchedulingWorker**:
  - Now delegates notifications to `NotificationService`
  - Success flow triggers confirmation email if enabled
  - Failure flow triggers refund + failure email if enabled
  - Removed inline `wp_mail` duplication in worker

### Internal
- Improved maintainability by decoupling email logic from worker
- Elementor template sync ensures admin edits persist to runtime options
- Unified toast helper supports both success and error styling


## [1.2.3] - 2025-08-21

### Added
- **Standardized error format** across backend:
  - Each error now contains `field`, `label`, `code`, `detail`
  - Easier to map to frontend fields and show friendly messages
- **Frontend error rendering**:
  - Errors are displayed as a styled `<ul>` list under the payment form
  - Each message is prefixed with the field label for clarity
- **Medicare validation rules**:
  - Medicare number must contain **exactly 10 digits**
  - Medicare reference number must be **a single digit between 1–9**

### Changed
- **ClinikoController**:
  - Now returns consistent error responses (`errors[]` array) for validation, API, and server exceptions
  - Validation errors return `422`, API/server issues return `500`
- **Stripe initialization**:
  - Refactored to singleton (`getStripe()`) to avoid multiple initializations
  - Payment button handler is attached only once (`paymentHandlerAttached` guard)
- **Multi-step form validation (`isCurrentStepValid`)**:
  - Added checks for Medicare fields (digit count & range)
  - Improved error messages for phone, postcode, and Medicare
- **Frontend feedback**:
  - Inline error messages styled in red
  - Borders reset automatically when user corrects the field

### Internal
- Unified backend and frontend error handling to use the same data contract
- Reduced duplication of Stripe initialization code
- Improved maintainability by centralizing validation logic both server- and client-side


## [1.2.2] - 2025-08-20

### Changed
- **PatientForm model refactor** for consistency and static analysis compliance:
  - `create()` now always returns a `PatientForm` or throws an `ApiException` (never `null`)
  - `delete()` now returns `void` and throws on failure instead of returning `true`
  - Improved error handling in `create()` and `update()` (guards against empty API responses)
  - Stricter type hints and docblocks added across methods
  - Cleaned up unused imports and redundant null checks

### Internal
- Aligns with the ongoing refactor of models and code abstraction
- PHPStan warnings removed by ensuring clear contracts in return types
- Safe handling of linked entity fetches with stronger defensive coding

## [1.1.6] - 2025-10-04

### Added
- New **Tools & Maintenance** page in the WordPress admin panel (`wp-cliniko-tools`)
- ✅ **Clear API Cache** button to purge all Cliniko GET request transients
- ✅ **Connectivity Test** for Cliniko and Stripe APIs with feedback display
- ✅ **Trigger Data Sync** tool to manually sync appointment types from Cliniko
- ✅ **System Info Display** section to show:
  - Site URL, Home URL
  - WordPress and PHP versions
  - Active theme and server software
  - Memory limit, execution time
  - Cliniko/Stripe key status and plugin version

### Changed
- Layout improvements to the Tools page:
  - Admin notices are clearer and styled
  - Maintenance actions grouped in a visual container
  - System info organized into a striped table for better readability

### Internal
- Created `App\Admin\Modules\Tools` module with proper hook registration
- Reused `CachedClientDecorator` logic and `AppointmentType::sync()` for backend utilities


## [1.1.5] - 2025-10-03

### Added
- Custom validation for patient fields:
  - Phone number: only accepts valid Australian numbers (minimum 10 digits after +61)
  - Postcode: must be exactly 4 digits
  - Email: format validation
- Visual feedback for invalid fields:
  - Red border and inline error message below the field
  - Styles are automatically removed when the user starts typing again

### Changed
- Improved "Back" button behavior: now properly centered and only shown when applicable

## [1.1.4] - 2025-10-02

### Added
- Toastify.js integration to replace native alert popups with subtle Material-like toast notifications
- Custom `showToast()` helper with theme-based styling and inline SVG icon
- Validation listeners to reset field outlines/borders when user starts typing or selecting

### Changed
- Required checkbox groups now display a red outline if no options are selected
- Improved UX: error messages are now unobtrusive and styled, without interrupting flow
- `isCurrentStepValid()` now handles grouped checkboxes, radios, and general field validation in a more modular way

### Fixed
- Prevented "false" output from appearing when conditionally adding `data-required-group` attribute
- Fixed issue where validation errors didn't clear styling when user corrected the field

## [1.1.3] - 2025-07-16

### Added
- Support for icons in multi-step form buttons (prev/next), with position and spacing control
- Elementor controls to manage button icons visually

### Changed
- Reorganized all widgets into structured `src/Widgets` folder
- Default button styles now inherit primary theme color

### Fixed
- Avoided PHP warning from `esc_attr()` when handling Dimensions arrays
- Improved flexibility and maintainability of inline styles and template logic

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
