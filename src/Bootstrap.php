<?php
namespace App;
if (!defined('ABSPATH')) exit;


use App\Routes\ApiRoutes;

class Bootstrap {
  public static function init() {
    new ApiRoutes();
  }
}
