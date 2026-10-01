<?php

namespace App\Admin\Modules\AccountBuilders\Dashboard\PatientAttachments;

use App\Admin\Modules\AccountBuilders\Forms\ShortcodeFormInputRules;

if (!defined('ABSPATH')) exit;

/** Owns the patient-attachments shortcode and its presentation assets. */
final class ShortCodeTemplates
{
    private const OPTION_KEY = 'wp_cliniko_patient_attachment_modules';
    private const SHORTCODE = 'cliniko_patient_attachments_module';
    private const LIST_SHORTCODE = 'cliniko_patient_attachments_list';
    private const UPLOAD_SHORTCODE = 'cliniko_patient_attachments_upload';

    public static function init(): void
    {
        add_shortcode(self::SHORTCODE, [self::class, 'render']);
        add_shortcode(self::LIST_SHORTCODE, static fn(array $attributes): string => self::renderMode($attributes, 'list'));
        add_shortcode(self::UPLOAD_SHORTCODE, static fn(array $attributes): string => self::renderMode($attributes, 'upload'));
    }

    /** @param array<string,mixed> $attributes */
    public static function render(array $attributes): string
    {
        return self::renderMode($attributes, 'combined');
    }

    /** @param array<string,mixed> $attributes */
    private static function renderMode(array $attributes, string $mode): string
    {
        if (!is_user_logged_in()) return '<p class="cliniko-dashboard-module-login-required">Please log in to manage your documents.</p>';
        $id = sanitize_key((string) ($attributes['id'] ?? ''));
        $modules = get_option(self::OPTION_KEY, []);
        $module = is_array($modules) && is_array($modules[$id] ?? null) ? $modules[$id] : null;
        if ($module === null) return current_user_can('manage_options') ? '<p>Patient attachment module not found.</p>' : '';

        $css = __DIR__ . '/ShortCodeTemplates/assets/patient-attachments.css';
        $jsName = $mode === 'list' ? 'patient-attachments-list.js' : ($mode === 'upload' ? 'patient-attachments-upload.js' : 'patient-attachments.js');
        $js = __DIR__ . '/ShortCodeTemplates/assets/' . $jsName;
        ShortcodeFormInputRules::enqueueFrontendAssets();
        wp_enqueue_style('cliniko-patient-attachments', plugins_url('ShortCodeTemplates/assets/patient-attachments.css', __FILE__), ['cliniko-shortcode-components', 'cliniko-shortcode-form-input-rules'], is_file($css) ? (string) filemtime($css) : null);
        wp_enqueue_script('cliniko-patient-attachments-' . $mode, plugins_url('ShortCodeTemplates/assets/' . $jsName, __FILE__), ['cliniko-shortcode-form-input-rules'], is_file($js) ? (string) filemtime($js) : null, true);

        ob_start();
        try {
            extract([
                'module' => $module,
                'endpoint' => rest_url('v2/patient/me/attachments'),
                'presignEndpoint' => rest_url('v2/patient/me/attachments/presign'),
                'nonce' => wp_create_nonce('wp_rest'),
                'descriptionRules' => ShortcodeFormInputRules::normalise(
                    is_array($module['description_rules'] ?? null) ? $module['description_rules'] : [],
                    ['text' => true, 'date' => false, 'select' => true, 'limit' => true, 'format' => true]
                ),
            ], EXTR_SKIP);
            if ($mode === 'list') {
                include __DIR__ . '/ShortCodeTemplates/patient-attachments-list.phtml';
            } elseif ($mode === 'upload') {
                include __DIR__ . '/ShortCodeTemplates/patient-attachments-upload.phtml';
            } else {
                include __DIR__ . '/ShortCodeTemplates/patient-attachments.phtml';
            }
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            error_log('Cliniko patient attachment shortcode failed: ' . $exception->getMessage());
            return '<p class="cliniko-dashboard-module__message is-error">Documents are temporarily unavailable.</p>';
        }
    }
}
