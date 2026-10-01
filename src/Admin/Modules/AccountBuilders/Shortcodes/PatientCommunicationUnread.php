<?php

namespace App\Admin\Modules\AccountBuilders\Shortcodes;

use App\Model\Communication;
use App\Service\PatientCommunicationReadStateService;
use App\Support\Auth;

if (!defined('ABSPATH')) exit;

/** Provides unread communication totals and a patient-safe link for site navigation. */
final class PatientCommunicationUnread
{
    public static function init(): void
    {
        add_shortcode('cliniko_patient_unread_communications', [self::class, 'renderCount']);
        add_shortcode('cliniko_patient_communications_link', [self::class, 'renderLink']);
        add_action('wp_ajax_wp_cliniko_patient_unread_communications', [self::class, 'ajaxSummary']);
    }

    /** Usage: [cliniko_patient_unread_communications view="total" fallback="0"] */
    public static function renderCount($attributes = []): string
    {
        $attributes = shortcode_atts(['view' => 'total', 'fallback' => '0'], is_array($attributes) ? $attributes : [], 'cliniko_patient_unread_communications');
        $summary = self::summary();
        $view = sanitize_key((string) $attributes['view']);
        if ($summary === null || !in_array($view, ['total', 'general', 'email', 'sms'], true)) return esc_html((string) $attributes['fallback']);
        return esc_html((string) $summary[$view]);
    }

    /** Usage: [cliniko_patient_communications_link url="/messages/" label="Messages"] */
    public static function renderLink($attributes = []): string
    {
        $attributes = shortcode_atts(['url' => '', 'label' => 'Messages', 'class' => ''], is_array($attributes) ? $attributes : [], 'cliniko_patient_communications_link');
        $url = esc_url((string) $attributes['url']);
        $label = sanitize_text_field((string) $attributes['label']) ?: 'Messages';
        if ($url === '') return esc_html($label);

        $summary = self::summary();
        $count = $summary['total'] ?? 0;
        $classes = trim('cliniko-patient-communications-link ' . sanitize_html_class((string) $attributes['class']));
        $badge = $count > 0
            ? ' <span class="cliniko-patient-communications-link__badge" aria-label="' . esc_attr(sprintf('%d unread messages', $count)) . '">' . esc_html((string) $count) . '</span>'
            : '';

        return '<a class="' . esc_attr($classes) . '" href="' . $url . '">' . esc_html($label) . $badge . '</a>';
    }

    /** Supplies the static custom-code navbar without exposing another patient's data. */
    public static function ajaxSummary(): void
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Authentication required.'], 403);
        }

        $summary = self::summary();
        if ($summary === null) {
            $unavailable = Auth::clinikoUnavailable();
            wp_send_json_error([
                'code' => $unavailable ? 'cliniko_connection_unavailable' : 'patient_not_found',
                'message' => $unavailable
                    ? 'Cliniko is temporarily unavailable. Please try again shortly.'
                    : 'Unread communications are temporarily unavailable.',
            ], $unavailable ? 503 : 502);
        }

        wp_send_json_success(['unread' => $summary]);
    }

    /** @return array{general:int,email:int,sms:int,total:int}|null */
    private static function summary(): ?array
    {
        if (!is_user_logged_in() || get_current_user_id() <= 0) return null;
        try {
            $summary = (new PatientCommunicationReadStateService())->unreadSummary(Communication::allForAuthenticatedPatient(), get_current_user_id());
            return [
                'general' => $summary['general'],
                'email' => $summary['email'],
                'sms' => $summary['sms'],
                'total' => $summary['total'],
            ];
        } catch (\Throwable $exception) {
            error_log('Cliniko unread communication shortcode failed: ' . $exception->getMessage());
            return null;
        }
    }
}
