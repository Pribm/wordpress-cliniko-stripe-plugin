<?php

namespace App\Authorization\Api;

use App\Authorization\VerifiedPatientPolicy;
use App\Service\Api\ApiClients;
use App\Service\Api\ApiRateLimiter;
use App\Service\Api\ApiSettings;
use App\Service\Api\EndpointCatalog;
use App\Service\Api\PatientSessions;
use App\Service\BookingAttemptStore;
use App\Service\PublicRequestGuard;
use App\Service\PatientService;
use App\Service\Api\PatientOnboardingApi;
use App\Support\Auth;
use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) exit;

final class AccessPolicy
{
    private ApiRateLimiter $limiter;
    private VerifiedPatientPolicy $patients;
    public function __construct(?ApiRateLimiter $limiter = null, ?VerifiedPatientPolicy $patients = null)
    {
        $this->limiter = $limiter ?: new ApiRateLimiter();
        $this->patients = $patients ?: new VerifiedPatientPolicy();
    }

    public function authorize(WP_REST_Request $request, array $endpoint)
    {
        $request->set_param('_cliniko_api_client', '');
        $request->set_param('_cliniko_api_user', 0);
        if (EndpointCatalog::state($endpoint) !== 'Active') return self::deny('api_disabled', 'This API feature is disabled.', 404);
        if (!is_ssl() && !in_array(wp_get_environment_type(), ['local', 'development'], true)) return self::deny('https_required', 'HTTPS is required.', 403);
        if (get_option('wp_cliniko_api_schema') !== '2') return self::deny('api_storage_unavailable', 'API storage is not ready. Open API Access in WordPress administration first.', 503);
        $settings = ApiSettings::get();
        $origin = trim((string) $request->get_header('origin'));
        $site = rtrim((string) wp_parse_url(home_url(), PHP_URL_SCHEME) . '://' . wp_parse_url(home_url(), PHP_URL_HOST), '/');
        $port = wp_parse_url(home_url(), PHP_URL_PORT);
        if ($port) $site .= ':' . $port;
        if ($origin !== '' && $origin !== $site && !ApiSettings::browserOriginAllowed($origin)) return self::deny('origin_denied', 'This browser origin is not allowed.', 403);
        $limit = $request->get_method() === 'GET' ? $settings['read_limit'] : $settings['write_limit'];
        if (!$this->limiter->consume('ip:' . ApiRateLimiter::remoteAddress(), $request->get_method() === 'GET' ? 'read' : 'write', (int) $limit)) return self::deny('rate_limited', 'Request limit reached. Please try again later.', 429);
        if ($endpoint['group'] === 'authentication') {
            if (!$this->limiter->consume('auth-ip:' . ApiRateLimiter::remoteAddress(), 'authentication', 20)) return self::deny('rate_limited', 'Too many sign-in requests. Try again later.', 429);
            // Credentials/challenge/refresh proof are checked by the authentication operation itself.
            if (trim((string) $request->get_header('authorization')) !== '') return self::deny('unexpected_authorization', 'Login operations use JSON credentials, not an Authorization header.', 400);
            return true;
        }

        $credential = trim((string) $request->get_header('authorization'));
        $client = null;
        $scopes = [];
        $userId = 0;
        if ($credential !== '') {
            if (!preg_match('/^Bearer ([^\s]+)$/iD', $credential, $matches) || strlen($matches[1]) > 8192) return self::deny('invalid_token', 'A valid bearer access token is required.', 401);
            $token = $matches[1];
            if (str_starts_with($token, 'reg_')) return self::deny('registration_token_not_access_token', 'An onboarding receipt cannot access patient data. Complete verification and sign in.', 401);
            if (str_starts_with($token, 'capi_')) {
                $client = ApiClients::server($token);
                if (!$client) return self::deny('invalid_token', 'The client credential is invalid, expired or revoked.', 401);
                $scopes = $client['scopes'];
                if (in_array($endpoint['auth'], ['patient', 'attempt'], true)) return self::deny('patient_required', 'Server credentials cannot access patient accounts or bookings.', 403);
            } else {
                $identity = PatientSessions::isLocal($token) ? PatientSessions::identity($token) : $this->introspect($token);
                if ($identity instanceof WP_Error) return $identity;
                $client = $identity['client'];
                $scopes = $identity['scopes'];
                $userId = $identity['user_id'];
            }
            if ($endpoint['scope'] !== '' && !in_array($endpoint['scope'], $scopes, true)) return self::deny('insufficient_scope', 'The token does not grant this operation.', 403);
            $request->set_param('_cliniko_api_scopes', $scopes);
            if (!$this->limiter->consume('client:' . $client['id'], $request->get_method() === 'GET' ? 'read' : 'write', (int) $limit)) return self::deny('rate_limited', 'Application request limit reached.', 429);
            $request->set_param('_cliniko_api_client', $client['id']);
            ApiClients::touch($client['id']);
        }
        if (in_array($endpoint['auth'], ['patient', 'attempt'], true)) {
            if ($credential === '') return self::deny('authentication_required', 'Use a patient bearer access token for patient endpoints.', 401);
            if ($userId <= 0) return self::deny('patient_mapping_required', 'The token subject has no approved patient mapping.', 403);
            if (!get_userdata($userId) || (string) get_user_meta($userId, PatientService::META_ACCOUNT_STATUS, true) !== PatientService::ACCOUNT_ACTIVE) return self::deny('patient_inactive', 'The patient account is not active.', 403);
            $request->set_param('_cliniko_api_user', $userId);
            $previous = get_current_user_id();
            wp_set_current_user($userId);
            try { $allowed = $this->patients->authorize($request); }
            finally { wp_set_current_user($previous); }
            if ($allowed !== true) return $allowed;
            if ($endpoint['auth'] === 'patient') {
                if ($endpoint['key'] === 'onboarding_complete') {
                    $token = trim((string) $request->get_header('x-es-onboarding-token'));
                    $revision = (string) $request->get_param('definition_revision');
                    $slug = (string) ($request->get_url_params()['slug'] ?? '');
                    if (!PatientOnboardingApi::validateSession($token, $userId, (string) $request->get_param('_cliniko_api_client'), $slug, $revision)) return self::deny('invalid_onboarding_token', 'A valid onboarding session token is required for this patient, app and definition revision.', 403);
                }
                if (in_array($endpoint['key'], ['patient_booking_create', 'booking_flow_create'], true)) {
                    try { $required = PatientOnboardingApi::requiredForBooking($userId); }
                    catch (\Throwable $exception) { return self::deny('onboarding_unavailable', 'The patient profile could not be checked before booking.', 503); }
                    if (is_array($required)) return self::deny('onboarding_required', 'Complete the patient profile onboarding before creating a booking.', 403, ['slug' => (string) ($required['slug'] ?? ''), 'path' => '/patient-onboarding/' . (string) ($required['slug'] ?? '')]);
                }
                return true;
            }
        }
        if ($endpoint['auth'] === 'attempt') {
            $id = (string) ($request->get_url_params()['id'] ?? '');
            $secret = (string) $request->get_header('x-es-attempt-token');
            $attempt = (new BookingAttemptStore())->get($id);
            if ($secret === '' || !$attempt || empty($attempt['attempt_token_hash']) || !hash_equals($attempt['attempt_token_hash'], PublicRequestGuard::hashAttemptToken($secret))) return self::deny('invalid_attempt_token', 'A valid attempt token is required.', 403);
            $createdAt = strtotime((string) ($attempt['created_at'] ?? ''));
            if (!$createdAt || $createdAt < time() - DAY_IN_SECONDS) return self::deny('attempt_expired', 'The attempt access token has expired.', 403);
            if (empty($attempt['api_client_id']) || !$client || $attempt['api_client_id'] !== $client['id']) return self::deny('attempt_client_mismatch', 'This attempt belongs to another application.', 403);
            if (($attempt['api_namespace'] ?? '') === '') return self::deny('legacy_attempt', 'This API accepts only attempts created through API Access.', 403);
            if (($attempt['patient_mode'] ?? '') !== 'account') return self::deny('guest_attempt_not_supported', 'Guest booking attempts are not supported by this API.', 403);
            if ($userId !== (int) ($attempt['user_id'] ?? 0)) return self::deny('attempt_patient_mismatch', 'The original verified patient must authorize this attempt.', 403);
            $previous = get_current_user_id();
            wp_set_current_user($userId);
            try { $patient = Auth::patientData(); }
            catch (\Throwable $exception) { return self::deny('cliniko_connection_unavailable', 'The linked patient could not be checked. Please try again later.', 503); }
            finally { wp_set_current_user($previous); }
            if (!$patient || empty($attempt['patient_id']) || (string) $patient['id'] !== (string) $attempt['patient_id']) return self::deny('attempt_patient_mismatch', 'This attempt no longer belongs to the linked patient.', 403);
        }
        return true;
    }

