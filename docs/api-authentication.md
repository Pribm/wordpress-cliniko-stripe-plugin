# API dashboard and patient app authentication

Updated 8 October 2026. This describes the implemented managed API, not the older website `/v1` and `/v2` contracts. Do not use the outdated `docs/openapi.yaml`.

## The dashboard

Open **Cliniko + Stripe → API Access**. The CMS-style sidebar is inspired by Strapi's separation of application settings, authentication and permissions; this is still a WordPress module, not a Strapi integration.

| Area | What your team manages |
| --- | --- |
| Overview | Saved configuration, API address and four getting-started decisions. “Configured” is not certification of a working patient login. |
| Publish API | API switch/address, route groups, published services and blank forms, limits and browser origins. |
| Booking flows | Explicit live links to existing patient booking setups; structure-only JSON and protected configured submission. |
| Patient login → WordPress login · JWT | Enable an approved app's own username/email/password login screen using existing patient accounts. |
| Patient login → External OAuth 2.0 | Configure an external token-checking service using the existing guided setup. |
| Patient login → Social sign-in | Enable Google/Apple identity-token exchanges for patient JWT sessions. |
| Patient login → Registration | Enable WordPress, Google/Apple and external OAuth signup; select a safe patient role and reuse the existing clinic verification email. |
| Patient login → Sessions & expiry | Access-token/session lifetimes and revocation of the latest 100 website-issued sessions. |
| Applications | App types, permissions, approval expiry and public social application IDs. |
| Patient links | Explicit external subject → existing WordPress patient links; not email matching. |
| Requests & responses | Seven interactive, synthetic walkthroughs: login, social, OAuth, registration, patient data, booking and renewal/logout. No requests are actually sent. |
| Endpoint documentation | Live active/disabled routes, schemas, synthetic examples and active-only OpenAPI JSON download. |
| Activity, Tutorials, Dashboard guide | Minimal audit metadata, staff instructions and optional developer references. |

Save actions are nonce-protected and require `manage_options`. Publishing, external OAuth, native password login, social login and session lifetime sections preserve each other's saved values. No login method or route group is enabled automatically. Help opens in keyboard-accessible modals, with technical details separated from everyday explanations. Narrow screens use scrollable navigation and contained tables.

## Choose a login method

All managed API booking creation, payments, SDK-token requests and booking progress now require an active verified patient access token. Create at `POST /me/booking-attempts`; guest creation at `POST /booking-attempts` is no longer exposed. Attempt-specific routes also require `X-ES-Attempt-Token` and the original app/patient ownership. This does not change website guest routes or pending-account registration. See [patient booking and migration](api-patient-booking.md).

JWT is a token format, not an alternative version of OAuth. The website issues signed JWT access tokens for WordPress/password and direct Google/Apple sessions. The external OAuth path accepts the external service's access tokens through introspection; it does not issue them.

| Method | What the patient sees | What reaches the website | What authorizes `/me` |
| --- | --- | --- | --- |
| WordPress login | Your app's own username/email/password screen | Credentials over HTTPS and an approved Patient app ID | Website-issued patient JWT |
| Direct Google/Apple | App's social button opening the provider's SDK/flow | Nonce-bound, provider-signed ID token and single-use challenge | Website-issued patient JWT after exchange |
| External OAuth 2.0 | External provider login/social screens, invoked by the app | Provider-issued access token on each API request | That provider access token after introspection and local permission checks |

For independently operated third-party apps, prefer external OAuth: collecting a website password lets the app operator receive that password. A custom password login route is **not** an OAuth Resource Owner Password Credentials grant. The plugin is **not** an OAuth authorization server.

## A. WordPress-account login screen

For new accounts rather than existing-patient login, see [App registration and existing Cliniko verification](api-registration.md). The new `/auth/register`, `/auth/register/social` and `/auth/register/oauth` operations create pending accounts only. The new progress/resend routes use a limited onboarding receipt, never a patient access token.

1. Register **Patient app · WordPress / social JWT** under Applications. Approve only the required permissions, set an expiry and copy its public App ID.
2. Have your developer review all login requirements, including two-factor, CAPTCHA and membership rules. `wp_authenticate` hooks run, but an API call does not execute interactive login screens. Known integrations are conservatively blocked; unknown integrations require explicit review. Do not bypass mandatory MFA. Custom integrations can force blocking through `wp_cliniko_api_app_login_requires_browser`.
3. Enable WordPress login after confirming the compatibility review. Saving initializes the encrypted signing key and verifies session storage; failure leaves the method unchanged.
4. Separately publish the required patient route groups and enable API Access.
5. Implement this exchange, using the base address from Overview:

