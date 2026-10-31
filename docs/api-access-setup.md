# API Access setup and developer handoff

Implemented 8 October 2026. **Dashboard/authentication redesign:** see [API dashboard and patient app authentication](api-authentication.md) for the current navigation and new native/social login paths. The administration module is under **Cliniko + Stripe → API Access**. It uses the plugin's existing Ultimate Member dependency.

## Start in WordPress administration

For new-patient onboarding, use **Patient login → Registration** and [the registration guide](api-registration.md). It covers WordPress, Google/Apple and external OAuth signup, the existing clinic verification email, safe patient roles and private progress/resend receipts. Registration and login are separate choices; signup never grants patient data access.

1. Open **Overview** and inspect the four getting-started decisions. Opening the administration area initializes the API tables if the database account has the necessary privileges.
2. Open **Publish API**. Keep the `cliniko-api` slug or choose a unique slug such as `api`.
3. Enable the master switch and save. Test `GET /wp-json/cliniko-api/v1/status`. On production, HTTPS is required. HTTP development requests require WordPress's environment type to be `local` or `development`.
4. Enable **Booking discovery**. Under **Published appointment types**, tick the services by name from the Cliniko list, then save. You can search the list; unchecked services are not published. The list is cached for up to five minutes per Cliniko connection. Previously selected services missing from the list remain checked for review, including when Cliniko is unavailable. Test `GET /appointment-types` under the same base URL.
5. Under **Published form templates**, tick the required blank questionnaires by name from Cliniko and save. No template IDs need to be typed. The independent search does not change selections. Previously selected templates remain checked if missing from the latest list or if Cliniko is unavailable; new templates are not automatically selected. Enable the **Published form templates** route group if developers should also retrieve their questions and submission shape. Bookings require an approved template even when public template browsing is disabled. The picker caches only template names and IDs, never completed patient forms.
6. For patient access, choose **Patient login → WordPress login · JWT**, **Social sign-in** (Google/Apple), or **External OAuth 2.0**. Register the corresponding app under **Applications**. WordPress credentials use existing patient accounts directly; Google/Apple/external OAuth additionally need an explicit **Patient link**. See [the current dashboard and authentication guide](api-authentication.md) for setup, request flows, session renewal and limitations.
7. Consult **Dashboard guide** for plain-language explanations of every dashboard area. Question-mark icons open help modals explaining what the setting does, whether you need it, what to enter, where to get it, an example and mistakes to avoid. Close with Escape, Done, the close button or the backdrop; keyboard focus returns to the original help icon. Optional technical notes are kept in a closed **For your developer: technical reference** section.
8. Open **Tutorials** for practical guides covering patient login, trusted partner servers, booking/payment, browser portals, API address changes and troubleshooting. The guides separate clinic decisions from developer tasks. Patient login is explained as a temporary login pass, and staff get a checklist of values to request from their developer. Protocol details, code examples and error-code tables remain available in the optional developer reference. Help modals link directly to the relevant guide.
9. Open **Endpoint documentation** for routes grouped into system, services/availability, blank forms, booking payment/progress, patient profile/appointments, patient booking, login/sessions, and registration/verification. Every route shows its access requirement even before you expand it, separately from its active/disabled status. Search by route, category, method or permission, or filter by access type. Expand a route for the exact header or JSON proof to send, complete URL, permissions, input rules, schemas and synthetic examples. This area remains developer-facing. Download active OpenAPI JSON after saving configuration changes.

The access labels distinguish public routes from patient-token routes, patient-token-plus-booking-secret routes, password/social/OAuth proof, private refresh tokens and private registration receipts. An approved app ID alone is **not** patient authentication: `/auth/challenge` only starts social sign-in. Password registration requires new account details and privacy consent, not an existing session, and grants no patient access before verification and login. `/auth/*` operations use their documented JSON fields and reject an `Authorization` header, including OAuth registration (which receives `access_token` in JSON). Public routes do not require a scope for anonymous requests; when a Bearer credential is supplied, its validity and permissions are checked. These documentation changes do not change route protection or publishing settings.

