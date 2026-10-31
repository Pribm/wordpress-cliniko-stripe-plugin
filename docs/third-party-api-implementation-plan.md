# Third-Party and Mobile API Implementation Plan

**Plugin:** WordPress Cliniko Stripe Plugin  
**Document status:** Implementation authorized; provider configuration and staging rollout remain deployment decisions  
**Created:** 7 October 2026  
**Primary goal:** Define a secure, manageable API that third-party applications, including native mobile apps, can consume through a published WordPress website running this plugin.

## 1. Purpose of this document

This document is the implementation plan and decision record for a new API-management capability. It is deliberately being written before code so that product, security, and engineering expectations can be agreed first.

The plan covers:

- what the plugin currently exposes;
- how shortcode modules relate to REST routes;
- the proposed API architecture;
- mobile and server-to-server authentication;
- route groups and permissions;
- the WordPress administration module;
- security, privacy, monitoring, testing, and rollout;
- open decisions that must be approved before implementation.

The existing `docs/openapi.yaml` is not being treated as authoritative. A replacement API contract will be produced from the approved implementation.

### Implementation snapshot — 8 October 2026

**9 October 2026 — linked booking structures:** API Access now publishes explicit links to saved patient shortcode booking setups. GET `/booking-flows/{slug}` returns structure and validation only; POST `/booking-flows/{slug}/booking-attempts` resolves the current configuration and reuses authenticated booking execution. No presentation hints are exported. Shared configuration/rule/scheduling helpers serve shortcode and API paths. Both routes require verified patient tokens; stale definitions and answer/configuration tampering are rejected. See [the implementation guide](api-booking-structures.md).

**Patient-only booking decision:** guest booking creation is no longer exposed in any managed namespace or migration alias. Booking creation, payment, SDK-token issuance and progress require an active verified patient access token. Attempt operations additionally require the original app/patient and private attempt secret. Server keys are limited to published catalog/availability. Registration remains pending-account onboarding, not patient access. Website/shortcode guest flows are unchanged. This decision supersedes any guest-booking or server-key booking proposals later in this planning record. See [the current booking contract](api-patient-booking.md).

**Dashboard and authentication redesign:** the current navigation is Overview, Publish API, Patient login, Applications, Patient links, Requests & responses, Endpoint documentation, Activity, Tutorials and Dashboard guide. Patient login separates WordPress/JWT, external OAuth 2.0, direct Google/Apple social sign-in and website-issued session management. The WordPress/JWT and social exchange routes are implemented, not merely provider-selection guidance. See [the current authentication implementation and rollout guide](api-authentication.md); it supersedes earlier external-only authentication assumptions in this planning record. All new login methods remain disabled by default and require explicit app approval and patient checks.

**Registration addition:** Patient login now also has Registration settings. Password, verified Google/Apple and verified external OAuth identities can create a new pending WordPress patient account with explicit privacy consent and `account:register` approval. The existing verification-email/Cliniko binding flow runs internally; no patient token is issued by signup. Existing emails/identities are never overwritten or merged. Private receipt-based progress and resend operations complete app onboarding. See [the current registration guide](api-registration.md); it supersedes earlier no-registration assumptions in this planning record.

The first-release API and administration module are implemented in the working tree. Open **Cliniko + Stripe → API Access** for Getting started, Settings & route groups, Patient login, Applications & patient mappings, Tutorials, Dashboard guide, Endpoint documentation and Audit events. The patient-login guided setup, service-specific guidance, credentials and connection check have their own tab; its save action preserves general API configuration, and general settings saves preserve login configuration. Fields include keyboard-accessible help modals explaining the purpose, whether the setting is needed, what to enter, examples and cautions in everyday language. Tutorials separate clinic tasks from developer tasks and cover patient login, partner servers, booking/payment, browser portals, address changes and troubleshooting. Technical notes and code examples are kept in optional developer-reference sections; Endpoint documentation remains developer-facing. New endpoints remain disabled by default.

Implemented features:

- configurable slug and a 30-day deprecated namespace alias on active-name changes;
- five publishable feature groups, explicit published Cliniko ID lists and 28 endpoint operations, including ten independently gated authentication/session/onboarding operations;
- WordPress credential login with revocable patient JWTs, encrypted signing material, rotating refresh tokens and replay-triggered session revocation;
- nonce-bound Google/Apple identity-token exchanges using fixed provider keys, per-app audiences and explicit existing-patient links; no automatic email linking;
- scoped, expiring, revocable server keys and locally approved OAuth applications;
- OAuth access-token validation through a configured RFC 7662 introspection endpoint, with explicit issuer/subject-to-WordPress mappings;
- verified active patient profile/appointment reads and authenticated booking preflight;
- authenticated patient booking attempts, patient-owned payment/progress routes, server-verified Tyro payments, Stripe idempotency and serialized finalization;
- shared-database rate limits, idempotency records and privacy-limited audit events;
- a searchable endpoint catalog using the registration/validation definitions and an active-only OpenAPI JSON download;
- a guided setup checklist, dashboard reference guide and technical field help;
- permanent retirement of the unsafe legacy form mutation, unverified Tyro charge and cross-device patient-token handoff.

