<?php

namespace App\Admin\Modules\AccountBuilders;

use App\Admin\Modules\AccountBuilders\Dashboard\DashboardModules;
use App\Admin\Modules\AccountBuilders\Dashboard\DashboardPatientDetails;
use App\Admin\Modules\AccountBuilders\Dashboard\PatientAttachmentsModule;
use App\Admin\Modules\AccountBuilders\Dashboard\PatientCommunicationsModule;
use App\Admin\Modules\AccountBuilders\Emails\PatientVerificationEmails;
use App\Admin\Modules\AccountBuilders\Forms\PatientAccountForms;
use App\Admin\Modules\AccountBuilders\Forms\PatientOnboarding;
use App\Admin\Modules\AccountBuilders\Forms\PatientFormHistoryForms;
use App\Admin\Modules\AccountBuilders\Forms\PatientFormTemplateForms;
use App\Admin\Modules\AccountBuilders\Shortcodes\ShortcodeCatalog;
use App\Admin\Modules\Components\ComponentStyles;
use App\Admin\Modules\PatientBookingForms;
use App\Support\AdminView;

if (!defined('ABSPATH')) {
    exit;
}

final class AccountBuilders
{
    public const PAGE = 'wp-cliniko-account-builders';
    public const TAB_PATIENT_FORMS = 'patient-forms';
    public const TAB_ONBOARDING = 'onboarding';
    public const TAB_BOOKING_FORMS = 'booking-forms';
    public const TAB_DASHBOARD_MODULES = 'dashboard-modules';
    public const TAB_COMPONENTS = 'components';
    public const TAB_SHORTCODES = 'shortcodes';
    public const TAB_REDIRECTS = 'redirects';
    public const TAB_EMAILS = 'emails';
    public const TAB_CUSTOM_CODE = 'custom-code';
    public const FORMS_PATIENT_DETAILS = 'patient-details';
    public const FORMS_PATIENT_FORMS = 'patient-forms';
    public const FORMS_PATIENT_FORM_TEMPLATES = 'patient-form-templates';
    public const DASHBOARD_APPOINTMENT_TYPES = 'appointment-types';
    public const DASHBOARD_APPOINTMENT_FORMS = 'appointment-forms';
    public const DASHBOARD_PATIENT_DETAILS = 'patient-details';
    public const DASHBOARD_PATIENT_ATTACHMENTS = 'patient-attachments';
    public const DASHBOARD_PATIENT_COMMUNICATIONS = 'patient-communications';

    public static function init(): void
    {
        CustomCode::init();
        ComponentStyles::init();
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_filter('admin_body_class', [self::class, 'bodyClass']);
    }

    public static function bodyClass(string $classes): string
    {
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if ($page === self::PAGE || $page === 'wp-cliniko-patient-booking-forms') {
            $classes .= ' cliniko-template-builder-screen';
        }
        return $classes;
    }

    public static function enqueueAssets(string $hook): void
    {
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if ($page !== self::PAGE && $page !== 'wp-cliniko-patient-booking-forms') {
            return;
        }

        $assetDirectory = dirname(__DIR__, 2) . '/assets';
        $path = $assetDirectory . '/template-builder-admin.css';
        $scriptPath = $assetDirectory . '/template-builder-admin.js';
        wp_enqueue_style(
            'cliniko-template-builder-admin',
            plugins_url('../../assets/template-builder-admin.css', __FILE__),
            [],
            file_exists($path) ? (string) filemtime($path) : null
        );
        wp_enqueue_script(
            'cliniko-template-builder-admin',
            plugins_url('../../assets/template-builder-admin.js', __FILE__),
            [],
            file_exists($scriptPath) ? (string) filemtime($scriptPath) : null,
            true
        );

        if (sanitize_key((string) ($_GET['builder_tab'] ?? '')) === self::TAB_COMPONENTS) {
            ComponentStyles::enqueueAdminAssets();
        }
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'wp-cliniko-stripe-settings',
            'Template Builder',
            'Template Builder',
            'manage_options',
            self::PAGE,
            [self::class, 'renderPage']
        );
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $tab = sanitize_key((string) ($_GET['builder_tab'] ?? self::TAB_PATIENT_FORMS));
        if (!in_array($tab, [self::TAB_PATIENT_FORMS, self::TAB_ONBOARDING, self::TAB_BOOKING_FORMS, self::TAB_DASHBOARD_MODULES, self::TAB_COMPONENTS, self::TAB_SHORTCODES, self::TAB_REDIRECTS, self::TAB_EMAILS, self::TAB_CUSTOM_CODE], true)) {
            $tab = self::TAB_PATIENT_FORMS;
        }

