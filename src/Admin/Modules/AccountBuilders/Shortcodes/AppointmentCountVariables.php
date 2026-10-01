<?php

namespace App\Admin\Modules\AccountBuilders\Shortcodes;

use App\Service\AppointmentCountService;

if (!defined('ABSPATH')) {
    exit;
}

final class AppointmentCountVariables
{
    /** @var array<string,'total'|'upcoming'|'completed'> */
    private const SHORTCODES = [
        'cliniko_patient_appointments_total' => 'total',
        'cliniko_patient_appointments_upcoming' => 'upcoming',
        'cliniko_patient_appointments_completed' => 'completed',
    ];

    public static function init(): void
    {
        foreach (self::SHORTCODES as $shortcode => $type) {
            add_shortcode($shortcode, static fn($attributes = []): string => self::render($type, $attributes));
        }
        add_shortcode('cliniko_patient_appointment_count', [self::class, 'renderGeneric']);
    }

    /**
     * Usage: [cliniko_patient_appointment_count type="upcoming"]
     *
     * @param array<string,mixed>|string $attributes
     */
    public static function renderGeneric($attributes = []): string
    {
        $attributes = shortcode_atts([
            'type' => 'total',
            'fallback' => '0',
        ], is_array($attributes) ? $attributes : [], 'cliniko_patient_appointment_count');
        $type = sanitize_key((string) $attributes['type']);
        if (!in_array($type, ['total', 'upcoming', 'completed'], true)) {
            $type = 'total';
        }

        return self::render($type, ['fallback' => (string) $attributes['fallback']]);
    }

    /**
     * @param 'total'|'upcoming'|'completed' $type
     * @param array<string,mixed>|string $attributes
     */
    private static function render(string $type, $attributes = []): string
    {
        $attributes = shortcode_atts(
            ['fallback' => '0'],
            is_array($attributes) ? $attributes : []
        );
        $fallback = sanitize_text_field((string) $attributes['fallback']);
        if (!is_user_logged_in()) {
            return esc_html($fallback);
        }

        try {
            $counts = (new AppointmentCountService())->forAuthenticatedPatient();
            return esc_html((string) $counts[$type]);
        } catch (\Throwable $exception) {
            error_log('Cliniko appointment count shortcode failed: ' . $exception->getMessage());
            return esc_html($fallback);
        }
    }
}