The OAuth provider remains an external deployment choice. The module supports HTTPS introspection with client-secret Basic authentication and requires `active`, `token_type=Bearer`, `exp`, `iss`, `aud`, `sub`, `client_id` and `scope`. The provider owns browser login, Authorization Code + PKCE, redirect URI approval and refresh-token rotation. A saved-provider connection test sends only an invalid synthetic token. It does not prove that a real staging patient can authenticate.

Deferred features: patient profile writes/email change, attachment upload/download APIs, communications APIs, automatic identity provisioning, JWT/JWKS validation, provider-specific login UX and legacy-consumer migration. Their sections below describe later work rather than functionality already shipped. Live production rollout and payment/Cliniko sandbox verification remain deployment checks.

Validation: new API/dashboard regression tests, WordPress's actual REST schema validators, PHP syntax checks and PHPStan pass. A full WordPress boot smoke test was attempted but the local database connection was unavailable. Two unrelated older suites have incomplete WordPress mocks (`WP_User::has_cap` and `get_option`); the other 22 non-live suites pass. See `docs/api-access-setup.md` for setup, compatibility changes and testing commands.

## 2. Correction to the current architecture picture

It is not quite correct to say that all existing plugin routes are public.

The plugin currently has three different access models:

| Access model | Examples | Current protection |
|---|---|---|
| Public/guest routes | Availability, practitioners, public booking attempts, payment helpers | Public request guard, rate limit, and attempt tokens where applicable |
| Short-lived patient-history access | Appointment history and form prefill | Email challenge or magic link followed by a short-lived patient-access token |
| Authenticated patient routes | `/v2/patient/me`, appointments, attachments, communications | WordPress login, configured patient role, verified patient link, and resolvable Cliniko patient |

The authenticated routes are already registered in:

- `src/Routes/AuthenticatedPatientRoutes.php`
- `src/Routes/AuthenticatedBookingRoutes.php`
- `src/Routes/PatientLinkRoutes.php`

### What `src/Admin/Modules` currently does

`src/Admin/Modules` contains two related concerns:

1. WordPress administration screens and saved configuration.
2. Patient-facing shortcode renderers and form handlers used to build the website experience.

The shortcode modules are not the only implementation of authenticated patient behavior. Much of the reusable behavior already lives in:

- `src/Controller`
- `src/Service`
- `src/Model`
- `src/Authorization`

The REST routes call these controllers and services directly. The shortcodes also call services/models, sometimes with additional UI-specific behavior.

### Desired separation

```text
WordPress admin UI
    -> manages API configuration, clients, policies, and visibility

Website shortcodes/widgets
    -> render HTML and handle website interactions
    -> call shared application services

Third-party/mobile REST API
    -> validates API authentication and scopes
    -> calls the same shared application services

Shared services/models
    -> enforce patient ownership and business rules
    -> communicate with Cliniko, Stripe, and Tyro
```

The new API must not call shortcode renderers or parse shortcode HTML. Any useful rule currently trapped inside a shortcode module should first be extracted into a shared service.

## 3. Proposed outcome

Create an **API Access** module that allows an administrator to safely manage a stable third-party API.

The result should provide:

- a configurable API namespace/base slug;
- explicit public, patient, and server-integration route groups;
- modern mobile authentication;
- scoped permissions;
- client/application registration;
- route-group enable/disable controls;
- rate-limit policies;
- audit and usage visibility;
- revocation controls;
- accurate API documentation;
- a controlled migration away from unsafe legacy routes.

### Configurable API base

```text
/wp-json/{api_slug}/v1/...
```

The administrator should choose the **API base slug** in the API Access dashboard. The plugin should append and manage the contract version. Examples:

```text
Setting: cliniko-api  -> https://example.com/wp-json/cliniko-api/v1/...
Setting: api          -> https://example.com/wp-json/api/v1/...
Setting: patient-api  -> https://example.com/wp-json/patient-api/v1/...
```

WordPress supplies the `/wp-json/` portion. Removing that portion and publishing directly under `/api/...` would require a separate web-server or WordPress rewrite layer and is outside the initial plan.

**Recommended default slug:** `cliniko-api`

The shorter default `api` is possible, but it is very generic. All WordPress plugins share the REST namespace registry, so another plugin or future site feature could also try to register `api/v1`. A plugin-specific default reduces that collision risk while still allowing an administrator to deliberately choose `api`.

The version suffix should not be an ordinary editable setting. This new API begins at `v1` because it is the first contract under its own namespace. The plugin's existing top-level `v1` and `v2` routes do not make this new contract `v3`; they are separate, poorly namespaced legacy contracts.

The dashboard field should:

- allow lowercase letters, numbers, and hyphens only;
- remove leading/trailing slashes;
- reject reserved WordPress names such as `wp`, `wp-json`, and `oembed`;
- check for an already registered `{slug}/v1` namespace collision;
- show a live preview of the complete base URL;
- explain that changing the slug changes every client URL;
- require an explicit confirmation before changing an enabled production namespace.

Changing the slug should not silently break installed apps. The module should support a controlled migration window in which the old `{slug}/v1` namespace is registered as a deprecated alias to the same handlers. The old alias should have an expiry date and visible warning. Mutation requests should be handled by the same controller rather than redirected with HTTP redirects.

