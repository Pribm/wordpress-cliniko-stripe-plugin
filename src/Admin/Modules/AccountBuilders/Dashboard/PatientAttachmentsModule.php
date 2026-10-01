<?php

namespace App\Admin\Modules\AccountBuilders\Dashboard;

use App\Admin\Modules\AccountBuilders\AccountBuilders;
use App\Admin\Modules\AccountBuilders\Forms\ShortcodeFormInputRules;

if (!defined('ABSPATH')) exit;

final class PatientAttachmentsModule
{
    private const OPTION_KEY = 'wp_cliniko_patient_attachment_modules';
    private const SHORTCODE = 'cliniko_patient_attachments_module';
    private const LIST_SHORTCODE = 'cliniko_patient_attachments_list';
    private const UPLOAD_SHORTCODE = 'cliniko_patient_attachments_upload';

    public static function init(): void
    {
        add_action('admin_post_wp_cliniko_patient_attachments_module_save', [self::class, 'save']);
        add_action('admin_post_wp_cliniko_patient_attachments_module_delete', [self::class, 'delete']);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $action = sanitize_key((string) ($_GET['action'] ?? 'list'));
        if (in_array($action, ['new', 'edit'], true)) { self::renderEditor($action === 'edit' ? sanitize_key((string) ($_GET['id'] ?? '')) : ''); return; }
        $modules = self::modules();
        ?><div class="wrap"><h1 class="wp-heading-inline">Patient Attachment Modules</h1> <a class="page-title-action" href="<?php echo esc_url(self::url(['action' => 'new'])); ?>">Add New</a><p>Let logged-in patients upload records and documents to their Cliniko patient account.<br><a href="#" class="cliniko-shortcode-style-guide-link" data-shortcode-guide="cliniko_patient_attachments_module">Open styling guide</a></p><table class="widefat striped" style="max-width:1100px;margin-top:20px"><thead><tr><th>Name</th><th>Shortcode</th><th>Actions</th></tr></thead><tbody><?php
        foreach ($modules as $id => $module) : ?><tr><td><strong><?php echo esc_html((string) ($module['name'] ?? '')); ?></strong></td><td><code>[<?php echo esc_html(self::LIST_SHORTCODE); ?> id="<?php echo esc_attr($id); ?>"]</code><br><code>[<?php echo esc_html(self::UPLOAD_SHORTCODE); ?> id="<?php echo esc_attr($id); ?>"]</code><br><small>Combined: [<?php echo esc_html(self::SHORTCODE); ?> id="<?php echo esc_attr($id); ?>"]</small></td><td><a href="<?php echo esc_url(self::url(['action' => 'edit', 'id' => $id])); ?>">Edit</a> | <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_cliniko_patient_attachments_module_delete&id=' . rawurlencode($id)), 'delete_patient_attachments_module_' . $id)); ?>" onclick="return confirm('Delete this module?');">Delete</a></td></tr><?php endforeach;
        if ($modules === []) : ?><tr><td colspan="3">No patient attachment modules have been created.</td></tr><?php endif; ?></tbody></table></div><?php
    }

