<?php
namespace App;

use ActionScheduler;
use App\Admin\PluginFacade;
use App\Debug\Runtime as DebugRuntime;



if (!defined('ABSPATH')) exit;

use App\Routes\ApiRoutes;

class Bootstrap {
    public static function init() {
        DebugRuntime::init();

        new ApiRoutes();
        PluginFacade::init();
    }
}