### Contract version

WordPress officially recommends a unique `vendor/v1` namespace pattern. The configurable slug acts as the vendor/package portion, while the plugin-managed `v1` suffix identifies the API contract.

Recommended behavior:

- expose the current contract version through the status/capabilities response;
- include a response header such as `X-Cliniko-API-Version: 1.0`;
- make changes backward-compatible wherever possible;
- use documented deprecation and sunset dates before removing fields or operations;
- never let a plugin update silently introduce a breaking contract under the same namespace;
- if a future breaking contract is unavoidable, register `{api_slug}/v2` alongside `{api_slug}/v1` during migration.

Reasons for a configurable slug with a plugin-managed version:

- administrators can choose a site-appropriate public API name;
- client URLs remain predictable and follow WordPress conventions;
- the new contract remains separate from the plugin's existing literal `v1` and `v2` namespaces;
- Existing website consumers can be migrated deliberately.
- future breaking changes have a clear compatibility path.

For the route tables below, `{api_base}` means `/wp-json/{api_slug}/v1`.

## 4. Design principles

1. **Secure by default:** New route groups are disabled until required configuration is valid.
2. **Server-side authority:** Patient identity, ownership, appointment price, payment state, and final booking state are determined by the server.
3. **One business rule:** Website shortcodes and API routes share services rather than reimplementing rules.
4. **Least privilege:** Applications and users receive only the scopes necessary for their functions.
5. **No secret in a mobile app:** A native app is a public OAuth client and cannot safely hold a permanent client secret.
6. **Patient isolation:** Every patient resource lookup must prove that the resource belongs to the authenticated patient.
7. **Explicit public exposure:** A route is public only when its data and operation have been intentionally approved as public.
8. **Privacy by design:** Responses contain the minimum patient information required by the feature.
9. **Observable operations:** Security-relevant actions are logged without recording passwords, full bearer tokens, payment tokens, or unnecessary medical content.
10. **Safe migration:** Existing website flows remain functional while consumers are moved, except routes that must be blocked because of critical risk.

## 5. Work that must happen before API expansion

The critical issues documented in `docs/critical-api-security-review.md` must be treated as Phase 0 work:

1. Prevent the legacy patient-form route from updating a patient before authorization/ownership checks.
2. Remove or secure the legacy Tyro charge route so payment is verified server-side.
3. Redesign or disable the patient-history request-status token handoff.
4. Correct proxy-aware rate limiting and stop treating Origin/Referer as authentication.

New mobile routes must not be layered on top of these unsafe legacy flows.

## 6. Consumer types

The API should recognize that “third-party application” can mean different things.

| Consumer | Example | Can hold a secret? | Recommended authentication |
|---|---|---:|---|
| Native mobile app | iOS or Android patient app | No | OAuth 2.1 Authorization Code with PKCE |
| Browser single-page app | Patient portal hosted separately | No | OAuth 2.1 Authorization Code with PKCE plus strict redirect/CORS policy |
| Trusted backend integration | Partner server or internal service | Yes | OAuth client credentials or scoped API credentials |
| Public anonymous client | Public website appointment browser | No | No patient authentication; strict public contract, abuse controls, and server-created attempt tokens |
| Existing WordPress website UI | Current shortcodes/widgets | Uses WordPress session | Existing WordPress cookie/nonce authentication during migration |

One authentication mechanism should not be forced onto every consumer type.

## 7. Authentication strategy

### 7.1 Recommended mobile patient authentication

Use **OAuth 2.1 Authorization Code with PKCE**, backed by a trusted OAuth/OpenID Connect provider or a proven WordPress OAuth server component.

Expected flow:

```text
Mobile app
  -> opens system browser to the authorization endpoint
  -> patient signs in using the approved WordPress/social-login experience
  -> authorization server returns a one-time code to the registered app redirect URI
  -> mobile app exchanges the code using its PKCE verifier
  -> mobile app receives a short-lived access token
  -> API validates issuer, audience, signature, expiry, and scopes
  -> API maps the token subject to a WordPress user
  -> verified-patient policy resolves the linked Cliniko patient
```

Required security properties:

- system browser, not an embedded password form;
- exact allowlist of mobile redirect URIs;
- PKCE required for every native-app authorization;
- short-lived access tokens;
- rotated refresh tokens where supported;
- refresh token stored in iOS Keychain or Android Keystore-backed secure storage;
- token audience restricted to this API;
- immediate rejection of disabled, unverified, or unlinked patient accounts;
- server-side revocation or session-version support.

### 7.2 Approaches not recommended for the mobile patient app

- Sending WordPress username/password directly to a custom API route.
- Embedding a shared API secret in the mobile application.
- Using WordPress Application Passwords as the normal patient login experience.
- Accepting a long-lived token in a URL query string.
- Building a new OAuth server from scratch inside this plugin without a reviewed standards implementation.

WordPress Application Passwords may be acceptable for controlled administrative/server integrations, but they are long-lived credentials and are not a good consumer mobile-login design.

### 7.3 Trusted server integrations

Trusted backend applications may use client credentials or scoped API keys if OAuth client credentials are not available.

If API keys are supported:

