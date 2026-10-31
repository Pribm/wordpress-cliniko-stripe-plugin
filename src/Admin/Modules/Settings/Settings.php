<?php

namespace App\Admin\Modules\Settings;

use App\Support\AdminView;
use App\Admin\AdminWorkspace;

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
        $currentTab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'credentials';
        $tabs = [
            'credentials' => 'API Credentials',
            'patient-accounts' => 'Patient Accounts',
        ];

        if (!isset($tabs[$currentTab])) {
            $currentTab = 'credentials';
        }

        AdminWorkspace::begin('wp-cliniko-stripe-settings', 'Settings', 'Connect your services and configure how patient accounts work.');
        try {
            echo AdminView::render('Settings/Views/page', compact('currentTab', 'tabs'));
        } finally {
            AdminWorkspace::end();
        }
    }
}
