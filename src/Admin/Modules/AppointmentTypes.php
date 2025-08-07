<?php

namespace App\Admin\Modules;

use App\Client\Cliniko\Client;
use App\Model\AppointmentType;

if (!defined('ABSPATH'))
    exit;

class AppointmentTypes
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register']);
    }

    public static function register(): void
    {
        add_submenu_page(
            'wp-cliniko-stripe-settings',
            'Appointment Types',
            'Appointment Types',
            'manage_options',
            'wp-cliniko-modules',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        $client = Client::getInstance();
        $modules = AppointmentType::all($client);
        ?>
        <div class="wrap">
            <h1>Cliniko Appointment Types (Modules)</h1>
            <p>These are the appointment types currently fetched from your Cliniko account.</p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Module ID</th>
                        <th>Name</th>
                        <th>Duration (minutes)</th>
                        <th>Price (cents)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($modules)): ?>
                        <tr>
                            <td colspan="4">No modules found. Check your API key or internet connection.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($modules as $id => $mod): ?>
                            <tr>
                                <td><?php echo esc_html($id); ?></td>
                                <td><?php echo esc_html($mod->getName()); ?></td>
                                <td><?php echo esc_html((string) $mod->getDurationInMinutes()); ?></td>
                                <td><?php echo esc_html((string) $mod->getBillableItemsFinalPrice()); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
