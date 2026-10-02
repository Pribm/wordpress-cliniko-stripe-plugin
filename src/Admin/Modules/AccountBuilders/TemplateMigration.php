<?php

namespace App\Admin\Modules\AccountBuilders;

if (!defined('ABSPATH')) {
    exit;
}

final class TemplateMigration
{
    private const FORMAT = 'wp-cliniko-template-bundle';
    private const SCHEMA_VERSION = 1;
    private const MAX_IMPORT_BYTES = 10485760;
    private const EXPORT_ACTION = 'wp_cliniko_export_templates';
    private const IMPORT_ACTION = 'wp_cliniko_import_templates';
    private const EXPORT_NONCE = 'export_wp_cliniko_templates';
    private const IMPORT_NONCE = 'import_wp_cliniko_templates';
    private const COMPONENT_STYLES_OPTION = 'wp_cliniko_component_styles';

    public static function init(): void
    {
        add_action('admin_post_' . self::EXPORT_ACTION, [self::class, 'export']);
        add_action('admin_post_' . self::IMPORT_ACTION, [self::class, 'import']);
    }

    /**
     * @return array<string,array{label:string,description:string,options:array<string,string>}>
     */
    public static function catalog(): array
    {
        return [
            'patient_forms' => [
                'label' => 'Patient forms',
                'description' => 'Profile forms, completed-form lists, and Cliniko template forms.',
                'options' => [
                    'wp_cliniko_patient_account_forms' => 'collection',
                    'wp_cliniko_patient_form_history_shortcodes' => 'collection',
                    'wp_cliniko_patient_form_template_shortcodes' => 'collection',
                ],
            ],
            'onboarding' => [
                'label' => 'Onboarding',
                'description' => 'Patient onboarding flows and their conditional fields.',
                'options' => [
                    'wp_cliniko_patient_onboardings' => 'collection',
                ],
            ],
            'booking_forms' => [
                'label' => 'Booking forms',
                'description' => 'Guest and patient booking forms plus booking aliases.',
                'options' => [
                    'wp_cliniko_patient_booking_forms' => 'collection',
                    'wp_cliniko_booking_aliases' => 'collection',
                ],
            ],
            'dashboard_modules' => [
                'label' => 'Patient dashboard',
                'description' => 'Appointment, patient-details, attachment, and communication modules.',
                'options' => [
                    'wp_cliniko_dashboard_modules' => 'collection',
                    'wp_cliniko_dashboard_patient_details' => 'collection',
                    'wp_cliniko_patient_attachment_modules' => 'collection',
                    'wp_cliniko_patient_communication_modules' => 'collection',
                ],
            ],
            'component_styles' => [
                'label' => 'Component styles',
                'description' => 'Shared colours, typography, forms, calendars, steps, lists, and messages.',
                'options' => [
                    self::COMPONENT_STYLES_OPTION => 'recursive',
                ],
            ],
            'redirects' => [
                'label' => 'Redirects',
                'description' => 'Shortcode-specific and default success destinations.',
                'options' => [
                    'wp_cliniko_shortcode_redirects' => 'recursive',
                    'wp_cliniko_shortcode_default_redirects' => 'fields',
                    'wp_cliniko_shortcode_default_redirect_custom' => 'fields',
                ],
            ],
            'email_templates' => [
                'label' => 'Email templates',
                'description' => 'Patient verification and account-closure email content and branding.',
                'options' => [
                    'wp_cliniko_patient_verification_email_template' => 'fields',
                    'wp_cliniko_patient_account_closure_request_email_template' => 'fields',
                    'wp_cliniko_patient_account_closure_receipt_email_template' => 'fields',
                ],
            ],
            'custom_code' => [
                'label' => 'Custom code',
                'description' => 'Template Builder HTML, CSS, and JavaScript bundles.',
                'options' => [
                    'wp_cliniko_custom_code_bundles' => 'collection',
                ],
            ],
        ];
    }

