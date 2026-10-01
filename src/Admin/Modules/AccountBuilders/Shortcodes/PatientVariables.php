<?php

namespace App\Admin\Modules\AccountBuilders\Shortcodes;

use App\Service\PatientFieldRegistry;
use App\Support\Auth;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientVariables
{
    /** @var array<string,string> */
    private const SHORTCODES = [
        'cliniko_patient_first_name' => 'first_name',
        'cliniko_patient_last_name' => 'last_name',
        'cliniko_patient_full_name' => 'full_name',
        'cliniko_patient_preferred_name' => 'preferred_first_name',
        'cliniko_patient_email' => 'email',
        'cliniko_patient_phone' => 'phone',
    ];

    public static function init(): void
    {
        foreach (self::SHORTCODES as $shortcode => $field) {
            add_shortcode($shortcode, static fn($attributes = []): string => self::renderField($field, $attributes));
        }
        add_shortcode('cliniko_patient_value', [self::class, 'renderGeneric']);
    }

    /**
     * Usage: [cliniko_patient_value field="first_name" fallback="Patient"]
     *
     * @param array<string,mixed>|string $attributes
     */
    public static function renderGeneric($attributes = []): string
    {
        $attributes = shortcode_atts([
            'field' => '',
            'fallback' => '',
        ], is_array($attributes) ? $attributes : [], 'cliniko_patient_value');

        return self::renderField(
            sanitize_key((string) $attributes['field']),
            ['fallback' => (string) $attributes['fallback']]
        );
    }

    /**
     * @param array<string,mixed>|string $attributes
     */
    private static function renderField(string $field, $attributes = []): string
    {
        $attributes = shortcode_atts(
            ['fallback' => ''],
            is_array($attributes) ? $attributes : []
        );
        $fallback = sanitize_text_field((string) $attributes['fallback']);
        if (!is_user_logged_in() || !self::isAllowed($field)) {
            return esc_html($fallback);
        }

        try {
            $patient = Auth::patientData();
            if ($patient === null) {
                return esc_html($fallback);
            }

            $values = array_merge(
                $patient,
                is_array($patient['custom_field_values'] ?? null) ? $patient['custom_field_values'] : []
            );
            $values['full_name'] = trim(implode(' ', array_filter([
                (string) ($patient['first_name'] ?? ''),
                (string) ($patient['last_name'] ?? ''),
            ])));

            $value = self::stringValue($values[$field] ?? '');
            return esc_html($value !== '' ? $value : $fallback);
        } catch (\Throwable $exception) {
            error_log('Cliniko patient variable shortcode failed: ' . $exception->getMessage());
            return esc_html($fallback);
        }
    }

    private static function isAllowed(string $field): bool
    {
        return $field === 'full_name'
            || array_key_exists($field, PatientFieldRegistry::editable())
            || (str_starts_with($field, 'custom_') && array_key_exists($field, PatientFieldRegistry::available()));
    }

    private static function stringValue($value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        if (!is_array($value)) {
            return '';
        }

        $parts = array_map(static function ($item): string {
            if (is_scalar($item)) {
                return trim((string) $item);
            }
            if (is_array($item)) {
                return trim((string) ($item['number'] ?? $item['value'] ?? ''));
            }
            return '';
        }, $value);
        return implode(', ', array_filter($parts, static fn(string $part): bool => $part !== ''));
    }
}
