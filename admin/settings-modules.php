<?php
if (!defined('ABSPATH')) exit;

add_action('admin_init', function () {
  register_setting('wp_cliniko_stripe_group', 'wp_cliniko_modules');

  add_settings_section(
    'wp_cliniko_modules_section',
    'Service Modules',
    function () {
      echo '<p>Add or remove service modules offered for booking.</p>';
    },
    'wp-cliniko-stripe-settings'
  );

  add_settings_field('wp_cliniko_modules_field', 'Modules Configuration', function () {
    $modules = get_option('wp_cliniko_modules', []);
    if (!is_array($modules)) $modules = [];

    echo '<div id="cliniko-modules-wrapper">';

    foreach ($modules as $key => $module) {
      echo '<fieldset style="margin-bottom:20px;padding:10px;border:1px solid #ccc;">
        <label>Key: <input type="text" name="wp_cliniko_modules[' . esc_attr($key) . '][key]" value="' . esc_attr($key) . '" /></label><br/>
        <label>Name: <input type="text" name="wp_cliniko_modules[' . esc_attr($key) . '][name]" value="' . esc_attr($module['name']) . '" /></label><br/>
        <label>Price (in cents): <input type="number" name="wp_cliniko_modules[' . esc_attr($key) . '][price]" value="' . esc_attr($module['price']) . '" /></label><br/>
        <label>Duration (minutes): <input type="number" name="wp_cliniko_modules[' . esc_attr($key) . '][duration]" value="' . esc_attr($module['duration']) . '" /></label><br/>
        <label>Appointment Type ID: <input type="number" name="wp_cliniko_modules[' . esc_attr($key) . '][appointment_type_id]" value="' . esc_attr($module['appointment_type_id']) . '" /></label><br/>
        <label>Practitioner ID: <input type="number" name="wp_cliniko_modules[' . esc_attr($key) . '][practitioner_id]" value="' . esc_attr($module['practitioner_id']) . '" /></label><br/>
        <label>Required Fields (comma-separated): <input type="text" name="wp_cliniko_modules[' . esc_attr($key) . '][required_fields]" value="' . esc_attr(implode(',', $module['required_fields'])) . '" /></label><br/>
        <button type="button" class="remove-module">Remove</button>
      </fieldset>';
    }

    echo '</div>';
    echo '<button type="button" id="add-module">Add Module</button>';

    

    ?>
    <script>
    document.getElementById('add-module').addEventListener('click', function () {
      const wrapper = document.getElementById('cliniko-modules-wrapper');
      const index = Date.now();
      const html = `
        <fieldset style="margin-bottom:20px;padding:10px;border:1px solid #ccc;">
          <label>Key: <input type="text" name="wp_cliniko_modules[\${index}][key]" /></label><br/>
          <label>Name: <input type="text" name="wp_cliniko_modules[\${index}][name]" /></label><br/>
          <label>Price (in cents): <input type="number" name="wp_cliniko_modules[\${index}][price]" /></label><br/>
          <label>Duration (minutes): <input type="number" name="wp_cliniko_modules[\${index}][duration]" /></label><br/>
          <label>Appointment Type ID: <input type="number" name="wp_cliniko_modules[\${index}][appointment_type_id]" /></label><br/>
          <label>Practitioner ID: <input type="number" name="wp_cliniko_modules[\${index}][practitioner_id]" /></label><br/>
          <label>Required Fields (comma-separated): <input type="text" name="wp_cliniko_modules[\${index}][required_fields]" /></label><br/>
          <button type="button" class="remove-module">Remove</button>
        </fieldset>`;
      wrapper.insertAdjacentHTML('beforeend', html);
    });

    document.addEventListener('click', function (e) {
      if (e.target.classList.contains('remove-module')) {
        e.target.closest('fieldset').remove();
      }
    });
    </script>
    <?php
  }, 'wp-cliniko-stripe-settings', 'wp_cliniko_modules_section');
});
