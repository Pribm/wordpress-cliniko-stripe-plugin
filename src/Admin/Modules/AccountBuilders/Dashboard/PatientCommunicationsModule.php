<?php

namespace App\Admin\Modules\AccountBuilders\Dashboard;

use App\Admin\Modules\AccountBuilders\AccountBuilders;

if (!defined('ABSPATH')) exit;

/** Builder for the patient communication timeline and clinic contact shortcode. */
final class PatientCommunicationsModule
{
    private const OPTION_KEY = 'wp_cliniko_patient_communication_modules';
    private const SHORTCODE = 'cliniko_patient_communications_module';

    public static function init(): void
    {
        add_action('admin_post_wp_cliniko_patient_communications_module_save', [self::class, 'save']);
        add_action('admin_post_wp_cliniko_patient_communications_module_delete', [self::class, 'delete']);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $action = sanitize_key((string) ($_GET['action'] ?? 'list'));
        if (in_array($action, ['new', 'edit'], true)) { self::renderEditor($action === 'edit' ? sanitize_key((string) ($_GET['id'] ?? '')) : ''); return; }
        $modules = self::modules();
        ?>
        <div class="wrap cliniko-template-builder-page">
            <h1 class="wp-heading-inline">Patient Communication Modules</h1> <a class="page-title-action" href="<?php echo esc_url(self::url(['action' => 'new'])); ?>">Add New</a>
            <p>Show a patient-safe timeline of Cliniko communication records and let a logged-in patient leave a message for the clinic.</p>
            <div class="notice notice-warning inline"><p><strong>Important:</strong> Cliniko’s API creates a memo record for a patient message. It does not send email or SMS, and it does not provide chat threads. Messages sent by staff through Cliniko can appear in the patient timeline when Cliniko records them as communications.</p></div>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success inline"><p>Communication module saved.</p></div><?php endif; ?>
            <table class="widefat striped" style="max-width:1100px;margin-top:20px"><thead><tr><th>Name</th><th>Clinic label</th><th>Timeline</th><th>Shortcode</th><th>Actions</th></tr></thead><tbody>
            <?php if ($modules === []) : ?><tr><td colspan="5">No communication modules have been created yet.</td></tr><?php else : foreach ($modules as $id => $module) : ?>
                <tr><td><?php echo esc_html((string) ($module['name'] ?? $id)); ?></td><td><?php echo esc_html((string) ($module['clinic_label'] ?? 'Clinic')); ?></td><td><?php echo !empty($module['show_history']) ? 'Shown' : 'Hidden'; ?></td><td><code>[<?php echo esc_html(self::SHORTCODE); ?> id="<?php echo esc_attr((string) $id); ?>"]</code></td><td><a class="button button-small" href="<?php echo esc_url(self::url(['action' => 'edit', 'id' => $id])); ?>">Edit</a> <a class="button-link-delete" href="<?php echo esc_url(wp_nonce_url(self::url(['action' => 'delete', 'id' => $id]), 'delete_patient_communications_module_' . $id)); ?>">Delete</a></td></tr>
            <?php endforeach; endif; ?>
            </tbody></table>
        </div>
        <?php
    }

