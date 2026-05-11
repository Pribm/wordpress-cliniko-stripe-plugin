<?php

namespace App\Service;

use App\Admin\Modules\Credentials;

if (!defined('ABSPATH')) {
    exit;
}

class PatientCustomFieldService
{
    private const DEFAULT_SECTION_NAME = 'Custom fields';
    private const ALLOWED_FIELD_TYPES = [
        'text',
        'textarea',
        'email',
        'tel',
        'date',
        'number',
        'select',
        'radio',
        'checkbox',
        'checkboxes',
        'multi_checkbox',
        'multi_select',
        'hidden',
    ];

    private const ALLOWED_VALIDATION_TYPES = [
        'none',
        'regex',
        'email',
        'phone_au',
        'postcode_au',
        'medicare',
        'date_iso',
        'length',
        'number_range',
        'enum',
    ];

    /**
     * @param mixed $definitions
     * @return array<int,array<string,mixed>>
     */
    public static function normalizeDefinitions($definitions): array
    {
        if (!is_array($definitions) || empty($definitions)) {
            return [];
        }

        $out = [];
        $seenPaths = [];

        foreach ($definitions as $definition) {
            $field = self::normalizeDefinition($definition);
            if ($field === null) {
                continue;
            }

            if (isset($seenPaths[$field['path']])) {
                continue;
            }

            $seenPaths[$field['path']] = true;
            $out[] = $field;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $patient
     * @param array<int,array<string,mixed>> $definitions
     * @return array<int,array<string,mixed>>
     */
    public static function validate(array $patient, array $definitions): array
    {
        if (empty($definitions)) {
            return [];
        }

        $errors = [];

        foreach ($definitions as $field) {
            $value = self::getNestedValue($patient, (string) ($field['path'] ?? ''));
            $valueIsEmpty = self::isEmptyValue($value);
            $fieldType = strtolower((string) ($field['type'] ?? 'text'));
            $validationType = strtolower((string) ($field['validation']['type'] ?? 'none'));
            $ruleType = $validationType !== 'none'
                ? $validationType
                : self::inferValidationType($fieldType, $field);

            if (!empty($field['required']) && $valueIsEmpty) {
                $errors[] = self::buildError($field, 'required', $field['validation']['message'] ?? sprintf('%s is required.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                continue;
            }

            if ($valueIsEmpty) {
                continue;
            }

            $stringValue = self::toStringValue($value);
            $arrayValue = is_array($value)
                ? self::flattenValueArray($value)
                : null;
            $optionList = !empty($field['validation']['options']) && is_array($field['validation']['options'])
                ? array_values(array_filter(array_map(
                    static fn($option) => trim((string) $option),
                    $field['validation']['options']
                )))
                : (is_array($field['options'] ?? null)
                    ? array_values(array_filter(array_map(
                        static fn($option) => trim((string) $option),
                        $field['options']
                    )))
                    : []);

            if ($ruleType === 'email') {
                if (!filter_var($stringValue, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = self::buildError($field, 'invalid_email', $field['validation']['message'] ?? sprintf('%s must be a valid email address.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                }
                continue;
            }

            if ($ruleType === 'phone_au') {
                $digits = preg_replace('/\D+/', '', $stringValue) ?? '';
                if (!(strlen($digits) === 10 || (strlen($digits) === 11 && str_starts_with($digits, '61')))) {
                    $errors[] = self::buildError($field, 'invalid_phone', $field['validation']['message'] ?? sprintf('%s must be a valid Australian phone number.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                }
                continue;
            }

            if ($ruleType === 'postcode_au') {
                $digits = preg_replace('/\D+/', '', $stringValue) ?? '';
                if (!preg_match('/^\d{4}$/', $digits)) {
                    $errors[] = self::buildError($field, 'invalid_postcode', $field['validation']['message'] ?? sprintf('%s must be a 4-digit postcode.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                }
                continue;
            }

            if ($ruleType === 'medicare') {
                $digits = preg_replace('/\D+/', '', $stringValue) ?? '';
                if (strlen($digits) !== 10) {
                    $errors[] = self::buildError($field, 'invalid_medicare', $field['validation']['message'] ?? sprintf('%s must be a 10-digit Medicare number.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                }
                continue;
            }

            if ($ruleType === 'date_iso') {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $stringValue)) {
                    $errors[] = self::buildError($field, 'invalid_date', $field['validation']['message'] ?? sprintf('%s must be a valid date in YYYY-MM-DD format.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                    continue;
                }

                [$year, $month, $day] = array_map('intval', explode('-', $stringValue));
                if (!checkdate($month, $day, $year)) {
                    $errors[] = self::buildError($field, 'invalid_date', $field['validation']['message'] ?? sprintf('%s must be a valid date in YYYY-MM-DD format.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                }
                continue;
            }

            if ($ruleType === 'regex') {
                $pattern = trim((string) ($field['validation']['pattern'] ?? ''));
                if ($pattern === '') {
                    $errors[] = self::buildError($field, 'invalid_validation_config', $field['validation']['message'] ?? sprintf('%s is missing a validation pattern.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                    continue;
                }

                $regex = '~' . str_replace('~', '\~', $pattern) . '~u';
                $match = @preg_match($regex, $stringValue);
                if ($match !== 1) {
                    $errors[] = self::buildError($field, 'invalid_pattern', $field['validation']['message'] ?? sprintf('%s does not match the expected format.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                }
                continue;
            }

            if ($ruleType === 'length') {
                $minLength = isset($field['validation']['min_length']) ? (int) $field['validation']['min_length'] : null;
                $maxLength = isset($field['validation']['max_length']) ? (int) $field['validation']['max_length'] : null;
                $measuredLength = is_array($value) ? count($value) : strlen($stringValue);

                if ($minLength !== null && $measuredLength < $minLength) {
                    $errors[] = self::buildError($field, 'invalid_length', $field['validation']['message'] ?? sprintf('%s must be at least %d characters long.', (string) ($field['label'] ?? $field['path'] ?? 'Field'), $minLength));
                    continue;
                }

                if ($maxLength !== null && $measuredLength > $maxLength) {
                    $errors[] = self::buildError($field, 'invalid_length', $field['validation']['message'] ?? sprintf('%s must be no more than %d characters long.', (string) ($field['label'] ?? $field['path'] ?? 'Field'), $maxLength));
                }
                continue;
            }

            if ($ruleType === 'number_range') {
                if (is_array($value)) {
                    $errors[] = self::buildError($field, 'invalid_number', $field['validation']['message'] ?? sprintf('%s must be a valid number.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                    continue;
                }

                if (!is_numeric($stringValue)) {
                    $errors[] = self::buildError($field, 'invalid_number', $field['validation']['message'] ?? sprintf('%s must be a valid number.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                    continue;
                }

                $numeric = (float) $stringValue;
                if (array_key_exists('min_value', $field['validation']) && is_numeric($field['validation']['min_value']) && $numeric < (float) $field['validation']['min_value']) {
                    $errors[] = self::buildError($field, 'invalid_number', $field['validation']['message'] ?? sprintf('%s must be at least %s.', (string) ($field['label'] ?? $field['path'] ?? 'Field'), (string) $field['validation']['min_value']));
                    continue;
                }

                if (array_key_exists('max_value', $field['validation']) && is_numeric($field['validation']['max_value']) && $numeric > (float) $field['validation']['max_value']) {
                    $errors[] = self::buildError($field, 'invalid_number', $field['validation']['message'] ?? sprintf('%s must be no more than %s.', (string) ($field['label'] ?? $field['path'] ?? 'Field'), (string) $field['validation']['max_value']));
                }
                continue;
            }

            if ($ruleType === 'enum') {
                if (empty($optionList)) {
                    continue;
                }

                if (is_array($value)) {
                    $invalid = [];
                    foreach ($arrayValue ?? [] as $item) {
                        $matched = false;
                        foreach ($optionList as $option) {
                            if (strtolower($option) === strtolower($item)) {
                                $matched = true;
                                break;
                            }
                        }
                        if (!$matched) {
                            $invalid[] = $item;
                        }
                    }

                    if (!empty($invalid)) {
                        $errors[] = self::buildError($field, 'invalid_selection', $field['validation']['message'] ?? sprintf('%s contains an invalid selection.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                    }
                    continue;
                }

                $matched = false;
                foreach ($optionList as $option) {
                    if (strtolower($option) === strtolower($stringValue)) {
                        $matched = true;
                        break;
                    }
                }

                if (!$matched) {
                    $errors[] = self::buildError($field, 'invalid_selection', $field['validation']['message'] ?? sprintf('%s contains an invalid selection.', (string) ($field['label'] ?? $field['path'] ?? 'Field')));
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $patient
     * @param array<int,array<string,mixed>> $definitions
     * @return array<string,mixed>|null
     */
    public static function buildCustomFields(array $patient, array $definitions): ?array
    {
        $normalizedDefinitions = self::normalizeDefinitions($definitions);
        $existingSections = self::normalizeSections(self::extractExistingSections($patient['custom_fields'] ?? null));
        $generatedSections = self::buildSectionsFromDefinitions($patient, $normalizedDefinitions);

        $sections = $existingSections;
        foreach ($generatedSections as $sectionKey => $section) {
            if (!isset($sections[$sectionKey])) {
                $sections[$sectionKey] = $section;
                continue;
            }

            foreach ($section['fields'] as $fieldKey => $field) {
                $sections[$sectionKey]['fields'][$fieldKey] = $field;
            }
        }

        if (empty($sections)) {
            return null;
        }

        return self::sectionsMapToPayload($sections);
    }

    /**
     * @param mixed $customFields
     */
    public static function hasCustomFieldValues($customFields): bool
    {
        if (!is_array($customFields)) {
            return false;
        }

        foreach ($customFields as $value) {
            if (is_array($value)) {
                if (self::hasCustomFieldValues($value)) {
                    return true;
                }
                continue;
            }

            if (is_bool($value)) {
                if ($value) {
                    return true;
                }
                continue;
            }

            if (is_numeric($value)) {
                return true;
            }

            if (trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed $definition
     * @return array<string,mixed>|null
     */
    private static function normalizeDefinition($definition): ?array
    {
        if (!is_array($definition) || empty($definition)) {
            return null;
        }

        $rawPath = trim((string) ($definition['path'] ?? $definition['field_path'] ?? ''));
        $rawKey = trim((string) ($definition['key'] ?? $definition['field_key'] ?? ''));
        $rawLabel = trim((string) ($definition['label'] ?? $definition['field_label'] ?? ''));
        $fieldType = strtolower(trim((string) ($definition['type'] ?? $definition['field_type'] ?? 'text')));
        if (!in_array($fieldType, self::ALLOWED_FIELD_TYPES, true)) {
            $fieldType = 'text';
        }

        $pathSource = $rawPath;
        if ($pathSource === '' && $rawKey !== '') {
            $pathSource = 'custom_fields.' . $rawKey;
        }
        if ($pathSource === '' && $rawLabel !== '') {
            $pathSource = 'custom_fields.' . self::normalizeSegment($rawLabel);
        }

        $path = self::normalizePath($pathSource);
        if ($path === '') {
            return null;
        }

        $key = self::normalizeSegment($rawKey !== '' ? $rawKey : str_replace('.', '_', $path));
        if ($key === '') {
            $key = self::normalizeSegment($rawLabel);
        }
        if ($key === '') {
            return null;
        }

        $validationRaw = is_array($definition['validation'] ?? null) ? $definition['validation'] : [];
        $options = [];
        $rawOptions = $definition['options'] ?? ($definition['field_options'] ?? []);
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $option) {
                if (is_array($option)) {
                    $candidate = trim((string) ($option['value'] ?? $option['label'] ?? ''));
                } else {
                    $candidate = trim((string) $option);
                }
                if ($candidate !== '') {
                    $options[] = $candidate;
                }
            }
        } elseif (is_string($rawOptions) && trim($rawOptions) !== '') {
            foreach (preg_split('/[\r\n,]+/', $rawOptions) ?: [] as $option) {
                $candidate = trim((string) $option);
                if ($candidate !== '') {
                    $options[] = $candidate;
                }
            }
        }

        $validationType = strtolower(trim((string) ($validationRaw['type'] ?? $definition['validation_type'] ?? 'none')));
        if (!in_array($validationType, self::ALLOWED_VALIDATION_TYPES, true) || $validationType === 'none') {
            $validationType = self::inferValidationType($fieldType, [
                'label' => $rawLabel,
                'name' => $rawLabel,
                'key' => $key,
                'path' => $path,
                'options' => $options,
            ]);
        }

        $validation = ['type' => $validationType];
        $pattern = trim((string) ($validationRaw['pattern'] ?? $definition['validation_pattern'] ?? ''));
        if ($pattern !== '') {
            $validation['pattern'] = $pattern;
        }

        $message = trim((string) ($validationRaw['message'] ?? $definition['validation_message'] ?? ''));
        if ($message !== '') {
            $validation['message'] = $message;
        }

        foreach (['min_length', 'max_length', 'min_value', 'max_value'] as $numericKey) {
            $numericValue = $validationRaw[$numericKey] ?? ($definition[$numericKey] ?? null);
            if ($numericValue === null || $numericValue === '') {
                continue;
            }
            if (is_numeric($numericValue)) {
                $validation[$numericKey] = $numericValue + 0;
            }
        }

        if (!empty($options)) {
            $validation['options'] = $options;
        }

        $clinikoSectionName = trim((string) ($definition['cliniko_section_name'] ?? $definition['section_name'] ?? ''));
        $clinikoSectionToken = trim((string) ($definition['cliniko_section_token'] ?? $definition['section_token'] ?? ''));
        $clinikoFieldName = trim((string) ($definition['cliniko_field_name'] ?? $definition['custom_field_name'] ?? ''));
        $clinikoFieldToken = trim((string) ($definition['cliniko_field_token'] ?? $definition['custom_field_token'] ?? ''));
        $clinikoFieldType = strtolower(trim((string) ($definition['cliniko_field_type'] ?? $definition['custom_field_type'] ?? $fieldType)));
        if (!in_array($clinikoFieldType, self::ALLOWED_FIELD_TYPES, true)) {
            $clinikoFieldType = $fieldType;
        }

        $defaultValue = $definition['default'] ?? null;

        return [
            'key' => $key,
            'label' => $rawLabel !== '' ? $rawLabel : $key,
            'path' => $path,
            'type' => $fieldType,
            'required' => !empty($definition['required']) && !in_array($definition['required'], [false, 'false', 0, '0'], true),
            'placeholder' => trim((string) ($definition['placeholder'] ?? '')),
            'help_text' => trim((string) ($definition['help_text'] ?? '')),
            'options' => $options,
            'default' => $defaultValue,
            'validation' => $validation,
            'cliniko_section_name' => $clinikoSectionName,
            'cliniko_section_token' => $clinikoSectionToken,
            'cliniko_field_name' => $clinikoFieldName !== '' ? $clinikoFieldName : ($rawLabel !== '' ? $rawLabel : $key),
            'cliniko_field_token' => $clinikoFieldToken,
            'cliniko_field_type' => $clinikoFieldType,
        ];
    }

    private static function normalizeSegment(string $segment): string
    {
        $segment = strtolower(trim($segment));
        $segment = preg_replace('/[^a-z0-9_-]+/', '_', $segment) ?? '';
        $segment = preg_replace('/_+/', '_', $segment) ?? '';
        return trim($segment, '_-');
    }

    private static function normalizePath(string $path): string
    {
        $raw = trim($path);
        if ($raw === '') {
            return '';
        }

        $segments = array_values(array_filter(
            array_map(static fn($segment) => self::normalizeSegment((string) $segment), explode('.', $raw)),
            static fn($segment) => $segment !== ''
        ));
        if (empty($segments)) {
            return '';
        }

        if ($segments[0] === 'patient') {
            array_shift($segments);
        }

        return implode('.', $segments);
    }

    /**
     * @param mixed $value
     */
    private static function isEmptyValue($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_bool($value)) {
            return $value === false;
        }

        if (is_array($value)) {
            return $value === [];
        }

        if (is_numeric($value)) {
            return false;
        }

        if (is_object($value)) {
            return count(get_object_vars($value)) === 0;
        }

        return trim((string) $value) === '';
    }

    /**
     * @param array<string,mixed> $source
     * @return mixed
     */
    private static function getNestedValue(array $source, string $path)
    {
        $normalizedPath = self::normalizePath($path);
        if ($normalizedPath === '') {
            return null;
        }

        $cursor = $source;
        foreach (explode('.', $normalizedPath) as $segment) {
            if (is_array($cursor) && array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
                continue;
            }

            return null;
        }

        return $cursor;
    }

    /**
     * @param array<int,mixed> $value
     * @return array<int,string>
     */
    private static function flattenValueArray(array $value): array
    {
        $out = [];
        array_walk_recursive($value, static function ($item) use (&$out): void {
            if (is_bool($item)) {
                if ($item) {
                    $out[] = 'true';
                }
                return;
            }

            if ($item === null) {
                return;
            }

            $candidate = trim((string) $item);
            if ($candidate !== '') {
                $out[] = $candidate;
            }
        });

        return $out;
    }

    /**
     * @param mixed $value
     */
    private static function toStringValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : '';
        }

        if (is_array($value)) {
            return implode(', ', self::flattenValueArray($value));
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                return trim((string) $value);
            }

            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
            return is_string($encoded) ? trim($encoded) : '';
        }

        return trim((string) $value);
    }

    private static function normalizeLookupKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
        return $value;
    }

    /**
     * @param array<string,mixed> $field
     */
    public static function inferValidationType(string $fieldType, array $field): string
    {
        $fieldType = strtolower(trim($fieldType));
        $label = self::normalizeLookupKey(implode(' ', array_filter([
            (string) ($field['label'] ?? ''),
            (string) ($field['name'] ?? ''),
            (string) ($field['field_label'] ?? ''),
            (string) ($field['cliniko_field_name'] ?? ''),
            (string) ($field['key'] ?? ''),
            (string) ($field['path'] ?? ''),
        ], static fn ($value) => trim((string) $value) !== '')));

        $optionTypes = ['select', 'radio', 'checkboxes', 'multi_checkbox', 'multi_select'];
        $hasOptions = !empty($field['options']) && is_array($field['options']);

        if ($fieldType === 'hidden') {
            return 'none';
        }

        if (in_array($fieldType, $optionTypes, true) && $hasOptions) {
            return 'enum';
        }

        if ($label !== '') {
            if (str_contains($label, 'medicare')) {
                return 'medicare';
            }

            if (str_contains($label, 'postcode') || str_contains($label, 'post_code') || str_contains($label, 'zip')) {
                return 'postcode_au';
            }

            if (str_contains($label, 'email')) {
                return 'email';
            }

            if (
                str_contains($label, 'phone') ||
                str_contains($label, 'mobile') ||
                str_contains($label, 'telephone') ||
                str_contains($label, 'contactnumber') ||
                str_contains($label, 'tel')
            ) {
                return 'phone_au';
            }

            if (
                str_contains($label, 'dateofbirth') ||
                str_contains($label, 'birthdate') ||
                str_contains($label, 'dob')
            ) {
                return 'date_iso';
            }
        }

        if ($fieldType === 'email') {
            return 'email';
        }

        if ($fieldType === 'tel') {
            return 'phone_au';
        }

        if ($fieldType === 'date') {
            return 'date_iso';
        }

        if ($fieldType === 'number') {
            return 'number_range';
        }

        if (in_array($fieldType, $optionTypes, true)) {
            return $hasOptions ? 'enum' : 'none';
        }

        return 'none';
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private static function buildError(array $field, string $code, string $message): array
    {
        return [
            'field' => (string) ($field['path'] ?? ''),
            'label' => (string) ($field['label'] ?? $field['path'] ?? 'Field'),
            'code' => $code,
            'detail' => $message,
        ];
    }

    /**
     * @return array{loaded:bool,sections:array<int,array<string,mixed>>}
     */
    private static function getClinikoCustomFieldSettings(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $cached = [
            'loaded' => false,
            'sections' => [],
        ];

        if (!function_exists('cliniko_client')) {
            return $cached;
        }

        try {
            $client = cliniko_client(true, Credentials::getClinikoApiCacheTtl());
            $response = $client->get('settings');
            $data = $response->data ?? null;
            if (!is_array($data)) {
                $cached['loaded'] = true;
                return $cached;
            }

            $definition = $data['patient_custom_fields_definition'] ?? null;
            if (!is_array($definition)) {
                $cached['loaded'] = true;
                return $cached;
            }

            $rawSections = $definition['sections'] ?? [];
            if (!is_array($rawSections)) {
                $cached['loaded'] = true;
                return $cached;
            }

            $sections = [];
            foreach ($rawSections as $section) {
                if (!is_array($section) || !empty($section['archived'])) {
                    continue;
                }

                $fields = [];
                $rawFields = $section['fields'] ?? [];
                if (is_array($rawFields)) {
                    foreach ($rawFields as $field) {
                        if (!is_array($field) || !empty($field['archived'])) {
                            continue;
                        }

                        $fieldName = trim((string) ($field['name'] ?? ''));
                        $fieldToken = trim((string) ($field['token'] ?? ''));
                        if ($fieldName === '' && $fieldToken === '') {
                            continue;
                        }

                        $fields[] = [
                            'name' => $fieldName,
                            'token' => $fieldToken,
                            'type' => trim((string) ($field['type'] ?? 'text')),
                        ];
                    }
                }

                $sections[] = [
                    'name' => trim((string) ($section['name'] ?? '')),
                    'token' => trim((string) ($section['token'] ?? '')),
                    'fields' => $fields,
                ];
            }

            $cached['loaded'] = true;
            $cached['sections'] = $sections;
            return $cached;
        } catch (\Throwable $e) {
            return $cached;
        }
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>|null
     */
    private static function resolveClinikoCustomFieldDefinition(array $field): ?array
    {
        $settings = self::getClinikoCustomFieldSettings();
        $sections = $settings['sections'];
        $settingsLoaded = !empty($settings['loaded']);

        $fieldName = trim((string) ($field['cliniko_field_name'] ?? $field['label'] ?? $field['key'] ?? ''));
        $sectionName = trim((string) ($field['cliniko_section_name'] ?? ''));
        $fieldToken = trim((string) ($field['cliniko_field_token'] ?? ''));
        $sectionToken = trim((string) ($field['cliniko_section_token'] ?? ''));
        $fieldType = strtolower(trim((string) ($field['cliniko_field_type'] ?? $field['type'] ?? 'text')));

        $buildResult = static function (array $section, array $candidateField) use ($fieldName, $fieldType): array {
            $resolvedFieldType = strtolower(trim((string) ($candidateField['type'] ?? $fieldType)));
            if ($resolvedFieldType === '') {
                $resolvedFieldType = $fieldType;
            }

            return [
                'cliniko_section_name' => trim((string) ($section['name'] ?? '')),
                'cliniko_section_token' => trim((string) ($section['token'] ?? '')),
                'cliniko_field_name' => trim((string) ($candidateField['name'] ?? $fieldName)),
                'cliniko_field_token' => trim((string) ($candidateField['token'] ?? '')),
                'cliniko_field_type' => $resolvedFieldType,
            ];
        };

        if (!$settingsLoaded) {
            if ($fieldToken === '') {
                return null;
            }

            return [
                'cliniko_section_name' => $sectionName,
                'cliniko_section_token' => $sectionToken,
                'cliniko_field_name' => $fieldName,
                'cliniko_field_token' => $fieldToken,
                'cliniko_field_type' => $fieldType,
            ];
        }

        $normalizedFieldName = self::normalizeLookupKey($fieldName);
        $normalizedFieldToken = self::normalizeLookupKey($fieldToken);
        $normalizedSectionName = self::normalizeLookupKey($sectionName);
        $normalizedSectionToken = self::normalizeLookupKey($sectionToken);

        if ($normalizedFieldToken !== '') {
            foreach ($sections as $section) {
                if (!empty($section['archived'])) {
                    continue;
                }

                foreach ($section['fields'] as $candidateField) {
                    if (!empty($candidateField['archived'])) {
                        continue;
                    }

                    if (self::normalizeLookupKey((string) ($candidateField['token'] ?? '')) === $normalizedFieldToken) {
                        return $buildResult($section, $candidateField);
                    }
                }
            }

            return null;
        }

        $sectionCandidates = [];
        if ($normalizedSectionToken !== '' || $normalizedSectionName !== '') {
            foreach ($sections as $section) {
                if (!empty($section['archived'])) {
                    continue;
                }

                $candidateToken = self::normalizeLookupKey((string) ($section['token'] ?? ''));
                $candidateName = self::normalizeLookupKey((string) ($section['name'] ?? ''));
                if ($normalizedSectionToken !== '' && $candidateToken === $normalizedSectionToken) {
                    $sectionCandidates[] = $section;
                    continue;
                }

                if ($normalizedSectionName !== '' && $candidateName === $normalizedSectionName) {
                    $sectionCandidates[] = $section;
                }
            }
        }

        $searchSections = !empty($sectionCandidates) ? $sectionCandidates : $sections;
        $matches = [];
        if ($normalizedFieldName !== '') {
            foreach ($searchSections as $section) {
                if (!empty($section['archived'])) {
                    continue;
                }

                foreach ($section['fields'] as $candidateField) {
                    if (!empty($candidateField['archived'])) {
                        continue;
                    }

                    if (self::normalizeLookupKey((string) ($candidateField['name'] ?? '')) !== $normalizedFieldName) {
                        continue;
                    }

                    $matches[] = $buildResult($section, $candidateField);
                }
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @param mixed $customFields
     * @return array<int,array<string,mixed>>
     */
    private static function extractExistingSections($customFields): array
    {
        if (!is_array($customFields)) {
            return [];
        }

        $sections = $customFields['sections'] ?? null;
        if (!is_array($sections)) {
            return [];
        }

        return $sections;
    }

    /**
     * @param array<int,mixed> $sections
     * @return array<string,array{name:string,token?:string,fields:array<string,array<string,mixed>>,archived?:bool}>
     */
    private static function normalizeSections(array $sections): array
    {
        $out = [];

        foreach ($sections as $index => $section) {
            if (!is_array($section)) {
                continue;
            }

            $name = trim((string) ($section['name'] ?? ''));
            $token = trim((string) ($section['token'] ?? ''));
            $key = $token !== '' ? 'token:' . strtolower($token) : 'name:' . strtolower($name !== '' ? $name : self::DEFAULT_SECTION_NAME . ':' . (string) $index);

            if (!isset($out[$key])) {
                $out[$key] = [
                    'name' => $name !== '' ? $name : self::DEFAULT_SECTION_NAME,
                    'fields' => [],
                ];

                if ($token !== '') {
                    $out[$key]['token'] = $token;
                }

                if (array_key_exists('archived', $section)) {
                    $out[$key]['archived'] = (bool) $section['archived'];
                }
            }

            $fields = $section['fields'] ?? [];
            if (!is_array($fields)) {
                continue;
            }

            foreach ($fields as $fieldIndex => $field) {
                if (!is_array($field)) {
                    continue;
                }

                $fieldName = trim((string) ($field['name'] ?? ''));
                $fieldToken = trim((string) ($field['token'] ?? ''));
                $fieldKey = $fieldToken !== '' ? 'token:' . strtolower($fieldToken) : 'name:' . strtolower($fieldName !== '' ? $fieldName : 'field:' . (string) $fieldIndex);

                $entry = [
                    'name' => $fieldName !== '' ? $fieldName : $fieldKey,
                    'type' => trim((string) ($field['type'] ?? 'text')),
                    'value' => self::toStringValue($field['value'] ?? ''),
                ];

                if ($fieldToken !== '') {
                    $entry['token'] = $fieldToken;
                }

                if (array_key_exists('archived', $field)) {
                    $entry['archived'] = (bool) $field['archived'];
                }

                $out[$key]['fields'][$fieldKey] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $patient
     * @param array<int,array<string,mixed>> $definitions
     * @return array<string,array{name:string,token?:string,fields:array<string,array<string,mixed>>,archived?:bool}>
     */
    private static function buildSectionsFromDefinitions(array $patient, array $definitions): array
    {
        $sections = [];

        foreach ($definitions as $field) {
            $resolved = self::resolveClinikoCustomFieldDefinition($field);
            if ($resolved === null) {
                continue;
            }

            $fieldToken = trim((string) ($resolved['cliniko_field_token'] ?? ''));
            $sectionToken = trim((string) ($resolved['cliniko_section_token'] ?? ''));
            $fieldName = trim((string) ($resolved['cliniko_field_name'] ?? ''));
            $sectionName = trim((string) ($resolved['cliniko_section_name'] ?? ''));
            $fieldType = trim((string) ($resolved['cliniko_field_type'] ?? $field['cliniko_field_type'] ?? $field['type'] ?? 'text'));

            if ($fieldToken === '' || $sectionToken === '') {
                continue;
            }

            $value = self::getNestedValue($patient, (string) ($field['path'] ?? ''));
            $formattedValue = self::formatCustomFieldValue($value);
            if ($formattedValue === null) {
                continue;
            }

            $sectionKey = 'token:' . strtolower($sectionToken);

            if (!isset($sections[$sectionKey])) {
                $sections[$sectionKey] = [
                    'name' => $sectionName !== '' ? $sectionName : self::DEFAULT_SECTION_NAME,
                    'fields' => [],
                ];

                $sections[$sectionKey]['token'] = $sectionToken;
            }

            $fieldKey = 'token:' . strtolower($fieldToken);

            $sections[$sectionKey]['fields'][$fieldKey] = [
                'name' => $fieldName !== '' ? $fieldName : (string) ($field['label'] ?? $field['key'] ?? $field['path'] ?? 'Field'),
                'type' => in_array(strtolower($fieldType), self::ALLOWED_FIELD_TYPES, true) ? strtolower($fieldType) : 'text',
                'value' => $formattedValue,
                'token' => $fieldToken,
            ];
        }

        return $sections;
    }

    /**
     * @param mixed $value
     */
    private static function formatCustomFieldValue($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : null;
        }

        if (is_array($value)) {
            $parts = self::flattenValueArray($value);
            return empty($parts) ? null : implode(', ', $parts);
        }

        if (is_object($value)) {
            if (method_exists($value, '__toString')) {
                $candidate = trim((string) $value);
                return $candidate !== '' ? $candidate : null;
            }

            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
            $candidate = is_string($encoded) ? trim($encoded) : '';
            return $candidate !== '' ? $candidate : null;
        }

        $candidate = trim((string) $value);
        return $candidate !== '' ? $candidate : null;
    }

    /**
     * @param array<string,array{name:string,token?:string,fields:array<string,array<string,mixed>>,archived?:bool}> $sections
     * @return array<string,mixed>
     */
    private static function sectionsMapToPayload(array $sections): array
    {
        $payload = ['sections' => []];

        foreach ($sections as $section) {
            $fields = array_values($section['fields']);
            if (empty($fields)) {
                continue;
            }

            $sectionPayload = [
                'name' => (string) $section['name'],
                'fields' => $fields,
            ];

            if (!empty($section['token'])) {
                $sectionPayload['token'] = (string) $section['token'];
            }

            if (array_key_exists('archived', $section)) {
                $sectionPayload['archived'] = (bool) $section['archived'];
            }

            $payload['sections'][] = $sectionPayload;
        }

        return $payload;
    }
}