    private static function renderEditor(string $id): void
    {
        $module = self::modules()[$id] ?? self::defaults();
        $rules = ShortcodeFormInputRules::normalise(
            is_array($module['description_rules'] ?? null) ? $module['description_rules'] : [],
            self::descriptionRuleCapabilities()
        );
        ShortcodeFormInputRules::enqueueBuilderAssets();
        $style = dirname(__DIR__, 3) . '/assets/patient-attachment-builder.css';
        wp_enqueue_style('cliniko-patient-attachment-builder', plugins_url('../../../assets/patient-attachment-builder.css', __FILE__), ['cliniko-shortcode-form-rule-builder'], is_file($style) ? (string) filemtime($style) : null);
        $shortcodeId = $id !== '' ? $id : 'SAVE_TO_CREATE_ID';
        ?>
        <div class="wrap cliniko-attachment-builder">
            <header class="cliniko-attachment-builder__heading">
                <div><h1><?php echo $id !== '' ? 'Edit Patient Attachment Module' : 'Create Patient Attachment Module'; ?></h1><p>Build a clear, mobile-friendly document upload form for logged-in patients.</p></div>
            </header>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-attachment-builder__form">
                <input type="hidden" name="action" value="wp_cliniko_patient_attachments_module_save">
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field('save_patient_attachments_module'); ?>
                <div class="cliniko-attachment-builder__layout">
                    <main>
                        <section class="cliniko-attachment-builder__card">
                            <header><span>1</span><div><h2>Name and introduction</h2><p>These words appear above the upload controls.</p></div></header>
                            <div class="cliniko-attachment-builder__fields">
                                <label><strong>Internal module name</strong><input class="widefat" required name="name" value="<?php echo esc_attr((string) ($module['name'] ?? '')); ?>" placeholder="Patient documents"><small>Only administrators see this name.</small></label>
                                <label><strong>Patient-facing title</strong><input class="widefat" name="title" value="<?php echo esc_attr((string) ($module['title'] ?? 'Your documents')); ?>"></label>
                                <label class="is-wide"><strong>Introduction</strong><textarea class="widefat" name="description" rows="3"><?php echo esc_textarea((string) ($module['description'] ?? 'Upload a file or document for your care team.')); ?></textarea></label>
                            </div>
                        </section>
                        <section class="cliniko-attachment-builder__card">
                            <header><span>2</span><div><h2>Upload controls</h2><p>Use direct labels so patients always know what to select and submit.</p></div></header>
                            <div class="cliniko-attachment-builder__fields">
                                <label><strong>File field label</strong><input class="widefat" name="file_label" value="<?php echo esc_attr((string) ($module['file_label'] ?? 'Choose a document')); ?>"></label>
                                <label><strong>Description field label</strong><input class="widefat" name="description_label" value="<?php echo esc_attr((string) ($module['description_label'] ?? 'Description (optional)')); ?>"></label>
                                <label><strong>Upload button text</strong><input class="widefat" name="button_label" value="<?php echo esc_attr((string) ($module['button_label'] ?? 'Upload document')); ?>"></label>
                                <label><strong>Allowed file types</strong><input class="widefat" name="accept" value="<?php echo esc_attr((string) ($module['accept'] ?? '.pdf,.jpg,.jpeg,.png,.doc,.docx')); ?>"><small>Comma-separated extensions or MIME types, for example <code>.pdf,.jpg,.png</code>.</small></label>
                            </div>
                        </section>
                        <section class="cliniko-attachment-builder__card">
                            <header><span>3</span><div><h2>Description input behaviour</h2><p>Optionally make this a dropdown, limit its length, allow numbers only, or apply a guided pattern.</p></div></header>
                            <?php ShortcodeFormInputRules::renderAdminControls('description_rules', $rules, self::descriptionRuleCapabilities()); ?>
                        </section>
                        <section class="cliniko-attachment-builder__card cliniko-attachment-builder__preview">
                            <header><span>4</span><div><h2>Initial patient view</h2><p>The published form inherits the Elementor primary colour and typography while keeping inputs black and legible.</p></div></header>
                            <div class="cliniko-attachment-builder__preview-surface">
                                <h3><?php echo esc_html((string) ($module['title'] ?? 'Your documents')); ?></h3>
                                <p><?php echo esc_html((string) ($module['description'] ?? 'Upload a file or document for your care team.')); ?></p>
                                <label><?php echo esc_html((string) ($module['file_label'] ?? 'Choose a document')); ?><input type="file" disabled></label>
                                <label><?php echo esc_html((string) ($module['description_label'] ?? 'Description (optional)')); ?><input type="text" disabled></label>
                                <button type="button" disabled><?php echo esc_html((string) ($module['button_label'] ?? 'Upload document')); ?></button>
                            </div>
                        </section>
                    </main>
                    <aside>
                        <section class="cliniko-attachment-builder__publish">
                            <h2>Publish</h2>
                            <p>Choose a shortcode based on where you want the upload and document list to appear.</p>
                            <label>Upload only<code>[<?php echo esc_html(self::UPLOAD_SHORTCODE); ?> id="<?php echo esc_attr($shortcodeId); ?>"]</code></label>
                            <label>List only<code>[<?php echo esc_html(self::LIST_SHORTCODE); ?> id="<?php echo esc_attr($shortcodeId); ?>"]</code></label>
                            <label>Upload and list<code>[<?php echo esc_html(self::SHORTCODE); ?> id="<?php echo esc_attr($shortcodeId); ?>"]</code></label>
                            <?php if ($id === '') : ?><p class="description">Save once to generate the final shortcode ID.</p><?php endif; ?>
                            <button class="button button-primary button-hero"><?php echo $id !== '' ? 'Update module' : 'Create module'; ?></button>
                        </section>
                    </aside>
                </div>
            </form>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('save_patient_attachments_module');
        $id = sanitize_key((string) ($_POST['id'] ?? '')); if ($id === '') $id = 'patient_attachments_' . wp_generate_uuid4();
        $name = sanitize_text_field((string) ($_POST['name'] ?? '')); if ($name === '') wp_die('A module name is required.');
        $descriptionRules = ShortcodeFormInputRules::normalise(
            is_array($_POST['description_rules'] ?? null) ? wp_unslash($_POST['description_rules']) : [],
            self::descriptionRuleCapabilities()
        );
        $modules = self::modules(); $modules[$id] = [
            'name' => $name,
            'type' => 'patient_attachments',
            'title' => sanitize_text_field((string) ($_POST['title'] ?? 'Your documents')),
            'description' => sanitize_textarea_field((string) ($_POST['description'] ?? '')),
            'file_label' => sanitize_text_field((string) ($_POST['file_label'] ?? 'Choose a document')),
            'description_label' => sanitize_text_field((string) ($_POST['description_label'] ?? 'Description (optional)')),
            'button_label' => sanitize_text_field((string) ($_POST['button_label'] ?? 'Upload document')),
            'accept' => sanitize_text_field((string) ($_POST['accept'] ?? '.pdf,.jpg,.jpeg,.png,.doc,.docx')),
            'description_rules' => $descriptionRules,
        ];
        update_option(self::OPTION_KEY, $modules, false); wp_safe_redirect(self::url(['saved' => 1])); exit;
    }