    private static function renderEditor(string $id): void
    {
        $module = array_merge(self::defaults(), self::modules()[$id] ?? []);
        $shortcodeId = $id !== '' ? $id : 'communication_…';
        ?>
        <div class="wrap cliniko-template-builder-page cliniko-communications-builder">
            <h1><?php echo $id === '' ? 'Create Patient Communication Module' : 'Edit Patient Communication Module'; ?></h1>
            <p>Create a clear patient-facing communication timeline. A patient submission is saved in Cliniko as a <strong>received memo</strong> for their patient record.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-communications-builder__form">
                <input type="hidden" name="action" value="wp_cliniko_patient_communications_module_save"><input type="hidden" name="id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field('save_patient_communications_module'); ?>
                <main>
                    <section><header><span>1</span><div><h2>Module details</h2><p>These labels are visible to patients except the internal module name.</p></div></header><div class="cliniko-communications-builder__grid">
                        <label><strong>Internal module name</strong><input class="widefat" name="name" value="<?php echo esc_attr((string) $module['name']); ?>" required></label>
                        <label><strong>Clinic or business name</strong><input class="widefat" name="clinic_label" value="<?php echo esc_attr((string) $module['clinic_label']); ?>" required><small>Used as the recipient and in the patient timeline.</small></label>
                        <label><strong>Title</strong><input class="widefat" name="title" value="<?php echo esc_attr((string) $module['title']); ?>"></label>
                        <label><strong>Message button text</strong><input class="widefat" name="submit_label" value="<?php echo esc_attr((string) $module['submit_label']); ?>"></label>
                    </div><label><strong>Introductory text</strong><textarea class="widefat" rows="3" name="description"><?php echo esc_textarea((string) $module['description']); ?></textarea></label></section>
                    <section><header><span>2</span><div><h2>Patient experience</h2><p>Choose whether patients can see the Cliniko history and how they record a message.</p></div></header><div class="cliniko-communications-builder__grid">
                        <label><strong>Messages per page</strong><input type="number" name="items_per_page" min="1" max="100" value="<?php echo esc_attr((string) $module['items_per_page']); ?>"></label>
                        <p><strong>General messages</strong><br><small>Patient submissions are always recorded as an “Other” memo. Email and SMS tabs are read-only history from Cliniko.</small></p>
                    </div><label><input type="checkbox" name="show_history" value="1" <?php checked(!empty($module['show_history'])); ?>> Show communication history to the patient</label><label><strong>Composer placeholder</strong><textarea class="widefat" rows="2" name="message_placeholder"><?php echo esc_textarea((string) $module['message_placeholder']); ?></textarea></label></section>
                    <section><header><span>3</span><div><h2>Custom CSS</h2><p>The shortcode inherits your active theme and Elementor typography. Add optional CSS here only when you want to change its presentation.</p></div></header>
                        <label><strong>Custom CSS for this communication module</strong><textarea class="large-text code" rows="12" name="custom_css" spellcheck="false" placeholder=".cliniko-patient-communications { }&#10;.cliniko-patient-communications__item { }&#10;.cliniko-patient-communications__composer textarea { }"><?php echo esc_textarea((string) ($module['custom_css'] ?? '')); ?></textarea><small>Start each selector with <code>.cliniko-patient-communications</code> so it affects communication shortcodes only. CSS is printed after the built-in module stylesheet.</small></label>
                        <details><summary><strong>Available selectors</strong></summary><p><code>.cliniko-patient-communications</code> — entire shortcode wrapper</p><p><code>.cliniko-patient-communications h2</code> — title</p><p><code>.cliniko-patient-communications__intro</code> — introductory text</p><p><code>.cliniko-patient-communications__composer</code> — message form</p><p><code>.cliniko-patient-communications__composer textarea</code> — message input</p><p><code>.cliniko-patient-communications__composer button</code> — send button</p><p><code>.cliniko-patient-communications__timeline</code> — communication list</p><p><code>.cliniko-patient-communications__item</code> — any timeline item</p><p><code>.cliniko-patient-communications__item.is-clinic</code> — message recorded as sent by the clinic</p><p><code>.cliniko-patient-communications__item.is-patient</code> — patient-submitted memo</p><p><code>.cliniko-patient-communications__email-modal</code> — email modal overlay</p><p><code>.cliniko-patient-communications__email-modal-dialog</code> — modal window; it also has <code>.cliniko-patient-communications__item-content.is-email-html</code>, the same classes used for rendered email content</p><p><code>.cliniko-patient-communications__item header</code>, <code>time</code>, <code>p</code>, and <code>small</code> — item metadata and content</p></details>
                    </section>
                    <section><header><span>4</span><div><h2>What this does</h2><p>Cliniko keeps the authoritative record.</p></div></header><ul><li>Patients only see records attached to their own Cliniko patient profile.</li><li>A patient message is stored as a received memo for clinic staff to review in Cliniko.</li><li>This is a chronological timeline, not a real-time conversation or a replacement for urgent clinical care.</li></ul></section>
                </main>
                <aside><section><h2>Publish</h2><p>Copy this after saving and place it in Elementor or the WordPress editor.</p><code>[<?php echo esc_html(self::SHORTCODE); ?> id="<?php echo esc_attr($shortcodeId); ?>"]</code><p><strong>Navigation link with unread count</strong></p><code>[cliniko_patient_communications_link url="/messages/" label="Messages"]</code><p><small>Place this in an Elementor Shortcode widget or another shortcode-enabled navigation area. The tab badges are shown automatically inside this module.</small></p><button class="button button-primary button-hero"><?php echo $id === '' ? 'Create module' : 'Update module'; ?></button></section></aside>
            </form>
        </div>
        <style>.cliniko-communications-builder__form{display:grid;grid-template-columns:minmax(0,850px) 280px;gap:24px;max-width:1180px}.cliniko-communications-builder__form section{margin:0 0 18px;padding:22px;background:#fff;border:1px solid #d9e1ea;border-radius:10px}.cliniko-communications-builder__form section header{display:flex;gap:12px;margin-bottom:16px}.cliniko-communications-builder__form section header span{display:grid;width:28px;height:28px;place-items:center;border-radius:50%;color:#fff;background:#2271b1;font-weight:700}.cliniko-communications-builder__form h2{margin:0;font-size:18px}.cliniko-communications-builder__form header p{margin:4px 0 0;color:#646970}.cliniko-communications-builder__grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}.cliniko-communications-builder__form label{display:grid;gap:6px;margin:12px 0}.cliniko-communications-builder__form label small{color:#646970}.cliniko-communications-builder__form aside section{position:sticky;top:32px}.cliniko-communications-builder__form code{display:block;margin:12px 0;padding:10px;overflow-wrap:anywhere;background:#f6f7f7}.cliniko-communications-builder__form button{margin-top:8px}@media(max-width:900px){.cliniko-communications-builder__form{grid-template-columns:1fr}.cliniko-communications-builder__form aside section{position:static}.cliniko-communications-builder__grid{grid-template-columns:1fr}}</style>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('save_patient_communications_module');
        $id = sanitize_key((string) ($_POST['id'] ?? '')); if ($id === '') $id = 'communications_' . wp_generate_uuid4();
        $name = sanitize_text_field((string) ($_POST['name'] ?? '')); if ($name === '') wp_die('A module name is required.');
        $modules = self::modules();
        $modules[$id] = [
            'name' => $name,
            'title' => sanitize_text_field((string) ($_POST['title'] ?? 'Messages')),
            'description' => sanitize_textarea_field((string) ($_POST['description'] ?? '')),
            'clinic_label' => sanitize_text_field((string) ($_POST['clinic_label'] ?? 'Clinic')) ?: 'Clinic',
            'submit_label' => sanitize_text_field((string) ($_POST['submit_label'] ?? 'Send message')) ?: 'Send message',
            'show_history' => !empty($_POST['show_history']),
            'items_per_page' => max(1, min(100, (int) ($_POST['items_per_page'] ?? 20))),
            'type_code' => 4,
            'message_placeholder' => sanitize_textarea_field((string) ($_POST['message_placeholder'] ?? 'Write a non-urgent message for your care team…')),
            'custom_css' => self::normaliseCustomCss($_POST['custom_css'] ?? ''),
        ];
        update_option(self::OPTION_KEY, $modules, false); wp_safe_redirect(self::url(['saved' => 1])); exit;
    }

    public static function delete(): void
    {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $id = sanitize_key((string) ($_GET['id'] ?? '')); check_admin_referer('delete_patient_communications_module_' . $id);
        $modules = self::modules(); unset($modules[$id]); update_option(self::OPTION_KEY, $modules, false); wp_safe_redirect(self::url(['deleted' => 1])); exit;
    }

    /** @return array<string,array<string,mixed>> */ private static function modules(): array { $modules = get_option(self::OPTION_KEY, []); return is_array($modules) ? $modules : []; }
    /** @return array<string,mixed> */ private static function defaults(): array { return ['name' => '', 'title' => 'Messages', 'description' => 'View communication records and send a non-urgent message to your care team.', 'clinic_label' => 'Clinic', 'submit_label' => 'Send message', 'show_history' => true, 'items_per_page' => 20, 'type_code' => 4, 'message_placeholder' => 'Write a non-urgent message for your care team…', 'custom_css' => '']; }
    /** @param mixed $css */
    private static function normaliseCustomCss($css): string { return trim(wp_strip_all_tags((string) wp_unslash($css), false)); }
    private static function url(array $args = []): string { return AccountBuilders::url(AccountBuilders::TAB_DASHBOARD_MODULES, array_merge(['dashboard_section' => AccountBuilders::DASHBOARD_PATIENT_COMMUNICATIONS], $args)); }
}
