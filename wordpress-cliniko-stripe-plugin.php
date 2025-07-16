<?php

use App\Widgets\ClinikoForm\Widget;

defined('ABSPATH') || exit;

/*
Plugin Name: WordPress Cliniko Stripe Plugin
Plugin URI: https://github.com/Pribm/wordpress-cliniko-stripe-plugin
Description: Integração entre Stripe e Cliniko via WordPress.
Author: Paulo Monteiro
Author URI: https://github.com/Pribm
Version: 1.2.3

GitHub Plugin URI: Pribm/wordpress-cliniko-stripe-plugin
Primary Branch: main
Release Asset: true
*/

use Elementor\Plugin;
use App\Widgets\ClinikoForm\Widget as ClinikoStripeForm;
use App\Widgets\AppointmentTypeCard\Widget as AppointmentTypeCard;

require_once __DIR__ . '/admin/menu.php';
require_once __DIR__ . '/admin/settings-credentials.php';
require_once __DIR__ . '/admin/settings-view-module.php';
require_once __DIR__ . '/admin/patient-form-view-module.php';

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Helpers/index.php';

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pagamento_api_add_settings_link');

function pagamento_api_add_settings_link($links): array {
  $settings_link = '<a href="' . admin_url('admin.php?page=pagamento-api-settings') . '">Settings</a>';
  array_unshift($links, $settings_link);
  return $links;
}


add_action('elementor/widgets/register', function ($widgets_manager) {
  $widgets_manager->register(new ClinikoStripeForm());
  $widgets_manager->register(new AppointmentTypeCard());
});


add_action('elementor/editor/after_enqueue_scripts', function () {
  // @phpstan-ignore-next-line
  if (Plugin::$instance->editor->is_edit_mode()) {
    wp_enqueue_script(
      'appointment-card-panel-sync',
      plugin_dir_url(__FILE__) . 'src/Widgets/assets/js/card-button-sync.js',
      ['jquery'],
      null,
      true
    );
  }
});

App\Bootstrap::init();
