<?php
if (!defined('ABSPATH')) exit;

use App\Config\ModuleConfig;

// Adiciona submenu para visualizar os módulos
add_action('admin_menu', function () {
  add_submenu_page(
    'wp-cliniko-stripe-settings', // parent slug
    'Available Modules',
    'Available Modules',
    'manage_options',
    'wp-cliniko-modules',
    'render_cliniko_modules_page'
  );
});

// Renderiza a tabela de módulos
function render_cliniko_modules_page() {
  $modules = \App\Config\ModuleConfig::getModules();
  ?>
  <div class="wrap">
    <h1>Cliniko Appointment Types (Modules)</h1>
    <p>These are the appointment types currently fetched from your Cliniko account.</p>

    <table class="widefat striped">
      <thead>
        <tr>
          <th>Module ID</th>
          <th>Name</th>
          <th>Duration (minutes)</th>
          <th>Price (cents)</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($modules)): ?>
          <tr><td colspan="4">No modules found. Check your API key or internet connection.</td></tr>
        <?php else: ?>
          <?php foreach ($modules as $id => $mod): ?>
            <tr>
              <td><?php echo esc_html($id); ?></td>
              <td><?php echo esc_html($mod['name']); ?></td>
              <td><?php echo esc_html($mod['duration']); ?></td>
              <td><?php echo esc_html($mod['price']); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php
}
