<?php

namespace App\Admin\Modules\ApiAccess;

use App\Model\AppointmentType;

if (!defined('ABSPATH')) exit;

/** Admin-only reference data; never changes which services are published. */
final class AppointmentTypeChoices
{
    public static function load(): array
    {
        $key = (string) \wp_cliniko_get_secret_option('wp_cliniko_api_key');
        if ($key === '') return ['items' => [], 'error' => 'Connect your Cliniko account in the plugin credentials settings to load the service list.'];
        // Keep this short-lived cache separate and tied to the configured Cliniko account.
        $cacheKey = 'cliniko_api_admin_types_' . hash('sha256', $key);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) return ['items' => $cached, 'error' => ''];
        try {
            $items = [];
            // all() follows pagination; do not populate the picker with only the first page.
            foreach (AppointmentType::all(\cliniko_client(false), true) as $type) {
                $id = (string) $type->getId();
                if (!ctype_digit($id) || $type->isArchived()) continue;
                $items[$id] = ['id' => $id, 'name' => (string) ($type->getName() ?: 'Unnamed appointment type'), 'minutes' => $type->getDurationInMinutes()];
            }
            $items = array_values($items);
            usort($items, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            set_transient($cacheKey, $items, 300);
            return ['items' => $items, 'error' => ''];
        } catch (\Throwable $exception) {
            error_log('Cliniko API Access service list failed: ' . get_class($exception));
            return ['items' => [], 'error' => 'We could not load your services from Cliniko. Check the Cliniko connection and try again. Previously selected services are kept below.'];
        }
    }
}