        self::renderNavigation($tab);
        ShortcodeCatalog::renderStyleGuideModal();
        if ($tab === self::TAB_COMPONENTS) {
            ComponentStyles::renderPage();
            return;
        }
        if ($tab === self::TAB_SHORTCODES) {
            self::renderShortcodeNavigation();
            ShortcodeCatalog::renderPage();
            return;
        }
        if ($tab === self::TAB_REDIRECTS) {
            ShortcodeCatalog::renderRedirectPage();
            return;
        }
        if ($tab === self::TAB_EMAILS) {
            PatientVerificationEmails::renderPage();
            return;
        }
        if ($tab === self::TAB_CUSTOM_CODE) {
            CustomCode::renderPage();
            return;
        }
        if ($tab === self::TAB_BOOKING_FORMS) {
            $bookingSection = sanitize_key((string) ($_GET['booking_section'] ?? 'guest'));
            if (!in_array($bookingSection, ['guest', 'patient', 'dynamic', 'aliases'], true)) {
                $bookingSection = 'guest';
            }
            self::renderBookingNavigation($bookingSection);
            PatientBookingForms::renderPage($bookingSection);
            return;
        }
        if ($tab === self::TAB_ONBOARDING) {
            PatientOnboarding::renderPage();
            return;
        }
        if ($tab === self::TAB_DASHBOARD_MODULES) {
            $section = sanitize_key((string) ($_GET['dashboard_section'] ?? self::DASHBOARD_APPOINTMENT_TYPES));
            self::renderDashboardNavigation($section);
            if ($section === self::DASHBOARD_APPOINTMENT_FORMS) {
                self::renderPlaceholder('Appointment Forms');
                return;
            }
            if ($section === self::DASHBOARD_PATIENT_DETAILS) {
                DashboardPatientDetails::renderPage();
                return;
            }
            if ($section === self::DASHBOARD_PATIENT_ATTACHMENTS) {
                PatientAttachmentsModule::renderPage();
                return;
            }
            if ($section === self::DASHBOARD_PATIENT_COMMUNICATIONS) {
                PatientCommunicationsModule::renderPage();
                return;
            }
            DashboardModules::renderPage();
            return;
        }

