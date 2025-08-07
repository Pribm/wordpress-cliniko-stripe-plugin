<?php
if (!defined('ABSPATH')) exit;


add_action('admin_init', function () {
  register_setting('wp_cliniko_stripe_group', 'wp_cliniko_api_key');
  register_setting('wp_cliniko_stripe_group', 'wp_cliniko_business_id');
  register_setting('wp_cliniko_stripe_group', 'wp_stripe_public_key');
  register_setting('wp_cliniko_stripe_group', 'wp_stripe_secret_key');

  add_settings_section(
    'wp_cliniko_stripe_section',
    'API Credentials',
    function () {
      echo '<p>Provide your Cliniko and Stripe API keys below.</p>';
    },
    'wp-cliniko-stripe-settings'
  );

  add_settings_field('wp_cliniko_api_key', 'Cliniko API Key', function () {
    echo "<input type='password' id='wp_cliniko_api_key' name='wp_cliniko_api_key' value='" . esc_attr(get_option('wp_cliniko_api_key')) . "' class='regular-text' />";
  }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

  add_settings_field('cliniko_connect_button', '', function () {
    echo "<button type='button' id='verify_cliniko_key' class='button'>Connect to Cliniko</button>";
    echo "<span id='cliniko_status' style='margin-left: 10px;'></span>";
  }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

  add_settings_field('wp_cliniko_business_id', 'Cliniko Business', function () {
    $saved = get_option('wp_cliniko_business_id');
    echo "<select name='wp_cliniko_business_id' id='wp_cliniko_business_id'>";
    if ($saved) {
      echo "<option selected value='$saved'>Selected Business (ID: $saved)</option>";
    } else {
      echo "<option value=''>Select a business</option>";
    }
    echo "</select>";
  }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

  add_settings_field('wp_stripe_public_key', 'Stripe Public Key', function () {
    echo "<input type='text' name='wp_stripe_public_key' value='" . esc_attr(get_option('wp_stripe_public_key')) . "' class='regular-text' />";
  }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

  add_settings_field('wp_stripe_secret_key', 'Stripe Secret Key', function () {
    echo "<input type='password' name='wp_stripe_secret_key' value='" . esc_attr(get_option('wp_stripe_secret_key')) . "' class='regular-text' />";
  }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');
});

add_action('admin_enqueue_scripts', function () {
  wp_register_script('cliniko-settings-js', '', [], null, true);
  wp_enqueue_script('cliniko-settings-js');

  wp_add_inline_script('cliniko-settings-js', "
    document.addEventListener('DOMContentLoaded', function () {
      const connectBtn = document.getElementById('verify_cliniko_key');
      const keyInput = document.getElementById('wp_cliniko_api_key');
      const statusEl = document.getElementById('cliniko_status');
      const businessSelect = document.getElementById('wp_cliniko_business_id');

      connectBtn.addEventListener('click', function () {
        const key = keyInput.value.trim();
        if (!key) {
          alert('Please enter your Cliniko API key.');
          return;
        }

        statusEl.style.color = 'inherit';
        statusEl.textContent = 'Connecting...';
        businessSelect.innerHTML = '<option>Loading...</option>';

        fetch(ajaxurl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'cliniko_get_and_save_businesses',
            key: key
          })
        })
        .then(res => res.json())
        .then(data => {
          if (!data.success || !Array.isArray(data.data)) {
            statusEl.style.color = 'red';
            statusEl.textContent = 'Connection failed.';
            businessSelect.innerHTML = '<option value=\"\">No businesses found</option>';
            return;
          }

          businessSelect.innerHTML = '';
          data.data.forEach(b => {
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = b.name;
            businessSelect.appendChild(opt);
          });

          statusEl.style.color = 'green';
          statusEl.textContent = 'Connected! Select a business and save.';
        })
        .catch(() => {
          statusEl.style.color = 'red';
          statusEl.textContent = 'Error connecting to Cliniko.';
          businessSelect.innerHTML = '<option value=\"\">Error</option>';
        });
      });
    });
  ");
});

add_action('wp_ajax_cliniko_get_and_save_businesses', function () {
  $key = sanitize_text_field($_POST['key'] ?? '');
  if (!$key) {
    wp_send_json_error('Missing API key.');
  }

  $response = wp_remote_get('https://api.au4.cliniko.com/v1/businesses', [
    'headers' => [
      'Authorization' => 'Basic ' . base64_encode($key . ':'),
      'Accept' => 'application/json'
    ]
  ]);

  if (is_wp_error($response)) {
    wp_send_json_error('Error connecting to Cliniko.');
  }

  $body = json_decode(wp_remote_retrieve_body($response), true);
  if (!isset($body['businesses']) || empty($body['businesses'])) {
    wp_send_json_error('No businesses found.');
  }


  $businesses = array_map(function ($b) {
    return ['id' => $b['id'], 'name' => $b['business_name']];
  }, $body['businesses']);

  wp_send_json_success($businesses);
});
