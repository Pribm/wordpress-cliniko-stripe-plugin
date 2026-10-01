<?php

namespace App\Admin\Modules\AccountBuilders\Dashboard;

use App\Client\Cliniko\Client;
use App\Admin\Modules\AccountBuilders\AccountBuilders;
use App\Model\AppointmentType;
use App\Model\PatientFormTemplate;
use App\Service\DashboardAppointmentService;
use App\Support\Phtml;

if (!defined('ABSPATH')) {
    exit;
}

final class DashboardModules
{
    private const OPTION_KEY = 'wp_cliniko_dashboard_modules';
    private const SHORTCODE = 'cliniko_dashboard_module';
    private const DETAILS_SHORTCODE = 'cliniko_appointment_details';

    public static function init(): void
    {
        add_action('admin_post_wp_cliniko_dashboard_module_save', [self::class, 'save']);
        add_action('admin_post_wp_cliniko_dashboard_module_delete', [self::class, 'delete']);
        add_shortcode(self::SHORTCODE, [self::class, 'shortcode']);
        add_shortcode(self::DETAILS_SHORTCODE, [self::class, 'detailsShortcode']);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $action = sanitize_key((string) ($_GET['action'] ?? 'list'));
        if ($action === 'edit' || $action === 'new') {
            self::renderEditor($action === 'edit' ? sanitize_key((string) ($_GET['id'] ?? '')) : '');
            return;
        }

        $modules = self::modules();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Dashboard Modules</h1>
            <a class="page-title-action" href="<?php echo esc_url(self::url(['action' => 'new'])); ?>">Add New</a>
            <p>Create one dashboard list for one Cliniko appointment type. Every list receives its own shortcode.<br><a href="#" class="cliniko-shortcode-style-guide-link" data-shortcode-guide="cliniko_dashboard_module">Open styling guide</a></p>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Dashboard module saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p>Dashboard module deleted.</p></div><?php endif; ?>
            <table class="widefat striped" style="max-width:1100px;margin-top:20px">
                <thead><tr><th>Name</th><th>Appointment type</th><th>Items per page</th><th>Shortcode</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($modules === []) : ?>
                    <tr><td colspan="5">No dashboard modules have been created.</td></tr>
                <?php else : foreach ($modules as $id => $module) : ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) ($module['name'] ?? '')); ?></strong></td>
                        <td><?php echo esc_html((string) ($module['appointment_type_name'] ?? $module['appointment_type_id'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($module['per_page'] ?? 5)); ?></td>
                        <td><code>[<?php echo esc_html(self::SHORTCODE); ?> id="<?php echo esc_attr($id); ?>"]</code></td>
                        <td>
                            <a href="<?php echo esc_url(self::url(['action' => 'edit', 'id' => $id])); ?>">Edit</a>
                            | <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_cliniko_dashboard_module_delete&id=' . rawurlencode($id)), 'delete_dashboard_module_' . $id)); ?>" onclick="return confirm('Delete this module?');">Delete</a>
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
        $appointmentTypes = AppointmentType::all(Client::getInstance());
        $formTemplates = PatientFormTemplate::all(Client::getInstance());
        $formFieldDefinitions = self::formFieldDefinitions($formTemplates);
        $selectedTypeId = (string) ($module['appointment_type_id'] ?? '');
        $selectedTemplateId = (string) ($module['patient_form_template_id'] ?? '');
        $columns = self::normaliseColumns((array) ($module['columns'] ?? []));
        if ($columns === []) {
            $columns = self::normaliseColumns((array) self::defaults()['columns']);
        }
        $builderStyle = dirname(__DIR__, 3) . '/assets/patient-account-form-builder.css';
        $builderScript = dirname(__DIR__, 3) . '/assets/dashboard-module-admin.js';
        wp_enqueue_style('cliniko-dashboard-module-builder', plugins_url('../../../assets/patient-account-form-builder.css', __FILE__), [], file_exists($builderStyle) ? (string) filemtime($builderStyle) : null);
        wp_enqueue_script('cliniko-dashboard-module-builder', plugins_url('../../../assets/dashboard-module-admin.js', __FILE__), [], file_exists($builderScript) ? (string) filemtime($builderScript) : null, true);
        ?>
        <div class="wrap">
            <h1><?php echo $id !== '' ? 'Edit Dashboard Module' : 'Add Dashboard Module'; ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-form-builder" data-cliniko-dashboard-module-builder>
                <input type="hidden" name="action" value="wp_cliniko_dashboard_module_save">
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
                <input type="hidden" name="columns_config" value="<?php echo esc_attr((string) wp_json_encode($columns)); ?>" data-dashboard-columns-config>
                <?php wp_nonce_field('save_dashboard_module'); ?>
                <div class="cliniko-form-builder__tabs"><button type="button" class="is-active">Appointments</button></div>
                <div class="cliniko-form-builder__layout">
                    <main>
                        <input class="cliniko-form-builder__name" required name="name" placeholder="Module name" value="<?php echo esc_attr((string) ($module['name'] ?? '')); ?>">
                        <section class="cliniko-form-builder__panel">
                            <header><strong>Appointment list builder</strong><button type="button" class="button-link" data-toggle-module-builder aria-expanded="true">↕</button></header>
                            <div data-module-builder-panel>
                                <div class="cliniko-form-builder__toolbar">
                                    <button type="button" class="button" data-dashboard-preview>Preview</button>
                                    <button type="button" class="button" data-dashboard-mobile-preview>Live preview mobile</button>
                                </div>
                                <div class="cliniko-dashboard-module-settings">
                                    <label>Section title <input type="text" name="title" value="<?php echo esc_attr((string) ($module['title'] ?? 'Appointments')); ?>"></label>
                                    <label>Items per page <input type="number" min="1" max="50" name="per_page" value="<?php echo esc_attr((string) ($module['per_page'] ?? 5)); ?>"></label>
                                    <label>Order <select name="order"><option value="asc" <?php selected((string) ($module['order'] ?? 'asc'), 'asc'); ?>>Oldest first</option><option value="desc" <?php selected((string) ($module['order'] ?? ''), 'desc'); ?>>Newest first</option></select></label>
                                    <label>Date format <select name="date_format"><option value="short" <?php selected((string) ($module['date_format'] ?? 'short'), 'short'); ?>>Short</option><option value="long" <?php selected((string) ($module['date_format'] ?? ''), 'long'); ?>>Long</option></select></label>
                                    <label>Empty-list message <input type="text" name="empty_message" value="<?php echo esc_attr((string) ($module['empty_message'] ?? 'No appointments found.')); ?>"></label>
                                    <label><input type="checkbox" name="show_pagination" value="1" <?php checked(!array_key_exists('show_pagination', $module) || !empty($module['show_pagination'])); ?>> Enable pagination</label>
                                </div>
                                <div class="cliniko-form-builder__row">
                                    <div class="cliniko-form-builder__row-header"><strong>Cliniko appointment type</strong></div>
                                    <label for="cliniko-dashboard-appointment-type">Appointment type</label>
                                    <select id="cliniko-dashboard-appointment-type" name="appointment_type_id" required>
                                        <option value="">Select an appointment type</option>
                                        <option value="all" data-appointment-type-name="All appointment types" <?php selected($selectedTypeId, 'all'); ?>>All appointment types</option>
                                        <?php foreach ($appointmentTypes as $appointmentType) : $typeId = (string) $appointmentType->getId(); ?>
                                            <option value="<?php echo esc_attr($typeId); ?>" data-appointment-type-name="<?php echo esc_attr((string) $appointmentType->getName()); ?>" <?php selected($selectedTypeId, $typeId); ?>><?php echo esc_html((string) $appointmentType->getName()); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="cliniko-form-builder__row">
                                    <div class="cliniko-form-builder__row-header"><strong>List columns</strong><span>Select a field and customize its column alias.</span></div>
                                    <div class="cliniko-dashboard-column-picker">
                                        <select data-dashboard-column-picker>
                                            <?php foreach (self::columnDefinitions() as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" data-column-label="<?php echo esc_attr($label); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?>
                                            <?php foreach ($formFieldDefinitions[$selectedTemplateId] ?? [] as $field) : ?><option value="patient_form_field" data-form-field="<?php echo esc_attr($field['field']); ?>" data-column-label="<?php echo esc_attr($field['label']); ?>"><?php echo esc_html('Form: ' . $field['label']); ?></option><?php endforeach; ?>
                                        </select>
                                        <button type="button" class="button" data-add-dashboard-column>Add column</button>
                                    </div>
                                    <div class="cliniko-dashboard-columns" data-dashboard-columns>
                                        <?php foreach ($columns as $column) : self::renderColumn($column); endforeach; ?>
                                    </div>
                                </div>
                                <div class="cliniko-form-builder__row">
                                    <div class="cliniko-form-builder__row-header"><strong>Patient form column</strong><span>Choose the Cliniko template used by the Patient form column.</span></div>
                                    <label for="cliniko-dashboard-patient-form-template">Form template</label>
                                    <select id="cliniko-dashboard-patient-form-template" name="patient_form_template_id">
                                        <option value="">Select a patient form template</option>
                                        <?php foreach ($formTemplates as $template) : $templateId = (string) $template->getId(); ?>
                                            <option value="<?php echo esc_attr($templateId); ?>" data-form-fields="<?php echo esc_attr(wp_json_encode($formFieldDefinitions[$templateId] ?? [])); ?>" <?php selected($selectedTemplateId, $templateId); ?>><?php echo esc_html((string) $template->getName()); ?><?php echo $template->isArchived() ? ' — Archived' : ''; ?></option>
                                        <?php endforeach; ?>
                                        <?php if ($selectedTemplateId !== '' && !array_filter($formTemplates, static fn($item): bool => (string) $item->getId() === $selectedTemplateId)) : ?>
                                            <option value="<?php echo esc_attr($selectedTemplateId); ?>" selected>Previously selected template (<?php echo esc_html($selectedTemplateId); ?>)</option>
                                        <?php endif; ?>
                                    </select>
                                    <p class="description">Choose a template, then add its individual questions from the list-column picker. Each selected question becomes its own column.</p>
                                </div>
                                <div class="cliniko-form-builder__row">
                                    <div class="cliniko-form-builder__row-header"><strong>Appointment details button</strong></div>
                                    <label><input type="checkbox" name="show_details_button" value="1" <?php checked(!empty($module['show_details_button'])); ?>> Show a button for each appointment</label>
                                    <label>Button label <input type="text" name="details_button_label" value="<?php echo esc_attr((string) ($module['details_button_label'] ?? 'View details')); ?>"></label>
                                    <label>Custom button URL <input type="url" class="large-text" name="details_button_url" value="<?php echo esc_attr((string) ($module['details_button_url'] ?? '')); ?>"></label>
                                    <p class="description">Optional. The appointment ID will be added as <code>?appointment_id=...</code>. Use <code>{appointment_id}</code> if you want to place it yourself.</p>
                                    <label>Details page
                                        <?php wp_dropdown_pages([
                                            'name' => 'details_page_id',
                                            'selected' => (int) ($module['details_page_id'] ?? 0),
                                            'show_option_none' => 'Select a page',
                                            'option_none_value' => '0',
                                        ]); ?>
                                    </label>
                                    <p class="description">Add <code>[<?php echo esc_html(self::DETAILS_SHORTCODE); ?>]</code> to the selected page when using the default details URL.</p>
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
            <div class="cliniko-form-builder__preview-modal" data-dashboard-preview-modal hidden>
                <div class="cliniko-form-builder__preview-dialog" role="dialog" aria-modal="true">
                    <header><h2>Appointment module preview</h2><button type="button" class="button-link" data-close-dashboard-preview>Close</button></header>
                    <div class="cliniko-form-builder__preview-content" data-dashboard-preview-content></div>
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
        check_admin_referer('save_dashboard_module');

        $id = sanitize_key((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            $id = 'module_' . wp_generate_uuid4();
        }
        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            wp_die('A module name is required.');
        }
        $appointmentTypeId = sanitize_text_field((string) ($_POST['appointment_type_id'] ?? ''));
        if ($appointmentTypeId === '') {
            wp_die('An appointment type is required.');
        }
        $appointmentTypeName = $appointmentTypeId === 'all' ? 'All appointment types' : $appointmentTypeId;
        if ($appointmentTypeId !== 'all') {
            foreach (AppointmentType::all(Client::getInstance()) as $appointmentType) {
                if ((string) $appointmentType->getId() === $appointmentTypeId) {
                    $appointmentTypeName = (string) $appointmentType->getName();
                    break;
                }
            }
        }
        $patientFormTemplateId = sanitize_text_field((string) ($_POST['patient_form_template_id'] ?? ''));
        $patientFormTemplateName = '';
        if ($patientFormTemplateId !== '') {
            foreach (PatientFormTemplate::all(Client::getInstance()) as $template) {
                if ((string) $template->getId() === $patientFormTemplateId) {
                    $patientFormTemplateName = (string) $template->getName();
                    break;
                }
            }
        }
        $rawColumns = json_decode(wp_unslash((string) ($_POST['columns_config'] ?? '')), true);
        $columns = self::normaliseColumns(is_array($rawColumns) ? $rawColumns : []);
        if ($columns === []) {
            wp_die('Select at least one list column.');
        }
        $showDetailsButton = !empty($_POST['show_details_button']);
        $detailsPageId = max(0, (int) ($_POST['details_page_id'] ?? 0));
        $detailsButtonUrl = esc_url_raw((string) ($_POST['details_button_url'] ?? ''));
        if (array_filter($columns, static fn(array $column): bool => in_array($column['key'], ['patient_form', 'patient_form_field'], true)) !== [] && $patientFormTemplateId === '') {
            wp_die('Select a patient form template when using the Patient form column.');
        }
        if ($showDetailsButton && $detailsPageId <= 0 && $detailsButtonUrl === '') {
            wp_die('Select a details page or enter a custom button URL when the appointment details button is enabled.');
        }

        $module = [
            'name' => $name,
            'type' => 'appointment_list',
            'title' => sanitize_text_field((string) ($_POST['title'] ?? 'Appointments')),
            'appointment_type_id' => $appointmentTypeId,
            'appointment_type_name' => $appointmentTypeName,
            'patient_form_template_id' => $patientFormTemplateId,
            'patient_form_template_name' => $patientFormTemplateName,
            'per_page' => max(1, min(50, (int) ($_POST['per_page'] ?? 5))),
            'order' => (string) ($_POST['order'] ?? '') === 'desc' ? 'desc' : 'asc',
            'date_format' => (string) ($_POST['date_format'] ?? '') === 'long' ? 'long' : 'short',
            'empty_message' => sanitize_text_field((string) ($_POST['empty_message'] ?? 'No appointments found.')),
            'show_pagination' => !empty($_POST['show_pagination']),
            'columns' => $columns,
            'show_details_button' => $showDetailsButton,
            'details_button_label' => sanitize_text_field((string) ($_POST['details_button_label'] ?? 'View details')),
            'details_page_id' => $detailsPageId,
            'details_button_url' => $detailsButtonUrl,
        ];

        $modules = self::modules();
        $modules[$id] = $module;
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
        check_admin_referer('delete_dashboard_module_' . $id);
        $modules = self::modules();
        unset($modules[$id]);
        update_option(self::OPTION_KEY, $modules, false);
        wp_safe_redirect(self::url(['deleted' => 1]));
        exit;
    }

    public static function shortcode(array $attributes): string
    {
        if (!is_user_logged_in()) {
            return '<p class="cliniko-dashboard-module-login-required">Please log in to view this section.</p>';
        }

        $id = sanitize_key((string) ($attributes['id'] ?? ''));
        $module = self::modules()[$id] ?? null;
        if (!is_array($module)) {
            return current_user_can('manage_options') ? '<p>Dashboard module not found.</p>' : '';
        }

        $style = __DIR__ . '/DashboardModules/ShortCodeTemplates/patient-dashboard.css';
        wp_enqueue_style('cliniko-dashboard-module', plugins_url('DashboardModules/ShortCodeTemplates/patient-dashboard.css', __FILE__), ['cliniko-shortcode-components'], file_exists($style) ? (string) filemtime($style) : null);

        $detailsPageUrl = '';
        if (!empty($module['show_details_button']) && !empty($module['details_button_url'])) {
            $detailsPageUrl = esc_url((string) $module['details_button_url']);
        } elseif (!empty($module['show_details_button']) && !empty($module['details_page_id'])) {
            $detailsPageUrl = get_permalink((int) $module['details_page_id']) ?: '';
        }

        $pageParameter = 'cliniko_page_' . str_replace('-', '_', $id);
        $page = max(1, (int) ($_GET[$pageParameter] ?? 1));
        try {
            $result = (new DashboardAppointmentService())->forAuthenticatedPatient(
                (string) ($module['appointment_type_id'] ?? ''),
                $page,
                (int) ($module['per_page'] ?? 5),
                (string) ($module['order'] ?? 'asc'),
                (string) ($module['patient_form_template_id'] ?? ''),
                is_array($module['columns'] ?? null) ? $module['columns'] : []
            );
            return Phtml::render(__DIR__ . '/DashboardModules/ShortCodeTemplates/appointment-list.phtml', [
                'module' => $module,
                'result' => $result,
                'pageParameter' => $pageParameter,
                'detailsPageUrl' => $detailsPageUrl,
            ]);
        } catch (\Throwable $exception) {
            error_log('Cliniko appointment list shortcode failed: ' . $exception->getMessage());
            return '<p class="cliniko-dashboard-module__message is-error">Appointments could not be loaded.</p>';
        }
    }

    public static function detailsShortcode(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Please log in to view this appointment.</p>';
        }
        $style = __DIR__ . '/DashboardModules/ShortCodeTemplates/patient-dashboard.css';
        wp_enqueue_style('cliniko-dashboard-module', plugins_url('DashboardModules/ShortCodeTemplates/patient-dashboard.css', __FILE__), ['cliniko-shortcode-components'], file_exists($style) ? (string) filemtime($style) : null);

        $appointmentId = sanitize_text_field(wp_unslash((string) ($_GET['appointment_id'] ?? '')));
        if ($appointmentId === '') {
            return '<p class="cliniko-dashboard-module__message is-error">Appointment not specified.</p>';
        }
        try {
            $appointment = (new DashboardAppointmentService())->findForAuthenticatedPatient($appointmentId);
            if ($appointment === null) {
                return '<p class="cliniko-dashboard-module__message is-error">Appointment not found.</p>';
            }
            return Phtml::render(__DIR__ . '/DashboardModules/ShortCodeTemplates/appointment-details.phtml', ['appointment' => $appointment]);
        } catch (\Throwable $exception) {
            error_log('Cliniko appointment details shortcode failed: ' . $exception->getMessage());
            return '<p class="cliniko-dashboard-module__message is-error">Appointment details could not be loaded.</p>';
        }
    }

    /** @return array<string,array<string,mixed>> */
    private static function modules(): array
    {
        $value = get_option(self::OPTION_KEY, []);
        return is_array($value) ? $value : [];
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        return [
            'name' => '',
            'type' => 'appointment_list',
            'title' => 'Appointments',
            'appointment_type_id' => '',
            'appointment_type_name' => '',
            'patient_form_template_id' => '',
            'patient_form_template_name' => '',
            'per_page' => 5,
            'order' => 'asc',
            'date_format' => 'short',
            'empty_message' => 'No appointments found.',
            'show_pagination' => true,
            'columns' => [
                ['key' => 'starts_at', 'alias' => 'Date'],
                ['key' => 'service', 'alias' => 'Service'],
                ['key' => 'status', 'alias' => 'Status'],
                ['key' => 'doctor', 'alias' => 'Doctor'],
            ],
            'show_details_button' => false,
            'details_button_label' => 'View details',
            'details_page_id' => 0,
            'details_button_url' => '',
        ];
    }

    /** @return array<string,string> */
    private static function columnDefinitions(): array
    {
        return [
            'starts_at' => 'Start date and time',
            'ends_at' => 'End date and time',
            'service' => 'Service',
            'appointment_type' => 'Appointment type',
            'status' => 'Status',
            'doctor' => 'Doctor / practitioner',
            'duration' => 'Duration',
            'category' => 'Category',
            'description' => 'Description',
            'price' => 'Price',
            'notes' => 'Notes',
            'telehealth_url' => 'Telehealth',
            'patient_form' => 'Patient form status',
        ];
    }

    /** @param array<int|string,mixed> $rawColumns @return array<int,array{key:string,alias:string,field?:string}> */
    private static function normaliseColumns(array $rawColumns): array
    {
        $available = self::columnDefinitions();
        $columns = [];
        $seen = [];
        foreach ($rawColumns as $rawColumn) {
            if (!is_array($rawColumn)) continue;
            $key = sanitize_key((string) ($rawColumn['key'] ?? ''));
            $field = '';
            if ($key === 'patient_form_field') {
                $field = sanitize_key((string) ($rawColumn['field'] ?? ''));
                if ($field === '' || isset($seen[$key . ':' . $field])) continue;
                $seen[$key . ':' . $field] = true;
            } elseif (!isset($available[$key]) || isset($seen[$key])) continue;
            else $seen[$key] = true;
            $alias = sanitize_text_field((string) ($rawColumn['alias'] ?? $available[$key]));
            $entry = ['key' => $key, 'alias' => $alias !== '' ? $alias : ($available[$key] ?? 'Form field')];
            if ($key === 'patient_form_field') $entry['field'] = $field;
            $columns[] = $entry;
        }
        return $columns;
    }

    /** @param array{key:string,alias:string,field?:string} $column */
    private static function renderColumn(array $column): void
    {
        $label = $column['key'] === 'patient_form_field'
            ? $column['alias']
            : (string) (self::columnDefinitions()[$column['key']] ?? $column['key']);
        ?>
        <article class="cliniko-dashboard-column" draggable="true" data-dashboard-column data-column-key="<?php echo esc_attr($column['key']); ?>"<?php if ($column['key'] === 'patient_form_field') : ?> data-column-field="<?php echo esc_attr((string) ($column['field'] ?? '')); ?>"<?php endif; ?>>
            <span class="dashicons dashicons-menu"></span>
            <strong><?php echo esc_html($label); ?></strong>
            <label>Alias <input type="text" value="<?php echo esc_attr($column['alias']); ?>" data-column-alias></label>
            <button type="button" class="button-link-delete" data-remove-dashboard-column>Remove</button>
        </article>
        <?php
    }

    /** @param array<int,PatientFormTemplate> $templates @return array<string,array<int,array{field:string,label:string}>> */
    private static function formFieldDefinitions(array $templates): array
    {
        $definitions = [];
        foreach ($templates as $template) {
            $templateId = (string) $template->getId();
            $definitions[$templateId] = [];
            foreach ($template->getSections() as $sectionIndex => $section) {
                foreach ($section->questions as $questionIndex => $question) {
                    $field = 's' . $sectionIndex . '_q' . $questionIndex;
                    $sectionName = trim((string) ($section->name ?? ''));
                    $questionName = trim((string) ($question->name ?? '')) ?: 'Question ' . ($questionIndex + 1);
                    $definitions[$templateId][] = ['field' => $field, 'label' => ($sectionName !== '' ? $sectionName . ': ' : '') . $questionName];
                }
            }
        }
        return $definitions;
    }

    /** @param array<string,int|string> $args */
    private static function url(array $args = []): string
    {
        return AccountBuilders::url(
            AccountBuilders::TAB_DASHBOARD_MODULES,
            array_merge(['dashboard_section' => AccountBuilders::DASHBOARD_APPOINTMENT_TYPES], $args)
        );
    }
}