- generate at least 32 random bytes;
- display the plaintext only once;
- store only a keyed hash;
- give each integration its own credential;
- attach explicit scopes;
- support expiry, rotation, and immediate revocation;
- record last-used time and a safe client fingerprint;
- never allow a server integration to impersonate an arbitrary patient unless an explicitly approved service flow requires it.

### Decision Gate A — Authentication provider

Before route implementation, choose one:

- [ ] **Recommended:** integrate a vetted OAuth/OIDC provider or WordPress OAuth server that supports Authorization Code + PKCE.
- [ ] Use an existing organizational identity provider and validate its JWTs through issuer/JWKS configuration.
- [ ] Build only server-to-server API access initially and postpone patient mobile login.
- [ ] Another approved approach, documented with a threat model.

Implementation decision: provide a standards-based introspection adapter with bearer authentication disabled until configured. Choosing and validating the actual provider is required before enabling patient mobile access.

## 8. Authorization and scopes

Authentication answers “who is calling.” Scopes and patient ownership answer “what may they do.”

### Proposed scopes

| Scope | Allows |
|---|---|
| `catalog:read` | Appointment types and other approved public booking metadata |
| `availability:read` | Practitioners, calendars, and available times |
| `booking:create` | Create and progress a booking attempt |
| `booking:read` | Read the caller's booking-attempt status |
| `patient:read` | Read the authenticated patient's profile |
| `patient:write` | Update approved fields on the authenticated patient's profile |
| `appointments:read` | Read the authenticated patient's appointments |
| `attachments:read` | List the authenticated patient's attachments |
| `attachments:write` | Upload, describe, or archive the authenticated patient's attachments |
| `communications:read` | Read the authenticated patient's communications |
| `communications:write` | Create messages and update the patient's read state |
| `account:manage` | Start approved email/account management actions |

### Authorization rules

1. Scopes never replace patient ownership checks.
2. A patient token may access only the Cliniko patient linked to its WordPress subject.
3. Route parameters such as `patient_id` must not be accepted as authority for patient routes.
4. Appointment, attachment, communication, and form records must be verified against the authenticated Cliniko patient after retrieval.
5. Server integrations should use separate integration scopes and should not automatically receive patient scopes.
6. Administrative capabilities remain separate from patient API scopes.

## 9. Proposed route groups

This is a planning contract, not the final OpenAPI specification.

### 9.1 System and discovery

| Method | Proposed route | Access | Purpose |
|---|---|---|---|
| GET | `{api_base}/status` | Public, minimal | Contract version and general availability; no credential/configuration details |
| GET | `{api_base}/capabilities` | Public or client-authenticated | Approved feature flags needed by clients |

Do not expose secret configuration, enabled payment credentials, internal hostnames, debug state, or raw WordPress/plugin versions unless specifically required.

### 9.2 Public booking discovery

| Method | Proposed route | Access | Existing logic to reuse |
|---|---|---|---|
| GET | `{api_base}/appointment-types` | Public or identified application | New approved list service may be needed |
| GET | `{api_base}/appointment-types/{id}` | Public or identified application | Appointment type model/controller logic |
| GET | `{api_base}/appointment-types/{id}/practitioners` | Public or identified application | Practitioner filtering logic |
| GET | `{api_base}/appointment-types/{id}/availability` | Public or identified application | Available-times and next-available logic |
| GET | `{api_base}/form-templates/{id}` | Public only when explicitly approved | Patient form template logic, with exposure policy |

Questions for approval:

- Should all configured appointment types be public, or only types explicitly marked “API visible”?
- Should form templates be public, or should clients receive a reduced schema containing only fields required for the selected booking flow?
- Should practitioner names be public for every appointment type?

### 9.3 Guest booking flow

| Method | Proposed route | Access | Purpose |
|---|---|---|---|
| POST | `{api_base}/booking-attempts` | Public with abuse controls | Validate input and create server-bound attempt |
| POST | `{api_base}/booking-attempts/{id}/stripe-payment` | Attempt token | Charge/confirm Stripe payment using the approved payment design |
| POST | `{api_base}/booking-attempts/{id}/tyro-payment` | Attempt token | Verify Tyro transaction server-side |
| POST | `{api_base}/booking-attempts/{id}/finalize` | Attempt token | Create appointment after verified/skipped payment |
| GET | `{api_base}/booking-attempts/{id}` | Attempt token | Read safe progress and result fields |

Rules:

- Attempt ID and secret are server-generated.
- Store only a hash of the attempt secret.
- The attempt carries the authoritative appointment type, price, currency, selected slot, patient mode, and expected payment reference.
- Finalization must be idempotent.
- Never accept “paid” as a client assertion.
- Do not return complete patient records through attempt status.

### 9.4 Authenticated patient account

