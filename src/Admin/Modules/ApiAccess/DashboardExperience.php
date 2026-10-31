<?php

namespace App\Admin\Modules\ApiAccess;

use App\Service\Api\ApiClients;
use App\Service\Api\ApiSettings;
use App\Service\Api\EndpointCatalog;

if (!defined('ABSPATH')) exit;

/** Staff-facing orientation and synthetic, read-only request walkthroughs. */
final class DashboardExperience
{
    private static function url(string $tab, string $section = ''): string
    {
        return add_query_arg(['page' => ApiAccess::PAGE, 'tab' => $tab] + ($section !== '' ? ['section' => $section] : []), admin_url('admin.php'));
    }

    public static function journey(array $steps): void
    {
        echo '<ol class="cliniko-api-journey">';
        foreach ($steps as $index => $step) echo '<li><span>' . (int) ($index + 1) . '</span><p>' . esc_html($step) . '</p></li>';
        echo '</ol>';
    }

    public static function overview(): void
    {
        $s = ApiSettings::get();
        $active = count(array_filter(EndpointCatalog::definitions(), static fn($item): bool => EndpointCatalog::state($item) === 'Active'));
        $clients = count(array_filter(ApiClients::all(), [ApiClients::class, 'active']));
        $methods = array_filter(['WordPress / JWT' => $s['password_login_enabled'], 'Google' => $s['google_login_enabled'], 'Apple' => $s['apple_login_enabled'], 'External OAuth 2.0' => $s['oauth_enabled']]);
        echo '<p class="cliniko-api-lead">Connect a mobile app without losing control of patient access. Start with four decisions: what to publish, which app to approve, how patients sign in, and what the app sends.</p><section class="cliniko-api-metrics" aria-label="Saved API configuration">';
        foreach ([[$active, 'Active endpoints'], [$clients, 'Approved applications'], [count($methods), 'Enabled login methods']] as [$value, $label]) echo '<div><strong>' . (int) $value . '</strong><span>' . esc_html($label) . '</span></div>';
        echo '</section><div class="cliniko-api-address"><div><span>API base address</span><code>' . esc_html(rest_url(ApiSettings::namespace())) . '</code></div><button type="button" class="button" data-copy-value="' . esc_attr(rest_url(ApiSettings::namespace())) . '">Copy address</button></div><section class="cliniko-api-card"><p class="cliniko-api-eyebrow">Getting started</p><h3>Build your first connection</h3><p>Nothing below switches on automatically. Saving one section does not change the others.</p><div class="cliniko-api-onboarding">';
        $cards = [
            ['01', 'Publish only what you need', 'Choose route groups and select approved appointment types and blank form templates. Private patient routes still require login.', 'settings', 'Choose API data', $s['enabled'] && $s['groups'] !== []],
            ['02', 'Register your app', 'Give the app a name, an approval expiry and specific permissions. Mobile apps receive a public App ID—not a permanent server password.', 'clients', 'Register application', $clients > 0],
            ['03', 'Choose patient login', 'Use existing WordPress credentials, Google/Apple social sign-in, or an external OAuth 2.0 service. External identities need patient links.', 'patient-login', 'Choose authentication', $methods !== []],
            ['04', 'Follow the requests', 'See synthetic login, token, patient-data and booking examples. Give the live endpoint documentation to your app developer.', 'flows', 'Explore request flows', false],
        ];
        foreach ($cards as [$number, $title, $text, $tab, $action, $ready]) {
            echo '<article><div class="cliniko-api-step-top"><span>' . esc_html($number) . '</span><span class="cliniko-api-badge ' . ($ready ? 'is-active' : '') . '">' . ($ready ? 'Configured' : 'Next step') . '</span></div><h4>' . esc_html($title) . '</h4><p>' . esc_html($text) . '</p><a class="button" href="' . esc_url(self::url($tab)) . '">' . esc_html($action) . ' &rarr;</a></article>';
        }
        echo '</div><p class="description">Configured badges reflect saved choices, not a successful real patient login. Always test with an approved test patient before rollout.</p></section><section class="cliniko-api-card"><h3>What happens when an app requests patient data?</h3>';
        self::journey(['Patient signs in', 'App sends a Bearer access token', 'Website checks token + app + patient permissions', 'Only that patient’s allowed data is returned']);
        echo '<p>Public discovery is different: published appointment types and availability do not require patient login. A trusted server key cannot open patient-account routes. Cliniko and payment administrator credentials stay on your website.</p></section><div class="cliniko-api-callout"><h3>JWT and OAuth are different parts of the picture</h3><p><strong>JWT</strong> is the signed token used by this website’s patient sessions. <strong>OAuth 2.0</strong> is the authorization flow used by an external sign-in service. Both reach the same protected API through <code>Authorization: Bearer</code>; neither bypasses patient permission checks.</p></div>';
    }

