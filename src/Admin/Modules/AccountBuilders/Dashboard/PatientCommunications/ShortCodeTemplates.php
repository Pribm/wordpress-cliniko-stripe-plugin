<?php

namespace App\Admin\Modules\AccountBuilders\Dashboard\PatientCommunications;

if (!defined('ABSPATH')) exit;

final class ShortCodeTemplates
{
    private const OPTION_KEY = 'wp_cliniko_patient_communication_modules';
    private const SHORTCODE = 'cliniko_patient_communications_module';

    public static function init(): void
    {
        add_shortcode(self::SHORTCODE, [self::class, 'render']);
    }

    /** @param array<string,mixed> $attributes */
    public static function render(array $attributes): string
    {
        if (!is_user_logged_in()) return '<p class="cliniko-dashboard-module-login-required">Please log in to view your communications.</p>';
        $id = sanitize_key((string) ($attributes['id'] ?? ''));
        $modules = get_option(self::OPTION_KEY, []);
        $module = is_array($modules) && is_array($modules[$id] ?? null) ? $modules[$id] : null;
        if ($module === null) return current_user_can('manage_options') ? '<p>Patient communication module not found.</p>' : '';

        $css = __DIR__ . '/ShortCodeTemplates/assets/patient-communications.css';
        $js = __DIR__ . '/ShortCodeTemplates/assets/patient-communications.js';
        wp_enqueue_style('cliniko-patient-communications', plugins_url('ShortCodeTemplates/assets/patient-communications.css', __FILE__), ['cliniko-shortcode-components'], is_file($css) ? (string) filemtime($css) : null);
        wp_enqueue_script('cliniko-patient-communications', plugins_url('ShortCodeTemplates/assets/patient-communications.js', __FILE__), [], is_file($js) ? (string) filemtime($js) : null, true);
        $customCss = trim((string) ($module['custom_css'] ?? ''));
        if ($customCss !== '') {
            wp_add_inline_style('cliniko-patient-communications', $customCss);
        }

        ob_start();
        try {
            $endpoint = rest_url('v2/patient/me/communications');
            $nonce = wp_create_nonce('wp_rest');
            include __DIR__ . '/ShortCodeTemplates/patient-communications.phtml';
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            error_log('Cliniko patient communication shortcode failed: ' . $exception->getMessage());
            return '<p class="cliniko-dashboard-module__message is-error">Communications are temporarily unavailable.</p>';
        }
    }
}