    public static function delete(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = sanitize_key((string) ($_GET['id'] ?? '')); check_admin_referer('delete_patient_attachments_module_' . $id); $modules = self::modules(); unset($modules[$id]); update_option(self::OPTION_KEY, $modules, false); wp_safe_redirect(self::url(['deleted' => 1])); exit;
    }

    /** @return array<string,array<string,mixed>> */ private static function modules(): array { $modules = get_option(self::OPTION_KEY, []); return is_array($modules) ? $modules : []; }
    /** @return array<string,bool> */
    private static function descriptionRuleCapabilities(): array
    {
        return ['text' => true, 'date' => false, 'select' => true, 'limit' => true, 'format' => true];
    }

    /** @return array<string,mixed> */ private static function defaults(): array { return ['name' => '', 'title' => 'Your documents', 'description' => 'Upload a file or document for your care team.', 'file_label' => 'Choose a document', 'description_label' => 'Description (optional)', 'button_label' => 'Upload document', 'accept' => '.pdf,.jpg,.jpeg,.png,.doc,.docx', 'description_rules' => []]; }
    private static function url(array $args = []): string { return AccountBuilders::url(AccountBuilders::TAB_DASHBOARD_MODULES, array_merge(['dashboard_section' => AccountBuilders::DASHBOARD_PATIENT_ATTACHMENTS], $args)); }
}
