<?php

namespace App\Admin\Modules\AccountBuilders\Forms;

use App\Admin\Modules\AccountBuilders\AccountBuilders;

use App\Model\PatientFormTemplate;
use App\Service\PatientFormTemplateSubmissionService;
use App\Support\Auth;
use App\Support\Phtml;
use App\Admin\Modules\AccountBuilders\Shortcodes\ShortcodeCatalog;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientFormTemplateForms
{
    private const OPTION_KEY = 'wp_cliniko_patient_form_template_shortcodes';

    public static function init(): void
    {
        add_action('admin_post_wp_cliniko_patient_template_shortcode_save', [self::class, 'save']);
        add_action('admin_post_wp_cliniko_patient_template_shortcode_delete', [self::class, 'delete']);
        add_action('admin_post_wp_cliniko_patient_template_submit', [self::class, 'submit']);
        add_shortcode('cliniko_patient_form_template', [self::class, 'renderShortcode']);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $action = sanitize_key((string) ($_GET['action'] ?? 'list'));
        if ($action === 'edit' || $action === 'new') {
            self::renderEditor(
                sanitize_key((string) ($_GET['id'] ?? '')),
                sanitize_text_field((string) ($_GET['template_id'] ?? ''))
            );
            return;
        }

        self::renderList();
    }

    private static function renderList(): void
    {
        try {
            $templates = PatientFormTemplate::all(cliniko_client(false), true);
        } catch (\Throwable $exception) {
            error_log('Cliniko patient form template list failed: ' . $exception->getMessage());
            $templates = [];
            $loadFailed = true;
        }

        $byTemplate = [];
        foreach (self::forms() as $id => $form) {
            $templateId = trim((string) ($form['template_id'] ?? ''));
            if ($templateId !== '') {
                $byTemplate[$templateId] = ['id' => $id, 'form' => $form];
            }
        }
        ?>
        <div class="wrap">
            <h1>Patient Form Template Shortcodes</h1>
            <p>Select a Cliniko patient form template and create a shortcode that logged-in, verified patients can submit.<br><a href="#" class="cliniko-shortcode-style-guide-link" data-shortcode-guide="cliniko_patient_form_template">Open styling guide</a></p>

            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Patient form template shortcode saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p>Shortcode configuration deleted. The Cliniko template was not changed.</p></div><?php endif; ?>
            <?php if (!empty($loadFailed)) : ?><div class="notice notice-error"><p>The Cliniko patient form templates could not be loaded.</p></div><?php endif; ?>

            <table class="widefat striped" style="max-width:1200px;margin-top:20px">
                <thead>
                    <tr>
                        <th>Template</th>
                        <th>Sections</th>
                        <th>Questions</th>
                        <th>Access</th>
                        <th>Status</th>
                        <th>Shortcode</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($templates === []) : ?>
                    <tr><td colspan="7">No patient form templates found.</td></tr>
                <?php else : foreach ($templates as $template) :
                    $templateId = (string) $template->getId();
                    $sections = $template->getSections();
                    $questionCount = array_sum(array_map(
                        static fn($section): int => count($section->questions),
                        $sections
                    ));
                    $saved = $byTemplate[$templateId] ?? null;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) $template->getName()); ?></strong><br><small><?php echo esc_html($templateId); ?></small></td>
                        <td><?php echo esc_html((string) count($sections)); ?></td>
                        <td><?php echo esc_html((string) $questionCount); ?></td>
                        <td><?php echo $template->isRestrictedToPractitioner() ? 'Practitioner only' : 'Patient accessible'; ?></td>
                        <td><?php echo $template->isArchived() ? 'Archived' : 'Active'; ?></td>
                        <td>
                            <?php if (is_array($saved)) : ?>
                                <code>[cliniko_patient_form_template id="<?php echo esc_attr((string) $saved['id']); ?>"]</code>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if (is_array($saved)) : ?>
                                <a href="<?php echo esc_url(self::url(['action' => 'edit', 'id' => (string) $saved['id']])); ?>">Edit shortcode</a>
                            <?php else : ?>
                                <a class="button button-small" href="<?php echo esc_url(self::url(['action' => 'new', 'template_id' => $templateId])); ?>">Create shortcode</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderEditor(string $id, string $requestedTemplateId): void
    {
        $forms = self::forms();
        $form = $forms[$id] ?? null;
        $templateId = is_array($form)
            ? trim((string) ($form['template_id'] ?? ''))
            : trim($requestedTemplateId);
        $template = $templateId !== ''
            ? PatientFormTemplate::find($templateId, cliniko_client(true), true)
            : null;

        if ($template === null) {
            echo '<div class="wrap"><div class="notice notice-error"><p>Patient form template not found.</p></div></div>';
            return;
        }

        $name = is_array($form) ? (string) ($form['name'] ?? '') : (string) $template->getName();
        $submitLabel = is_array($form) ? (string) ($form['submit_label'] ?? 'Submit form') : 'Submit form';
        $successMessage = is_array($form)
            ? (string) ($form['success_message'] ?? 'Your patient form was submitted.')
            : 'Your patient form was submitted.';
        $fieldRules = self::normaliseFieldRules($template, is_array($form) && is_array($form['field_rules'] ?? null) ? $form['field_rules'] : []);
        $builderStyle = dirname(__DIR__, 3) . '/assets/patient-account-form-builder.css';
        wp_enqueue_style(
            'cliniko-patient-account-form-builder',
            plugins_url('../../../assets/patient-account-form-builder.css', __FILE__),
            [],
            file_exists($builderStyle) ? (string) filemtime($builderStyle) : null
        );
        ShortcodeFormInputRules::enqueueBuilderAssets();
        ?>
        <div class="wrap">
            <h1><?php echo $id !== '' ? 'Edit Patient Form Template Shortcode' : 'Create Patient Form Template Shortcode'; ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-form-builder">
                <input type="hidden" name="action" value="wp_cliniko_patient_template_shortcode_save">
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
                <input type="hidden" name="template_id" value="<?php echo esc_attr($templateId); ?>">
                <?php wp_nonce_field('save_patient_template_shortcode'); ?>
                <div class="cliniko-form-builder__layout">
                    <main>
                        <input class="cliniko-form-builder__name" type="text" name="name" value="<?php echo esc_attr($name); ?>" placeholder="Form name" required>
                        <section class="cliniko-form-builder__panel">
                            <header><strong><?php echo esc_html((string) $template->getName()); ?></strong></header>
                            <div data-builder-panel>
                                <p>This structure is loaded from Cliniko and cannot be changed by the shortcode builder.</p>
                                <?php self::renderPreview($template, $fieldRules); ?>
                            </div>
                        </section>
                    </main>
                    <aside class="cliniko-form-builder__sidebar">
                        <section class="cliniko-form-builder__sidebox">
                            <h2>Publish</h2>
                            <p><label for="cliniko-template-submit-label">Submit button label</label></p>
                            <input id="cliniko-template-submit-label" class="widefat" type="text" name="submit_label" value="<?php echo esc_attr($submitLabel); ?>" required>
                            <p><label for="cliniko-template-success-message">Success message</label></p>
                            <textarea id="cliniko-template-success-message" class="widefat" name="success_message" rows="3" required><?php echo esc_textarea($successMessage); ?></textarea>
                            <p><label for="cliniko-template-redirect">Redirect override after successful submission</label></p>
                            <input id="cliniko-template-redirect" class="widefat" type="url" name="redirect_url" value="<?php echo esc_attr((string) ($form['redirect_url'] ?? '')); ?>" placeholder="Leave blank to use the default">
                            <p class="description">Optional. This overrides the generic destination configured in the Redirects tab.</p>
                            <p><button type="submit" class="button button-primary button-large">Create / Update shortcode</button></p>
                        </section>
                        <section class="cliniko-form-builder__sidebox">
                            <h2>Shortcode</h2>
                            <?php if ($id !== '') : ?>
                                <code>[cliniko_patient_form_template id="<?php echo esc_attr($id); ?>"]</code>
                            <?php else : ?>
                                <p>Save this configuration to generate its shortcode.</p>
                            <?php endif; ?>
                        </section>
                        <?php if ($id !== '') : ?>
                            <section class="cliniko-form-builder__sidebox">
                                <h2>Configuration</h2>
                                <a class="button-link-delete" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_cliniko_patient_template_shortcode_delete&id=' . rawurlencode($id)), 'delete_patient_template_shortcode_' . $id)); ?>" onclick="return confirm('Delete this shortcode configuration? The Cliniko template will not be changed.');">Delete shortcode configuration</a>
                            </section>
                        <?php endif; ?>
                    </aside>
                </div>
            </form>
        </div>
        <?php
    }

    /** @param array<string,array<string,mixed>> $fieldRules */
    private static function renderPreview(PatientFormTemplate $template, array $fieldRules): void
    {
        ?>
        <div class="cliniko-form-builder__preview-form">
            <?php foreach ($template->getSections() as $sectionIndex => $section) : ?>
                <section style="margin-bottom:24px">
                    <h3><?php echo esc_html((string) $section->name); ?></h3>
                    <?php if ((string) $section->description !== '') : ?><p><?php echo wp_kses_post((string) $section->description); ?></p><?php endif; ?>
                    <?php foreach ($section->questions as $questionIndex => $question) :
                        $ruleKey = self::questionRuleKey((int) $sectionIndex, (int) $questionIndex);
                        self::renderPreviewQuestion($sectionIndex, $questionIndex, $question, $fieldRules[$ruleKey] ?? []);
                    endforeach; ?>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /** @param array<string,mixed> $rule */
    private static function renderPreviewQuestion(int $sectionIndex, int $questionIndex, $question, array $rule): void
    {
        $type = strtolower((string) $question->type);
        $name = 'preview_' . $sectionIndex . '_' . $questionIndex;
        $configuredType = (string) ($rule['input_type'] ?? 'default');
        $renderType = $configuredType === 'default' ? $type : $configuredType;
        $previewOptions = array_map('strval', (array) ($rule['select_options'] ?? []));
        ?>
        <div class="cliniko-form-builder__preview-field">
            <label><?php echo esc_html($question->name); ?><?php echo $question->required ? ' *' : ''; ?></label>
            <?php if ($renderType === 'select' && $previewOptions !== []) : ?>
                <select disabled><option>Select</option><?php foreach ($previewOptions as $option) : ?><option><?php echo esc_html($option); ?></option><?php endforeach; ?></select>
            <?php elseif ($renderType === 'date') : ?>
                <input disabled type="text" placeholder="<?php echo esc_attr(ShortcodeFormInputRules::datePlaceholder((string) ($rule['date_format'] ?? 'dmy_slash'))); ?>">
            <?php elseif (in_array($type, ['textarea', 'paragraph', 'long_text', 'longtext', 'text_area', 'multiline'], true) && $renderType !== 'text') : ?>
                <textarea disabled></textarea>
            <?php elseif (in_array($type, ['checkboxes', 'radiobuttons'], true)) : ?>
                <?php foreach ($question->answers as $answer) : if (!is_array($answer)) continue; ?>
                    <label><input disabled type="<?php echo $type === 'checkboxes' ? 'checkbox' : 'radio'; ?>" name="<?php echo esc_attr($name); ?>"> <?php echo esc_html((string) ($answer['value'] ?? '')); ?></label>
                <?php endforeach; ?>
                <?php if ($question->other !== null && !empty($question->other->enabled)) : ?><label><input disabled type="<?php echo $type === 'checkboxes' ? 'checkbox' : 'radio'; ?>" name="<?php echo esc_attr($name); ?>"> Other</label><?php endif; ?>
            <?php elseif ($type === 'signature') : ?>
                <p><em>Signature questions are not supported by this shortcode yet.</em></p>
            <?php else : ?>
                <input disabled type="text">
            <?php endif; ?>
            <?php if (!in_array($type, ['checkboxes', 'radiobuttons', 'signature'], true)) :
                ShortcodeFormInputRules::renderAdminControls(
                    'field_rules[' . self::questionRuleKey($sectionIndex, $questionIndex) . ']',
                    $rule,
                    self::questionRuleCapabilities($type)
                );
            endif; ?>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('save_patient_template_shortcode');

        $id = sanitize_key((string) ($_POST['id'] ?? ''));
        $templateId = sanitize_text_field((string) ($_POST['template_id'] ?? ''));
        $template = $templateId !== '' ? PatientFormTemplate::find($templateId, cliniko_client(true), true) : null;
        if ($template === null) {
            wp_die('Patient form template not found.');
        }

        if ($id === '') {
            $id = 'patient_form_' . wp_generate_uuid4();
        }
        $id = sanitize_key($id);
        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        $submitLabel = sanitize_text_field((string) ($_POST['submit_label'] ?? ''));
        $successMessage = sanitize_text_field((string) ($_POST['success_message'] ?? ''));
        if ($name === '' || $submitLabel === '' || $successMessage === '') {
            wp_die('Form name, submit label, and success message are required.');
        }
        $rawFieldRules = isset($_POST['field_rules']) && is_array($_POST['field_rules'])
            ? wp_unslash($_POST['field_rules'])
            : [];

        $forms = self::forms();
        $forms[$id] = [
            'name' => $name,
            'template_id' => $templateId,
            'submit_label' => $submitLabel,
            'success_message' => $successMessage,
            'redirect_url' => esc_url_raw((string) ($_POST['redirect_url'] ?? '')),
            'field_rules' => self::normaliseFieldRules($template, $rawFieldRules),
        ];
        update_option(self::OPTION_KEY, $forms, false);
        wp_safe_redirect(self::url(['saved' => 1]));
        exit;
    }

    public static function delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $id = sanitize_key((string) ($_GET['id'] ?? ''));
        check_admin_referer('delete_patient_template_shortcode_' . $id);
        $forms = self::forms();
        unset($forms[$id]);
        update_option(self::OPTION_KEY, $forms, false);
        wp_safe_redirect(self::url(['deleted' => 1]));
        exit;
    }

    /** @param array<string,mixed> $attributes */
    public static function renderShortcode(array $attributes): string
    {
        if (!is_user_logged_in()) {
            return '<p class="cliniko-patient-template-form__message is-error">Please log in to complete this patient form.</p>';
        }

        $id = sanitize_key((string) ($attributes['id'] ?? ''));
        $form = self::forms()[$id] ?? null;
        if (!is_array($form)) {
            return current_user_can('manage_options') ? '<p>Patient form template shortcode not found.</p>' : '';
        }

        try {
            $templateId = trim((string) ($form['template_id'] ?? ''));
            $template = PatientFormTemplate::find(
                $templateId,
                function_exists('cliniko_dashboard_client')
                    ? cliniko_dashboard_client(null, 900)
                    : cliniko_client(false)
            );
            if ($template === null) {
                return '<p class="cliniko-patient-template-form__message is-error">This patient form could not be found.</p>';
            }

            $style = __DIR__ . '/PatientFormTemplateForms/ShortCodeTemplates/patient-form-template.css';
            ShortcodeFormInputRules::enqueueFrontendAssets();
            wp_enqueue_style(
                'cliniko-patient-form-template',
                plugins_url('PatientFormTemplateForms/ShortCodeTemplates/patient-form-template.css', __FILE__),
                ['cliniko-shortcode-components', 'cliniko-shortcode-form-input-rules'],
                file_exists($style) ? (string) filemtime($style) : null
            );

            return Phtml::render(__DIR__ . '/PatientFormTemplateForms/ShortCodeTemplates/patient-form-template.phtml', [
                'builderId' => $id,
                'form' => $form,
                'template' => $template,
                'fieldRules' => self::normaliseFieldRules($template, is_array($form['field_rules'] ?? null) ? $form['field_rules'] : []),
                'returnTo' => self::currentUrl(),
            ]);
        } catch (\Throwable $exception) {
            error_log('Cliniko patient form template shortcode failed: ' . $exception->getMessage());
            return '<p class="cliniko-patient-template-form__message is-error">Patient data is temporarily unavailable. Please try again shortly.</p>';
        }
    }

    public static function submit(): void
    {
        if (!is_user_logged_in()) {
            wp_die('Unauthorized');
        }

        $posted = wp_unslash($_POST);
        $id = sanitize_key((string) ($posted['builder_id'] ?? ''));
        check_admin_referer('submit_patient_template_form_' . $id);
        $form = self::forms()[$id] ?? null;
        $returnTo = wp_validate_redirect((string) ($posted['return_to'] ?? ''), home_url('/'));
        if (!is_array($form)) {
            wp_safe_redirect(add_query_arg('cliniko_patient_form_submitted', '0', $returnTo));
            exit;
        }

        $patient = Auth::user();
        if ($patient === null) {
            wp_safe_redirect(add_query_arg('cliniko_patient_form_submitted', '0', $returnTo));
            exit;
        }

        $userId = (int) get_current_user_id();
        $lockKey = 'cliniko_patient_form_submit_' . substr(hash('sha256', $userId . '|' . $id), 0, 32);
        if (get_transient($lockKey)) {
            wp_safe_redirect(add_query_arg('cliniko_patient_form_submitted', '0', $returnTo));
            exit;
        }
        set_transient($lockKey, 1, 30);

        try {
            $answers = is_array($posted['answers'] ?? null) ? $posted['answers'] : [];
            $other = is_array($posted['other'] ?? null) ? $posted['other'] : [];
            $template = PatientFormTemplate::find((string) ($form['template_id'] ?? ''), cliniko_client(false));
            if ($template === null) {
                throw new \RuntimeException('Patient form template not found.');
            }
            $fieldRules = self::normaliseFieldRules($template, is_array($form['field_rules'] ?? null) ? $form['field_rules'] : []);
            $inputErrors = [];
            foreach ($template->getSections() as $sectionIndex => $section) {
                foreach ($section->questions as $questionIndex => $question) {
                    $ruleKey = self::questionRuleKey((int) $sectionIndex, (int) $questionIndex);
                    if (!isset($fieldRules[$ruleKey]) || !isset($answers[$sectionIndex]) || !is_array($answers[$sectionIndex]) || !array_key_exists($questionIndex, $answers[$sectionIndex])) {
                        continue;
                    }
                    $validated = ShortcodeFormInputRules::validateAndNormaliseValue(
                        $answers[$sectionIndex][$questionIndex],
                        $fieldRules[$ruleKey],
                        (string) $question->name
                    );
                    $answers[$sectionIndex][$questionIndex] = $validated['value'];
                    $inputErrors = array_merge($inputErrors, $validated['errors']);
                }
            }
            if ($inputErrors !== []) {
                throw new \InvalidArgumentException(implode(' ', $inputErrors));
            }
            (new PatientFormTemplateSubmissionService())->submit(
                $patient,
                (string) ($form['template_id'] ?? ''),
                (string) ($form['name'] ?? ''),
                $answers,
                $other
            );
            $redirect = wp_validate_redirect((string) ($form['redirect_url'] ?? ''), '');
            if ($redirect === '') {
                $redirect = ShortcodeCatalog::redirectFor('cliniko_patient_form_template', $id);
            }
            if ($redirect === '') {
                $redirect = ShortcodeCatalog::defaultRedirectFor('cliniko_patient_form_template');
            }
            wp_safe_redirect(add_query_arg('cliniko_patient_form_submitted', '1', $redirect !== '' ? $redirect : $returnTo));
        } catch (\Throwable $exception) {
            delete_transient($lockKey);
            error_log('Cliniko patient form template submission failed: ' . $exception->getMessage());
            wp_safe_redirect(add_query_arg('cliniko_patient_form_submitted', '0', $returnTo));
        }
        exit;
    }

    /** @return array<string,array<string,mixed>> */
    private static function forms(): array
    {
        $forms = get_option(self::OPTION_KEY, []);
        return is_array($forms) ? $forms : [];
    }

    private static function questionRuleKey(int $sectionIndex, int $questionIndex): string
    {
        return 'section_' . $sectionIndex . '_question_' . $questionIndex;
    }

    /** @return array<string,bool> */
    private static function questionRuleCapabilities(string $type): array
    {
        $type = strtolower($type);
        $isTextarea = in_array($type, ['textarea', 'paragraph', 'long_text', 'longtext', 'text_area', 'multiline'], true);
        $isDate = $type === 'date';
        return [
            'text' => true,
            'date' => !$isTextarea,
            'select' => true,
            'limit' => true,
            'format' => !$isDate,
        ];
    }

    /** @param array<string,mixed> $raw @return array<string,array<string,mixed>> */
    private static function normaliseFieldRules(PatientFormTemplate $template, array $raw): array
    {
        $rules = [];
        foreach ($template->getSections() as $sectionIndex => $section) {
            foreach ($section->questions as $questionIndex => $question) {
                $type = strtolower((string) $question->type);
                if (in_array($type, ['checkboxes', 'radiobuttons', 'signature'], true)) {
                    continue;
                }
                $key = self::questionRuleKey((int) $sectionIndex, (int) $questionIndex);
                $config = is_array($raw[$key] ?? null) ? $raw[$key] : [];
                $rules[$key] = ShortcodeFormInputRules::normalise($config, self::questionRuleCapabilities($type));
                if ($type === 'date' && $rules[$key]['input_type'] === 'default') {
                    $rules[$key]['input_type'] = 'date';
                }
            }
        }
        return $rules;
    }

    /** @param array<string,int|string> $args */
    private static function url(array $args = []): string
    {
        return AccountBuilders::url(
            AccountBuilders::TAB_PATIENT_FORMS,
            array_merge(['forms_section' => AccountBuilders::FORMS_PATIENT_FORM_TEMPLATES], $args)
        );
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