    public static function flows(): void
    {
        $base = rest_url(ApiSettings::namespace());
        echo '<p class="cliniko-api-lead">See what leaves the app, what the website verifies, and what comes back. These are synthetic examples—this page does not send requests, authenticate anyone or fetch patient records.</p><div class="cliniko-api-flow-picker" role="group" aria-label="Choose a request walkthrough">';
        $names = ['wordpress' => 'WordPress login', 'social' => 'Google / Apple', 'oauth' => 'External OAuth 2.0', 'registration' => 'New patient signup', 'patient' => 'Protected patient data', 'booking' => 'Patient booking', 'refresh' => 'Renewal & logout'];
        foreach ($names as $key => $name) echo '<button type="button" data-flow-button="' . esc_attr($key) . '" aria-pressed="' . ($key === 'wordpress' ? 'true' : 'false') . '">' . esc_html($name) . '</button>';
        echo '</div>';
        $tokens = ['access_token' => '<short-lived-patient-JWT>', 'token_type' => 'Bearer', 'expires_in' => 900, 'refresh_token' => '<private-rotating-refresh-token>', 'refresh_expires_in' => 604800, 'scope' => 'patient:read appointments:read'];
        $flows = [
            'registration' => ['Sign up, verify email, then sign in', ['App sends signup + explicit privacy consent', 'WordPress creates only a pending account', 'Patient opens existing clinic verification email', 'After binding/approval, app signs in separately'], 'Signup returns HTTP 202 with a private onboarding receipt—not a JWT. The website reuses its existing verification email, Cliniko matching/creation and approval rules. The app can check progress or request another email with its receipt. Existing WordPress accounts are never overwritten or joined by matching email. For social signup, request a purpose=register challenge, then send the provider ID token, first/last names and consent to /auth/register/social. For external OAuth, send the approved internal App ID, provider access token, names and consent to /auth/register/oauth; introspection must include account:register, email and email_verified.', [
                ['POST', '/auth/register', [], ['client_id' => str_repeat('a', 24), 'email' => 'new@example.test', 'password' => '<new-patient-password>', 'first_name' => 'New', 'last_name' => 'Patient', 'accept_privacy_policy' => true], ['status' => 'verification_pending', 'message' => 'If registration can proceed, check your email. Existing users should sign in or contact the clinic.', 'registration_token' => 'reg_' . str_repeat('b', 48) . '.' . str_repeat('c', 64), 'expires_in' => 86400]],
                ['POST', '/auth/registration/status', [], ['client_id' => str_repeat('a', 24), 'registration_token' => '<private-onboarding-receipt>'], ['status' => 'ready_to_sign_in', 'message' => 'Verification is complete. Sign in through your selected login method.']],
            ], [['names + email', 'App → pending WordPress account', 'The server assigns a safe patient role. Social/OAuth email must come from verified service claims.'], ['privacy acceptance', 'App → registration consent metadata', 'Explicit true is required; the app must display the clinic policy.'], ['verification link', 'Website → patient email → existing website verifier', 'Not returned to the app. Existing verifier completes clinic binding or creates a new Cliniko patient after email proof.'], ['registration_token', 'Website → app → status/resend only', 'Private 24-hour receipt, bound to the original approved app. It cannot open /me.']]],
            'wordpress' => ['Your app’s own login screen', ['App collects username/email + password', 'POST /auth/login over HTTPS', 'WordPress credentials + patient checks', 'JWT session returned to app'], 'The website password is used only for sign-in. It is not a client secret, and it must not be stored in the app or logs. The app stores the returned tokens in platform secure storage. Known interactive MFA requirements block direct password login.', [
                ['POST', '/auth/login', [], ['client_id' => str_repeat('a', 24), 'identifier' => 'alex@example.test', 'password' => '<patient-website-password>'], $tokens],
            ], [['client_id', 'App → website', 'Public App ID from Applications. Identifies the approved app; it is not proof of patient identity.'], ['identifier + password', 'App → WordPress authentication', 'The patient’s website credentials. The plugin never logs them.'], ['access_token', 'Website → app → protected routes', 'Short-lived signed proof of this patient session.'], ['refresh_token', 'Website → app → renewal/logout only', 'Private renewal credential; replace it after every refresh.']]],
            'social' => ['Social buttons in your app', ['App asks website for a challenge', 'Google / Apple signs in with that nonce', 'App sends signed ID token + challenge', 'Website checks identity + existing patient link'], 'Use the provider’s official SDK or authorization flow. Pass the exact nonce from the challenge into the provider request; Apple’s returned challenge nonce is already hashed. The SDK must support nonce. Email, name and a client-provided patient ID are never enough to link an account.', [
                ['POST', '/auth/challenge', [], ['client_id' => str_repeat('a', 24), 'provider' => 'google'], ['challenge_id' => str_repeat('b', 48), 'nonce' => '<one-use-nonce>', 'expires_in' => 300, 'provider' => 'google', 'audience' => 'your-client.apps.googleusercontent.com']],
                ['POST', '/auth/social', [], ['client_id' => str_repeat('a', 24), 'provider' => 'google', 'challenge_id' => str_repeat('b', 48), 'id_token' => '<Google-signed-ID-token-containing-nonce>'], $tokens],
            ], [['nonce', 'Website → app → social service → signed ID token', 'Binds the identity proof to this login attempt. Expires in five minutes and is single-use.'], ['id_token', 'Social service → app → website exchange', 'Identity proof, not the token used to read /me. Signature and this app’s audience are checked.'], ['provider subject ID', 'Verified token → explicit patient link', 'Stable external identity; linked by the clinic to an existing patient.'], ['patient JWT', 'Website → app', 'Use this new token on patient API requests. Never send the Google/Apple ID token directly to /me.']]],
            'oauth' => ['Use your external sign-in service', ['App opens provider login / social buttons', 'Provider completes Authorization Code + PKCE', 'App receives provider access token', 'Website validates it + permissions + patient link'], 'Your developer implements the provider’s login SDK/flow and token renewal. This plugin does not issue OAuth authorization codes or external provider tokens. It checks access tokens using the configured HTTPS introspection endpoint; provider compatibility must be verified.', [
                ['GET', '/me', ['Authorization' => 'Bearer <provider-access-token>'], null, ['id' => '100', 'first_name' => 'Alex', 'last_name' => 'Example']],
            ], [['provider access token', 'Provider → app → website', 'Presented on every protected request.'], ['website connection secret', 'Website → provider token check only', 'Stored encrypted on WordPress. Never send it to mobile/browser apps.'], ['issuer + subject', 'Verified provider response → patient link', 'Identifies the approved WordPress patient account.'], ['permissions / scopes', 'Provider token ∩ dashboard approval', 'Both must allow the requested patient-data operation.']]],
            'patient' => ['Read or update the signed-in patient’s data', ['App sends access token', 'Website verifies token and approved app', 'Active verified patient + ownership checked', 'Filtered profile returned or allowed fields updated'], 'A missing/invalid token is rejected. A valid token without the required permission is rejected. The website chooses the patient from the validated identity—not from a patient ID supplied by the app. App revocation or patient suspension stops access.', [
                ['GET', '/me/appointments?page=1&per_page=20', ['Authorization' => 'Bearer <patient-JWT-or-provider-access-token>', 'Accept' => 'application/json'], null, ['items' => [['id' => '100', 'starts_at' => '2026-11-01T10:00:00+11:00', 'service' => 'Consultation']], 'page' => 1, 'per_page' => 20, 'has_more' => false]],
            ], [['Authorization header', 'App → website', 'Proof of patient access; never put it in the URL.'], ['page / per_page', 'App → website', 'Pagination controls only—not identity selectors.'], ['data', 'Cliniko → website → app', 'Only allowed fields belonging to the authenticated patient.'], ['meta.request_id', 'Website → app / support team', 'Safe reference for troubleshooting; do not include tokens in support tickets.']]],
            'booking' => ['Verified patient booking and payment', ['App retrieves published services / slots', 'Website checks patient identity and booking draft', 'App receives private attempt token', 'Website verifies payment, then finalizes'], 'Sign in as an active verified patient before creating a booking at /me/booking-attempts. Every payment, finalization and progress request requires the original patient access token and the private attempt secret from the same approved app. Guest booking creation is not exposed. Payment details are tokenized with the payment provider; card numbers must never be sent to this website.', [
                ['GET', '/appointment-types', [], null, ['items' => [['appointment_type_id' => '123', 'name' => 'Consultation']]]],
                ['POST', '/me/booking-attempts', ['Authorization' => 'Bearer <patient-access-token>', 'Content-Type' => 'application/json'], ['moduleId' => '123', 'patient_form_template_id' => '456', 'gateway' => 'stripe', 'patient' => ['practitioner_id' => '789', 'appointment_start' => '2026-11-01T10:00:00+11:00'], 'content' => ['sections' => []]], ['attempt' => ['id' => str_repeat('b', 32), 'status' => 'preflighted', 'token' => '<private-attempt-token>', 'expires_in' => 86400], 'payment' => ['required' => true, 'gateway' => 'stripe', 'amount' => 10000, 'currency' => 'aud']]],
                ['GET', '/booking-attempts/' . str_repeat('b', 32), ['Authorization' => 'Bearer <patient-access-token>', 'X-ES-Attempt-Token' => '<private-attempt-token>'], null, ['attempt' => ['id' => str_repeat('b', 32), 'status' => 'preflighted']]],
            ], [['appointment type / form template', 'App → website allowlists', 'Only the administrator’s published choices may be booked.'], ['attempt token', 'Website → app → attempt routes', 'Private secret for this booking only, maximum 24 hours. Must be combined with the original verified patient access token.'], ['payment token / reference', 'Payment SDK → app → website', 'Website verifies gateway, amount and payment; app assertions are not trusted.'], ['Idempotency-Key', 'App → finalize request', 'Reuse for the same finalize operation so retries do not duplicate it.']]],
            'refresh' => ['Keep the app signed in safely', ['Access token expires', 'App submits current refresh token once', 'Website checks live account and session', 'App replaces both returned tokens'], 'Serialize refresh calls. Reusing a previously spent refresh token revokes the session, so automatic parallel retries are unsafe. Logout revokes this app session, not the WordPress browser login or Google/Apple account. External OAuth renewal happens at the provider instead.', [
                ['POST', '/auth/refresh', [], ['client_id' => str_repeat('a', 24), 'refresh_token' => '<current-refresh-token>'], $tokens],
                ['POST', '/auth/logout', [], ['client_id' => str_repeat('a', 24), 'refresh_token' => '<latest-refresh-token>'], ['logged_out' => true]],
            ], [['current refresh token', 'App secure storage → website', 'Never embed it in a URL, browser localStorage or logging.'], ['new token pair', 'Website → app secure storage', 'Replace the old access + refresh tokens together.'], ['session deadline', 'Website database', 'Absolute time since original login; refresh does not extend it.'], ['revocation', 'Website database → every patient request', 'Even a correctly signed JWT is rejected after its session is revoked.']]],
        ];
        foreach ($flows as $key => [$title, $steps, $explanation, $requests, $fields]) {
            echo '<article class="cliniko-api-card cliniko-api-flow-panel" data-flow-panel="' . esc_attr($key) . '"><p class="cliniko-api-eyebrow">Request walkthrough</p><h3 tabindex="-1">' . esc_html($title) . '</h3>';
            self::journey($steps);
            echo '<p>' . esc_html($explanation) . '</p><h4>Where does each piece of data go?</h4><div class="cliniko-api-table-scroll"><table class="widefat"><thead><tr><th>Data</th><th>Journey</th><th>What it means</th></tr></thead><tbody>';
            foreach ($fields as [$field, $direction, $meaning]) echo '<tr><td><strong>' . esc_html($field) . '</strong></td><td>' . esc_html($direction) . '</td><td>' . esc_html($meaning) . '</td></tr>';
            echo '</tbody></table></div>';
            foreach ($requests as $index => [$method, $path, $headers, $body, $response]) {
                $request = $method . ' ' . $base . $path . "\n";
                if ($body !== null) $headers['Content-Type'] = 'application/json';
                foreach ($headers as $name => $value) $request .= $name . ': ' . $value . "\n";
                if ($body !== null) $request .= "\n" . wp_json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $id = 'api-flow-' . $key . '-' . $index;
                echo '<div class="cliniko-api-exchange"><section><div class="cliniko-api-code-heading"><h4>App → website · request</h4><button type="button" class="button button-small" data-copy-target="' . esc_attr($id) . '">Copy example</button></div><pre id="' . esc_attr($id) . '">' . esc_html($request) . '</pre></section><section><h4>Website → app · success response</h4><pre>' . esc_html(wp_json_encode(['ok' => true, 'data' => $response, 'meta' => ['request_id' => 'example-request-id']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></section></div>';
            }
            echo '<p class="description">Illustrative successful exchanges, not real tokens or a complete provider SDK implementation. See Endpoint documentation for exact required fields, schemas and failure statuses.</p><p><a class="button" href="' . esc_url(self::url('documentation')) . '">Open endpoint contract</a></p></article>';
        }
    }
}
