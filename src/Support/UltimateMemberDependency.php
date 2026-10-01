<?php

namespace App\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class UltimateMemberDependency
{
    private const PLUGIN_FILE = 'ultimate-member/ultimate-member.php';

    public static function init(): void
    {
        add_action('admin_notices', [self::class, 'notice']);
    }

    public static function isActive(): bool
    {
        if (function_exists('is_plugin_active')) {
            return is_plugin_active(self::PLUGIN_FILE);
        }

        $active = in_array(self::PLUGIN_FILE, (array) get_option('active_plugins', []), true);
        if (is_multisite()) {
            $networkActive = (array) get_site_option('active_sitewide_plugins', []);
            $active = $active || isset($networkActive[self::PLUGIN_FILE]);
        }

        return $active;
    }

    public static function notice(): void
    {
        if (!current_user_can('activate_plugins') || self::isActive()) {
            return;
        }

        if (self::isInstalled()) {
            $url = wp_nonce_url(
                admin_url('plugins.php?action=activate&plugin=' . rawurlencode(self::PLUGIN_FILE)),
                'activate-plugin_' . self::PLUGIN_FILE
            );
            $action = '<a href="' . esc_url($url) . '">Activate Ultimate Member</a>';
        } else {
            $url = wp_nonce_url(
                admin_url('update.php?action=install-plugin&plugin=ultimate-member'),
                'install-plugin_ultimate-member'
            );
            $action = '<a href="' . esc_url($url) . '">Install Ultimate Member</a>';
        }

        echo '<div class="notice notice-error"><p><strong>WordPress Cliniko Stripe Plugin:</strong> Ultimate Member is required for patient account protection. ' . $action . ' to continue.</p></div>';
    }

    private static function isInstalled(): bool
    {
        return file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE);
    }
}
