<?php

namespace App\Admin\Modules\AccountBuilders\Shortcodes;

use App\Admin\Modules\AccountBuilders\AccountBuilders;

if (!defined('ABSPATH')) {
    exit;
}

final class ShortcodeCatalog
{
    private const REDIRECT_OPTION = 'wp_cliniko_shortcode_redirects';
    private const DEFAULT_REDIRECT_OPTION = 'wp_cliniko_shortcode_default_redirects';
    private const DEFAULT_REDIRECT_CUSTOM_OPTION = 'wp_cliniko_shortcode_default_redirect_custom';

    public static function init(): void
    {
        add_action('admin_post_wp_cliniko_shortcode_redirects_save', [self::class, 'saveRedirects']);
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        ?>
        <div class="wrap cliniko-template-builder-page cliniko-shortcode-library">
            <h1>Shortcode Library</h1>
            <p>Find the shortcode for the experience you want to place on a page. Build or configure reusable templates first, then copy the generated shortcode here.</p>

            <div class="notice notice-info inline" style="max-width:1200px">
                <p><strong>Start here:</strong>
                    <a class="button button-secondary" href="<?php echo esc_url(AccountBuilders::url(AccountBuilders::TAB_PATIENT_FORMS)); ?>">Build patient forms</a>
                    <a class="button button-secondary" href="<?php echo esc_url(AccountBuilders::url(AccountBuilders::TAB_BOOKING_FORMS)); ?>">Build booking forms</a>
                    <a class="button button-secondary" href="<?php echo esc_url(AccountBuilders::url(AccountBuilders::TAB_DASHBOARD_MODULES)); ?>">Build dashboard modules</a>
                </p>
            </div>

            <?php self::renderSection('Personalisation and patient data', [
                ['[cliniko_patient_first_name]', 'Patient first name', 'Welcome back, [cliniko_patient_first_name]'],
                ['[cliniko_patient_last_name]', 'Patient last name', '[cliniko_patient_last_name]'],
                ['[cliniko_patient_full_name]', 'Combined first and last name', '[cliniko_patient_full_name]'],
                ['[cliniko_patient_preferred_name]', 'Preferred first name', '[cliniko_patient_preferred_name fallback="Patient"]'],
                ['[cliniko_patient_email]', 'Patient email address', '[cliniko_patient_email]'],
                ['[cliniko_patient_phone]', 'Primary patient phone number', '[cliniko_patient_phone]'],
                ['[cliniko_patient_value field="FIELD"]', 'Any allowed standard or Cliniko custom patient field', '[cliniko_patient_value field="date_of_birth" fallback="Not provided"]'],
                ['[cliniko_patient_unread_communications view="total"]', 'Unread clinic communications for the logged-in patient; view can be total, general, email, or sms', '[cliniko_patient_unread_communications view="email"]'],
            ], 'cliniko-shortcode-patient-data'); ?>

            <?php self::renderSection('Appointment summaries', [
                ['[cliniko_patient_appointments_total]', 'Total appointments', 'You have [cliniko_patient_appointments_total] appointments.'],
                ['[cliniko_patient_appointments_upcoming]', 'Appointments with a future start time', '[cliniko_patient_appointments_upcoming] upcoming'],
                ['[cliniko_patient_appointments_completed]', 'Past appointments where did_not_arrive is false', '[cliniko_patient_appointments_completed] completed'],
                ['[cliniko_patient_appointment_count type="TYPE"]', 'Generic count; TYPE can be total, upcoming, or completed', '[cliniko_patient_appointment_count type="completed" fallback="0"]'],
            ], 'cliniko-shortcode-appointments'); ?>

            <?php self::renderSection('Patient experiences and booking', [
                ['[cliniko_patient_form id="FORM_ID"]', 'Editable patient details form', '[cliniko_patient_form id="form_..."]'],
                ['[cliniko_patient_onboarding id="ONBOARDING_ID"]', 'Conditional multi-step patient profile onboarding', '[cliniko_patient_onboarding id="onboarding_..."]'],
                ['[cliniko_patient_forms id="FORM_ID"]', 'Previously completed forms for the authenticated patient', '[cliniko_patient_forms id="patient_forms_..."]'],
                ['[cliniko_patient_form_template id="FORM_ID"]', 'Blank Cliniko template the authenticated patient can complete', '[cliniko_patient_form_template id="patient_form_..."]'],
                ['[cliniko_guest_booking_form id="FORM_ID"]', 'Guest appointment booking and payment form', '[cliniko_guest_booking_form id="booking_..."]'],
                ['[cliniko_patient_booking_form id="FORM_ID"]', 'Booking form alias for existing pages', '[cliniko_patient_booking_form id="booking_..."]'],
                ['[cliniko_patient_booking_renewal]', 'Appointment renewal form using appointment_id; derives the appointment type and patient-form template from the previous booking', '[cliniko_patient_booking_renewal]'],
            ], 'cliniko-shortcode-experiences'); ?>

            <?php self::renderSection('Patient dashboard modules', [
                ['[cliniko_patient_details_module id="MODULE_ID"]', 'Display-only patient details module', '[cliniko_patient_details_module id="patient_details_..."]'],
                ['[cliniko_dashboard_module id="MODULE_ID"]', 'Appointment list module', '[cliniko_dashboard_module id="module_..."]'],
                ['[cliniko_patient_communications_module id="MODULE_ID"]', 'Patient communication timeline and non-urgent clinic memo composer', '[cliniko_patient_communications_module id="communications_..."]'],
                ['[cliniko_patient_communications_link url="/messages/"]', 'A messages link with an unread-count badge', '[cliniko_patient_communications_link url="/messages/" label="Messages"]'],
                ['[cliniko_appointment_details]', 'Authenticated appointment details page', '[cliniko_appointment_details]'],
            ], 'cliniko-shortcode-dashboard'); ?>

            <?php self::renderSection('Patient account management', [
                ['[cliniko_patient_verification]', 'Public email-verification page with optional automatic login and a configurable protected destination', '[cliniko_patient_verification success_url="/patient-dashboard/" login_after_verification="yes" button_label="Verify my account"]'],
                ['[cliniko_patient_close_account]', 'Email-confirmed closure of the WordPress/Ultimate Member patient portal account; display can be inline or modal and Cliniko clinical records are retained', '[cliniko_patient_close_account display="modal" trigger_label="Close my account" success_url="/account-closed/"]'],
            ], 'cliniko-shortcode-account'); ?>

            <div class="notice notice-info inline">
                <p><strong>How to use:</strong> copy a generated shortcode into a WordPress page, post, widget, or Elementor shortcode element. For dynamic booking forms, appointment type and patient form IDs can be supplied directly as shortcode attributes. Cliniko custom fields use the field key shown by the patient field builder, such as <code>custom_your_field_token</code>.</p>
            </div>
        </div>
        <?php
    }

