<?php

namespace App\Service;

use App\Admin\Modules\AccountBuilders\Forms\ShortcodeFormInputRules;
use App\Model\PatientFormTemplate;
use WP_Error;

if (!defined('ABSPATH')) exit;

/** Shared saved configuration and rules; no HTML or transport authentication. */
final class PatientBookingConfiguration
{
    public const OPTION = 'wp_cliniko_patient_booking_forms';

    public static function all(): array
    {
        $forms = get_option(self::OPTION, []);
        return is_array($forms) ? $forms : [];
    }

    public static function patients(): array
    {
        return array_filter(self::all(), static fn($form): bool => is_array($form) && ($form['flow_type'] ?? 'web') === 'web' && ($form['booking_mode'] ?? 'guest') === 'patient');
    }

    /** Common booking settings, independent of web presentation and API publication. */
    public static function parameters(array $input)
    {
        $result = [];
        foreach (['name', 'appointment_type_id', 'patient_form_template_id', 'gateway', 'scheduling_mode'] as $field) {
            if (isset($input[$field]) && !is_scalar($input[$field])) return new WP_Error('invalid_booking_configuration', 'Booking settings must be simple values.');
            $result[$field] = trim((string) ($input[$field] ?? ''));
        }
        $result['name'] = sanitize_text_field($result['name']);
        if ($result['name'] === '') return new WP_Error('invalid_booking_configuration', 'Give this booking flow a name.');
        foreach (['appointment_type_id', 'patient_form_template_id'] as $field) {
            if (!preg_match('/^[1-9][0-9]*$/D', $result[$field])) return new WP_Error('invalid_booking_configuration', 'Choose an appointment type and form template with valid IDs.');
        }
        if (!in_array($result['gateway'], ['stripe', 'tyrohealth', 'none'], true)) return new WP_Error('invalid_booking_configuration', 'Choose Stripe, Tyro Health or no payment.');
        if (!in_array($result['scheduling_mode'], ['manual', 'next_available'], true)) return new WP_Error('invalid_booking_configuration', 'Choose how an appointment time is selected.');
        return $result;
    }

    public static function schedulingMode(array $form): string
    {
        // API definitions have scheduling policy, not calendar visibility settings.
        if (($form['flow_type'] ?? 'web') === 'api') return (string) ($form['scheduling_mode'] ?? 'manual');
        if (($form['show_calendar'] ?? 'yes') === 'no') return 'next_available';
        return 'manual';
    }

    public static function ruleCapabilities(string $type): array
    {
        $type = strtolower($type);
        $textarea = in_array($type, ['textarea', 'paragraph', 'long_text', 'longtext', 'text_area', 'multiline'], true);
        return ['text' => true, 'date' => !$textarea && $type !== 'email', 'select' => $type !== 'email', 'limit' => true, 'format' => !in_array($type, ['email', 'date'], true)];
    }

    public static function questionRules(?PatientFormTemplate $template, array $raw): array
    {
        $rules = [];
        if (!$template) return $rules;
        foreach ($template->getSections() as $sectionIndex => $section) {
            foreach ($section->questions as $questionIndex => $question) {
                $type = strtolower((string) $question->type);
                if (in_array($type, ['checkboxes', 'radiobuttons', 'signature'], true)) continue;
                $key = 'question_' . $sectionIndex . '_' . $questionIndex;
                $config = is_array($raw[$key] ?? null) ? $raw[$key] : [];
                if ($type === 'date' && !isset($config['input_type'])) $config['input_type'] = 'date';
                $rules[$key] = ShortcodeFormInputRules::normalise($config, self::ruleCapabilities($type));
            }
        }
        return $rules;
    }
}