    public static function renderPage(): void
    {
        self::requireAdministrator();
        $catalog = self::catalog();
        $error = sanitize_key((string) ($_GET['migration_error'] ?? ''));
        $errors = [
            'empty_export' => 'Select at least one template group to export.',
            'invalid_file' => 'Choose a readable JSON export file.',
            'invalid_extension' => 'The import file must use the .json extension.',
            'too_large' => 'The import file is larger than 10 MB.',
            'invalid_json' => 'The selected file does not contain valid JSON.',
            'invalid_bundle' => 'The selected file is not a supported Cliniko Template Builder export.',
            'empty_bundle' => 'The export does not contain any supported template data.',
            'replace_confirmation' => 'Confirm that imported groups may replace their existing destination data.',
        ];
        ?>
        <div class="wrap cliniko-template-builder-page cliniko-template-migration">
            <h1>Import / Export Templates</h1>
            <p>Move Template Builder configurations between WordPress sites without exporting credentials, patient records, or account settings.</p>

            <?php if (isset($_GET['migration_imported'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf(
                    'Imported %d template groups using %s mode.',
                    max(0, (int) $_GET['migration_imported']),
                    sanitize_key((string) ($_GET['migration_mode'] ?? 'merge')) === 'replace' ? 'replace' : 'merge'
                )); ?></p></div>
            <?php elseif ($error !== '' && isset($errors[$error])) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($errors[$error]); ?></p></div>
            <?php endif; ?>

            <div class="cliniko-template-migration__grid">
                <section class="card cliniko-template-migration__card">
                    <h2>Export templates</h2>
                    <p>Download a versioned JSON bundle. Destination-specific URLs remain unchanged and should be reviewed after import.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::EXPORT_ACTION); ?>">
                        <?php wp_nonce_field(self::EXPORT_NONCE); ?>
                        <fieldset>
                            <legend class="screen-reader-text">Template groups to export</legend>
                            <?php foreach ($catalog as $key => $group) : ?>
                                <label class="cliniko-template-migration__choice">
                                    <input type="checkbox" name="sections[]" value="<?php echo esc_attr($key); ?>" checked>
                                    <span><strong><?php echo esc_html($group['label']); ?></strong><small><?php echo esc_html($group['description']); ?></small></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">Locally uploaded font files are not embedded in the JSON bundle.</p>
                        <p><button class="button button-primary" type="submit">Download export</button></p>
                    </form>
                </section>

                <section class="card cliniko-template-migration__card">
                    <h2>Import templates</h2>
                    <p>Upload a JSON bundle created by this screen. Only recognised Template Builder groups can be written.</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::IMPORT_ACTION); ?>">
                        <?php wp_nonce_field(self::IMPORT_NONCE); ?>
                        <p><input type="file" name="template_file" accept="application/json,.json" required></p>
                        <fieldset>
                            <legend><strong>Import behavior</strong></legend>
                            <label class="cliniko-template-migration__choice">
                                <input type="radio" name="import_mode" value="merge" checked>
                                <span><strong>Merge</strong><small>Keep destination templates; imported items replace only matching IDs or fields.</small></span>
                            </label>
                            <label class="cliniko-template-migration__choice">
                                <input type="radio" name="import_mode" value="replace">
                                <span><strong>Replace imported groups</strong><small>Remove existing data for every group included in the file, then use the imported data.</small></span>
                            </label>
                        </fieldset>
                        <label class="cliniko-template-migration__confirm">
                            <input type="checkbox" name="confirm_replace" value="1">
                            I understand that replace mode can remove destination templates.
                        </label>
                        <p class="description">The import does not copy media files or rewrite site URLs, page IDs, Cliniko IDs, or Elementor IDs.</p>
                        <p><button class="button button-primary" type="submit">Import templates</button></p>
                    </form>
                </section>
            </div>
        </div>
        <?php
    }

    public static function export(): void
    {
        self::requireAdministrator();
        check_admin_referer(self::EXPORT_NONCE);
        $posted = isset($_POST['sections']) && is_array($_POST['sections'])
            ? wp_unslash($_POST['sections'])
            : [];
        $selected = array_values(array_filter(array_map(
            static fn($value): string => sanitize_key((string) $value),
            $posted
        )));
        if ($selected === []) {
            self::redirectError('empty_export');
        }

        $payload = self::buildExportBundle($selected);
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            self::redirectError('invalid_bundle');
        }

        $filename = 'cliniko-template-builder-' . gmdate('Y-m-d-His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;
    }

    public static function import(): void
    {
        self::requireAdministrator();
        check_admin_referer(self::IMPORT_NONCE);
        $mode = sanitize_key((string) ($_POST['import_mode'] ?? 'merge')) === 'replace' ? 'replace' : 'merge';
        if ($mode === 'replace' && (string) ($_POST['confirm_replace'] ?? '') !== '1') {
            self::redirectError('replace_confirmation');
        }

        $file = $_FILES['template_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            self::redirectError('invalid_file');
        }
        $filename = sanitize_file_name((string) ($file['name'] ?? ''));
        if (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== 'json') {
            self::redirectError('invalid_extension');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_IMPORT_BYTES) {
            self::redirectError($size > self::MAX_IMPORT_BYTES ? 'too_large' : 'invalid_file');
        }
        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        if ($temporaryPath === '' || !is_uploaded_file($temporaryPath) || !is_readable($temporaryPath)) {
            self::redirectError('invalid_file');
        }
        $json = file_get_contents($temporaryPath);
        if (!is_string($json) || $json === '') {
            self::redirectError('invalid_file');
        }

        try {
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            self::redirectError('invalid_json');
        }
        if (!is_array($payload)) {
            self::redirectError('invalid_bundle');
        }

        try {
            $result = self::importBundle($payload, $mode);
        } catch (\InvalidArgumentException $exception) {
            self::redirectError($exception->getMessage() === 'empty_bundle' ? 'empty_bundle' : 'invalid_bundle');
        }

        wp_safe_redirect(AccountBuilders::url(AccountBuilders::TAB_MIGRATION, [
            'migration_imported' => $result['sections'],
            'migration_mode' => $mode,
        ]));
        exit;
    }

