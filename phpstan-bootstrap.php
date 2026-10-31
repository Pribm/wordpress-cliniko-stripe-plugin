<?php
if (!defined('WP_PLUGIN_DIR')) {
    define('WP_PLUGIN_DIR', __DIR__);
}

require_once __DIR__ . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php';
// These are defined by wp_initial_constants()/wp-db.php in a normal WordPress boot.
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
require_once __DIR__ . '/stubs/elementor-stubs.php';
