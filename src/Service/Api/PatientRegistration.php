<?php

namespace App\Service\Api;

use App\Service\PatientLinkService;
use App\Service\PatientService;
use WP_Error;

if (!defined('ABSPATH')) exit;

/** Pending account creation only: onboarding receipts can never authorize patient-data routes. */
final class PatientRegistration
{
    public const META_CLIENT = '_cliniko_api_registration_client';
    public const META_METHOD = '_cliniko_api_registration_method';
    public const META_CONSENT = '_cliniko_api_registration_consent_at';
    private PatientService $patients;
    private ?PatientLinkService $links;

    public function __construct(?PatientService $patients = null, ?PatientLinkService $links = null) { $this->patients = $patients ?: new PatientService(); $this->links = $links; }

    public static function eligibleRoles(): array
    {
        $configured = get_option('wp_cliniko_patient_sync_roles', []);
        $result = [];
        foreach (is_array($configured) ? $configured : [] as $key) {
            if (!is_string($key)) continue;
            $role = get_role($key);
            if (!$role) continue;
            // Public signup may grant only low-privilege patient roles, never admin/editor/custom elevated caps.
            foreach ($role->capabilities as $capability => $enabled) if ($enabled && !in_array($capability, ['read', 'level_0'], true)) continue 2;
            $result[$key] = $role->name;
        }
        return $result;
    }

    public static function enabled(): bool
    {
        $s = ApiSettings::get();
        return $s['registration_password_enabled'] || $s['registration_social_enabled'] || $s['registration_oauth_enabled'];
    }