    /** @param array<int,string> $selected @return array<string,mixed> */
    public static function buildExportBundle(array $selected = []): array
    {
        $catalog = self::catalog();
        if ($selected === []) {
            $selected = array_keys($catalog);
        }
        $sections = [];
        foreach (array_unique($selected) as $sectionKey) {
            if (!isset($catalog[$sectionKey])) {
                continue;
            }
            $section = [];
            foreach ($catalog[$sectionKey]['options'] as $option => $strategy) {
                $value = get_option($option, null);
                if (!is_array($value)) {
                    continue;
                }
                $section[$option] = self::prepareExportValue($option, $value);
            }
            $sections[$sectionKey] = $section;
        }

        return [
            'format' => self::FORMAT,
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => defined('WP_CLINIKO_PLUGIN_VERSION') ? (string) WP_CLINIKO_PLUGIN_VERSION : '',
            'exported_at' => gmdate('c'),
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{sections:int,options:int,items:int}
     */
    public static function importBundle(array $payload, string $mode = 'merge'): array
    {
        if (
            ($payload['format'] ?? null) !== self::FORMAT
            || (int) ($payload['schema_version'] ?? 0) !== self::SCHEMA_VERSION
            || !is_array($payload['sections'] ?? null)
        ) {
            throw new \InvalidArgumentException('invalid_bundle');
        }
        if (!in_array($mode, ['merge', 'replace'], true)) {
            throw new \InvalidArgumentException('invalid_bundle');
        }

        $catalog = self::catalog();
        $sectionCount = 0;
        $optionCount = 0;
        $itemCount = 0;
        foreach ($payload['sections'] as $sectionKey => $sectionData) {
            if (!is_string($sectionKey) || !isset($catalog[$sectionKey]) || !is_array($sectionData)) {
                continue;
            }
            $sectionImported = false;
            foreach ($catalog[$sectionKey]['options'] as $option => $strategy) {
                if (!array_key_exists($option, $sectionData) || !is_array($sectionData[$option])) {
                    continue;
                }
                $incoming = self::prepareExportValue(
                    $option,
                    self::validateTree($sectionData[$option])
                );
                $value = $incoming;
                if ($mode === 'merge') {
                    $existing = get_option($option, []);
                    $existing = is_array($existing) ? $existing : [];
                    $value = $strategy === 'recursive'
                        ? array_replace_recursive($existing, $incoming)
                        : array_replace($existing, $incoming);
                }
                update_option($option, $value, false);
                $optionCount++;
                $itemCount += count($incoming);
                $sectionImported = true;
            }
            if ($sectionImported) {
                $sectionCount++;
            }
        }
        if ($sectionCount === 0) {
            throw new \InvalidArgumentException('empty_bundle');
        }

        return ['sections' => $sectionCount, 'options' => $optionCount, 'items' => $itemCount];
    }

    /** @param array<int|string,mixed> $value @return array<int|string,mixed> */
    private static function prepareExportValue(string $option, array $value): array
    {
        if (
            $option === self::COMPONENT_STYLES_OPTION
            && is_array($value['foundation'] ?? null)
        ) {
            unset($value['foundation']['installed_fonts']);
        }
        return $value;
    }

    /** @param array<int|string,mixed> $value @return array<int|string,mixed> */
    private static function validateTree(array $value, int $depth = 0): array
    {
        if ($depth > 32) {
            throw new \InvalidArgumentException('invalid_bundle');
        }
        $validated = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $validated[$key] = self::validateTree($item, $depth + 1);
                continue;
            }
            if (!is_scalar($item) && $item !== null) {
                throw new \InvalidArgumentException('invalid_bundle');
            }
            $validated[$key] = $item;
        }
        return $validated;
    }

    private static function requireAdministrator(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
    }

    /** @return never */
    private static function redirectError(string $error): void
    {
        wp_safe_redirect(AccountBuilders::url(AccountBuilders::TAB_MIGRATION, [
            'migration_error' => sanitize_key($error),
        ]));
        exit;
    }
}
