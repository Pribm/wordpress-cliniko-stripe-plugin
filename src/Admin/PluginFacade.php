<?php

namespace App\Admin;

use App\Admin\Modules\AccountBuilders\AccountBuilders;
use App\Admin\Modules\Settings\Credentials;
use App\Admin\Modules\Debug;
use App\Admin\Modules\Integrations\ElementorTemplateSync;
use App\Admin\Modules\AccountBuilders\Forms\PatientAccountForms;
use App\Admin\Modules\AccountBuilders\Forms\PatientOnboarding;
use App\Admin\Modules\AccountBuilders\Forms\PatientFormHistoryForms;
use App\Admin\Modules\AccountBuilders\Forms\PatientFormTemplateForms;
use App\Admin\Modules\AccountBuilders\Dashboard\DashboardModules;
use App\Admin\Modules\AccountBuilders\Dashboard\DashboardPatientDetails;
use App\Admin\Modules\AccountBuilders\Dashboard\PatientAttachmentsModule;
use App\Admin\Modules\AccountBuilders\Dashboard\PatientCommunicationsModule;
use App\Admin\Modules\Settings\PatientSyncSettings;
use App\Admin\Modules\AccountBuilders\Emails\PatientVerificationEmails;
use App\Admin\Modules\PatientBookingForms;
use App\Admin\Modules\AccountBuilders\Shortcodes\ShortcodeCatalog;
use App\Admin\Modules\Settings\Settings;
use App\Admin\Modules\Tools;
use App\Admin\Modules\UserGuide;
use App\Workers\BookingAttemptCleanupWorker;
use App\Workers\ClinikoPatientFormWorker;
use App\Workers\ClinikoSchedulingWorker;
use App\Widgets\ClinikoForm\Webhooks\WebhookDeliveryWorker;
use App\Admin\Modules\AccountBuilders\Shortcodes\PatientVariables;
use App\Admin\Modules\AccountBuilders\Shortcodes\PatientCommunicationUnread;
use App\Admin\Modules\AccountBuilders\Shortcodes\AppointmentCountVariables;
use App\Admin\Modules\AccountBuilders\Shortcodes\PatientAccountClosure;
use App\Admin\Modules\AccountBuilders\Shortcodes\PatientVerificationConfirmation;
use App\Admin\Modules\AccountBuilders\Dashboard\PatientAttachments\ShortCodeTemplates;
use App\Admin\Modules\AccountBuilders\Dashboard\PatientCommunications\ShortCodeTemplates as PatientCommunicationShortCodeTemplates;



if (!defined('ABSPATH')) {
    exit;
}

/**
 * Facade class responsible for initializing all Admin-related modules.
 *
 * This class serves as a central entry point to load and configure
 * WordPress admin menus, settings pages, and related modules.
 *
 * Usage:
 *   \App\Admin\AdminFacade::init();
 */
class PluginFacade
{
    /**
     * Initializes all admin-related modules.
     *
     * This provides a single point of access to setup admin UI and functionality.
     */
    public static function init(): void
    {

        add_action('init', function () {
            BookingAttemptCleanupWorker::register();
            ClinikoSchedulingWorker::register();
            ClinikoPatientFormWorker::register();
            WebhookDeliveryWorker::register();
        });

        ElementorTemplateSync::init();
        Settings::init();
        Credentials::init();
        AccountBuilders::init();
        ShortcodeCatalog::init();
        PatientAccountForms::init();
        PatientOnboarding::init();
        PatientFormHistoryForms::init();
        PatientFormTemplateForms::init();
        DashboardModules::init();
        DashboardPatientDetails::init();
        PatientAttachmentsModule::init();
        ShortCodeTemplates::init();
        PatientCommunicationsModule::init();
        PatientCommunicationShortCodeTemplates::init();
        PatientVariables::init();
        PatientCommunicationUnread::init();
        AppointmentCountVariables::init();
        PatientAccountClosure::init();
        PatientVerificationConfirmation::init();
        PatientSyncSettings::init();
        PatientVerificationEmails::init();
        PatientBookingForms::init();
        Tools::init();
        Debug::init();
        UserGuide::init();
    }
}
