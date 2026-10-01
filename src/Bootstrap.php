<?php
namespace App;

use ActionScheduler;
use App\Admin\PluginFacade;
use App\Debug\Runtime as DebugRuntime;
use App\Service\PatientService;
use App\Service\PatientLinkService;
use App\Support\NextendRedirectCompatibility;
use App\Support\PatientDataRedirect;
use App\Support\ShortcodeConnectionNotice;
use App\Support\UltimateMemberDependency;



if (!defined('ABSPATH')) exit;

use App\Routes\ApiRoutes;

class Bootstrap {
    public static function init() {
        DebugRuntime::init();
        UltimateMemberDependency::init();
        if (!UltimateMemberDependency::isActive()) {
            return;
        }
        PatientDataRedirect::init();
        ShortcodeConnectionNotice::init();
        NextendRedirectCompatibility::init();

        PatientService::registerHooks();
        PatientLinkService::registerHooks();

        new ApiRoutes();
        PluginFacade::init();
    }
}