    public function register(array $body, array $client, string $method, ?array $identity = null)
    {
        $flag = ['password' => 'registration_password_enabled', 'social' => 'registration_social_enabled', 'oauth' => 'registration_oauth_enabled'][$method] ?? '';
        $s = ApiSettings::get();
        if ($flag === '' || empty($s[$flag]) || !$s['registration_reviewed'] || !get_option('wp_cliniko_patient_sync_enabled', false) || !isset(self::eligibleRoles()[$s['registration_role']])) return new WP_Error('registration_disabled', 'App registration is not configured. Contact the clinic.', ['status' => 403]);
        $type = $method === 'oauth' ? 'oauth' : 'patient';
        $current = ApiClients::all()[$client['id']] ?? null;
        if (!$current || $current['type'] !== $type || !ApiClients::active($current) || !in_array('account:register', $current['scopes'], true)) return new WP_Error('insufficient_scope', 'This application cannot register patient accounts.', ['status' => 403]);
        if (!apply_filters('wp_cliniko_api_registration_allowed', true, $method, $current)) return new WP_Error('registration_disabled', 'Use the website registration process or contact the clinic.', ['status' => 403]);
        if (($body['accept_privacy_policy'] ?? null) !== true) return new WP_Error('privacy_consent_required', 'Accept the clinic privacy policy before registering.', ['status' => 400]);
        $names = [];
        foreach (['first_name', 'last_name'] as $key) {
            if (!is_string($body[$key] ?? null) || trim($body[$key]) === '' || strlen($body[$key]) > 120) return new WP_Error('invalid_registration_name', 'Provide your first and last names.', ['status' => 400]);
            $names[$key] = sanitize_text_field(trim($body[$key]));
            if ($names[$key] === '') return new WP_Error('invalid_registration_name', 'Provide your first and last names.', ['status' => 400]);
        }
        $email = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        if ($method !== 'password') {
            if (!$identity || !is_string($identity['issuer'] ?? null) || $identity['issuer'] === '' || !is_string($identity['subject'] ?? null) || $identity['subject'] === '' || strlen($identity['subject']) > 255 || !in_array($identity['email_verified'] ?? false, [true, 'true'], true) || !is_string($identity['email'] ?? null)) return new WP_Error('verified_email_required', 'The sign-in service must supply a verified email for registration. Otherwise register through the website.', ['status' => 400]);
            // A caller must not attach their social/provider identity to someone else's clinic email.
            if ($email !== '' && strcasecmp($email, trim($identity['email'])) !== 0) return new WP_Error('registration_email_mismatch', 'Use the email verified by your sign-in service, or register separately through the website.', ['status' => 400]);
            $email = trim($identity['email']);
            $password = bin2hex(random_bytes(32)); // Never returned; social/OAuth registration sets no caller-known password.
        } elseif (str_contains($password, "\0") || !preg_match('/^.{12,128}$/usD', $password)) return new WP_Error('registration_password_required', 'Choose a password with 12–128 characters.', ['status' => 400]);
        $email = strtolower($email);
        if (strlen($email) > 254 || !is_email($email)) return new WP_Error('invalid_registration_email', 'Provide a valid email address.', ['status' => 400]);
        if (!(new ApiRateLimiter())->consume('register-email:' . $email, 'registration', 3)) return new WP_Error('rate_limited', 'Too many registration attempts. Try again later.', ['status' => 429]);
        return (new OperationLock())->run('register-email:' . $email, function () use ($client, $method, $identity, $s, $names, $email, $password) {
            $callback = function () use ($client, $method, $identity, $s, $names, $email, $password) {
                // Both existing-email and already-linked-identity cases return the same generic envelope.
                // Never overwrite their password, role, profile, status or identity links.
                if (email_exists($email) || ($identity && ApiClients::userId($identity['issuer'], $identity['subject']) > 0)) return $this->receipt($client['id'], 0, $email);
                $provisional = $this->receipt($client['id'], 0, $email);
                if (is_wp_error($provisional)) return $provisional;
                $consentKey = sanitize_key((string) get_option('wp_cliniko_patient_privacy_meta_key', 'accepted_privacy_policy'));
                $meta = [PatientService::META_ACCOUNT_STATUS => PatientService::ACCOUNT_PENDING_REVIEW, self::META_CLIENT => $client['id'], self::META_METHOD => $identity ? ($method === 'social' ? $identity['provider'] : 'oauth') : 'password', self::META_CONSENT => gmdate('Y-m-d H:i:s')];
                if ($consentKey !== '') $meta[$consentKey] = '1';
                $userdata = ['user_login' => 'patient_' . bin2hex(random_bytes(12)), 'user_email' => wp_slash($email), 'user_pass' => $password, 'first_name' => wp_slash($names['first_name']), 'last_name' => wp_slash($names['last_name']), 'display_name' => wp_slash($names['first_name'] . ' ' . $names['last_name']), 'role' => $s['registration_role'], 'meta_input' => $meta];
                $id = PatientService::withDeferredRegistrationSync(static fn() => wp_insert_user($userdata));
                if (is_wp_error($id)) {
                    if (in_array($id->get_error_code(), ['existing_user_email', 'existing_user_login'], true)) return $provisional;
                    return new WP_Error('registration_unavailable', 'Registration could not be completed. Try again later or contact the clinic.', ['status' => 503]);
                }
                if ($id <= 0) return new WP_Error('registration_unavailable', 'Registration could not be completed. Try again later or contact the clinic.', ['status' => 503]);
                PatientService::markAccountPendingReview($id);
                if ($identity) {
                    $mapped = ApiClients::map($identity['issuer'], $identity['subject'], $id, true);
                    if (is_wp_error($mapped)) {
                        ApiAudit::record('registration_link_failed', 'registration', $client['id'], $id, 503);
                        return new WP_Error('registration_link_unavailable', 'The account is pending but its sign-in link could not be saved. Contact the clinic; no patient access was granted.', ['status' => 503]);
                    }
                }
                $token = $provisional['registration_token'];
                $key = self::receiptKey($token);
                $record = get_transient($key);
                if (!is_array($record)) return new WP_Error('registration_unavailable', 'The account is pending. Contact the clinic for verification help.', ['status' => 503]);
                $record['user_id'] = $id;
                if (!set_transient($key, $record, DAY_IN_SECONDS)) return new WP_Error('registration_unavailable', 'The account is pending. Contact the clinic for verification help.', ['status' => 503]);
                // Normal WordPress creation hooks ran; deferred Cliniko sync now has complete role/consent/name data.
                // This reuses the existing email template, token storage, Cliniko matching and approval flow.
                $this->patients->syncUser($id);
                ApiAudit::record('patient_registration_pending', 'registration', $client['id'], $id, 202);
                return $provisional;
            };
            return $identity ? (new OperationLock())->run('register-identity:' . $identity['issuer'] . '|' . $identity['subject'], $callback) : $callback();
        });
    }

