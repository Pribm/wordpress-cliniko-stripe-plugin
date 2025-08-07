<?php

namespace App\Admin\Modules;

if (!defined('ABSPATH')) exit;

class Settings
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addSettingsPage']);
    }

    public static function addSettingsPage(): void
    {
        add_menu_page(
            'Cliniko + Stripe Integration',
            'Cliniko + Stripe',
            'manage_options',
            'wp-cliniko-stripe-settings',
            [self::class, 'renderSettingsPage'],
            'dashicons-admin-links'
        );
    }

    public static function renderSettingsPage(): void
    {
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
}
