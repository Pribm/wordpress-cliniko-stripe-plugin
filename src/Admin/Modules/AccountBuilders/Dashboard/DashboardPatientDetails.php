<?php

namespace App\Admin\Modules\AccountBuilders\Dashboard;

use App\Service\PatientFieldRegistry;
use App\Admin\Modules\AccountBuilders\AccountBuilders;
use App\Support\Auth;
use App\Support\MockPatientData;
use App\Support\Phtml;

if (!defined('ABSPATH')) {
    exit;
}

final class DashboardPatientDetails
{
    private const OPTION_KEY = 'wp_cliniko_dashboard_patient_details';
    private const SHORTCODE = 'cliniko_patient_details_module';

    public static function init(): void
    {
        add_action('admin_post_wp_cliniko_patient_details_module_save', [self::class, 'save']);
        add_action('admin_post_wp_cliniko_patient_details_module_delete', [self::class, 'delete']);
        add_shortcode(self::SHORTCODE, [self::class, 'shortcode']);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $action = sanitize_key((string) ($_GET['action'] ?? 'list'));
        if ($action === 'new' || $action === 'edit') {
            self::renderEditor($action === 'edit' ? sanitize_key((string) ($_GET['id'] ?? '')) : '');
            return;
        }

        $modules = self::modules();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Patient Details Modules</h1>
            <a class="page-title-action" href="<?php echo esc_url(self::url(['action' => 'new'])); ?>">Add New</a>
            <p>Create display-only patient detail sections. Each module has an independent shortcode.<br><a href="#" class="cliniko-shortcode-style-guide-link" data-shortcode-guide="cliniko_patient_details_module">Open styling guide</a></p>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Patient details module saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p>Patient details module deleted.</p></div><?php endif; ?>
            <table class="widefat striped" style="max-width:1100px;margin-top:20px">
                <thead><tr><th>Name</th><th>Fields</th><th>Layout</th><th>Shortcode</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($modules === []) : ?>
                    <tr><td colspan="5">No patient details modules have been created.</td></tr>
                <?php else : foreach ($modules as $id => $module) : ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) ($module['name'] ?? '')); ?></strong></td>
                        <td><?php echo esc_html((string) count((array) ($module['fields'] ?? []))); ?></td>
                        <td><?php echo esc_html((string) ($module['columns'] ?? 2)); ?> columns</td>
                        <td><code>[<?php echo esc_html(self::SHORTCODE); ?> id="<?php echo esc_attr($id); ?>"]</code></td>
                        <td>
                            <a href="<?php echo esc_url(self::url(['action' => 'edit', 'id' => $id])); ?>">Edit</a>
                            | <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_cliniko_patient_details_module_delete&id=' . rawurlencode($id)), 'delete_patient_details_module_' . $id)); ?>" onclick="return confirm('Delete this module?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderEditor(string $id): void
    {
        $module = self::modules()[$id] ?? self::defaults();
        $available = self::availableFields();
        $fields = self::normaliseFields((array) ($module['fields'] ?? []), $available);
        if ($fields === []) {
            $fields = self::normaliseFields((array) self::defaults()['fields'], $available);
        }

        $style = dirname(__DIR__, 3) . '/assets/patient-account-form-builder.css';
        $script = dirname(__DIR__, 3) . '/assets/dashboard-patient-details-admin.js';
        wp_enqueue_style('cliniko-patient-details-builder', plugins_url('../../../assets/patient-account-form-builder.css', __FILE__), [], file_exists($style) ? (string) filemtime($style) : null);
        wp_enqueue_script('cliniko-patient-details-builder', plugins_url('../../../assets/dashboard-patient-details-admin.js', __FILE__), [], file_exists($script) ? (string) filemtime($script) : null, true);
        ?>
        <div class="wrap">
            <h1><?php echo $id !== '' ? 'Edit Patient Details Module' : 'Add Patient Details Module'; ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-form-builder" data-cliniko-patient-details-builder>
                <input type="hidden" name="action" value="wp_cliniko_patient_details_module_save">
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
                <input type="hidden" name="fields_config" value="<?php echo esc_attr((string) wp_json_encode($fields)); ?>" data-patient-details-fields-config>
                <?php wp_nonce_field('save_patient_details_module'); ?>
                <div class="cliniko-form-builder__tabs"><button type="button" class="is-active">Patient Details</button></div>
                <div class="cliniko-form-builder__layout">
                    <main>
                        <input class="cliniko-form-builder__name" required name="name" placeholder="Module name" value="<?php echo esc_attr((string) ($module['name'] ?? '')); ?>">
                        <section class="cliniko-form-builder__panel">
                            <header><strong>Patient details builder</strong><button type="button" class="button-link" data-toggle-patient-details-builder aria-expanded="true">↕</button></header>
                            <div data-patient-details-builder-panel>
                                <div class="cliniko-form-builder__toolbar">
                                    <button type="button" class="button" data-patient-details-preview>Preview</button>
                                    <button type="button" class="button" data-patient-details-mobile-preview>Live preview mobile</button>
                                </div>
                                <div class="cliniko-dashboard-module-settings">
                                    <label>Section title <input type="text" name="title" value="<?php echo esc_attr((string) ($module['title'] ?? 'Your details')); ?>"></label>
                                    <label>Columns
                                        <select name="columns">
                                            <?php foreach ([1, 2, 3] as $count) : ?><option value="<?php echo $count; ?>" <?php selected((int) ($module['columns'] ?? 2), $count); ?>><?php echo $count; ?></option><?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label>Empty value text <input type="text" name="empty_value" value="<?php echo esc_attr((string) ($module['empty_value'] ?? '—')); ?>"></label>
                                    <label><input type="checkbox" name="hide_empty" value="1" <?php checked(!empty($module['hide_empty'])); ?>> Hide fields with no value</label>
                                    <label><input type="checkbox" name="show_labels" value="1" <?php checked(!array_key_exists('show_labels', $module) || !empty($module['show_labels'])); ?>> Show field labels</label>
                                </div>
                                <div class="cliniko-form-builder__row">
                                    <div class="cliniko-form-builder__row-header"><strong>Displayed patient fields</strong><span>Choose fields, set aliases, and drag to reorder.</span></div>
                                    <div class="cliniko-dashboard-column-picker">
                                        <select data-patient-details-field-picker>
                                            <?php foreach ($available as $key => $definition) : ?>
                                                <option value="<?php echo esc_attr($key); ?>" data-field-label="<?php echo esc_attr((string) $definition['label']); ?>"><?php echo esc_html((string) $definition['label']); ?><?php echo !empty($definition['custom']) ? ' (custom)' : ''; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="button" data-add-patient-details-field>Add field</button>
                                    </div>
                                    <div class="cliniko-dashboard-columns" data-patient-details-fields>
                                        <?php foreach ($fields as $field) : self::renderField($field, $available); endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </main>
                    <aside class="cliniko-form-builder__sidebar">
                        <section class="cliniko-form-builder__sidebox"><h2>Publish</h2><button type="submit" class="button button-primary button-large">Create / Update module</button></section>
                        <section class="cliniko-form-builder__sidebox"><h2>Shortcode</h2><p>Place this module anywhere in Elementor or the WordPress editor.</p><code>[<?php echo esc_html(self::SHORTCODE); ?> id="<?php echo esc_attr($id); ?>"]</code></section>
                    </aside>
                </div>
            </form>
            <div class="cliniko-form-builder__preview-modal" data-patient-details-preview-modal hidden>
                <div class="cliniko-form-builder__preview-dialog" role="dialog" aria-modal="true">
                    <header><h2>Patient details preview</h2><button type="button" class="button-link" data-close-patient-details-preview>Close</button></header>
                    <div class="cliniko-form-builder__preview-content" data-patient-details-preview-content></div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('save_patient_details_module');

        $id = sanitize_key((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            $id = 'patient_details_' . wp_generate_uuid4();
        }
        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        $rawFields = json_decode(wp_unslash((string) ($_POST['fields_config'] ?? '')), true);
        $fields = self::normaliseFields(is_array($rawFields) ? $rawFields : [], self::availableFields());
        if ($name === '' || $fields === []) {
            wp_die('A module name and at least one patient field are required.');
        }

        $modules = self::modules();
        $modules[$id] = [
            'name' => $name,
            'type' => 'patient_details',
            'title' => sanitize_text_field((string) ($_POST['title'] ?? 'Your details')),
            'columns' => max(1, min(3, (int) ($_POST['columns'] ?? 2))),
            'empty_value' => sanitize_text_field((string) ($_POST['empty_value'] ?? '—')),
            'hide_empty' => !empty($_POST['hide_empty']),
            'show_labels' => !empty($_POST['show_labels']),
            'fields' => $fields,
        ];
        update_option(self::OPTION_KEY, $modules, false);
        wp_safe_redirect(self::url(['saved' => 1]));
        exit;
    }

    public static function delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $id = sanitize_key((string) ($_GET['id'] ?? ''));
        check_admin_referer('delete_patient_details_module_' . $id);
        $modules = self::modules();
        unset($modules[$id]);
        update_option(self::OPTION_KEY, $modules, false);
        wp_safe_redirect(self::url(['deleted' => 1]));
        exit;
    }

    public static function shortcode(array $attributes): string
    {
        if (!is_user_logged_in()) {
            return '<p class="cliniko-dashboard-module-login-required">Please log in to view your patient details.</p>';
        }

        $id = sanitize_key((string) ($attributes['id'] ?? ''));
        $module = self::modules()[$id] ?? null;
        if (!is_array($module)) {
            return current_user_can('manage_options') ? '<p>Patient details module not found.</p>' : '';
        }

        $style = __DIR__ . '/DashboardPatientDetails/ShortCodeTemplates/patient-dashboard.css';
        wp_enqueue_style('cliniko-dashboard-patient-details', plugins_url('DashboardPatientDetails/ShortCodeTemplates/patient-dashboard.css', __FILE__), ['cliniko-shortcode-components'], file_exists($style) ? (string) filemtime($style) : null);

        try {
            $patient = Auth::patientData();
            
            if ($patient === null && MockPatientData::enabled()) {
                $patient = MockPatientData::make();
            }



            if ($patient === null) {
                return '<p class="cliniko-dashboard-module__message is-error">Patient not found.</p>';
            }
            return Phtml::render(__DIR__ . '/DashboardPatientDetails/ShortCodeTemplates/patient-details.phtml', ['module' => $module, 'patient' => $patient]);
        } catch (\Throwable $exception) {
            error_log('Cliniko patient details shortcode failed: ' . $exception->getMessage());
            return '<p class="cliniko-dashboard-module__message is-error">Patient data is temporarily unavailable. Please try again shortly.</p>';
        }
    }

    /** @return array<string,array<string,mixed>> */
    private static function modules(): array
    {
        $modules = get_option(self::OPTION_KEY, []);
        return is_array($modules) ? $modules : [];
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        return [
            'name' => '',
            'type' => 'patient_details',
            'title' => 'Your details',
            'columns' => 2,
            'empty_value' => '—',
            'hide_empty' => false,
            'show_labels' => true,
            'fields' => [
                ['key' => 'first_name', 'alias' => 'First name'],
                ['key' => 'last_name', 'alias' => 'Last name'],
                ['key' => 'email', 'alias' => 'Email'],
                ['key' => 'phone', 'alias' => 'Phone'],
            ],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function availableFields(): array
    {
        return array_merge(
            [
                'full_name' => [
                    'label' => 'Full name',
                    'type' => 'text',
                    'editable' => false,
                    'computed' => true,
                ],
            ],
            PatientFieldRegistry::available()
        );
    }

    /** @param array<int|string,mixed> $rawFields @param array<string,array<string,mixed>> $available @return array<int,array{key:string,alias:string}> */
    private static function normaliseFields(array $rawFields, array $available): array
    {
        $fields = [];
        $seen = [];
        foreach ($rawFields as $rawField) {
            if (!is_array($rawField)) {
                continue;
            }
            $key = sanitize_key((string) ($rawField['key'] ?? ''));
            if ($key === '' || isset($seen[$key]) || !isset($available[$key])) {
                continue;
            }
            $seen[$key] = true;
            $fallback = (string) ($available[$key]['label'] ?? $key);
            $alias = sanitize_text_field((string) ($rawField['alias'] ?? $fallback));
            $fields[] = ['key' => $key, 'alias' => $alias !== '' ? $alias : $fallback];
        }
        return $fields;
    }

    /** @param array{key:string,alias:string} $field @param array<string,array<string,mixed>> $available */
    private static function renderField(array $field, array $available): void
    {
        if (!isset($available[$field['key']])) {
            return;
        }
        ?>
        <article class="cliniko-dashboard-column" draggable="true" data-patient-details-field data-field-key="<?php echo esc_attr($field['key']); ?>">
            <span class="dashicons dashicons-menu"></span>
            <strong><?php echo esc_html((string) $available[$field['key']]['label']); ?></strong>
            <label>Alias <input type="text" value="<?php echo esc_attr($field['alias']); ?>" data-patient-details-field-alias></label>
            <button type="button" class="button-link-delete" data-remove-patient-details-field>Remove</button>
        </article>
        <?php
    }

    /** @param array<string,int|string> $args */
    private static function url(array $args = []): string
    {
        return AccountBuilders::url(
            AccountBuilders::TAB_DASHBOARD_MODULES,
            array_merge(['dashboard_section' => AccountBuilders::DASHBOARD_PATIENT_DETAILS], $args)
        );
    }
}
