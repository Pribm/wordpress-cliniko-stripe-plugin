<?php

namespace App\Admin\Modules;

if (!defined('ABSPATH')) exit;

class Credentials
{
    /** Existing option keys */
    private const OPT_APP_NAME = 'wp_cliniko_app_name';
    private const OPT_SHARD    = 'wp_cliniko_shard';

    /** NEW: Tyro Health option keys */
    private const OPT_TYRO_ENV          = 'wp_tyro_env';                 // stg|prod
    private const OPT_TYRO_APP_ID       = 'wp_tyro_app_id';              // x-appid
    private const OPT_TYRO_ADMIN_APIKEY = 'wp_tyro_admin_api_key';       // Business Admin API key
    private const OPT_TYRO_APP_VERSION  = 'wp_tyro_app_version';         // appVersion
    private const OPT_TYRO_PROVIDER_NO  = 'wp_tyro_provider_number';     // optional

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueScript']);

        add_action('wp_ajax_cliniko_get_and_save_businesses', [self::class, 'ajaxFetchBusinesses']);

        // NEW
        add_action('wp_ajax_tyrohealth_verify_credentials', [self::class, 'ajaxVerifyTyrohealth']);
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

    // NEW
    public static function sanitizeTyroEnv($value): string
    {
        $v = strtolower(trim(sanitize_text_field((string)$value)));
        return in_array($v, ['stg', 'prod'], true) ? $v : 'stg';
    }

    public static function sanitizeTyroAppId($value): string
    {
        // keep it permissive (IDs vary). Just trim and sanitize text.
        return trim(sanitize_text_field((string)$value));
    }

    public static function sanitizeTyroApiKey($value): string
    {
        // Don't mangle secrets too much; keep it safe-ish.
        // sanitize_text_field strips some characters, but is typically OK for keys.
        // If your key includes unusual chars, replace with trim((string)$value) instead.
        return trim(sanitize_text_field((string)$value));
    }

    public static function sanitizeTyroAppVersion($value): string
    {
        return trim(sanitize_text_field((string)$value));
    }

    public static function sanitizeTyroProviderNumber($value): string
    {
        // provider number formats vary; allow digits/letters/dash/space
        $v = trim(sanitize_text_field((string)$value));
        return preg_replace('/[^a-zA-Z0-9\- ]/', '', $v);
    }