    public static function saveRedirects(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('save_cliniko_shortcode_redirects');

        $redirects = [];
        $posted = is_array($_POST['redirects'] ?? null) ? wp_unslash($_POST['redirects']) : [];
        foreach ($posted as $shortcode => $configurations) {
            $shortcode = sanitize_key((string) $shortcode);
            if (!is_array($configurations)) {
                continue;
            }
            foreach ($configurations as $id => $url) {
                $id = sanitize_key((string) $id);
                $url = esc_url_raw((string) $url);
                if ($id !== '' && $url !== '') {
                    $redirects[$shortcode][$id] = $url;
                }
            }
        }
        update_option(self::REDIRECT_OPTION, $redirects, false);
        $defaults = [];
        $postedDefaults = is_array($_POST['default_redirects'] ?? null) ? wp_unslash($_POST['default_redirects']) : [];
        foreach ($postedDefaults as $shortcode => $url) {
            $shortcode = sanitize_key((string) $shortcode);
            $url = esc_url_raw((string) $url);
            if ($shortcode !== '' && $url !== '') {
                $defaults[$shortcode] = $url;
            }
        }
        update_option(self::DEFAULT_REDIRECT_OPTION, $defaults, false);
        $customDefaults = [];
        $postedCustomDefaults = is_array($_POST['default_redirect_custom'] ?? null) ? wp_unslash($_POST['default_redirect_custom']) : [];
        foreach ($postedCustomDefaults as $shortcode => $enabled) {
            $shortcode = sanitize_key((string) $shortcode);
            if ($shortcode !== '' && (string) $enabled === '1') {
                $customDefaults[$shortcode] = 1;
            }
        }
        update_option(self::DEFAULT_REDIRECT_CUSTOM_OPTION, $customDefaults, false);
        wp_safe_redirect(AccountBuilders::url(AccountBuilders::TAB_REDIRECTS, ['redirects_saved' => 1]));
        exit;
    }

