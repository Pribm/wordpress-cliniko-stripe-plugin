<?php
namespace App;

use App\Admin\PluginFacade;



if (!defined('ABSPATH')) exit;

use App\Routes\ApiRoutes;

class Bootstrap {
    public static function init() {

        
        
        new ApiRoutes();
        PluginFacade::init();
    }
}