| Method | Proposed route | Required scope | Purpose |
|---|---|---|---|
| GET | `{api_base}/me` | `patient:read` | Current patient profile |
| PATCH | `{api_base}/me` | `patient:write` | Update explicitly approved profile fields |
| POST | `{api_base}/me/email-change` | `account:manage` | Begin verified email-change process |
| GET | `{api_base}/me/appointments` | `appointments:read` | Paginated patient appointment list |
| GET | `{api_base}/me/appointments/{id}` | `appointments:read` | Patient-owned appointment detail |
| GET | `{api_base}/me/attachments` | `attachments:read` | List patient-owned attachments |
| POST | `{api_base}/me/attachments/uploads` | `attachments:write` | Begin controlled attachment upload |
| POST | `{api_base}/me/attachments` | `attachments:write` | Complete attachment record creation |
| PATCH | `{api_base}/me/attachments/{id}` | `attachments:write` | Update patient-owned attachment description |
| DELETE | `{api_base}/me/attachments/{id}` | `attachments:write` | Archive patient-owned attachment |
| GET | `{api_base}/me/communications` | `communications:read` | Paginated communications |
| POST | `{api_base}/me/communications` | `communications:write` | Send/create an approved patient message |
| POST | `{api_base}/me/communications/read-receipts` | `communications:write` | Update read state |

The first implementation should reuse and strengthen the existing authenticated controllers/services instead of calling shortcode code.

### 9.5 Authenticated patient booking

The patient booking endpoint should resolve identity from the access token and linked Cliniko patient. Submitted profile fields must not override the authenticated identity.

| Method | Proposed route | Required scope | Purpose |
|---|---|---|---|
| POST | `{api_base}/me/booking-attempts` | `booking:create` | Create an attempt using the authenticated linked patient |
| POST/GET | Same attempt progression routes as guest booking | `booking:create` / `booking:read` plus attempt token | Payment, finalization, and status |

Website-specific WordPress nonces should not be required from native apps. OAuth token validation, scopes, patient policy, and attempt tokens replace the shortcode nonce in the mobile flow.

### 9.6 Patient-history email access

Decision required: the mobile app may make this feature unnecessary for signed-in patients.

Recommended direction:

- authenticated patients use `/me` routes;
- guest history access, if retained, uses email code verification in the same client;
- do not transfer bearer tokens through a public status endpoint;
- do not allow caller-selected correlation IDs to become authentication secrets.

### Decision Gate B — Initial API product scope

Choose the first release:

- [ ] **Recommended MVP:** public discovery + guest booking + authenticated patient profile and appointments.
- [ ] Read-only patient app first: authentication + profile + appointments, with booking added later.
- [ ] Full patient app: profile, appointments, booking, attachments, and communications.
- [ ] Server integrations only; no patient mobile app in the first release.

Attachments and communications increase privacy, storage, malware-scanning, and support requirements, so they are reasonable second-phase features.

## 10. API administration module

The implemented API must be manageable under **Cliniko + Stripe → API Access** in WordPress administration. Administrators can configure the API slug, enable approved feature groups, publish specific appointment types/form templates, manage application scopes and revocation, configure authentication, set limits, and inspect audit events. Endpoint behavior is implemented in code; the dashboard manages that supported behavior.

### Proposed location

```text
src/Admin/Modules/ApiAccess/
```

This directory owns the WordPress administration experience only. It should not own route callbacks or core authorization logic.

### Proposed administration screens

#### A. Overview

- API enabled/disabled status.
- Current API namespace, complete WordPress REST base URL, and contract version.
- Authentication provider health.
- Cliniko/payment dependency readiness.
- Critical security warnings.
- Recent request counts and errors.
- Links to documentation and audit events.

#### B. Route groups

Manage coherent feature groups instead of exposing a dangerous list of independent low-level switches.

Suggested groups:

- Public booking discovery.
- Guest booking creation.
- Patient profile.
- Patient appointments.
- Patient attachments.
- Patient communications.
- Patient account management.
- Trusted server integrations.

Each group should show:

- enabled/disabled;
- required authentication type;
- scopes;
- dependent configuration;
- public data exposure summary;
- rate-limit profile;
- whether the feature is experimental, active, or deprecated.

Unsafe legacy routes should not appear as normal enable switches. They should appear in a separate migration/deprecation status section and remain blocked after retirement.

#### C. Applications/clients

For each registered application:

- display name and owner/contact;
- client/application ID;
- type: native, browser, confidential server;
- exact redirect URIs where applicable;
- approved scopes;
- enabled/disabled status;
- created, last-used, expiry, and last-rotated dates;
- environment: development, staging, production;
- credential rotation/revocation controls;
- audit history.

Native applications receive an identifier, not a permanent secret.

#### D. Authentication

- OAuth/OIDC issuer.
- Expected audience.
- Discovery/JWKS configuration.
- Redirect URI policy.
- Token clock-skew allowance.
- Session/revocation strategy.
- Connection test that does not expose tokens.

Secrets must use the plugin's encrypted secret-option handling and must never be echoed back after initial entry.

#### E. Rate limits and abuse controls

- named profiles rather than arbitrary values on every route;
- public read limits;
- public mutation/booking limits;
- email challenge limits;
- authenticated patient limits;
- server integration quotas;
- proxy/CDN trust configuration;
- optional bot-protection provider configuration.

#### F. Audit and usage

Show security-safe events such as:

- authentication successes/failures;
- client ID and scope denial;
- route group and response status;
- booking-attempt lifecycle;
- payment verification result without payment token details;
- patient-resource access category without full medical content;
- client creation, scope change, rotation, and revocation;
- configuration changes with WordPress administrator ID.