The master switch controls only the new managed namespace. Existing website `/v1` and `/v2` contracts remain separate. The new default base is `/wp-json/cliniko-api/v1`; `v1` describes this API's first contract and is not an editable dashboard version setting.

## External OAuth 2.0 guided setup

In **API Access → Patient login → External OAuth 2.0**, use the five-step **Patient app login (OAuth)** guided setup. General publishing stays under **Publish API**. This section describes external introspection only. WordPress-issued JWT sessions, direct Google/Apple login, session storage and new `/auth/*` routes are documented in [api-authentication.md](api-authentication.md).

1. **Choose a service:** select Keycloak, Okta custom authorization server, authentik, Auth0, Microsoft Entra ID, Google sign-in through Keycloak, Firebase Authentication, direct Google Sign-In, Amazon Cognito or another compatible service/adapter. The selected guide explains the service-specific setup screens, information to collect, compatibility limits and official references. The developer checklist is tailored to the selection and contains no saved passwords. Selecting a service does not configure it, change existing connection values or add validation support.
2. **Your sign-in service:** paste the service address and its API name supplied by the developer. These are the issuer and audience, not guesses based on the WordPress URL.
3. **Website connection:** enter the login-checking address, website connection ID and private password. A saved password is never filled back into the form; a blank field keeps it. Show entered password only reveals a value you are currently typing.
4. **Review & save:** the live checklist highlights missing values. Enable sign-in only when all details are ready, or leave it off to save an incomplete draft. **Save patient-login settings** saves only provider/sign-in values and, when entered, the website connection password. It does not change API addresses, publication, groups, limits or origins. **Save API settings** on the general tab preserves the saved login configuration. API exposure and application permissions are not automatically enabled.
5. **Check & finish:** check the saved connection, then follow the links to approve the app and link a test patient. Ask your developer to test an actual app login before rollout.

The connection-check result has a timestamp and is tied to the saved provider configuration and password through a keyed fingerprint; it stores no credential or token. Saved changes invalidate old results. Unsaved login changes disable the check button until saved or undone. Passing does not prove patient login, token claims, provider scopes, account verification or ownership. The wizard works without JavaScript too: all steps are shown and existing server validation still applies.

The tabs have separate nonce-protected save actions; posting fields owned by the other tab cannot modify them. Each save uses the current saved values for the untouched section rather than hidden copies from an older page. Login notices and connection-check results return to **Patient login**. Existing stored settings and credentials need no migration.

Named service guides are onboarding assistance, not live integration certification. External OAuth token validation remains RFC 7662 introspection with confidential client-secret Basic authentication and the required claims listed below. Keycloak and Okta need provider-side configuration; authentik may need extra verified response fields or a separate adapter. Auth0 and Entra guides describe validation work not implemented here and cannot be enabled as direct connections. A separately implemented compatible adapter uses the custom-service path; selecting that path does not create an adapter. See the official sources linked in each dashboard guide.

The Google/Firebase/Cognito guides are also available in that first-step selector:

- **Google sign-in through Keycloak** explains the broker setup and separation of Google broker credentials from WordPress's Keycloak connection. The API still accepts Keycloak passes and links the verified Keycloak subject to the existing patient account, not a Google email. It uses the configured Keycloak checker, without adding a direct Google verifier.
- **Firebase Authentication** and **Amazon Cognito** remain planning guides only. **Google Sign-In — direct** now points to the separate implemented Social sign-in section; it cannot be enabled as an introspection provider. They explain the account/app details to collect and the missing server-side verification work. Their copied checklists request an integration plan rather than invented introspection credentials. These selections can be saved with patient sign-in off, but cannot enable direct access while the validation methods are unimplemented. Steps 2/3 warn that the existing connection fields are not direct-service setup fields.
- No guide asks for a Firebase service-account private key, Firebase API key, or AWS access key in the website-connection password field. Google login is not permission to read clinic records; local app approval and verified patient linking remain required.

