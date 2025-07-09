<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
  add_menu_page(
    'Cliniko + Stripe Integration',
    'Cliniko + Stripe',
    'manage_options',
    'wp-cliniko-stripe-settings',
    'render_wp_cliniko_stripe_settings',
    'dashicons-admin-links'
  );
});

function render_wp_cliniko_stripe_settings() {
  ?>
  <div class="wrap">
    <h1>Cliniko + Stripe Integration Settings</h1>
    <form method="post" action="options.php">
      <?php
        settings_fields('wp_cliniko_stripe_group');
        do_settings_sections('wp-cliniko-stripe-settings');
        submit_button('Save Settings');
      ?>
    </form>
  </div>
  <?php
}
