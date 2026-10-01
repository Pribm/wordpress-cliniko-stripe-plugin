<?php

namespace App\Admin\Modules\AccountBuilders\Forms;

use App\Admin\Modules\AccountBuilders\AccountBuilders;
use App\Admin\Modules\AccountBuilders\CustomCode;
use App\Admin\Modules\Components\ComponentStyles;
use App\Service\PatientCustomFieldService;
use App\Service\PatientEmailChangeService;
use App\Service\PatientFieldRegistry;
use App\Service\PatientLinkService;
use App\Service\PatientService;
use App\Support\Auth;
use App\Support\Phtml;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configurable, authenticated patient-profile onboarding flows.
 *
 * The module stores only field definitions and presentation settings. Patient
 * values are fetched from Cliniko at render time and are never stored in the
 * onboarding configuration.
 */
final class PatientOnboarding
{
    private const OPTION_KEY = 'wp_cliniko_patient_onboardings';
    private const SHORTCODE = 'cliniko_patient_onboarding';
    private const SAVE_ACTION = 'wp_cliniko_patient_onboarding_save';
    private const DELETE_ACTION = 'wp_cliniko_patient_onboarding_delete';
    private const SWITCH_META_PREFIX = '_wp_cliniko_onboarding_switches_';
    public const PATIENT_SAVE_ACTION = 'wp_cliniko_patient_onboarding_save_patient';