    public static function registerSettings(): void
    {
        // Existing
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

        // NEW: Tyro Health settings
        register_setting('wp_cliniko_stripe_group', self::OPT_TYRO_ENV, [
            'sanitize_callback' => [self::class, 'sanitizeTyroEnv'],
            'default' => 'stg',
        ]);
        register_setting('wp_cliniko_stripe_group', self::OPT_TYRO_APP_ID, [
            'sanitize_callback' => [self::class, 'sanitizeTyroAppId'],
        ]);
        register_setting('wp_cliniko_stripe_group', self::OPT_TYRO_ADMIN_APIKEY, [
            'sanitize_callback' => [self::class, 'sanitizeTyroApiKey'],
        ]);
        register_setting('wp_cliniko_stripe_group', self::OPT_TYRO_APP_VERSION, [
            'sanitize_callback' => [self::class, 'sanitizeTyroAppVersion'],
            'default' => 'wp-dev',
        ]);
        register_setting('wp_cliniko_stripe_group', self::OPT_TYRO_PROVIDER_NO, [
            'sanitize_callback' => [self::class, 'sanitizeTyroProviderNumber'],
        ]);

        // Existing section
        add_settings_section(
            'wp_cliniko_stripe_section',
            'API Credentials',
            function () {
                echo '<p>Provide your Cliniko and Stripe credentials below.</p>';
            },
            'wp-cliniko-stripe-settings'
        );

        // Existing fields...
        add_settings_field(self::OPT_APP_NAME, 'Cliniko App Name', function () {
            $val = esc_attr(get_option(self::OPT_APP_NAME, ''));
            echo "<input type='text' id='" . esc_attr(self::OPT_APP_NAME) . "' name='" . esc_attr(self::OPT_APP_NAME) . "' value='{$val}' class='regular-text' placeholder='e.g. lorem-ipsum' />";
            echo "<p class='description'>Find it in your embed URL as the <strong>first subdomain</strong>.<br/>Example: <code>https://<strong>lorem-ipsum</strong>.au1.cliniko.com/...</code> → App Name: <code>lorem-ipsum</code>.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

        add_settings_field(self::OPT_SHARD, 'Cliniko Shard (Region)', function () {
            $val = esc_attr(get_option(self::OPT_SHARD, 'au1'));
            echo "<input type='text' id='" . esc_attr(self::OPT_SHARD) . "' name='" . esc_attr(self::OPT_SHARD) . "' value='{$val}' class='regular-text' placeholder='e.g. au1' />";
            echo "<p class='description'>Find it in your embed URL as the <strong>middle part</strong>.<br/>Example: <code>https://lorem-ipsum.<strong>au1</strong>.cliniko.com/...</code> → Shard: <code>au1</code>.<br/>API host becomes <code>https://api.&lt;shard&gt;.cliniko.com</code>.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_stripe_section');

        add_settings_field('wp_cliniko_api_key', 'Cliniko API Key', function () {
            echo "<input type='password' id='wp_cliniko_api_key' name='wp_cliniko_api_key' value='" . esc_attr(get_option('wp_cliniko_api_key')) . "' class='regular-text' />";
            echo "<p class='description'>Cliniko → <em>My info</em> → <em>API keys</em>.</p>";
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

        /**
         * NEW SECTION: Tyro Health (THOP)
         */
        add_settings_section(
            'wp_tyrohealth_section',
            'Tyro Health (THOP) Credentials',
            function () {
                echo '<p>Provide your Tyro Health credentials used to generate a short-lived SDK token and run THOP transactions.</p>';
                echo '<p class="description">Token endpoint uses <code>POST /v3/auth/token</code> with headers <code>Authorization: Bearer ...</code> and <code>x-appid</code>.</p>';
            },
            'wp-cliniko-stripe-settings'
        );

        add_settings_field(self::OPT_TYRO_ENV, 'Tyro Environment', function () {
            $val = esc_attr(get_option(self::OPT_TYRO_ENV, 'stg'));
            echo "<select id='" . esc_attr(self::OPT_TYRO_ENV) . "' name='" . esc_attr(self::OPT_TYRO_ENV) . "'>";
            echo "<option value='stg' " . selected($val, 'stg', false) . ">Staging (stg)</option>";
            echo "<option value='prod' " . selected($val, 'prod', false) . ">Production (prod)</option>";
            echo "</select>";
            echo "<p class='description'>Staging base: <code>https://stg-api-au.medipass.io</code>. Production base: <code>https://api-au.medipass.io</code>.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_tyrohealth_section');

        add_settings_field(self::OPT_TYRO_APP_ID, 'Tyro App ID (x-appid)', function () {
            $val = esc_attr(get_option(self::OPT_TYRO_APP_ID, ''));
            echo "<input type='text' id='" . esc_attr(self::OPT_TYRO_APP_ID) . "' name='" . esc_attr(self::OPT_TYRO_APP_ID) . "' value='{$val}' class='regular-text' placeholder='Your App ID' />";
            echo "<p class='description'>Provided by Tyro Health (Partnerships). Used as <code>x-appid</code>.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_tyrohealth_section');

        add_settings_field(self::OPT_TYRO_ADMIN_APIKEY, 'Tyro API Key (Business Admin)', function () {
            $val = esc_attr(get_option(self::OPT_TYRO_ADMIN_APIKEY, ''));
            echo "<input type='password' id='" . esc_attr(self::OPT_TYRO_ADMIN_APIKEY) . "' name='" . esc_attr(self::OPT_TYRO_ADMIN_APIKEY) . "' value='{$val}' class='regular-text' autocomplete='off' />";
            echo "<p class='description'>Used server-side to mint short-lived SDK tokens. Do not expose this key in JavaScript.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_tyrohealth_section');

        add_settings_field(self::OPT_TYRO_APP_VERSION, 'Tyro App Version', function () {
            $val = esc_attr(get_option(self::OPT_TYRO_APP_VERSION, 'wp-dev'));
            echo "<input type='text' id='" . esc_attr(self::OPT_TYRO_APP_VERSION) . "' name='" . esc_attr(self::OPT_TYRO_APP_VERSION) . "' value='{$val}' class='regular-text' placeholder='e.g. wp-1.2.3' />";
            echo "<p class='description'>Passed to the SDK as <code>appVersion</code>.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_tyrohealth_section');

        add_settings_field(self::OPT_TYRO_PROVIDER_NO, 'Tyro Provider Number (optional)', function () {
            $val = esc_attr(get_option(self::OPT_TYRO_PROVIDER_NO, ''));
            echo "<input type='text' id='" . esc_attr(self::OPT_TYRO_PROVIDER_NO) . "' name='" . esc_attr(self::OPT_TYRO_PROVIDER_NO) . "' value='{$val}' class='regular-text' placeholder='Provider number (optional)' />";
            echo "<p class='description'>Optional. If set, you can pass it into the SDK transaction payload.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_tyrohealth_section');

        add_settings_field('tyrohealth_test_button', '', function () {
            echo "<button type='button' id='verify_tyrohealth' class='button'>Test Tyro Health</button>";
            echo "<span id='tyrohealth_status' style='margin-left: 10px;'></span>";
            echo "<p class='description'>This will attempt to mint a short-lived SDK token via <code>/v3/auth/token</code>.</p>";
        }, 'wp-cliniko-stripe-settings', 'wp_tyrohealth_section');
    }

    public static function enqueueScript(): void
    {
        wp_register_script('cliniko-settings-js', '', [], null, true);
        wp_enqueue_script('cliniko-settings-js');

        wp_add_inline_script('cliniko-settings-js', "
            document.addEventListener('DOMContentLoaded', function () {
              // ----- Cliniko connect -----
              const connectBtn     = document.getElementById('verify_cliniko_key');
              const keyInput       = document.getElementById('wp_cliniko_api_key');
              const shardInput     = document.getElementById('" . esc_js(self::OPT_SHARD) . "');
              const statusEl       = document.getElementById('cliniko_status');
              const businessSelect = document.getElementById('wp_cliniko_business_id');

              if (connectBtn) {
                connectBtn.addEventListener('click', function () {
                  const key   = (keyInput?.value || '').trim();
                  const shard = (shardInput?.value || '').trim();

                  if (!key) { alert('Please enter your Cliniko API key.'); return; }

                  statusEl.style.color = 'inherit';
                  statusEl.textContent = 'Connecting...';
                  businessSelect.innerHTML = '<option>Loading...</option>';

                  const body = new URLSearchParams({
                    action: 'cliniko_get_and_save_businesses',
                    key: key
                  });
                  if (shard) body.append('shard', shard);

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
              }

              // ----- Tyro Health test -----
              const tyroBtn = document.getElementById('verify_tyrohealth');
              const tyroStatus = document.getElementById('tyrohealth_status');
              const tyroEnv = document.getElementById('" . esc_js(self::OPT_TYRO_ENV) . "');
              const tyroAppId = document.getElementById('" . esc_js(self::OPT_TYRO_APP_ID) . "');
              const tyroKey = document.getElementById('" . esc_js(self::OPT_TYRO_ADMIN_APIKEY) . "');

              if (tyroBtn) {
                tyroBtn.addEventListener('click', function () {
                  const env = (tyroEnv?.value || 'stg').trim();
                  const appId = (tyroAppId?.value || '').trim();
                  const key = (tyroKey?.value || '').trim();

                  if (!appId) { alert('Please enter Tyro App ID.'); return; }
                  if (!key) { alert('Please enter Tyro API key.'); return; }

                  tyroStatus.style.color = 'inherit';
                  tyroStatus.textContent = 'Testing...';

                  const body = new URLSearchParams({
                    action: 'tyrohealth_verify_credentials',
                    env: env,
                    appId: appId,
                    key: key
                  });

                  fetch(ajaxurl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body
                  })
                  .then(res => res.json())
                  .then(data => {
                    if (!data.success) {
                      tyroStatus.style.color = 'red';
                      tyroStatus.textContent = 'Failed: ' + (data.data || 'Unknown error');
                      return;
                    }
                    tyroStatus.style.color = 'green';
                    tyroStatus.textContent = 'OK! Token minted successfully.';
                  })
                  .catch(() => {
                    tyroStatus.style.color = 'red';
                    tyroStatus.textContent = 'Error testing Tyro Health.';
                  });
                });
              }
            });
        ");
    }

    public static function ajaxFetchBusinesses(): void
    {
        $key = sanitize_text_field($_POST['key'] ?? '');
        if (!$key) {
            wp_send_json_error('Missing API key.');
        }

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

    // NEW: Test token minting with /v3/auth/token
    public static function ajaxVerifyTyrohealth(): void
    {
        $env  = self::sanitizeTyroEnv($_POST['env'] ?? 'stg');
        $appId = self::sanitizeTyroAppId($_POST['appId'] ?? '');
        $key  = self::sanitizeTyroApiKey($_POST['key'] ?? '');

        if (!$appId) wp_send_json_error('Missing App ID.');
        if (!$key)   wp_send_json_error('Missing API key.');

        $base = ($env === 'prod') ? 'api-au.medipass.io' : 'stg-api-au.medipass.io';
        $url  = 'https://' . $base . '/v3/auth/token';

        $payload = [
            'audience'  => 'aud:business-sdk',
            'expiresIn' => '1h',
        ];

        $res = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'x-appid'       => $appId,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 20,
        ]);

        if (is_wp_error($res)) {
            wp_send_json_error('Request error: ' . $res->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true);

        if ($code < 200 || $code >= 300 || empty($body['token'])) {
            $msg = $body['message'] ?? ('HTTP ' . $code);
            wp_send_json_error('Token mint failed: ' . $msg);
        }

        // Don’t return the token to admin UI
        wp_send_json_success(true);
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

    // NEW: Tyro helpers
    public static function getTyroEnv(): string
    {
        return self::sanitizeTyroEnv(get_option(self::OPT_TYRO_ENV, 'stg'));
    }

    public static function getTyroAppId(): ?string
    {
        $v = self::sanitizeTyroAppId(get_option(self::OPT_TYRO_APP_ID, ''));
        return $v !== '' ? $v : null;
    }

    public static function getTyroAdminApiKey(): ?string
    {
        $v = self::sanitizeTyroApiKey(get_option(self::OPT_TYRO_ADMIN_APIKEY, ''));
        return $v !== '' ? $v : null;
    }

    public static function getTyroAppVersion(): string
    {
        $v = self::sanitizeTyroAppVersion(get_option(self::OPT_TYRO_APP_VERSION, 'wp-dev'));
        return $v !== '' ? $v : 'wp-dev';
    }

    public static function getTyroProviderNumber(): ?string
    {
        $v = self::sanitizeTyroProviderNumber(get_option(self::OPT_TYRO_PROVIDER_NO, ''));
        return $v !== '' ? $v : null;
    }

    public static function getTyroApiBaseHost(): string
    {
        // AU endpoints
        return (self::getTyroEnv() === 'prod') ? 'api-au.medipass.io' : 'stg-api-au.medipass.io';
    }

    public static function getTyroApiBaseUrl(): string
    {
        return 'https://' . self::getTyroApiBaseHost();
    }
}
