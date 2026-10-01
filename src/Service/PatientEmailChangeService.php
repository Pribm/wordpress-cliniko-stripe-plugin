<?php

namespace App\Service;

use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;
use App\Debug\Settings as DebugSettings;
use App\Model\Patient;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientEmailChangeService
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNCHANGED = 'unchanged';

    private const META_STATUS = '_cliniko_email_change_status';
    private const META_NEW_EMAIL = '_cliniko_email_change_new_email';
    private const META_OLD_EMAIL_HASH = '_cliniko_email_change_old_email_hash';
    private const META_PATIENT_ID_HASH = '_cliniko_email_change_patient_id_hash';
    private const META_TOKEN_HASH = '_cliniko_email_change_token_hash';
    private const META_EXPIRES_AT = '_cliniko_email_change_expires_at';
    private const META_ATTEMPTS = '_cliniko_email_change_attempts';
    private const META_LAST_SENT_AT = '_cliniko_email_change_last_sent_at';

    private const TOKEN_TTL = 1800;
    private const RESEND_INTERVAL = 60;
    private const MAX_ATTEMPTS = 5;

    private ApiClientInterface $client;
    private PatientLinkService $links;

    public function __construct(
        ?ApiClientInterface $client = null,
        ?PatientLinkService $links = null
    ) {
        $this->client = $client ?: Client::getInstance();
        $this->links = $links ?: new PatientLinkService($this->client);
    }

    /** @return array{ok:bool,status:string,message:string,http_status:int} */
    public function requestForCurrentUser(string $newEmail): array
    {
        return $this->request((int) get_current_user_id(), $newEmail);
    }

    /** @return array{ok:bool,status:string,message:string,http_status:int} */
    public function request(int $userId, string $newEmail): array
    {
        $user = get_userdata($userId);
        $newEmail = self::normalizeEmail($newEmail);
        if (!$user instanceof \WP_User || !$this->links->isVerifiedUser($userId)) {
            return self::result(false, self::STATUS_FAILED, 'A verified patient account is required.', 403);
        }
        if ($newEmail === '' || !is_email($newEmail)) {
            return self::result(false, self::STATUS_FAILED, 'Please provide a valid email address.', 400);
        }

        $oldEmail = self::normalizeEmail((string) $user->user_email);
        if (hash_equals($oldEmail, $newEmail)) {
            return self::result(true, self::STATUS_UNCHANGED, 'The email address is unchanged.', 200);
        }

        $patientId = $this->linkedPatientId($userId);
        if ($patientId === '') {
            return self::result(false, self::STATUS_FAILED, 'We couldn’t verify your account details. Please contact us for help.', 409);
        }

        $conflict = $this->checkConflicts($userId, $patientId, $oldEmail, $newEmail, false);
        if ($conflict !== null) {
            return $conflict;
        }

        $lastSentAt = (int) get_user_meta($userId, self::META_LAST_SENT_AT, true);
        if ($lastSentAt > 0 && (time() - $lastSentAt) < self::RESEND_INTERVAL) {
            return self::result(false, self::STATUS_FAILED, 'Please wait before requesting another confirmation email.', 429);
        }

        try {
            $token = self::randomToken();
        } catch (\Throwable $exception) {
            return self::result(false, self::STATUS_FAILED, 'A secure confirmation link could not be created.', 500);
        }

        update_user_meta($userId, self::META_STATUS, self::STATUS_PENDING);
        update_user_meta($userId, self::META_NEW_EMAIL, \wp_cliniko_secret_option_encrypt($newEmail));
        update_user_meta($userId, self::META_OLD_EMAIL_HASH, self::hashValue($oldEmail));
        update_user_meta($userId, self::META_PATIENT_ID_HASH, self::hashValue($patientId));
        update_user_meta($userId, self::META_TOKEN_HASH, self::hashValue($token));
        update_user_meta($userId, self::META_EXPIRES_AT, time() + self::TOKEN_TTL);
        update_user_meta($userId, self::META_ATTEMPTS, 0);
        update_user_meta($userId, self::META_LAST_SENT_AT, time());

        $verificationUrl = add_query_arg([
            'user_id' => $userId,
            'token' => $token,
        ], rest_url('v2/patient/email-change/verify'));
        $subject = (string) apply_filters(
            'wp_cliniko_patient_email_change_subject',
            sprintf('Confirm your new email for %s', (string) get_bloginfo('name'))
        );
        $message = $this->emailMessage($user, $newEmail, $verificationUrl);
        if (!wp_mail($newEmail, $subject, $message, ['Content-Type: text/html; charset=UTF-8'])) {
            $this->clearPending($userId);
            return self::result(false, self::STATUS_FAILED, 'The confirmation email could not be sent.', 500);
        }

        return self::result(true, self::STATUS_PENDING, 'A confirmation link was sent to the new email address.', 202);
    }

    /** @return array{ok:bool,status:string,message:string,http_status:int} */
    public function verify(int $userId, string $token): array
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || (string) get_user_meta($userId, self::META_STATUS, true) !== self::STATUS_PENDING) {
            return self::result(false, self::STATUS_FAILED, 'The confirmation link is invalid or already used.', 400);
        }
        if ((int) get_user_meta($userId, self::META_EXPIRES_AT, true) < time()) {
            $this->clearPending($userId);
            return self::result(false, self::STATUS_FAILED, 'The confirmation link has expired.', 410);
        }

        $attempts = (int) get_user_meta($userId, self::META_ATTEMPTS, true);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return self::result(false, self::STATUS_FAILED, 'Confirmation attempts exceeded.', 429);
        }
        $storedTokenHash = (string) get_user_meta($userId, self::META_TOKEN_HASH, true);
        if ($token === '' || $storedTokenHash === '' || !hash_equals($storedTokenHash, self::hashValue($token))) {
            update_user_meta($userId, self::META_ATTEMPTS, $attempts + 1);
            return self::result(false, self::STATUS_FAILED, 'The confirmation link is invalid.', 400);
        }

        $newEmail = self::normalizeEmail($this->readEncryptedMeta($userId, self::META_NEW_EMAIL));
        $oldEmail = self::normalizeEmail((string) $user->user_email);
        $patientId = $this->linkedPatientId($userId);
        if (
            $newEmail === ''
            || $patientId === ''
            || !hash_equals((string) get_user_meta($userId, self::META_OLD_EMAIL_HASH, true), self::hashValue($oldEmail))
            || !hash_equals((string) get_user_meta($userId, self::META_PATIENT_ID_HASH, true), self::hashValue($patientId))
            || !$this->links->isVerifiedUser($userId)
        ) {
            $this->clearPending($userId);
            return self::result(false, self::STATUS_FAILED, 'The account changed after this confirmation was requested.', 409);
        }

        $conflict = $this->checkConflicts($userId, $patientId, $oldEmail, $newEmail, true);
        if ($conflict !== null) {
            return $conflict;
        }

        try {
            $updatedPatient = Patient::update($patientId, ['email' => $newEmail], $this->client);
            if ($updatedPatient === null || !hash_equals(self::normalizeEmail((string) $updatedPatient->getEmail()), $newEmail)) {
                return self::result(false, self::STATUS_FAILED, 'We couldn’t update your email address. Please try again later.', 502);
            }
        } catch (\Throwable $exception) {
            error_log('Cliniko patient email change failed: ' . $exception->getMessage());
            return self::result(false, self::STATUS_FAILED, 'We couldn’t update your email address. Please try again later.', 503);
        }

        $wordpressResult = PatientLinkService::updateVerifiedWordPressEmail($userId, $newEmail);
        if (function_exists('is_wp_error') && is_wp_error($wordpressResult)) {
            try {
                Patient::update($patientId, ['email' => $oldEmail], $this->client);
            } catch (\Throwable $rollbackException) {
                error_log('Cliniko patient email rollback failed: ' . $rollbackException->getMessage());
                $this->links->revoke($userId);
            }
            return self::result(false, self::STATUS_FAILED, 'We couldn’t finish updating your email address. Please contact us for help.', 500);
        }

        $this->links->completeVerifiedEmailChange($userId, $newEmail);
        if (function_exists('cliniko_dashboard_cache_invalidate')) {
            cliniko_dashboard_cache_invalidate();
        }
        $this->clearPending($userId);
        if (DebugSettings::authBehaviourEnabled(DebugSettings::AUTH_BEHAVIOUR_EMAIL_CHANGE_SESSIONS)) {
            $this->destroySessions($userId);
        }

        return self::result(true, self::STATUS_COMPLETED, 'Your email was updated. Please sign in again.', 200);
    }

    /**
     * @return array{ok:bool,status:string,message:string,http_status:int}|null
     */
    private function checkConflicts(
        int $userId,
        string $patientId,
        string $oldEmail,
        string $newEmail,
        bool $allowCurrentPatientNewEmail
    ): ?array {
        if (function_exists('email_exists')) {
            $existingUserId = (int) email_exists($newEmail);
            if ($existingUserId > 0 && $existingUserId !== $userId) {
                return self::result(false, self::STATUS_CONFLICT, 'That email address isn’t available. Please use a different one or contact us for help.', 409);
            }
        }

        try {
            $patient = Patient::find($patientId, $this->client, true);
            $patientEmail = $patient ? self::normalizeEmail((string) $patient->getEmail()) : '';
            $allowedEmails = $allowCurrentPatientNewEmail ? [$oldEmail, $newEmail] : [$oldEmail];
            if ($patient === null || !in_array($patientEmail, $allowedEmails, true)) {
                return self::result(false, self::STATUS_CONFLICT, 'We couldn’t verify your account details. Please contact us for help.', 409);
            }

            $matches = Patient::queryManyByQueryString(
                '?q[]=' . rawurlencode('email:=' . $newEmail),
                $this->client,
                true
            );
            foreach ($matches as $match) {
                if ((string) $match->getId() !== $patientId) {
                    return self::result(false, self::STATUS_CONFLICT, 'That email address isn’t available. Please use a different one or contact us for help.', 409);
                }
            }
        } catch (\Throwable $exception) {
            error_log('Cliniko patient email conflict check failed: ' . $exception->getMessage());
            return self::result(false, self::STATUS_FAILED, 'We couldn’t verify that email address right now. Please try again later.', 503);
        }

        return null;
    }

    private function linkedPatientId(int $userId): string
    {
        return $this->readEncryptedMeta($userId, PatientService::META_PATIENT_ID);
    }

    private function readEncryptedMeta(int $userId, string $key): string
    {
        $stored = (string) get_user_meta($userId, $key, true);
        return $stored !== '' ? trim((string) \wp_cliniko_secret_option_decrypt($stored)) : '';
    }

    private function clearPending(int $userId): void
    {
        foreach ([
            self::META_STATUS,
            self::META_NEW_EMAIL,
            self::META_OLD_EMAIL_HASH,
            self::META_PATIENT_ID_HASH,
            self::META_TOKEN_HASH,
            self::META_EXPIRES_AT,
            self::META_ATTEMPTS,
        ] as $key) {
            delete_user_meta($userId, $key);
        }
    }

    private function destroySessions(int $userId): void
    {
        if (class_exists('\WP_Session_Tokens')) {
            \WP_Session_Tokens::get_instance($userId)->destroy_all();
        }
        if ((int) get_current_user_id() === $userId && function_exists('wp_clear_auth_cookie')) {
            wp_clear_auth_cookie();
        }
    }

    private function emailMessage(\WP_User $user, string $newEmail, string $verificationUrl): string
    {
        $displayName = trim((string) $user->display_name);
        $message = '<p>Hello ' . esc_html($displayName !== '' ? $displayName : 'Patient') . ',</p>';
        $message .= '<p>Confirm that you want to use <strong>' . esc_html($newEmail) . '</strong> for your account.</p>';
        $message .= '<p><a href="' . esc_url($verificationUrl) . '">Confirm new email address</a></p>';
        $message .= '<p>This link expires in 30 minutes and can only be used once. After confirmation, all sessions will be signed out.</p>';
        return (string) apply_filters('wp_cliniko_patient_email_change_message', $message, $user, $verificationUrl);
    }

    private static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        return function_exists('sanitize_email') ? (string) sanitize_email($email) : $email;
    }

    private static function hashValue(string $value): string
    {
        return hash_hmac('sha256', $value, self::secret());
    }

    private static function secret(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }
        return defined('AUTH_SALT') ? (string) constant('AUTH_SALT') : __FILE__;
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** @return array{ok:bool,status:string,message:string,http_status:int} */
    private static function result(bool $ok, string $status, string $message, int $httpStatus): array
    {
        return ['ok' => $ok, 'status' => $status, 'message' => $message, 'http_status' => $httpStatus];
    }
}