Audit access should require an administrative capability and have an explicit retention period.

#### G. Documentation

The dashboard must include a searchable endpoint catalog generated from the same definitions used to register routes. For each operation it shows active/disabled status and reason, method, complete URL, authorization/scopes, path/query/header/body fields, required/optional indicators, validation constraints, request example, success response schema/example, and possible error responses. It includes a download of the current OpenAPI JSON. Disabled operations remain visible and clearly labelled; the downloadable contract includes only currently enabled operations. Documentation uses synthetic values and does not read patient records or call external services.

- current API base URL;
- downloadable OpenAPI contract generated from the implemented API contract;
- authentication examples;
- error model;
- pagination rules;
- version/deprecation policy;
- examples that use placeholder credentials and synthetic patients only.

## 11. Proposed internal code boundaries

Exact names can change during implementation, but responsibilities should remain separated.

```text
src/Admin/Modules/ApiAccess/
    Administration UI, settings validation, client management views

src/Routes/V3/
    Route registration grouped by public, booking, patient, and integration APIs

src/Controller/Api/V3/
    HTTP input/output translation only

src/Authorization/Api/
    Token validation, scopes, verified-patient policy, client policy

src/Service/Api/
    Client registry, settings, audit log, rate limits, response/error helpers

src/Service/
    Existing/refactored patient, appointment, booking, attachment,
    communication, Cliniko, Stripe, and Tyro business services
```

### Controller rule

A controller should:

- read and validate the HTTP request shape;
- call authorization/application services;
- map the result into the standard API response;
- avoid containing Cliniko queries, payment rules, patient ownership logic, or HTML rendering.

### Shared-service rule

If both a shortcode and an API endpoint perform the same action, the action belongs in a shared service with two thin adapters:

```text
Shortcode adapter -> shared service <- API controller
```

## 12. API contract standards

### Response envelope

Choose one consistent response format. Proposed success:

```json
{
  "ok": true,
  "data": {},
  "meta": {
    "request_id": "server-generated-id"
  }
}
```

Proposed error:

```json
{
  "ok": false,
  "error": {
    "code": "appointment_not_found",
    "message": "The requested appointment could not be found.",
    "fields": []
  },
  "meta": {
    "request_id": "server-generated-id"
  }
}
```

Rules:

- stable machine-readable error codes;
- safe patient-facing messages;
- internal exception details only in protected logs;
- correct HTTP status codes;
- no inconsistent mix of `success`, `status`, `message`, and `ok` conventions in the new API.

### Pagination

Use consistent parameters and limits:

```text
page=1
per_page=20
order=asc|desc
```

Return pagination metadata without exposing upstream Cliniko URLs containing internal query details.

### Idempotency

Require an `Idempotency-Key` for high-impact mutation routes such as booking finalization and appropriate server-integration writes.

Store the key against:

- authenticated client;
- authenticated user where applicable;
- route/operation;
- normalized request hash;
- result and expiry.

A reused key with a different request body must be rejected.

### Contract compatibility and deprecation

- Track a semantic contract release version in addition to the major `v1` URL suffix.
- Additive, backward-compatible fields may be introduced under the current namespace.
- Clients must ignore unknown response fields.
- Deprecated routes return documented deprecation/sunset headers where practical.
- A removal date and migration path must be published before non-security removals.
- Critical routes may be disabled immediately with an incident/change record.
- A breaking change must use an explicit compatibility plan or a separately configured namespace; it must not silently replace the live contract.

## 13. Public exposure policy

Every route group must have a written data classification before it can be enabled.

| Classification | Examples | Default access |
|---|---|---|
| Public | Approved appointment labels and public availability | Anonymous, rate-limited |
| Patient confidential | Profile, appointments, form answers, communications | Authenticated patient with ownership check |
| Operational confidential | Audit events, client usage, configuration health | Authorized administrator |
| Secret | API keys, private keys, refresh tokens, payment tokens | Never returned through ordinary API/admin responses |

For each public field, document why it is needed. Do not expose the entire Cliniko upstream response simply because it is available.

## 14. CORS and origin policy

CORS controls which browser origins may read API responses. It is not authentication and does not protect against native or server clients.

Plan:

- public native-mobile API requests do not depend on `Origin`;
- browser clients use exact configured HTTPS origins;
- do not use `Access-Control-Allow-Origin: *` with credentials;
- allow only required methods and headers;
- keep WordPress cookie-authenticated routes same-origin unless explicitly designed otherwise;
- OAuth bearer routes authenticate using the token regardless of Origin, while browser CORS still controls browser access;
- preflight behavior is documented and tested.

## 15. Rate limiting and abuse prevention

The implementation should support limits by:

- route group;
- authenticated client;
- authenticated user;
- booking attempt;
- verified proxy-derived IP address;
- email-address hash for email challenge flows.

Requirements:

- atomic counters or a suitable shared store in multi-server deployments;
- `429 Too Many Requests` with a safe retry indication;
- no unbounded WordPress-option growth;
- trusted-proxy configuration before using forwarded IP headers;
- stricter controls for email sending, upload initialization, booking creation, and payment operations;
- administrative visibility into blocks without exposing patient data.

