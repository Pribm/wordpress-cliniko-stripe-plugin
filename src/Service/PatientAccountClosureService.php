<?php

namespace App\Service;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Closes the local patient portal account after an email-confirmed request.
 * Cliniko clinical records are deliberately outside this service's scope.
 */
final class PatientAccountClosureService
{
    public const META_TOKEN_HASH = '_cliniko_account_closure_token_hash';
    public const META_EXPIRES_AT = '_cliniko_account_closure_expires_at';
    public const META_LAST_SENT_AT = '_cliniko_account_closure_last_sent_at';

    private const TOKEN_TTL = 1800;
    private const RESEND_INTERVAL = 60;

    private PatientLinkService $patientLinks;

    public function __construct(?PatientLinkService $patientLinks = null)
    {
        $this->patientLinks = $patientLinks ?: new PatientLinkService();
    }

    /** @return array{ok:bool,status:string,message:string} */
    public function request(int $userId, string $returnUrl): array
    {
        $user = get_userdata($userId);
        if (
            !$user instanceof \WP_User
            || !$this->patientLinks->userHasConfiguredRole($userId)
            || !$this->patientLinks->isVerifiedUser($userId)
        ) {
            return self::result(false, 'not_allowed', 'This account cannot be closed from the patient portal.');
        }

        $lastSentAt = (int) get_user_meta($userId, self::META_LAST_SENT_AT, true);
        if ($lastSentAt > 0 && (time() - $lastSentAt) < self::RESEND_INTERVAL) {
            return self::result(false, 'rate_limited', 'Please wait before requesting another confirmation email.');
        }

        try {
            $token = self::randomToken();
        } catch (\Throwable $exception) {
            return self::result(false, 'failed', 'A secure confirmation could not be created.');
        }

        $returnUrl = wp_validate_redirect($returnUrl, home_url('/'));
        $returnUrl = remove_query_arg([
            'cliniko_account_closure',
            'cliniko_account_closure_status',
            'user_id',
            'token',
        ], $returnUrl);
        $confirmationUrl = add_query_arg([
            'cliniko_account_closure' => 'confirm',
            'user_id' => $userId,
            'token' => $token,
        ], $returnUrl);

        update_user_meta($userId, self::META_TOKEN_HASH, self::tokenHash($token));
        update_user_meta($userId, self::META_EXPIRES_AT, time() + self::TOKEN_TTL);
        update_user_meta($userId, self::META_LAST_SENT_AT, time());

        $subject = (string) apply_filters(
            'wp_cliniko_patient_account_closure_request_email_subject',
            PatientAccountClosureEmailTemplate::subject(PatientAccountClosureEmailTemplate::REQUEST, $user),
            $user
        );
        $message = PatientAccountClosureEmailTemplate::renderRequest($user, $confirmationUrl);

        if (!wp_mail(
            (string) $user->user_email,
            $subject,
            $message,
            ['Content-Type: text/html; charset=UTF-8']
        )) {
            self::clearChallenge($userId, true);
            return self::result(false, 'email_failed', 'The confirmation email could not be sent.');
        }

        return self::result(true, 'requested', 'Check your email to confirm account closure.');
    }

    public function isValidConfirmation(int $currentUserId, int $requestedUserId, string $token): bool
    {
        if ($currentUserId <= 0 || $currentUserId !== $requestedUserId || $token === '') {
            return false;
        }

        $storedHash = (string) get_user_meta($requestedUserId, self::META_TOKEN_HASH, true);
        $expiresAt = (int) get_user_meta($requestedUserId, self::META_EXPIRES_AT, true);
        return $storedHash !== ''
            && $expiresAt >= time()
            && hash_equals($storedHash, self::tokenHash($token));
    }

    /** @return array{ok:bool,status:string,message:string} */
    public function close(int $currentUserId, int $requestedUserId, string $token): array
    {
        if (!$this->isValidConfirmation($currentUserId, $requestedUserId, $token)) {
            return self::result(false, 'invalid', 'The account-closure confirmation is invalid or expired.');
        }

        $user = get_userdata($requestedUserId);
        if (!$user instanceof \WP_User) {
            return self::result(false, 'invalid', 'The patient portal account no longer exists.');
        }

        if (is_multisite()) {
            return self::result(false, 'multisite', 'Please contact the clinic to close this network account.');
        }

        // Remove encrypted patient dashboard data before the user identity and
        // its cache index disappear.
        if (function_exists('cliniko_dashboard_cache_invalidate')) {
            cliniko_dashboard_cache_invalidate();
        }

        if (class_exists('\WP_Session_Tokens')) {
            \WP_Session_Tokens::get_instance($requestedUserId)->destroy_all();
        }

        do_action('wp_cliniko_before_patient_portal_account_closed', $requestedUserId);

        if (!function_exists('wp_delete_user')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        if (!function_exists('wp_delete_user') || !wp_delete_user($requestedUserId)) {
            return self::result(false, 'failed', 'The portal account could not be closed.');
        }

        wp_clear_auth_cookie();
        do_action('wp_cliniko_patient_portal_account_closed', $requestedUserId);

        $receiptSubject = (string) apply_filters(
            'wp_cliniko_patient_account_closure_receipt_email_subject',
            PatientAccountClosureEmailTemplate::subject(PatientAccountClosureEmailTemplate::RECEIPT, $user),
            $user
        );
        wp_mail(
            (string) $user->user_email,
            $receiptSubject,
            PatientAccountClosureEmailTemplate::renderReceipt($user),
            ['Content-Type: text/html; charset=UTF-8']
        );

        return self::result(true, 'closed', 'Your patient portal account has been closed.');
    }

    private static function clearChallenge(int $userId, bool $includeRateLimit = false): void
    {
        delete_user_meta($userId, self::META_TOKEN_HASH);
        delete_user_meta($userId, self::META_EXPIRES_AT);
        if ($includeRateLimit) {
            delete_user_meta($userId, self::META_LAST_SENT_AT);
        }
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) wp_salt('auth'));
    }

    /** @return array{ok:bool,status:string,message:string} */
    private static function result(bool $ok, string $status, string $message): array
    {
        return compact('ok', 'status', 'message');
    }
}
