<?php

namespace App\Admin\Modules;

use App\Client\Cliniko\CachedClientDecorator;
use App\Model\AppointmentType;
use App\Model\PatientFormTemplate;

if (!defined('ABSPATH')) {
    exit;
}

class Tools
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_post_wp_cliniko_clear_cache', [self::class, 'handleCacheClear']);
        add_action('admin_post_wp_cliniko_test_connectivity', [self::class, 'handleConnectivityTest']);
        add_action('admin_post_wp_cliniko_trigger_sync', [self::class, 'handleSyncTrigger']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'wp-cliniko-stripe-settings',
            'Tools & Maintenance',
            'Tools',
            'manage_options',
            'wp-cliniko-tools',
            [self::class, 'renderPage']
        );
    }
public static function renderPage(): void
{
    ?>
    <div class="wrap">
        <h1>Cliniko Plugin Tools</h1>

        <?php if (isset($_GET['cache_cleared'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Success:</strong> Cliniko API cache has been cleared.</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['sync_success'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Success:</strong> Data sync completed successfully.</p>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['connectivity_tested'])):
            $results = get_transient('wp_cliniko_connectivity_results') ?: [];
            ?>
            <div class="notice notice-info">
                <p><strong>Connectivity Test Results:</strong></p>
                <ul style="margin-left: 1.5em; list-style: disc;">
                    <li>
                        <?php
                        $clinikoResult = $results['cliniko'] ?? null;
                        echo esc_html($clinikoResult ? "Cliniko: $clinikoResult - Connected ✅" : 'Cliniko: No response ❌');
                        ?>
                    </li>
                    <li>
                        <?php
                        $stripeResult = $results['stripe'] ?? null;
                        echo esc_html($stripeResult ? "Stripe: $stripeResult - Connected ✅" : 'Stripe: No response ❌');
                        ?>
                    </li>
                </ul>
            </div>
        <?php endif; ?>

        <div class="postbox" style="padding: 20px; margin-top: 20px;">
            <h2>Maintenance Tools</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 10px;">
                <?php wp_nonce_field('wp_cliniko_clear_cache'); ?>
                <input type="hidden" name="action" value="wp_cliniko_clear_cache">
                <button class="button button-primary">Clear API Cache</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom: 10px;">
                <?php wp_nonce_field('wp_cliniko_test_connectivity'); ?>
                <input type="hidden" name="action" value="wp_cliniko_test_connectivity">
                <button class="button">Test API Connectivity</button>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('wp_cliniko_trigger_sync'); ?>
                <input type="hidden" name="action" value="wp_cliniko_trigger_sync">
                <button class="button">Trigger Data Sync</button>
            </form>
        </div>

        <div class="postbox" style="padding: 20px; margin-top: 30px;">
            <h2>System Information</h2>
            <table class="widefat striped">
                <tbody>
                    <tr><th>Site URL</th><td><?php echo esc_html(site_url()); ?></td></tr>
                    <tr><th>Home URL</th><td><?php echo esc_html(home_url()); ?></td></tr>
                    <tr><th>WordPress Version</th><td><?php echo esc_html(get_bloginfo('version')); ?></td></tr>
                    <tr><th>Active Theme</th><td><?php
                        $theme = wp_get_theme();
                        echo esc_html($theme->get('Name') . ' ' . $theme->get('Version'));
                    ?></td></tr>
                    <tr><th>PHP Version</th><td><?php echo esc_html(phpversion()); ?></td></tr>
                    <tr><th>Server Software</th><td><?php echo esc_html($_SERVER['SERVER_SOFTWARE'] ?? 'N/A'); ?></td></tr>
                    <tr><th>Memory Limit</th><td><?php echo esc_html(ini_get('memory_limit')); ?></td></tr>
                    <tr><th>Max Execution Time</th><td><?php echo esc_html(ini_get('max_execution_time')) . ' seconds'; ?></td></tr>
                    <tr><th>Cliniko API Key Set</th><td><?php echo get_option('wp_cliniko_api_key') ? '✅' : '❌'; ?></td></tr>
                    <tr><th>Stripe Keys Set</th><td>
                        <?php
                            $pub = get_option('wp_stripe_public_key');
                            $sec = get_option('wp_stripe_secret_key');
                            echo ($pub && $sec) ? '✅' : '❌';
                        ?>
                    </td></tr>
                    <tr><th>Plugin Version</th><td>
                        <?php
                            if (defined('WP_CLINIKO_PLUGIN_VERSION')) {
                                echo esc_html(WP_CLINIKO_PLUGIN_VERSION);
                            } else {
                                echo 'Not defined';
                            }
                        ?>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}


    public static function handleConnectivityTest(): void
    {
        check_admin_referer('wp_cliniko_test_connectivity');

        $clinikoKey = get_option('wp_cliniko_api_key');
        $stripeKey = get_option('wp_stripe_secret_key');

        $results = [];

        // Test Cliniko
        $clinikoResponse = wp_remote_get(Credentials::getApiBase() . '/v1/businesses', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clinikoKey . ':'),
                'Accept' => 'application/json',
            ],
            'timeout' => 10,
        ]);
        $results['cliniko'] = is_wp_error($clinikoResponse)
            ? 'Error: ' . $clinikoResponse->get_error_message()
            : 'Cliniko: ' . wp_remote_retrieve_response_code($clinikoResponse);

        // Test Stripe
        $stripeResponse = wp_remote_get('https://api.stripe.com/v1/account', [
            'headers' => [
                'Authorization' => 'Bearer ' . $stripeKey,
            ],
            'timeout' => 10,
        ]);
        $results['stripe'] = is_wp_error($stripeResponse)
            ? 'Error: ' . $stripeResponse->get_error_message()
            : 'Stripe: ' . wp_remote_retrieve_response_code($stripeResponse);

        // Store result in transient (so we can display it)
        set_transient('wp_cliniko_connectivity_results', $results, 60);

        wp_redirect(add_query_arg(['page' => 'wp-cliniko-tools', 'connectivity_tested' => 1], admin_url('admin.php')));
        exit;
    }


    public static function handleCacheClear(): void
    {
        check_admin_referer('wp_cliniko_clear_cache');

        global $wpdb;

        $count = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cliniko_api_%'"
        );

        wp_redirect(add_query_arg(['page' => 'wp-cliniko-tools', 'cache_cleared' => '1'], admin_url('admin.php')));
        exit;
    }

    public static function handleSyncTrigger(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }

    check_admin_referer('wp_cliniko_trigger_sync');

    $client = cliniko_client(true);

    try {
        AppointmentType::all($client);
        PatientFormTemplate::all($client);
        // Add other models as needed in the future
    } catch (\Throwable $e) {
        wp_die('<div class="notice notice-error"><p>Data sync failed: ' . esc_html($e->getMessage()) . '</p></div>');
    }

    wp_safe_redirect(add_query_arg(['page' => 'wp-cliniko-tools', 'sync_success' => 1], admin_url('admin.php')));
    exit;
}
}