Rate limiting reduces abuse; it does not replace authentication or authorization.

## 16. Privacy, healthcare data, and logging

Before production release:

1. Identify which API responses contain personal or health information.
2. Confirm applicable Australian privacy, healthcare, contractual, and data-residency obligations with the responsible advisor.
3. Define log and audit retention periods.
4. Redact:
   - Authorization headers;
   - attempt and patient-access tokens;
   - OAuth codes and refresh tokens;
   - Stripe/Tyro payment tokens;
   - Medicare and health identifier numbers;
   - form answers and message bodies unless separately justified.
5. Record access categories and resource IDs only where necessary for audit.
6. Require TLS for every production API request.
7. Document deletion/account-closure behavior across WordPress, local audit data, and Cliniko.

## 17. File upload plan

Attachments should not be included in the first release unless the following are defined:

- accepted file types and content verification;
- maximum file size;
- filename normalization;
- malware scanning responsibility;
- presigned upload expiry and patient binding;
- prevention of arbitrary external `upload_url` submission;
- upload completion verification;
- storage/data residency;
- retention and deletion behavior;
- safe download behavior and content-disposition headers.

## 18. Testing strategy

### Unit tests

- scope and policy decisions;
- token claims and expiry validation;
- client credential hashing and rotation;
- patient ownership checks;
- response/error mapping;
- field allowlists;
- rate-limit identity derivation;
- idempotency behavior.

### WordPress REST integration tests

For every route, test an authorization matrix:

| Scenario | Expected result |
|---|---|
| No credential | Allowed only for intentionally public routes |
| Invalid/expired token | `401` |
| Valid token, missing scope | `403` |
| Valid scope, unverified patient | `403` |
| Valid patient accessing another patient's record | `404` or safe `403`, with no data leakage |
| Disabled client | `401` or `403` |
| Disabled route group | Controlled unavailable/not-found response |
| Replayed one-time code | Rejected |
| Reused payment transaction | Rejected |
| Repeated finalization | Same idempotent result; no duplicate appointment |

### External integration tests

- Cliniko staging/sandbox where available;
- Stripe test mode;
- Tyro staging;
- identity-provider staging tenant;
- iOS and Android redirect/deep-link behavior;
- reverse-proxy/CDN IP handling;
- upload lifecycle if attachments are included.

### Security tests

- cross-patient IDOR attempts;
- forged patient/resource IDs;
- scope escalation;
- token replay and substitution;
- OAuth redirect URI manipulation;
- PKCE downgrade/missing verifier;
- payment amount/reference mismatch;
- request-status/token handoff attacks;
- mass assignment and unknown fields;
- email/account enumeration;
- rate-limit bypass;
- log injection and secret leakage;
- oversized payload and file attacks;
- concurrent finalization/race conditions.

## 19. Observability and operational readiness

Define metrics before launch:

- request count and latency by route group;
- response status distribution;
- authentication and scope failures;
- rate-limit blocks;
- Cliniko dependency errors;
- booking attempt success/failure;
- payment verification failure and mismatch;
- duplicate/idempotent request count;
- email challenge volume;
- attachment upload failures where enabled.

Alerts should focus on meaningful conditions, such as:

- sudden patient-data access spikes;
- repeated cross-patient authorization failures;
- payment mismatches;
- high email challenge volume;
- large increases in booking creation without finalization;
- repeated token validation failures from one client;
- Cliniko or payment-provider outage.

## 20. Delivery phases

### Phase 0 — Containment and remediation

Deliverables:

- critical findings fixed or affected routes blocked;
- regression tests for those findings;
- production log/reconciliation review completed;
- agreed incident/compliance record.

Exit condition: new API development will not reuse a known unsafe flow.

### Phase 1 — Product and authentication decisions

Deliverables:

- approved initial mobile/API feature scope;
- selected OAuth/OIDC solution;
- approved default API slug and rules for customization;
- data classification and privacy review;
- identified application owners and environments;
- documented compatibility requirements for existing shortcodes/widgets.

Exit condition: namespace and release scope are established; OAuth bearer access stays unavailable until a trusted provider and subject mappings are configured.

### Phase 2 — Shared-service assessment and refactoring plan

Deliverables:

- map of each shortcode/API feature to its services/models;
- list of business logic that must be extracted from UI modules;
- patient field allowlist;
- resource ownership rules;
- standard API error and pagination design;
- no behavior-changing refactor without corresponding tests.

Exit condition: API controllers can be thin adapters over shared tested services.

### Phase 3 — API security foundation

Deliverables:

- configurable API bootstrap and route-group registry;
- OAuth/JWT verification integration;
- scope policy;
- client registry for applicable client types;
- server-generated request IDs;
- trusted-proxy-aware rate limiter;
- audit logger with redaction;
- idempotency service;
- global API enable/disable control.

Exit condition: security foundation passes unit and integration tests before patient routes are added.

### Phase 4 — API administration module

Deliverables:

- overview/health screen;
- route-group controls;
- client/application management;
- authentication configuration and connection test;
- rate-limit profiles;
- audit/usage views;
- capability checks and nonces for every admin action.

Exit condition: only authorized administrators can change API exposure, clients, scopes, or secrets.

