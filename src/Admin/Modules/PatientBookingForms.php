<?php

namespace App\Admin\Modules;

use App\Model\AppointmentType;
use App\Model\Booking;
use App\Model\PatientFormTemplate;
use App\Admin\Modules\Settings\Credentials;
use App\Service\ClinikoService;
use App\Service\PatientBookingRenewalService;
use App\Support\Auth;
use App\Admin\Modules\AccountBuilders\Shortcodes\ShortcodeCatalog;
use App\Admin\Modules\AccountBuilders\CustomCode;
use App\Admin\Modules\AccountBuilders\Forms\ShortcodeFormInputRules;
use App\Admin\Modules\Components\AppointmentSelector;
use App\Admin\Modules\Components\ComponentStyles;
use App\Admin\Modules\Components\DataReview;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientBookingForms
{
    private const LEGACY_ADMIN_PAGE = 'wp-cliniko-patient-booking-forms';
    private const OPTION_KEY = 'wp_cliniko_patient_booking_forms';
    private const ALIAS_OPTION_KEY = 'wp_cliniko_booking_aliases';
    private const SHORTCODE = 'cliniko_patient_booking_form';
    private const GUEST_SHORTCODE = 'cliniko_guest_booking_form';
    private const RENEWAL_SHORTCODE = 'cliniko_patient_booking_renewal';

    /** @var array<string,mixed>|null */
    private static ?array $renewalPrefillContent = null;
    private static string $renewalAppointmentId = '';
    /** @var array<string,string>|null */
    private static ?array $renewalAppointmentSummary = null;

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerLegacyAdminPage'], 20);
        add_action('admin_init', [self::class, 'redirectLegacyAdminPage']);
        add_action('admin_post_wp_cliniko_patient_booking_form_save', [self::class, 'save']);
        add_action('admin_post_wp_cliniko_patient_booking_form_delete', [self::class, 'delete']);
        add_action('admin_post_wp_cliniko_booking_alias_save', [self::class, 'saveAlias']);
        add_action('admin_post_wp_cliniko_booking_alias_delete', [self::class, 'deleteAlias']);
        add_shortcode(self::SHORTCODE, [self::class, 'shortcode']);
        add_shortcode(self::GUEST_SHORTCODE, [self::class, 'guestShortcode']);
        add_shortcode(self::RENEWAL_SHORTCODE, [self::class, 'renewalShortcode']);
    }

    /**
     * Keep the old admin URL registered so WordPress allows it to reach the redirect.
     * The page is removed from the visible submenu immediately after registration.
     */
    public static function registerLegacyAdminPage(): void
    {
        add_submenu_page(
            'wp-cliniko-stripe-settings',
            'Booking Forms',
            'Booking Forms',
            'manage_options',
            self::LEGACY_ADMIN_PAGE,
            [self::class, 'renderPage']
        );

        remove_submenu_page('wp-cliniko-stripe-settings', self::LEGACY_ADMIN_PAGE);
    }

    public static function redirectLegacyAdminPage(): void
    {
        if (!is_admin() || (string) ($_GET['page'] ?? '') !== self::LEGACY_ADMIN_PAGE) {
            return;
        }

        $args = [];
        foreach (['action', 'id'] as $key) {
            if (isset($_GET[$key])) {
                $args[$key] = sanitize_key((string) $_GET[$key]);
            }
        }

        wp_safe_redirect(
            \App\Admin\Modules\AccountBuilders\AccountBuilders::url(
                \App\Admin\Modules\AccountBuilders\AccountBuilders::TAB_BOOKING_FORMS,
                $args
            )
        );
        exit;
    }

    public static function renderPage(string $bookingSection = 'guest'): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $bookingSection = in_array($bookingSection, ['guest', 'patient', 'dynamic', 'aliases'], true) ? $bookingSection : 'guest';
        $action = sanitize_key((string) ($_GET['action'] ?? 'list'));
        if (in_array($action, ['new', 'edit'], true)) {
            self::renderEditor($action === 'edit' ? sanitize_key((string) ($_GET['id'] ?? '')) : '');
            return;
        }

        if ($bookingSection === 'dynamic') {
            ?>
            <div class="wrap cliniko-template-builder-page">
                <h1>Dynamic Booking Shortcode</h1>
                <p>Build a reusable shortcode without saving a booking configuration. Choose whether it is for guests or logged-in patients.</p>
                <?php self::renderGenericBuilder(); ?>
            </div>
            <?php
            return;
        }

        if ($bookingSection === 'aliases') {
            $showAliasForm = sanitize_key((string) ($_GET['action'] ?? '')) === 'alias-new';
            ?>
            <div class="wrap cliniko-template-builder-page">
                <h1 class="wp-heading-inline">Booking Aliases</h1>
                <?php if (!$showAliasForm) : ?><a class="page-title-action" href="<?php echo esc_url(self::url(['booking_section' => 'aliases', 'action' => 'alias-new'])); ?>">Add New</a><?php endif; ?>
                <p>Save fixed booking combinations and expose them through a short alias in the page URL.</p>
                <?php self::renderAliasManager($showAliasForm); ?>
            </div>
            <?php
            return;
        }

        $forms = array_filter(self::forms(), static function (array $form) use ($bookingSection): bool {
            return (($form['booking_mode'] ?? 'guest') === $bookingSection);
        });
        $title = $bookingSection === 'patient' ? 'Logged-in Patient Booking Forms' : 'Guest Booking Forms';
        $addUrl = self::url(['action' => 'new', 'booking_section' => $bookingSection, 'booking_mode' => $bookingSection]);
        ?>
        <div id="cliniko-booking-configurations" class="wrap cliniko-template-builder-page">
            <h1 class="wp-heading-inline"><?php echo esc_html($title); ?></h1>
            <a class="page-title-action" href="<?php echo esc_url($addUrl); ?>">Add New</a>
            <p><?php echo $bookingSection === 'patient' ? 'Create booking forms for authenticated patients. The linked Cliniko patient is resolved server-side.' : 'Create reusable booking forms for visitors and collect their patient details during booking.'; ?><br><a href="#" class="cliniko-shortcode-style-guide-link" data-shortcode-guide="cliniko_<?php echo esc_attr($bookingSection === 'patient' ? 'patient' : 'guest'); ?>_booking_form">Open styling guide</a></p>
            <?php if (isset($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p>Booking form saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])) : ?><div class="notice notice-success is-dismissible"><p>Booking form deleted.</p></div><?php endif; ?>
            <table class="widefat striped" style="max-width:1100px;margin-top:20px">
                <thead><tr><th>Name</th><th>Booking mode</th><th>Appointment type</th><th>Form template</th><th>Shortcode</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if ($forms === []) : ?>
                    <tr><td colspan="6">No booking forms have been created.</td></tr>
            <?php else : foreach ($forms as $id => $form) : ?>
                    <tr>
                        <td><strong><?php echo esc_html((string) ($form['name'] ?? '')); ?></strong></td>
                        <td><?php echo (string) ($form['booking_mode'] ?? 'guest') === 'patient' ? 'Logged-in patient' : 'Guest'; ?></td>
                        <td><?php echo esc_html((string) ($form['appointment_type_id'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($form['patient_form_template_id'] ?? '')); ?></td>
                        <td><code>[<?php echo esc_html((string) ($form['booking_mode'] ?? 'guest') === 'patient' ? self::SHORTCODE : self::GUEST_SHORTCODE); ?> id="<?php echo esc_attr($id); ?>"]</code></td>
                        <td>
                            <a href="<?php echo esc_url(self::url(['action' => 'edit', 'id' => $id, 'booking_section' => $bookingSection])); ?>">Edit</a>
                            | <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_cliniko_patient_booking_form_delete&id=' . rawurlencode($id)), 'delete_patient_booking_form_' . $id)); ?>" onclick="return confirm('Delete this form?');">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function renderGenericBuilder(): void
    {
        self::enqueueBookingBuilderStyle();
        $appointmentTypes = [];
        $templates = [];
        $aliases = self::aliases();
        $codeBundles = CustomCode::enabledBundles();
        $loadError = false;
        try {
            $client = cliniko_client(false);
            $appointmentTypes = AppointmentType::all($client, true);
            $templates = PatientFormTemplate::all($client, true);
        } catch (\Throwable $exception) {
            $loadError = true;
            error_log('Cliniko generic booking shortcode options failed: ' . $exception->getMessage());
        }
        ?>
        <div id="cliniko-generic-booking-builder" class="postbox" style="padding:20px;margin-top:28px;max-width:1100px" data-generic-booking-builder>
            <h2 style="margin-top:0">Generic new-booking shortcode</h2>
            <p>Build a reusable shortcode without saving a booking form. Use direct selections, or choose a booking alias created in the Booking aliases section.<br><a href="#" class="cliniko-shortcode-style-guide-link" data-shortcode-guide="cliniko_guest_booking_form">Open styling guide</a></p>
            <?php if ($loadError) : ?><div class="notice notice-error inline"><p>Cliniko options could not be loaded. Check the API credentials and reload this page.</p></div><?php endif; ?>
            <table class="form-table">
                <tr>
                    <th><label for="generic-booking-source">Booking setup</label></th>
                    <td>
                        <label><input type="checkbox" id="generic-booking-source" data-generic-booking-source> Use a booking alias</label>
                        <p class="description">When enabled, the alias provides the appointment type and patient form template. Choose whether the booking is for a guest or logged-in patient here.</p>
                    </td>
                </tr>
                <tr data-generic-booking-alias-row hidden>
                    <th><label for="generic-booking-alias">Booking alias</label></th>
                    <td>
                        <?php if ($aliases !== []) : ?>
                            <select id="generic-booking-alias" data-generic-booking-alias style="min-width:420px">
                                <option value="">Select a booking alias</option>
                                <?php foreach ($aliases as $alias => $aliasConfig) : ?>
                                    <option value="<?php echo esc_attr((string) $alias); ?>" data-appointment-type="<?php echo esc_attr((string) ($aliasConfig['appointment_type_id'] ?? '')); ?>" data-template="<?php echo esc_attr((string) ($aliasConfig['patient_form_template_id'] ?? '')); ?>"><?php echo esc_html((string) ($aliasConfig['name'] ?? $alias)); ?> (<?php echo esc_html((string) $alias); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        <?php else : ?>
                            <p>No aliases have been created yet. <a href="<?php echo esc_url(self::url(['booking_section' => 'aliases', 'action' => 'alias-new'])); ?>">Create an alias</a> first.</p>
                        <?php endif; ?>
                        <p class="description">An alias is only a server-side shortcut for booking type and appointment type. It cannot carry payment or gateway settings.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="generic-booking-mode">Booking type</label></th>
                    <td>
                        <select id="generic-booking-mode" data-generic-booking-mode style="min-width:420px">
                            <option value="guest">Guest booking</option>
                            <option value="patient">Logged-in patient booking</option>
                        </select>
                        <p class="description">Guest forms collect patient details. Logged-in forms use the authenticated patient account.</p>
                    </td>
                </tr>
                <tr data-generic-booking-direct-row>
                    <th><label for="generic-booking-appointment-type">Appointment type</label></th>
                    <td>
                        <select id="generic-booking-appointment-type" data-generic-booking-appointment style="min-width:420px">
                            <option value="">Select an appointment type</option>
                            <?php foreach ($appointmentTypes as $appointmentType) : $typeId = (string) $appointmentType->getId(); ?>
                                <option value="<?php echo esc_attr($typeId); ?>"><?php echo esc_html((string) $appointmentType->getName()); ?> (<?php echo esc_html($typeId); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="generic-booking-template">Patient form template</label></th>
                    <td>
                        <select id="generic-booking-template" data-generic-booking-template style="min-width:420px">
                            <option value="">Select a patient form template</option>
                            <?php foreach ($templates as $template) : $templateId = (string) $template->getId(); ?>
                                <option value="<?php echo esc_attr($templateId); ?>"><?php echo esc_html((string) $template->getName()); ?> (<?php echo esc_html($templateId); ?>)<?php echo $template->isArchived() ? ' — Archived' : ''; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="generic-booking-scheduling">Scheduling</label></th>
                    <td>
                        <select id="generic-booking-scheduling" data-generic-booking-scheduling style="min-width:420px">
                            <option value="manual">Patient chooses practitioner, date, and time</option>
                            <option value="next_available">Use the next available appointment automatically</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="generic-booking-code">Custom code bundle</label></th>
                    <td>
                        <select id="generic-booking-code" data-generic-booking-code style="min-width:420px">
                            <option value="">No custom code</option>
                            <?php foreach ($codeBundles as $codeAlias => $codeBundle) : ?>
                                <option value="<?php echo esc_attr((string) $codeAlias); ?>"><?php echo esc_html((string) ($codeBundle['name'] ?? $codeAlias)); ?> (<?php echo esc_html((string) $codeAlias); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">The bundle can be selected with <code>?variant=...</code>. If the selected <code>booking_alias</code> has the same name as the bundle, this parameter is implied and can be omitted.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="generic-booking-multistep">Form layout</label></th>
                    <td>
                        <select id="generic-booking-multistep" data-generic-booking-multistep style="min-width:420px">
                            <option value="yes">Multistep</option>
                            <option value="no">Single page</option>
                        </select>
                    </td>
                </tr>
                <tr data-generic-booking-patient-review-row hidden>
                    <th>Patient details review</th>
                    <td>
                        <label><input type="checkbox" data-generic-booking-patient-review> Show patient personal details review when logged in</label>
                        <p class="description">Available for the logged-in patient shortcode. The review is read-only until the patient opens the full-screen editor.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="generic-booking-payment">Payment</label></th>
                    <td>
                        <select id="generic-booking-payment" data-generic-booking-payment style="min-width:420px">
                            <option value="yes">Paid booking</option>
                            <option value="no">No payment</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="generic-booking-gateway">Payment gateway</label></th>
                    <td>
                        <select id="generic-booking-gateway" data-generic-booking-gateway style="min-width:420px">
                            <option value="stripe">Stripe</option>
                            <option value="tyrohealth">Tyro Health</option>
                        </select>
                        <p class="description">Payment and gateway are stored in the shortcode attributes and are never accepted from URL parameters.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="generic-booking-calendar">Calendar</label></th>
                    <td>
                        <select id="generic-booking-calendar" data-generic-booking-calendar style="min-width:420px">
                            <option value="yes">Show calendar and time selection</option>
                            <option value="no">Hide calendar and use next available</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="generic-booking-practitioner">Practitioner</label></th>
                    <td>
                        <select id="generic-booking-practitioner" data-generic-booking-practitioner style="min-width:420px">
                            <option value="yes">Patient selects practitioner</option>
                            <option value="no">Use automatic practitioner selection</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p><strong>Attribute-based shortcode</strong><br>
                <input type="text" class="large-text code" data-generic-booking-shortcode readonly>
                <button type="button" class="button" data-generic-booking-copy="shortcode" style="margin-top:8px">Copy shortcode</button>
            </p>
            <p><strong>URL-driven shortcode</strong><br>
                <input type="text" class="large-text code" data-generic-booking-url-shortcode readonly>
                <button type="button" class="button" data-generic-booking-copy="url-shortcode" style="margin-top:8px">Copy shortcode</button>
            </p>
            <p><strong>Page URL parameters</strong><br>
                <code data-generic-booking-query>?appointment_type_id=APPOINTMENT_TYPE_ID&amp;patient_form_template_id=FORM_TEMPLATE_ID</code>
            </p>
            <p class="description">Place the URL-driven shortcode on the page, then append the parameters to that page URL. Payment and gateway are intentionally not URL parameters.</p>
        </div>
        <script>
            (function () {
                const root = document.querySelector('[data-generic-booking-builder]');
                if (!root) return;
                const appointment = root.querySelector('[data-generic-booking-appointment]');
                const template = root.querySelector('[data-generic-booking-template]');
                const scheduling = root.querySelector('[data-generic-booking-scheduling]');
                const code = root.querySelector('[data-generic-booking-code]');
                const multistep = root.querySelector('[data-generic-booking-multistep]');
                const patientReview = root.querySelector('[data-generic-booking-patient-review]');
                const patientReviewRow = root.querySelector('[data-generic-booking-patient-review-row]');
                const payment = root.querySelector('[data-generic-booking-payment]');
                const gateway = root.querySelector('[data-generic-booking-gateway]');
                const calendar = root.querySelector('[data-generic-booking-calendar]');
                const practitioner = root.querySelector('[data-generic-booking-practitioner]');
                const mode = root.querySelector('[data-generic-booking-mode]');
                const source = root.querySelector('[data-generic-booking-source]');
                const alias = root.querySelector('[data-generic-booking-alias]');
                const aliasRow = root.querySelector('[data-generic-booking-alias-row]');
                const directRow = root.querySelector('[data-generic-booking-direct-row]');
                const urlShortcode = root.querySelector('[data-generic-booking-url-shortcode]');
                const shortcode = root.querySelector('[data-generic-booking-shortcode]');
                const query = root.querySelector('[data-generic-booking-query]');

                function refresh() {
                    const selectedAlias = alias && alias.value ? alias.options[alias.selectedIndex] : null;
                    const usingAlias = source.checked;
                    const appointmentId = usingAlias ? ((selectedAlias && selectedAlias.dataset.appointmentType) || 'APPOINTMENT_TYPE_ID') : (appointment.value || 'APPOINTMENT_TYPE_ID');
                    const templateId = usingAlias ? ((selectedAlias && selectedAlias.dataset.template) || 'FORM_TEMPLATE_ID') : (template.value || 'FORM_TEMPLATE_ID');
                    const schedulingMode = scheduling.value || 'manual';
                    const multistepMode = multistep.value || 'yes';
                    const gatewayName = payment.value === 'no' ? 'none' : (gateway.value || 'stripe');
                    const calendarMode = calendar.value || 'yes';
                    const practitionerMode = practitioner.value || 'yes';
                    const codeAlias = code.value || '';
                    const bookingMode = mode.value;
                    const patientReviewMode = bookingMode === 'patient' && patientReview.checked ? 'yes' : 'no';
                    const shortcodeName = bookingMode === 'patient' ? 'cliniko_patient_booking_form' : 'cliniko_guest_booking_form';
                    const aliasPart = usingAlias ? ' booking_alias="' + ((alias && alias.value) || 'ALIAS') + '"' : '';
                    const directPart = usingAlias ? '' : ' appointment_type_id="' + appointmentId + '" patient_form_template_id="' + templateId + '"';
                    const codePart = codeAlias ? ' code_alias="' + codeAlias + '"' : '';
                    const optionsPart = ' scheduling_mode="' + schedulingMode + '" multistep="' + multistepMode + '" gateway="' + gatewayName + '" show_calendar="' + calendarMode + '" show_practitioner="' + practitionerMode + '" show_patient_details_review="' + patientReviewMode + '"' + codePart;
                    shortcode.value = '[' + shortcodeName + aliasPart + directPart + optionsPart + ']';
                    urlShortcode.value = '[' + shortcodeName + (usingAlias ? ' booking_alias="ALIAS"' : '') + ' scheduling_mode="' + schedulingMode + '" multistep="' + multistepMode + '" gateway="' + gatewayName + '" show_calendar="' + calendarMode + '" show_practitioner="' + practitionerMode + '" show_patient_details_review="' + patientReviewMode + '"]';
                    const selectedBookingAlias = alias && alias.value ? alias.value : '';
                    const impliedCodeAlias = usingAlias && selectedBookingAlias !== '' && selectedBookingAlias === codeAlias;
                    const codeQuery = codeAlias !== '' && !impliedCodeAlias ? '&variant=' + codeAlias : '';
                    query.textContent = (usingAlias ? '?booking_alias=' + (selectedBookingAlias || 'ALIAS') : '?appointment_type_id=' + appointmentId + '&patient_form_template_id=' + templateId) + codeQuery;
                    aliasRow.hidden = !usingAlias;
                    directRow.hidden = usingAlias;
                    appointment.disabled = usingAlias;
                    template.disabled = usingAlias;
                    patientReviewRow.hidden = bookingMode !== 'patient';
                }

                root.querySelectorAll('[data-generic-booking-copy]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        const target = button.dataset.genericBookingCopy === 'shortcode' ? shortcode : root.querySelector('[data-generic-booking-url-shortcode]');
                        if (!target || !target.value || !navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                            if (target) { target.focus(); target.select(); }
                            return;
                        }
                        navigator.clipboard.writeText(target.value);
                    });
                });
                appointment.addEventListener('change', refresh);
                template.addEventListener('change', refresh);
                scheduling.addEventListener('change', refresh);
                code.addEventListener('change', refresh);
                multistep.addEventListener('change', refresh);
                patientReview.addEventListener('change', refresh);
                payment.addEventListener('change', refresh);
                gateway.addEventListener('change', refresh);
                calendar.addEventListener('change', refresh);
                practitioner.addEventListener('change', refresh);
                mode.addEventListener('change', refresh);
                source.addEventListener('change', refresh);
                if (alias) alias.addEventListener('change', refresh);
                refresh();
            }());
        </script>
        <?php
    }

    /** @return array<string,array<string,mixed>> */
    private static function aliases(): array
    {
        $aliases = get_option(self::ALIAS_OPTION_KEY, []);
        return is_array($aliases) ? $aliases : [];
    }

    private static function aliasKey(string $value): string
    {
        return sanitize_key(trim($value));
    }

    /** @param array<string,mixed> $attributes @return array{alias:string,config:array<string,mixed>}|null */
    private static function resolveAlias(array $attributes): ?array
    {
        $raw = (string) ($attributes['booking_alias'] ?? $attributes['alias'] ?? '');
        $alias = self::aliasKey($raw);
        $aliases = self::aliases();

        // URL-driven shortcode examples use a placeholder attribute. A real
        // booking_alias in the page URL must be allowed to replace it.
        if ($alias === '' || $alias === 'alias' || !isset($aliases[$alias])) {
            $urlAlias = self::aliasKey((string) ($_GET['booking_alias'] ?? $_GET['alias'] ?? ''));
            if ($urlAlias !== '' && isset($aliases[$urlAlias])) {
                $alias = $urlAlias;
            }
        }

        if ($alias === '') {
            return null;
        }

        $config = $aliases[$alias] ?? null;
        return is_array($config) ? ['alias' => $alias, 'config' => $config] : null;
    }

    public static function saveAlias(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('save_booking_alias');
        $alias = self::aliasKey((string) ($_POST['alias'] ?? ''));
        $appointmentTypeId = self::numericId((string) ($_POST['appointment_type_id'] ?? ''));
        $templateId = self::numericId((string) ($_POST['patient_form_template_id'] ?? ''));
        if ($alias === '' || $appointmentTypeId === '' || $templateId === '') {
            wp_die('Alias, appointment type, and patient form template are required.');
        }
        $aliases = self::aliases();
        $aliases[$alias] = [
            'name' => $alias,
            'appointment_type_id' => $appointmentTypeId,
            'patient_form_template_id' => $templateId,
        ];
        update_option(self::ALIAS_OPTION_KEY, $aliases, false);
        wp_safe_redirect(self::url(['booking_section' => 'aliases', 'alias_saved' => 1]));
        exit;
    }

    public static function deleteAlias(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $alias = self::aliasKey((string) ($_GET['alias'] ?? ''));
        check_admin_referer('delete_booking_alias_' . $alias);
        $aliases = self::aliases();
        unset($aliases[$alias]);
        update_option(self::ALIAS_OPTION_KEY, $aliases, false);
        wp_safe_redirect(self::url(['booking_section' => 'aliases', 'alias_deleted' => 1]));
        exit;
    }

    private static function renderAliasManager(bool $showForm = false): void
    {
        self::enqueueBookingBuilderStyle();
        $appointmentTypes = [];
        $templates = [];
        try {
            $client = cliniko_client(false);
            $appointmentTypes = AppointmentType::all($client, true);
            $templates = PatientFormTemplate::all($client, true);
        } catch (\Throwable $exception) {
            error_log('Cliniko booking alias options failed: ' . $exception->getMessage());
        }
        $aliases = self::aliases();
        ?>
        <section id="cliniko-booking-aliases" class="postbox" style="padding:20px;margin-top:20px;max-width:1100px">
            <h2 style="margin-top:0">Booking aliases</h2>
            <p>Save a short alias for an appointment type and patient form template. Choose guest or logged-in patient when using it in the Dynamic Booking Shortcode builder.</p>
            <?php if (isset($_GET['alias_saved'])) : ?><div class="notice notice-success inline"><p>Booking alias saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['alias_deleted'])) : ?><div class="notice notice-success inline"><p>Booking alias deleted.</p></div><?php endif; ?>
            <?php if ($aliases !== []) : ?>
                <table class="widefat striped" style="margin-bottom:20px">
                    <thead><tr><th>Alias</th><th>Appointment</th><th>Patient form template</th><th>Generic URL parameter</th><th>Actions</th></tr></thead>
                    <tbody><?php foreach ($aliases as $alias => $config) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($alias); ?></strong><br><code>?booking_alias=<?php echo esc_attr($alias); ?></code></td>
                            <td><?php echo esc_html((string) ($config['appointment_type_id'] ?? '')); ?></td>
                            <td><?php echo esc_html((string) ($config['patient_form_template_id'] ?? '')); ?></td>
                            <td><code>booking_alias=<?php echo esc_attr($alias); ?></code></td>
                            <td><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=wp_cliniko_booking_alias_delete&alias=' . rawurlencode($alias)), 'delete_booking_alias_' . $alias)); ?>" onclick="return confirm('Delete this alias?');">Delete</a></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            <?php endif; ?>
            <?php if ($showForm) : ?><h3>Create alias</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wp_cliniko_booking_alias_save">
                <?php wp_nonce_field('save_booking_alias'); ?>
                <table class="form-table">
                    <tr><th><label for="booking-alias">Alias</label></th><td><input class="regular-text" required id="booking-alias" name="alias" placeholder="initial-consult"></td></tr>
                    <tr><th><label for="booking-alias-appointment">Appointment type</label></th><td><select class="regular-text" required id="booking-alias-appointment" name="appointment_type_id"><option value="">Select an appointment type</option><?php foreach ($appointmentTypes as $item) : ?><option value="<?php echo esc_attr((string) $item->getId()); ?>"><?php echo esc_html((string) $item->getName()); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th><label for="booking-alias-template">Patient form template</label></th><td><select class="regular-text" required id="booking-alias-template" name="patient_form_template_id"><option value="">Select a patient form template</option><?php foreach ($templates as $item) : ?><option value="<?php echo esc_attr((string) $item->getId()); ?>"><?php echo esc_html((string) $item->getName()); ?></option><?php endforeach; ?></select></td></tr>
                </table>
                <p><button class="button button-primary" type="submit">Save alias</button></p>
            </form><?php endif; ?>
        </section>
        <?php
    }

    private static function renderEditor(string $id): void
    {
        $form = self::forms()[$id] ?? [
            'name' => '',
            'booking_mode' => 'guest',
            'appointment_type_id' => '',
            'patient_form_template_id' => '',
            'gateway' => 'stripe',
            'scheduling_mode' => 'manual',
            'show_calendar' => 'yes',
            'show_practitioner' => 'yes',
            'multistep' => 'yes',
            'show_patient_details_review' => 'no',
            'success_action' => 'message',
            'success_message' => 'Your appointment has been confirmed.',
            'success_redirect_url' => '',
            'failure_action' => 'message',
            'failure_message' => 'We could not complete your booking. Please try again.',
            'failure_redirect_url' => '',
            'custom_css' => '',
            'custom_js' => '',
            'input_rules' => [],
        ];
        if ($id === '' && sanitize_key((string) ($_GET['booking_mode'] ?? 'guest')) === 'patient') {
            $form['booking_mode'] = 'patient';
        }
        $appointmentTypes = [];
        $templates = [];
        $loadError = false;
        try {
            $client = cliniko_client(false);
            $appointmentTypes = AppointmentType::all($client, true);
            $templates = PatientFormTemplate::all($client, true);
        } catch (\Throwable $exception) {
            $loadError = true;
            error_log('Cliniko patient booking form options failed: ' . $exception->getMessage());
        }
        $selectedAppointmentType = (string) ($form['appointment_type_id'] ?? '');
        $selectedTemplate = (string) ($form['patient_form_template_id'] ?? '');
        $schedulingMode = (string) ($form['scheduling_mode'] ?? 'manual');
        if (!in_array($schedulingMode, ['manual', 'next_available'], true)) {
            $schedulingMode = 'manual';
        }
        $successAction = (string) ($form['success_action'] ?? 'message');
        if (!in_array($successAction, ['message', 'redirect'], true)) {
            $successAction = 'message';
        }
        $bookingMode = (string) ($form['booking_mode'] ?? 'guest') === 'patient' ? 'patient' : 'guest';
        $selectedTemplateModel = null;
        foreach ($templates as $templateOption) {
            if ((string) $templateOption->getId() === $selectedTemplate) {
                $selectedTemplateModel = $templateOption;
                break;
            }
        }
        $inputRules = self::normaliseBookingInputRules(
            $selectedTemplateModel,
            is_array($form['input_rules'] ?? null) ? $form['input_rules'] : [],
            $bookingMode
        );
        ShortcodeFormInputRules::enqueueBuilderAssets();
        $bookingEditorStyle = __DIR__ . '/../assets/patient-booking-form-builder.css';
        wp_enqueue_style(
            'cliniko-patient-booking-form-builder',
            plugins_url('../assets/patient-booking-form-builder.css', __FILE__),
            ['cliniko-shortcode-form-rule-builder'],
            file_exists($bookingEditorStyle) ? (string) filemtime($bookingEditorStyle) : null
        );
        $bookingEditorScript = __DIR__ . '/../assets/patient-booking-form-builder.js';
        wp_enqueue_script(
            'cliniko-patient-booking-form-builder',
            plugins_url('../assets/patient-booking-form-builder.js', __FILE__),
            ['cliniko-shortcode-form-rule-builder'],
            file_exists($bookingEditorScript) ? (string) filemtime($bookingEditorScript) : null,
            true
        );
        ?>
        <div class="wrap cliniko-template-builder-page cliniko-template-builder-editor">
            <h1><?php echo $id !== '' ? 'Edit Booking Form' : 'Add Booking Form'; ?></h1>
            <p class="cliniko-booking-builder__intro">Work through the numbered sections, then copy the generated shortcode after saving.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cliniko-booking-builder">
                <input type="hidden" name="action" value="wp_cliniko_patient_booking_form_save">
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">
                <?php wp_nonce_field('save_patient_booking_form'); ?>
                <table class="form-table cliniko-booking-builder__table">
                    <tr class="cliniko-booking-builder__section"><th colspan="2"><span>1</span><div><strong>Booking essentials</strong><small>Choose who can book and which Cliniko records power the form.</small></div></th></tr>
                    <tr><th><label for="booking-form-name">Name</label></th><td><input class="regular-text" required id="booking-form-name" name="name" value="<?php echo esc_attr((string) ($form['name'] ?? '')); ?>"></td></tr>
                    <tr><th><label for="booking-form-mode">Booking mode</label></th><td><select class="regular-text" required id="booking-form-mode" name="booking_mode"><option value="guest" <?php selected((string) ($form['booking_mode'] ?? 'guest'), 'guest'); ?>>Guest booking</option><option value="patient" <?php selected((string) ($form['booking_mode'] ?? 'guest'), 'patient'); ?>>Logged-in patient booking</option></select><p class="description">Guest booking collects patient details in the form. Logged-in patient booking is intended for authenticated users.</p></td></tr>
                    <tr><th><label for="booking-form-appointment">Appointment type</label></th><td><select class="regular-text" required id="booking-form-appointment" name="appointment_type_id"><option value="">Select an appointment type</option><?php foreach ($appointmentTypes as $appointmentType) : $typeId = (string) $appointmentType->getId(); ?><option value="<?php echo esc_attr($typeId); ?>" <?php selected($selectedAppointmentType, $typeId); ?>><?php echo esc_html((string) $appointmentType->getName()); ?> (<?php echo esc_html($typeId); ?>)</option><?php endforeach; ?><?php if ($selectedAppointmentType !== '' && !array_filter($appointmentTypes, static fn($item): bool => (string) $item->getId() === $selectedAppointmentType)) : ?><option value="<?php echo esc_attr($selectedAppointmentType); ?>" selected>Previously selected appointment type (<?php echo esc_html($selectedAppointmentType); ?>)</option><?php endif; ?></select></td></tr>
                    <tr><th><label for="booking-form-template">Patient form template</label></th><td><select class="regular-text" required id="booking-form-template" name="patient_form_template_id"><option value="">Select a patient form template</option><?php foreach ($templates as $template) : $templateId = (string) $template->getId(); ?><option value="<?php echo esc_attr($templateId); ?>" <?php selected($selectedTemplate, $templateId); ?>><?php echo esc_html((string) $template->getName()); ?> (<?php echo esc_html($templateId); ?>)<?php echo $template->isArchived() ? ' — Archived' : ''; ?></option><?php endforeach; ?><?php if ($selectedTemplate !== '' && !array_filter($templates, static fn($item): bool => (string) $item->getId() === $selectedTemplate)) : ?><option value="<?php echo esc_attr($selectedTemplate); ?>" selected>Previously selected template (<?php echo esc_html($selectedTemplate); ?>)</option><?php endif; ?></select></td></tr>
                    <tr><th><label for="booking-form-scheduling">Appointment scheduling</label></th><td><select class="regular-text" required id="booking-form-scheduling" name="scheduling_mode"><option value="manual" <?php selected($schedulingMode, 'manual'); ?>>Patient selects practitioner, date, and time</option><option value="next_available" <?php selected($schedulingMode, 'next_available'); ?>>Automatically book the next available time</option></select><p class="description">Automatic mode searches all practitioners linked to the appointment type and selects the earliest available slot.</p></td></tr>
                    <tr><th><label for="booking-form-calendar">Calendar <span class="cliniko-info" tabindex="0" aria-label="Calendar help" data-tooltip="When hidden, the form selects the next available appointment automatically.">i</span></label></th><td><select class="regular-text" id="booking-form-calendar" name="show_calendar"><option value="yes" <?php selected((string) ($form['show_calendar'] ?? 'yes'), 'yes'); ?>>Show calendar and time selection</option><option value="no" <?php selected((string) ($form['show_calendar'] ?? 'yes'), 'no'); ?>>Hide calendar and use next available</option></select></td></tr>
                    <tr><th><label for="booking-form-practitioner">Practitioner selection</label></th><td><select class="regular-text" id="booking-form-practitioner" name="show_practitioner"><option value="yes" <?php selected((string) ($form['show_practitioner'] ?? 'yes'), 'yes'); ?>>Patient selects practitioner</option><option value="no" <?php selected((string) ($form['show_practitioner'] ?? 'yes'), 'no'); ?>>Use automatic practitioner selection</option></select></td></tr>
                    <tr><th><label for="booking-form-multistep">Form layout</label></th><td><select class="regular-text" id="booking-form-multistep" name="multistep"><option value="yes" <?php selected((string) ($form['multistep'] ?? 'yes'), 'yes'); ?>>Multistep</option><option value="no" <?php selected((string) ($form['multistep'] ?? 'yes'), 'no'); ?>>Single page</option></select></td></tr>
                    <tr><th>Patient details review</th><td><label for="booking-form-patient-details-review"><input id="booking-form-patient-details-review" type="checkbox" name="show_patient_details_review" value="yes" <?php checked((string) ($form['show_patient_details_review'] ?? 'no'), 'yes'); ?>> Show patient personal details review when logged in</label><p class="description">For logged-in patient bookings only. Shows the linked Cliniko details as read-only values and provides an Edit button that opens a full-screen drawer.</p></td></tr>
                    <tr><th><label for="booking-form-gateway">Payment gateway <span class="cliniko-info" tabindex="0" aria-label="Payment gateway help" data-tooltip="No payment is accepted only for zero-priced Cliniko appointment types.">i</span></label></th><td><select id="booking-form-gateway" name="gateway"><option value="stripe" <?php selected((string) ($form['gateway'] ?? 'stripe'), 'stripe'); ?>>Stripe</option><option value="tyrohealth" <?php selected((string) ($form['gateway'] ?? 'stripe'), 'tyrohealth'); ?>>Tyro Health</option><option value="none" <?php selected((string) ($form['gateway'] ?? 'stripe'), 'none'); ?>>No payment</option></select><p class="description">No payment is valid only when the Cliniko appointment type has a zero price.</p></td></tr>
                    <tr><th><label for="booking-form-success-action">After successful booking</label></th><td><select class="regular-text" id="booking-form-success-action" name="success_action"><option value="message" <?php selected($successAction, 'message'); ?>>Show success message</option><option value="redirect" <?php selected($successAction, 'redirect'); ?>>Redirect to URL</option></select></td></tr>
                    <tr><th><label for="booking-form-success">Success message</label></th><td><textarea class="large-text" id="booking-form-success" name="success_message" rows="3"><?php echo esc_textarea((string) ($form['success_message'] ?? '')); ?></textarea></td></tr>
                    <tr><th><label for="booking-form-success-redirect">Success redirect URL</label></th><td><input class="large-text" type="url" id="booking-form-success-redirect" name="success_redirect_url" value="<?php echo esc_attr((string) ($form['success_redirect_url'] ?? '')); ?>"><p class="description">Used when “Redirect to URL” is selected.</p></td></tr>
                    <tr><th><label for="booking-form-failure-action">After failed booking</label></th><td><select class="regular-text" id="booking-form-failure-action" name="failure_action"><option value="message" <?php selected((string) ($form['failure_action'] ?? 'message'), 'message'); ?>>Show failure message</option><option value="redirect" <?php selected((string) ($form['failure_action'] ?? 'message'), 'redirect'); ?>>Redirect to URL</option></select></td></tr>
                    <tr><th><label for="booking-form-failure-message">Failure message</label></th><td><textarea class="large-text" id="booking-form-failure-message" name="failure_message" rows="3"><?php echo esc_textarea((string) ($form['failure_message'] ?? 'We could not complete your booking. Please try again.')); ?></textarea></td></tr>
                    <tr><th><label for="booking-form-failure-redirect">Failure redirect URL</label></th><td><input class="large-text" type="url" id="booking-form-failure-redirect" name="failure_redirect_url" value="<?php echo esc_attr((string) ($form['failure_redirect_url'] ?? '')); ?>"></td></tr>
                    <tr><th>Input behaviour</th><td><?php self::renderBookingInputRuleEditor($selectedTemplateModel, $inputRules, $bookingMode); ?></td></tr>
                    <tr><th><label for="booking-form-custom-css">Custom CSS</label></th><td><textarea class="large-text code" id="booking-form-custom-css" name="custom_css" rows="8" placeholder="Scope styles to #cliniko-guest-booking-<?php echo esc_attr($id !== '' ? $id : 'FORM_ID'); ?>"><?php echo esc_textarea((string) ($form['custom_css'] ?? '')); ?></textarea><p class="description">Injected only when this guest shortcode is rendered. Scope selectors to the form wrapper.</p></td></tr>
                    <tr><th><label for="booking-form-custom-js">Custom JavaScript</label></th><td><textarea class="large-text code" id="booking-form-custom-js" name="custom_js" rows="8" placeholder="document.querySelector('#cliniko-guest-booking-FORM_ID')"><?php echo esc_textarea((string) ($form['custom_js'] ?? '')); ?></textarea><p class="description">Injected only when this guest shortcode is rendered. The form is available through its unique wrapper ID.</p></td></tr>
                </table>
                <?php if ($loadError) : ?><div class="notice notice-error inline"><p>Cliniko options could not be loaded. Check the API credentials and reload this editor.</p></div><?php endif; ?>
                <p><button class="button button-primary" type="submit">Save form</button></p>
            </form>
            <?php if ($id !== '') : ?><p><strong>Shortcode:</strong> <code>[<?php echo esc_html($bookingMode === 'patient' ? self::SHORTCODE : self::GUEST_SHORTCODE); ?> id="<?php echo esc_attr($id); ?>"]</code></p><?php endif; ?>
        </div>
        <?php
    }

    private static function enqueueBookingBuilderStyle(): void
    {
        $style = __DIR__ . '/../assets/patient-booking-form-builder.css';
        wp_enqueue_style(
            'cliniko-patient-booking-form-builder',
            plugins_url('../assets/patient-booking-form-builder.css', __FILE__),
            [],
            file_exists($style) ? (string) filemtime($style) : null
        );
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        check_admin_referer('save_patient_booking_form');
        $id = sanitize_key((string) ($_POST['id'] ?? ''));
        if ($id === '') {
            $id = 'booking_' . wp_generate_uuid4();
        }
        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        $appointmentTypeId = preg_replace('/[^0-9]/', '', (string) ($_POST['appointment_type_id'] ?? ''));
        $templateId = preg_replace('/[^0-9]/', '', (string) ($_POST['patient_form_template_id'] ?? ''));
        if ($name === '' || $appointmentTypeId === '' || $templateId === '') {
            wp_die('Name, appointment type ID, and patient form template ID are required.');
        }
        $gateway = strtolower(sanitize_key((string) ($_POST['gateway'] ?? 'stripe')));
        if (!in_array($gateway, ['stripe', 'tyrohealth', 'none'], true)) {
            $gateway = 'stripe';
        }
        $bookingMode = ($_POST['booking_mode'] ?? 'guest') === 'patient' ? 'patient' : 'guest';
        $template = null;
        try {
            $template = PatientFormTemplate::find($templateId, cliniko_client(true), true);
        } catch (\Throwable $exception) {
            error_log('Cliniko booking input-rule template load failed: ' . $exception->getMessage());
        }
        $rawInputRules = isset($_POST['input_rules']) && is_array($_POST['input_rules'])
            ? wp_unslash($_POST['input_rules'])
            : [];
        $forms = self::forms();
        $forms[$id] = [
            'name' => $name,
            'booking_mode' => $bookingMode,
            'appointment_type_id' => $appointmentTypeId,
            'patient_form_template_id' => $templateId,
            'gateway' => $gateway,
            'scheduling_mode' => in_array($_POST['scheduling_mode'] ?? '', ['manual', 'next_available'], true) ? sanitize_key((string) $_POST['scheduling_mode']) : 'manual',
            'show_calendar' => ($_POST['show_calendar'] ?? 'yes') === 'no' ? 'no' : 'yes',
            'show_practitioner' => ($_POST['show_practitioner'] ?? 'yes') === 'no' ? 'no' : 'yes',
            'multistep' => ($_POST['multistep'] ?? 'yes') === 'no' ? 'no' : 'yes',
            'show_patient_details_review' => isset($_POST['show_patient_details_review']) ? 'yes' : 'no',
            'success_action' => in_array($_POST['success_action'] ?? '', ['message', 'redirect'], true) ? sanitize_key((string) $_POST['success_action']) : 'message',
            'success_message' => sanitize_textarea_field((string) ($_POST['success_message'] ?? 'Your appointment has been confirmed.')),
            'success_redirect_url' => esc_url_raw((string) ($_POST['success_redirect_url'] ?? '')),
            'failure_action' => in_array($_POST['failure_action'] ?? '', ['message', 'redirect'], true) ? sanitize_key((string) $_POST['failure_action']) : 'message',
            'failure_message' => sanitize_textarea_field((string) ($_POST['failure_message'] ?? 'We could not complete your booking. Please try again.')),
            'failure_redirect_url' => esc_url_raw((string) ($_POST['failure_redirect_url'] ?? '')),
            'custom_css' => trim((string) wp_unslash($_POST['custom_css'] ?? '')),
            'custom_js' => trim((string) wp_unslash($_POST['custom_js'] ?? '')),
            'input_rules' => self::normaliseBookingInputRules($template, $rawInputRules, $bookingMode),
        ];
        update_option(self::OPTION_KEY, $forms, false);
        wp_safe_redirect(self::url(['saved' => 1, 'booking_section' => $forms[$id]['booking_mode']]));
        exit;
    }

    public static function delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $id = sanitize_key((string) ($_GET['id'] ?? ''));
        check_admin_referer('delete_patient_booking_form_' . $id);
        $forms = self::forms();
        $bookingSection = (($forms[$id]['booking_mode'] ?? 'guest') === 'patient') ? 'patient' : 'guest';
        unset($forms[$id]);
        update_option(self::OPTION_KEY, $forms, false);
        wp_safe_redirect(self::url(['deleted' => 1, 'booking_section' => $bookingSection]));
        exit;
    }

    /** @param array<string,mixed> $attributes */
    public static function renewalShortcode(array $attributes): string
    {
        if (!is_user_logged_in()) {
            return '<p class="cliniko-patient-booking-form__message is-error">Please log in to renew an appointment.</p>';
        }

        $attributes = shortcode_atts([
            'id' => '',
            'appointment_id' => '',
            'scheduling_mode' => '',
            'show_calendar' => '',
            'show_practitioner' => '',
        ], $attributes, self::RENEWAL_SHORTCODE);
        $moduleId = sanitize_key((string) ($attributes['id'] ?? ''));
        $appointmentId = (string) ($attributes['appointment_id'] ?? '');
        if ($appointmentId === '') {
            $appointmentId = (string) ($_GET['appointment_id'] ?? '');
        }
        $appointmentId = preg_replace('/[^0-9]/', '', $appointmentId) ?: '';
        if ($appointmentId === '') {
            return '<p class="cliniko-patient-booking-form__message is-error">An appointment ID is required to renew this appointment.</p>';
        }

        try {
            $patient = Auth::patientData();
            $patientId = is_array($patient) ? trim((string) ($patient['id'] ?? '')) : '';
            if ($patientId === '') {
                return '<p class="cliniko-patient-booking-form__message is-error">A verified Cliniko patient account is required.</p>';
            }

            $context = (new PatientBookingRenewalService())->getAuthorizedPrefill(
                $appointmentId,
                $patientId,
                cliniko_client(false)
            );
            self::$renewalPrefillContent = $context['content'];
            self::$renewalAppointmentId = $appointmentId;
            $form = $moduleId !== '' ? (self::forms()[$moduleId] ?? null) : null;
            $renewalAttributes = [
                'id' => $moduleId,
                'appointment_type_id' => $context['appointment_type_id'],
                'patient_form_template_id' => $context['patient_form_template_id'],
                'scheduling_mode' => (string) ($attributes['scheduling_mode'] ?? ''),
                'show_calendar' => (string) ($attributes['show_calendar'] ?? ''),
                'show_practitioner' => (string) ($attributes['show_practitioner'] ?? ''),
            ];
            if ($moduleId === '' && $context['appointment_type_id'] === '') {
                throw new \RuntimeException('The previous appointment has no appointment type.');
            }
            $booking = Booking::find($appointmentId, cliniko_client(false));
            self::$renewalAppointmentSummary = [
                'appointment_type' => trim((string) ($booking ? $booking->getAppointmentTypeName() : '')),
                'practitioner' => trim((string) ($booking ? $booking->getPractitionerName() : '')),
                'starts_at' => trim((string) ($booking ? $booking->getStartsAt() : '')),
            ];
            return self::shortcode($renewalAttributes);
        } catch (\Throwable $exception) {
            error_log('Cliniko patient booking renewal failed: ' . $exception->getMessage());
            return '<p class="cliniko-patient-booking-form__message is-error">This appointment could not be loaded for renewal.</p>';
        } finally {
            self::$renewalPrefillContent = null;
            self::$renewalAppointmentId = '';
            self::$renewalAppointmentSummary = null;
        }
    }

    /** @param array<string,mixed> $attributes */
    public static function shortcode(array $attributes): string
    {
        $resolvedAlias = self::resolveAlias($attributes);
        $id = sanitize_key((string) ($attributes['id'] ?? ''));
        $configured = $id !== '' ? (self::forms()[$id] ?? null) : null;
        if (is_array($configured) && (string) ($configured['booking_mode'] ?? 'guest') === 'guest') {
            return self::renderBookingShortcode($attributes, 'guest');
        }
        return self::renderBookingShortcode($attributes, 'patient');
    }

    /** @param array<string,mixed> $attributes */
    public static function guestShortcode(array $attributes): string
    {
        return self::renderBookingShortcode($attributes, 'guest');
    }

    /** @param array<string,mixed> $attributes */
    private static function renderClinikoFormShortcode(array $attributes): string
    {
        $attributes = shortcode_atts([
            'id' => '',
            'booking_alias' => '',
            'appointment_type_id' => '',
            'patient_form_template_id' => '',
            'scheduling_mode' => '',
            'gateway' => '',
            'multistep' => '',
            'show_calendar' => '',
            'show_practitioner' => '',
            'show_patient_details_review' => '',
            'success_redirect' => '',
            'failure_redirect' => '',
            'custom_css' => '',
            'custom_js' => '',
            'code_alias' => '',
        ], $attributes, self::GUEST_SHORTCODE);

        $resolvedAlias = self::resolveAlias($attributes);
        $aliasMode = is_array($resolvedAlias) ? (string) ($resolvedAlias['config']['booking_mode'] ?? 'guest') : 'guest';
        if ($aliasMode !== 'guest') {
            return '<p class="cliniko-form-message is-error">This alias is configured for logged-in patients. Use the patient booking shortcode.</p>';
        }
        $id = is_array($resolvedAlias) ? 'alias_' . $resolvedAlias['alias'] : sanitize_key((string) ($attributes['id'] ?? ''));
        $form = is_array($resolvedAlias) ? $resolvedAlias['config'] : ($id !== '' ? (self::forms()[$id] ?? null) : null);
        if ($id === '') {
            $appointmentTypeId = self::numericId((string) ($attributes['appointment_type_id'] ?? ($_GET['appointment_type_id'] ?? '')));
            $templateId = self::numericId((string) ($attributes['patient_form_template_id'] ?? ($_GET['patient_form_template_id'] ?? '')));
            if ($appointmentTypeId === '' || $templateId === '') {
                return '<p class="cliniko-form-message is-error">A valid appointment type and patient form template are required.</p>';
            }
            $id = 'guest_' . substr(hash('sha256', $appointmentTypeId . ':' . $templateId), 0, 16);
            $form = [
                'booking_mode' => 'guest',
                'appointment_type_id' => $appointmentTypeId,
                'patient_form_template_id' => $templateId,
                'gateway' => 'stripe',
                'scheduling_mode' => 'manual',
                'show_calendar' => 'yes',
                'show_practitioner' => 'yes',
                'multistep' => 'yes',
                'show_patient_details_review' => 'no',
                'success_action' => 'message',
                'success_message' => 'Your appointment has been confirmed.',
                'success_redirect_url' => '',
                'failure_action' => 'message',
                'failure_message' => 'We could not complete your booking. Please try again.',
                'failure_redirect_url' => '',
                'custom_css' => '',
                'custom_js' => '',
            ];
        }
        if (!is_array($form)) {
            return current_user_can('manage_options') ? '<p>Booking form not found.</p>' : '';
        }
        if ((string) ($form['booking_mode'] ?? 'guest') === 'patient') {
            return '<p class="cliniko-form-message is-error">This configuration is for logged-in patients. Use its patient shortcode.</p>';
        }

        if (is_array($resolvedAlias)) {
            $templateId = self::numericId((string) ($form['patient_form_template_id'] ?? ''));
            if ($templateId === '') {
                return '<p class="cliniko-form-message is-error">This booking alias is missing its patient form template.</p>';
            }
            $form['patient_form_template_id'] = $templateId;
        }

        foreach (['appointment_type_id', 'patient_form_template_id'] as $key) {
            $override = self::numericId((string) ($attributes[$key] ?? ''));
            if ($override !== '' && !is_array($resolvedAlias)) {
                $form[$key] = $override;
            }
        }
        foreach (['gateway', 'multistep', 'show_calendar', 'show_practitioner'] as $key) {
            $value = sanitize_key((string) ($attributes[$key] ?? ''));
            if ($value !== '') {
                $form[$key] = $value;
            }
        }
        $schedulingMode = (string) ($attributes['scheduling_mode'] ?? '');
        if (in_array($schedulingMode, ['manual', 'next_available'], true)) {
            $form['scheduling_mode'] = $schedulingMode;
        }

        $appointmentTypeId = self::numericId((string) ($form['appointment_type_id'] ?? ''));
        $templateId = self::numericId((string) ($form['patient_form_template_id'] ?? ''));
        $gateway = in_array((string) ($form['gateway'] ?? 'stripe'), ['stripe', 'tyrohealth', 'none'], true)
            ? (string) $form['gateway']
            : 'stripe';
        $isMultistep = (string) ($form['multistep'] ?? 'yes') !== 'no';
        $showCalendar = (string) ($form['show_calendar'] ?? 'yes') !== 'no';
        $nextSlot = null;
        if (!$showCalendar) {
            $nextSlot = self::nextAvailableSlot($appointmentTypeId);
            if ($nextSlot === null) {
                return '<p class="cliniko-form-message is-error">There are no available appointments in the next 90 days.</p>';
            }
        }

        try {
            $template = PatientFormTemplate::find($templateId, cliniko_client(false));
            $sections = $template ? $template->getSections() : [];
            if (!$template || $sections === []) {
                return '<p class="cliniko-form-message is-error">The configured patient form could not be found.</p>';
            }

            $settings = self::clinikoShortcodeSettings($form, $attributes, $id, $appointmentTypeId, $templateId, $gateway, $isMultistep, $showCalendar, $nextSlot);
            $settings['shortcode_sections'] = $sections;
            self::enqueueClinikoShortcodeAssets($settings);

            ob_start();
            $sections_loaded = true;
            require __DIR__ . '/../../Widgets/ClinikoForm/templates/cliniko_multistep_form.phtml';
            if (($settings['enable_payment'] ?? 'no') === 'yes') {
                if ($gateway === 'stripe') {
                    require __DIR__ . '/../../Widgets/ClinikoForm/templates/card_form_real.phtml';
                } else {
                    require __DIR__ . '/../../Widgets/ClinikoForm/templates/card_form_tyrohealth.phtml';
                }
            }
            $markup = (string) ob_get_clean();
            $customCss = trim((string) ($form['custom_css'] ?? ''));
            $customJs = trim((string) ($form['custom_js'] ?? ''));
            if (trim((string) ($attributes['custom_css'] ?? '')) !== '') {
                $customCss = (string) $attributes['custom_css'];
            }
            if (trim((string) ($attributes['custom_js'] ?? '')) !== '') {
                $customJs = (string) $attributes['custom_js'];
            }
            if ($customCss !== '') {
                $markup .= '<style data-cliniko-guest-booking-css="' . esc_attr($id) . '">' . $customCss . '</style>';
            }
            if ($customJs !== '') {
                wp_add_inline_script('cliniko-form-handler', $customJs, 'after');
            }
            $markup = CustomCode::applyToMarkup($markup, $attributes);
            return $markup;
        } catch (\Throwable $exception) {
            error_log('Cliniko guest shortcode render failed: ' . $exception->getMessage());
            return '<p class="cliniko-form-message is-error">The booking form is temporarily unavailable.</p>';
        }
    }

    /** @param array<string,mixed> $form @return array<string,mixed> */
    private static function clinikoShortcodeSettings(
        array $form,
        array $attributes,
        string $id,
        string $appointmentTypeId,
        string $templateId,
        string $gateway,
        bool $isMultistep,
        bool $showCalendar,
        ?array $nextSlot
    ): array {
        $successRedirect = esc_url_raw((string) ($attributes['success_redirect'] ?? ''));
        if ($successRedirect === '') {
            $successRedirect = esc_url_raw((string) ($form['success_redirect_url'] ?? ''));
        }
        if ($successRedirect === '') {
            $successRedirect = ShortcodeCatalog::redirectFor(self::GUEST_SHORTCODE, $id);
        }

        $failureRedirect = esc_url_raw((string) ($attributes['failure_redirect'] ?? ''));
        if ($failureRedirect === '') {
            $failureRedirect = esc_url_raw((string) ($form['failure_redirect_url'] ?? ''));
        }

        $patient = [
            'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '',
            'medicare' => '', 'medicare_reference_number' => '', 'address_1' => '',
            'address_2' => '', 'city' => '', 'state' => '', 'post_code' => '',
            'country' => '', 'date_of_birth' => '', 'appointment_start' => '',
            'appointment_date' => '', 'practitioner_id' => '',
        ];
        $data = [
            'sections' => [],
            'submission_template' => [
                'moduleId' => $appointmentTypeId,
                'patient_form_template_id' => $templateId,
                'patient' => $patient,
                'content' => ['sections' => []],
            ],
            'headless_patient_fields' => [],
            'btn_bg' => '#0073e6',
            'btn_text' => '#ffffff',
            'btn_pad' => '12px 24px',
            'border_radius' => '6px',
            'is_payment_enabled' => $gateway !== 'none',
            'module_id' => $appointmentTypeId,
            'patient_form_template_id' => $templateId,
            'payment_url' => rest_url('v1/payments/charge'),
            'booking_attempt_preflight_url' => rest_url('v2/booking-attempts/preflight'),
            'booking_attempt_charge_stripe_url' => rest_url('v2/booking-attempts/charge-stripe'),
            'booking_attempt_confirm_tyro_url' => rest_url('v2/booking-attempts/confirm-tyro'),
            'booking_attempt_finalize_url' => rest_url('v2/booking-attempts/finalize'),
            'booking_attempt_status_url' => rest_url('v2/booking-attempts/status'),
            'available_times_url' => rest_url('v1/available-times'),
            'next_available_times_url' => rest_url('v1/next-available-times'),
            'practitioners_url' => rest_url('v1/practitioners'),
            'appointment_type_url' => rest_url('v1/appointment-type'),
            'patient_form_template_url' => rest_url('v1/patient-form-template'),
            'appointment_calendar_url' => rest_url('v1/appointment-calendar'),
            'available_times_per_page' => 100,
            'cliniko_embeded_form_sync_patient_form_url' => rest_url('v1/send-patient-form'),
            'cliniko_embeded_host' => 'https://' . Credentials::getEmbedHost(),
            'redirect_url' => $successRedirect !== '' ? $successRedirect : home_url('/'),
            'custom_form_payment' => $gateway === 'none' ? 'stripe' : $gateway,
            'appointment_time_selection' => $showCalendar ? 'calendar' : 'next_available',
            'form_type' => $isMultistep ? 'multi' : 'single',
            'cliniko_embed' => 'custom_form',
            'patient_history_access' => ['enabled' => false],
            'appearance' => [],
        ];

        $settings = array_merge([
            'cliniko_form_template_id' => $templateId,
            'module_id' => $appointmentTypeId,
            'form_type' => $isMultistep ? 'multi' : 'single',
            'appointment_source' => 'custom_form',
            'enable_payment' => $gateway === 'none' ? 'no' : 'yes',
            'custom_form_payment' => $gateway === 'none' ? 'stripe' : $gateway,
            'appointment_time_selection' => $showCalendar ? 'calendar' : 'next_available',
            'shortcode_booking' => true,
            'shortcode_unstyled' => true,
            'shortcode_next_available_slot' => $nextSlot,
            'details_background' => '#ffffff',
            'details_border_color' => '#e2e8f0',
            'details_border_radius' => ['size' => 8],
            'summary_heading_color' => '#111827',
            'summary_text_color' => '#4b5563',
            'summary_price_color' => '#111827',
            'gap_between_columns' => ['size' => 24],
            'button_label' => 'Continue',
            'input_border' => '1px solid #d1d5db',
            'border_radius' => ['size' => 6],
            'font_family' => 'Arial, sans-serif',
            'color_text' => '#111827',
            'color_background' => '#ffffff',
            'color_primary' => '#0073e6',
            'button_text_color' => '#ffffff',
            'button_padding' => '12px 24px',
            'button_font_size' => ['size' => 16],
            'button_css' => '',
            'form_background_color' => '#ffffff',
            'form_text_color' => '#111827',
            'form_font_family' => 'Arial, sans-serif',
            'form_label_color' => '#374151',
            'form_input_border' => '1px solid #d1d5db',
            'form_border_radius' => ['size' => 6],
            'titles_color' => '#111827',
            'accent_color' => '#0073e6',
            'form_button_color' => '#0073e6',
            'form_button_text_color' => '#ffffff',
            'form_button_padding' => ['top' => 12, 'right' => 24, 'bottom' => 12, 'left' => 24, 'unit' => 'px'],
            'form_button_border_radius' => ['size' => 6],
            'form_button_layout' => 'stacked',
            'form_button_width' => 'full',
            'form_button_alignment' => 'center',
            'progress_type' => 'bar',
            'progress_label_mode' => 'step_text',
            'progress_steps_show_labels' => 'yes',
            'buttons_show_disabled_prev' => 'no',
            'show_back_button' => 'yes',
            'back_button_color' => '#0073e6',
            'back_button_bg' => '#f1f5f9',
            'back_button_hover_text' => '#ffffff',
            'back_button_align' => 'center',
            'back_button_margin_top' => ['size' => 30],
            'shell_intro_title' => '',
            'shell_intro_subtitle' => '',
        ], $form, [
            'cliniko_form_template_id' => $templateId,
            'module_id' => $appointmentTypeId,
            'form_type' => $isMultistep ? 'multi' : 'single',
            'appointment_source' => 'custom_form',
            'enable_payment' => $gateway === 'none' ? 'no' : 'yes',
            'custom_form_payment' => $gateway === 'none' ? 'stripe' : $gateway,
            'appointment_time_selection' => $showCalendar ? 'calendar' : 'next_available',
            'shortcode_booking' => true,
            'shortcode_next_available_slot' => $nextSlot,
        ]);
        $settings['shortcode_form_id'] = $id;
        $settings['shortcode_success_message'] = (string) ($form['success_message'] ?? 'Your appointment has been confirmed.');
        $settings['shortcode_failure_message'] = (string) ($form['failure_message'] ?? 'We could not complete your booking. Please try again.');
        $settings['shortcode_success_action'] = (string) ($form['success_action'] ?? 'message');
        $settings['shortcode_failure_action'] = (string) ($form['failure_action'] ?? 'message');
        $data['sections'] = [];
        $data['submission_template']['content'] = ['sections' => []];
        $settings['shortcode_form_handler_data'] = $data;
        return $settings;
    }

    /** @param array<string,mixed> $settings */
    private static function enqueueClinikoShortcodeAssets(array $settings): void
    {
        $base = dirname(__DIR__, 2) . '/Widgets/ClinikoForm/';
        $url = plugins_url('../../Widgets/ClinikoForm/', __FILE__);
        wp_enqueue_script('cliniko-form-helpers', $url . 'assets/js/helpers.js', [], file_exists($base . 'assets/js/helpers.js') ? (string) filemtime($base . 'assets/js/helpers.js') : null, true);
        wp_enqueue_script('cliniko-form-handler', $url . 'assets/js/form-handler.js', [], file_exists($base . 'assets/js/form-handler.js') ? (string) filemtime($base . 'assets/js/form-handler.js') : null, true);

        $data = $settings['shortcode_form_handler_data'] ?? [];
        $data['sections'] = $settings['shortcode_sections'] ?? [];
        $data['submission_template']['content'] = ['sections' => []];
        $data['is_payment_enabled'] = ($settings['enable_payment'] ?? 'no') === 'yes';
        $data['module_id'] = (string) ($settings['module_id'] ?? '');
        $data['patient_form_template_id'] = (string) ($settings['cliniko_form_template_id'] ?? '');
        $data['form_type'] = (string) ($settings['form_type'] ?? 'multi');
        $data['custom_form_payment'] = (string) ($settings['custom_form_payment'] ?? 'stripe');
        $data['appointment_time_selection'] = (string) ($settings['appointment_time_selection'] ?? 'calendar');
        $data['cliniko_embed'] = 'custom_form';
        $data['redirect_url'] = (string) ($settings['shortcode_redirect_url'] ?? ($settings['redirect_url'] ?? home_url('/')));
        wp_localize_script('cliniko-form-handler', 'formHandlerData', $data);

        $gateway = (string) ($settings['custom_form_payment'] ?? 'stripe');
        if (($settings['enable_payment'] ?? 'no') === 'yes' && $gateway === 'stripe') {
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
            wp_enqueue_script('cliniko-stripe-js', $url . 'assets/js/stripe.js', ['cliniko-form-handler'], file_exists($base . 'assets/js/stripe.js') ? (string) filemtime($base . 'assets/js/stripe.js') : null, true);
        }
        if (($settings['enable_payment'] ?? 'no') === 'yes' && $gateway === 'tyrohealth') {
            wp_enqueue_script('medipass-transaction-sdk', 'https://unpkg.com/@medipass/partner-sdk@1.10.1/umd/@medipass/partner-sdk.min.js', [], null, true);
            wp_enqueue_script('cliniko-tyrohealth-js', $url . 'assets/js/tyrohealth.js', ['cliniko-form-handler', 'medipass-transaction-sdk'], file_exists($base . 'assets/js/tyrohealth.js') ? (string) filemtime($base . 'assets/js/tyrohealth.js') : null, true);
            wp_localize_script('cliniko-tyrohealth-js', 'TyroHealthData', [
                'env' => Credentials::getTyroEnv(),
                'appId' => Credentials::getTyroAppId(),
                'appVersion' => Credentials::getTyroAppVersion(),
                'providerNumber' => Credentials::getTyroProviderNumber(),
                'sdk_token_url' => rest_url('v1/tyrohealth/sdk-token'),
                'attempt_preflight_url' => rest_url('v2/booking-attempts/preflight'),
                'attempt_confirm_url' => rest_url('v2/booking-attempts/confirm-tyro'),
                'attempt_finalize_url' => rest_url('v2/booking-attempts/finalize'),
                'paymentMethod' => 'new-payment-card',
            ]);
        }
    }

    /** @param array<string,mixed> $attributes */
    private static function renderBookingShortcode(array $attributes, string $mode): string
    {
        $attributes = shortcode_atts([
            'id' => '',
            'booking_alias' => '',
            'appointment_type_id' => '',
            'patient_form_template_id' => '',
            'scheduling_mode' => '',
            'gateway' => '',
            'multistep' => '',
            'show_calendar' => '',
            'show_practitioner' => '',
            'show_patient_details_review' => '',
            'success_redirect' => '',
            'failure_redirect' => '',
            'custom_css' => '',
            'custom_js' => '',
            'code_alias' => '',
        ], $attributes, self::SHORTCODE);
        $mode = $mode === 'guest' ? 'guest' : 'patient';
        $resolvedAlias = self::resolveAlias($attributes);
        $id = is_array($resolvedAlias) ? 'alias_' . $resolvedAlias['alias'] : sanitize_key((string) ($attributes['id'] ?? ''));
        $form = is_array($resolvedAlias) ? $resolvedAlias['config'] : ($id !== '' ? (self::forms()[$id] ?? null) : null);
        if ($id === '') {
            $appointmentTypeValue = (string) ($attributes['appointment_type_id'] ?? '');
            $templateValue = (string) ($attributes['patient_form_template_id'] ?? '');
            $appointmentTypeId = self::numericId($appointmentTypeValue !== '' ? $appointmentTypeValue : ($_GET['appointment_type_id'] ?? ''));
            $templateId = self::numericId($templateValue !== '' ? $templateValue : ($_GET['patient_form_template_id'] ?? ''));
            if ($appointmentTypeId === '' || $templateId === '') {
                return '<p class="cliniko-patient-booking-form__message is-error">A valid appointment type and patient form template are required.</p>';
            }
            $id = 'generic_' . substr(hash('sha256', $appointmentTypeId . ':' . $templateId), 0, 16);
            $form = [
                'name' => 'Generic booking',
                'appointment_type_id' => $appointmentTypeId,
                'patient_form_template_id' => $templateId,
                'gateway' => 'stripe',
                'scheduling_mode' => 'manual',
                'show_calendar' => 'yes',
                'show_practitioner' => 'yes',
                'multistep' => 'yes',
                'show_patient_details_review' => 'no',
                'success_action' => 'message',
                'success_message' => 'Your appointment has been confirmed.',
                'success_redirect_url' => '',
                'failure_action' => 'message',
                'failure_message' => 'We could not complete your booking. Please try again.',
                'failure_redirect_url' => '',
            ];
        }
        if (!is_array($form)) {
            return current_user_can('manage_options') ? '<p>Patient booking form not found.</p>' : '';
        }

        if (is_array($resolvedAlias)) {
            $templateId = self::numericId((string) ($form['patient_form_template_id'] ?? ''));
            if ($templateId === '') {
                return '<p class="cliniko-patient-booking-form__message is-error">This booking alias is missing its patient form template.</p>';
            }
            $form['patient_form_template_id'] = $templateId;
        }

        // The saved builder mode is authoritative for configured forms.
        $mode = (string) ($form['booking_mode'] ?? $mode) === 'patient' ? 'patient' : 'guest';
        if ($mode === 'patient' && !is_user_logged_in()) {
            return '<p class="cliniko-patient-booking-form__message is-error">Please log in to use this booking form.</p>';
        }

        foreach (['appointment_type_id', 'patient_form_template_id'] as $key) {
            $override = self::numericId((string) ($attributes[$key] ?? ''));
            if ($override !== '' && !is_array($resolvedAlias)) {
                $form[$key] = $override;
            }
        }

        if ($mode === 'guest' && !is_array($resolvedAlias)) {
            foreach (['custom_css', 'custom_js'] as $key) {
                if (trim((string) ($attributes[$key] ?? '')) !== '') {
                    $form[$key] = (string) $attributes[$key];
                }
            }
        }

        $schedulingMode = (string) ($attributes['scheduling_mode'] ?? '');
        if (in_array($schedulingMode, ['manual', 'next_available'], true)) {
            $form['scheduling_mode'] = $schedulingMode;
        }

        foreach (['gateway', 'multistep', 'show_calendar', 'show_practitioner'] as $key) {
            $value = sanitize_key((string) ($attributes[$key] ?? ''));
            if ($value !== '') {
                $form[$key] = $value;
            }
        }
        $patientDetailsReview = sanitize_key((string) ($attributes['show_patient_details_review'] ?? ''));
        if ($patientDetailsReview !== '') {
            $form['show_patient_details_review'] = in_array($patientDetailsReview, ['1', 'on', 'true', 'yes'], true)
                ? 'yes'
                : 'no';
        }
        if (($form['show_calendar'] ?? 'yes') === 'no') {
            $form['scheduling_mode'] = 'next_available';
        } elseif (($form['scheduling_mode'] ?? 'manual') === 'next_available') {
            // A visible calendar is authoritative: next-available mode cannot
            // simultaneously provide calendar/date/time controls.
            $form['scheduling_mode'] = 'manual';
        }
        if (!in_array((string) ($form['gateway'] ?? 'stripe'), ['stripe', 'tyrohealth', 'none'], true)) {
            $form['gateway'] = 'stripe';
        }

        try {
            $client = cliniko_client(false);
            $templateId = (string) ($form['patient_form_template_id'] ?? '');
            $template = PatientFormTemplate::find($templateId, $client);
            if (!$template) {
                return '<p class="cliniko-patient-booking-form__message is-error">The configured patient form could not be found.</p>';
            }
            $appointmentTypeId = (string) ($form['appointment_type_id'] ?? '');
            $appointmentType = AppointmentType::find($appointmentTypeId, $client);
            if (!$appointmentType) {
                return '<p class="cliniko-patient-booking-form__message is-error">The configured appointment type could not be found.</p>';
            }
            $inputRules = self::normaliseBookingInputRules(
                $template,
                is_array($form['input_rules'] ?? null) ? $form['input_rules'] : [],
                $mode
            );
            $bookingName = trim((string) $appointmentType->getName());
            $bookingDescription = trim((string) $appointmentType->getDescription());
            $bookingPrice = max(0, (int) $appointmentType->getBillableItemsFinalPrice());
            $showPatientDetailsReview = $mode === 'patient'
                && (string) ($form['show_patient_details_review'] ?? 'no') === 'yes';
            $patientDetails = [];
            if ($showPatientDetailsReview) {
                $patientDetails = Auth::patientData() ?? [];
                if ($patientDetails === []) {
                    return '<p class="cliniko-patient-booking-form__message is-error">Your linked patient details could not be loaded.</p>';
                }
            }

            $automaticScheduling = ($form['scheduling_mode'] ?? 'manual') === 'next_available';
            $nextSlot = $automaticScheduling
                ? self::nextAvailableSlot((string) ($form['appointment_type_id'] ?? ''))
                : null;
            if ($automaticScheduling && $nextSlot === null) {
                return '<p class="cliniko-patient-booking-form__message is-error">There are no available appointments in the next 90 days.</p>';
            }

            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
            if (($form['gateway'] ?? 'stripe') === 'tyrohealth') {
                wp_enqueue_script(
                    'medipass-transaction-sdk',
                    'https://unpkg.com/@medipass/partner-sdk@1.10.1/umd/@medipass/partner-sdk.min.js',
                    [],
                    null,
                    ['strategy' => 'defer']
                );
            }
            ComponentStyles::enqueueFrontend();
            ShortcodeFormInputRules::enqueueFrontendAssets();
            $scriptPath = __DIR__ . '/../assets/patient-booking-form.js';
            $stylePath = __DIR__ . '/../assets/patient-booking-form.css';
            wp_enqueue_style('cliniko-patient-booking-form', plugins_url('../assets/patient-booking-form.css', __FILE__), ['cliniko-shortcode-components', 'cliniko-shortcode-form-input-rules'], file_exists($stylePath) ? (string) filemtime($stylePath) : null);
            wp_enqueue_script('cliniko-patient-booking-form', plugins_url('../assets/patient-booking-form.js', __FILE__), ['cliniko-shortcode-components', 'cliniko-shortcode-form-input-rules'], file_exists($scriptPath) ? (string) filemtime($scriptPath) : null, true);
            $isRenewal = self::$renewalAppointmentId !== '';
            $specificRedirect = (string) ($form['success_redirect_url'] ?? '');
            $attributeSuccessRedirect = esc_url_raw((string) ($attributes['success_redirect'] ?? ''));
            if ($attributeSuccessRedirect !== '') {
                $specificRedirect = $attributeSuccessRedirect;
            }
            $genericShortcode = $isRenewal ? self::RENEWAL_SHORTCODE : self::SHORTCODE;
            $configuredRedirect = $isRenewal
                ? ShortcodeCatalog::redirectFor(self::RENEWAL_SHORTCODE, $id)
                : ShortcodeCatalog::redirectFor(self::GUEST_SHORTCODE, $id);
            $genericRedirect = $configuredRedirect !== ''
                ? $configuredRedirect
                : ShortcodeCatalog::defaultRedirectFor($genericShortcode);
            $successAction = (string) ($form['success_action'] ?? 'message');
            if ($specificRedirect === '' && $genericRedirect !== '') {
                $specificRedirect = $genericRedirect;
                $successAction = 'redirect';
            }
            wp_localize_script('cliniko-patient-booking-form', 'ClinikoPatientBookingData', [
                'appointment_type_id' => (string) ($form['appointment_type_id'] ?? ''),
                'template_id' => $templateId,
                'form_id' => $id,
                'form_mode' => $mode,
                'form_nonce' => wp_create_nonce('cliniko_patient_booking_form_' . $id),
                'rest_nonce' => wp_create_nonce('wp_rest'),
                'gateway' => (string) ($form['gateway'] ?? 'stripe'),
                'multistep' => (string) ($form['multistep'] ?? 'yes'),
                'show_calendar' => (string) ($form['show_calendar'] ?? 'yes'),
                'show_practitioner' => (string) ($form['show_practitioner'] ?? 'yes'),
                'stripe_pk' => (string) get_option('wp_stripe_public_key', ''),
                'preflight_url' => $mode === 'patient' ? rest_url('v2/patient-booking-attempts/preflight') : rest_url('v2/booking-attempts/preflight'),
                'authenticated' => $mode === 'patient',
                'charge_url' => rest_url('v2/booking-attempts/charge-stripe'),
                'confirm_tyro_url' => rest_url('v2/booking-attempts/confirm-tyro'),
                'finalize_url' => rest_url('v2/booking-attempts/finalize'),
                'practitioners_url' => rest_url('v1/practitioners'),
                'available_times_url' => rest_url('v1/available-times'),
                'appointment_calendar_url' => rest_url('v1/appointment-calendar'),
                'next_available_times_url' => rest_url('v1/next-available-times'),
                'patient_update_url' => $showPatientDetailsReview ? rest_url('v2/patient/me') : '',
                'scheduling_mode' => (string) ($form['scheduling_mode'] ?? 'manual'),
                'success_message' => (string) ($form['success_message'] ?? 'Your appointment has been confirmed.'),
                'success_action' => $successAction,
                'success_redirect_url' => $specificRedirect,
                'failure_action' => (string) ($form['failure_action'] ?? 'message'),
                'failure_message' => (string) ($form['failure_message'] ?? 'We could not complete your booking. Please try again.'),
                'failure_redirect_url' => esc_url_raw((string) ($attributes['failure_redirect'] ?? '')) ?: (string) ($form['failure_redirect_url'] ?? ''),
                'renewal_appointment_id' => self::$renewalAppointmentId,
                'components' => ComponentStyles::frontendConfig(),
                'tyro' => [
                    'env' => Credentials::getTyroEnv(),
                    'appId' => Credentials::getTyroAppId(),
                    'appVersion' => Credentials::getTyroAppVersion(),
                    'provider_number' => Credentials::getTyroProviderNumber(),
                    'sdk_token_url' => rest_url('v1/tyrohealth/sdk-token'),
                ],
            ]);

            ob_start();
            ?>
            <!-- cliniko-code-slot:before_form -->
            <form id="<?php echo esc_attr('cliniko-' . $mode . '-booking-' . $id); ?>" class="cliniko-<?php echo esc_attr($mode); ?>-booking-form cliniko-patient-booking-form cliniko-component cliniko-component-form-fields<?php echo $isRenewal ? ' cliniko-renewal-mode cliniko-component-data-review' : ''; ?>" data-cliniko-<?php echo esc_attr($mode); ?>-booking-form data-scheduling-mode="<?php echo esc_attr((string) ($form['scheduling_mode'] ?? 'manual')); ?>" data-multistep="<?php echo esc_attr($isRenewal ? 'yes' : (string) ($form['multistep'] ?? 'yes')); ?>">
                <input type="hidden" name="module_id" value="<?php echo esc_attr((string) ($form['appointment_type_id'] ?? '')); ?>">
                <input type="hidden" name="patient_form_template_id" value="<?php echo esc_attr($templateId); ?>">
                <?php if ($isRenewal || ($form['multistep'] ?? 'yes') === 'yes') : ?><ol class="cliniko-booking-progress cliniko-component-steps" data-booking-progress aria-label="Booking progress"></ol><?php endif; ?>
                <div class="cliniko-patient-booking-form__questions">
                        <?php foreach ($template->getSections() as $sectionIndex => $section) : ?>
                <div class="cliniko-booking-step" data-booking-step data-step-label="<?php echo esc_attr(trim((string) ($section->name ?? '')) ?: 'Questions'); ?>">
                    <?php if ($isRenewal && (int) $sectionIndex === 0) : self::renderRenewalReview(); endif; ?>
                    <fieldset data-section-index="<?php echo esc_attr((string) $sectionIndex); ?>" data-section-name="<?php echo esc_attr((string) ($section->name ?? '')); ?>" data-section-description="<?php echo esc_attr((string) ($section->description ?? '')); ?>">
                        <legend><?php echo self::renderApiHtml((string) ($section->name ?? '')); ?></legend>
                        <?php if (trim((string) ($section->description ?? '')) !== '') : ?><div class="cliniko-patient-booking-form__section-description"><?php echo self::renderApiHtml((string) $section->description); ?></div><?php endif; ?>
                        <?php foreach ($section->questions as $questionIndex => $question) : $type = strtolower((string) $question->type); $name = 'question_' . $sectionIndex . '_' . $questionIndex; $prefill = self::renewalQuestionPrefill((int) $sectionIndex, (int) $questionIndex, (string) $question->name); $ruleKey = self::bookingQuestionRuleKey((int) $sectionIndex, (int) $questionIndex); $inputRule = $inputRules[$ruleKey] ?? []; if ($type === 'signature') continue; ?>
                            <div class="cliniko-patient-booking-form__question<?php echo $isRenewal ? ' is-reviewing' : ''; ?>" data-question-type="<?php echo esc_attr($type); ?>" data-question-required="<?php echo !empty($question->required) ? '1' : '0'; ?>" data-section-index="<?php echo esc_attr((string) $sectionIndex); ?>" data-question-index="<?php echo esc_attr((string) $questionIndex); ?>">
                                <div class="cliniko-renewal-field-heading"><label><?php echo self::renderApiHtml((string) ($question->name ?? '')); ?><?php if (!empty($question->required)) : ?> <span aria-hidden="true">*</span><?php endif; ?></label><?php if ($isRenewal) : ?><button type="button" class="cliniko-renewal-edit" data-renewal-edit>Edit</button><?php endif; ?></div>
                                <?php if ($isRenewal) : self::renderRenewalQuestionDisplay($type, $prefill, $question); endif; ?>
                                <?php self::renderBookingQuestionInput($type, $name, $question, $prefill, $inputRule); ?>
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                </div>
                <?php endforeach; ?>
                </div>
                <?php if ($isRenewal) : ?>
                <div class="cliniko-booking-step cliniko-renewal-consent-step" data-booking-step data-step-label="Consent">
                    <fieldset>
                        <legend>Consent</legend>
                        <label class="cliniko-renewal-consent"><input type="checkbox" name="renewal_consent" value="1" required> I agree to the consent requirements again for this new appointment.</label>
                    </fieldset>
                </div>
                <?php endif; ?>
                <!-- cliniko-code-slot:before_appointment -->
                <?php echo AppointmentSelector::render([
                    'automatic' => $automaticScheduling,
                    'next_slot' => $nextSlot,
                    'show_calendar' => ($form['show_calendar'] ?? 'yes') === 'yes',
                    'show_practitioner' => ($form['show_practitioner'] ?? 'yes') === 'yes',
                    'is_renewal' => $isRenewal,
                ]); ?>
                <!-- cliniko-code-slot:after_appointment -->
                <?php if ($showPatientDetailsReview) : self::renderPatientDetailsReview($patientDetails); endif; ?>
                <?php if ($mode === 'guest') : ?><div class="cliniko-booking-step" data-booking-step data-step-label="Patient details">
                <fieldset data-booking-patient>
                    <legend>Patient details</legend>
                    <?php foreach (self::bookingPatientFields() as $patientField => $patientDefinition) :
                        self::renderBookingPatientInput($patientField, $patientDefinition, $inputRules['patient_' . $patientField] ?? []);
                    endforeach; ?>
                </fieldset>
                </div><?php endif; ?>
                <!-- cliniko-code-slot:before_payment -->
                <?php if (($form['gateway'] ?? 'stripe') !== 'none') : ?><div class="cliniko-booking-step" data-booking-step data-step-label="Payment">
                <section class="cliniko-patient-booking-form__payment cliniko-component-payment" aria-labelledby="cliniko-booking-summary-title-<?php echo esc_attr($id); ?>">
                    <div class="cliniko-patient-booking-form__summary">
                        <h3 id="cliniko-booking-summary-title-<?php echo esc_attr($id); ?>">Booking summary</h3>
                        <p><strong>Booking:</strong> <?php echo esc_html($bookingName); ?></p>
                        <?php if ($bookingDescription !== '') : ?><div><strong>Description:</strong> <?php echo self::renderApiHtml($bookingDescription); ?></div><?php endif; ?>
                        <p><strong>Price:</strong> $<?php echo esc_html(number_format($bookingPrice / 100, 2)); ?> AUD</p>
                    </div>
                    <?php if (($form['gateway'] ?? 'stripe') === 'stripe') : ?><div class="cliniko-patient-booking-form__payment-details"><label>Payment details</label><div data-booking-card></div><div data-booking-payment-error role="alert"></div></div><?php elseif (($form['gateway'] ?? '') === 'tyrohealth') : ?><div class="cliniko-patient-booking-form__payment-details"><p>Payment will open in the secure Tyro Health checkout.</p><div data-booking-tyro-error role="alert"></div></div><?php endif; ?>
                </section>
                </div><?php endif; ?>
                <!-- cliniko-code-slot:after_payment -->
                <button type="submit">Book appointment</button>
                <div class="cliniko-patient-booking-form__status cliniko-component-feedback" data-booking-status role="status"></div>
            </form>
            <!-- cliniko-code-slot:after_form -->
            <?php
            $markup = (string) ob_get_clean();
            if ($mode === 'guest') {
                $customCss = trim((string) ($form['custom_css'] ?? ''));
                $customJs = trim((string) ($form['custom_js'] ?? ''));
                if ($customCss !== '') {
                    $markup .= '<style data-cliniko-guest-booking-css="' . esc_attr($id) . '">' . $customCss . '</style>';
                }
                if ($customJs !== '') {
                    wp_add_inline_script('cliniko-patient-booking-form', $customJs, 'after');
                }
            }
            // Apply the bundle last so custom CSS has the final cascade
            // position relative to the plugin's built-in inline styles.
            $markup = CustomCode::applyToMarkup($markup, $attributes);
            return $markup;
        } catch (\Throwable $exception) {
            error_log('Cliniko patient booking form failed: ' . $exception->getMessage());
            return '<p class="cliniko-patient-booking-form__message is-error">The booking form is temporarily unavailable.</p>';
        }
    }

    /** @return array<string,array{label:string,type:string,required:bool,autocomplete:string}> */
    private static function bookingPatientFields(): array
    {
        return [
            'first_name' => ['label' => 'First name', 'type' => 'text', 'required' => true, 'autocomplete' => 'given-name'],
            'last_name' => ['label' => 'Last name', 'type' => 'text', 'required' => true, 'autocomplete' => 'family-name'],
            'email' => ['label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email'],
            'phone' => ['label' => 'Phone', 'type' => 'tel', 'required' => false, 'autocomplete' => 'tel'],
            'medicare' => ['label' => 'Medicare number', 'type' => 'text', 'required' => false, 'autocomplete' => 'off'],
            'medicare_reference_number' => ['label' => 'Medicare reference', 'type' => 'text', 'required' => false, 'autocomplete' => 'off'],
            'address_1' => ['label' => 'Address line 1', 'type' => 'text', 'required' => false, 'autocomplete' => 'address-line1'],
            'address_2' => ['label' => 'Address line 2', 'type' => 'text', 'required' => false, 'autocomplete' => 'address-line2'],
            'city' => ['label' => 'City', 'type' => 'text', 'required' => false, 'autocomplete' => 'address-level2'],
            'state' => ['label' => 'State', 'type' => 'text', 'required' => false, 'autocomplete' => 'address-level1'],
            'post_code' => ['label' => 'Post code', 'type' => 'text', 'required' => false, 'autocomplete' => 'postal-code'],
            'country' => ['label' => 'Country', 'type' => 'text', 'required' => false, 'autocomplete' => 'country-name'],
            'date_of_birth' => ['label' => 'Date of birth', 'type' => 'date', 'required' => false, 'autocomplete' => 'bday'],
        ];
    }

    /** @param array<string,mixed> $patient */
    private static function renderPatientDetailsReview(array $patient): void
    {
        $fields = self::bookingPatientFields();
        $displayValue = static function (string $key, $value): string {
            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($key === 'date_of_birth' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches)) {
                return $matches[3] . '/' . $matches[2] . '/' . $matches[1];
            }
            return $value !== '' ? $value : 'Not provided';
        };
        ?>
        <div class="cliniko-booking-step cliniko-patient-details-review-step" data-booking-step data-step-label="Personal details" data-patient-details-review>
            <section class="cliniko-patient-details-review" aria-labelledby="cliniko-patient-details-review-title">
                <header class="cliniko-patient-details-review__header">
                    <div><h2 id="cliniko-patient-details-review-title">Review your personal details</h2><p>Please check that these details are correct before continuing.</p></div>
                    <button type="button" class="cliniko-patient-details-review__edit" data-patient-details-open aria-haspopup="dialog">Edit details</button>
                </header>
                <dl class="cliniko-patient-details-review__values">
                    <?php foreach ($fields as $key => $definition) : ?>
                        <div><dt><?php echo esc_html($definition['label']); ?></dt><dd data-patient-details-value="<?php echo esc_attr($key); ?>"><?php echo esc_html($displayValue($key, $patient[$key] ?? '')); ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </section>
            <div class="cliniko-patient-details-drawer" data-patient-details-drawer hidden>
                <button type="button" class="cliniko-patient-details-drawer__backdrop" data-patient-details-close aria-label="Close personal details editor"></button>
                <section class="cliniko-patient-details-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="cliniko-patient-details-drawer-title" tabindex="-1">
                    <header class="cliniko-patient-details-drawer__header">
                        <div><p class="cliniko-patient-details-drawer__eyebrow">Patient profile</p><h2 id="cliniko-patient-details-drawer-title">Edit personal details</h2></div>
                        <button type="button" class="cliniko-patient-details-drawer__close" data-patient-details-close aria-label="Close personal details editor">&times;</button>
                    </header>
                    <div class="cliniko-patient-details-drawer__body">
                        <div class="cliniko-patient-details-drawer__fields">
                            <?php foreach ($fields as $key => $definition) :
                                $value = is_scalar($patient[$key] ?? null) ? (string) $patient[$key] : '';
                                $inputType = in_array($definition['type'], ['email', 'tel', 'date'], true) ? $definition['type'] : 'text';
                                ?>
                                <div class="cliniko-patient-details-drawer__field">
                                    <label for="<?php echo esc_attr(wp_unique_id('cliniko-patient-review-')); ?>"><?php echo esc_html($definition['label']); ?></label>
                                    <input id="<?php echo esc_attr(wp_unique_id('cliniko-patient-review-input-')); ?>" type="<?php echo esc_attr($inputType); ?>" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($inputType === 'date' ? substr($value, 0, 10) : $value); ?>" autocomplete="<?php echo esc_attr($definition['autocomplete']); ?>" data-patient-details-field="<?php echo esc_attr($key); ?>">
                                    <?php if ($key === 'email') : ?><small>Email changes require confirmation from the new address before they take effect.</small><?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="cliniko-patient-details-drawer__status" data-patient-details-status role="status" aria-live="polite"></p>
                    </div>
                    <footer class="cliniko-patient-details-drawer__footer">
                        <button type="button" class="cliniko-patient-details-drawer__cancel" data-patient-details-close>Cancel</button>
                        <button type="button" class="cliniko-patient-details-drawer__save" data-patient-details-save>Save details</button>
                    </footer>
                </section>
            </div>
        </div>
        <?php
    }

    /** @return array<string,bool> */
    private static function bookingRuleCapabilities(string $type): array
    {
        $type = strtolower($type);
        $isTextarea = in_array($type, ['textarea', 'paragraph', 'long_text', 'longtext', 'text_area', 'multiline'], true);
        $isEmail = $type === 'email';
        $isDate = $type === 'date';
        return [
            'text' => true,
            'date' => !$isTextarea && !$isEmail,
            'select' => !$isEmail,
            'limit' => true,
            'format' => !$isEmail && !$isDate,
        ];
    }

    private static function bookingQuestionRuleKey(int $sectionIndex, int $questionIndex): string
    {
        return 'question_' . $sectionIndex . '_' . $questionIndex;
    }

    /** @param object $question @param array<string,mixed> $prefill @param array<string,mixed> $rule */
    private static function renderBookingQuestionInput(string $type, string $name, $question, array $prefill, array $rule): void
    {
        $required = !empty($question->required);
        $answer = is_scalar($prefill['answer'] ?? null) ? (string) $prefill['answer'] : '';

        if (in_array($type, ['checkboxes', 'radiobuttons'], true)) {
            $inputType = $type === 'checkboxes' ? 'checkbox' : 'radio';
            $selected = array_values(array_filter(
                array_map('strval', (array) ($prefill['selected'] ?? [])),
                static fn(string $value): bool => $value !== ''
            ));
            ?>
            <div class="cliniko-patient-booking-form__choices" <?php echo $required ? 'data-cliniko-required-choice-group="1"' : ''; ?>>
                <?php foreach ((array) ($question->answers ?? []) as $optionIndex => $option) :
                    $optionValue = is_object($option) ? (string) ($option->value ?? '') : (string) ($option['value'] ?? '');
                    $optionId = wp_unique_id('cliniko-booking-choice-');
                    ?>
                    <label for="<?php echo esc_attr($optionId); ?>" class="cliniko-patient-booking-form__choice">
                        <input id="<?php echo esc_attr($optionId); ?>" type="<?php echo esc_attr($inputType); ?>" name="<?php echo esc_attr($name . ($inputType === 'checkbox' ? '[]' : '')); ?>" value="<?php echo esc_attr($optionValue); ?>" data-booking-answer-option <?php checked(in_array($optionValue, $selected, true)); ?> <?php echo $required && $inputType === 'radio' && $optionIndex === 0 ? 'required' : ''; ?>>
                        <span><?php echo esc_html($optionValue); ?></span>
                    </label>
                <?php endforeach; ?>
                <?php if ($question->other !== null && !empty($question->other->enabled)) :
                    $otherId = wp_unique_id('cliniko-booking-choice-other-');
                    $otherSelected = !empty($prefill['other']['selected']);
                    $otherValue = is_scalar($prefill['other']['value'] ?? null) ? (string) $prefill['other']['value'] : '';
                    ?>
                    <label for="<?php echo esc_attr($otherId); ?>" class="cliniko-patient-booking-form__choice">
                        <input id="<?php echo esc_attr($otherId); ?>" type="<?php echo esc_attr($inputType); ?>" name="<?php echo esc_attr($name . ($inputType === 'checkbox' ? '[]' : '')); ?>" value="__other__" data-booking-other-toggle <?php checked($otherSelected); ?> <?php echo $required && $inputType === 'radio' ? 'required' : ''; ?>>
                        <span>Other</span>
                    </label>
                    <div class="cliniko-patient-booking-form__other" data-booking-other-control <?php echo $otherSelected ? '' : 'hidden'; ?>>
                        <label for="<?php echo esc_attr($otherId . '-value'); ?>">Please specify</label>
                        <input id="<?php echo esc_attr($otherId . '-value'); ?>" type="text" name="<?php echo esc_attr($name . '_other'); ?>" value="<?php echo esc_attr($otherValue); ?>" data-booking-other-input <?php echo $otherSelected ? 'required' : ''; ?>>
                    </div>
                <?php endif; ?>
            </div>
            <?php
            return;
        }

        $renderType = (string) ($rule['input_type'] ?? 'default');
        $dateFormat = (string) ($rule['date_format'] ?? 'dmy_slash');
        $options = array_values(array_filter(array_map('strval', (array) ($rule['select_options'] ?? [])), static fn(string $option): bool => $option !== ''));
        $attributes = ShortcodeFormInputRules::inputAttributes($rule);
        $requiredAttribute = $required ? ' required' : '';

        if ($renderType === 'select' && $options !== []) {
            ?>
            <select name="<?php echo esc_attr($name); ?>"<?php echo $requiredAttribute; ?>>
                <option value="">Select</option>
                <?php foreach ($options as $option) : ?><option value="<?php echo esc_attr($option); ?>" <?php selected($answer, $option); ?>><?php echo esc_html($option); ?></option><?php endforeach; ?>
            </select>
            <?php
            return;
        }

        if ($renderType === 'date') {
            $displayValue = ShortcodeFormInputRules::displayDate($answer, $dateFormat);
            ?>
            <div class="cliniko-shortcode-date-control" data-cliniko-date-control>
                <input type="text" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($displayValue); ?>" inputmode="numeric" maxlength="10" placeholder="<?php echo esc_attr(ShortcodeFormInputRules::datePlaceholder($dateFormat)); ?>" data-cliniko-date-input data-cliniko-date-format="<?php echo esc_attr($dateFormat); ?>"<?php echo $requiredAttribute; ?>>
                <?php self::renderDatePickerButton((string) ($question->name ?? 'date')); ?>
                <input type="date" class="cliniko-shortcode-native-date" value="<?php echo esc_attr(substr($answer, 0, 10)); ?>" data-cliniko-native-date-picker tabindex="-1" aria-hidden="true">
            </div>
            <small class="cliniko-shortcode-input-hint">Displayed as <?php echo esc_html(ShortcodeFormInputRules::datePlaceholder($dateFormat)); ?> and submitted as YYYY-MM-DD.</small>
            <?php
            return;
        }

        if (in_array($type, ['textarea', 'paragraph', 'long_text', 'longtext', 'text_area', 'multiline'], true) && $renderType !== 'text') {
            ?><textarea name="<?php echo esc_attr($name); ?>"<?php echo $requiredAttribute; ?><?php echo $attributes; ?>><?php echo esc_textarea($answer); ?></textarea><?php
        } else {
            $htmlType = $renderType === 'text' || (string) ($rule['input_format'] ?? 'none') !== 'none'
                ? 'text'
                : (in_array($type, ['email', 'number', 'tel', 'url'], true) ? $type : 'text');
            ?><input type="<?php echo esc_attr($htmlType); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($answer); ?>"<?php echo $requiredAttribute; ?><?php echo $attributes; ?>><?php
        }
        $hint = ShortcodeFormInputRules::hint($rule);
        if ($hint !== '') {
            ?><small class="cliniko-shortcode-input-hint"><?php echo esc_html($hint); ?></small><?php
        }
    }

    /** @param array{label:string,type:string,required:bool,autocomplete:string} $definition @param array<string,mixed> $rule */
    private static function renderBookingPatientInput(string $key, array $definition, array $rule): void
    {
        $fieldId = wp_unique_id('cliniko-booking-patient-');
        $renderType = (string) ($rule['input_type'] ?? 'default');
        $dateFormat = (string) ($rule['date_format'] ?? 'dmy_slash');
        $options = array_values(array_filter(array_map('strval', (array) ($rule['select_options'] ?? [])), static fn(string $option): bool => $option !== ''));
        $required = !empty($definition['required']);
        $requiredAttribute = $required ? ' required' : '';
        ?>
        <div class="cliniko-patient-booking-form__patient-field">
            <label for="<?php echo esc_attr($fieldId); ?>"><?php echo esc_html($definition['label']); ?><?php if ($required) : ?> <span aria-hidden="true">*</span><?php endif; ?></label>
            <?php if ($renderType === 'select' && $options !== []) : ?>
                <select id="<?php echo esc_attr($fieldId); ?>" name="<?php echo esc_attr($key); ?>" autocomplete="<?php echo esc_attr($definition['autocomplete']); ?>"<?php echo $requiredAttribute; ?>>
                    <option value="">Select</option>
                    <?php foreach ($options as $option) : ?><option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option><?php endforeach; ?>
                </select>
            <?php elseif ($renderType === 'date' || ($renderType === 'default' && $definition['type'] === 'date')) : ?>
                <div class="cliniko-shortcode-date-control" data-cliniko-date-control>
                    <input id="<?php echo esc_attr($fieldId); ?>" type="text" name="<?php echo esc_attr($key); ?>" autocomplete="<?php echo esc_attr($definition['autocomplete']); ?>" inputmode="numeric" maxlength="10" placeholder="<?php echo esc_attr(ShortcodeFormInputRules::datePlaceholder($dateFormat)); ?>" data-cliniko-date-input data-cliniko-date-format="<?php echo esc_attr($dateFormat); ?>"<?php echo $requiredAttribute; ?>>
                    <?php self::renderDatePickerButton($definition['label']); ?>
                    <input type="date" class="cliniko-shortcode-native-date" data-cliniko-native-date-picker tabindex="-1" aria-hidden="true">
                </div>
                <small class="cliniko-shortcode-input-hint">Displayed as <?php echo esc_html(ShortcodeFormInputRules::datePlaceholder($dateFormat)); ?> and submitted as YYYY-MM-DD.</small>
            <?php else :
                $htmlType = $renderType === 'text' || (string) ($rule['input_format'] ?? 'none') !== 'none'
                    ? 'text'
                    : $definition['type'];
                ?>
                <input id="<?php echo esc_attr($fieldId); ?>" type="<?php echo esc_attr($htmlType); ?>" name="<?php echo esc_attr($key); ?>" autocomplete="<?php echo esc_attr($definition['autocomplete']); ?>"<?php echo $requiredAttribute; ?><?php echo ShortcodeFormInputRules::inputAttributes($rule); ?>>
                <?php $hint = ShortcodeFormInputRules::hint($rule); if ($hint !== '') : ?><small class="cliniko-shortcode-input-hint"><?php echo esc_html($hint); ?></small><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function renderDatePickerButton(string $label): void
    {
        ?>
        <button type="button" class="cliniko-shortcode-date-picker" data-cliniko-date-picker-trigger aria-label="Choose <?php echo esc_attr($label); ?> from calendar"><svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20"><path d="M7 2v2H5a3 3 0 0 0-3 3v12a3 3 0 0 0 3 3h14a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3h-2V2h-2v2H9V2H7Zm12 8H5v9a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-9ZM6 6h12a1 1 0 0 1 1 1v1H5V7a1 1 0 0 1 1-1Z"/></svg></button>
        <?php
    }

    /** @param array<string,mixed> $raw @return array<string,array<string,mixed>> */
    private static function normaliseBookingInputRules(?PatientFormTemplate $template, array $raw, string $mode): array
    {
        $rules = [];
        if ($mode === 'guest') {
            foreach (self::bookingPatientFields() as $key => $definition) {
                $rawConfig = is_array($raw['patient_' . $key] ?? null) ? $raw['patient_' . $key] : [];
                if ($key === 'medicare_reference_number' && $rawConfig === []) {
                    $rawConfig = ['max_length' => 1, 'input_format' => 'numbers'];
                }
                if ($definition['type'] === 'date' && !isset($rawConfig['input_type'])) {
                    $rawConfig['input_type'] = 'date';
                }
                $rules['patient_' . $key] = ShortcodeFormInputRules::normalise(
                    $rawConfig,
                    self::bookingRuleCapabilities($definition['type'])
                );
            }
        }

        if ($template !== null) {
            foreach ($template->getSections() as $sectionIndex => $section) {
                foreach ($section->questions as $questionIndex => $question) {
                    $type = strtolower((string) $question->type);
                    if (in_array($type, ['checkboxes', 'radiobuttons', 'signature'], true)) {
                        continue;
                    }
                    $key = self::bookingQuestionRuleKey((int) $sectionIndex, (int) $questionIndex);
                    $rawConfig = is_array($raw[$key] ?? null) ? $raw[$key] : [];
                    if ($type === 'date' && !isset($rawConfig['input_type'])) {
                        $rawConfig['input_type'] = 'date';
                    }
                    $rules[$key] = ShortcodeFormInputRules::normalise($rawConfig, self::bookingRuleCapabilities($type));
                }
            }
        }
        return $rules;
    }

    /** @param array<string,array<string,mixed>> $rules */
    private static function renderBookingInputRuleEditor(?PatientFormTemplate $template, array $rules, string $mode): void
    {
        ?>
        <div class="cliniko-booking-input-editor">
            <header>
                <h3>Input types and formatting</h3>
                <p>Configure dates, dropdowns, character limits, number-only fields, and masks. These rules apply to the generated shortcode.</p>
            </header>
            <?php if ($mode === 'guest') : ?>
                <section>
                    <h4>Patient details</h4>
                    <div class="cliniko-booking-input-editor__grid">
                    <?php foreach (self::bookingPatientFields() as $key => $definition) : ?>
                        <article>
                            <strong><?php echo esc_html($definition['label']); ?></strong>
                            <small><?php echo !empty($definition['required']) ? 'Required patient field' : 'Optional patient field'; ?></small>
                            <?php ShortcodeFormInputRules::renderAdminControls(
                                'input_rules[patient_' . $key . ']',
                                $rules['patient_' . $key] ?? [],
                                self::bookingRuleCapabilities($definition['type'])
                            ); ?>
                        </article>
                    <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
            <section>
                <h4>Patient form questions</h4>
                <?php if ($template === null) : ?>
                    <p class="description">Select and save a patient form template, then reopen this form to configure its individual questions.</p>
                <?php else : ?>
                    <div class="cliniko-booking-input-editor__grid">
                    <?php foreach ($template->getSections() as $sectionIndex => $section) : foreach ($section->questions as $questionIndex => $question) :
                        $type = strtolower((string) $question->type);
                        if (in_array($type, ['checkboxes', 'radiobuttons', 'signature'], true)) continue;
                        $key = self::bookingQuestionRuleKey((int) $sectionIndex, (int) $questionIndex);
                        ?>
                        <article>
                            <strong><?php echo esc_html((string) $question->name); ?></strong>
                            <small><?php echo esc_html((string) ($section->name ?? 'Patient form')); ?></small>
                            <?php ShortcodeFormInputRules::renderAdminControls(
                                'input_rules[' . $key . ']',
                                $rules[$key] ?? [],
                                self::bookingRuleCapabilities($type)
                            ); ?>
                        </article>
                    <?php endforeach; endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    /** @return array<string,array<string,mixed>> */
    private static function forms(): array
    {
        $forms = get_option(self::OPTION_KEY, []);
        return is_array($forms) ? $forms : [];
    }

    private static function numericId($value): string
    {
        $id = preg_replace('/[^0-9]/', '', (string) $value);
        return is_string($id) ? $id : '';
    }

    private static function renderApiHtml(string $value): string
    {
        $html = trim($value);
        if ($html === '') {
            return '';
        }

        // Cliniko can return markup encoded once or more inside the API text.
        for ($pass = 0; $pass < 3; $pass++) {
            $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $html) {
                break;
            }
            $html = $decoded;
        }

        return wp_kses_post($html);
    }

    private static function renderRenewalReview(): void
    {
        $summary = self::$renewalAppointmentSummary ?? [];
        $startsAt = (string) ($summary['starts_at'] ?? '');
        $startsTimestamp = $startsAt !== '' ? strtotime($startsAt) : false;
        $date = $startsTimestamp !== false ? wp_date(get_option('date_format'), $startsTimestamp) : '';
        $time = $startsTimestamp !== false ? wp_date(get_option('time_format'), $startsTimestamp) : '';
        echo DataReview::render([
            'class' => 'cliniko-renewal-review',
            'title' => 'Review your last appointment',
            'title_id' => 'cliniko-renewal-review-title',
            'introduction' => 'We have copied the details from your previous appointment. Select Edit beside any answer you want to change.',
            'rows' => [
                ['label' => 'Appointment type', 'value' => (string) ($summary['appointment_type'] ?? '')],
                ['label' => 'Practitioner', 'value' => (string) ($summary['practitioner'] ?? '')],
                ['label' => 'Date', 'value' => $date],
                ['label' => 'Time', 'value' => $time],
            ],
        ]);
    }

    /** @param array<string,mixed> $prefill @param object $question */
    private static function renderRenewalQuestionDisplay(string $type, array $prefill, $question): void
    {
        $values = [];
        if (in_array($type, ['checkboxes', 'radiobuttons'], true)) {
            $selected = array_map('strval', (array) ($prefill['selected'] ?? []));
            foreach ((array) ($question->answers ?? []) as $option) {
                $value = is_object($option) ? (string) ($option->value ?? '') : (string) ($option['value'] ?? '');
                if ($value !== '' && in_array($value, $selected, true)) {
                    $values[] = $value;
                }
            }
            if (!empty($prefill['other']['selected']) && trim((string) ($prefill['other']['value'] ?? '')) !== '') {
                $values[] = (string) $prefill['other']['value'];
            }
        } elseif (is_scalar($prefill['answer'] ?? null) && trim((string) $prefill['answer']) !== '') {
            $values[] = (string) $prefill['answer'];
        }
        if ($values === []) {
            $values[] = 'Not provided';
        }
        ?>
        <div class="cliniko-renewal-value" data-renewal-value>
            <?php foreach ($values as $value) : ?><span><?php echo nl2br(esc_html($value)); ?></span><?php endforeach; ?>
        </div>
        <?php
    }

    /** @return array{answer:string,selected:array<int,string>,other:array{selected:bool,value:string}} */
    private static function renewalQuestionPrefill(int $sectionIndex, int $questionIndex, string $questionName): array
    {
        $sections = is_array(self::$renewalPrefillContent['sections'] ?? null)
            ? self::$renewalPrefillContent['sections']
            : [];
        $question = is_array($sections[$sectionIndex]['questions'][$questionIndex] ?? null)
            ? $sections[$sectionIndex]['questions'][$questionIndex]
            : null;

        if ($question === null && $questionName !== '') {
            foreach ($sections as $section) {
                foreach (($section['questions'] ?? []) as $candidate) {
                    if (is_array($candidate) && trim((string) ($candidate['name'] ?? '')) === $questionName) {
                        $question = $candidate;
                        break 2;
                    }
                }
            }
        }

        if ($question === null) {
            return ['answer' => '', 'selected' => [], 'other' => ['selected' => false, 'value' => '']];
        }

        $selected = [];
        foreach (($question['answers'] ?? []) as $answer) {
            if (is_array($answer) && !empty($answer['selected'])) {
                $selected[] = (string) ($answer['value'] ?? '');
            }
        }
        foreach (($question['selected_answers'] ?? []) as $answer) {
            if (is_scalar($answer)) {
                $selected[] = (string) $answer;
            }
        }

        return [
            'answer' => is_scalar($question['answer'] ?? null) ? (string) $question['answer'] : '',
            'selected' => array_values(array_unique(array_filter($selected, static fn(string $value): bool => $value !== ''))),
            'other' => [
                'selected' => !empty($question['other']['selected']),
                'value' => is_scalar($question['other']['value'] ?? null) ? (string) $question['other']['value'] : '',
            ],
        ];
    }

    /** @return array{practitioner_id:string,appointment_start:string,appointment_date:string,label:string}|null */
    private static function nextAvailableSlot(string $appointmentTypeId): ?array
    {
        $appointmentTypeId = trim($appointmentTypeId);
        if ($appointmentTypeId === '') {
            return null;
        }

        try {
            $timezone = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $fromDate = new \DateTimeImmutable('now', $timezone);
            $toDate = $fromDate->add(new \DateInterval('P90D'));
            $appointmentType = AppointmentType::find($appointmentTypeId, cliniko_client(false));
            if (!$appointmentType) {
                return null;
            }

            $client = cliniko_client(false);
            $service = new ClinikoService();
            $items = [];
            $practitioners = $appointmentType->getPractitioners();
            $visiblePractitioners = array_values(array_filter($practitioners, static function ($practitioner): bool {
                $dto = $practitioner->getDTO();
                return !($dto && property_exists($dto, 'active') && $dto->active === false)
                    && !($dto && property_exists($dto, 'showInOnlineBookings') && $dto->showInOnlineBookings === false);
            }));
            if ($visiblePractitioners === []) {
                $visiblePractitioners = $practitioners;
            }

            foreach ($visiblePractitioners as $practitioner) {
                $dto = $practitioner->getDTO();
                if ($dto && property_exists($dto, 'active') && $dto->active === false) {
                    continue;
                }
                if ($dto && property_exists($dto, 'showInOnlineBookings') && $dto->showInOnlineBookings === false) {
                    continue;
                }

                $start = $service->getNextAvailableTime(
                    (string) get_option('wp_cliniko_business_id'),
                    (string) $practitioner->getId(),
                    $appointmentTypeId,
                    $fromDate->format('Y-m-d'),
                    $toDate->format('Y-m-d'),
                    $client
                );
                if (!$start || empty($start->appointmentStart)) {
                    continue;
                }
                $items[] = [
                    'practitioner_id' => (string) $practitioner->getId(),
                    'appointment_start' => (string) $start->appointmentStart,
                ];
            }

            usort($items, static fn(array $a, array $b): int => strtotime($a['appointment_start']) <=> strtotime($b['appointment_start']));
            $selected = $items[0] ?? null;
            if (!$selected) {
                return null;
            }

            $start = new \DateTimeImmutable($selected['appointment_start']);
            return [
                'practitioner_id' => $selected['practitioner_id'],
                'appointment_start' => $selected['appointment_start'],
                'appointment_date' => $start->format('Y-m-d'),
                'label' => $start->setTimezone($timezone)->format('F j, Y \a\t g:i A (T)'),
            ];
        } catch (\Throwable $exception) {
            error_log('Cliniko patient booking next-available lookup failed: ' . $exception->getMessage());
            return null;
        }
    }

    /** @param array<string,int|string> $args */
    private static function url(array $args = []): string
    {
        return \App\Admin\Modules\AccountBuilders\AccountBuilders::url(
            \App\Admin\Modules\AccountBuilders\AccountBuilders::TAB_BOOKING_FORMS,
            $args
        );
    }
}