```http
POST <API-BASE>/auth/login
Content-Type: application/json

{
  "client_id": "<24-character-public-Patient-App-ID>",
  "identifier": "alex@example.test",
  "password": "<patient-website-password>"
}
```

The website authenticates credentials and checks the active account, approved patient role, email verification and Cliniko link. Administrator accounts cannot obtain these website-issued patient sessions. There is no WordPress browser cookie or administrator API access granted by this login.

Successful authentication returns:

```json
{
  "ok": true,
  "data": {
    "access_token": "<short-lived-patient-JWT>",
    "token_type": "Bearer",
    "expires_in": 900,
    "refresh_token": "<private-rotating-refresh-token>",
    "refresh_expires_in": 604800,
    "scope": "patient:read appointments:read"
  },
  "meta": { "request_id": "<support-reference>" }
}
```

Expiry values depend on the saved settings and application approval. The app must not store the patient password. Store tokens in the platform's secure credential storage. Browser applications need an appropriate secure session architecture; do not use browser localStorage for these secrets.

### Forgotten-password flow

The API also supports a patient who no longer knows their password. It deliberately never confirms whether an email belongs to an account.

1. Send the approved app ID and the email entered on the app's **Forgot password** screen:

```http
POST <API-BASE>/auth/password/forgot
Content-Type: application/json

{
  "client_id": "<24-character-public-Patient-App-ID>",
  "email": "alex@example.test"
}
```

Always show the same message in the app: “If an account exists, check your email.” The route returns `202` whether the email is unknown, inactive, unverified or eligible. For an eligible patient, WordPress sends its normal password-reset email using the site's existing email settings and hooks.

2. The patient opens that email. The reset link contains WordPress's `login` and one-time `key` values. A mobile app may open the link in a browser/webview, or use those two values in its own reset screen. Never log or store the key longer than necessary.

3. To finish inside the app, send:

```http
POST <API-BASE>/auth/password/reset
Content-Type: application/json

{
  "client_id": "<24-character-public-Patient-App-ID>",
  "login": "<login-from-email-link>",
  "key": "<one-time-key-from-email-link>",
  "new_password": "<new-12-to-128-character-password>"
}
```

The key is checked by WordPress and can be used only once under WordPress's expiry rules. Only active, verified patient accounts can reset through this API. A successful reset revokes existing website and API sessions, so the app must call `/auth/login` again. Invalid or expired keys are rejected without disclosing account details. Rate limits apply to both the request and completion steps.

## B. Google and Apple buttons

1. Register the actual platform at Google/Apple. Configure required consent, origins, return addresses and native identifiers using their official guides.
2. Register a **Patient app** here. Configure its exact Google ID-token audience or Apple bundle/Services ID. These are public IDs, not secrets. Google hybrid native flows may additionally require the exact **Google native app ID** (`azp` authorized presenter); when configured it is required to match. Use separate app approvals for distinct platform registrations when necessary.
3. Enable the intended social method in Patient login → Social sign-in and publish patient routes separately.
4. Request `POST /auth/challenge` with `{ "client_id": "<App-ID>", "provider": "google" }` or `"apple"`.
5. Pass the returned `data.nonce` into the provider's authorization request. It is valid for five minutes. **Apple's nonce is already SHA-256 transformed—do not hash it again.** SDK flows without nonce support are not compatible.
6. The developer completes the provider SDK/authorization flow, including its own state, redirects and PKCE where applicable. Send `client_id`, `provider`, `challenge_id` and the returned `id_token` to `POST /auth/social`.
7. The website verifies RS256 signature against fixed Google/Apple public-key endpoints, issuer, exact per-app audience, presenter where applicable, expiry and nonce. It consumes the challenge once.
8. An explicit canonical issuer + verified subject mapping must already point to the correct existing approved patient. No automatic patient creation, email matching or authorization based on a decoded-but-unverified ID token occurs.
9. The response is the same website-issued JWT session envelope as above. Use that JWT—not the Google/Apple ID token—on patient-data requests.

Google issuer variants normalize to `https://accounts.google.com` for patient links; Apple uses `https://appleid.apple.com`. Obtain the stable subject through a verified provider flow and confirm ownership before an administrator links it. Removing the link blocks its existing local sessions too.

