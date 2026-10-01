<?php

namespace App\Service;

use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;
use App\Debug\LogSanitizer;
use App\Debug\Runtime;
use App\Debug\Settings as DebugSettings;
use App\Exception\ApiException;
use App\DTO\PatientUpdateDTO;
use App\Model\Patient;

if (!defined('ABSPATH')) {
    exit;
}

class PatientService
{
    public const META_PATIENT_ID = '_cliniko_patient_id';
    public const META_SYNC_STATUS = '_cliniko_patient_sync_status';
    public const META_SYNCED_AT = '_cliniko_patient_synced_at';
    public const META_SYNC_ERROR = '_cliniko_patient_sync_error';
    public const META_ACCOUNT_STATUS = '_cliniko_patient_account_status';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SYNCED = 'synced';
    public const STATUS_FAILED = 'failed';
    public const ACCOUNT_PENDING_REVIEW = 'pending_review';
    public const ACCOUNT_ACTIVE = 'active';
    public const ACCOUNT_INACTIVE = 'inactive';
    public const META_SUSPENDED_REASON = '_cliniko_patient_suspended_reason';
    public const META_SUSPENDED_AT = '_cliniko_patient_suspended_at';
    public const SUSPENSION_PATIENT_NOT_FOUND = 'patient_not_found';

    private const OPTION_ENABLED = 'wp_cliniko_patient_sync_enabled';
    private const OPTION_ROLES = 'wp_cliniko_patient_sync_roles';
    private const OPTION_ALLOW_FAILURE = 'wp_cliniko_patient_allow_user_without_sync';
    private const OPTION_PRIVACY_META_KEY = 'wp_cliniko_patient_privacy_meta_key';
    private const OPTION_STRICT_ROLE = 'wp_cliniko_patient_strict_role';

    private ApiClientInterface $client;
    private ApiClientInterface $authenticatedPatientClient;
    private ApiClientInterface $freshPatientClient;