        $section = sanitize_key((string) ($_GET['forms_section'] ?? self::FORMS_PATIENT_DETAILS));
        if (!in_array($section, [self::FORMS_PATIENT_DETAILS, self::FORMS_PATIENT_FORMS, self::FORMS_PATIENT_FORM_TEMPLATES], true)) {
            $section = self::FORMS_PATIENT_DETAILS;
        }
        self::renderFormsNavigation($section);
        if ($section === self::FORMS_PATIENT_FORMS) {
            PatientFormHistoryForms::renderPage();
            return;
        }
        if ($section === self::FORMS_PATIENT_FORM_TEMPLATES) {
            PatientFormTemplateForms::renderPage();
            return;
        }
        PatientAccountForms::renderPage();
    }

    public static function renderNavigation(string $activeTab): void
    {
        echo AdminView::render('AccountBuilders/Views/navigation', [
            'activeTab' => $activeTab,
            'tabs' => [
                self::TAB_PATIENT_FORMS => 'Patient Forms',
                self::TAB_ONBOARDING => 'Onboarding',
                self::TAB_BOOKING_FORMS => 'Booking Forms',
                self::TAB_DASHBOARD_MODULES => 'Patient Dashboard',
                self::TAB_COMPONENTS => 'Component Styles',
                self::TAB_SHORTCODES => 'Shortcode Library',
                self::TAB_REDIRECTS => 'Redirects',
                self::TAB_EMAILS => 'Email Templates',
                self::TAB_CUSTOM_CODE => 'Custom Code',
            ],
            'url' => [self::class, 'url'],
        ]);
    }

    private static function renderDashboardNavigation(string $activeSection): void
    {
        $sections = [
            self::DASHBOARD_APPOINTMENT_TYPES => 'Appointment Types',
            self::DASHBOARD_APPOINTMENT_FORMS => 'Appointment Forms',
            self::DASHBOARD_PATIENT_DETAILS => 'Patient Details',
            self::DASHBOARD_PATIENT_ATTACHMENTS => 'Patient Attachments',
            self::DASHBOARD_PATIENT_COMMUNICATIONS => 'Communications',
        ];
        ?>
        <aside class="cliniko-template-builder-subnav">
            <h2>Patient Dashboard</h2>
            <nav aria-label="Dashboard sections">
                <?php foreach ($sections as $section => $label) : ?>
                    <a class="<?php echo $activeSection === $section ? 'current' : ''; ?>" href="<?php echo esc_url(self::url(self::TAB_DASHBOARD_MODULES, ['dashboard_section' => $section])); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <?php
    }

    private static function renderFormsNavigation(string $activeSection): void
    {
        $sections = [
            self::FORMS_PATIENT_DETAILS => 'Profile Forms',
            self::FORMS_PATIENT_FORMS => 'Completed Forms',
            self::FORMS_PATIENT_FORM_TEMPLATES => 'Cliniko Templates',
        ];
        ?>
        <aside class="cliniko-template-builder-subnav">
            <h2>Patient Forms</h2>
            <nav aria-label="Form sections">
                <?php foreach ($sections as $section => $label) : ?>
                    <a class="<?php echo $activeSection === $section ? 'current' : ''; ?>" href="<?php echo esc_url(self::url(self::TAB_PATIENT_FORMS, ['forms_section' => $section])); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <?php
    }

    private static function renderBookingNavigation(string $activeSection = 'guest'): void
    {
        ?>
        <aside class="cliniko-template-builder-subnav">
            <h2>Booking types</h2>
            <nav aria-label="Booking form sections">
                <a class="<?php echo $activeSection === 'guest' ? 'current' : ''; ?>" href="<?php echo esc_url(self::url(self::TAB_BOOKING_FORMS, ['booking_section' => 'guest'])); ?>">Guest booking</a>
                <a class="<?php echo $activeSection === 'patient' ? 'current' : ''; ?>" href="<?php echo esc_url(self::url(self::TAB_BOOKING_FORMS, ['booking_section' => 'patient'])); ?>">Logged-in patient booking</a>
                <a class="<?php echo $activeSection === 'dynamic' ? 'current' : ''; ?>" href="<?php echo esc_url(self::url(self::TAB_BOOKING_FORMS, ['booking_section' => 'dynamic'])); ?>">Dynamic shortcode</a>
                <a class="<?php echo $activeSection === 'aliases' ? 'current' : ''; ?>" href="<?php echo esc_url(self::url(self::TAB_BOOKING_FORMS, ['booking_section' => 'aliases'])); ?>">Booking aliases</a>
            </nav>
            <div class="cliniko-template-builder-tip">
                <span class="cliniko-info" tabindex="0" aria-label="Booking type help" data-tooltip="Guest booking collects patient details. Logged-in booking resolves the linked patient account on the server.">i</span>
                <span><?php echo $activeSection === 'guest' ? 'For visitors without an account.' : 'For authenticated patient accounts.'; ?></span>
            </div>
        </aside>
        <?php
    }

    private static function renderShortcodeNavigation(): void
    {
        ?>
        <aside class="cliniko-template-builder-subnav">
            <h2>Shortcode Library</h2>
            <nav aria-label="Shortcode library sections">
                <a href="#cliniko-shortcode-patient-data">Patient data</a>
                <a href="#cliniko-shortcode-appointments">Appointments</a>
                <a href="#cliniko-shortcode-experiences">Forms and bookings</a>
                <a href="#cliniko-shortcode-dashboard">Dashboard modules</a>
                <a href="#cliniko-shortcode-account">Account management</a>
            </nav>
            <div class="cliniko-template-builder-tip">
                <span class="cliniko-info" tabindex="0" aria-label="Shortcode library help" data-tooltip="Use the builders to create configurations. Use this page to find, copy, and manage the generated shortcodes.">i</span>
                <span>Build first, then copy the shortcode you need.</span>
            </div>
        </aside>
        <?php
    }

    private static function renderPlaceholder(string $title): void
    {
        ?>
        <div class="wrap"><h1><?php echo esc_html($title); ?></h1><p>This dashboard module will be implemented next.</p></div>
        <?php
    }

    public static function url(string $tab, array $args = []): string
    {
        return add_query_arg(
            array_merge(['page' => self::PAGE, 'builder_tab' => $tab], $args),
            admin_url('admin.php')
        );
    }
}
