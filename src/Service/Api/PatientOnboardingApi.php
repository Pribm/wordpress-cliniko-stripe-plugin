<?php

namespace App\Service\Api;

use App\Admin\Modules\AccountBuilders\Forms\PatientOnboarding;
use App\Service\PatientFieldRegistry;
use App\Service\PatientCustomFieldService;
use App\Service\PatientService;
use WP_Error;

if (!defined('ABSPATH')) exit;

/**
 * Server-side contract for authenticated patient profile onboarding.
 *
 * The API never trusts a client-side "completed" flag. Completion is derived
 * from the linked Cliniko patient and the current definition revision.
 */
final class PatientOnboardingApi
{
    public const OPTION = 'wp_cliniko_api_patient_onboarding';
    private const SESSION_PREFIX = 'cliniko_api_onboard_';
    private const SWITCH_META_PREFIX = '_cliniko_api_onboarding_switches_';
    private const SESSION_TTL = 1800;

    public static function all(): array
    {
        $value = get_option(self::OPTION, []);
        return is_array($value) ? $value : [];
    }

    public static function configured(): ?array
    {
        foreach (self::all() as $slug => $definition) {
            if (is_array($definition) && !empty($definition['enabled']) && self::slug((string) $slug) !== '') {
                return self::definition((string) $slug);
            }
        }
        return null;
    }

    public static function save(string $slug, array $input): array|WP_Error
    {
        $slug = self::slug($slug);
        if ($slug === '') return new WP_Error('invalid_onboarding_slug', 'Use a short identifier with lowercase letters, numbers and hyphens.');
        $source = in_array((string) ($input['source'] ?? 'api'), ['api', 'shortcode'], true) ? (string) $input['source'] : 'api';
        $steps = [];
        $shortcodeId = '';
        if ($source === 'shortcode') {
            $shortcodeId = sanitize_key((string) ($input['shortcode_id'] ?? ''));
            $module = PatientOnboarding::apiSourceModules()[$shortcodeId] ?? null;
            if (!is_array($module)) return new WP_Error('invalid_shortcode_flow', 'Choose an existing patient onboarding flow.');
            $steps = PatientOnboarding::apiSourceSteps($module);
        } else {
            $steps = is_array($input['steps'] ?? null) && $input['steps'] !== []
                ? PatientOnboarding::apiNormaliseSteps((array) $input['steps'])
                : self::normaliseApiSteps((array) ($input['fields'] ?? []));
            if ($steps === []) return new WP_Error('onboarding_fields_required', 'Select at least one patient field for the API onboarding flow.');
        }
        $record = [
            'name' => sanitize_text_field((string) ($input['name'] ?? $slug)),
            'enabled' => !empty($input['enabled']),
            'source' => $source,
            'shortcode_id' => $shortcodeId,
            'require_before_booking' => !empty($input['require_before_booking']),
            'steps' => $steps,
        ];
        $all = self::all();
        $all[$slug] = $record;
        update_option(self::OPTION, $all, false);
        return $record + ['slug' => $slug];
    }

    public static function remove(string $slug): void
    {
        $all = self::all();
        unset($all[self::slug($slug)]);
        update_option(self::OPTION, $all, false);
    }