    public static function init(): void
    {
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'save']);
        add_action('admin_post_' . self::DELETE_ACTION, [self::class, 'delete']);
        add_action('admin_post_' . self::PATIENT_SAVE_ACTION, [self::class, 'savePatient']);
        add_shortcode(self::SHORTCODE, [self::class, 'shortcode']);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $action = sanitize_key((string) ($_GET['action'] ?? 'list'));
        if (in_array($action, ['new', 'edit'], true)) {
            self::renderEditor($action === 'edit' ? sanitize_key((string) ($_GET['id'] ?? '')) : '');
            return;
        }

        $modules = self::modules();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Patient Onboarding</h1>
            <a class="page-title-action" href="<?php echo esc_url(self::url(['action' => 'new'])); ?>">Add New</a>
            <p>Create a multi-step profile setup flow for logged-in, verified Cliniko patients. The flow appears only while its required fields are incomplete.<br><a href="#cliniko-onboarding-help">How it works</a></p>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Patient onboarding saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p>Patient onboarding deleted.</p></div><?php endif; ?>
            <table class="widefat striped" style="max-width:1200px;margin-top:20px">
                <thead><tr><th>Name</th><th>Steps</th><th>Required fields</th><th>Placement</th><th>Status</th><th>Shortcode</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($modules === []) : ?>
                    <tr><td colspan="7">No patient onboarding flows have been created.</td></tr>
                <?php else : foreach ($modules as $id => $module) :
                    $steps = self::effectiveSteps($module);
                    $required = self::requiredFieldCount($steps);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) ($module['name'] ?? $id)); ?></strong></td>
                        <td><?php echo esc_html((string) count($steps)); ?></td>
                        <td><?php echo esc_html((string) $required); ?></td>
                        <td><?php echo esc_html(ucwords(str_replace('_', ' ', self::normalisePlacement($module['placement'] ?? 'inline')))); ?></td>
                        <td><?php echo !empty($module['enabled']) ? 'Enabled' : 'Disabled'; ?></td>
                        <td><code>[<?php echo esc_html(self::SHORTCODE); ?> id="<?php echo esc_attr((string) $id); ?>"]</code></td>
                        <td>
                            <a href="<?php echo esc_url(self::url(['action' => 'edit', 'id' => (string) $id])); ?>">Edit</a>
                            | <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::DELETE_ACTION . '&id=' . rawurlencode((string) $id)), 'delete_patient_onboarding_' . $id)); ?>" onclick="return confirm('Delete this onboarding flow?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <div id="cliniko-onboarding-help" class="notice notice-info inline" style="max-width:1200px;margin-top:24px">
                <p><strong>How it works:</strong> choose the fields that should be collected, organize them into steps, and mark the fields that determine completion. The shortcode performs the completeness check on the server for the authenticated Cliniko patient. Custom Code can style the generated <code>.cliniko-patient-onboarding</code> wrapper.</p>
            </div>
        </div>
        <?php
    }

    private static function renderEditor(string $id): void
    {
        $module = self::modules()[$id] ?? self::defaults();
        $available = self::editableFields();
        $steps = self::normaliseSteps($module['steps'] ?? [], $available);
        $conditions = self::normaliseConditions($module['conditions'] ?? null, $module);
        $conditionLogic = self::normaliseConditionLogic($module['condition_logic'] ?? 'all');
        $pages = self::pages();
        if ($steps === []) {
            $steps = self::normaliseSteps(self::defaults()['steps'], $available);
        }

        $style = dirname(__DIR__, 3) . '/assets/patient-onboarding-builder.css';
        $script = dirname(__DIR__, 3) . '/assets/patient-onboarding-builder.js';
        wp_enqueue_style(
            'cliniko-patient-onboarding-builder',
            plugins_url('../../../assets/patient-onboarding-builder.css', __FILE__),
            [],
            file_exists($style) ? (string) filemtime($style) : null
        );
        wp_enqueue_script(
            'cliniko-patient-onboarding-builder',
            plugins_url('../../../assets/patient-onboarding-builder.js', __FILE__),
            [],
            file_exists($script) ? (string) filemtime($script) : null,
            true
        );
        ?>
        <div class="wrap">
            <h1><?php echo $id !== '' ? 'Edit Patient Onboarding' : 'Add Patient Onboarding'; ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-form-builder" data-cliniko-onboarding-builder>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
                <textarea name="steps_config" data-onboarding-steps-config hidden><?php echo esc_textarea((string) wp_json_encode($steps)); ?></textarea>
                <textarea name="conditions_config" data-onboarding-conditions-config hidden><?php echo esc_textarea((string) wp_json_encode($conditions)); ?></textarea>
                <script type="application/json" data-onboarding-available-fields><?php echo wp_json_encode(array_map(static function (array $definition, string $key): array {
                    return [
                        'key' => $key,
                        'label' => (string) ($definition['label'] ?? $key),
                        'type' => (string) ($definition['type'] ?? 'text'),
                        'cliniko_field_type' => (string) ($definition['cliniko_field_type'] ?? ''),
                        'custom' => !empty($definition['custom']),
                    ];
                 }, $available, array_keys($available))); ?></script>
                <script type="application/json" data-onboarding-pages><?php echo wp_json_encode(array_map(static function (\WP_Post $page): array {
                    return [
                        'id' => (int) $page->ID,
                        'label' => self::pageLabel($page),
                    ];
                }, $pages)); ?></script>
                <?php wp_nonce_field('save_patient_onboarding'); ?>
                <div class="cliniko-onboarding-editor">
                    <header class="cliniko-onboarding-editor__hero">
                        <div>
                            <span class="cliniko-onboarding-editor__eyebrow">Patient experience builder</span>
                            <h2><?php echo $id !== '' ? 'Edit onboarding flow' : 'Create onboarding flow'; ?></h2>
                            <p>Configure the experience in order, then publish and place the generated shortcode on your page.</p>
                        </div>
                        <span class="cliniko-onboarding-editor__status <?php echo !array_key_exists('enabled', $module) || !empty($module['enabled']) ? 'is-enabled' : ''; ?>"><?php echo !array_key_exists('enabled', $module) || !empty($module['enabled']) ? 'Enabled' : 'Disabled'; ?></span>
                    </header>

                    <nav class="cliniko-onboarding-editor__tabs" role="tablist" aria-label="Onboarding builder sections" data-onboarding-builder-tabs>
                        <button type="button" role="tab" aria-selected="true" class="is-active" data-onboarding-tab="setup"><span>1</span> Setup</button>
                        <button type="button" role="tab" aria-selected="false" data-onboarding-tab="content"><span>2</span> Content</button>
                        <button type="button" role="tab" aria-selected="false" data-onboarding-tab="appearance"><span>3</span> Appearance</button>
                        <button type="button" role="tab" aria-selected="false" data-onboarding-tab="steps"><span>4</span> Steps &amp; fields</button>
                    </nav>

                    <div class="cliniko-form-builder__layout cliniko-onboarding-builder">
                        <main>
                            <section class="cliniko-form-builder__panel cliniko-onboarding-tab-panel is-active" data-onboarding-tab-panel="setup">
                                <header><div><strong>Setup</strong><span>Name the flow and choose how patients will see it.</span></div></header>
                                <div class="cliniko-onboarding-panel-body">
                                    <div class="cliniko-onboarding-builder__identity">
                                        <label for="cliniko-onboarding-name">Internal name</label>
                                        <input id="cliniko-onboarding-name" required name="name" placeholder="For example: New patient profile setup" value="<?php echo esc_attr((string) ($module['name'] ?? '')); ?>">
                                        <p>Only administrators see this name. Patients see the title configured in Content.</p>
                                    </div>
                                    <div class="cliniko-onboarding-field-grid">
                                        <label>Display mode
                                            <select name="mode">
                                                <option value="multistep" <?php selected((string) ($module['mode'] ?? 'multistep'), 'multistep'); ?>>Multi-step guided flow</option>
                                                <option value="single" <?php selected((string) ($module['mode'] ?? ''), 'single'); ?>>Single-page form</option>
                                            </select>
                                            <small>Multi-step is recommended for longer onboarding forms.</small>
                                        </label>
                                        <label>Placement
                                            <select name="placement" data-onboarding-placement-control>
                                                <option value="inline" <?php selected(self::normalisePlacement($module['placement'] ?? 'inline'), 'inline'); ?>>Inline within the page</option>
                                                <option value="modal" <?php selected(self::normalisePlacement($module['placement'] ?? 'inline'), 'modal'); ?>>Dismissible overlay</option>
                                                <option value="blocking_overlay" <?php selected(self::normalisePlacement($module['placement'] ?? 'inline'), 'blocking_overlay'); ?>>Required completion overlay</option>
                                            </select>
                                            <small>Overlays appear above the page and do not require an Elementor popup.</small>
                                        </label>
                                        <label data-onboarding-overlay-size-field>Overlay size
                                            <select name="overlay_size">
                                                <option value="contained" <?php selected(self::normaliseOverlaySize($module['overlay_size'] ?? 'contained'), 'contained'); ?>>Centered panel</option>
                                                <option value="full_screen" <?php selected(self::normaliseOverlaySize($module['overlay_size'] ?? 'contained'), 'full_screen'); ?>>Full screen</option>
                                            </select>
                                            <small>Only applies when an overlay placement is selected.</small>
                                        </label>
                                    </div>
                                    <label class="cliniko-onboarding-switch">
                                        <input type="checkbox" name="enabled" value="1" <?php checked(!array_key_exists('enabled', $module) || !empty($module['enabled'])); ?>>
                                        <span><strong>Enable this onboarding</strong><small>Disabled flows do not render for patients.</small></span>
                                    </label>
                                    <section class="cliniko-onboarding-protected-pages">
                                        <header>
                                            <div>
                                                <h3>Protected pages</h3>
                                                <p>Choose the pages where this onboarding is allowed to appear.</p>
                                            </div>
                                            <button type="button" class="button button-secondary" data-onboarding-add-condition>Add page rule</button>
                                        </header>
                                        <label class="cliniko-onboarding-logic">How should multiple rules work?
                                            <select name="condition_logic">
                                                <option value="all" <?php selected($conditionLogic, 'all'); ?>>All page rules must match</option>
                                                <option value="any" <?php selected($conditionLogic, 'any'); ?>>Any page rule may match</option>
                                            </select>
                                            <small>Leave the list empty to allow the shortcode on every page where it is placed.</small>
                                        </label>
                                        <div class="cliniko-onboarding-builder-conditions" data-onboarding-conditions></div>
                                    </section>
                                </div>
                            </section>

                            <section class="cliniko-form-builder__panel cliniko-onboarding-tab-panel" data-onboarding-tab-panel="content" hidden>
                                <header><div><strong>Patient-facing content</strong><span>Write the messages patients see before and after completion.</span></div></header>
                                <div class="cliniko-onboarding-panel-body cliniko-onboarding-field-stack">
                                    <label>Screen title <input type="text" name="title" value="<?php echo esc_attr((string) ($module['title'] ?? 'Complete your profile')); ?>"></label>
                                    <label>Introductory message <textarea name="subtitle" rows="3"><?php echo esc_textarea((string) ($module['subtitle'] ?? 'Please complete the required details before continuing.')); ?></textarea></label>
                                    <div class="cliniko-onboarding-welcome-settings">
                                        <div>
                                            <h3>Welcome screen</h3>
                                            <p>Optionally show branded content before the patient starts the onboarding questions.</p>
                                        </div>
                                        <label class="cliniko-onboarding-switch cliniko-onboarding-switch--accent">
                                            <input type="checkbox" name="welcome_enabled" value="1" <?php checked(!empty($module['welcome_enabled'])); ?> data-onboarding-welcome-enabled>
                                            <span><strong>Show a welcome screen first</strong><small>The patient selects a start button before step one is displayed.</small></span>
                                        </label>
                                        <div class="cliniko-onboarding-welcome-controls" data-onboarding-welcome-controls>
                                            <label>Content source
                                                <select name="welcome_source" data-onboarding-welcome-source>
                                                    <option value="shortcode" <?php selected(self::normaliseWelcomeSource($module['welcome_source'] ?? 'shortcode'), 'shortcode'); ?>>Template or content shortcode</option>
                                                    <option value="html" <?php selected(self::normaliseWelcomeSource($module['welcome_source'] ?? 'shortcode'), 'html'); ?>>Custom HTML</option>
                                                </select>
                                            </label>
                                            <label data-onboarding-welcome-shortcode>Template shortcode
                                                <textarea name="welcome_shortcode" rows="4" placeholder='[elementor-template id="123"]'><?php echo esc_textarea((string) ($module['welcome_shortcode'] ?? '')); ?></textarea>
                                                <small>Accepts Elementor template shortcodes, Gutenberg/block template shortcodes, and other registered WordPress shortcodes.</small>
                                            </label>
                                            <label data-onboarding-welcome-html>Welcome HTML
                                                <textarea name="welcome_html" rows="8" class="code" placeholder="<h2>Welcome</h2>&#10;<p>Let’s get your profile ready.</p>"><?php echo esc_textarea((string) ($module['welcome_html'] ?? '')); ?></textarea>
                                                <small>Safe WordPress post HTML is allowed. Scripts and unsafe attributes are removed.</small>
                                            </label>
                                            <label data-onboarding-welcome-html>Welcome CSS
                                                <textarea name="welcome_css" rows="8" class="code" placeholder="{{WRAPPER}} .welcome-heading {&#10;  color: #111827;&#10;}"><?php echo esc_textarea((string) ($module['welcome_css'] ?? '')); ?></textarea>
                                                <small>Use <code>{{WRAPPER}}</code> to scope styles to this onboarding instance. Do not include &lt;style&gt; tags.</small>
                                            </label>
                                            <label data-onboarding-welcome-html>Welcome JavaScript
                                                <textarea name="welcome_js" rows="8" class="code" placeholder="welcomeRoot.querySelector('.my-button')"><?php echo esc_textarea((string) ($module['welcome_js'] ?? '')); ?></textarea>
                                                <small>The variables <code>onboardingRoot</code> and <code>welcomeRoot</code> are available automatically. Do not include &lt;script&gt; tags.</small>
                                            </label>
                                            <label>Start button label <input type="text" name="welcome_button_label" value="<?php echo esc_attr((string) ($module['welcome_button_label'] ?? 'Get started')); ?>" placeholder="Get started"></label>
                                        </div>
                                    </div>
                                    <label>Questions per step <input type="number" name="questions_per_step" min="0" max="20" value="<?php echo esc_attr((string) ($module['questions_per_step'] ?? 0)); ?>"><small>Use 0 to keep the manual step groups configured under Steps &amp; fields.</small></label>
                                    <label>Completion message <textarea name="completion_message" rows="3"><?php echo esc_textarea((string) ($module['completion_message'] ?? 'Your profile is complete.')); ?></textarea></label>
                                    <label>Completion redirect <input type="url" name="completion_redirect" value="<?php echo esc_attr((string) ($module['completion_redirect'] ?? '')); ?>" placeholder="https://"><small>Optional. Redirects after all required fields are complete.</small></label>
                                    <label class="cliniko-onboarding-switch">
                                        <input type="checkbox" name="show_complete_message" value="1" <?php checked(!empty($module['show_complete_message'])); ?>>
                                        <span><strong>Show a completion message after saving</strong><small>Shown only when the patient has just completed onboarding. Otherwise the shortcode disappears immediately.</small></span>
                                    </label>
                                </div>
                            </section>

                            <section class="cliniko-form-builder__panel cliniko-onboarding-tab-panel" data-onboarding-tab-panel="appearance" hidden>
                                <header><div><strong>Appearance</strong><span>Elementor theme styles are used by default; override only what you need.</span></div></header>
                                <div class="cliniko-onboarding-panel-body">
                                    <div class="cliniko-onboarding-theme-note">
                                        <span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span>
                                        <div><strong>Elementor theme integration</strong><p>The initial design uses Elementor global primary, text, and typography variables. Form inputs remain high-contrast for accessibility.</p></div>
                                    </div>
                                    <div class="cliniko-onboarding-field-grid">
                                        <label>Font family
                                            <select name="font_family">
                                                <?php foreach (self::fontFamilies() as $fontValue => $fontLabel) : ?><option value="<?php echo esc_attr($fontValue); ?>" <?php selected((string) ($module['font_family'] ?? ''), $fontValue); ?>><?php echo esc_html($fontLabel); ?></option><?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label>Heading size <span class="cliniko-onboarding-input-suffix"><input type="number" name="heading_font_size" min="0" max="72" step="1" value="<?php echo esc_attr((string) ($module['heading_font_size'] ?? '')); ?>"><em>px</em></span><small>Leave blank to inherit Elementor typography.</small></label>
                                        <label>Body size <span class="cliniko-onboarding-input-suffix"><input type="number" name="body_font_size" min="0" max="32" step="1" value="<?php echo esc_attr((string) ($module['body_font_size'] ?? '')); ?>"><em>px</em></span><small>Leave blank to inherit Elementor typography.</small></label>
                                    </div>
                                    <label class="cliniko-onboarding-switch cliniko-onboarding-switch--accent">
                                        <input type="checkbox" name="colors_enabled" value="1" <?php checked(!empty($module['colors_enabled'])); ?> data-onboarding-custom-colors>
                                        <span><strong>Override Elementor colors</strong><small>Enable custom colors for this onboarding flow only.</small></span>
                                    </label>
                                    <div class="cliniko-onboarding-builder-colors" data-onboarding-color-controls>
                                        <label>Primary <input type="color" name="primary_color" value="<?php echo esc_attr(self::colorValue($module['primary_color'] ?? '')); ?>"></label>
                                        <label>Title <input type="color" name="title_color" value="<?php echo esc_attr(self::colorValue($module['title_color'] ?? ($module['heading_color'] ?? ''))); ?>"></label>
                                        <label>Input labels <input type="color" name="label_color" value="<?php echo esc_attr(self::colorValue($module['label_color'] ?? '')); ?>"></label>
                                        <label>Text <input type="color" name="text_color" value="<?php echo esc_attr(self::colorValue($module['text_color'] ?? '')); ?>"></label>
                                        <label>Surface <input type="color" name="surface_color" value="<?php echo esc_attr(self::colorValue($module['surface_color'] ?? '')); ?>"></label>
                                        <label>Borders <input type="color" name="border_color" value="<?php echo esc_attr(self::colorValue($module['border_color'] ?? '')); ?>"></label>
                                    </div>
                                </div>
                            </section>

                            <section class="cliniko-form-builder__panel cliniko-onboarding-tab-panel" data-onboarding-tab-panel="steps" hidden>
                                <header><div><strong>Steps &amp; fields</strong><span>Build the questions patients complete.</span></div><button type="button" class="button button-primary" data-onboarding-add-step>Add step</button></header>
                                <div class="cliniko-onboarding-panel-body">
                                    <div class="cliniko-onboarding-builder-help"><strong>Controls, completion, and input rules</strong><p>Choose an input type for each compatible field, including formatted dates and custom dropdowns. A patient is considered complete when every field marked “Required for completion” has a value. Open <em>Input rules</em> on a text field to limit its length, accept numbers only, or build a formatting mask.</p></div>
                                    <div data-onboarding-steps></div>
                                </div>
                            </section>
                        </main>

                        <aside class="cliniko-form-builder__sidebar cliniko-onboarding-editor__sidebar">
                            <section class="cliniko-form-builder__sidebox cliniko-onboarding-publish-card">
                                <span class="cliniko-onboarding-editor__eyebrow">Ready to publish?</span>
                                <h2><?php echo $id !== '' ? 'Update onboarding' : 'Create onboarding'; ?></h2>
                                <p>Your configuration is saved only when you use the button below.</p>
                                <button type="submit" class="button button-primary button-hero"><?php echo $id !== '' ? 'Save changes' : 'Create onboarding'; ?></button>
                            </section>
                            <section class="cliniko-form-builder__sidebox cliniko-onboarding-shortcode-card">
                                <h2>Shortcode</h2>
                                <p>Place this in an Elementor Shortcode widget or WordPress page.</p>
                                <code>[<?php echo esc_html(self::SHORTCODE); ?> id="<?php echo esc_attr($id !== '' ? $id : 'onboarding_ID'); ?>"]</code>
                            </section>
                            <section class="cliniko-form-builder__sidebox cliniko-onboarding-checklist">
                                <h2>Builder checklist</h2>
                                <ol><li>Name and enable the flow</li><li>Select protected pages</li><li>Write patient-facing content</li><li>Review appearance</li><li>Add steps and required fields</li></ol>
                            </section>
                        </aside>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('save_patient_onboarding');

        $id = sanitize_key((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            $id = 'onboarding_' . sanitize_key(wp_generate_uuid4());
        }

        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            wp_die('An onboarding name is required.');
        }

        $available = self::editableFields();
        $rawSteps = json_decode(wp_unslash((string) ($_POST['steps_config'] ?? '')), true);
        $steps = self::normaliseSteps(is_array($rawSteps) ? $rawSteps : [], $available);
        if ($steps === [] || self::flattenFields($steps) === []) {
            wp_die('Add at least one patient field to the onboarding flow.');
        }

        $mode = in_array((string) ($_POST['mode'] ?? ''), ['single', 'multistep'], true)
            ? (string) $_POST['mode']
            : 'multistep';
        $placement = self::normalisePlacement($_POST['placement'] ?? 'inline');
        $overlaySize = self::normaliseOverlaySize($_POST['overlay_size'] ?? 'contained');
        $rawConditions = json_decode(wp_unslash((string) ($_POST['conditions_config'] ?? '')), true);
        $conditions = self::normaliseConditions(is_array($rawConditions) ? $rawConditions : [], []);
        $conditionLogic = self::normaliseConditionLogic($_POST['condition_logic'] ?? 'all');
        $questionsPerStep = max(0, min(20, (int) ($_POST['questions_per_step'] ?? 0)));
        $modules = self::modules();
        $modules[$id] = [
            'name' => $name,
            'enabled' => !empty($_POST['enabled']),
            'mode' => $mode,
            'placement' => $placement,
            'overlay_size' => $overlaySize,
            'conditions' => $conditions,
            'condition_logic' => $conditionLogic,
            'title' => sanitize_text_field((string) ($_POST['title'] ?? 'Complete your profile')),
            'subtitle' => sanitize_textarea_field((string) ($_POST['subtitle'] ?? 'Please complete the required details before continuing.')),
            'welcome_enabled' => !empty($_POST['welcome_enabled']),
            'welcome_source' => self::normaliseWelcomeSource($_POST['welcome_source'] ?? 'shortcode'),
            'welcome_shortcode' => self::normaliseWelcomeShortcode(wp_unslash((string) ($_POST['welcome_shortcode'] ?? ''))),
            'welcome_html' => wp_kses_post(wp_unslash((string) ($_POST['welcome_html'] ?? ''))),
            'welcome_css' => self::normaliseWelcomeCode(wp_unslash((string) ($_POST['welcome_css'] ?? '')), 'style'),
            'welcome_js' => self::normaliseWelcomeCode(wp_unslash((string) ($_POST['welcome_js'] ?? '')), 'script'),
            'welcome_button_label' => sanitize_text_field((string) ($_POST['welcome_button_label'] ?? 'Get started')),
            'questions_per_step' => $questionsPerStep,
            'completion_message' => sanitize_textarea_field((string) ($_POST['completion_message'] ?? 'Your profile is complete.')),
            'show_complete_message' => !empty($_POST['show_complete_message']),
            'completion_redirect' => esc_url_raw((string) ($_POST['completion_redirect'] ?? '')),
            'font_family' => self::normaliseFontFamily($_POST['font_family'] ?? ''),
            'heading_font_size' => self::normaliseFontSize($_POST['heading_font_size'] ?? '', 72),
            'body_font_size' => self::normaliseFontSize($_POST['body_font_size'] ?? '', 32),
            'colors_enabled' => !empty($_POST['colors_enabled']),
            'primary_color' => !empty($_POST['colors_enabled']) ? (sanitize_hex_color((string) ($_POST['primary_color'] ?? '')) ?: '') : '',
            'title_color' => !empty($_POST['colors_enabled']) ? (sanitize_hex_color((string) ($_POST['title_color'] ?? '')) ?: '') : '',
            'label_color' => !empty($_POST['colors_enabled']) ? (sanitize_hex_color((string) ($_POST['label_color'] ?? '')) ?: '') : '',
            'text_color' => !empty($_POST['colors_enabled']) ? (sanitize_hex_color((string) ($_POST['text_color'] ?? '')) ?: '') : '',
            'surface_color' => !empty($_POST['colors_enabled']) ? (sanitize_hex_color((string) ($_POST['surface_color'] ?? '')) ?: '') : '',
            'border_color' => !empty($_POST['colors_enabled']) ? (sanitize_hex_color((string) ($_POST['border_color'] ?? '')) ?: '') : '',
            'steps' => $steps,
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
        check_admin_referer('delete_patient_onboarding_' . $id);
        $modules = self::modules();
        unset($modules[$id]);
        update_option(self::OPTION_KEY, $modules, false);
        wp_safe_redirect(self::url(['deleted' => 1]));
        exit;
    }

    /** @param array<string,mixed> $attributes */
    public static function shortcode(array $attributes): string
    {
        $id = sanitize_key((string) ($attributes['id'] ?? ''));
        $module = self::modules()[$id] ?? null;
        if (!is_array($module) || empty($module['enabled'])) {
            return current_user_can('manage_options') && $id !== ''
                ? '<p class="cliniko-patient-onboarding__message is-error">Patient onboarding not found or disabled.</p>'
                : '';
        }

        if (!self::matchesDisplayConditions($module)) {
            return '';
        }

        if (!is_user_logged_in()) {
            return '<p class="cliniko-patient-onboarding-login-required">Please log in to complete your profile.</p>';
        }

        if (PatientService::isPatientSyncExemptUser(get_current_user_id())) {
            return '';
        }

        try {
            $patient = Auth::patientData();
            if (!is_array($patient) || trim((string) ($patient['id'] ?? '')) === '') {
                return '';
            }

            $available = self::editableFields();
            $steps = self::effectiveSteps($module, $available);
            $answeredSwitchValues = self::switchValues($id, get_current_user_id());
            $switchValues = self::effectiveSwitchValues($steps, $answeredSwitchValues);
            $missing = self::missingRequiredFields($steps, $patient, $switchValues, $answeredSwitchValues);
            $saved = sanitize_key((string) ($_GET['cliniko_onboarding_saved'] ?? ''));

            $showCompletionMessage = !empty($module['show_complete_message']);
            if ($missing === [] && ($saved !== '1' || !$showCompletionMessage)) {
                return '';
            }

            $style = __DIR__ . '/PatientOnboarding/ShortCodeTemplates/patient-onboarding.css';
            $script = __DIR__ . '/PatientOnboarding/ShortCodeTemplates/patient-onboarding.js';
            ComponentStyles::enqueueFrontend();
            wp_enqueue_style(
                'cliniko-patient-onboarding',
                plugins_url('PatientOnboarding/ShortCodeTemplates/patient-onboarding.css', __FILE__),
                ['cliniko-shortcode-components'],
                file_exists($style) ? (string) filemtime($style) : null
            );
            wp_enqueue_script(
                'cliniko-patient-onboarding',
                plugins_url('PatientOnboarding/ShortCodeTemplates/patient-onboarding.js', __FILE__),
                ['cliniko-shortcode-components'],
                file_exists($script) ? (string) filemtime($script) : null,
                true
            );
            $instanceId = wp_unique_id('cliniko-patient-onboarding-' . sanitize_html_class($id) . '-');
            self::enqueueWelcomeAssets($instanceId, $module);

            $values = self::patientValues($patient);
            $messageState = $saved === '1' ? 'success' : ($saved === '0' ? 'error' : '');
            $markup = Phtml::render(__DIR__ . '/PatientOnboarding/ShortCodeTemplates/patient-onboarding.phtml', [
                'id' => $id,
                'module' => $module,
                'steps' => $steps,
                'definitions' => $available,
                'values' => $values,
                'switchValues' => $switchValues,
                'missing' => $missing,
                'messageState' => $messageState,
                'patientSaveAction' => self::PATIENT_SAVE_ACTION,
                'returnTo' => self::currentUrl(),
                'welcomeContent' => self::welcomeContent($module),
                'instanceId' => $instanceId,
            ]);
            return CustomCode::applyToMarkup($markup, [
                'code_alias' => (string) ($attributes['code_alias'] ?? ''),
                'booking_alias' => (string) ($attributes['booking_alias'] ?? ''),
            ]);
        } catch (\Throwable $exception) {
            error_log('Cliniko patient onboarding shortcode failed: ' . $exception->getMessage());
            return '<p class="cliniko-patient-onboarding__message is-error">Patient data is temporarily unavailable. Please try again shortly.</p>';
        }
    }

    public static function savePatient(): void
    {
        if (!is_user_logged_in()) {
            wp_die('Unauthorized');
        }

        $id = sanitize_key((string) ($_POST['onboarding_id'] ?? ''));
        check_admin_referer('save_cliniko_patient_onboarding_' . $id);
        $module = self::modules()[$id] ?? null;
        if (!is_array($module) || empty($module['enabled'])) {
            wp_die('This onboarding flow is no longer available.');
        }

        $returnTo = wp_validate_redirect((string) ($_POST['return_to'] ?? ''), home_url('/'));
        $returnTo = remove_query_arg(['cliniko_onboarding_saved'], $returnTo);
        $available = self::editableFields();
        $steps = self::effectiveSteps($module, $available);
        $configuredFields = self::flattenFields($steps);
        $payload = [];
        $validationPatient = [];
        $inputErrors = [];
        $emailRequested = null;
        $answeredSwitchValues = self::switchValues($id, get_current_user_id());
        $postedSwitches = isset($_POST['onboarding_switches']) && is_array($_POST['onboarding_switches'])
            ? wp_unslash($_POST['onboarding_switches'])
            : [];

        foreach ($configuredFields as $field) {
            if ((string) ($field['kind'] ?? '') !== 'switch') {
                continue;
            }
            $switchKey = sanitize_key((string) ($field['key'] ?? ''));
            if ($switchKey !== '' && array_key_exists($switchKey, $postedSwitches)) {
                $answeredSwitchValues[$switchKey] = self::isTruthyValue($postedSwitches[$switchKey]);
            }
        }
        self::saveSwitchValues($id, get_current_user_id(), $answeredSwitchValues);
        $switchValues = self::effectiveSwitchValues($steps, $answeredSwitchValues);

        foreach ($configuredFields as $field) {
            $key = (string) ($field['key'] ?? '');
            $definition = $available[$key] ?? null;
            if (!is_array($definition) || !self::fieldConditionMatches($field, $switchValues)) {
                continue;
            }

            $fieldType = strtolower((string) ($definition['type'] ?? 'text'));
            $isArrayField = in_array($fieldType, ['checkboxes', 'multi_checkbox', 'multi_select'], true)
                || strtolower((string) ($definition['cliniko_field_type'] ?? '')) === 'checkboxes';
            $raw = array_key_exists($key, $_POST) ? wp_unslash($_POST[$key]) : ($isArrayField ? [] : '');
            $value = self::sanitizePostedValue($raw);
            if ((string) ($field['input_type'] ?? 'default') === 'date' && is_scalar($value) && trim((string) $value) !== '') {
                $clinikoDate = self::dateValueForCliniko((string) $value, (string) ($field['date_format'] ?? 'dmy_slash'));
                if ($clinikoDate === null) {
                    $inputErrors[] = (string) ($field['label'] ?? $key) . ' is not a valid date.';
                } else {
                    $value = $clinikoDate;
                }
            }
            $validationPatient[$key] = $value;

            if ($key === 'email') {
                $emailRequested = sanitize_email((string) $value);
                continue;
            }
            if (!empty($definition['custom'])) {
                $payload['custom_fields'][$key] = $value;
            } else {
                $payload[$key] = $value;
            }
        }

        $errors = array_merge($inputErrors, self::validateRequiredFields($steps, $validationPatient, $switchValues, $answeredSwitchValues));
        $errors = array_merge($errors, self::validateInputRules($steps, $validationPatient, $switchValues));
        $customDefinitions = [];
        foreach ($configuredFields as $field) {
            $key = (string) ($field['key'] ?? '');
            if (isset($available[$key]) && !empty($available[$key]['custom']) && self::fieldConditionMatches($field, $switchValues)) {
                $customDefinitions[] = array_merge($available[$key], [
                    'key' => $key,
                    'path' => 'custom_fields.' . $key,
                    'label' => (string) ($field['label'] ?? $available[$key]['label'] ?? $key),
                    'required' => !empty($field['required']),
                ]);
            }
        }
        if ($customDefinitions !== []) {
            $customValidationPatient = ['custom_fields' => $payload['custom_fields'] ?? []];
            $errors = array_merge($errors, PatientCustomFieldService::validate($customValidationPatient, $customDefinitions));
        }

        if ($emailRequested !== null && $emailRequested === '' && self::fieldIsRequired($steps, 'email', $switchValues)) {
            $errors[] = 'Email is required.';
        }

        if ($errors !== []) {
            wp_safe_redirect(add_query_arg('cliniko_onboarding_saved', '0', $returnTo));
            exit;
        }

        try {
            if ($emailRequested !== null) {
                $user = wp_get_current_user();
                $currentEmail = strtolower(trim((string) $user->user_email));
                if ($emailRequested !== '' && !hash_equals($currentEmail, strtolower($emailRequested))) {
                    $emailChange = (new PatientEmailChangeService())->requestForCurrentUser($emailRequested);
                    if (empty($emailChange['ok'])) {
                        wp_safe_redirect(add_query_arg('cliniko_onboarding_saved', '0', $returnTo));
                        exit;
                    }
                }
            }

            $updated = $payload === [] ? [] : (new PatientService())->updatePatientForCurrentUser($payload);
            if (!is_array($updated)) {
                wp_safe_redirect(add_query_arg('cliniko_onboarding_saved', '0', $returnTo));
                exit;
            }

            $freshPatient = (new PatientService())->getPatientForCurrentUser();
            $complete = is_array($freshPatient) && self::missingRequiredFields($steps, $freshPatient, $switchValues, $answeredSwitchValues) === [];
            $redirect = wp_validate_redirect((string) ($module['completion_redirect'] ?? ''), '');
            if ($complete && $redirect !== '') {
                $returnTo = $redirect;
            }
            if ($complete && empty($module['show_complete_message'])) {
                wp_safe_redirect($returnTo);
                exit;
            }
            wp_safe_redirect(add_query_arg('cliniko_onboarding_saved', '1', $returnTo));
            exit;
        } catch (\Throwable $exception) {
            error_log('Cliniko patient onboarding submission failed: ' . $exception->getMessage());
            wp_safe_redirect(add_query_arg('cliniko_onboarding_saved', '0', $returnTo));
            exit;
        }
    }

    /** @return array<string,array<string,mixed>> */
    private static function modules(): array
    {
        $modules = get_option(self::OPTION_KEY, []);
        return is_array($modules) ? $modules : [];
    }

    /** @param array<string,mixed> $module */
    private static function welcomeContent(array $module): string
    {
        if (empty($module['welcome_enabled'])) {
            return '';
        }

        $source = self::normaliseWelcomeSource($module['welcome_source'] ?? 'shortcode');
        if ($source === 'html') {
            $content = wp_kses_post((string) ($module['welcome_html'] ?? ''));
            if (function_exists('do_blocks')) {
                $content = do_blocks($content);
            }
            return do_shortcode($content);
        }

        $shortcode = self::normaliseWelcomeShortcode($module['welcome_shortcode'] ?? '');
        return $shortcode !== '' ? do_shortcode($shortcode) : '';
    }

    /** @param array<string,mixed> $module */
    private static function enqueueWelcomeAssets(string $instanceId, array $module): void
    {
        if (empty($module['welcome_enabled']) || self::normaliseWelcomeSource($module['welcome_source'] ?? '') !== 'html') {
            return;
        }

        $selector = '#' . sanitize_html_class($instanceId);
        $css = self::normaliseWelcomeCode($module['welcome_css'] ?? '', 'style');
        if (trim($css) !== '') {
            wp_add_inline_style(
                'cliniko-patient-onboarding',
                str_replace('{{WRAPPER}}', $selector, $css)
            );
        }

        $javascript = self::normaliseWelcomeCode($module['welcome_js'] ?? '', 'script');
        if (trim($javascript) === '') {
            return;
        }

        $encodedId = wp_json_encode($instanceId);
        wp_add_inline_script(
            'cliniko-patient-onboarding',
            '(function(){'
            . 'var onboardingRoot=document.getElementById(' . $encodedId . ');'
            . 'if(!onboardingRoot){return;}'
            . 'var welcomeRoot=onboardingRoot.querySelector("[data-onboarding-welcome]");'
            . 'if(!welcomeRoot){return;}'
            . 'try{' . "\n" . $javascript . "\n"
            . '}catch(error){console.error("Cliniko onboarding welcome script failed:",error);}'
            . '}());',
            'after'
        );
    }

    /** @return array<string,mixed> */
    private static function defaults(): array
    {
        return [
            'name' => '',
            'enabled' => true,
            'mode' => 'multistep',
            'placement' => 'inline',
            'overlay_size' => 'contained',
            'conditions' => [],
            'condition_logic' => 'all',
            'title' => 'Complete your profile',
            'subtitle' => 'Please complete the required details before continuing.',
            'welcome_enabled' => false,
            'welcome_source' => 'shortcode',
            'welcome_shortcode' => '',
            'welcome_html' => '',
            'welcome_css' => '',
            'welcome_js' => '',
            'welcome_button_label' => 'Get started',
            'questions_per_step' => 0,
            'completion_message' => 'Your profile is complete.',
            'show_complete_message' => false,
            'completion_redirect' => '',
            'font_family' => '',
            'heading_font_size' => '',
            'body_font_size' => '',
            'colors_enabled' => false,
            'primary_color' => '',
            'title_color' => '',
            'label_color' => '',
            'heading_color' => '',
            'text_color' => '',
            'surface_color' => '',
            'border_color' => '',
            'steps' => [[
                'id' => 'onboarding-step-1',
                'title' => 'Your details',
                'subtitle' => 'Tell us a little more about you.',
                'fields' => [
                    ['key' => 'phone', 'label' => 'Phone', 'required' => true, 'width' => 100],
                    ['key' => 'date_of_birth', 'label' => 'Date of birth', 'required' => false, 'width' => 100],
                ],
            ]],
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private static function editableFields(): array
    {
        return array_filter(
            PatientFieldRegistry::available(),
            static fn(array $definition): bool => !empty($definition['editable'])
        );
    }

    private static function normalisePlacement($placement): string
    {
        $placement = sanitize_key((string) $placement);
        return in_array($placement, ['inline', 'modal', 'blocking_overlay'], true) ? $placement : 'inline';
    }

    private static function normaliseOverlaySize($size): string
    {
        $size = sanitize_key((string) $size);
        return in_array($size, ['contained', 'full_screen'], true) ? $size : 'contained';
    }

    private static function normaliseWelcomeSource($source): string
    {
        $source = sanitize_key((string) $source);
        return in_array($source, ['shortcode', 'html'], true) ? $source : 'shortcode';
    }

    private static function normaliseWelcomeShortcode($shortcode): string
    {
        $shortcode = sanitize_textarea_field((string) $shortcode);
        if (stripos($shortcode, '[' . self::SHORTCODE) !== false) {
            return '';
        }
        return $shortcode;
    }

    private static function normaliseWelcomeCode($code, string $tag): string
    {
        $code = str_replace("\0", '', (string) $code);
        return (string) preg_replace('#</?' . preg_quote($tag, '#') . '[^>]*>#i', '', $code);
    }

    /** @return array<string,string> */
    private static function fontFamilies(): array
    {
        return [
            '' => 'Use Elementor/theme font',
            'system-ui, sans-serif' => 'System sans-serif',
            'Arial, sans-serif' => 'Arial',
            'Verdana, sans-serif' => 'Verdana',
            'Georgia, serif' => 'Georgia',
            'Tahoma, sans-serif' => 'Tahoma',
            'Times New Roman, serif' => 'Times New Roman',
        ];
    }

    private static function normaliseFontFamily($fontFamily): string
    {
        $fontFamily = (string) $fontFamily;
        return array_key_exists($fontFamily, self::fontFamilies()) ? $fontFamily : '';
    }

    private static function normaliseFontSize($size, int $max): string
    {
        $size = trim((string) $size);
        if ($size === '') {
            return '';
        }
        return (string) max(1, min($max, (int) $size));
    }

    private static function colorValue($color): string
    {
        return sanitize_hex_color((string) $color) ?: '#ffffff';
    }

    /** @param mixed $pageIds @return array<int,int> */
    private static function normalisePageIds($pageIds): array
    {
        if (!is_array($pageIds)) {
            return [];
        }

        $pageIds = array_map('absint', $pageIds);
        $pageIds = array_values(array_unique(array_filter($pageIds, static fn(int $pageId): bool => $pageId > 0)));
        sort($pageIds);
        return $pageIds;
    }

    /** @return array<int,\WP_Post> */
    private static function pages(): array
    {
        return get_pages([
            'post_type' => 'page',
            'post_status' => ['publish', 'private', 'draft', 'pending', 'future'],
            'sort_column' => 'post_title',
            'sort_order' => 'ASC',
        ]);
    }

    private static function pageLabel(\WP_Post $page): string
    {
        $titles = [];
        $ancestorIds = function_exists('get_post_ancestors') ? array_reverse(get_post_ancestors($page->ID)) : [];
        foreach ($ancestorIds as $ancestorId) {
            $ancestor = get_post((int) $ancestorId);
            if ($ancestor instanceof \WP_Post && $ancestor->post_title !== '') {
                $titles[] = $ancestor->post_title;
            }
        }

        $titles[] = $page->post_title !== '' ? $page->post_title : '(no title)';
        return implode(' / ', $titles) . ' (' . $page->ID . ')';
    }

    private static function normaliseConditionLogic($logic): string
    {
        $logic = sanitize_key((string) $logic);
        return $logic === 'any' ? 'any' : 'all';
    }

    /**
     * @param mixed $rawConditions
     * @param array<string,mixed> $legacyModule
     * @return array<int,array{type:string,page_ids:array<int,int>}>
     */
    private static function normaliseConditions($rawConditions, array $legacyModule = []): array
    {
        if (!is_array($rawConditions)) {
            $rawConditions = self::legacyConditions($legacyModule);
        }

        $conditions = [];
        foreach ($rawConditions as $condition) {
            if (!is_array($condition)) {
                continue;
            }

            $type = sanitize_key((string) ($condition['type'] ?? ''));
            if (!in_array($type, ['page_is', 'page_child_of', 'page_is_not', 'page_not_child_of'], true)) {
                continue;
            }

            $pageIds = self::normalisePageIds($condition['page_ids'] ?? ($condition['pages'] ?? []));
            $conditions[] = [
                'type' => $type,
                'page_ids' => $pageIds,
            ];
        }

        return $conditions;
    }

    /** @param array<string,mixed> $module @return array<int,array{type:string,page_ids:array<int,int>}> */
    private static function legacyConditions(array $module): array
    {
        $scope = sanitize_key((string) ($module['page_scope'] ?? 'all'));
        $pageIds = self::normalisePageIds($module['page_ids'] ?? []);
        if ($pageIds === [] || $scope === 'all') {
            return [];
        }

        return [[
            'type' => $scope === 'exclude' ? 'page_is_not' : 'page_is',
            'page_ids' => $pageIds,
        ]];
    }

    /** @param array<string,mixed> $module */
    private static function matchesDisplayConditions(array $module): bool
    {
        $conditions = self::normaliseConditions($module['conditions'] ?? null, $module);
        if ($conditions === []) {
            return true;
        }

        $pageId = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
        $matches = array_map(
            static fn(array $condition): bool => self::conditionMatchesPage($condition, $pageId),
            $conditions
        );

        return self::normaliseConditionLogic($module['condition_logic'] ?? 'all') === 'any'
            ? in_array(true, $matches, true)
            : !in_array(false, $matches, true);
    }

    /** @param array{type:string,page_ids:array<int,int>} $condition */
    private static function conditionMatchesPage(array $condition, int $pageId): bool
    {
        $pageIds = $condition['page_ids'];
        if ($pageId <= 0 || $pageIds === []) {
            return false;
        }

        $isExact = in_array($pageId, $pageIds, true);
        $ancestors = function_exists('get_post_ancestors') ? array_map('intval', get_post_ancestors($pageId)) : [];
        $isChild = (bool) array_intersect($pageIds, $ancestors);

        return match ($condition['type']) {
            'page_is' => $isExact,
            'page_child_of' => $isChild,
            'page_is_not' => !$isExact,
            'page_not_child_of' => !$isChild,
            default => false,
        };
    }

    /** @param array<int,mixed> $rawSteps @param array<string,array<string,mixed>> $available @return array<int,array<string,mixed>> */
    private static function normaliseSteps(array $rawSteps, array $available): array
    {
        $steps = [];
        $usedFields = [];
        $usedIds = [];
        $availableSwitches = [];
        foreach ($rawSteps as $rawStep) {
            if (!is_array($rawStep)) {
                continue;
            }
            foreach ((array) ($rawStep['fields'] ?? []) as $rawField) {
                if (!is_array($rawField) || sanitize_key((string) ($rawField['kind'] ?? '')) !== 'switch') {
                    continue;
                }
                $switchKey = sanitize_key((string) ($rawField['key'] ?? ''));
                if ($switchKey !== '') {
                    $availableSwitches[$switchKey] = true;
                }
            }
        }

        foreach ($rawSteps as $index => $rawStep) {
            if (!is_array($rawStep)) {
                continue;
            }

            $title = sanitize_text_field((string) ($rawStep['title'] ?? ''));
            if ($title === '') {
                $title = 'Step ' . ((int) $index + 1);
            }
            $baseId = sanitize_html_class((string) ($rawStep['id'] ?? ''), '');
            if ($baseId === '') {
                $baseId = 'onboarding-step-' . sanitize_title($title);
            }
            $stepId = $baseId;
            $suffix = 2;
            while (isset($usedIds[$stepId])) {
                $stepId = $baseId . '-' . $suffix++;
            }
            $usedIds[$stepId] = true;

            $fields = [];
            foreach ((array) ($rawStep['fields'] ?? []) as $rawField) {
                $config = is_array($rawField) ? $rawField : ['key' => $rawField];
                $key = sanitize_key((string) ($config['key'] ?? ''));
                if ($key === '' || isset($usedFields[$key])) {
                    continue;
                }
                $isSwitch = sanitize_key((string) ($config['kind'] ?? '')) === 'switch';
                if ($isSwitch) {
                    $fieldWidth = (int) ($config['width'] ?? 100);
                    $label = sanitize_text_field((string) ($config['label'] ?? ''));
                    $onLabel = sanitize_text_field((string) ($config['on_label'] ?? ''));
                    $offLabel = sanitize_text_field((string) ($config['off_label'] ?? ''));
                    $fields[] = [
                        'kind' => 'switch',
                        'key' => $key,
                        'label' => $label !== '' ? $label : 'Do you have additional details to provide?',
                        'on_label' => $onLabel !== '' ? $onLabel : 'Yes',
                        'off_label' => $offLabel !== '' ? $offLabel : 'No',
                        'default_value' => (string) ($config['default_value'] ?? 'off') === 'on' ? 'on' : 'off',
                        'required' => !array_key_exists('required', $config) || !empty($config['required']),
                        'width' => in_array($fieldWidth, [50, 100], true) ? $fieldWidth : 100,
                    ];
                    $usedFields[$key] = true;
                    continue;
                }
                if (!isset($available[$key]) || empty($available[$key]['editable'])) {
                    continue;
                }
                $usedFields[$key] = true;
                $fallback = (string) ($available[$key]['label'] ?? $key);
                $label = sanitize_text_field((string) ($config['label'] ?? $fallback));
                $fieldType = strtolower((string) ($available[$key]['type'] ?? 'text'));
                $clinikoFieldType = strtolower((string) ($available[$key]['cliniko_field_type'] ?? ''));
                $isScalarField = !in_array($fieldType, ['checkbox', 'checkboxes', 'multi_checkbox', 'multi_select', 'paragraph', 'hidden'], true)
                    && !in_array($clinikoFieldType, ['checkbox', 'checkboxes', 'multi_checkbox', 'radiobuttons', 'radio'], true);
                $allowedInputTypes = ['default'];
                if ($isScalarField) {
                    $allowedInputTypes[] = 'text';
                }
                if ($isScalarField && in_array($fieldType, ['text', 'tel', 'date'], true)) {
                    $allowedInputTypes[] = 'date';
                }
                if ($isScalarField && in_array($fieldType, ['text', 'tel', 'select', 'textarea'], true)) {
                    $allowedInputTypes[] = 'select';
                }
                $requestedInputType = sanitize_key((string) ($config['input_type'] ?? 'default'));
                $inputType = in_array($requestedInputType, $allowedInputTypes, true) ? $requestedInputType : 'default';
                $dateFormat = $inputType === 'date' ? self::normaliseDateFormat($config['date_format'] ?? 'dmy_slash') : 'dmy_slash';
                $selectOptions = $inputType === 'select' ? self::normaliseSelectOptions($config['select_options'] ?? []) : [];
                if ($inputType === 'select' && $selectOptions === []) {
                    $inputType = 'default';
                }
                $effectiveFieldType = match ($inputType) {
                    'text' => 'text',
                    'date', 'select' => $inputType,
                    default => $fieldType,
                };
                $canLimit = in_array($effectiveFieldType, ['text', 'tel', 'email', 'url', 'search', 'textarea', 'number'], true);
                $canFormat = in_array($effectiveFieldType, ['text', 'tel', 'number'], true);
                $inputFormat = $canFormat ? self::normaliseInputFormat($config['input_format'] ?? 'none') : 'none';
                $maskPattern = $inputFormat === 'pattern' ? self::normaliseInputRuleText($config['mask_pattern'] ?? '', 80) : '';
                $maskPrefix = $inputFormat === 'pattern' ? self::normaliseInputRuleText($config['mask_prefix'] ?? '', 30) : '';
                $maskSuffix = $inputFormat === 'pattern' ? self::normaliseInputRuleText($config['mask_suffix'] ?? '', 30) : '';
                if ($inputFormat === 'pattern' && !preg_match('/[#A*]/', $maskPattern)) {
                    $inputFormat = 'none';
                    $maskPattern = '';
                    $maskPrefix = '';
                    $maskSuffix = '';
                }
                $fieldWidth = (int) ($config['width'] ?? 100);
                $conditionSwitch = sanitize_key((string) ($config['condition_switch'] ?? ''));
                if (!isset($availableSwitches[$conditionSwitch]) || $conditionSwitch === $key) {
                    $conditionSwitch = '';
                }
                $fields[] = [
                    'key' => $key,
                    'label' => $label !== '' ? $label : $fallback,
                    'required' => !empty($config['required']),
                    'width' => in_array($fieldWidth, [25, 50, 75, 100], true) ? $fieldWidth : 100,
                    'input_type' => $inputType,
                    'date_format' => $dateFormat,
                    'select_options' => $selectOptions,
                    'max_length' => $canLimit && $inputFormat !== 'pattern' ? max(0, min(5000, (int) ($config['max_length'] ?? 0))) : 0,
                    'input_format' => $inputFormat,
                    'mask_pattern' => $maskPattern,
                    'mask_prefix' => $maskPrefix,
                    'mask_suffix' => $maskSuffix,
                    'condition_switch' => $conditionSwitch,
                    'condition_value' => (string) ($config['condition_value'] ?? 'on') === 'off' ? 'off' : 'on',
                ];
            }

            if ($fields !== []) {
                $steps[] = [
                    'id' => $stepId,
                    'title' => $title,
                    'subtitle' => sanitize_textarea_field((string) ($rawStep['subtitle'] ?? '')),
                    'fields' => $fields,
                ];
            }
        }
        return $steps;
    }

    private static function normaliseInputFormat($format): string
    {
        $format = sanitize_key((string) $format);
        return in_array($format, ['numbers', 'pattern'], true) ? $format : 'none';
    }

    private static function normaliseDateFormat($format): string
    {
        $format = sanitize_key((string) $format);
        return in_array($format, ['dmy_slash', 'mdy_slash', 'ymd_dash', 'dmy_dash'], true) ? $format : 'dmy_slash';
    }

    /** @param mixed $options @return array<int,string> */
    private static function normaliseSelectOptions($options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $normalised = [];
        foreach (array_slice($options, 0, 100) as $option) {
            $option = sanitize_text_field((string) $option);
            $option = self::normaliseInputRuleText($option, 150);
            if ($option !== '' && !in_array($option, $normalised, true)) {
                $normalised[] = $option;
            }
        }
        return $normalised;
    }

    private static function dateValueForCliniko(string $value, string $format): ?string
    {
        $phpFormats = [
            'dmy_slash' => 'd/m/Y',
            'mdy_slash' => 'm/d/Y',
            'ymd_dash' => 'Y-m-d',
            'dmy_dash' => 'd-m-Y',
        ];
        $phpFormat = $phpFormats[self::normaliseDateFormat($format)];
        $value = trim($value);
        $date = \DateTimeImmutable::createFromFormat('!' . $phpFormat, $value);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (!$date instanceof \DateTimeImmutable
            || (is_array($dateErrors) && ((int) $dateErrors['warning_count'] > 0 || (int) $dateErrors['error_count'] > 0))
            || $date->format($phpFormat) !== $value) {
            return null;
        }
        return $date->format('Y-m-d');
    }

    private static function normaliseInputRuleText($value, int $maxLength): string
    {
        $value = wp_check_invalid_utf8((string) $value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
    }

    /** @param array<string,mixed> $module @param array<string,array<string,mixed>>|null $available @return array<int,array<string,mixed>> */
    private static function effectiveSteps(array $module, ?array $available = null): array
    {
        $available = $available ?? PatientFieldRegistry::available();
        $steps = self::normaliseSteps($module['steps'] ?? [], $available);
        if ($steps === []) {
            return [];
        }

        $flat = self::flattenFields($steps);
        if ((string) ($module['mode'] ?? 'multistep') === 'single') {
            return [[
                'id' => (string) ($steps[0]['id'] ?? 'onboarding-step-1'),
                'title' => (string) ($steps[0]['title'] ?? 'Your details'),
                'subtitle' => (string) ($steps[0]['subtitle'] ?? ''),
                'fields' => $flat,
            ]];
        }

        $perStep = max(0, min(20, (int) ($module['questions_per_step'] ?? 0)));
        if ($perStep <= 0) {
            return $steps;
        }

        $chunks = array_chunk($flat, $perStep);
        $result = [];
        foreach ($chunks as $index => $chunk) {
            $source = $steps[$index] ?? [];
            $result[] = [
                'id' => 'onboarding-step-' . ((int) $index + 1),
                'title' => (string) ($source['title'] ?? ('Step ' . ((int) $index + 1))),
                'subtitle' => (string) ($source['subtitle'] ?? ''),
                'fields' => $chunk,
            ];
        }
        return $result;
    }

    /** @param array<int,array<string,mixed>> $steps @return array<int,array<string,mixed>> */
    private static function flattenFields(array $steps): array
    {
        $fields = [];
        foreach ($steps as $step) {
            foreach ((array) ($step['fields'] ?? []) as $field) {
                if (is_array($field)) {
                    $fields[] = $field;
                }
            }
        }
        return $fields;
    }

    /** @param array<int,array<string,mixed>> $steps */
    private static function requiredFieldCount(array $steps): int
    {
        return count(array_filter(self::flattenFields($steps), static fn(array $field): bool => !empty($field['required'])));
    }

    /** @param array<int,array<string,mixed>> $steps @param array<string,mixed> $patient @param array<string,bool> $switchValues @param array<string,bool>|null $answeredSwitchValues @return array<int,array<string,string>> */
    private static function missingRequiredFields(array $steps, array $patient, array $switchValues = [], ?array $answeredSwitchValues = null): array
    {
        $values = self::patientValues($patient);
        $answeredSwitchValues = $answeredSwitchValues ?? $switchValues;
        $missing = [];
        foreach (self::flattenFields($steps) as $field) {
            if (empty($field['required'])) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            if ((string) ($field['kind'] ?? '') === 'switch') {
                if (!array_key_exists($key, $answeredSwitchValues)) {
                    $missing[] = ['key' => $key, 'label' => (string) ($field['label'] ?? $key)];
                }
                continue;
            }
            if (!self::fieldConditionMatches($field, $switchValues)) {
                continue;
            }
            if (self::isEmptyValue($values[$key] ?? null)) {
                $missing[] = ['key' => $key, 'label' => (string) ($field['label'] ?? $key)];
            }
        }
        return $missing;
    }

    /** @param array<int,array<string,mixed>> $steps @param array<string,mixed> $posted @param array<string,bool> $switchValues @param array<string,bool>|null $answeredSwitchValues */
    private static function validateRequiredFields(array $steps, array $posted, array $switchValues = [], ?array $answeredSwitchValues = null): array
    {
        $answeredSwitchValues = $answeredSwitchValues ?? $switchValues;
        $errors = [];
        foreach (self::flattenFields($steps) as $field) {
            if (empty($field['required'])) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            if ((string) ($field['kind'] ?? '') === 'switch') {
                if (!array_key_exists($key, $answeredSwitchValues)) {
                    $errors[] = (string) ($field['label'] ?? $key) . ' is required.';
                }
                continue;
            }
            if (!self::fieldConditionMatches($field, $switchValues)) {
                continue;
            }
            if (self::isEmptyValue($posted[$key] ?? null)) {
                $errors[] = (string) ($field['label'] ?? $key) . ' is required.';
            }
        }
        return $errors;
    }

    /** @param array<int,array<string,mixed>> $steps @param array<string,mixed> $posted @param array<string,bool> $switchValues @return array<int,string> */
    private static function validateInputRules(array $steps, array $posted, array $switchValues = []): array
    {
        $errors = [];
        foreach (self::flattenFields($steps) as $field) {
            if ((string) ($field['kind'] ?? '') === 'switch' || !self::fieldConditionMatches($field, $switchValues)) {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            $value = $posted[$key] ?? '';
            if (!is_scalar($value) || self::isEmptyValue($value)) {
                continue;
            }

            $value = (string) $value;
            $label = (string) ($field['label'] ?? $key);
            $maxLength = max(0, (int) ($field['max_length'] ?? 0));
            $actualLength = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
            if ($maxLength > 0 && $actualLength > $maxLength) {
                $errors[] = $label . ' must be no more than ' . $maxLength . ' characters.';
            }

            $inputFormat = self::normaliseInputFormat($field['input_format'] ?? 'none');
            if ($inputFormat === 'numbers' && !preg_match('/^[0-9]+$/', $value)) {
                $errors[] = $label . ' can contain numbers only.';
            }

            if ($inputFormat === 'pattern') {
                $pattern = (string) ($field['mask_pattern'] ?? '');
                $prefix = (string) ($field['mask_prefix'] ?? '');
                $suffix = (string) ($field['mask_suffix'] ?? '');
                $regex = self::inputMaskRegex($pattern, $prefix, $suffix);
                if ($regex === '' || preg_match($regex, $value) !== 1) {
                    $errors[] = $label . ' does not match the requested format.';
                }
            }

            if ((string) ($field['input_type'] ?? 'default') === 'select') {
                $options = array_map('strval', (array) ($field['select_options'] ?? []));
                if (!in_array($value, $options, true)) {
                    $errors[] = $label . ' must use one of the configured options.';
                }
            }
        }
        return $errors;
    }

    private static function inputMaskRegex(string $pattern, string $prefix, string $suffix): string
    {
        if ($pattern === '' || !preg_match('/[#A*]/', $pattern)) {
            return '';
        }

        $characters = preg_split('//u', $pattern, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            $characters = str_split($pattern);
        }
        $body = '';
        foreach ($characters as $character) {
            $body .= match ($character) {
                '#' => '[0-9]',
                'A' => '[A-Za-z]',
                '*' => '[A-Za-z0-9]',
                default => preg_quote($character, '~'),
            };
        }

        return '~^' . preg_quote($prefix, '~') . $body . preg_quote($suffix, '~') . '$~uD';
    }

    /** @param array<int,array<string,mixed>> $steps @param array<string,bool> $switchValues */
    private static function fieldIsRequired(array $steps, string $key, array $switchValues = []): bool
    {
        foreach (self::flattenFields($steps) as $field) {
            if ((string) ($field['key'] ?? '') === $key) {
                return !empty($field['required']) && self::fieldConditionMatches($field, $switchValues);
            }
        }
        return false;
    }

    /** @param array<string,mixed> $field @param array<string,bool> $switchValues */
    private static function fieldConditionMatches(array $field, array $switchValues): bool
    {
        $switchKey = sanitize_key((string) ($field['condition_switch'] ?? ''));
        if ($switchKey === '') {
            return true;
        }

        $actual = !empty($switchValues[$switchKey]);
        $expected = (string) ($field['condition_value'] ?? 'on') !== 'off';
        return $actual === $expected;
    }

    /** @param array<int,array<string,mixed>> $steps @param array<string,bool> $answeredValues @return array<string,bool> */
    private static function effectiveSwitchValues(array $steps, array $answeredValues): array
    {
        $values = $answeredValues;
        foreach (self::flattenFields($steps) as $field) {
            if ((string) ($field['kind'] ?? '') !== 'switch') {
                continue;
            }
            $key = sanitize_key((string) ($field['key'] ?? ''));
            if ($key !== '' && !array_key_exists($key, $values)) {
                $values[$key] = (string) ($field['default_value'] ?? 'off') === 'on';
            }
        }
        return $values;
    }

    /** @return array<string,bool> */
    private static function switchValues(string $onboardingId, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        $stored = get_user_meta($userId, self::switchMetaKey($onboardingId), true);
        if (!is_array($stored)) {
            return [];
        }

        $values = [];
        foreach ($stored as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key !== '') {
                $values[$key] = self::isTruthyValue($value);
            }
        }
        return $values;
    }

    /** @param array<string,bool> $values */
    private static function saveSwitchValues(string $onboardingId, int $userId, array $values): void
    {
        if ($userId <= 0) {
            return;
        }
        update_user_meta($userId, self::switchMetaKey($onboardingId), $values);
    }

    private static function switchMetaKey(string $onboardingId): string
    {
        return self::SWITCH_META_PREFIX . sanitize_key($onboardingId);
    }

    /** @param mixed $value */
    private static function isTruthyValue($value): bool
    {
        return $value === true || $value === 1 || in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }

    /** @param array<string,mixed> $patient @return array<string,mixed> */
    private static function patientValues(array $patient): array
    {
        $values = $patient;
        if (is_array($patient['custom_field_values'] ?? null)) {
            $values = array_merge($values, $patient['custom_field_values']);
        }
        if (is_array($patient['custom_fields'] ?? null)) {
            foreach (($patient['custom_fields']['sections'] ?? []) as $section) {
                if (!is_array($section)) {
                    continue;
                }
                foreach (($section['fields'] ?? []) as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    $token = sanitize_key((string) ($field['token'] ?? ''));
                    if ($token !== '') {
                        $values['custom_' . $token] = $field['value'] ?? '';
                    }
                }
            }
        }
        return $values;
    }

    /** @param mixed $value */
    private static function isEmptyValue($value): bool
    {
        if ($value === null || $value === false) {
            return true;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isEmptyValue($item)) {
                    return false;
                }
            }
            return true;
        }
        if (is_scalar($value)) {
            return trim((string) $value) === '';
        }
        return true;
    }

    /** @param mixed $value @return mixed */
    private static function sanitizePostedValue($value)
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn($item): string => sanitize_textarea_field((string) $item),
                $value
            ), static fn(string $item): bool => $item !== ''));
        }
        return is_scalar($value) ? sanitize_textarea_field((string) $value) : '';
    }

    /** @param array<string,int|string> $args */
    private static function url(array $args = []): string
    {
        return AccountBuilders::url(AccountBuilders::TAB_ONBOARDING, $args);
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