    public static function renderRedirectPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        ?>
        <div class="wrap cliniko-template-builder-page cliniko-shortcode-redirects-page">
            <h1>Form Redirects</h1>
            <p>Choose where patients are sent after successfully submitting a form or completing a booking.</p>
            <?php self::renderRedirectSettings(); ?>
        </div>
        <?php
    }

    public static function redirectFor(string $shortcode, string $id): string
    {
        $redirects = get_option(self::REDIRECT_OPTION, []);
        $url = is_array($redirects)
            ? ($redirects[sanitize_key($shortcode)][sanitize_key($id)] ?? '')
            : '';

        return wp_validate_redirect((string) $url, '');
    }

    public static function defaultRedirectFor(string $shortcode): string
    {
        $defaults = get_option(self::DEFAULT_REDIRECT_OPTION, []);
        $url = is_array($defaults) ? ($defaults[sanitize_key($shortcode)] ?? '') : '';
        return wp_validate_redirect((string) $url, '');
    }

    private static function renderRedirectSettings(): void
    {
        $redirects = get_option(self::REDIRECT_OPTION, []);
        $defaults = get_option(self::DEFAULT_REDIRECT_OPTION, []);
        $customDefaults = get_option(self::DEFAULT_REDIRECT_CUSTOM_OPTION, []);
        $pages = get_pages(['post_status' => 'publish', 'sort_column' => 'post_title']);
        $groups = [
            'cliniko_patient_form' => [
                'title' => 'Editable patient details forms',
                'option' => 'wp_cliniko_patient_account_forms',
                'description' => 'Redirect after a patient successfully saves their details.',
            ],
            'cliniko_patient_form_template' => [
                'title' => 'Patient form templates',
                'option' => 'wp_cliniko_patient_form_template_shortcodes',
                'description' => 'Redirect after a patient successfully submits a Cliniko template form.',
            ],
            'cliniko_guest_booking_form' => [
                'title' => 'Guest booking forms',
                'option' => 'wp_cliniko_patient_booking_forms',
                'description' => 'Redirect after a guest booking form completes successfully.',
            ],
        ];
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wp_cliniko_shortcode_redirects_save">
            <?php wp_nonce_field('save_cliniko_shortcode_redirects'); ?>
        <h2 style="margin-top:28px">Default form submission redirects</h2>
        <p>These defaults apply to every matching form shortcode unless a created form has its own override.</p>
        <table class="widefat striped" style="max-width:1200px;margin-bottom:24px">
            <thead><tr><th>Generic shortcode</th><th>Default destination</th></tr></thead>
            <tbody>
            <?php foreach ([
                'cliniko_patient_form' => '[cliniko_patient_form id="FORM_ID"]',
                'cliniko_patient_form_template' => '[cliniko_patient_form_template id="FORM_ID"]',
                'cliniko_guest_booking_form' => '[cliniko_guest_booking_form id="FORM_ID"]',
                'cliniko_patient_booking_form' => '[cliniko_patient_booking_form id="FORM_ID"]',
                'cliniko_patient_booking_renewal' => '[cliniko_patient_booking_renewal]',
            ] as $shortcode => $example) :
                $defaultUrl = is_array($defaults) ? (string) ($defaults[$shortcode] ?? '') : '';
                $useCustomDefault = is_array($customDefaults) && !empty($customDefaults[$shortcode]);
                ?>
                <tr><td><code><?php echo esc_html($example); ?></code></td><td><?php self::renderDefaultRedirectControl($shortcode, $defaultUrl, $pages, $useCustomDefault); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <script>
        (function(){
            document.querySelectorAll('[data-cliniko-default-redirect]').forEach(function(control){
                const toggle=control.querySelector('[data-cliniko-custom-redirect-toggle]');
                const select=control.querySelector('[data-cliniko-page-redirect]');
                const input=control.querySelector('[data-cliniko-custom-redirect]');
                if(!toggle||!select||!input)return;

                function syncRedirectControl(){
                    const custom=toggle.checked;
                    if(custom&&input.value==='')input.value=select.value;
                    select.hidden=custom;
                    select.disabled=custom;
                    input.hidden=!custom;
                    input.disabled=!custom;
                }

                toggle.addEventListener('change',syncRedirectControl);
                select.addEventListener('change',function(){
                    if(!toggle.checked)input.value=select.value;
                });
                syncRedirectControl();
            });
        }());
        </script>

        <h2 style="margin-top:28px">Form submission redirects</h2>
        <p>Choose the page each configured form should open after a successful submission. Leave a form set to “Stay on the current page” to show its normal success message.</p>
        <?php if (isset($_GET['redirects_saved'])) : ?><div class="notice notice-success inline"><p>Form redirect settings saved.</p></div><?php endif; ?>
            <?php foreach ($groups as $shortcode => $group) :
                $configurations = get_option($group['option'], []);
                $configurations = is_array($configurations) ? $configurations : [];
                ?>
                <h3><?php echo esc_html($group['title']); ?></h3>
                <p><?php echo esc_html($group['description']); ?></p>
                <table class="widefat striped" style="max-width:1200px;margin-bottom:24px">
                    <thead><tr><th>Shortcode</th><th>Redirect destination</th></tr></thead>
                    <tbody>
                    <?php if ($configurations === []) : ?>
                        <tr><td colspan="2">No configurations have been created yet.</td></tr>
                    <?php else : foreach ($configurations as $id => $configuration) :
                        $savedUrl = is_array($redirects) && is_array($redirects[$shortcode] ?? null)
                            ? (string) ($redirects[$shortcode][$id] ?? '')
                            : '';
                        ?>
                        <tr>
                            <td><code>[<?php echo esc_html($shortcode); ?> id=&quot;<?php echo esc_attr((string) $id); ?>&quot;]</code><br><small><?php echo esc_html((string) ($configuration['name'] ?? $id)); ?></small></td>
                            <td><select name="redirects[<?php echo esc_attr($shortcode); ?>][<?php echo esc_attr((string) $id); ?>]" style="min-width:360px">
                                <option value="">Stay on the current page</option>
                                <?php foreach ($pages as $page) : $pageUrl = get_permalink($page); ?><option value="<?php echo esc_attr($pageUrl); ?>" <?php selected($savedUrl, $pageUrl); ?>><?php echo esc_html($page->post_title); ?></option><?php endforeach; ?>
                            </select></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
            <p><button type="submit" class="button button-primary">Save form redirect settings</button></p>
        </form>
        <?php
    }

    /** @param array<int,\WP_Post> $pages */
    private static function renderDefaultRedirectControl(string $shortcode, string $selectedUrl, array $pages, bool $useCustomUrl): void
    {
        $name = 'default_redirects[' . $shortcode . ']';
        $controlId = 'cliniko-default-redirect-' . sanitize_html_class($shortcode);
        $pageUrls = array_map(static function ($page): string {
            return (string) get_permalink($page);
        }, $pages);
        $usesCustomUrl = $useCustomUrl || ($selectedUrl !== '' && !in_array($selectedUrl, $pageUrls, true));
        ?>
        <div data-cliniko-default-redirect>
            <select
                id="<?php echo esc_attr($controlId . '-page'); ?>"
                name="<?php echo esc_attr($name); ?>"
                data-cliniko-page-redirect
                style="min-width:360px"
                <?php disabled($usesCustomUrl); ?>
                <?php echo $usesCustomUrl ? 'hidden' : ''; ?>
            >
                <option value="">Stay on the current page</option>
                <?php foreach ($pages as $page) : $pageUrl = get_permalink($page); ?><option value="<?php echo esc_attr($pageUrl); ?>" <?php selected($selectedUrl, $pageUrl); ?>><?php echo esc_html($page->post_title); ?></option><?php endforeach; ?>
            </select>
            <input
                id="<?php echo esc_attr($controlId . '-custom'); ?>"
                type="text"
                inputmode="url"
                name="<?php echo esc_attr($name); ?>"
                value="<?php echo esc_attr($selectedUrl); ?>"
                placeholder="/thank-you/ or https://example.com/thank-you/"
                data-cliniko-custom-redirect
                style="min-width:360px"
                <?php disabled(!$usesCustomUrl); ?>
                <?php echo $usesCustomUrl ? '' : 'hidden'; ?>
            >
            <label for="<?php echo esc_attr($controlId . '-toggle'); ?>" style="display:block;margin-top:8px">
                <input
                    id="<?php echo esc_attr($controlId . '-toggle'); ?>"
                    type="checkbox"
                    name="default_redirect_custom[<?php echo esc_attr($shortcode); ?>]"
                    value="1"
                    data-cliniko-custom-redirect-toggle
                    aria-controls="<?php echo esc_attr($controlId . '-page ' . $controlId . '-custom'); ?>"
                    <?php checked($usesCustomUrl); ?>
                >
                Enter a custom destination
            </label>
            <p class="description">Use a site-relative path or a full URL.</p>
        </div>
        <?php
    }

    /**
     * @param array<int,array{0:string,1:string,2:string}> $rows
     */
    private static function renderSection(string $title, array $rows, string $id = ''): void
    {
        ?>
        <h2 id="<?php echo esc_attr($id); ?>" style="margin-top:28px"><?php echo esc_html($title); ?></h2>
        <table class="widefat striped" style="max-width:1200px">
            <thead><tr><th style="width:36%">Shortcode</th><th style="width:29%">Returns</th><th>Example</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <?php preg_match('/\[([a-z0-9_]+)/i', $row[0], $shortcodeMatch); $shortcodeName = (string) ($shortcodeMatch[1] ?? ''); ?>
                <tr>
                    <td>
                        <code><?php echo esc_html($row[0]); ?></code>
                        <?php if ($shortcodeName !== '') : ?>
                            <br><a href="#" class="cliniko-shortcode-style-guide-link" data-shortcode-guide="<?php echo esc_attr($shortcodeName); ?>">Styling guide</a>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($row[1]); ?></td>
                    <td><code><?php echo esc_html($row[2]); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function renderStyleGuideModal(): void
    {
        ?>
        <div class="cliniko-shortcode-style-guide-modal" data-shortcode-guide-modal hidden>
            <div class="cliniko-shortcode-style-guide-backdrop" data-shortcode-guide-close></div>
            <section class="cliniko-shortcode-style-guide-dialog" role="dialog" aria-modal="true" aria-labelledby="cliniko-shortcode-style-guide-title">
                <header>
                    <div>
                        <p class="cliniko-shortcode-style-guide-eyebrow">Shortcode styling guide</p>
                        <h2 id="cliniko-shortcode-style-guide-title" data-shortcode-guide-title>Styling guide</h2>
                    </div>
                    <button type="button" class="button-link" data-shortcode-guide-close>Close</button>
                </header>
                <div class="cliniko-shortcode-style-guide-content" data-shortcode-guide-content></div>
            </section>
        </div>
        <style>
            .cliniko-shortcode-style-guide-modal[hidden]{display:none}
            .cliniko-shortcode-style-guide-modal{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:24px}
            .cliniko-shortcode-style-guide-backdrop{position:absolute;inset:0;background:rgba(16,24,40,.48)}
            .cliniko-shortcode-style-guide-dialog{position:relative;width:min(760px,100%);max-height:min(760px,90vh);overflow:auto;background:#fff;border:1px solid #d9e1ea;border-radius:12px;box-shadow:0 24px 70px rgba(16,24,40,.24)}
            .cliniko-shortcode-style-guide-dialog header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:22px 24px 16px;border-bottom:1px solid #eaecf0}
            .cliniko-shortcode-style-guide-dialog h2{margin:0;color:#101828;font-size:21px}
            .cliniko-shortcode-style-guide-eyebrow{margin:0 0 5px;color:#1976d2;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
            .cliniko-shortcode-style-guide-content{padding:20px 24px 24px;color:#344054;font-size:14px;line-height:1.5}
            .cliniko-shortcode-style-guide-content h3{margin:20px 0 8px;color:#101828;font-size:15px}
            .cliniko-shortcode-style-guide-content h3:first-child{margin-top:0}
            .cliniko-shortcode-style-guide-content code{padding:2px 5px;background:#f2f4f7;border-radius:4px;font-size:12px}
            .cliniko-shortcode-style-guide-content pre{margin:10px 0;padding:14px;overflow:auto;background:#101828;color:#f2f4f7;border-radius:8px;font-size:12px;line-height:1.5}
            .cliniko-shortcode-style-guide-content .notice{padding:10px 12px;background:#f5faff;border-left:3px solid #1976d2}
        </style>
        <script>
        (function(){
            const modal=document.querySelector('[data-shortcode-guide-modal]');
            if(!modal)return;
            const title=modal.querySelector('[data-shortcode-guide-title]');
            const content=modal.querySelector('[data-shortcode-guide-content]');
            const guides={
                booking:{title:'Booking forms',intro:'Booking forms contain patient-form sections, appointment selection, optional patient details, payment, and step controls.',selectors:'.cliniko-patient-booking-form\n.cliniko-booking-step\n.cliniko-booking-calendar\n.cliniko-booking-step-controls',css:'.cliniko-patient-booking-form .cliniko-booking-step {\n  background: #fff;\n}\n\n.cliniko-patient-booking-form input {\n  border-color: #1976d2 !important;\n}',note:'Use the form wrapper as the scope. Payment fields rendered inside a Stripe iframe cannot be styled from page CSS.'},
                patientForm:{title:'Patient account forms',intro:'Editable logged-in patient profile forms use a stable form wrapper and section, field, and choice-group hooks.',selectors:'.cliniko-patient-account-form\n.cliniko-patient-account-form__section\n.cliniko-patient-account-form__field\n.cliniko-patient-account-form__choice-group',css:'.cliniko-patient-account-form {\n  max-width: 760px;\n}\n\n.cliniko-patient-account-form .cliniko-patient-account-form__field input {\n  background: #fff;\n}',note:'These forms require an authenticated Cliniko patient.'},
                onboarding:{title:'Patient onboarding',intro:'Conditional profile onboarding flows expose step, field, progress, and completion hooks for custom styling.',selectors:'.cliniko-patient-onboarding\n.cliniko-patient-onboarding__header\n.cliniko-patient-onboarding__step\n.cliniko-patient-onboarding__field\n.cliniko-patient-onboarding__progress',css:'.cliniko-patient-onboarding {\n  max-width: 760px;\n}\n\n.cliniko-patient-onboarding__step {\n  background: #fff;\n}',note:'The shortcode renders only for logged-in, verified Cliniko patients while configured required fields are incomplete.'},
                template:{title:'Patient form templates',intro:'Template shortcodes render Cliniko sections and questions for an authenticated patient to complete.',selectors:'.cliniko-patient-template-form\n.cliniko-patient-template-form__section\n.cliniko-patient-template-form__field\n.cliniko-patient-template-form__hint',css:'.cliniko-patient-template-form {\n  max-width: 760px;\n}\n\n.cliniko-patient-template-form__section {\n  margin-bottom: 24px;\n}',note:'Scope selectors to .cliniko-patient-template-form so site-wide form styles do not leak into this shortcode.'},
                history:{title:'Completed patient forms',intro:'This shortcode displays previously completed forms and their responses for the logged-in patient.',selectors:'.cliniko-patient-forms\n.cliniko-patient-forms__item\n.cliniko-patient-forms__content',css:'.cliniko-patient-forms {\n  max-width: 900px;\n}\n\n.cliniko-patient-forms__item {\n  border-bottom: 1px solid #d9e1ea;\n}',note:'The exact response fields depend on the completed Cliniko forms available to the patient.'},
                dashboard:{title:'Patient dashboard modules',intro:'Dashboard modules display authenticated patient information, appointments, or appointment details.',selectors:'.cliniko-patient-details-module\n.cliniko-dashboard-module\n.cliniko-appointment-details',css:'.cliniko-dashboard-module,\n.cliniko-patient-details-module,\n.cliniko-appointment-details {\n  max-width: 900px;\n}',note:'Dashboard modules are intended for logged-in patients.'},
                closure:{title:'Patient account closure',intro:'The account-closure panel separates portal deletion from the clinic health record and uses an email-confirmed final action.',selectors:'.cliniko-account-closure\n.cliniko-account-closure__warning\n.cliniko-account-closure__acknowledgement\n.cliniko-account-closure__button',css:'.cliniko-account-closure {\n  max-width: 720px;\n}\n\n.cliniko-account-closure__button {\n  background: #b42318;\n}',note:'Keep the destructive action visually distinct. Do not hide the warning that Cliniko clinical records are handled separately.'},
                verification:{title:'Patient account verification',intro:'The public verification page safely turns an email token into an explicit patient-account confirmation.',selectors:'.cliniko-patient-verification\n.cliniko-patient-verification__header\n.cliniko-patient-verification__message\n.cliniko-patient-verification__button',css:'.cliniko-patient-verification {\n  max-width: 680px;\n}\n\n.cliniko-patient-verification__button {\n  background: #175cd3;\n}',note:'Keep this page public and exclude it from full-page or CDN caching. Set its URL in the Patient verification email template.'},
                variable:{title:'Patient and appointment value shortcodes',intro:'Value shortcodes return text, numbers, or a fallback. They do not render a form or provide a styling wrapper.',selectors:'No dedicated wrapper is guaranteed.',css:'/* Style the surrounding element where you place the shortcode. */\n.patient-summary__value {\n  color: #1976d2;\n}',note:'Place the shortcode inside your own element, for example: <span class="patient-summary__value">[cliniko_patient_first_name]</span>.'}
            };
            function guideFor(name){
                if(name.indexOf('booking')!==-1)return guides.booking;
                if(name==='cliniko_patient_onboarding')return guides.onboarding;
                if(name==='cliniko_patient_form')return guides.patientForm;
                if(name.indexOf('form_template')!==-1)return guides.template;
                if(name==='cliniko_patient_forms')return guides.history;
                if(name==='cliniko_patient_close_account')return guides.closure;
                if(name==='cliniko_patient_verification')return guides.verification;
                if(name.indexOf('dashboard')!==-1||name.indexOf('appointment_details')!==-1||name.indexOf('patient_details_module')!==-1||name.indexOf('attachments')!==-1)return guides.dashboard;
                return guides.variable;
            }
            function open(name){
                const guide=guideFor(name);
                title.textContent=guide.title;
                content.innerHTML='<p>'+guide.intro+'</p><h3>Available hooks</h3><pre>'+guide.selectors.replace(/</g,'&lt;')+'</pre><h3>Starter CSS</h3><pre>'+guide.css.replace(/</g,'&lt;')+'</pre><p class="notice">'+guide.note+'</p>';
                modal.hidden=false;
                document.body.classList.add('cliniko-shortcode-guide-open');
            }
            function close(){modal.hidden=true;document.body.classList.remove('cliniko-shortcode-guide-open');}
            function handleLink(e){const link=e.target.closest&&e.target.closest('[data-shortcode-guide]');if(!link)return;e.preventDefault();open(link.dataset.shortcodeGuide||'');}
            function bindLinks(){document.querySelectorAll('[data-shortcode-guide]').forEach(link=>{if(link.dataset.shortcodeGuideBound==='1')return;link.dataset.shortcodeGuideBound='1';link.addEventListener('click',handleLink);});}
            document.addEventListener('click',handleLink);
            bindLinks();
            window.setTimeout(bindLinks,0);
            window.setTimeout(bindLinks,250);
            modal.querySelectorAll('[data-shortcode-guide-close]').forEach(el=>el.addEventListener('click',close));
            document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)close();});
        }());
        </script>
        <?php
    }
}
