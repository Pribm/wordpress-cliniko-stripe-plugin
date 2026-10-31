<?php

namespace App\Service\Api;

use App\Authorization\VerifiedPatientPolicy;
use App\Authorization\Api\AccessPolicy;
use App\Service\PatientService;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) exit;

/** WordPress password login and nonce-bound Google/Apple identity-token exchange. */
final class PatientAuthentication
{
    public static function socialProviders(): array
    {
        return [
            'google' => ['name' => 'Google', 'issuer' => 'https://accounts.google.com', 'issuers' => ['https://accounts.google.com', 'accounts.google.com'], 'jwks' => 'https://www.googleapis.com/oauth2/v3/certs'],
            'apple' => ['name' => 'Apple', 'issuer' => 'https://appleid.apple.com', 'issuers' => ['https://appleid.apple.com'], 'jwks' => 'https://appleid.apple.com/auth/keys'],
        ];
    }

    public static function dispatch(WP_REST_Request $request, string $operation, ?PatientRegistration $registration = null)
    {
        $body = json_decode($request->get_body(), true);
        if (!is_array($body)) return new WP_Error('invalid_json', 'Send a JSON object.', ['status' => 400]);
        $client = ApiClients::all()[(string) ($body['client_id'] ?? '')] ?? null;
        $types = in_array($operation, ['auth_register_oauth', 'auth_registration_status', 'auth_registration_resend'], true) ? ['patient', 'oauth'] : ['patient'];
        if (!$client || !in_array($client['type'], $types, true) || !ApiClients::active($client)) return new WP_Error('client_denied', 'This patient application is not approved.', ['status' => 403]);
        if (!(new ApiRateLimiter())->consume('auth-client:' . $client['id'], 'authentication', 60)) return new WP_Error('rate_limited', 'Too many sign-in requests. Try again later.', ['status' => 429]);
        if ($operation === 'auth_login') return self::password($body, $client);
        if ($operation === 'password_forgot') return PatientAccountSecurity::requestPasswordReset((string) ($body['email'] ?? ''));
        if ($operation === 'password_reset') return PatientAccountSecurity::resetPassword((string) ($body['login'] ?? ''), (string) ($body['key'] ?? ''), (string) ($body['new_password'] ?? ''));
        if ($operation === 'auth_challenge') return self::challenge($body, $client);
        if ($operation === 'auth_social') return self::social($body, $client);
        if ($operation === 'auth_register') return ($registration ?: new PatientRegistration())->register($body, $client, 'password');
        if ($operation === 'auth_register_social') return self::social($body, $client, $registration ?: new PatientRegistration());
        if ($operation === 'auth_register_oauth') {
            $identity = AccessPolicy::oauthIdentity((string) ($body['access_token'] ?? ''));
            if (is_wp_error($identity)) return $identity;
            if ($identity['client']['id'] !== $client['id'] || !in_array('account:register', $identity['scopes'], true)) return new WP_Error('insufficient_scope', 'The provider token and approved application must both allow registration.', ['status' => 403]);
            return ($registration ?: new PatientRegistration())->register($body, $client, 'oauth', $identity);
        }
        if (in_array($operation, ['auth_registration_status', 'auth_registration_resend'], true)) return ($registration ?: new PatientRegistration())->progress($body, $client, $operation === 'auth_registration_resend');
        if (in_array($operation, ['auth_refresh', 'auth_logout'], true)) return PatientSessions::refresh((string) ($body['refresh_token'] ?? ''), $client['id'], $operation === 'auth_logout');
        return new WP_Error('not_found', 'Authentication method unavailable.', ['status' => 404]);
    }

