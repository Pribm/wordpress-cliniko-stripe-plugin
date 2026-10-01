<?php

namespace App\Admin\Modules\AccountBuilders\Emails;

use App\Admin\Modules\AccountBuilders\AccountBuilders;

use App\Service\PatientLinkService;
use App\Service\PatientAccountClosureEmailTemplate;
use App\Service\PatientVerificationEmailTemplate;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientVerificationEmails
{
    private const SAVE_ACTION = 'wp_cliniko_save_patient_verification_email';
    private const RESET_ACTION = 'wp_cliniko_reset_patient_verification_email';

    public static function init(): void
    {
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'save']);
        add_action('admin_post_' . self::RESET_ACTION, [self::class, 'reset']);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $templateType = self::templateType((string) ($_GET['email_template'] ?? 'verification'));
        $isVerification = $templateType === 'verification';
        $isReceipt = $templateType === PatientAccountClosureEmailTemplate::RECEIPT;
        $template = $isVerification
            ? PatientVerificationEmailTemplate::get()
            : PatientAccountClosureEmailTemplate::get($templateType);
        $defaults = $isVerification
            ? PatientVerificationEmailTemplate::defaults()
            : PatientAccountClosureEmailTemplate::defaults($templateType);
        $pageTitle = $isVerification
            ? 'Patient verification email'
            : ($isReceipt ? 'Account closure receipt' : 'Account closure confirmation');
        $pageDescription = $isVerification
            ? 'Customize the email sent while a patient account is pending verification.'
            : ($isReceipt
                ? 'Customize the receipt sent after the patient portal account has been closed.'
                : 'Customize the secure confirmation email sent when a patient requests portal account closure.');
        $suppressWelcomeEmail = PatientLinkService::suppressesNextendWelcomeEmail();
        $verificationPageUrl = PatientLinkService::verificationPageUrl();
        $resetUrl = wp_nonce_url(
            add_query_arg([
                'action' => self::RESET_ACTION,
                'template_type' => $templateType,
            ], admin_url('admin-post.php')),
            self::RESET_ACTION
        );
        ?>
        <div class="wrap es-email-builder">
            <h1>Email templates</h1>
            <p>Customize branded patient account emails and preview how they will appear.</p>
            <nav class="nav-tab-wrapper es-email-builder__tabs" aria-label="Email templates">
                <?php foreach (self::templateTabs() as $type => $label) : ?>
                    <a class="nav-tab <?php echo $templateType === $type ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(AccountBuilders::url(AccountBuilders::TAB_EMAILS, ['email_template' => $type])); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <h2 class="es-email-builder__template-title"><?php echo esc_html($pageTitle); ?></h2>
            <p><?php echo esc_html($pageDescription); ?></p>
            <?php if (isset($_GET['email_template_updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Email template saved.</p></div>
            <?php endif; ?>
            <?php if (isset($_GET['email_template_reset'])) : ?>
                <div class="notice notice-success is-dismissible"><p>Email template restored to its defaults.</p></div>
            <?php endif; ?>

            <div class="es-email-builder__grid">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="es-email-builder__editor">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
                    <input type="hidden" name="template_type" value="<?php echo esc_attr($templateType); ?>">
                    <?php wp_nonce_field(self::SAVE_ACTION); ?>

                    <?php if ($isVerification) : ?><section class="es-email-panel">
                        <h2>Registration email delivery</h2>
                        <input type="hidden" name="suppress_nextend_welcome_email" value="0">
                        <label class="es-email-delivery-option">
                            <input type="checkbox" name="suppress_nextend_welcome_email" value="1" <?php checked($suppressWelcomeEmail); ?>>
                            <span>
                                <strong>Send only the Cliniko verification email to new social patients</strong>
                                <span>Suppress the separate WordPress set-password email for Nextend registrations using a configured patient role.</span>
                            </span>
                        </label>
                        <p class="description">Recommended. The WordPress administrator still receives the normal new-user notification. Patients can create a local password later through Lost Password if needed.</p>
                    </section><?php endif; ?>

                    <?php if ($isVerification) : ?><section class="es-email-panel">
                        <h2>Verification page</h2>
                        <?php self::textField('verification_page_url', 'Public verification page URL', $verificationPageUrl, 'url', false); ?>
                        <p class="description">Create a public, uncached WordPress page containing <code>[cliniko_patient_verification success_url="/patient-dashboard/" login_after_verification="yes"]</code>, then enter that page's full URL here. Clicking the email button opens that page, automatically verifies the token, creates a short patient session, and opens the protected destination. When this field is empty, the email uses a compatibility handler and redirects successful verification to the site homepage without logging in.</p>
                    </section><?php endif; ?>

                    <section class="es-email-panel">
                        <h2>Message</h2>
                        <?php self::textField('subject', 'Email subject', $template['subject']); ?>
                        <?php self::textField('preheader', 'Preview text', $template['preheader']); ?>
                        <?php self::textField('heading', 'Heading', $template['heading']); ?>
                        <?php self::textareaField('body', 'Message', $template['body'], 7); ?>
                        <?php if (!$isReceipt) self::textField('button_text', 'Button label', $template['button_text']); ?>
                        <?php self::textareaField('footer', 'Footer', $template['footer'], 4); ?>
                    </section>

                    <section class="es-email-panel">
                        <h2>Branding</h2>
                        <?php self::textField('logo_url', 'Logo URL', $template['logo_url'], 'url'); ?>
                        <div class="es-email-colors">
                            <?php self::colorField('background_color', 'Page background', $template['background_color']); ?>
                            <?php self::colorField('card_color', 'Email background', $template['card_color']); ?>
                            <?php self::colorField('text_color', 'Text', $template['text_color']); ?>
                            <?php if (!$isReceipt) : ?>
                                <?php self::colorField('button_color', 'Button', $template['button_color']); ?>
                                <?php self::colorField('button_text_color', 'Button text', $template['button_text_color']); ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="es-email-panel">
                        <h2>Available variables</h2>
                        <p><code>{first_name}</code> <code>{site_name}</code><?php if (!$isReceipt) : ?> <code>{expires_minutes}</code><?php endif; ?></p>
                        <p class="description"><?php echo esc_html($isVerification
                            ? 'The secure verification URL is always assigned to the button and cannot be removed from the email.'
                            : ($isReceipt
                                ? 'The receipt has no action button because the account has already been closed.'
                                : 'The secure account-closure review URL is always assigned to the button and cannot be removed from the email.')); ?></p>
                    </section>

                    <div class="es-email-builder__actions">
                        <?php submit_button('Save email template', 'primary', 'submit', false); ?>
                        <a class="button" href="<?php echo esc_url($resetUrl); ?>" onclick="return confirm('Restore the default <?php echo esc_attr(strtolower($pageTitle)); ?>?')">Reset defaults</a>
                    </div>
                </form>

                <aside class="es-email-builder__preview-column">
                    <div class="es-email-panel es-email-builder__preview-toolbar">
                        <strong>Live preview</strong>
                        <button type="button" class="button" data-email-width="360">Mobile</button>
                        <button type="button" class="button" data-email-width="600">Desktop</button>
                    </div>
                    <div class="es-email-panel es-email-inbox-preview">
                        <strong id="es-email-preview-subject"></strong>
                        <span id="es-email-preview-inbox-text"></span>
                    </div>
                    <div id="es-email-preview-shell" class="es-email-preview-shell">
                        <div id="es-email-preview-card" class="es-email-preview-card">
                            <img id="es-email-preview-logo" alt="" hidden>
                            <div id="es-email-preview-preheader" hidden></div>
                            <h2 id="es-email-preview-heading"></h2>
                            <div id="es-email-preview-body"></div>
                            <a id="es-email-preview-button" href="#" onclick="return false"<?php echo $isReceipt ? ' hidden' : ''; ?>></a>
                            <div id="es-email-preview-footer"></div>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
        <style>
            .es-email-builder__grid{display:grid;grid-template-columns:minmax(0,7fr) minmax(340px,5fr);gap:24px;align-items:start;max-width:1280px}
            .es-email-builder__tabs{margin:20px 0 22px}.es-email-builder__template-title{margin-bottom:4px}
            .es-email-panel{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px;margin:0 0 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
            .es-email-panel h2{margin-top:0}.es-email-field{display:block;margin:0 0 16px}.es-email-field>span{display:block;font-weight:600;margin-bottom:6px}
            .es-email-delivery-option{display:flex;gap:10px;align-items:flex-start}.es-email-delivery-option>input{margin-top:3px}.es-email-delivery-option>span{display:flex;flex-direction:column;gap:4px}.es-email-delivery-option>span>span{color:#646970}
            .es-email-field input[type=text],.es-email-field input[type=url],.es-email-field textarea{width:100%}
            .es-email-colors{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.es-email-color{display:flex;align-items:center;justify-content:space-between;gap:12px}
            .es-email-color input{width:52px;height:34px;padding:2px}.es-email-builder__actions{display:flex;gap:10px;align-items:center}
            .es-email-builder__preview-column{position:sticky;top:46px}.es-email-builder__preview-toolbar{display:flex;gap:8px;align-items:center}.es-email-builder__preview-toolbar strong{margin-right:auto}
            .es-email-inbox-preview{display:flex;gap:8px;white-space:nowrap;overflow:hidden}.es-email-inbox-preview strong{flex:0 0 auto}.es-email-inbox-preview span{color:#646970;overflow:hidden;text-overflow:ellipsis}
            .es-email-preview-shell{padding:28px 16px;background:#f3f4f6;transition:.2s;max-width:600px;margin:auto;border-radius:8px;box-sizing:border-box}
            .es-email-preview-card{font-family:Arial,sans-serif;padding:32px;background:#fff;color:#1f2937;border-radius:10px;box-sizing:border-box}
            #es-email-preview-logo{display:block;max-width:180px;max-height:72px;margin:0 0 24px}#es-email-preview-heading{font-size:24px;line-height:1.3;margin:0 0 16px}
            #es-email-preview-body{white-space:pre-line;line-height:1.6}#es-email-preview-button{display:inline-block;margin:28px 0;background:#2563eb;color:#fff;text-decoration:none;padding:12px 20px;border-radius:6px;font-weight:600}#es-email-preview-button[hidden]{display:none}
            #es-email-preview-footer{white-space:pre-line;font-size:13px;line-height:1.5;opacity:.72}
            @media(max-width:960px){.es-email-builder__grid{grid-template-columns:1fr}.es-email-builder__preview-column{position:static}}
        </style>
        <script>
            (() => {
                const form = document.querySelector('.es-email-builder__editor');
                if (!form) return;
                const defaults = <?php echo wp_json_encode($defaults); ?>;
                const hasButton = <?php echo $isReceipt ? 'false' : 'true'; ?>;
                const value = key => (form.elements['template[' + key + ']'] || {}).value || defaults[key] || '';
                const replace = text => text.replaceAll('{first_name}', 'Alex').replaceAll('{site_name}', <?php echo wp_json_encode(get_bloginfo('name')); ?>).replaceAll('{expires_minutes}', '30');
                const update = () => {
                    const shell = document.getElementById('es-email-preview-shell');
                    const card = document.getElementById('es-email-preview-card');
                    const logo = document.getElementById('es-email-preview-logo');
                    shell.style.backgroundColor = value('background_color');
                    card.style.backgroundColor = value('card_color');
                    card.style.color = value('text_color');
                    document.getElementById('es-email-preview-subject').textContent = replace(value('subject'));
                    document.getElementById('es-email-preview-inbox-text').textContent = '— ' + replace(value('preheader'));
                    document.getElementById('es-email-preview-heading').textContent = replace(value('heading'));
                    document.getElementById('es-email-preview-body').textContent = replace(value('body'));
                    const button = document.getElementById('es-email-preview-button');
                    button.hidden = !hasButton;
                    if (hasButton) {
                        button.textContent = replace(value('button_text'));
                        button.style.backgroundColor = value('button_color');
                        button.style.color = value('button_text_color');
                    }
                    const footer = document.getElementById('es-email-preview-footer');
                    footer.textContent = replace(value('footer'));
                    footer.style.color = value('text_color');
                    const logoUrl = value('logo_url').trim();
                    logo.hidden = !logoUrl;
                    if (logoUrl) logo.src = logoUrl;
                };
                form.addEventListener('input', update);
                document.querySelectorAll('[data-email-width]').forEach(button => button.addEventListener('click', () => {
                    document.getElementById('es-email-preview-shell').style.maxWidth = button.dataset.emailWidth + 'px';
                }));
                update();
            })();
        </script>
        <?php
    }

    public static function save(): void
    {
        self::authorize(self::SAVE_ACTION);
        $templateType = self::templateType((string) ($_POST['template_type'] ?? 'verification'));
        $input = isset($_POST['template']) ? wp_unslash($_POST['template']) : [];
        if ($templateType === 'verification') {
            update_option(PatientVerificationEmailTemplate::OPTION, PatientVerificationEmailTemplate::sanitize($input), false);
            $verificationPageUrl = wp_validate_redirect(
                esc_url_raw((string) wp_unslash($_POST['verification_page_url'] ?? '')),
                ''
            );
            update_option(PatientLinkService::OPTION_VERIFICATION_PAGE_URL, $verificationPageUrl, false);
            update_option(
                PatientLinkService::OPTION_SUPPRESS_NEXTEND_WELCOME_EMAIL,
                isset($_POST['suppress_nextend_welcome_email'])
                    && (string) $_POST['suppress_nextend_welcome_email'] === '1'
                    ? 'yes'
                    : 'no',
                false
            );
        } else {
            update_option(
                PatientAccountClosureEmailTemplate::option($templateType),
                PatientAccountClosureEmailTemplate::sanitize($input, $templateType),
                false
            );
        }
        wp_safe_redirect(AccountBuilders::url(AccountBuilders::TAB_EMAILS, [
            'email_template' => $templateType,
            'email_template_updated' => 1,
        ]));
        exit;
    }

    public static function reset(): void
    {
        self::authorize(self::RESET_ACTION);
        $templateType = self::templateType((string) ($_GET['template_type'] ?? 'verification'));
        if ($templateType === 'verification') {
            delete_option(PatientVerificationEmailTemplate::OPTION);
            delete_option(PatientLinkService::OPTION_SUPPRESS_NEXTEND_WELCOME_EMAIL);
        } else {
            delete_option(PatientAccountClosureEmailTemplate::option($templateType));
        }
        wp_safe_redirect(AccountBuilders::url(AccountBuilders::TAB_EMAILS, [
            'email_template' => $templateType,
            'email_template_reset' => 1,
        ]));
        exit;
    }

    private static function authorize(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer($action);
    }

    private static function textField(string $key, string $label, string $value, string $type = 'text', bool $templateField = true): void
    {
        printf(
            '<label class="es-email-field"><span>%1$s</span><input type="%2$s" name="%3$s" value="%4$s"></label>',
            esc_html($label),
            esc_attr($type),
            esc_attr($templateField ? 'template[' . $key . ']' : $key),
            esc_attr($value)
        );
    }

    private static function textareaField(string $key, string $label, string $value, int $rows): void
    {
        printf(
            '<label class="es-email-field"><span>%1$s</span><textarea name="template[%2$s]" rows="%3$d">%4$s</textarea></label>',
            esc_html($label),
            esc_attr($key),
            $rows,
            esc_textarea($value)
        );
    }

    private static function colorField(string $key, string $label, string $value): void
    {
        printf(
            '<label class="es-email-color"><span>%1$s</span><input type="color" name="template[%2$s]" value="%3$s"></label>',
            esc_html($label),
            esc_attr($key),
            esc_attr($value)
        );
    }

    /** @return array<string,string> */
    private static function templateTabs(): array
    {
        return [
            'verification' => 'Patient verification',
            PatientAccountClosureEmailTemplate::REQUEST => 'Closure confirmation',
            PatientAccountClosureEmailTemplate::RECEIPT => 'Closure receipt',
        ];
    }

    private static function templateType(string $type): string
    {
        $type = sanitize_key($type);
        return array_key_exists($type, self::templateTabs()) ? $type : 'verification';
    }
}
