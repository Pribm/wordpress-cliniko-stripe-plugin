<?php

namespace App\Service;

use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;
use App\Debug\Settings as DebugSettings;
use App\DTO\CreatePatientDTO;
use App\Model\Patient;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientLinkService
{
    public const OPTION_VERIFICATION_PAGE_URL = 'wp_cliniko_patient_verification_page_url';
    public const EMAIL_VERIFY_ACTION = 'wp_cliniko_verify_patient_email';
    public const STATUS_PENDING = 'pending';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REVOKED = 'revoked';
    public const PENDING_NEW_PATIENT = '__new_patient__';

    public const META_STATUS = '_cliniko_link_status';
    public const META_VERIFIED_AT = '_cliniko_link_verified_at';
    public const META_EMAIL_HASH = '_cliniko_link_email_hash';
    public const OPTION_SUPPRESS_NEXTEND_WELCOME_EMAIL = 'wp_cliniko_suppress_nextend_welcome_email';
    private const META_PENDING_PATIENT_ID = '_cliniko_pending_patient_id';
    private const META_TOKEN_HASH = '_cliniko_link_token_hash';
    private const META_EXPIRES_AT = '_cliniko_link_expires_at';
    private const META_ATTEMPTS = '_cliniko_link_attempts';
    private const META_LAST_SENT_AT = '_cliniko_link_last_sent_at';
    private const OPTION_ENABLED = 'wp_cliniko_patient_sync_enabled';
    private const OPTION_ROLES = 'wp_cliniko_patient_sync_roles';
    private const TOKEN_TTL = 1800;
    private const RESEND_INTERVAL = 60;
    private const MAX_ATTEMPTS = 5;

    /** @var array<int,bool> */
    private static array $trustedEmailUpdates = [];
    private static int $nextendRegistrationUserId = 0;
    private static bool $newUserNotificationRemoved = false;
    private static int $newUserNotificationPriority = 10;

    private ApiClientInterface $client;

    public function __construct(?ApiClientInterface $client = null)
    {
        $this->client = $client ?: Client::getInstance();
    }

    public static function registerHooks(): void
    {
        add_action('profile_update', [self::class, 'handleProfileUpdate'], 20, 2);
        add_action('set_user_role', [self::class, 'handleRoleChanged'], 20, 3);
        add_action('add_user_role', [self::class, 'handleRoleChanged'], 20, 2);
        add_action('remove_user_role', [self::class, 'handleRoleChanged'], 20, 2);
        add_action('nsl_register_new_user', [self::class, 'handleNextendPatientRegistered'], 20, 2);
        add_action('register_new_user', [self::class, 'suppressNextendPatientWelcomeEmail'], 1, 1);
        add_action('register_new_user', [self::class, 'restoreNewUserNotification'], PHP_INT_MAX, 1);
        // Nextend asks the authenticate filter before wp_authenticate_user.
        // Run before Ultimate Member rejects an account awaiting review so an
        // expired patient-verification challenge can be safely rotated.
        add_filter('authenticate', [self::class, 'handleSocialAuthentication'], 40, 3);
    }

    public function userHasConfiguredRole(int $userId): bool
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || PatientService::isPatientSyncExemptUser($user)) {
            return false;
        }
        $roles = get_option(self::OPTION_ROLES, []);
        return is_array($roles) && $roles !== [] && (bool) array_intersect($roles, $user->roles);
    }

    public static function suppressesNextendWelcomeEmail(): bool
    {
        return (string) get_option(self::OPTION_SUPPRESS_NEXTEND_WELCOME_EMAIL, 'yes') !== 'no';
    }

    /** @param mixed $provider */
    public static function handleNextendPatientRegistered(int $userId, $provider = null): void
    {
        if (!self::suppressesNextendWelcomeEmail()) {
            return;
        }

        $user = get_userdata($userId);
        $roles = get_option(self::OPTION_ROLES, []);
        $configuredPatient = $user instanceof \WP_User
            && !PatientService::isPatientSyncExemptUser($user)
            && is_array($roles)
            && $roles !== []
            && (bool) array_intersect($roles, $user->roles);
        $pendingReview = (string) get_user_meta(
            $userId,
            PatientService::META_ACCOUNT_STATUS,
            true
        ) === PatientService::ACCOUNT_PENDING_REVIEW;

        if ($configuredPatient && $pendingReview) {
            self::$nextendRegistrationUserId = $userId;
        }
    }

    /**
     * Nextend Free triggers WordPress's register_new_user notification after
     * social registration. Keep the administrator notification, but do not
     * send a patient a competing password-setup email while Cliniko ownership
     * verification is still pending.
     */
    public static function suppressNextendPatientWelcomeEmail(int $userId): void
    {
        if ($userId <= 0 || $userId !== self::$nextendRegistrationUserId) {
            return;
        }

        $notificationPriority = has_action('register_new_user', 'wp_send_new_user_notifications');
        if ($notificationPriority === false) {
            return;
        }

        remove_action('register_new_user', 'wp_send_new_user_notifications', (int) $notificationPriority);
        self::$newUserNotificationRemoved = true;
        self::$newUserNotificationPriority = (int) $notificationPriority;

        if (function_exists('wp_new_user_notification')) {
            wp_new_user_notification($userId, null, 'admin');
        }
    }

    public static function restoreNewUserNotification(int $userId): void
    {
        if ($userId !== self::$nextendRegistrationUserId) {
            return;
        }

        if (self::$newUserNotificationRemoved) {
            add_action(
                'register_new_user',
                'wp_send_new_user_notifications',
                self::$newUserNotificationPriority,
                1
            );
        }

        self::$nextendRegistrationUserId = 0;
        self::$newUserNotificationRemoved = false;
        self::$newUserNotificationPriority = 10;
    }

    public function isVerifiedUser(int $userId): bool
    {
        if (!$this->userHasConfiguredRole($userId)) {
            return false;
        }
        if ((string) get_user_meta($userId, self::META_STATUS, true) !== self::STATUS_VERIFIED) {
            return false;
        }

        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return false;
        }
        $storedEmailHash = (string) get_user_meta($userId, self::META_EMAIL_HASH, true);
        $patientId = $this->readEncryptedMeta($userId, PatientService::META_PATIENT_ID);
        return $patientId !== ''
            && $storedEmailHash !== ''
            && hash_equals($storedEmailHash, self::emailHash((string) $user->user_email));
    }

    /**
     * @return array{ok:bool,status:string,message:string,http_status:int}
     */
    public function beginVerification(int $userId, string $patientId, bool $forceResend = false): array
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !$this->userHasConfiguredRole($userId)) {
            return self::result(false, self::STATUS_FAILED, 'This account is not eligible for patient linking.', 403);
        }
        $email = strtolower(trim((string) $user->user_email));
        if ($patientId === '' || !is_email($email)) {
            return self::result(false, self::STATUS_FAILED, 'A valid patient and email address are required.', 400);
        }
        if ($this->isVerifiedUser($userId)) {
            return self::result(true, self::STATUS_VERIFIED, 'Patient email is already verified.', 200);
        }

        $lastSentAt = (int) get_user_meta($userId, self::META_LAST_SENT_AT, true);
        if ($forceResend && $lastSentAt > 0 && (time() - $lastSentAt) < self::RESEND_INTERVAL) {
            return self::result(false, self::STATUS_PENDING, 'Please wait before requesting another verification email.', 429);
        }
        $expiresAt = (int) get_user_meta($userId, self::META_EXPIRES_AT, true);
        $hasPendingToken = (string) get_user_meta($userId, self::META_TOKEN_HASH, true) !== '';
        $attempts = (int) get_user_meta($userId, self::META_ATTEMPTS, true);
        if (!$forceResend && $hasPendingToken && $expiresAt > time() && $attempts < self::MAX_ATTEMPTS) {
            return self::result(false, self::STATUS_PENDING, 'Email verification is pending.', 202);
        }

        try {
            $token = self::randomToken();
        } catch (\Throwable $exception) {
            return self::result(false, self::STATUS_FAILED, 'Could not create a verification token.', 500);
        }

        update_user_meta($userId, self::META_PENDING_PATIENT_ID, \wp_cliniko_secret_option_encrypt($patientId));
        update_user_meta($userId, self::META_TOKEN_HASH, self::tokenHash($token));
        update_user_meta($userId, self::META_EXPIRES_AT, time() + self::TOKEN_TTL);
        update_user_meta($userId, self::META_ATTEMPTS, 0);
        update_user_meta($userId, self::META_LAST_SENT_AT, time());
        update_user_meta($userId, self::META_EMAIL_HASH, self::emailHash($email));
        update_user_meta($userId, self::META_STATUS, self::STATUS_PENDING);
        PatientService::markAccountPendingReview($userId);

        $verificationPageUrl = self::verificationPageUrl();
        $verificationUrl = add_query_arg(array_merge(
            $verificationPageUrl !== '' ? ['cliniko_patient_verification' => 'confirm'] : ['action' => self::EMAIL_VERIFY_ACTION],
            [
                'user_id' => $userId,
                'token' => $token,
            ]
        ), $verificationPageUrl !== '' ? $verificationPageUrl : admin_url('admin-post.php'));
        $subject = (string) apply_filters(
            'wp_cliniko_patient_link_email_subject',
            PatientVerificationEmailTemplate::subject($user)
        );
        $message = PatientVerificationEmailTemplate::render($user, $verificationUrl);
        $sent = wp_mail($email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
        if (!$sent) {
            update_user_meta($userId, self::META_STATUS, self::STATUS_FAILED);
            delete_user_meta($userId, self::META_TOKEN_HASH);
            return self::result(false, self::STATUS_FAILED, 'The verification email could not be sent.', 500);
        }

        return self::result(false, self::STATUS_PENDING, 'Verification email sent.', 202);
    }

    public static function verificationPageUrl(): string
    {
        $url = trim((string) get_option(self::OPTION_VERIFICATION_PAGE_URL, ''));
        return $url !== '' ? wp_validate_redirect($url, '') : '';
    }

    /**
     * Replace a stale pending challenge without repeating the Cliniko lookup.
     * A successful call overwrites the stored token hash, invalidating every
     * previously issued link for this user.
     *
     * @return array{ok:bool,status:string,message:string,http_status:int}|null
     */
    public function refreshStaleVerification(int $userId): ?array
    {
        if (!(bool) get_option(self::OPTION_ENABLED, false) || !$this->userHasConfiguredRole($userId)) {
            return null;
        }

        $accountStatus = (string) get_user_meta($userId, PatientService::META_ACCOUNT_STATUS, true);
        if ($accountStatus !== PatientService::ACCOUNT_PENDING_REVIEW) {
            return null;
        }

        $status = (string) get_user_meta($userId, self::META_STATUS, true);
        if (!in_array($status, [self::STATUS_PENDING, self::STATUS_FAILED], true)) {
            return null;
        }

        $pendingPatientId = $this->readEncryptedMeta($userId, self::META_PENDING_PATIENT_ID);
        if ($pendingPatientId === '') {
            return null;
        }

        $tokenHash = (string) get_user_meta($userId, self::META_TOKEN_HASH, true);
        $expiresAt = (int) get_user_meta($userId, self::META_EXPIRES_AT, true);
        $attempts = (int) get_user_meta($userId, self::META_ATTEMPTS, true);
        $challengeIsStale = $status === self::STATUS_FAILED
            || $tokenHash === ''
            || $expiresAt <= time()
            || $attempts >= self::MAX_ATTEMPTS;

        if (!$challengeIsStale) {
            return null;
        }

        return $this->beginVerification($userId, $pendingPatientId, true);
    }

    /**
     * Nextend passes an already resolved WP_User with an empty password to
     * authenticate(). Standard WordPress password authentication does not.
     *
     * @param mixed $user
     * @param mixed $username
     * @param mixed $password
     * @return mixed
     */
    public static function handleSocialAuthentication($user, $username = '', $password = '')
    {
        if (
            !$user instanceof \WP_User
            || (string) $password !== ''
            || !class_exists('NextendSocialLogin', false)
        ) {
            return $user;
        }

        $userId = (int) $user->ID;
        $ultimateMemberStatus = UltimateMemberAccountService::status($userId);
        if (in_array($ultimateMemberStatus, [
            UltimateMemberAccountService::STATUS_REJECTED,
            UltimateMemberAccountService::STATUS_INACTIVE,
        ], true)) {
            return $user;
        }

        $result = (new self())->refreshStaleVerification($userId);
        if ($result === null) {
            return $user;
        }

        if ($result['http_status'] === 202) {
            return new \WP_Error(
                'cliniko_patient_verification_resent',
                'Your previous patient verification link expired. We sent a new link. Use the newest verification email; older links will not work.',
                []
            );
        }

        if ($result['http_status'] === 429) {
            return new \WP_Error('cliniko_patient_verification_rate_limited', $result['message'], []);
        }

        return new \WP_Error(
            'cliniko_patient_verification_resend_failed',
            'Your patient verification link is no longer valid, but a new email could not be sent. Please try again shortly.',
            []
        );
    }

    /**
     * @return array{ok:bool,status:string,message:string,http_status:int}
     */
    public function verify(int $userId, string $token): array
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !$this->userHasConfiguredRole($userId)) {
            return self::result(false, self::STATUS_FAILED, 'Verification link is invalid.', 400);
        }
        if ((string) get_user_meta($userId, self::META_STATUS, true) !== self::STATUS_PENDING) {
            return self::result(false, self::STATUS_FAILED, 'Verification link is invalid or already used.', 400);
        }
        if ((int) get_user_meta($userId, self::META_EXPIRES_AT, true) < time()) {
            return self::result(false, self::STATUS_FAILED, 'Verification link has expired.', 410);
        }

        $attempts = (int) get_user_meta($userId, self::META_ATTEMPTS, true);
        if ($attempts >= self::MAX_ATTEMPTS) {
            return self::result(false, self::STATUS_FAILED, 'Verification attempts exceeded.', 429);
        }
        update_user_meta($userId, self::META_ATTEMPTS, $attempts + 1);

        $storedTokenHash = (string) get_user_meta($userId, self::META_TOKEN_HASH, true);
        if ($token === '' || $storedTokenHash === '' || !hash_equals($storedTokenHash, self::tokenHash($token))) {
            return self::result(false, self::STATUS_FAILED, 'Verification link is invalid.', 400);
        }

        $email = strtolower(trim((string) $user->user_email));
        $storedEmailHash = (string) get_user_meta($userId, self::META_EMAIL_HASH, true);
        if ($storedEmailHash === '' || !hash_equals($storedEmailHash, self::emailHash($email))) {
            $this->revoke($userId);
            return self::result(false, self::STATUS_REVOKED, 'The account email changed. Request a new link.', 409);
        }

        try {
            $patientId = $this->readEncryptedMeta($userId, self::META_PENDING_PATIENT_ID);
            if ($patientId === self::PENDING_NEW_PATIENT) {
                $patient = $this->resolveOrCreatePatient($userId, $email);
                $patientId = $patient ? (string) $patient->getId() : '';
            } else {
                $patient = $patientId !== '' ? Patient::find($patientId, $this->client) : null;
            }
        } catch (\Throwable $exception) {
            error_log('Cliniko patient email verification failed: ' . $exception->getMessage());
            return self::result(false, self::STATUS_FAILED, 'Cliniko could not be reached. Please try again.', 503);
        }
        $patientEmail = $patient ? strtolower(trim((string) $patient->getEmail())) : '';
        if ($patient === null || $patientId === '' || $patientEmail === '' || !hash_equals($patientEmail, $email)) {
            $this->revoke($userId);
            return self::result(false, self::STATUS_REVOKED, 'The Cliniko patient email does not match this account.', 409);
        }

        update_user_meta($userId, PatientService::META_PATIENT_ID, \wp_cliniko_secret_option_encrypt($patientId));
        update_user_meta($userId, self::META_STATUS, self::STATUS_VERIFIED);
        update_user_meta($userId, self::META_VERIFIED_AT, current_time('mysql', true));
        update_user_meta($userId, self::META_EMAIL_HASH, self::emailHash($email));
        update_user_meta($userId, PatientService::META_SYNC_STATUS, PatientService::STATUS_SYNCED);
        update_user_meta($userId, PatientService::META_SYNC_ERROR, '');
        update_user_meta($userId, PatientService::META_SYNCED_AT, current_time('mysql', true));
        $activated = PatientService::markAccountActive($userId);
        self::clearChallenge($userId);

        if (!$activated) {
            return self::result(false, self::STATUS_PENDING, 'Your account is still awaiting approval.', 202);
        }

        return self::result(true, self::STATUS_VERIFIED, 'Patient email verified.', 200);
    }

    /** @return array{verified:bool,status:string,verified_at:string,account_status:string} */
    public function status(int $userId): array
    {
        return [
            'verified' => $this->isVerifiedUser($userId),
            'status' => sanitize_key((string) get_user_meta($userId, self::META_STATUS, true)),
            'verified_at' => (string) get_user_meta($userId, self::META_VERIFIED_AT, true),
            'account_status' => sanitize_key(
                (string) get_user_meta($userId, PatientService::META_ACCOUNT_STATUS, true)
            ),
        ];
    }

    public function revoke(int $userId): void
    {
        self::revokeUser($userId);
    }

    /**
     * Revoke a link only after PatientService has confirmed two live 404s for
     * the linked Cliniko patient. Keep the patient ID for recovery/auditing.
     */
    public function revokeForMissingPatient(int $userId): void
    {
        update_user_meta($userId, self::META_STATUS, self::STATUS_REVOKED);
        update_user_meta($userId, PatientService::META_SYNC_STATUS, PatientService::STATUS_FAILED);
        update_user_meta($userId, PatientService::META_SYNC_ERROR, 'The linked Cliniko patient no longer exists.');
        delete_user_meta($userId, self::META_VERIFIED_AT);
        self::clearChallenge($userId);
        if (function_exists('cliniko_dashboard_cache_invalidate')) {
            cliniko_dashboard_cache_invalidate();
        }
        PatientService::markAccountInactiveForMissingPatient($userId);
    }

    private static function revokeUser(int $userId): void
    {
        update_user_meta($userId, self::META_STATUS, self::STATUS_REVOKED);
        update_user_meta($userId, PatientService::META_SYNC_STATUS, PatientService::STATUS_PENDING);
        update_user_meta($userId, PatientService::META_SYNC_ERROR, 'Patient email verification is required.');
        PatientService::markAccountPendingReview($userId);
        delete_user_meta($userId, self::META_VERIFIED_AT);
        self::clearChallenge($userId);
        if (function_exists('cliniko_dashboard_cache_invalidate')) {
            cliniko_dashboard_cache_invalidate();
        }
    }

    /** @param mixed $oldUserData */
    public static function handleProfileUpdate(int $userId, $oldUserData): void
    {
        if (!DebugSettings::authBehaviourEnabled(DebugSettings::AUTH_BEHAVIOUR_PROFILE_EMAIL_REVOKE)) {
            return;
        }

        if (!empty(self::$trustedEmailUpdates[$userId])) {
            return;
        }
        $oldEmail = $oldUserData instanceof \WP_User ? strtolower(trim((string) $oldUserData->user_email)) : '';
        $user = get_userdata($userId);
        $newEmail = $user instanceof \WP_User ? strtolower(trim((string) $user->user_email)) : '';
        if ($oldEmail !== '' && $newEmail !== '' && !hash_equals($oldEmail, $newEmail)) {
            self::revokeUser($userId);
        }
    }

    /** @return int|mixed */
    public static function updateVerifiedWordPressEmail(int $userId, string $email)
    {
        self::$trustedEmailUpdates[$userId] = true;
        try {
            return wp_update_user([
                'ID' => $userId,
                'user_email' => strtolower(trim($email)),
            ]);
        } finally {
            unset(self::$trustedEmailUpdates[$userId]);
        }
    }

    public function completeVerifiedEmailChange(int $userId, string $email): void
    {
        update_user_meta($userId, self::META_STATUS, self::STATUS_VERIFIED);
        update_user_meta($userId, self::META_EMAIL_HASH, self::emailHash($email));
        update_user_meta($userId, self::META_VERIFIED_AT, current_time('mysql', true));
        update_user_meta($userId, PatientService::META_SYNC_STATUS, PatientService::STATUS_SYNCED);
        update_user_meta($userId, PatientService::META_SYNC_ERROR, '');
        update_user_meta($userId, PatientService::META_SYNCED_AT, current_time('mysql', true));
        PatientService::markAccountActive($userId);
    }

    /** @param mixed ...$unused */
    public static function handleRoleChanged(int $userId, ...$unused): void
    {
        if (!DebugSettings::authBehaviourEnabled(DebugSettings::AUTH_BEHAVIOUR_ROLE_REVOKE)) {
            return;
        }

        $user = get_userdata($userId);
        if (PatientService::isPatientSyncExemptUser($user)) {
            return;
        }

        $roles = get_option(self::OPTION_ROLES, []);
        $hasConfiguredRole = $user instanceof \WP_User
            && is_array($roles)
            && $roles !== []
            && (bool) array_intersect($roles, $user->roles);
        if (!$hasConfiguredRole) {
            self::revokeUser($userId);
        }
    }

    private static function clearChallenge(int $userId): void
    {
        delete_user_meta($userId, self::META_PENDING_PATIENT_ID);
        delete_user_meta($userId, self::META_TOKEN_HASH);
        delete_user_meta($userId, self::META_EXPIRES_AT);
        delete_user_meta($userId, self::META_ATTEMPTS);
    }

    private function resolveOrCreatePatient(int $userId, string $email): ?Patient
    {
        $matches = Patient::queryManyByQueryString(
            '?q[]=' . rawurlencode('email:=' . $email),
            $this->client,
            true
        );
        if (count($matches) > 1) {
            return null;
        }
        if (isset($matches[0])) {
            return $matches[0];
        }

        $dto = new CreatePatientDTO();
        $dto->firstName = sanitize_text_field((string) get_user_meta($userId, 'first_name', true));
        $dto->lastName = sanitize_text_field((string) get_user_meta($userId, 'last_name', true));
        $dto->email = $email;
        $dto->acceptedPrivacyPolicy = true;
        return Patient::create($dto, $this->client);
    }

    private function readEncryptedMeta(int $userId, string $key): string
    {
        $stored = (string) get_user_meta($userId, $key, true);
        return $stored !== '' ? trim((string) \wp_cliniko_secret_option_decrypt($stored)) : '';
    }

    private static function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, self::secret());
    }

    private static function emailHash(string $email): string
    {
        return hash_hmac('sha256', strtolower(trim($email)), self::secret());
    }

    private static function secret(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }
        if (defined('AUTH_SALT')) {
            return (string) constant('AUTH_SALT');
        }
        return __FILE__;
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
