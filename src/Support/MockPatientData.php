<?php

namespace App\Support;

use App\Debug\Settings as DebugSettings;
use App\Service\PatientFieldRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class MockPatientData
{
    /** @return array<string,mixed> */
    public static function make(): array
    {
        $patient = [
            'id' => 'development-john-doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'preferred_first_name' => 'Johnny',
            'email' => 'john.doe@example.test',
            'date_of_birth' => '1985-06-15',
            'medicare' => '0000 00000 0',
            'medicare_reference_number' => '1',
            'gender' => 'Other',
            'phone' => '+61 400 000 000',
            'address_1' => '123 Example Street',
            'address_2' => '',
            'city' => 'Sydney',
            'state' => 'NSW',
            'post_code' => '2000',
            'country' => 'Australia',
            'occupation' => 'Designer',
            'notes' => 'Mock patient for development styling.',
        ];

        $sections = [];
        $flatValues = [];
        foreach (PatientFieldRegistry::available() as $key => $definition) {
            if (empty($definition['custom'])) {
                continue;
            }

            $sectionToken = (string) ($definition['cliniko_section_token'] ?? '');
            $fieldToken = (string) ($definition['cliniko_field_token'] ?? '');
            if ($sectionToken === '' || $fieldToken === '') {
                continue;
            }

            if (!isset($sections[$sectionToken])) {
                $sections[$sectionToken] = [
                    'name' => (string) ($definition['cliniko_section_name'] ?? 'Custom fields'),
                    'token' => $sectionToken,
                    'fields' => [],
                ];
            }

            $type = strtolower((string) ($definition['cliniko_field_type'] ?? 'text'));
            $field = [
                'name' => (string) ($definition['cliniko_field_name'] ?? $definition['label'] ?? $key),
                'type' => $type,
                'token' => $fieldToken,
            ];

            if (in_array($type, ['radiobuttons', 'checkboxes'], true)) {
                $options = [];
                foreach ((array) ($definition['options'] ?? []) as $index => $option) {
                    $name = is_array($option) ? (string) ($option['name'] ?? '') : (string) $option;
                    if ($name === '') {
                        continue;
                    }
                    $options[] = [
                        'name' => $name,
                        'token' => (string) (is_array($option) ? ($option['token'] ?? '') : ''),
                        'selected' => $type === 'radiobuttons' ? $index === 0 : $index < 2,
                    ];
                }
                $field['options'] = $options;
                $flatValues['custom_' . $fieldToken] = array_map(
                    static fn(array $option): string => (string) $option['name'],
                    array_filter($options, static fn(array $option): bool => !empty($option['selected']))
                );
            } else {
                $sample = match ($type) {
                    'date' => '2026-01-15',
                    'paragraph' => 'Example paragraph content for styling.',
                    default => 'Example value',
                };
                $field['value'] = $sample;
                $flatValues['custom_' . $fieldToken] = $sample;
            }

            $sections[$sectionToken]['fields'][] = $field;
        }

        $patient['custom_fields'] = ['sections' => array_values($sections)];
        $patient['custom_field_values'] = $flatValues;
        $patient['patient_phone_numbers'] = [['number' => $patient['phone'], 'phone_type' => 'Mobile']];

        return $patient;
    }

    public static function enabled(): bool
    {
        return DebugSettings::mockPatientEnabled() && current_user_can('manage_options');
    }
}
