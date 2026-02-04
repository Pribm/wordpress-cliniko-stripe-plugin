<?php

use App\Widgets\Widgets;
/*
Plugin Name: WordPress Cliniko Stripe Plugin
Plugin URI: https://github.com/Pribm/wordpress-cliniko-stripe-plugin
Description: Integração entre Stripe e Cliniko via WordPress.
Author: Paulo Monteiro
Author URI: https://github.com/Pribm
Version: 1.4.6
GitHub Plugin URI: Pribm/wordpress-cliniko-stripe-plugin
Primary Branch: main
Release Asset: true
*/
defined('ABSPATH') || exit;
define('WP_CLINIKO_PLUGIN_VERSION', '1.4.6');

use Elementor\Plugin;



require_once  plugin_dir_path( __FILE__ ) . '/includes/action-scheduler/action-scheduler.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/Helpers/index.php';

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'pagamento_api_add_settings_link');

function pagamento_api_add_settings_link($links): array {
  $settings_link = '<a href="' . admin_url('admin.php?page=pagamento-api-settings') . '">Settings</a>';
  array_unshift($links, $settings_link);
  return $links;
}


add_action('elementor/widgets/register', [Widgets::class, 'register']);



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

// Extend CSP to allow Tyro Health checkout domains when present.
add_filter('wp_headers', function (array $headers): array {
  // Domains used by Tyro Health / Medipass SDK (staging + prod + CDN).
  $allow = [
    'https://test-tyro.mtf.gateway.mastercard.com', // Tyro staging checkout
    'https://tyro.gateway.mastercard.com',          // Tyro production checkout
    'https://unpkg.com',                            // SDK CDN
    'https://stg-api-au.medipass.io',               // Staging API (token mint)
    'https://api-au.medipass.io',                   // Production API
  ];

  // No existing CSP -> add a minimal connect-src only (non-invasive).
  if (!isset($headers['Content-Security-Policy'])) {
    $headers['Content-Security-Policy'] = "connect-src 'self' " . implode(' ', $allow);
    return $headers;
  }

  // If there is a CSP, try to append to connect-src; fall back to appending a new directive.
  $csp = $headers['Content-Security-Policy'];
  $pattern = '/connect-src\s+([^;]+);?/i';
  if (preg_match($pattern, $csp, $m)) {
    $current = $m[1];
    foreach ($allow as $domain) {
      if (strpos($current, $domain) === false) {
        $current .= ' ' . $domain;
      }
    }
    $csp = preg_replace($pattern, 'connect-src ' . $current . ';', $csp, 1);
  } else {
    $csp .= '; connect-src ' . implode(' ', $allow);
  }

  $headers['Content-Security-Policy'] = $csp;
  return $headers;
});