    private function receipt(string $clientId, int $userId, string $email)
    {
        $token = 'reg_' . bin2hex(random_bytes(24)) . '.' . bin2hex(random_bytes(32));
        $record = ['client_id' => $clientId, 'user_id' => $userId, 'email_hash' => self::hash(strtolower($email)), 'token_hash' => self::hash($token), 'expires_at' => time() + DAY_IN_SECONDS];
        if (!set_transient(self::receiptKey($token), $record, DAY_IN_SECONDS)) return new WP_Error('registration_unavailable', 'Registration could not be started. Try again later.', ['status' => 503]);
        return ['status' => 'verification_pending', 'message' => self::pendingMessage(), 'registration_token' => $token, 'expires_in' => DAY_IN_SECONDS];
    }

    public function progress(array $body, array $client, bool $resend = false)
    {
        $token = (string) ($body['registration_token'] ?? '');
        if (!preg_match('/^reg_[a-f0-9]{48}\.[a-f0-9]{64}$/D', $token)) return self::invalidReceipt();
        return (new OperationLock())->run('registration-receipt:' . self::receiptKey($token), function () use ($token, $client, $resend) {
            $record = get_transient(self::receiptKey($token));
            if (!is_array($record) || $record['expires_at'] <= time() || $record['client_id'] !== $client['id'] || !hash_equals($record['token_hash'], self::hash($token))) return self::invalidReceipt();
            $user = $record['user_id'] > 0 ? get_userdata((int) $record['user_id']) : null;
            if ($record['user_id'] > 0 && (!$user || !hash_equals($record['email_hash'], self::hash(strtolower(trim($user->user_email)))))) return self::invalidReceipt();
            $ready = $user && get_user_meta($user->ID, PatientService::META_ACCOUNT_STATUS, true) === PatientService::ACCOUNT_ACTIVE && ($this->links ?: new PatientLinkService())->isVerifiedUser($user->ID);
            if ($resend) {
                // Also limit dummy receipts, so existing-account responses do not reveal email membership.
                if (!(new ApiRateLimiter())->consume('registration-resend:' . $record['email_hash'], 'resend', 3)) return new WP_Error('rate_limited', 'Too many email requests. Try again later.', ['status' => 429]);
                if ($user && !$ready && get_option('wp_cliniko_patient_sync_enabled', false) && ($this->links ?: new PatientLinkService())->userHasConfiguredRole($user->ID) && get_user_meta($user->ID, PatientService::META_ACCOUNT_STATUS, true) === PatientService::ACCOUNT_PENDING_REVIEW && (int) get_user_meta($user->ID, '_cliniko_link_last_sent_at', true) <= time() - 60) $this->patients->syncUser($user->ID, true);
            }
            return ['status' => $ready ? 'ready_to_sign_in' : 'verification_pending', 'message' => $ready ? 'Verification is complete. Sign in through your selected login method.' : self::pendingMessage()];
        });
    }

    private static function receiptKey(string $token): string { return 'cliniko_api_registration_' . substr($token, 4, 48); }
    private static function hash(string $value): string { return hash_hmac('sha256', $value, wp_salt('auth')); }
    private static function pendingMessage(): string { return 'If registration can proceed, check your email to verify the patient account. Existing users should sign in or contact the clinic. Patient API access requires completed verification and approval.'; }
    private static function invalidReceipt(): WP_Error { return new WP_Error('invalid_registration_token', 'The onboarding receipt is invalid or expired. Use your verification email or contact the clinic.', ['status' => 401]); }
}
