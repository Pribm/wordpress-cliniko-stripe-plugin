<?php

namespace App\Admin\Modules\ApiAccess;

use App\Model\PatientFormTemplate;

if (!defined('ABSPATH')) exit;

/** Admin-only blank-template reference data, never completed patient forms. */
final class FormTemplateChoices
{
    public static function load(): array
    {
        $key = (string) \wp_cliniko_get_secret_option('wp_cliniko_api_key');
        if ($key === '') return ['items' => [], 'error' => 'Connect your Cliniko account in the plugin credentials settings to load the form template list.'];
        $cacheKey = 'cliniko_api_admin_templates_' . hash('sha256', $key);
        $cached = get_transient($cacheKey);
        if (is_array($cached)) return ['items' => $cached, 'error' => ''];
        try {
            $items = [];
            foreach (PatientFormTemplate::all(\cliniko_client(false), true) as $template) {
                $id = (string) $template->getId();
                if (!ctype_digit($id) || $template->isArchived()) continue;
                $items[$id] = ['id' => $id, 'name' => (string) ($template->getName() ?: 'Unnamed form template')];
            }
            $items = array_values($items);
            usort($items, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            set_transient($cacheKey, $items, 300);
            return ['items' => $items, 'error' => ''];
        } catch (\Throwable $exception) {
            error_log('Cliniko API Access form template list failed: ' . get_class($exception));
            return ['items' => [], 'error' => 'We could not load your form templates from Cliniko. Check the Cliniko connection and try again. Previously selected templates are kept below.'];
        }
    }
}
