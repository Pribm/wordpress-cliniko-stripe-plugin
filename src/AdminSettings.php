<?php

namespace App;

if (!defined('ABSPATH')) exit;

class AdminSettings
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenu()
    {
        add_menu_page(
            'Cliniko + Stripe Integration',
            'Cliniko + Stripe',
            'manage_options',
            'wp-cliniko-stripe-settings',
            [$this, 'renderSettingsPage'],
            'dashicons-admin-links'
        );
    }

    public function renderSettingsPage()
    {
        ?>
        <div class="wrap">
            <h1>Cliniko + Stripe Integration Settings</h1>
            <p>
                This plugin allows you to integrate <strong>Elementor forms</strong> with the <strong>Cliniko API</strong>
                and <strong>Stripe</strong> for payments. Please enter your API keys below.
            </p>
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

    public function registerSettings()
    {
        register_setting('wp_cliniko_stripe_group', 'wp_cliniko_api_key');
        register_setting('wp_cliniko_stripe_group', 'wp_stripe_public_key');
        register_setting('wp_cliniko_stripe_group', 'wp_stripe_secret_key');

        add_settings_section(
            'wp_cliniko_stripe_section',
            'API Credentials',
            function () {
                echo '<p>Please provide your Cliniko and Stripe API keys below.</p>';
            },
            'wp-cliniko-stripe-settings'
        );

        add_settings_field(
            'wp_cliniko_api_key',
            'Cliniko API Key',
            function () {
                $value = esc_attr(get_option('wp_cliniko_api_key'));
                echo "<input type='text' name='wp_cliniko_api_key' value='$value' class='regular-text' />";
                echo "<p class='description'>You can find this key in your Cliniko account under API Settings.</p>";
            },
            'wp-cliniko-stripe-settings',
            'wp_cliniko_stripe_section'
        );

        add_settings_field(
            'wp_stripe_public_key',
            'Stripe Public Key',
            function () {
                $value = esc_attr(get_option('wp_stripe_public_key'));
                echo "<input type='text' name='wp_stripe_public_key' value='$value' class='regular-text' />";
                echo "<p class='description'>Used to render payment elements on the frontend.</p>";
            },
            'wp-cliniko-stripe-settings',
            'wp_cliniko_stripe_section'
        );

        add_settings_field(
            'wp_stripe_secret_key',
            'Stripe Secret Key',
            function () {
                $value = esc_attr(get_option('wp_stripe_secret_key'));
                echo "<input type='password' name='wp_stripe_secret_key' value='$value' class='regular-text' />";
                echo "<p class='description'>Used for processing payments securely. Keep this key safe.</p>";
            },
            'wp-cliniko-stripe-settings',
            'wp_cliniko_stripe_section'
        );
    }
}
