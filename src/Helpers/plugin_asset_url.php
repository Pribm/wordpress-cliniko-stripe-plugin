<?php
if (!function_exists('plugin_asset_url')) {
  function plugin_asset_url(string $path = ''): string {
    // Altere para o nome do seu plugin principal, se diferente
    $plugin_main_file = dirname(__DIR__, 2) . '/wordpress-cliniko-stripe-plugin.php';
    return plugins_url($path, $plugin_main_file);
  }
}