    public function __construct(?ApiClientInterface $client = null, ?ApiClientInterface $freshClient = null)
    {
        if ($client !== null) {
            $this->client = $client;
            $this->authenticatedPatientClient = $client;
            $this->freshPatientClient = $freshClient ?: $client;
            return;
        }

        $this->client = Client::getInstance();
        $this->authenticatedPatientClient = function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 7200)
            : $this->client;
        $this->freshPatientClient = $freshClient ?: (function_exists('cliniko_client')
            ? cliniko_client(false)
            : $this->client);
    }

    public function getPatient(string $patientId): ?array
    {
        $patient = Patient::find($patientId, $this->client);
        if (!$patient) {
            return null;
        }

        $result = $this->toArray($patient);
        $customFields = $this->getRawCustomFields($patientId);
        if ($customFields !== null) {
            $result['custom_fields'] = $customFields;
            $result['custom_field_values'] = $this->flattenCustomFieldValues($customFields);
        }

        return $result;
    }

    public static function registerHooks(): void
    {
        add_action('user_register', [self::class, 'handleUserRegistered'], 20, 1);
        add_action('added_user_meta', [self::class, 'handleIdentityMetaChanged'], 20, 4);
        add_action('updated_user_meta', [self::class, 'handleIdentityMetaChanged'], 20, 4);
        add_action('set_user_role', [self::class, 'handleConfiguredRoleAssigned'], 30, 3);
        add_action('add_user_role', [self::class, 'handleConfiguredRoleAssigned'], 30, 2);
        add_action('um_registration_complete', [self::class, 'handleUltimateMemberRegistration'], 20, 3);
        add_filter('wp_authenticate_user', [self::class, 'handleAuthentication'], 20, 2);
        add_filter('determine_current_user', [self::class, 'denyPendingCurrentUser'], 99);
        add_filter('manage_users_columns', [self::class, 'addAccountStatusColumn']);
        add_filter('manage_users_custom_column', [self::class, 'renderAccountStatusColumn'], 10, 3);
    }

    /**
     * Administrators and manager accounts must remain independent from the
     * Cliniko patient-account lifecycle. They manage WordPress and do not
     * need a patient link to use the dashboard or Elementor.
     */
    public static function isPatientSyncExemptUser($userOrId): bool
    {
        $user = $userOrId instanceof \WP_User
            ? $userOrId
            : (function_exists('get_userdata') ? get_userdata((int) $userOrId) : null);

        if (!$user instanceof \WP_User) {
            return false;
        }

        if (in_array('administrator', (array) $user->roles, true)) {
            return true;
        }

        return (bool) $user->has_cap('manage_options');
    }

    public static function handleUserRegistered(int $userId): void
    {
        $service = new self();
        if (!$service->shouldSyncUser($userId)) {
            return;
        }

        self::markAccountPendingReview($userId);

        // Cliniko patient creation is the privacy-consent boundary for this
        // integration. Keep the configured WordPress meta flag in sync with
        // the successful registration flow.
        $service->ensurePrivacyAcceptanceFlag($userId);

        // Some registration plugins save first_name/last_name after the
        // user_register action. Wait for those fields before calling Cliniko.
        if (!$service->hasRequiredRegistrationData($userId)) {
            update_user_meta($userId, self::META_SYNC_STATUS, self::STATUS_PENDING);
            return;
        }

        $result = $service->syncUser($userId);
        // The WordPress row remains provisional until the verification link
        // activates it. API and email failures must never activate the account.
    }

    /**
     * Registration plugins commonly persist profile fields after user_register.
     * Retry synchronously as soon as the required identity fields are present.
     *
     * @param mixed $metaId
     * @param mixed $userId
     * @param mixed $metaKey
     * @param mixed $metaValue
     */
    public static function handleIdentityMetaChanged($metaId, $userId, $metaKey, $metaValue): void
    {
        if (function_exists('doing_action') && doing_action('um_user_register')) {
            return;
        }

        $service = new self();
        if (!$service->isRegistrationMetaKey((string) $metaKey)) {
            return;
        }

        $userId = (int) $userId;
        if ($userId <= 0 || !$service->shouldSyncUser($userId) || !$service->hasRequiredRegistrationData($userId)) {
            return;
        }

        if (!(new PatientLinkService())->isVerifiedUser($userId)) {
            $service->syncUser($userId);
        }
    }

    /**
     * Ultimate Member saves profile metadata after WordPress user_register.
     * Start verification only after UM has finished persisting that data.
     *
     * @param mixed $submitted
     * @param mixed $formData
     */
    public static function handleUltimateMemberRegistration(int $userId, $submitted = [], $formData = []): void
    {
        $service = new self();
        if (!$service->shouldSyncUser($userId)) {
            return;
        }

        self::markAccountPendingReview($userId);
        $service->ensurePrivacyAcceptanceFlag($userId);
        if ($service->hasRequiredRegistrationData($userId)) {
            $service->syncUser($userId);
        }
    }

    /** @param mixed ...$unused */
    public static function handleConfiguredRoleAssigned(int $userId, ...$unused): void
    {
        if (!self::isSyncEnabled()) {
            return;
        }

        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !self::roleIsConfigured($user)) {
            return;
        }

        if (
            (string) get_user_meta($userId, PatientLinkService::META_STATUS, true)
            !== PatientLinkService::STATUS_VERIFIED
        ) {
            self::markAccountPendingReview($userId);
        }
    }

    /**
     * Existing users are synchronised before they receive a login session.
     * In permissive mode, a Cliniko outage does not block WordPress login.
     *
     * @param mixed $user
     * @return mixed
     */
    public static function handleAuthentication($user, string $password)
    {
        if (!DebugSettings::authBehaviourEnabled(DebugSettings::AUTH_BEHAVIOUR_LOGIN_AUTHENTICATION)) {
            return $user;
        }

        if ($user instanceof \WP_User && self::isPatientSyncExemptUser($user)) {
            // Repair administrator/manager accounts that were previously put
            // into the patient-review state by an older configuration.
            self::markAccountActive((int) $user->ID);
            return $user;
        }

        if ($user instanceof \WP_User && self::isSyncEnabled() && self::roleIsConfigured($user)) {
            $service = new self();
            $result = $service->syncUser((int) $user->ID);
            $accountStatus = (string) get_user_meta(
                (int) $user->ID,
                self::META_ACCOUNT_STATUS,
                true
            );
            if ($accountStatus === self::ACCOUNT_INACTIVE) {
                return new \WP_Error(
                    'cliniko_patient_not_found',
                    'Your Cliniko patient account is no longer available.'
                );
            }
            if ($accountStatus === self::ACCOUNT_PENDING_REVIEW) {
                $awaitingVerification = !(new PatientLinkService())->isVerifiedUser((int) $user->ID);
                return new \WP_Error(
                    'cliniko_patient_pending_review',
                    $awaitingVerification
                        ? 'Check your email and verify your patient account before logging in.'
                        : 'Your account is still awaiting approval.'
                );
            }
            if (!$result['ok'] && !self::allowUserWithoutSync((int) $user->ID)) {
                $message = $result['status'] === PatientLinkService::STATUS_PENDING
                    ? 'Check your email and verify your patient account before logging in.'
                    : 'We could not connect your account to Cliniko. Please try again later.';
                return new \WP_Error(
                    'cliniko_patient_sync_failed',
                    $message
                );
            }
        }

        return $user;
    }

    /** @param mixed $userId @return mixed */
    public static function denyPendingCurrentUser($userId)
    {
        if (!DebugSettings::authBehaviourEnabled(DebugSettings::AUTH_BEHAVIOUR_PENDING_CURRENT_USER)) {
            return $userId;
        }

        $resolvedUserId = (int) $userId;
        if ($resolvedUserId <= 0) {
            return $userId;
        }

        $user = get_userdata($resolvedUserId);
        if (self::isPatientSyncExemptUser($user)) {
            return $userId;
        }

        if (
            $user instanceof \WP_User
            && self::isSyncEnabled()
            && self::roleIsConfigured($user)
            && in_array(
                (string) get_user_meta($resolvedUserId, self::META_ACCOUNT_STATUS, true),
                [self::ACCOUNT_PENDING_REVIEW, self::ACCOUNT_INACTIVE],
                true
            )
        ) {
            return 0;
        }

        return $userId;
    }

    /**
     * Ensure the current WordPress user has a Cliniko mapping and return the
     * current Cliniko profile.
     *
     * @return array<string,mixed>|null
     */
    public function getPatientForCurrentUser(): ?array
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        return $this->getPatientForUser($userId);
    }

    public function getPatientModelForCurrentUser(): ?Patient
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        return $this->getPatientModelForUser($userId);
    }

    public function getPatientModelForUser(int $userId, bool $forceFresh = false): ?Patient
    {
        $resolved = $this->resolvePatientForUser($userId, $forceFresh);
        return $resolved['patient'] ?? null;
    }

    /**
     * Resolve a verified WordPress user against the linked Cliniko patient.
     * A missing patient is accepted only after two live 404 responses.
     *
     * @return array{patient:Patient,data:array<string,mixed>}|null
     */
    public function resolvePatientForUser(int $userId, bool $forceFresh = false): ?array
    {
        if ($userId <= 0 || !(new PatientLinkService($this->client))->isVerifiedUser($userId)) {
            return null;
        }

        $patientId = $this->readPatientId($userId);
        if ($patientId === '') {
            return null;
        }

        $client = $forceFresh ? $this->freshPatientClient : $this->authenticatedPatientClient;
        try {
            $patient = Patient::find($patientId, $client, true);
        } catch (ApiException $exception) {
            if ($this->responseStatus($exception) !== 404) {
                throw $exception;
            }

            // A cached client never stores failures, but the confirmation is
            // deliberately uncached so a stale success can never suspend an
            // account and a transient 404 cannot suspend it on its own.
            try {
                $patient = Patient::find($patientId, $this->freshPatientClient, true);
                $client = $this->freshPatientClient;
            } catch (ApiException $confirmation) {
                if ($this->responseStatus($confirmation) === 404) {
                    (new PatientLinkService($this->freshPatientClient))->revokeForMissingPatient($userId);
                    return null;
                }

                throw $confirmation;
            }
        }

        if (!$patient instanceof Patient || trim((string) $patient->getId()) === '') {
            throw new ApiException(
                'Cliniko returned an empty patient response.',
                ['status_code' => 200, 'response_data' => null]
            );
        }

        $data = $this->toArray($patient);
        $customFields = $this->getRawCustomFieldsForClient((string) $patient->getId(), $client);
        if ($customFields !== null) {
            $data['custom_fields'] = $customFields;
            $data['custom_field_values'] = $this->flattenCustomFieldValues($customFields);
        }

        return ['patient' => $patient, 'data' => $data];
    }

    /** @return array<string,mixed>|null */
    public function getPatientForUser(int $userId, bool $forceFresh = false): ?array
    {
        $resolved = $this->resolvePatientForUser($userId, $forceFresh);
        return $resolved['data'] ?? null;
    }

    private function responseStatus(ApiException $exception): int
    {
        return (int) ($exception->getContext()['status_code'] ?? 0);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public function updatePatientForCurrentUser(array $data): ?array
    {
        $userId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($userId <= 0) {
            return null;
        }
        if (!(new PatientLinkService($this->client))->isVerifiedUser($userId)) {
            return null;
        }

        $patientId = $this->readPatientId($userId);
        return $patientId !== '' ? $this->updatePatient($patientId, $data) : null;
    }

    /**
     * @return array{ok:bool,patient_id:string,status:string,error:string}
     */
    public function syncUser(int $userId, bool $forceResend = false): array
    {
        $links = new PatientLinkService($this->client);
        $existing = $this->readPatientId($userId);
        if ($existing !== '' && $links->isVerifiedUser($userId)) {
            try {
                $patient = $this->getPatientModelForUser($userId, true);
            } catch (\Throwable $e) {
                $message = $this->diagnosticMessage($e);
                $this->logSyncFailure($userId, $e, $message);
                return $this->failed($userId, $message);
            }

            if ($patient === null) {
                return $this->failed($userId, 'The linked Cliniko patient no longer exists.');
            }

            $this->setSyncMeta($userId, self::STATUS_SYNCED, '', false);
            if (!self::markAccountActive($userId)) {
                return [
                    'ok' => false,
                    'patient_id' => $existing,
                    'status' => self::STATUS_PENDING,
                    'error' => 'Your account is still awaiting approval.',
                ];
            }
            return ['ok' => true, 'patient_id' => $existing, 'status' => self::STATUS_SYNCED, 'error' => ''];
        }

        $user = get_userdata($userId);
        if (!$user instanceof \WP_User) {
            return $this->failed($userId, 'WordPress user not found.');
        }

        $email = strtolower(trim((string) $user->user_email));
        if ($email === '' || !is_email($email)) {
            return $this->failed($userId, 'A valid email address is required.');
        }

        $this->ensurePrivacyAcceptanceFlag($userId);
        if (!$this->hasRequiredRegistrationData($userId)) {
            if (!$this->hasRequiredIdentity($userId)) {
                return $this->failed($userId, 'First name and last name are required.');
            }

            return $this->failed($userId, 'Privacy policy acceptance is required.');
        }

        try {
            if ($existing !== '') {
                $existingPatient = null;
                try {
                    $existingPatient = Patient::find($existing, $this->freshPatientClient, true);
                } catch (ApiException $exception) {
                    if ($this->responseStatus($exception) !== 404) {
                        throw $exception;
                    }
                }

                $existingEmail = $existingPatient ? strtolower(trim((string) $existingPatient->getEmail())) : '';
                if ($existingEmail !== '' && hash_equals($existingEmail, $email)) {
                    return $this->beginEmailVerification($links, $userId, $existing, $forceResend);
                }
            }

            $matches = Patient::queryManyByQueryString(
                '?q[]=' . rawurlencode('email:=' . $email),
                $this->freshPatientClient,
                true
            );

            if (count($matches) > 1) {
                return $this->failed($userId, 'Multiple Cliniko patients match this email address.');
            }

            $patient = $matches[0] ?? null;
            if (!$patient) {
                if (self::isInactiveForMissingPatient($userId)) {
                    return $this->failed($userId, 'The linked Cliniko patient no longer exists.');
                }
                return $this->beginEmailVerification(
                    $links,
                    $userId,
                    PatientLinkService::PENDING_NEW_PATIENT,
                    $forceResend
                );
            }

            $patientId = (string) $patient->getId();
            if ($patientId === '') {
                return $this->failed($userId, 'Cliniko did not return a patient ID.');
            }

            return $this->beginEmailVerification($links, $userId, $patientId, $forceResend);
        } catch (\Throwable $e) {
            $message = $this->diagnosticMessage($e);
            $this->logSyncFailure($userId, $e, $message);
            return $this->failed($userId, $message);
        }
    }

    /** @return array{ok:bool,patient_id:string,status:string,error:string} */
    private function beginEmailVerification(
        PatientLinkService $links,
        int $userId,
        string $patientId,
        bool $forceResend
    ): array {
        $result = $links->beginVerification($userId, $patientId, $forceResend);
        if ($result['status'] === PatientLinkService::STATUS_VERIFIED) {
            $this->setSyncMeta($userId, self::STATUS_SYNCED, '', true);
            return ['ok' => true, 'patient_id' => $patientId, 'status' => self::STATUS_SYNCED, 'error' => ''];
        }
        if ($result['status'] === PatientLinkService::STATUS_PENDING) {
            self::markAccountPendingReview($userId);
            $this->setSyncMeta($userId, self::STATUS_PENDING, '', false);
            return ['ok' => false, 'patient_id' => '', 'status' => PatientLinkService::STATUS_PENDING, 'error' => $result['message']];
        }

        return $this->failed($userId, $result['message']);
    }

    /**
     * Update only fields that are safe for a patient to manage themselves.
     *
     * @param array<string,mixed> $data
     */
    public function updatePatient(string $patientId, array $data): ?array
    {
        // Email is an account identity field. It is handled exclusively by
        // PatientEmailChangeService after ownership of the new address has
        // been verified, never by the generic Cliniko patient update.
        $allowedFields = array_values(array_filter(
            array_keys(PatientFieldRegistry::editable()),
            static fn(string $field): bool => $field !== 'email'
        ));

        $payload = [];
        foreach ($allowedFields as $field) {
            $definition = PatientFieldRegistry::available()[$field] ?? null;
            $apiField = is_array($definition) ? (string) ($definition['api_field'] ?? $field) : $field;
            if (array_key_exists($field, $data) && is_scalar($data[$field])) {
                $value = trim((string) $data[$field]);
                if ($field === 'phone') {
                    // PatientDTO intentionally remains unchanged. Cliniko's
                    // update contract requires phone numbers as an array,
                    // while the account form exposes the primary phone as a
                    // simple value.
                    $payload['patient_phone_numbers'] = $this->buildPhoneNumbersPayload($patientId, $value);
                    continue;
                }

                $payload[$apiField] = $value;
            }
        }

        $customFields = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
        if ($customFields !== []) {
            $definitions = PatientCustomFieldService::getPatientFieldDefinitions();
            $currentCustomFields = $this->getRawCustomFields($patientId) ?? [];
            $workingPatient = ['custom_fields' => $currentCustomFields];

            foreach ($customFields as $key => $value) {
                $key = sanitize_key((string) $key);
                if (!isset($definitions[$key])) {
                    continue;
                }

                $workingPatient['custom_fields'][$key] = is_scalar($value) ? (string) $value : $value;
            }

            if ($definitions !== []) {
                $customPayload = PatientCustomFieldService::buildCustomFields($workingPatient, array_values($definitions));
                if ($customPayload !== null) {
                    $payload['custom_fields'] = $customPayload;
                }
            }
        }

        if ($payload === []) {
            return $this->getPatient($patientId);
        }

        // var_dump(json_encode($payload));
        // die();

        // Keep module updates on the same Cliniko request contract as creates.
        // Dashboard profile writes use the encrypted patient-scoped client so
        // its successful mutation invalidates the related cached reads.
        $patient = Patient::update($patientId, (new PatientUpdateDTO($payload))->toArray(), $this->authenticatedPatientClient);
        
        return $patient ? $this->toArray($patient) : $this->getPatient($patientId);
    }

    /** @return array<int,array{number:string,phone_type:string}> */
    private function buildPhoneNumbersPayload(string $patientId, string $phone): array
    {
        if ($phone === '') {
            return [];
        }

        $phoneType = 'Mobile';
        $response = $this->client->get('patients/' . rawurlencode($patientId));
        if ($response->isSuccessful() && is_array($response->data)) {
            $existingNumbers = $response->data['patient_phone_numbers'] ?? [];
            if (is_array($existingNumbers) && is_array($existingNumbers[0] ?? null)) {
                $existingType = trim((string) ($existingNumbers[0]['phone_type'] ?? ''));
                if (in_array($existingType, ['Fax', 'Home', 'Mobile', 'Other', 'Work'], true)) {
                    $phoneType = $existingType;
                }
            }
        }

        return [[
            'number' => $phone,
            'phone_type' => $phoneType,
        ]];
    }

    /**
     * @return array<string,mixed>
     */
    private function toArray(Patient $patient): array
    {
        $dto = $patient->getDTO();

        return [
            'id' => $patient->getId(),
            'first_name' => $patient->getFirstName(),
            'last_name' => $patient->getLastName(),
            'preferred_first_name' => $dto->preferredFirstName ?? null,
            'email' => $patient->getEmail(),
            'date_of_birth' => $patient->getDateOfBirth(),
            'medicare' => $patient->getMedicare(),
            'medicare_reference_number' => $patient->getMedicareReferenceNumber(),
            'gender' => $dto->gender ?? null,
            'phone' => $patient->getPhone(),
            'address_1' => $patient->getAddress1(),
            'address_2' => $patient->getAddress2(),
            'city' => $patient->getCity(),
            'state' => $patient->getState(),
            'post_code' => $patient->getPostCode(),
            'country' => $patient->getCountry(),
            'occupation' => $dto->occupation ?? null,
            'notes' => $dto->notes ?? null,
        ];
    }

    private function readPatientId(int $userId): string
    {
        $stored = (string) get_user_meta($userId, self::META_PATIENT_ID, true);
        if ($stored === '') {
            return '';
        }

        return function_exists('wp_cliniko_secret_option_decrypt')
            ? trim((string) wp_cliniko_secret_option_decrypt($stored))
            : trim($stored);
    }

    /** @return array<string,mixed>|null */
    private function getRawCustomFields(string $patientId): ?array
    {
        return $this->getRawCustomFieldsForClient($patientId, $this->client);
    }

    /** @return array<string,mixed>|null */
    private function getRawCustomFieldsForClient(string $patientId, ApiClientInterface $client): ?array
    {
        $response = $client->get('patients/' . rawurlencode($patientId));
        if (!$response->isSuccessful() || !is_array($response->data)) {
            return null;
        }

        return is_array($response->data['custom_fields'] ?? null)
            ? $response->data['custom_fields']
            : null;
    }

    /** @param array<string,mixed> $customFields @return array<string,mixed> */
    private function flattenCustomFieldValues(array $customFields): array
    {
        $values = [];
        foreach (($customFields['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }

            foreach (($section['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }

                $token = sanitize_key((string) ($field['token'] ?? ''));
                if ($token === '') {
                    continue;
                }

                $values['custom_' . $token] = $field['value'] ?? '';
            }
        }

        return $values;
    }

    private function shouldSyncUser(int $userId): bool
    {
        if (!self::isSyncEnabled()) {
            return false;
        }

        if (self::isPatientSyncExemptUser($userId)) {
            return false;
        }

        $user = get_userdata($userId);
        return $user instanceof \WP_User && self::roleIsConfigured($user);
    }

    private function hasRequiredRegistrationData(int $userId): bool
    {
        return $this->hasRequiredIdentity($userId);
    }

    private function ensurePrivacyAcceptanceFlag(int $userId): void
    {
        $metaKey = sanitize_key((string) get_option(self::OPTION_PRIVACY_META_KEY, 'accepted_privacy_policy'));
        if ($metaKey !== '' && get_user_meta($userId, $metaKey, true) !== '1') {
            update_user_meta($userId, $metaKey, '1');
        }
    }

    private function hasRequiredIdentity(int $userId): bool
    {
        return trim((string) get_user_meta($userId, 'first_name', true)) !== ''
            && trim((string) get_user_meta($userId, 'last_name', true)) !== '';
    }

    private function hasAcceptedPrivacyPolicy(int $userId): bool
    {
        $metaKey = sanitize_key((string) get_option(self::OPTION_PRIVACY_META_KEY, 'accepted_privacy_policy'));
        if ($metaKey === '') {
            return false;
        }

        $value = get_user_meta($userId, $metaKey, true);
        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on', 'accepted'], true);
    }

    private function isRegistrationMetaKey(string $metaKey): bool
    {
        $privacyKey = sanitize_key((string) get_option(self::OPTION_PRIVACY_META_KEY, 'accepted_privacy_policy'));
        return in_array($metaKey, ['first_name', 'last_name', $privacyKey], true);
    }

    private static function isSyncEnabled(): bool
    {
        return (bool) get_option(self::OPTION_ENABLED, false);
    }

    private static function allowUserWithoutSync(int $userId = 0): bool
    {
        if ($userId > 0) {
            $user = get_userdata($userId);
            if ($user instanceof \WP_User && self::isStrictUser($user)) {
                return false;
            }
        }

        return (bool) get_option(self::OPTION_ALLOW_FAILURE, false);
    }

    private static function isStrictUser(\WP_User $user): bool
    {
        $strictRole = sanitize_key((string) get_option(self::OPTION_STRICT_ROLE, ''));
        return $strictRole !== '' && in_array($strictRole, $user->roles, true);
    }

    private static function roleIsConfigured(\WP_User $user): bool
    {
        $roles = get_option(self::OPTION_ROLES, []);
        if (!is_array($roles) || $roles === []) {
            return false;
        }

        return (bool) array_intersect($roles, $user->roles);
    }

    public static function markAccountPendingReview(int $userId): void
    {
        if (self::isPatientSyncExemptUser($userId) || self::isInactiveForMissingPatient($userId)) {
            return;
        }

        update_user_meta($userId, self::META_ACCOUNT_STATUS, self::ACCOUNT_PENDING_REVIEW);
        UltimateMemberAccountService::requireAdminReview($userId);
    }

    public static function markAccountActive(int $userId): bool
    {
        if (self::isInactiveForMissingPatient($userId)) {
            if (!UltimateMemberAccountService::reactivateAfterPatientRestore($userId)) {
                return false;
            }

            delete_user_meta($userId, self::META_SUSPENDED_REASON);
            delete_user_meta($userId, self::META_SUSPENDED_AT);
            update_user_meta($userId, self::META_ACCOUNT_STATUS, self::ACCOUNT_ACTIVE);
            return true;
        }

        if (!UltimateMemberAccountService::approveAfterPatientSync($userId)) {
            update_user_meta($userId, self::META_ACCOUNT_STATUS, self::ACCOUNT_PENDING_REVIEW);
            return false;
        }

        update_user_meta($userId, self::META_ACCOUNT_STATUS, self::ACCOUNT_ACTIVE);
        return true;
    }

    public static function markAccountInactiveForMissingPatient(int $userId): bool
    {
        if (self::isPatientSyncExemptUser($userId)) {
            return false;
        }

        if (!UltimateMemberAccountService::deactivateForMissingPatient($userId)) {
            return false;
        }

        update_user_meta($userId, self::META_ACCOUNT_STATUS, self::ACCOUNT_INACTIVE);
        update_user_meta($userId, self::META_SUSPENDED_REASON, self::SUSPENSION_PATIENT_NOT_FOUND);
        update_user_meta($userId, self::META_SUSPENDED_AT, current_time('mysql', true));
        update_user_meta($userId, self::META_SYNC_STATUS, self::STATUS_FAILED);
        update_user_meta($userId, self::META_SYNC_ERROR, 'The linked Cliniko patient no longer exists.');
        return true;
    }

    public static function isInactiveForMissingPatient(int $userId): bool
    {
        if (!function_exists('get_user_meta')) {
            return false;
        }

        return (string) get_user_meta($userId, self::META_ACCOUNT_STATUS, true) === self::ACCOUNT_INACTIVE
            && (string) get_user_meta($userId, self::META_SUSPENDED_REASON, true) === self::SUSPENSION_PATIENT_NOT_FOUND;
    }

    /** @param array<string,string> $columns @return array<string,string> */
    public static function addAccountStatusColumn(array $columns): array
    {
        $columns['cliniko_patient_account'] = 'Cliniko patient account';
        return $columns;
    }

    public static function renderAccountStatusColumn(string $value, string $column, int $userId): string
    {
        if ($column !== 'cliniko_patient_account') {
            return $value;
        }

        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || !self::roleIsConfigured($user)) {
            return '—';
        }

        $status = (string) get_user_meta($userId, self::META_ACCOUNT_STATUS, true);
        if ($status === self::ACCOUNT_ACTIVE) {
            return 'Active';
        }
        if ($status === self::ACCOUNT_PENDING_REVIEW) {
            return 'Pending review';
        }
        if ($status === self::ACCOUNT_INACTIVE) {
            return 'Inactive';
        }

        return 'Not linked';
    }

    private function setSyncMeta(int $userId, string $status, string $error, bool $setTimestamp): void
    {
        update_user_meta($userId, self::META_SYNC_STATUS, $status);
        update_user_meta($userId, self::META_SYNC_ERROR, $error);
        if ($setTimestamp) {
            update_user_meta($userId, self::META_SYNCED_AT, current_time('mysql', true));
        }
    }

    /** @return array{ok:bool,patient_id:string,status:string,error:string} */
    private function failed(int $userId, string $error): array
    {
        $this->setSyncMeta($userId, self::STATUS_FAILED, $error, false);
        return ['ok' => false, 'patient_id' => '', 'status' => self::STATUS_FAILED, 'error' => $error];
    }

    private function safeError(string $message): string
    {
        $message = trim(wp_strip_all_tags($message));
        return function_exists('mb_substr') ? mb_substr($message, 0, 240) : substr($message, 0, 240);
    }

    private function diagnosticMessage(\Throwable $error): string
    {
        if ($error instanceof ApiException) {
            $context = $error->getContext();
            $apiError = trim((string) ($context['error'] ?? ''));
            if ($apiError !== '') {
                return $this->safeError($apiError);
            }
        }

        return $this->safeError($error->getMessage());
    }

    private function logSyncFailure(int $userId, \Throwable $error, string $message): void
    {
        $context = [];
        if ($error instanceof ApiException) {
            $exceptionContext = $error->getContext();
            $context = [
                'status_code' => isset($exceptionContext['status_code'])
                    ? (int) $exceptionContext['status_code']
                    : null,
                'api_error' => (string) ($exceptionContext['error'] ?? ''),
                'response_data' => $exceptionContext['response_data'] ?? null,
            ];
        }

        $entry = [
            'user_id' => $userId,
            'error' => $message,
            'exception_class' => get_class($error),
            'context' => $context,
        ];

        // Always write a redacted line to the normal WordPress/PHP log.
        error_log('[ClinikoPatientSync] ' . (string) wp_json_encode(LogSanitizer::sanitizeValue($entry)));

        // Also use the plugin Debug screen when debug capture is enabled.
        Runtime::logEvent([
            'channel' => 'patient-sync',
            'level' => 'error',
            'event' => 'patient_sync_failed',
            'method' => 'POST',
            'target' => 'patients',
            'request_kind' => 'cliniko',
            'status_code' => $context['status_code'] ?? null,
            'message' => $message,
            'context' => $entry,
        ]);
    }
}