    public static function definition(string $slug): ?array
    {
        $slug = self::slug($slug);
        $stored = self::all()[$slug] ?? null;
        if (!is_array($stored)) return null;
        $steps = [];
        if (($stored['source'] ?? 'api') === 'shortcode') {
            $module = PatientOnboarding::apiSourceModules()[(string) ($stored['shortcode_id'] ?? '')] ?? null;
            if (!is_array($module)) return null;
            $steps = PatientOnboarding::apiSourceSteps($module);
        } else {
            $storedSteps = (array) ($stored['steps'] ?? []);
            $steps = self::looksLikeSteps($storedSteps)
                ? PatientOnboarding::apiNormaliseSteps($storedSteps)
                : self::normaliseApiSteps($storedSteps);
        }
        if ($steps === []) return null;
        $record = $stored;
        $record['slug'] = $slug;
        $record['steps'] = $steps;
        $optionContract = [];
        $available = PatientFieldRegistry::available();
        foreach (self::flatten($steps) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '' || ($field['kind'] ?? '') === 'switch') continue;
            $options = self::fieldOptions($field, $available[$key] ?? []);
            if ($options !== []) $optionContract[$key] = $options;
        }
        $record['revision'] = hash('sha256', (string) wp_json_encode([
            'source' => $record['source'] ?? 'api',
            'shortcode_id' => $record['shortcode_id'] ?? '',
            'require_before_booking' => !empty($record['require_before_booking']),
            'steps' => $steps,
            'options' => $optionContract,
        ]));
        return $record;
    }

    public static function requiredForBooking(int $userId): ?array
    {
        $definition = self::configured();
        if (!$definition || empty($definition['require_before_booking'])) return null;
        $status = self::status($definition['slug'], $userId);
        return !empty($status['complete']) ? null : $status + ['slug' => $definition['slug']];
    }

    public static function schema(string $slug, int $userId): array|WP_Error
    {
        $definition = self::definition($slug);
        if (!$definition || empty($definition['enabled'])) return new WP_Error('onboarding_not_found', 'This onboarding flow is not published.', ['status' => 404]);
        $status = self::status($slug, $userId, $definition);
        $fields = [];
        foreach (self::flatten($definition['steps']) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') continue;
            $fields[] = self::fieldSchema($field, $status['values'][$key] ?? null, $status['values']);
        }
        return [
            'slug' => $definition['slug'],
            'name' => (string) ($definition['name'] ?? $definition['slug']),
            'source' => (string) ($definition['source'] ?? 'api'),
            'definition_revision' => $definition['revision'],
            'complete' => $status['complete'],
            'missing' => $status['missing'],
            'steps' => array_map(static function (array $step): array {
                return ['id' => (string) ($step['id'] ?? ''), 'title' => (string) ($step['title'] ?? ''), 'subtitle' => (string) ($step['subtitle'] ?? ''), 'fields' => array_values(array_filter(array_map(static fn($field) => is_array($field) ? (string) ($field['key'] ?? '') : '', (array) ($step['fields'] ?? []))))];
            }, $definition['steps']),
            'fields' => $fields,
            'submission' => [
                'session' => ['method' => 'POST', 'path' => '/patient-onboarding/' . $definition['slug'] . '/session'],
                'complete' => ['method' => 'POST', 'path' => '/patient-onboarding/' . $definition['slug'] . '/complete', 'header' => 'X-ES-Onboarding-Token', 'body' => ['definition_revision' => $definition['revision'], 'data' => new \stdClass()]],
            ],
            'rules' => ['existing_values_count_as_complete' => true, 're_onboard_when_revision_changes' => true, 'email_changes_use_confirmation' => true],
        ];
    }

    /**
     * Return the patient profile shaped by the active onboarding definition.
     * The onboarding fields are the allowlist; unconfigured Cliniko fields are
     * deliberately not exposed through /me.
     */
    public static function profileForUser(int $userId): ?array
    {
        $definition = self::configured();
        if (!$definition) return null;
        $schema = self::schema((string) $definition['slug'], $userId);
        if ($schema instanceof WP_Error) return null;
        $profile = [];
        foreach (self::flatten($definition['steps']) as $field) {
            if (($field['kind'] ?? '') === 'switch') continue;
            $key = (string) ($field['key'] ?? '');
            if ($key === '') continue;
            foreach ((array) $schema['fields'] as $schemaField) {
                if (is_array($schemaField) && (string) ($schemaField['key'] ?? '') === $key) {
                    $profile[$key] = $schemaField['current_value'] ?? null;
                    break;
                }
            }
        }
        return $profile;
    }

    /**
     * Update the linked patient's profile using the same contract as /me.
     *
     * This is deliberately separate from onboarding completion: a patient
     * can edit an already-complete profile with their access token, without
     * creating a short-lived one-time onboarding session. The authenticated
     * WordPress user supplies the patient identity; no patient ID is accepted.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>|WP_Error
     */
    public static function updateProfile(int $userId, array $data): array|WP_Error
    {
        if ($data === []) return new WP_Error('profile_data_required', 'Send at least one profile field to update.', ['status' => 400]);

        $definition = self::configured();
        $allowed = [];
        $steps = [];
        if ($definition) {
            $steps = $definition['steps'];
            $allowed = array_fill_keys(array_map(static fn($field): string => (string) ($field['key'] ?? ''), self::flatten($steps)), true);
        } else {
            // Keep a useful fallback for installations that have not
            // published an onboarding scheme yet. Email remains excluded.
            $allowed = array_fill_keys(array_keys(PatientFieldRegistry::available()), true);
        }
        unset($allowed['email']);

        if (array_key_exists('email', $data)) {
            return new WP_Error('email_change_requires_confirmation', 'Email changes must use the separate verified email-change flow.', ['status' => 422, 'fields' => ['email' => 'Email changes require confirmation.']]);
        }
        $unknown = array_diff(array_keys($data), array_keys($allowed));
        if ($unknown !== []) {
            return new WP_Error('unknown_profile_field', 'The submission contains a profile field that is not allowed by the active API contract.', ['status' => 400, 'fields' => ['fields' => array_values($unknown)]]);
        }

        if ($definition) {
            $status = self::status((string) $definition['slug'], $userId, $definition);
            $submittedValues = array_merge($status['values'], $data);
            // PATCH is a partial edit: required fields are enforced by the
            // onboarding completion endpoint, while submitted fields still
            // receive the same format, option and conditional validation.
            $errors = self::validate($steps, $submittedValues, $data, false);
            if ($errors !== []) return new WP_Error('profile_validation_failed', 'Review the profile fields and try again.', ['status' => 422, 'fields' => $errors]);

            $switchKeys = [];
            foreach (self::flatten($steps) as $field) {
                if (($field['kind'] ?? '') !== 'switch') continue;
                $switchKey = (string) ($field['key'] ?? '');
                if ($switchKey !== '') $switchKeys[$switchKey] = true;
            }
            $switchUpdates = [];
            foreach (array_intersect_key($data, $switchKeys) as $key => $value) $switchUpdates[$key] = self::conditionBoolean($value);
            if ($switchUpdates !== []) self::storeSwitchValues((string) $definition['slug'], $userId, $switchUpdates);
            $data = array_diff_key($data, $switchKeys);
        }

        $update = $data;
        $custom = PatientCustomFieldService::getPatientFieldDefinitions();
        foreach (array_keys($custom) as $customKey) {
            if (array_key_exists($customKey, $update)) {
                $update['custom_fields'][$customKey] = $update[$customKey];
                unset($update[$customKey]);
            }
        }

        $patient = (new PatientService())->updatePatientForCurrentUser($update);
        if (!is_array($patient)) return new WP_Error('profile_update_failed', 'The linked patient profile could not be updated.', ['status' => 503]);

        $profile = self::profileForUser($userId);
        if ($profile !== null) return ['id' => $patient['id'] ?? null] + $profile;
        return ['id' => $patient['id'] ?? null] + array_intersect_key($patient, array_flip(['first_name', 'last_name', 'preferred_first_name', 'email', 'phone', 'date_of_birth', 'gender', 'occupation', 'address_1', 'address_2', 'city', 'state', 'post_code', 'country', 'notes']));
    }

    public static function start(string $slug, int $userId, string $clientId): array|WP_Error
    {
        $definition = self::definition($slug);
        if (!$definition || empty($definition['enabled'])) return new WP_Error('onboarding_not_found', 'This onboarding flow is not published.', ['status' => 404]);
        $status = self::status($slug, $userId, $definition);
        if ($status['complete']) return ['complete' => true, 'definition_revision' => $definition['revision'], 'expires_in' => 0];
        try { $token = bin2hex(random_bytes(32)); } catch (\Throwable $e) { return new WP_Error('onboarding_session_failed', 'A secure onboarding session could not be created.', ['status' => 503]); }
        $hash = hash('sha256', $token);
        set_transient(self::SESSION_PREFIX . $hash, ['user_id' => $userId, 'client_id' => $clientId, 'slug' => $definition['slug'], 'revision' => $definition['revision'], 'expires_at' => time() + self::SESSION_TTL], self::SESSION_TTL);
        return ['complete' => false, 'definition_revision' => $definition['revision'], 'onboarding_token' => $token, 'expires_in' => self::SESSION_TTL, 'missing' => $status['missing']];
    }

    public static function complete(string $slug, int $userId, string $clientId, string $token, string $revision, array $data): array|WP_Error
    {
        $definition = self::definition($slug);
        if (!$definition || empty($definition['enabled'])) return new WP_Error('onboarding_not_found', 'This onboarding flow is not published.', ['status' => 404]);
        if (!hash_equals((string) $definition['revision'], $revision)) return new WP_Error('onboarding_definition_changed', 'The onboarding definition changed. Reload its schema and start again.', ['status' => 409]);
        $session = self::session($token);
        if (!$session || (int) ($session['user_id'] ?? 0) !== $userId || (string) ($session['client_id'] ?? '') !== $clientId || (string) ($session['slug'] ?? '') !== $definition['slug'] || !hash_equals((string) ($session['revision'] ?? ''), $revision)) return new WP_Error('invalid_onboarding_token', 'The onboarding token is invalid, expired or belongs to another app or patient.', ['status' => 403]);
        $allowed = array_fill_keys(array_map(static fn($field): string => (string) ($field['key'] ?? ''), self::flatten($definition['steps'])), true);
        if (array_diff(array_keys($data), array_keys($allowed))) return new WP_Error('unknown_onboarding_field', 'The submission contains a field that is not in the current onboarding schema.', ['status' => 400]);
        $status = self::status($slug, $userId, $definition);
        $submittedValues = array_merge($status['values'], $data);
        $errors = self::validate($definition['steps'], $submittedValues, $data);
        if ($errors !== []) {
            return new WP_Error(
                'onboarding_validation_failed',
                'Review the patient details below before continuing.',
                ['status' => 422, 'fields' => self::validationDetails($definition['steps'], $errors)]
            );
        }
        $switchKeys = [];
        foreach (self::flatten($definition['steps']) as $field) {
            if (($field['kind'] ?? '') !== 'switch') continue;
            $switchKey = (string) ($field['key'] ?? '');
            if ($switchKey !== '') $switchKeys[$switchKey] = true;
        }
        $switchUpdates = [];
        foreach (array_intersect_key($data, $switchKeys) as $key => $value) {
            $switchUpdates[$key] = self::conditionBoolean($value);
        }
        if ($switchUpdates !== []) {
            self::storeSwitchValues($definition['slug'], $userId, $switchUpdates);
        }
        // Switches control the onboarding contract but are not Cliniko
        // patient fields. Never pass them to the patient update DTO.
        $update = array_diff_key($data, array_merge(['email' => true], $switchKeys));
        $custom = PatientCustomFieldService::getPatientFieldDefinitions();
        foreach (array_keys($custom) as $customKey) {
            if (array_key_exists($customKey, $update)) {
                $update['custom_fields'][$customKey] = $update[$customKey];
                unset($update[$customKey]);
            }
        }
        $patient = (new PatientService())->updatePatientForCurrentUser($update);
        if (!is_array($patient)) return new WP_Error('onboarding_update_failed', 'The linked patient profile could not be updated.', ['status' => 503]);
        delete_transient(self::SESSION_PREFIX . hash('sha256', $token));
        return self::schema($slug, $userId);
    }

    public static function validateSession(string $token, int $userId, string $clientId, string $slug, string $revision): bool
    {
        $session = self::session($token);
        return is_array($session) && (int) ($session['user_id'] ?? 0) === $userId && (string) ($session['client_id'] ?? '') === $clientId && (string) ($session['slug'] ?? '') === self::slug($slug) && hash_equals((string) ($session['revision'] ?? ''), $revision);
    }

    private static function session(string $token): ?array
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        $value = get_transient(self::SESSION_PREFIX . hash('sha256', $token));
        return is_array($value) && (int) ($value['expires_at'] ?? 0) >= time() ? $value : null;
    }

    private static function status(string $slug, int $userId, ?array $definition = null): array
    {
        $definition = $definition ?: self::definition($slug);
        if (!$definition) return ['complete' => false, 'missing' => [], 'values' => []];
        $previous = get_current_user_id();
        wp_set_current_user($userId);
        try { $patient = (new PatientService())->getPatientForCurrentUser() ?: []; } finally { wp_set_current_user($previous); }
        $values = self::values($patient);
        foreach (self::storedSwitchValues($definition['slug'], $userId) as $key => $value) {
            $values[$key] = $value;
        }
        $missing = self::missing($definition['steps'], $values);
        return ['complete' => $missing === [], 'missing' => $missing, 'values' => $values];
    }

    private static function values(array $patient): array
    {
        $values = $patient;
        foreach ((array) ($patient['custom_fields'] ?? []) as $key => $value) $values[(string) $key] = $value;
        foreach ((array) ($patient['custom_field_values'] ?? []) as $key => $value) $values[(string) $key] = $value;
        return $values;
    }

    private static function missing(array $steps, array $values): array
    {
        $missing = [];
        foreach (self::flatten($steps) as $field) {
            if (empty($field['required'])) continue;
            $key = (string) ($field['key'] ?? '');
            // Conditional requiredness is evaluated against the actual
            // controller value. A false/off/0 controller must remain false;
            // using empty() here made inverted conditions unreliable for
            // string and numeric values.
            if (!self::conditionMatches($field, $values)) continue;
            if (($field['kind'] ?? '') === 'switch') {
                if (!array_key_exists($key, $values)) {
                    $missing[] = ['key' => $key, 'label' => (string) ($field['label'] ?? $key)];
                }
                continue;
            }
            if ($key === 'email' && !empty($values[$key])) continue;
            $value = $values[$key] ?? null;
            $empty = is_array($value) ? $value === [] : !isset($value) || trim((string) $value) === '';
            if ($empty) $missing[] = ['key' => $key, 'label' => (string) ($field['label'] ?? $key)];
        }
        return $missing;
    }

    private static function validate(array $steps, array $data, array $submitted = [], bool $requireComplete = true): array
    {
        $errors = [];
        $available = PatientFieldRegistry::available();
        foreach (self::flatten($steps) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '' || !array_key_exists($key, $data)) continue;
            $conditionSwitch = (string) ($field['condition_switch'] ?? '');
            if (!self::conditionMatches($field, $data)) continue;
            $value = $data[$key];
            // Existing profile values are used to determine completeness, but
            // only values in this submission should be revalidated. A missing
            // nullable field from Cliniko must not become "invalid" merely
            // because it is represented as null in the merged profile.
            if (!array_key_exists($key, $submitted) && ($value === null || $value === '')) continue;
            if (!is_scalar($value) && !is_array($value)) { $errors[$key] = 'Enter a valid value.'; continue; }
            if (is_scalar($value) && (int) ($field['max_length'] ?? 0) > 0 && strlen((string) $value) > (int) $field['max_length']) $errors[$key] = 'This value is too long.';
            if (($field['input_type'] ?? '') === 'date' && is_scalar($value) && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $value)) $errors[$key] = 'Use YYYY-MM-DD.';
            $options = self::fieldOptions($field, $available[$key] ?? []);
            if ($options !== [] && is_scalar($value) && !in_array((string) $value, $options, true)) $errors[$key] = 'Choose one of the available options.';
            if (isset($field['input_format']) && $field['input_format'] === 'numbers' && is_scalar($value) && !preg_match('/^\d+$/D', (string) $value)) $errors[$key] = 'Use numbers only.';
            if (($field['input_format'] ?? '') === 'pattern' && is_scalar($value)) {
                $pattern = (string) ($field['mask_pattern'] ?? '');
                $prefix = (string) ($field['mask_prefix'] ?? '');
                $suffix = (string) ($field['mask_suffix'] ?? '');
                if (!self::maskMatches((string) $value, $pattern, $prefix, $suffix)) $errors[$key] = 'Use the configured format: ' . $prefix . $pattern . $suffix . ' (spaces may be omitted in API requests).';
            }
        }
        if ($requireComplete) {
            $values = $data;
            foreach (self::missing($steps, $values) as $item) if (!array_key_exists($item['key'], $data) || trim((string) ($data[$item['key']] ?? '')) === '') $errors[$item['key']] = 'This field is required.';
        }
        return $errors;
    }

    /**
     * Convert the internal field-to-message map into the same detailed shape
     * used by booking preflight validation. The API client can therefore show
     * the field label and a useful correction without knowing the dashboard
     * configuration.
     *
     * @param array<int,array<string,mixed>> $steps
     * @param array<string,string> $errors
     * @return array<int,array{field:string,label:string,code:string,detail:string}>
     */
    private static function validationDetails(array $steps, array $errors): array
    {
        $labels = [];
        foreach (self::flatten($steps) as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key !== '') $labels[$key] = (string) ($field['label'] ?? $key);
        }

        $details = [];
        foreach ($errors as $key => $reason) {
            $reason = trim((string) $reason);
            $code = match (true) {
                $reason === 'This field is required.' => 'required',
                str_starts_with($reason, 'Choose one of the available options.') => 'invalid_selection',
                str_starts_with($reason, 'Use YYYY-MM-DD.'),
                str_starts_with($reason, 'Use numbers only.'),
                str_starts_with($reason, 'Use the configured format:') => 'invalid_format',
                str_starts_with($reason, 'This value is too long.') => 'max_length',
                str_starts_with($reason, 'Enter a valid value.') => 'invalid_value',
                default => 'invalid',
            };
            $details[] = [
                'field' => (string) $key,
                'label' => $labels[(string) $key] ?? (string) $key,
                'code' => $code,
                'detail' => $reason !== '' ? $reason : 'Enter a valid value.',
            ];
        }
        return $details;
    }

    private static function fieldSchema(array $field, $value, array $values = []): array
    {
        $key = (string) ($field['key'] ?? '');
        $definition = PatientFieldRegistry::available()[$key] ?? [];
        if (($field['kind'] ?? '') === 'switch') {
            return [
                'key' => $key,
                'label' => (string) ($field['label'] ?? $key),
                'type' => 'boolean',
                'required' => !empty($field['required']),
                'read_only' => false,
                'current_value' => array_key_exists($key, $values) ? self::conditionBoolean($values[$key]) : null,
                'validation' => ['format' => 'boolean'],
                'condition' => null,
            ];
        }
        $type = (string) ($field['input_type'] ?? '');
        if ($type === '' || $type === 'default') $type = (string) ($definition['type'] ?? 'text');
        $options = self::fieldOptions($field, $definition);
        $validation = array_filter([
            'max_length' => (int) ($field['max_length'] ?? 0),
            'format' => (string) ($field['input_format'] ?? ''),
            'pattern' => (string) ($field['mask_pattern'] ?? ''),
            'prefix' => (string) ($field['mask_prefix'] ?? ''),
            'suffix' => (string) ($field['mask_suffix'] ?? ''),
            'options' => $options,
        ], static fn($item) => $item !== 0 && $item !== '' && $item !== []);
        if (($field['input_format'] ?? '') === 'pattern' && str_contains((string) ($field['mask_pattern'] ?? '') . (string) ($field['mask_prefix'] ?? '') . (string) ($field['mask_suffix'] ?? ''), ' ')) $validation['spaces_optional'] = true;
        $condition = !empty($field['condition_switch'])
            ? ['switch' => (string) $field['condition_switch'], 'equals' => self::conditionBoolean($field['condition_value'] ?? true)]
            : null;
        return ['key' => $key, 'label' => (string) ($field['label'] ?? $definition['label'] ?? $key), 'type' => $type, 'required' => !empty($field['required']) && self::conditionMatches($field, $values), 'read_only' => $key === 'email', 'current_value' => $value, 'validation' => $validation, 'condition' => $condition];
    }

    /** @param array<string,mixed> $field @param array<string,mixed> $definition @return array<int,string> */
    private static function fieldOptions(array $field, array $definition): array
    {
        $inputType = strtolower((string) ($field['input_type'] ?? ''));
        $usesDefaultType = $inputType === '' || $inputType === 'default';
        $type = $usesDefaultType ? strtolower((string) ($definition['type'] ?? '')) : $inputType;
        if (!in_array($type, ['select', 'radio', 'radiobuttons'], true)) return [];
        $raw = $usesDefaultType
            ? (array) ($definition['options'] ?? [])
            : (array) ($field['select_options'] ?? []);
        if ($raw === []) $raw = (array) ($definition['options'] ?? []);
        $options = [];
        foreach ($raw as $option) {
            $value = is_array($option) ? (string) ($option['name'] ?? $option['label'] ?? $option['value'] ?? '') : (string) $option;
            $value = trim($value);
            if ($value !== '' && !in_array($value, $options, true)) $options[] = $value;
        }
        return $options;
    }

    /**
     * A conditional field is active only when its controller exists and its
     * normalized value matches the configured condition. If the controller
     * has not been answered yet, dependent fields remain inactive; the
     * controller itself can still be reported as missing when configured as
     * required.
     *
     * @param array<string,mixed> $field
     * @param array<string,mixed> $values
     */
    private static function conditionMatches(array $field, array $values): bool
    {
        $switch = trim((string) ($field['condition_switch'] ?? ''));
        if ($switch === '') return true;
        if (!array_key_exists($switch, $values)) return false;
        return self::conditionBoolean($values[$switch]) === self::conditionBoolean($field['condition_value'] ?? true);
    }

    private static function conditionBoolean($value): bool
    {
        if (is_bool($value)) return $value;
        return !in_array(strtolower(trim((string) $value)), ['off', 'false', '0', 'no'], true);
    }

    /** @return array<string,bool> */
    private static function storedSwitchValues(string $slug, int $userId): array
    {
        if ($userId <= 0) return [];
        $stored = get_user_meta($userId, self::switchMetaKey($slug), true);
        if (!is_array($stored)) return [];
        $values = [];
        foreach ($stored as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key !== '') $values[$key] = self::conditionBoolean($value);
        }
        return $values;
    }

    /** @param array<string,bool> $values */
    private static function storeSwitchValues(string $slug, int $userId, array $values): void
    {
        if ($userId <= 0 || $values === []) return;
        $stored = self::storedSwitchValues($slug, $userId);
        update_user_meta($userId, self::switchMetaKey($slug), array_merge($stored, $values));
    }

    private static function switchMetaKey(string $slug): string
    {
        return self::SWITCH_META_PREFIX . sanitize_key($slug);
    }

    private static function normaliseApiSteps(array $fields): array
    {
        $result = [];
        foreach ($fields as $key => $field) {
            if (!is_array($field)) continue;
            $fieldKey = sanitize_key((string) ($field['key'] ?? $key));
            if (!isset(PatientFieldRegistry::available()[$fieldKey])) continue;
            $options = is_string($field['select_options'] ?? null) ? (preg_split('/\s*,\s*/', (string) $field['select_options']) ?: []) : (array) ($field['select_options'] ?? []);
            $conditionSwitch = sanitize_key((string) ($field['condition_switch'] ?? ''));
            $result[] = ['key' => $fieldKey, 'label' => sanitize_text_field((string) ($field['label'] ?? PatientFieldRegistry::available()[$fieldKey]['label'] ?? $fieldKey)), 'required' => !empty($field['required']), 'input_type' => sanitize_key((string) ($field['input_type'] ?? PatientFieldRegistry::available()[$fieldKey]['type'] ?? 'text')), 'max_length' => min(4096, max(0, (int) ($field['max_length'] ?? 255))), 'input_format' => sanitize_key((string) ($field['input_format'] ?? '')), 'select_options' => array_values(array_filter(array_map('sanitize_text_field', $options))), 'condition_switch' => $conditionSwitch, 'condition_value' => self::conditionBoolean($field['condition_value'] ?? true) ? 'on' : 'off'];
        }
        return $result === [] ? [] : [['id' => 'api-profile', 'title' => 'Patient details', 'subtitle' => 'Complete the missing details in your patient profile.', 'fields' => $result]];
    }

    /** @param array<int,mixed> $value */
    private static function looksLikeSteps(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item) && array_key_exists('fields', $item)) return true;
        }
        return false;
    }

    private static function maskRegex(string $pattern, string $prefix, string $suffix): string
    {
        if ($pattern === '' || !preg_match('/[#A*]/', $pattern)) return '';
        $characters = preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($pattern);
        $body = '';
        foreach ($characters as $character) $body .= match ($character) {
            '#' => '[0-9]', 'A' => '[A-Za-z]', '*' => '[A-Za-z0-9]', default => preg_quote($character, '~'),
        };
        return '~^' . preg_quote($prefix, '~') . $body . preg_quote($suffix, '~') . '$~uD';
    }

    private static function maskMatches(string $value, string $pattern, string $prefix, string $suffix): bool
    {
        $regex = self::maskRegex($pattern, $prefix, $suffix);
        if ($regex !== '' && preg_match($regex, $value) === 1) return true;
        $compactValue = preg_replace('/\s+/u', '', $value);
        $compactPattern = preg_replace('/\s+/u', '', $pattern);
        $compactPrefix = preg_replace('/\s+/u', '', $prefix);
        $compactSuffix = preg_replace('/\s+/u', '', $suffix);
        $compactRegex = self::maskRegex((string) $compactPattern, (string) $compactPrefix, (string) $compactSuffix);
        return $compactRegex !== '' && is_string($compactValue) && preg_match($compactRegex, $compactValue) === 1;
    }

    private static function flatten(array $steps): array
    {
        $fields = [];
        foreach ($steps as $step) foreach ((array) ($step['fields'] ?? []) as $field) if (is_array($field)) $fields[] = $field;
        return $fields;
    }

    private static function slug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        return preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $slug) ? $slug : '';
    }
}
