<?php

namespace App\Admin;

use App\Admin\Modules\AppointmentTypes;
use App\Admin\Modules\Credentials;
use App\Admin\Modules\ElementorTemplateSync;
use App\Admin\Modules\PatientForms;
use App\Admin\Modules\Settings;
use App\Admin\Modules\Tools;
use App\Workers\ClinikoSchedulingWorker;



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
            ClinikoSchedulingWorker::register();
        });

        ElementorTemplateSync::init();
        Settings::init();
        Credentials::init();
        AppointmentTypes::init();
        PatientForms::init();
        Tools::init();
    }
}
