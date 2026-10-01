<?php

namespace App\Admin\Modules\AccountBuilders\Forms;

use App\Admin\Modules\AccountBuilders\AccountBuilders;

use App\Service\PatientFieldRegistry;
use App\Service\PatientEmailChangeService;
use App\Service\PatientService;
use App\Support\Auth;
use App\Support\MockPatientData;
use App\Support\Phtml;
use App\Admin\Modules\AccountBuilders\Shortcodes\ShortcodeCatalog;

if (!defined('ABSPATH')) {
    exit;
}

class PatientAccountForms
{
    private const OPTION_KEY = 'wp_cliniko_patient_account_forms';
    public static function init(): void
    {
        add_action('admin_post_wp_cliniko_patient_account_form_save', [self::class, 'saveForm']);
        add_action('admin_post_wp_cliniko_patient_account_form_delete', [self::class, 'deleteForm']);
        add_action('admin_post_wp_cliniko_patient_account_update', [self::class, 'updatePatient']);
        add_shortcode('cliniko_patient_form', [self::class, 'renderShortcode']);
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

        $forms = self::forms();
        ?>
        <div class="wrap cliniko-template-builder-page cliniko-patient-account-builder-page">
            <h1 class="wp-heading-inline">Patient Account Forms</h1>
            <a href="<?php echo esc_url(self::url(['action' => 'new'])); ?>" class="page-title-action">Add New</a>
            <p>Create reusable forms for logged-in patients to view and update selected Cliniko fields.<br><a href="#" class="cliniko-shortcode-style-guide-link" data-shortcode-guide="cliniko_patient_form">Open styling guide</a></p>

            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Patient account form saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p>Patient account form deleted.</p></div><?php endif; ?>

            <table class="widefat striped" style="max-width:1100px;margin-top:20px">
                <thead><tr><th>Name</th><th>Fields</th><th>Shortcode</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($forms === []) : ?>
                    <tr><td colspan="4">No patient account forms have been created.</td></tr>
                <?php else : foreach ($forms as $id => $form) : ?>
                    <tr>
                        <td><strong><?php echo esc_html($form['name']); ?></strong></td>
                        <td><?php echo esc_html((string) count(self::normaliseFields($form['fields'] ?? []))); ?></td>
                        <td><code>[cliniko_patient_form id="<?php echo esc_attr($id); ?>"]</code></td>
                        <td>
                            <a href="<?php echo esc_url(self::url(['action' => 'edit', 'id' => $id])); ?>">Edit</a>
                            | <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_cliniko_patient_account_form_delete&id=' . rawurlencode($id)), 'delete_patient_account_form_' . $id)); ?>" onclick="return confirm('Delete this form?');">Delete</a>
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
        $forms = self::forms();
        $form = $forms[$id] ?? ['name' => '', 'fields' => ['first_name', 'last_name', 'email']];
        $availableFields = self::editableFields();
        $sections = self::normaliseSections(
            $form['sections'] ?? ($form['rows'] ?? ($form['fields'] ?? [])),
            $availableFields,
            $id
        );
        $fieldCount = count(self::flattenSections($sections));
        $builderScript = dirname(__DIR__, 3) . '/assets/patient-account-form-builder.js';
        $builderStyle = dirname(__DIR__, 3) . '/assets/patient-account-form-builder.css';
        wp_enqueue_style('cliniko-patient-account-form-builder', plugins_url('../../../assets/patient-account-form-builder.css', __FILE__), [], file_exists($builderStyle) ? (string) filemtime($builderStyle) : null);
        ShortcodeFormInputRules::enqueueBuilderAssets();
        wp_enqueue_script('cliniko-patient-account-form-builder', plugins_url('../../../assets/patient-account-form-builder.js', __FILE__), ['cliniko-shortcode-form-rule-builder'], file_exists($builderScript) ? (string) filemtime($builderScript) : null, true);
        ?>
        <div class="wrap cliniko-template-builder-page cliniko-patient-account-builder-page" data-cliniko-patient-account-builder-page>
            <h1 class="cliniko-account-form-editor__page-title"><?php echo $id !== '' ? 'Edit Patient Account Form' : 'Add Patient Account Form'; ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-form-builder" data-cliniko-form-builder>
                <input type="hidden" name="action" value="wp_cliniko_patient_account_form_save" />
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>" />
                <input type="hidden" name="layout_config" value="<?php echo esc_attr(wp_json_encode($sections)); ?>" data-cliniko-layout-config />
                <?php wp_nonce_field('save_patient_account_form'); ?>
                <div class="cliniko-account-form-editor" data-cliniko-account-editor>
                    <header class="cliniko-account-form-editor__hero">
                        <div>
                            <span class="cliniko-account-form-editor__eyebrow">Patient experience builder</span>
                            <h2><?php echo $id !== '' ? 'Edit profile form' : 'Create profile form'; ?></h2>
                            <p>Set up the form, arrange the patient fields, then review the submission behaviour before publishing.</p>
                        </div>
                        <span class="cliniko-account-form-editor__status <?php echo $id !== '' ? 'is-enabled' : ''; ?>"><?php echo $id !== '' ? 'Published configuration' : 'New configuration'; ?></span>
                    </header>

                    <nav class="cliniko-account-form-editor__tabs" role="tablist" aria-label="Patient account form builder sections" data-account-builder-tabs>
                        <button type="button" role="tab" aria-selected="true" class="is-active" data-account-builder-tab="setup"><span>1</span> Setup</button>
                        <button type="button" role="tab" aria-selected="false" data-account-builder-tab="fields"><span>2</span> Fields &amp; layout</button>
                        <button type="button" role="tab" aria-selected="false" data-account-builder-tab="submission"><span>3</span> Submission</button>
                    </nav>

                    <div class="cliniko-form-builder__layout cliniko-account-form-builder">
                        <main>
                            <section class="cliniko-form-builder__panel cliniko-account-form-tab-panel is-active" data-account-builder-tab-panel="setup">
                                <header><div><strong>Setup</strong><span>Name the configuration and understand where it will be used.</span></div></header>
                                <div class="cliniko-account-form-panel-body">
                                    <div class="cliniko-account-form-editor__identity">
                                        <label for="patient-account-form-name">Internal form name</label>
                                        <input id="patient-account-form-name" class="cliniko-form-builder__name" type="text" name="name" value="<?php echo esc_attr($form['name']); ?>" placeholder="For example: Patient profile details" required />
                                        <p>Only administrators see this name. Patients see the section and field labels configured in the next step.</p>
                                    </div>
                                    <div class="cliniko-account-form-summary-grid">
                                        <article><span class="dashicons dashicons-admin-users"></span><div><strong>Who sees this form?</strong><p>Logged-in patients linked to a Cliniko patient record.</p></div></article>
                                        <article><span class="dashicons dashicons-editor-table"></span><div><strong>Current structure</strong><p><?php echo esc_html((string) $fieldCount); ?> field<?php echo $fieldCount === 1 ? '' : 's'; ?> across <?php echo esc_html((string) count($sections)); ?> section<?php echo count($sections) === 1 ? '' : 's'; ?>.</p></div></article>
                                    </div>
                                    <div class="cliniko-account-form-guidance"><span class="dashicons dashicons-info-outline"></span><div><strong>How this form works</strong><p>Patients can update only the fields you place in the layout. Required fields and input behaviour are configured individually.</p></div></div>
                                </div>
                            </section>

                            <section class="cliniko-form-builder__panel cliniko-account-form-tab-panel" data-account-builder-tab-panel="fields" hidden>
                                <header><div><strong>Fields &amp; layout</strong><span>Create sections, arrange columns, and configure each input.</span></div><div class="cliniko-account-form-preview-actions"><button type="button" class="button" data-preview>Desktop preview</button><button type="button" class="button" data-mobile-preview>Mobile preview</button></div></header>
                                <div class="cliniko-account-form-panel-body" data-builder-panel>
                                    <div class="cliniko-account-form-toolbar">
                                        <div><strong>Form structure</strong><span>Add sections first, then add patient fields inside each column.</span></div>
                                        <div><button type="button" class="button button-primary" data-add-section>Add section</button><button type="button" class="button" data-edit-section>Edit first section</button><button type="button" class="button" data-reorder-section>Reorder sections</button></div>
                                    </div>
                                    <div class="cliniko-form-builder__sections" data-sections>
                                    <?php foreach ($sections as $sectionIndex => $section) : self::renderBuilderSection($sectionIndex, $section, $availableFields); endforeach; ?>
                                    </div>
                                </div>
                            </section>

                            <section class="cliniko-form-builder__panel cliniko-account-form-tab-panel" data-account-builder-tab-panel="submission" hidden>
                                <header><div><strong>Submission</strong><span>Choose what happens after the patient successfully saves the form.</span></div></header>
                                <div class="cliniko-account-form-panel-body">
                                    <label class="cliniko-account-form-field" for="cliniko-patient-form-redirect"><strong>Redirect override after successful save</strong><input id="cliniko-patient-form-redirect" type="url" name="redirect_url" value="<?php echo esc_attr((string) ($form['redirect_url'] ?? '')); ?>" placeholder="Leave blank to use the default redirect"><small>Optional. This form-specific destination overrides the generic destination configured in the Redirects tab.</small></label>
                                    <div class="cliniko-account-form-guidance"><span class="dashicons dashicons-yes-alt"></span><div><strong>Patient data is validated before saving</strong><p>Cliniko receives the field values only after the required fields and configured input rules pass validation.</p></div></div>
                                </div>
                            </section>
                        </main>

                        <aside class="cliniko-form-builder__sidebar cliniko-account-form-editor__sidebar">
                            <section class="cliniko-form-builder__sidebox cliniko-account-form-publish-card">
                                <span class="cliniko-account-form-card-label">Publish</span>
                                <h2><?php echo $id !== '' ? 'Update this form' : 'Create this form'; ?></h2>
                                <p><?php echo $id !== '' ? 'Your changes will apply anywhere this shortcode is currently used.' : 'Save the configuration to generate its final shortcode ID.'; ?></p>
                                <button type="submit" class="button button-primary button-large"><?php echo $id !== '' ? 'Update patient form' : 'Create patient form'; ?></button>
                            </section>
                            <section class="cliniko-form-builder__sidebox cliniko-account-form-shortcode-card">
                                <h2>Shortcode</h2>
                                <p>Place this on a page available to logged-in patients.</p>
                                <?php if ($id !== '') : ?><code>[cliniko_patient_form id="<?php echo esc_attr($id); ?>"]</code><?php else : ?><p class="description">The shortcode is generated after the first save.</p><?php endif; ?>
                            </section>
                            <section class="cliniko-form-builder__sidebox cliniko-account-form-checklist">
                                <h2>Builder checklist</h2>
                                <ol><li>Give the form a clear internal name.</li><li>Arrange fields and test both previews.</li><li>Review the redirect before publishing.</li></ol>
                            </section>
                        </aside>
                    </div>
                </div>
                <div data-cliniko-available-fields hidden>
                    <?php foreach ($availableFields as $field => $definition) : ?><button type="button" data-field-key="<?php echo esc_attr($field); ?>" data-field-label="<?php echo esc_attr($definition['label']); ?>" data-field-type="<?php echo esc_attr((string) ($definition['type'] ?? 'text')); ?>" data-field-cliniko-type="<?php echo esc_attr((string) ($definition['cliniko_field_type'] ?? '')); ?>" data-field-custom="<?php echo !empty($definition['custom']) ? '1' : '0'; ?>" data-field-capabilities="<?php echo esc_attr(wp_json_encode(self::inputRuleCapabilities($definition))); ?>"></button><?php endforeach; ?>
                </div>
            </form>
            <div class="cliniko-form-builder__preview-modal" data-preview-modal hidden>
                <div class="cliniko-form-builder__preview-dialog" role="dialog" aria-modal="true" aria-labelledby="cliniko-form-preview-title">
                    <header><h2 id="cliniko-form-preview-title">Form preview</h2><button type="button" class="button-link" data-close-preview>Close</button></header>
                    <div class="cliniko-form-builder__preview-content" data-preview-content></div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function saveForm(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('save_patient_account_form');

        $id = sanitize_key((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            $id = 'form_' . wp_generate_uuid4();
        }

        $availableFields = self::editableFields();
        $rawSections = json_decode(wp_unslash((string) ($_POST['layout_config'] ?? '')), true);
        if (!is_array($rawSections)) {
            $rawSections = is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [];
        }
        $sections = self::normaliseSections($rawSections, $availableFields, $id);
        $fields = self::flattenSections($sections);
        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        if ($name === '' || $fields === []) {
            wp_die('A form name and at least one field are required.');
        }

        $forms = self::forms();
        $forms[$id] = [
            'name' => $name,
            'sections' => $sections,
            'redirect_url' => esc_url_raw((string) ($_POST['redirect_url'] ?? '')),
            'fields' => array_values(array_map(
                static fn(array $field): string => $field['key'],
                $fields
            )),
        ];
        update_option(self::OPTION_KEY, $forms, false);
        wp_safe_redirect(self::url(['saved' => 1]));
        exit;
    }

    public static function deleteForm(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $id = sanitize_key((string) ($_GET['id'] ?? ''));
        check_admin_referer('delete_patient_account_form_' . $id);
        $forms = self::forms();
        unset($forms[$id]);
        update_option(self::OPTION_KEY, $forms, false);
        wp_safe_redirect(self::url(['deleted' => 1]));
        exit;
    }

    public static function renderShortcode(array $attributes): string
    {
        if (!is_user_logged_in()) {
            return '<p class="cliniko-patient-form-login-required">Please log in to manage your patient details.</p>';
        }

        $id = sanitize_key((string) ($attributes['id'] ?? ''));
        $form = self::forms()[$id] ?? null;
        if (!is_array($form)) {
            return current_user_can('manage_options') ? '<p>Patient account form not found.</p>' : '';
        }

        $definitions = self::editableFields();
        $layout = self::normaliseSections(
            $form['sections'] ?? ($form['rows'] ?? ($form['fields'] ?? [])),
            $definitions,
            $id
        );

        try {
            $patient = Auth::patientData();
            if ($patient === null && MockPatientData::enabled()) {
                $patient = MockPatientData::make();
            }
            if ($patient === null) {
                return '<p class="cliniko-patient-account-form__message is-error">Patient not found.</p>';
            }
            ShortcodeFormInputRules::enqueueFrontendAssets();
            $frontendStyle = __DIR__ . '/PatientAccountForms/ShortCodeTemplates/patient-form.css';
            wp_enqueue_style(
                'cliniko-patient-account-form',
                plugins_url('PatientAccountForms/ShortCodeTemplates/patient-form.css', __FILE__),
                ['cliniko-shortcode-components', 'cliniko-shortcode-form-input-rules'],
                file_exists($frontendStyle) ? (string) filemtime($frontendStyle) : null
            );
            $frontendScript = __DIR__ . '/PatientAccountForms/ShortCodeTemplates/patient-form.js';
            wp_enqueue_script(
                'cliniko-patient-account-form',
                plugins_url('PatientAccountForms/ShortCodeTemplates/patient-form.js', __FILE__),
                [],
                file_exists($frontendScript) ? (string) filemtime($frontendScript) : null,
                true
            );
            return Phtml::render(__DIR__ . '/PatientAccountForms/ShortCodeTemplates/patient-form.phtml', [
                'formId' => $id,
                'layout' => $layout,
                'definitions' => $definitions,
                'patient' => $patient,
                'returnTo' => self::currentUrl(),
            ]);
        } catch (\Throwable $exception) {
            error_log('Cliniko patient form shortcode failed: ' . $exception->getMessage());
            return '<p class="cliniko-patient-account-form__message is-error">Patient data is temporarily unavailable. Please try again shortly.</p>';
        }
    }

    public static function updatePatient(): void
    {
        if (!is_user_logged_in()) {
            wp_die('Unauthorized');
        }
        check_admin_referer('update_cliniko_patient_account');

        $definitions = self::editableFields();
        $posted = wp_unslash($_POST);
        $formId = sanitize_key((string) ($posted['form_id'] ?? ''));
        $formConfig = self::forms()[$formId] ?? null;
        if (!is_array($formConfig)) {
            wp_die('Patient account form not found.');
        }
        $layout = self::normaliseSections(
            $formConfig['sections'] ?? ($formConfig['rows'] ?? ($formConfig['fields'] ?? [])),
            $definitions,
            $formId
        );
        $configuredFields = self::flattenSections($layout);
        $payload = [];
        $errors = [];
        $switchValues = [];
        $postedSwitches = is_array($posted['cliniko_switches'] ?? null) ? $posted['cliniko_switches'] : [];
        foreach ($configuredFields as $fieldConfig) {
            if ((string) ($fieldConfig['kind'] ?? '') !== 'switch') {
                continue;
            }
            $key = sanitize_key((string) ($fieldConfig['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            if (!array_key_exists($key, $postedSwitches) && !empty($fieldConfig['required'])) {
                $errors[] = (string) ($fieldConfig['label'] ?? 'Conditional choice') . ' is required.';
            }
            $value = is_scalar($postedSwitches[$key] ?? null) ? (string) $postedSwitches[$key] : '';
            $switchValues[$key] = $value === ''
                ? (string) ($fieldConfig['default_value'] ?? 'off') === 'on'
                : in_array(strtolower($value), ['1', 'yes', 'true', 'on'], true);
        }
        foreach ($configuredFields as $fieldConfig) {
            if ((string) ($fieldConfig['kind'] ?? '') === 'switch' || !self::fieldConditionMatches($fieldConfig, $switchValues)) {
                continue;
            }
            $key = (string) $fieldConfig['key'];
            $definition = $definitions[$key] ?? null;
            if (!is_array($definition)) {
                continue;
            }
            if (!array_key_exists($key, $posted)) {
                if (!empty($fieldConfig['required'])) {
                    $errors[] = (string) $fieldConfig['label'] . ' is required.';
                }
                continue;
            }
            if (is_array($posted[$key])) {
                $value = array_values(array_filter(array_map(
                    static fn($item): string => sanitize_textarea_field((string) $item),
                    $posted[$key]
                ), static fn(string $item): bool => $item !== ''));
            } elseif (is_scalar($posted[$key])) {
                $value = sanitize_textarea_field((string) $posted[$key]);
            } else {
                continue;
            }
            $label = (string) $fieldConfig['label'];
            if (!empty($fieldConfig['required']) && self::isEmptyValue($value)) {
                $errors[] = $label . ' is required.';
            }
            $validated = ShortcodeFormInputRules::validateAndNormaliseValue($value, $fieldConfig, $label);
            $value = $validated['value'];
            $errors = array_merge($errors, $validated['errors']);
            if (!empty($definition['custom'])) {
                $payload['custom_fields'][$key] = $value;
            } else {
                $payload[$key] = $value;
            }
        }

        $returnTo = wp_validate_redirect((string) ($posted['return_to'] ?? ''), home_url('/'));
        $returnTo = remove_query_arg([
            'cliniko_patient_saved',
            'cliniko_patient_email_change',
        ], $returnTo);
        if ($errors !== []) {
            wp_safe_redirect(add_query_arg('cliniko_patient_saved', '0', $returnTo));
            exit;
        }
        try {
            if (isset($payload['email']) && is_scalar($payload['email'])) {
                $requestedEmail = sanitize_email((string) $payload['email']);
                unset($payload['email']);
                $user = wp_get_current_user();
                $currentEmail = strtolower(trim((string) $user->user_email));
                if ($requestedEmail !== '' && !hash_equals($currentEmail, strtolower($requestedEmail))) {
                    $emailChange = (new PatientEmailChangeService())->requestForCurrentUser($requestedEmail);
                    $returnTo = add_query_arg(
                        'cliniko_patient_email_change',
                        sanitize_key($emailChange['status']),
                        $returnTo
                    );
                }
            }

            // echo "<pre style='margin-bottom: 200px;'>";
            // print_r(json_encode($payload));            
            // echo "<pre style='margin-bottom: 200px;'>";
            //                 die();
            $patient = $payload === [] ? [] : (new PatientService())->updatePatientForCurrentUser($payload);
            $redirect = wp_validate_redirect((string) ($formConfig['redirect_url'] ?? ''), '');
            if ($redirect === '') {
                $redirect = ShortcodeCatalog::redirectFor('cliniko_patient_form', (string) ($posted['form_id'] ?? ''));
            }
            if ($patient !== null && $redirect === '') {
                $redirect = ShortcodeCatalog::defaultRedirectFor('cliniko_patient_form');
            }
            if ($patient !== null && $redirect !== '') {
                $returnTo = add_query_arg('cliniko_patient_saved', '1', $redirect);
            } else {
                $returnTo = add_query_arg('cliniko_patient_saved', $patient !== null ? '1' : '0', $returnTo);
            }
        } catch (\Throwable $exception) {
            error_log('Cliniko patient form submission failed: ' . $exception->getMessage());
            $returnTo = add_query_arg('cliniko_patient_saved', '0', $returnTo);
        }
        wp_safe_redirect($returnTo);
        exit;
    }

    /** @return array<string,array<string,mixed>> */
    private static function forms(): array
    {
        $forms = get_option(self::OPTION_KEY, []);
        return is_array($forms) ? $forms : [];
    }

    /** @return array<string,array<string,mixed>> */
    private static function editableFields(): array
    {
        return array_filter(
            PatientFieldRegistry::available(),
            static fn(array $definition): bool => $definition['editable']
        );
    }

    /** @param array<int|string,mixed> $rawFields @param array<string,array<string,mixed>>|null $available @return array<int,array<string,mixed>> */
    private static function normaliseFields(array $rawFields, ?array $available = null, array $availableSwitches = []): array
    {
        $available = $available ?? self::editableFields();
        $result = [];
        $seen = [];
        foreach ($rawFields as $raw) {
            $config = is_array($raw) ? $raw : ['key' => $raw];
            $key = sanitize_key((string) ($config['key'] ?? ''));
            if ($key === '' || isset($seen[$key])) continue;
            if (sanitize_key((string) ($config['kind'] ?? '')) === 'switch') {
                $seen[$key] = true;
                $width = (int) ($config['width'] ?? 100);
                $label = sanitize_text_field((string) ($config['label'] ?? ''));
                $onLabel = sanitize_text_field((string) ($config['on_label'] ?? ''));
                $offLabel = sanitize_text_field((string) ($config['off_label'] ?? ''));
                $result[] = [
                    'kind' => 'switch',
                    'key' => $key,
                    'label' => $label !== '' ? $label : 'Do you have additional details to provide?',
                    'on_label' => $onLabel !== '' ? $onLabel : 'Yes',
                    'off_label' => $offLabel !== '' ? $offLabel : 'No',
                    'default_value' => (string) ($config['default_value'] ?? 'off') === 'on' ? 'on' : 'off',
                    'required' => !array_key_exists('required', $config) || !empty($config['required']),
                    'width' => in_array($width, [50, 100], true) ? $width : 100,
                ];
                continue;
            }
            if (!isset($available[$key])) continue;
            $seen[$key] = true;
            $field = array_merge([
                'key' => $key,
                'label' => sanitize_text_field((string) ($config['label'] ?? $available[$key]['label'])),
                'required' => !empty($config['required']),
                'width' => max(25, min(100, (int) ($config['width'] ?? 100))),
            ], ShortcodeFormInputRules::normalise($config, self::inputRuleCapabilities($available[$key])));
            $conditionSwitch = sanitize_key((string) ($config['condition_switch'] ?? ''));
            $field['condition_switch'] = isset($availableSwitches[$conditionSwitch]) && $conditionSwitch !== $key ? $conditionSwitch : '';
            $field['condition_value'] = (string) ($config['condition_value'] ?? 'on') === 'off' ? 'off' : 'on';
            $result[] = $field;
        }
        return $result;
    }

    /**
     * @param array<int|string,mixed> $rawSections
     * @param array<string,array<string,mixed>>|null $available
     * @return array<int,array{id:string,name:string,columns:array<int,array{width:int,fields:array<int,array<string,mixed>>}>}>
     */
    private static function normaliseSections(array $rawSections, ?array $available = null, string $scope = ''): array
    {
        $available = $available ?? self::editableFields();
        $availableSwitches = [];
        foreach ($rawSections as $rawSection) {
            foreach ((array) (is_array($rawSection) ? ($rawSection['columns'] ?? []) : []) as $rawColumn) {
                foreach ((array) (is_array($rawColumn) ? ($rawColumn['fields'] ?? []) : []) as $rawField) {
                    if (!is_array($rawField) || sanitize_key((string) ($rawField['kind'] ?? '')) !== 'switch') continue;
                    $switchKey = sanitize_key((string) ($rawField['key'] ?? ''));
                    if ($switchKey !== '') $availableSwitches[$switchKey] = true;
                }
            }
        }
        if ($rawSections !== [] && isset($rawSections[0]) && is_array($rawSections[0]) && array_key_exists('columns', $rawSections[0])) {
            $sections = [];
            $usedIds = [];
            foreach ($rawSections as $sectionIndex => $rawSection) {
                if (!is_array($rawSection) || !is_array($rawSection['columns'] ?? null)) continue;
                $name = sanitize_text_field((string) ($rawSection['name'] ?? ''));
                if ($name === '') {
                    $name = 'Section ' . ((int) $sectionIndex + 1);
                }
                $sectionId = self::normaliseSectionId(
                    (string) ($rawSection['id'] ?? ''),
                    $name,
                    $scope,
                    (int) $sectionIndex,
                    $usedIds
                );
                $columns = [];
                foreach ($rawSection['columns'] as $rawColumn) {
                    if (!is_array($rawColumn)) continue;
                    $fields = self::normaliseFields(is_array($rawColumn['fields'] ?? null) ? $rawColumn['fields'] : [], $available, $availableSwitches);
                    $columns[] = ['width' => max(1, min(12, (int) ($rawColumn['width'] ?? 12))), 'fields' => $fields];
                }
                if ($columns !== []) {
                    $sections[] = ['id' => $sectionId, 'name' => $name, 'columns' => $columns];
                }
            }
            return $sections;
        }

        $fields = self::normaliseFields($rawSections, $available, $availableSwitches);
        if ($fields === []) {
            return [];
        }

        $usedIds = [];
        return [[
            'id' => self::normaliseSectionId('', 'Patient details', $scope, 0, $usedIds),
            'name' => 'Patient details',
            'columns' => [['width' => 12, 'fields' => $fields]],
        ]];
    }

    /**
     * @param array<int,array{id:string,name:string,columns:array<int,array{width:int,fields:array<int,array<string,mixed>>}>}> $sections
     * @return array<int,array<string,mixed>>
     */
    private static function flattenSections(array $sections): array
    {
        $fields = [];
        foreach ($sections as $section) foreach ($section['columns'] as $column) foreach ($column['fields'] as $field) $fields[] = $field;
        return $fields;
    }

    /**
     * @param array{id:string,name:string,columns:array<int,array{width:int,fields:array<int,array<string,mixed>>}>} $section
     * @param array<string,array<string,mixed>> $available
     */
    private static function renderBuilderSection(int $sectionIndex, array $section, array $available): void
    {
        ?>
        <section class="cliniko-form-builder__section" data-section data-section-id="<?php echo esc_attr($section['id']); ?>" draggable="false">
            <div class="cliniko-form-builder__section-header">
                <label>Section name <input type="text" value="<?php echo esc_attr($section['name']); ?>" data-section-name required></label>
                <span class="cliniko-form-builder__section-css-id">CSS ID: <code>#<?php echo esc_html($section['id']); ?></code></span>
                <label>Columns <select data-column-count><option value="1" <?php selected(count($section['columns']), 1); ?>>1</option><option value="2" <?php selected(count($section['columns']), 2); ?>>2</option><option value="3" <?php selected(count($section['columns']), 3); ?>>3</option></select></label>
                <button type="button" class="button-link-delete" data-remove-section>Remove section</button>
            </div>
            <div class="cliniko-form-builder__columns" data-columns>
            <?php foreach ($section['columns'] as $column) : ?>
                <div class="cliniko-form-builder__column" data-column style="width:<?php echo esc_attr((string) (($column['width'] / 12) * 100)); ?>%">
                    <div class="cliniko-form-builder__column-fields" data-column-fields>
                    <?php foreach ($column['fields'] as $config) :
                        if ((string) ($config['kind'] ?? '') === 'switch') { self::renderBuilderSwitch($config); continue; }
                        $fieldKey = (string) ($config['key'] ?? '');
                        if ($fieldKey === '' || !isset($available[$fieldKey])) continue;
                        self::renderBuilderField($fieldKey, $config, $available[$fieldKey], $available);
                    endforeach; ?>
                    </div>
                    <div class="cliniko-form-builder__add-actions"><button type="button" class="cliniko-form-builder__add-field" data-add-field>＋ Add patient field</button><button type="button" class="button" data-add-switch>Add conditional choice</button></div><div class="cliniko-form-builder__field-palette" data-field-palette hidden></div>
                </div>
            <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    /** @param array<string,bool> $usedIds */
    private static function normaliseSectionId(
        string $requestedId,
        string $name,
        string $scope,
        int $sectionIndex,
        array &$usedIds
    ): string {
        $id = sanitize_html_class($requestedId, '');
        if ($id === '') {
            $slug = sanitize_html_class(sanitize_title($name), 'section');
            $hash = substr(hash('sha256', $scope . '|' . $sectionIndex . '|' . $name), 0, 10);
            $id = 'cliniko-section-' . $slug . '-' . $hash;
        }

        $baseId = $id;
        $suffix = 2;
        while (isset($usedIds[$id])) {
            $id = $baseId . '-' . $suffix;
            $suffix++;
        }
        $usedIds[$id] = true;

        return $id;
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $definition @param array<string,array<string,mixed>> $available */
    private static function renderBuilderField(string $field, array $config, array $definition, array $available): void
    {
        ?>
        <article class="cliniko-form-builder__field cliniko-patient-form-builder-field" draggable="false" data-field-key="<?php echo esc_attr($field); ?>" data-field-kind="patient" data-condition-switch="<?php echo esc_attr((string) ($config['condition_switch'] ?? '')); ?>" data-condition-value="<?php echo esc_attr((string) ($config['condition_value'] ?? 'on')); ?>">
            <span class="dashicons dashicons-menu cliniko-patient-form-builder-field__drag" data-field-drag-handle aria-label="Drag to reorder" title="Drag to reorder"></span>
            <div class="cliniko-patient-form-builder-field__main">
                <label>Patient field <select data-field-selector required><?php self::renderPatientFieldOptions($field, $available); ?></select></label>
                <label>Visible label <input type="text" value="<?php echo esc_attr($config['label']); ?>" data-field-label /></label>
                <label>Width <select data-field-width><?php foreach ([25, 50, 75, 100] as $width) : ?><option value="<?php echo $width; ?>" <?php selected($config['width'], $width); ?>><?php echo $width; ?>%</option><?php endforeach; ?></select></label>
                <label class="cliniko-patient-form-builder-field__required"><input type="checkbox" data-field-required <?php checked($config['required']); ?> /> Required when the patient submits this form</label>
                <?php ShortcodeFormInputRules::renderAdminControls('builder_fields[' . $field . ']', $config, self::inputRuleCapabilities($definition)); ?>
            </div>
            <div class="cliniko-patient-form-builder-field__actions"><button type="button" class="button-link-delete" data-remove-field>Remove</button></div>
        </article>
        <?php
    }

    /** @param array<string,mixed> $config */
    private static function renderBuilderSwitch(array $config): void
    {
        $key = sanitize_key((string) ($config['key'] ?? ''));
        if ($key === '') return;
        ?>
        <article class="cliniko-form-builder__field cliniko-patient-form-builder-field cliniko-patient-form-builder-field--switch" draggable="false" data-field-key="<?php echo esc_attr($key); ?>" data-field-kind="switch">
            <span class="dashicons dashicons-randomize cliniko-patient-form-builder-field__drag" data-field-drag-handle aria-label="Drag to reorder" title="Drag to reorder"></span>
            <div class="cliniko-patient-form-builder-field__main cliniko-patient-form-builder-switch__main">
                <div class="cliniko-patient-form-builder-switch__heading"><strong>Conditional choice</strong><span>A two-option answer that can show, hide, and conditionally require other fields.</span></div>
                <label>Question shown to patient <input type="text" data-field-switch-label value="<?php echo esc_attr((string) $config['label']); ?>" required></label>
                <div class="cliniko-patient-form-builder-switch__options"><label>First option label <input type="text" data-field-switch-on-label value="<?php echo esc_attr((string) $config['on_label']); ?>" required></label><label>Second option label <input type="text" data-field-switch-off-label value="<?php echo esc_attr((string) $config['off_label']); ?>" required></label><label>Selected by default <select data-field-switch-default><option value="on" <?php selected((string) $config['default_value'], 'on'); ?>><?php echo esc_html((string) $config['on_label']); ?></option><option value="off" <?php selected((string) $config['default_value'], 'off'); ?>><?php echo esc_html((string) $config['off_label']); ?></option></select></label></div>
                <label>Width <select data-field-width><option value="50" <?php selected((int) $config['width'], 50); ?>>50%</option><option value="100" <?php selected((int) $config['width'], 100); ?>>100%</option></select></label>
                <label class="cliniko-patient-form-builder-field__required"><input type="checkbox" data-field-required <?php checked(!empty($config['required'])); ?>> Require an answer before the patient submits this form</label>
            </div>
            <div class="cliniko-patient-form-builder-field__actions"><button type="button" class="button-link-delete" data-remove-field>Remove</button></div>
        </article>
        <?php
    }

    /** @param array<string,mixed> $field @param array<string,bool> $switchValues */
    private static function fieldConditionMatches(array $field, array $switchValues): bool
    {
        $switchKey = sanitize_key((string) ($field['condition_switch'] ?? ''));
        if ($switchKey === '') return true;
        $expected = (string) ($field['condition_value'] ?? 'on') !== 'off';
        return !empty($switchValues[$switchKey]) === $expected;
    }

    /** @param array<string,array<string,mixed>> $available */
    private static function renderPatientFieldOptions(string $selected, array $available): void
    {
        $groups = [
            'Patient fields' => array_filter($available, static fn(array $definition): bool => empty($definition['custom'])),
            'Cliniko custom fields' => array_filter($available, static fn(array $definition): bool => !empty($definition['custom'])),
        ];
        foreach ($groups as $label => $fields) {
            if ($fields === []) continue;
            ?><optgroup label="<?php echo esc_attr($label); ?>"><?php
            foreach ($fields as $key => $fieldDefinition) {
                ?><option value="<?php echo esc_attr((string) $key); ?>" <?php selected($selected, (string) $key); ?>><?php echo esc_html((string) ($fieldDefinition['label'] ?? $key)); ?></option><?php
            }
            ?></optgroup><?php
        }
    }

    /** @param array<string,mixed> $definition @return array<string,bool> */
    private static function inputRuleCapabilities(array $definition): array
    {
        $type = strtolower((string) ($definition['type'] ?? 'text'));
        $clinikoType = strtolower((string) ($definition['cliniko_field_type'] ?? ''));
        $isChoice = in_array($type, ['checkbox', 'checkboxes', 'multi_checkbox', 'multi_select', 'hidden', 'paragraph'], true)
            || in_array($clinikoType, ['checkbox', 'checkboxes', 'multi_checkbox', 'radiobuttons', 'radio'], true);
        $isScalar = !$isChoice;
        return [
            'text' => $isScalar,
            'date' => $isScalar && in_array($type, ['text', 'tel', 'date'], true),
            'select' => $isScalar && in_array($type, ['text', 'tel', 'select', 'textarea'], true),
            'limit' => $isScalar && in_array($type, ['text', 'tel', 'email', 'url', 'search', 'textarea', 'number', 'date'], true),
            'format' => $isScalar && in_array($type, ['text', 'tel', 'number'], true),
        ];
    }

    /** @param mixed $value */
    private static function isEmptyValue($value): bool
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn($item): bool => trim((string) $item) !== '')) === [];
        }
        return $value === null || trim((string) $value) === '';
    }

    /** @param array<string,int|string> $args */
    private static function url(array $args = []): string
    {
        return AccountBuilders::url(AccountBuilders::TAB_PATIENT_FORMS, $args);
    }

    private static function currentUrl(): string
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '/';
        $homePath = untrailingslashit((string) wp_parse_url(home_url('/'), PHP_URL_PATH));
        if ($homePath !== '' && $homePath !== '/' && str_starts_with($requestUri, $homePath . '/')) {
            $requestUri = substr($requestUri, strlen($homePath));
        }

        return wp_validate_redirect(home_url('/' . ltrim($requestUri, '/')), home_url('/'));
    }
}