Direct social sessions enforce local revocation. They do not continuously poll Google/Apple account revocation or provide provider-wide logout; choose session lifetimes and operational procedures accordingly. Other social services may be brokered through the configured external OAuth service. Firebase, Cognito, Auth0 and Entra do not gain new direct-verification implementations from this redesign.

Official references: [Google ID-token validation](https://developers.google.com/identity/gsi/web/guides/verify-google-id-token), [Google hybrid authorized presenter](https://developers.google.com/identity/openid-connect/openid-connect), [Sign in with Apple](https://developer.apple.com/documentation/signinwithapple).

## C. External OAuth 2.0

The existing service guides and RFC 7662 introspection remain under Patient login → External OAuth 2.0. The provider implements app login, social providers, Authorization Code + PKCE and external token renewal. Register an **OAuth mobile/browser application** here using the provider's public client ID and approved permissions. Link its exact issuer + subject to the existing patient account.

Google's direct profile in this external-service selector points to the separate Social sign-in section. It cannot be enabled as an introspection provider. Google-through-Keycloak remains a different flow with Keycloak-issued tokens and Keycloak patient subjects.

The connectivity check uses an invalid synthetic token; passing it does not prove real patient login or scope/mapping compatibility.

## Protected requests and withdrawal

```http
GET <API-BASE>/me/appointments
Authorization: Bearer <patient-JWT-or-external-provider-access-token>
Accept: application/json
```

Every protected request verifies the token, active app approval, allowed permissions and active verified patient account. The website chooses the Cliniko patient from that trusted account, never from a patient ID posted by the app. JWT claims contain identifiers and permissions, not medical records or passwords.

For website-issued JWTs, every request also checks its database session. A valid signature alone is insufficient. Password changes, account suspension, app revocation, login-method disabling and session revocation block access. Local JWTs are not sent to external introspection. They do not authenticate legacy `/v2` or WordPress core routes.

- `POST /auth/refresh`: send the Patient app ID and **current** refresh token. Receive a replacement pair; store both together. Serialize refreshes. Any replay of a recorded spent token revokes the entire session.
- `POST /auth/logout`: send the same app ID and latest refresh token. Revoke the session, including outstanding JWT access tokens.
- Dashboard session revocation also stops outstanding access tokens immediately.
- Access lifetime: 5–30 minutes, default 15. Absolute session lifetime: 1–30 days, default seven. It cannot outlive app approval. Refresh never extends the original session deadline.
- External OAuth tokens use provider renewal/revocation instead of these local routes.

Login endpoints use JSON proofs, not an Authorization header. Authentication IP budget: 20 requests/10 minutes; per-app budget: 60; password-identifier budget: five. These supplement normal API limits. An App ID is public and does not attest an app binary or replace patient authentication.

## Storage and deployment checklist

New tables: `cliniko_api_sessions` and `cliniko_api_spent_refresh`, with the site's WordPress prefix. Session schema is tracked separately from the existing API audit/rate/idempotency schema. Sessions persist only keyed refresh hashes, password fingerprints, identity references and timestamps—not raw passwords or tokens. Daily cleanup removes expired session/history rows. Signing material uses the existing encrypted secret-option support. JWT verification uses `firebase/php-jwt` with fixed algorithms; social key requests have bounded responses, no redirects, cache and globally limited refreshes.

Before production:

- Run `composer install` so the new JWT dependency is packaged.
- Confirm HTTPS, encryption, database table permissions and daily WordPress cron.
- Review actual MFA/membership/security-plugin behavior; never enable a shortcut around required authentication.
- Test real staging WordPress, Google and Apple patients, including native audience/presenter/nonce details.
- Verify wrong-password, unlinked-user, wrong-audience, replay, expired-token, revoked-session and suspended-account denial.
- Test app renewal as a serialized operation; retrying a spent refresh token intentionally forces sign-in again.
- Re-download active OpenAPI JSON and give the developer Requests & responses plus Endpoint documentation.
- Review existing dependency audit findings separately before rollout. The existing Guzzle/Guzzle PSR-7 versions have advisories; this change does not silently upgrade those unrelated dependencies.

Automated checks use synthetic patient/provider fixtures and real JWT signatures. They do not certify external-provider configuration or production readiness. Live WordPress/database/provider testing remains required.