For Keycloak/Okta, **Suggest checking address from service address** builds a local URL suggestion only after the issuer matches the selected service's format. It makes no network request, never supplies an audience or credentials, and will not overwrite a different address already entered. Confirm the suggestion against your actual service metadata. Existing configuration fields/passwords are preserved when switching guides; replace them before connecting a different service rather than sending old credentials to a new service. Existing installations without a saved provider choice remain on the compatible custom-service path.

Dashboard labels map to the existing technical fields without changing the API contract:

| Dashboard label | Developer field |
| --- | --- |
| Sign-in service address | Issuer (`iss`) |
| API name at the sign-in service | Audience (`aud`) |
| Address for checking patient logins | Introspection endpoint |
| Website connection ID | Confidential introspection client ID |
| Website connection password | Confidential introspection client secret |

For external OAuth, the plugin is a resource server, not an authorization server: it does not issue provider authorization codes, registration or refresh tokens. Its separate `/auth/login`, `/auth/challenge`, `/auth/social`, `/auth/refresh` and `/auth/logout` operations handle WordPress-issued patient JWT sessions only. The provider must implement Authorization Code + PKCE for native apps and handle authorized redirect URIs and refresh-token rotation. See [OAuth security guidance](https://www.rfc-editor.org/rfc/rfc9700).

Configure the provider's exact issuer, this API's audience, HTTPS introspection endpoint, resource-server client ID and confidential introspection secret. The secret is encrypted using the plugin's existing secret-option support. A blank secret field preserves its current value.

The introspection endpoint must support client-secret Basic authentication, return HTTP 200 JSON, and provide these claims for valid access tokens:

```json
{
  "active": true,
  "token_type": "Bearer",
  "exp": 1893456000,
  "iss": "https://identity.example.com",
  "aud": "cliniko-api",
  "sub": "provider-patient-identifier",
  "client_id": "registered-mobile-client-id",
  "scope": "patient:read appointments:read booking:create"
}
```

This implementation deliberately requires more claims than the minimum introspection standard. Providers omitting them need configuration or an adapter before use. Token state is checked on every authenticated request; positive introspection results are not cached. See [RFC 7662](https://www.rfc-editor.org/rfc/rfc7662).

Register the mobile client ID and approved scopes locally. Map the exact issuer + subject to the existing WordPress patient user ID. The account must be active, have the configured patient role, and have a verified Cliniko link. An email supplied in a token is never used for automatic linking.

Use **Check saved connection** in the final guided step to verify that saved credentials can introspect an invalid synthetic token and receive `active: false`. Then test a real staging patient; the connectivity check does not validate their scopes, claims or mapping.

Patient requests use:

```http
Authorization: Bearer <provider-access-token>
```

Never embed server-integration keys or introspection secrets in a native application.

## Booking flow

For configured, structure-only JSON, create an independent flow in **API Access → Booking flows**; no shortcode setup is required. Choose appointment type, form, scheduling, payment and optional answer checks. API/web records are marked separately, but reuse the same booking settings and validation helpers. Historical website-linked publications remain labelled Legacy and can be made API-only at the same address without changing their web form. See [API-only booking structures](api-booking-structures.md) and the step-by-step [third-party booking and payment guide](api-third-party-booking-payment-guide.md). `GET /booking-flows/{slug}` returns the current contract; `POST /booking-flows/{slug}/booking-attempts` resolves it server-side and uses the existing booking execution. Both require a verified patient token. The Endpoint documentation tab includes both contracts and your published URLs.

**Patient-only API policy:** enable **Authenticated patient booking** and **Booking payment and progress**, and configure patient sign-in and the app's `booking:create` / `booking:read` permissions. The former Guest booking setting retains its internal `booking` key but no longer publishes guest creation. See [the patient booking contract and migration notes](api-patient-booking.md). Website/shortcode guest routes are unchanged by this policy.

1. Retrieve an approved appointment type, practitioner, availability and form structure.
2. Sign in as an active verified patient, then `POST /me/booking-attempts` with `Authorization: Bearer <patient-access-token>`. Supply only scheduling fields in `patient`; the website resolves identity from the verified account. `POST /booking-attempts` is not exposed.
3. Save the returned attempt ID and secret in secure client storage. Attempt access expires after 24 hours; unfinished drafts remain subject to the existing 30-minute cleanup worker.
4. If payment is required, use `/booking-attempts/{id}/stripe-payment` or `/booking-attempts/{id}/tyro-payment`. Stripe uses `tok_...` card tokens; Tyro transactions must match the server-calculated amount and invoice reference.
5. Finalize with `/booking-attempts/{id}/finalize`, sending the original patient's access token, attempt token and an `Idempotency-Key`. Reuse the same idempotency key on retries.
6. Read progress through `GET /booking-attempts/{id}`.

```http
Authorization: Bearer <original-patient-access-token>
X-ES-Attempt-Token: <attempt-secret>
Idempotency-Key: <unique-finalization-key>
```

Every payment, finalization and progress request requires both credentials, the original approved app and active verified patient, and the same linked Cliniko patient. Guest attempts, missing ownership metadata and server keys are rejected. `/payments/tyro-sdk-token` also requires patient authentication. Legacy `/v2/booking-attempts/*` routes reject attempts created in the managed namespace, preventing bypass of the new scope, lock and retry controls.

Finalization is serialized using a database advisory lock. A completed attempt returns its existing result. An interrupted attempt that already began creating an appointment requires reconciliation rather than blindly retrying appointment creation. Guest booking and legacy queued form work no longer overwrite an existing Cliniko patient profile.

## Administration and storage

For an HTTP frontend during development, set the site's existing `WP_ENVIRONMENT_TYPE` configuration to `local` or `development` (for example `define('WP_ENVIRONMENT_TYPE', 'local');` in `wp-config.php`, before WordPress loads), then save `http://localhost:3000` under Allowed browser origins. Use your frontend's actual port. An origin has no page path: `http://localhost/easyscripts` is not an origin; use `http://localhost`. Do not enable development mode on a production site. Changing the origin setting does not weaken patient authentication or alter provider/introspection URL requirements.

- Client approvals can be edited for scopes and expiry, revoked or rotated. Rotation immediately invalidates a server key. Plaintext server keys are never persisted.
- A namespace change retains the previous slug as an alias for 30 days and adds deprecation/sunset headers. Update installed apps before expiry.
- Browser CORS origins are exact HTTPS origins, or HTTP origins when WordPress's environment type is `local` or `development`. Staging/production reject HTTP origins, including ones saved earlier in development. Requests carrying disallowed Origin headers are rejected. Native clients normally omit Origin.
- Read/write budgets use shared, atomic database counters per remote connection IP and identified application. Forwarded IP/User-Agent headers supplied by clients are ignored.
- API responses are marked `private, no-store` and include a generated request ID.
- Audit events retain operation/client/user IDs, response status and request ID. API audit logging excludes tokens, bodies, patient answers and contact details. Existing plugin debug logging has its own configuration.
- The API initializes three tables: `{prefix}cliniko_api_audit`, `{prefix}cliniko_api_rate`, `{prefix}cliniko_api_idempotency`. Daily WordPress cron removes old records. No uninstall/data-deletion workflow is introduced.

## Compatibility changes to review before deployment

The following operations now return **410 Gone** and cannot be re-enabled through API Access:

```text
POST /wp-json/v1/send-patient-form
POST /wp-json/v1/tyrohealth/charge
GET  /wp-json/v2/patient-access/request-status
POST /wp-json/v2/patient-access/request-complete
```

Migrate legacy form/payment consumers to the verified booking-attempt flow. Patient-history email-code verification and direct magic links remain available; cross-device token handoff is removed. Headers forwarded by your CDN must be translated to a trusted `REMOTE_ADDR` at the web-server layer to avoid grouping all users behind one rate-limit identity.

This initial release provides read-only patient profile/appointments. Profile writes, email changes, attachment APIs and communications APIs in the planning document are deferred.

## Verification

```text
php tests/managed_api_test.php
php tests/api_patient_booking_flow_test.php
php tests/api_booking_structure_test.php
php tests/request_guard_test.php
php tests/booking_attempt_flow_test.php
php tests/authenticated_patient_routes_test.php
php tests/patient_access_challenge_test.php
php tests/worker_flow_test.php
php tests/payment_endpoints_test.php
php vendor/phpstan/phpstan/phpstan.phar analyse --configuration=phpstan.neon --memory-limit=2048M
node --check src/Admin/Modules/ApiAccess/assets/api-access.js
```

With a WordPress source directory, validate the schemas against the actual core REST validator without a database:

```text
php tests/managed_api_schema_test.php /path/to/wordpress
```

With Node 22+, PHP and Chrome/Chromium installed, check the actual admin HTML, styles and scripts in a headless browser without a database:

```text
node tests/api_access_help_browser_test.mjs
```

Set `BROWSER_PATH` if the browser is installed outside the detected locations. These read-only fixture checks cover native modal opening, keyboard focus, Escape/close/backdrop dismissal, narrow viewports, contextual help, tutorial rendering and endpoint filtering. A screenshot is saved in the test's isolated temporary browser profile for visual review.

With a running local WordPress database, perform the registration/documentation smoke check:

```text
php tests/managed_api_wordpress_smoke.php /path/to/wordpress
```

The smoke check uses in-memory option filters and synthetic examples. It does not create bookings or submit payment/Cliniko requests. It requires the local WordPress installation to boot successfully.

Current validation: the 56 API/dashboard regression scenarios, 18 registration scenarios and four authenticated booking-flow scenarios and eleven API-only/legacy booking-structure scenarios plus the web/API-origin save regression pass, along with the seven targeted regression suites, PHPStan (2 GB analysis limit), PHP/JavaScript syntax checks, headless Chrome admin interactions and actual WordPress schema validation. Both picker tests use synthetic Cliniko data and cover pagination, account-specific caching, escaped names, explicit selection, provider failure, missing selections, independent filtering and checkbox submissions. Template cache tests check that only names and IDs are stored. Guided-login checks cover step navigation, focus, incomplete drafts, named missing values, copied-checklist privacy, saved-only test submission, password visibility and stale connection results. Tab tests verify separate forms/save actions, visibility and preservation of the untouched section, including ignored cross-section fields. Service tests cover legacy/custom compatibility, service selection, provider-specific hints, safe URL suggestions, unsupported-method blocking and preservation of entered credentials. Google/managed-service tests additionally cover collected setup details, planning-only checklists, direct-field warnings and the distinct Keycloak broker issuer/subject. New JWT/social tests cover real signatures with synthetic provider keys, nonce/client/audience/presenter binding, key rotation, missing storage, credential rejection, refresh rotation/replay, logout/revocation and live approval checks. New browser tests cover the sidebar, authentication sections, app-type fields, synthetic walkthroughs and narrow-screen layouts. No live provider account was configured or certified by these tests. The current real WordPress smoke check now boots successfully and validates registration of all 28 route operations, their schemas/examples and endpoint documentation using in-memory settings only. It does not submit a real login or make Cliniko/payment requests. In the earlier wider non-live suite run, 22 passed; two older suites failed due to missing WordPress test mocks (`WP_User::has_cap` in `patient_email_change_service_test.php`, and `get_option` in `ultimate_member_patient_approval_test.php`).
