<?php

namespace App\Admin\Modules;

if (!defined('ABSPATH')) exit;

class Credentials
{
    /** Option keys for reuse */
    private const OPT_APP_NAME = 'wp_cliniko_app_name';
    private const OPT_SHARD    = 'wp_cliniko_shard';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueScript']);
        add_action('wp_ajax_cliniko_get_and_save_businesses', [self::class, 'ajaxFetchBusinesses']);
    }

    /** Sanitizers */
    public static function sanitizeAppName($value): string
    {
        $value = sanitize_text_field((string)$value);
        $value = strtolower($value);
        return preg_replace('/[^a-z0-9-]/', '', $value) ?: '';
    }

    public static function sanitizeShard($value): string
    {
        $value = sanitize_text_field((string)$value);
        $value = strtolower($value);
        // shards like "au1", "us1", "eu2"
        if (!preg_match('/^[a-z]{2}\d+$/', $value)) {
            return 'au1';
        }
        return $value;
    }

    public static function registerSettings(): void
    {
        // NEW: App Name & Shard
        register_setting('wp_cliniko_stripe_group', self::OPT_APP_NAME, [
            'sanitize_callback' => [self::class, 'sanitizeAppName'],
        ]);
        register_setting('wp_cliniko_stripe_group', self::OPT_SHARD, [
            'sanitize_callback' => [self::class, 'sanitizeShard'],
            'default' => 'au1',
        ]);

        register_setting('wp_cliniko_stripe_group', 'wp_cliniko_api_key');
        register_setting('wp_cliniko_stripe_group', 'wp_cliniko_business_id');
        register_setting('wp_cliniko_stripe_group', 'wp_stripe_public_key');
        register_setting('wp_cliniko_stripe_group', 'wp_stripe_secret_key');

        add_settings_section(
            'wp_cliniko_stripe_section',
            'API Credentials',
            function () {
                echo '<p>Provide your Cliniko and Stripe credentials below.</p>';
            },
            'wp-cliniko-stripe-settings'
        );

        // NEW: Cliniko App Name
        add_settings_field(self::OPT_APP_NAME, 'Cliniko App Name', function () {
            $val = esc_attr(get_option(self::OPT_APP_NAME, ''));
            echo "<input type='text' id='" . esc_attr(self::OPT_APP_NAME) . "' name='" . esc_attr(self::OPT_APP_NAME) . "' value='{$val}' class='regular-text' placeholder='e.g. lorem-ipsum' />";
            echo "<p class='description'>Find it in your embed URL as the <strong>first subdomain</strong>.<br/>Example: <code>https://<strong>lorem-ipsum</strong>.au1.cliniko.com/...</code> → App Name: <code>lorem-ipsum</code>.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

        // NEW: Cliniko Shard (Region)
        add_settings_field(self::OPT_SHARD, 'Cliniko Shard (Region)', function () {
            $val = esc_attr(get_option(self::OPT_SHARD, 'au1'));
            echo "<input type='text' id='" . esc_attr(self::OPT_SHARD) . "' name='" . esc_attr(self::OPT_SHARD) . "' value='{$val}' class='regular-text' placeholder='e.g. au1' />";
            echo "<p class='description'>Find it in your embed URL as the <strong>middle part</strong>.<br/>Example: <code>https://lorem-ipsum.<strong>au1</strong>.cliniko.com/...</code> → Shard: <code>au1</code>.<br/>API host becomes <code>https://api.&lt;shard&gt;.cliniko.com</code>.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

        add_settings_field('wp_cliniko_api_key', 'Cliniko API Key', function () {
            echo "<input type='password' id='wp_cliniko_api_key' name='wp_cliniko_api_key' value='" . esc_attr(get_option('wp_cliniko_api_key')) . "' class='regular-text' />";
            echo "<p class='description'>Cliniko &rarr; <em>My info</em> &rarr; <em>API keys</em>.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

        add_settings_field('cliniko_connect_button', '', function () {
            echo "<button type='button' id='verify_cliniko_key' class='button'>Connect to Cliniko</button>";
            echo "<span id='cliniko_status' style='margin-left: 10px;'></span>";
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

        add_settings_field('wp_cliniko_business_id', 'Cliniko Business', function () {
            $saved = get_option('wp_cliniko_business_id');
            echo "<select name='wp_cliniko_business_id' id='wp_cliniko_business_id'>";
            if ($saved) {
                echo "<option selected value='" . esc_attr($saved) . "'>Selected Business (ID: " . esc_html($saved) . ")</option>";
            } else {
                echo "<option value=''>Select a business</option>";
            }
            echo "</select>";
            echo "<p class='description'>Click <em>Connect to Cliniko</em>, choose your business, then <em>Save</em>.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

        add_settings_field('wp_stripe_public_key', 'Stripe Public Key', function () {
            echo "<input type='text' name='wp_stripe_public_key' value='" . esc_attr(get_option('wp_stripe_public_key')) . "' class='regular-text' />";
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

        add_settings_field('wp_stripe_secret_key', 'Stripe Secret Key', function () {
            echo "<input type='password' name='wp_stripe_secret_key' value='" . esc_attr(get_option('wp_stripe_secret_key')) . "' class='regular-text' />";
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');
    }

    public static function enqueueScript(): void
    {
        wp_register_script('cliniko-settings-js', '', [], null, true);
        wp_enqueue_script('cliniko-settings-js');

        // Send shard along with API key (so you can test before saving)
        wp_add_inline_script('cliniko-settings-js', "
            document.addEventListener('DOMContentLoaded', function () {
              const connectBtn     = document.getElementById('verify_cliniko_key');
              const keyInput       = document.getElementById('wp_cliniko_api_key');
              const shardInput     = document.getElementById('" . esc_js(self::OPT_SHARD) . "'); // NEW
              const statusEl       = document.getElementById('cliniko_status');
              const businessSelect = document.getElementById('wp_cliniko_business_id');

              if (!connectBtn) return;

              connectBtn.addEventListener('click', function () {
                const key   = (keyInput?.value || '').trim();
                const shard = (shardInput?.value || '').trim();

                if (!key) {
                  alert('Please enter your Cliniko API key.');
                  return;
                }

                statusEl.style.color = 'inherit';
                statusEl.textContent = 'Connecting...';
                businessSelect.innerHTML = '<option>Loading...</option>';

                const body = new URLSearchParams({
                  action: 'cliniko_get_and_save_businesses',
                  key: key
                });
                if (shard) body.append('shard', shard); // NEW

                fetch(ajaxurl, {
                  method: 'POST',
                  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                  body
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
    }

    public static function ajaxFetchBusinesses(): void
    {
        $key = sanitize_text_field($_POST['key'] ?? '');
        if (!$key) {
            wp_send_json_error('Missing API key.');
        }

        // Prefer shard from POST (lets you test before saving), else saved option, fallback to 'au1'
        $postedShard = isset($_POST['shard']) ? self::sanitizeShard($_POST['shard']) : '';
        $shard       = $postedShard ?: get_option(self::OPT_SHARD, 'au1');
        $shard       = self::sanitizeShard($shard);

        $apiUrl = sprintf('https://api.%s.cliniko.com/v1/businesses', $shard);

        $response = wp_remote_get($apiUrl, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($key . ':'),
                'Accept'        => 'application/json',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Error connecting to Cliniko.');
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['businesses']) || empty($body['businesses'])) {
            wp_send_json_error('No businesses found.');
        }

        $businesses = array_map(function ($b) {
            return [
                'id'   => $b['id'],
                'name' => $b['business_name']
            ];
        }, $body['businesses']);

        wp_send_json_success($businesses);
    }

    /* ---------- Helpers you can reuse elsewhere ---------- */

    public static function getAppName(): ?string
    {
        $v = self::sanitizeAppName(get_option(self::OPT_APP_NAME, ''));
        return $v !== '' ? $v : null;
    }

    public static function getShard(): string
    {
        return self::sanitizeShard(get_option(self::OPT_SHARD, 'au1'));
    }

    public static function getApiBase(): string
    {
        return 'https://api.' . self::getShard() . '.cliniko.com';
    }

    public static function getEmbedHost(): ?string
    {
        $app = self::getAppName();
        if (!$app) return null;
        return $app . '.' . self::getShard() . '.cliniko.com';
    }
}