### Phase 5 — Read-only API contract

Recommended initial routes:

- API status/capabilities;
- approved appointment types;
- practitioners and availability;
- authenticated patient profile read;
- authenticated patient appointment list/detail.

Exit condition: contract, ownership, privacy, load, and mobile integration tests pass.

### Phase 6 — Booking and payment API

Deliverables:

- guest and authenticated booking attempts;
- Stripe flow;
- server-verified Tyro flow;
- idempotent finalization;
- safe status polling;
- reconciliation and failure/refund behavior.

Exit condition: no appointment can be finalized without verified/skipped server-authoritative payment state.

### Phase 7 — Patient write features

Possible deliverables:

- allowlisted profile updates;
- verified email change;
- attachments after upload-security approval;
- communications after privacy/product approval.

Exit condition: each feature passes patient-isolation and data-minimization review.

### Phase 8 — Documentation, pilot, and migration

Deliverables:

- new OpenAPI contract generated from implemented behavior;
- integration guide and synthetic examples;
- mobile deep-link/authentication guide;
- pilot client in a non-production environment;
- staged rollout and monitoring;
- v1/v2 consumer inventory;
- migration and deprecation schedule;
- post-launch security review.

## 21. Migration strategy

1. Inventory every current consumer of `/wp-json/v1` and `/wp-json/v2` using source search, browser code, access logs, and partner confirmation.
2. Immediately block routes with critical exposure where the risk outweighs compatibility.
3. Keep safe existing website routes during the transition.
4. Build the new configurable-namespace API without silently changing v1/v2 response formats.
5. Migrate first-party website code first to prove the new API.
6. Pilot one mobile or partner client in staging.
7. Roll out production clients individually with monitoring.
8. Publish deprecation dates for remaining legacy routes.
9. Remove unused v1/v2 routes after measured traffic reaches zero and owners sign off.

## 22. Proposed definition of done

The third-party API initiative is complete when:

- the approved route groups are implemented under the configured namespace;
- mobile patients authenticate using the approved PKCE flow;
- server clients have isolated, scoped, revocable credentials;
- every patient resource has tested ownership enforcement;
- payments are verified server-side and cannot be replayed;
- route exposure is manageable through the API Access administration module;
- logs are useful for audit without exposing secrets or medical content;
- rate limits work behind the production proxy/CDN configuration;
- an accurate OpenAPI contract and integration guide are published;
- automated authorization/security tests run in CI;
- a staging pilot and production security review have passed;
- unsafe legacy routes are removed or permanently disabled.

## 23. Decisions to make together

### Decision Gate C — Namespace and compatibility

- [ ] **Recommended default slug:** `cliniko-api`, producing `/wp-json/cliniko-api/v1/...`.
- [ ] Short generic default slug: `api`, producing `/wp-json/api/v1/...`, accepting the higher collision risk.
- [ ] Another default slug: ____________________
- [ ] Allow administrators to customize the slug using the validation and migration safeguards in this plan.
- [ ] Keep `v1` plugin-managed rather than making the contract version an editable setting.
- [ ] Existing v1/v2 routes remain only for first-party website migration.
- [ ] Approved external consumers must move to the new API contract.

### Decision Gate D — Administration model

- [ ] **Recommended:** manage route groups, not arbitrary individual endpoints.
- [ ] Allow selected endpoint-level overrides with dependency validation.
- [ ] Only network administrators may manage API clients/settings on multisite.
- [ ] Site administrators may manage their own site's API clients/settings.

### Product questions

1. Is the first mobile app only for patients, or will practitioners/staff use it too?
2. Must users register/login in the app, or is guest booking the first objective?
3. Which features are required for the first release: discovery, booking, profile, appointments, attachments, communications?
4. Should guest users be able to retrieve old form data, or should that be available only after full account login?
5. Which current v1/v2 routes are used by production pages today?
6. Are external partner servers expected, or only first-party mobile applications?
7. Is there an existing organizational identity provider or preferred OAuth/OIDC WordPress solution?
8. What audit-log retention period is required?
9. Who approves public exposure of appointment types, practitioners, and form templates?
10. Who owns incident response and payment reconciliation?

## 24. Recommended first agreement

For the first planning approval, adopt these defaults:

1. Use a configurable API slug with a plugin-managed `v1` contract suffix; recommend `cliniko-api` while allowing `api` as an administrator choice.
2. Use the native WordPress REST URL; no façade, gateway alias, or clean-path rewrite is part of this feature.
3. Build an `ApiAccess` administration module for route groups, clients, authentication, limits, endpoint documentation, and audit visibility.
4. Keep route/controller/authorization code outside `src/Admin/Modules`.
5. Use shared services for both shortcodes and API routes.
6. Use OAuth 2.1 Authorization Code + PKCE for patient mobile login.
7. Start with public discovery, secure booking attempts, patient profile read, and appointment read.
8. Defer attachments and communications until the core API and privacy controls are proven.
9. Complete Phase 0 critical remediation before exposing the new API.

Once these directives are accepted or amended, the next planning artifact should be a route-by-route contract containing request fields, response fields, scopes, ownership rules, error codes, and migration mapping from existing v1/v2 behavior.