    private function introspect(string $token)
    {
        return self::oauthIdentity($token);
    }

    /** Validated provider identity; a missing mapping never grants patient access. */
    public static function oauthIdentity(string $token)
    {
        if ($token === '' || strlen($token) > 8192 || preg_match('/\s/', $token)) return self::deny('invalid_token', 'A valid provider access token is required.', 401);
        $settings = ApiSettings::get();
        $claims = self::providerResponse($token);
        if ($claims instanceof WP_Error) return $claims;
        if (($claims['active'] ?? false) !== true || strtolower((string) ($claims['token_type'] ?? '')) !== 'bearer' || !is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] <= time() || (int) ($claims['nbf'] ?? 0) > time()) return self::deny('invalid_token', 'The bearer access token is inactive or expired.', 401);
        // Require these claims even though RFC 7662 makes some optional.
        if (($claims['iss'] ?? '') !== $settings['issuer'] || !in_array($settings['audience'], (array) ($claims['aud'] ?? []), true) || empty($claims['sub']) || !is_string($claims['sub']) || empty($claims['client_id']) || !is_string($claims['scope'] ?? null)) return self::deny('invalid_token_claims', 'The token issuer, audience or subject is not valid for this API.', 401);
        $client = ApiClients::oauth((string) $claims['client_id']);
        if (!$client) return self::deny('client_denied', 'The token application is not enabled for this API.', 403);
        $scopes = array_values(array_intersect($client['scopes'], preg_split('/\s+/', trim($claims['scope'])) ?: []));
        return ['client' => $client, 'scopes' => $scopes, 'user_id' => ApiClients::userId($settings['issuer'], $claims['sub']), 'issuer' => $settings['issuer'], 'subject' => $claims['sub'], 'email' => $claims['email'] ?? '', 'email_verified' => $claims['email_verified'] ?? false];
    }

    public static function testProvider()
    {
        // Send an intentionally invalid token; never ask an admin to paste a patient token.
        $response = self::providerResponse('cliniko-connectivity-test-' . bin2hex(random_bytes(16)));
        if ($response instanceof WP_Error) return $response;
        if (($response['active'] ?? null) !== false) return self::deny('oauth_test_failed', 'The provider must report an invalid test token as inactive.', 503);
        return true;
    }

    private static function providerResponse(string $token)
    {
        $settings = ApiSettings::get();
        $secret = \wp_cliniko_get_secret_option(ApiSettings::SECRET);
        if (!$settings['oauth_enabled'] || $secret === '') return self::deny('oauth_unconfigured', 'OAuth bearer authentication is not configured.', 503);
        $response = wp_safe_remote_post($settings['introspection_url'], [
            'timeout' => 10, 'redirection' => 0, 'limit_response_size' => 32768,
            'headers' => ['Authorization' => 'Basic ' . base64_encode(rawurlencode($settings['introspection_client']) . ':' . rawurlencode($secret)), 'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded'],
            'body' => ['token' => $token, 'token_type_hint' => 'access_token'],
        ]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return self::deny('oauth_unavailable', 'The authentication provider is temporarily unavailable.', 503);
        $claims = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($claims) ? $claims : self::deny('oauth_response_invalid', 'The provider returned an invalid introspection response.', 503);
    }

    private static function deny(string $code, string $message, int $status, array $fields = []): WP_Error { return new WP_Error($code, $message, ['status' => $status, 'fields' => $fields]); }
}