    private static function password(array $body, array $client)
    {
        if (!ApiSettings::get()['password_login_enabled']) return new WP_Error('login_disabled', 'WordPress app login is not enabled.', ['status' => 403]);
        $identifier = trim((string) ($body['identifier'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($identifier === '' || $password === '' || strlen($identifier) > 254 || strlen($password) > 4096) return self::loginDenied();
        // Account throttling supplements IP/application budgets; changing an app ID does not bypass it.
        if (!(new ApiRateLimiter())->consume('patient-login:' . strtolower($identifier), 'password-login', 5)) return new WP_Error('rate_limited', 'Too many sign-in attempts. Try again later.', ['status' => 429]);
        $user = wp_authenticate($identifier, $password);
        if (is_wp_error($user)) return self::loginDenied();
        if (self::passwordBlocked($user)) return new WP_Error('interactive_login_required', 'This account requires the website’s interactive sign-in process. Direct password login is not available.', ['status' => 403]);
        $allowed = self::patientAllowed((int) $user->ID);
        if (is_wp_error($allowed)) return $allowed->get_error_code() === 'cliniko_connection_unavailable' ? $allowed : self::loginDenied();
        return PatientSessions::issue($client, (int) $user->ID, 'password');
    }

    /** Conservative block for known interactive MFA plugins; sites can block custom integrations too. */
    public static function passwordBlocked($user): bool
    {
        $blocked = class_exists('Two_Factor_Core') || class_exists('WordfenceLS\\Controller') || defined('ITSEC_VERSION') || defined('WP_2FA_VERSION');
        $blocked = $blocked || !empty(get_user_meta($user->ID, '_two_factor_enabled_providers', true)) || get_user_meta($user->ID, 'wfls_2fa_status', true) === 'active';
        return (bool) apply_filters('wp_cliniko_api_app_login_requires_browser', $blocked, $user);
    }

    public static function patientAllowed(int $userId)
    {
        $user = get_userdata($userId);
        if (!$user || user_can($user, 'manage_options') || (string) get_user_meta($userId, PatientService::META_ACCOUNT_STATUS, true) !== PatientService::ACCOUNT_ACTIVE) return self::loginDenied();
        $previous = get_current_user_id();
        wp_set_current_user($userId);
        try {
            $allowed = (new VerifiedPatientPolicy())->authorize(new WP_REST_Request('GET'));
            return $allowed === true || is_wp_error($allowed) ? $allowed : self::loginDenied();
        }
        finally { wp_set_current_user($previous); }
    }

    private static function provider(array $body, array $client)
    {
        $provider = (string) ($body['provider'] ?? '');
        $providers = self::socialProviders();
        if (!isset($providers[$provider]) || !ApiSettings::get()[$provider . '_login_enabled'] || empty($client[$provider . '_client_id'])) return new WP_Error('social_login_disabled', 'This social sign-in method is not configured for this application.', ['status' => 403]);
        return $providers[$provider] + ['key' => $provider, 'audience' => $client[$provider . '_client_id'], 'presenter' => $provider === 'google' && !empty($client['google_presenter_id']) ? $client['google_presenter_id'] : $client[$provider . '_client_id']];
    }

    private static function challenge(array $body, array $client)
    {
        $provider = self::provider($body, $client);
        if (is_wp_error($provider)) return $provider;
        if (!PatientSessions::ready()) return new WP_Error('patient_auth_unavailable', 'Patient app sessions are not ready.', ['status' => 503]);
        $purpose = (string) ($body['purpose'] ?? 'login');
        if (!in_array($purpose, ['login', 'register'], true)) return new WP_Error('invalid_challenge_purpose', 'Choose login or register.', ['status' => 400]);
        if ($purpose === 'register' && (!ApiSettings::get()['registration_social_enabled'] || !in_array('account:register', $client['scopes'], true))) return new WP_Error('registration_disabled', 'Social registration is not approved for this app.', ['status' => 403]);
        $id = bin2hex(random_bytes(24));
        $nonce = bin2hex(random_bytes(32));
        // Apple native flows use the SHA-256 nonce supplied to Apple's authorization request.
        $providerNonce = $provider['key'] === 'apple' ? hash('sha256', $nonce) : $nonce;
        if (!set_transient('cliniko_api_social_' . $id, ['client_id' => $client['id'], 'provider' => $provider['key'], 'purpose' => $purpose, 'nonce_hash' => hash('sha256', $providerNonce), 'expires_at' => time() + 300], 300)) return new WP_Error('challenge_unavailable', 'Could not start sign-in. Try again.', ['status' => 503]);
        return ['challenge_id' => $id, 'nonce' => $providerNonce, 'expires_in' => 300, 'provider' => $provider['key'], 'audience' => $provider['audience']];
    }

    private static function social(array $body, array $client, ?PatientRegistration $registration = null)
    {
        $provider = self::provider($body, $client);
        if (is_wp_error($provider)) return $provider;
        $id = (string) ($body['challenge_id'] ?? '');
        $token = (string) ($body['id_token'] ?? '');
        if (!preg_match('/^[a-f0-9]{48}$/D', $id) || $token === '' || strlen($token) > 8192) return self::loginDenied();
        return (new OperationLock())->run('social-challenge:' . $id, static function () use ($id, $provider, $client, $token, $body, $registration) {
            $challenge = get_transient('cliniko_api_social_' . $id);
            if (!is_array($challenge) || $challenge['expires_at'] <= time() || $challenge['client_id'] !== $client['id'] || $challenge['provider'] !== $provider['key']) return self::loginDenied();
            if (($challenge['purpose'] ?? 'login') !== ($registration ? 'register' : 'login')) return self::loginDenied();
            $claims = self::verifySocialToken($token, $provider);
            if (is_wp_error($claims)) return $claims;
            if (!is_string($claims['nonce'] ?? null) || !hash_equals($challenge['nonce_hash'], hash('sha256', $claims['nonce']))) return self::loginDenied();
            // A successful identity assertion can be consumed only once, even if the local account is not mapped.
            if (!delete_transient('cliniko_api_social_' . $id)) return self::loginDenied();
            if ($registration) return $registration->register($body, $client, 'social', ['provider' => $provider['key'], 'issuer' => $provider['issuer'], 'subject' => $claims['sub'], 'email' => $claims['email'] ?? '', 'email_verified' => $claims['email_verified'] ?? false]);
            $userId = ApiClients::userId($provider['issuer'], $claims['sub']);
            if ($userId <= 0) return new WP_Error('patient_mapping_required', 'Ask the clinic to link this sign-in to your existing patient account.', ['status' => 403]);
            $allowed = self::patientAllowed($userId);
            if (is_wp_error($allowed)) return $allowed;
            // Never link by email or automatically create patient accounts from social claims.
            return PatientSessions::issue($client, $userId, $provider['key'], $provider['issuer'], $claims['sub']);
        });
    }

    /** Accept provider-issued RS256 tokens only, with a fixed trusted key endpoint and exact app audience. */
    public static function verifySocialToken(string $token, array $provider)
    {
        $cacheKey = 'cliniko_api_social_keys_' . $provider['key'];
        $jwks = get_transient($cacheKey);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            if (!is_array($jwks)) {
                // Globally bound key refreshes: unknown key IDs cannot trigger unbounded outbound requests.
                if (!(new ApiRateLimiter())->consume('social-keys:' . $provider['key'], 'jwks', 5)) return new WP_Error('social_provider_unavailable', 'Sign-in keys could not be checked. Try again later.', ['status' => 503]);
                $response = wp_safe_remote_get($provider['jwks'], ['timeout' => 10, 'redirection' => 0, 'limit_response_size' => 65536, 'headers' => ['Accept' => 'application/json']]);
                if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('social_provider_unavailable', 'The sign-in service is temporarily unavailable.', ['status' => 503]);
                $jwks = json_decode(wp_remote_retrieve_body($response), true);
                if (!is_array($jwks) || !is_array($jwks['keys'] ?? null)) return self::loginDenied();
                // Do not trust alg supplied by an attacker or accept a provider symmetric key.
                $jwks['keys'] = array_values(array_filter($jwks['keys'], static fn($key): bool => is_array($key) && ($key['kty'] ?? '') === 'RSA' && ($key['alg'] ?? 'RS256') === 'RS256' && ($key['use'] ?? 'sig') === 'sig'));
                set_transient($cacheKey, $jwks, 3600);
            }
            try {
                $claims = (array) JWT::decode($token, JWK::parseKeySet($jwks, 'RS256'));
                $presenter = $provider['presenter'] ?? $provider['audience'];
                if (!in_array($claims['iss'] ?? '', $provider['issuers'], true) || ($claims['aud'] ?? '') !== $provider['audience'] || (isset($claims['azp']) && $claims['azp'] !== $presenter) || ($presenter !== $provider['audience'] && !isset($claims['azp'])) || !is_string($claims['sub'] ?? null) || $claims['sub'] === '' || strlen($claims['sub']) > 255 || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time() || !is_int($claims['iat'] ?? null) || $claims['iat'] > time() + 30 || (isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > time()))) return self::loginDenied();
                return $claims;
            } catch (\Throwable $error) {
                // One bounded refetch permits provider key rotation. Any second failure remains denied.
                $jwks = null;
            }
        }
        return self::loginDenied();
    }

    private static function loginDenied(): WP_Error { return new WP_Error('login_failed', 'Sign-in could not be completed. Check your details or contact the clinic.', ['status' => 401]); }
}
