# Shared plugin admin workspace

Updated 9 October 2026. All plugin-owned admin pages now use the same workspace identity as API Access: a neutral page header, white sidebar, teal actions, pink selection/highlight states, light content surface, consistent cards/controls and responsive navigation.

## Shared foundation

`src/Admin/assets/admin-workspace.css` owns the shared `cliniko-admin-ui` tokens and workspace primitives. Each admin area enqueues it before its feature-specific stylesheet. It is not loaded on patient-facing pages.

| Token | Purpose |
| --- | --- |
| `--cliniko-admin-accent` | `#007fa3`: primary actions and focus indication |
| `--cliniko-admin-accent-hover` / `--cliniko-admin-link` | Darker teal for hover and readable links on pale-blue surfaces |
| `--cliniko-admin-highlight` | `#cc3057`: selected navigation, highlights and destructive action links |
| `--cliniko-admin-accent-soft` | `#F0F8FB`: selected navigation and supporting callouts |
| `--cliniko-admin-ink` / `--cliniko-admin-muted` | `#4b384c` main text and a lighter plum for supporting text |
| `--cliniko-admin-line` | Borders and dividers |
| `--cliniko-admin-surface` | `#F0F8FB`: workspace background |

White cards and conventional success/warning status colors remain. Teal primary buttons with white labels and pink selected labels meet a 4.5:1 contrast ratio; small links on pale-blue surfaces use a darker teal for readability. Template preview content and saved patient-facing colors are not changed.

Use the shared foundation for future plugin admin modules instead of introducing another color palette. API Access aliases its existing `--api-*` tokens to this foundation; Template Builder's editor `--builder-*` tokens also use it.

## Template Builder layout

- Primary workspaces appear in the left sidebar on desktop, with icons and an accessible `aria-current="page"` state.
- Existing profile, booking, dashboard and shortcode sections appear in a secondary horizontal navigation within the content area.
- Narrow screens use independently scrollable navigation and stacked editor/publish panels. Page content does not require horizontal scrolling.
- Existing editor tabs, field controls, preview dialogs, configuration IDs, nonce-protected forms and save actions are retained. A shared preview-close handler handles dialogs located outside their editor forms.
- Admin notices are moved outside the complete workspace rather than being inserted against a nested sidebar node.

The styling changes do not alter saved template settings, patient-facing CSS, API permissions or booking rules. Patient preview content is excluded from the new admin input styling so it can retain its configured form appearance.

## Settings, operations and Getting Started

`src/Admin/AdminWorkspace.php` renders a reusable header/sidebar/content shell for Settings, Tools, Debug and Getting Started. Its assets are loaded only for those four page slugs. Their sidebar links to all six plugin workspaces; Template Builder and API Access retain their existing context-specific sidebars.

- **Settings:** API Credentials and Patient Accounts remain secondary tabs in the same Settings API form. Switching tabs changes visibility and the URL, not values or which settings are submitted. Connection-test controls and existing field names are retained.
- **Tools:** maintenance actions have separate cards with plain-language descriptions. Existing cache-clear, connection-test and sync handlers, forms and nonces are unchanged. System information uses the same table treatment.
- **Debug:** responsive metric cards, styled settings, log actions and filters. The wide log table scrolls inside its own keyboard-focusable region rather than making the whole page wider. Capture, retention, export and clear behavior remain unchanged.
- **Getting Started:** uses the shared neutral header, pink navigation and teal progress accents, and consistent cards. Search, setup checks and all existing guide content are retained. The searchable contents list sits above the documentation to avoid a second competing sidebar.
- **Mobile navigation:** `admin-pages.js` brings the selected workspace into view within the horizontal navigation without scrolling the page itself.

`admin-pages.css` is scoped to these plugin workspaces. WordPress core screens, Elementor's editor, patient shortcodes and frontend assets are not restyled.

## Validation

```text
node tests/template_builder_ui_browser_test.mjs /path/to/wordpress
node tests/admin_pages_ui_browser_test.mjs /path/to/wordpress
node tests/api_access_help_browser_test.mjs
php tests/template_migration_test.php
```

The Template Builder browser suite renders 18 actual WordPress screens, blocks outgoing HTTP, masks plugin configuration in memory, and never submits forms. It checks desktop/mobile navigation, active colors, editor tabs, desktop/mobile previews, original save actions/nonces, notice relocation and JavaScript errors. Screenshots are saved in its isolated temporary browser directory. A running local WordPress database and Chrome/Chromium are required; set `BROWSER_PATH` if needed.

The remaining-pages suite uses the same real-WordPress fixture for Settings, Patient Accounts, Tools, Debug and Getting Started at 1440, 1200, 980 and 390 pixels, reserving desktop space for the WordPress menu. It checks page headings, selected navigation, settings tab visibility and unsaved values, original form actions/nonces, guide search/anchors, and overflow. Credentials are masked in memory; log queries return synthetic rows and schema installation is skipped in the test process. No settings are saved, maintenance actions run, or live logs read/exported/cleared by this test.
