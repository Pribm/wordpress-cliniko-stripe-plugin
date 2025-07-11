<?php
/*
Plugin Name: Cliniko Stripe Integration
Plugin URI: https://github.com/Pribm/wordpress-cliniko-stripe-plugin
Description: Integração entre Stripe e Cliniko via WordPress.
Author: Paulo Monteiro
Author URI: https://github.com/Pribm
GitHub Plugin URI: https://github.com/Pribm/wordpress-cliniko-stripe-plugin
Release Asset: true
Primary Branch: main
 * Version: 1.0.2
*/

use App\Widgets\ClinikoStripeWidget;


defined('ABSPATH') || exit;


require_once __DIR__ . '/admin/menu.php';
require_once __DIR__ . '/admin/settings-credentials.php';
require_once __DIR__ . '/admin/settings-view-module.php';
require_once __DIR__ . '/admin/patient-form-view-module.php';

require_once __DIR__ . '/vendor/autoload.php';

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pagamento_api_add_settings_link');

function pagamento_api_add_settings_link($links): array {
  $settings_link = '<a href="' . admin_url('admin.php?page=pagamento-api-settings') . '">Settings</a>';
  array_unshift($links, $settings_link);
  return $links;
}


// require_once plugin_dir_path(__FILE__) . 'src/admin-settings.php';

add_action('elementor/widgets/register', function ($widgets_manager) {
  //require_once plugin_dir_path(__FILE__) . 'src/Widgets/class-widget-cliniko-stripe.php';
  $widgets_manager->register(new ClinikoStripeWidget());
});

App\Bootstrap::init();
