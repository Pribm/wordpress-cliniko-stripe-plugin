<?php

namespace App\Admin\Modules;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles syncing Elementor widget email templates into WP options.
 */
class ElementorTemplateSync
{
    /**
     * Register hooks.
     */
    public static function init(): void
    {
        add_action('elementor/editor/after_save', [self::class, 'handleAfterSave'], 10, 2);
    }

    /**
     * Callback when Elementor saves a page.
     *
     * @param int   $post_id
     * @param array $editor_data
     */
    public static function handleAfterSave(int $post_id, array $editor_data): void
    {
        self::walkElements($editor_data);
    }

    /**
     * Walk Elementor element tree recursively.
     *
     * @param array $elements
     */
    private static function walkElements(array $elements): void
    {
        foreach ($elements as $element) {
            if (!empty($element['widgetType']) && $element['widgetType'] === 'cliniko_stripe_payment') {
                if (!empty($element['settings']['failure_email_template'])) {
                    update_option('wp_cliniko_failure_email_tpl', $element['settings']['failure_email_template']);
                }
                if (!empty($element['settings']['success_email_template'])) {
                    update_option('wp_cliniko_success_email_tpl', $element['settings']['success_email_template']);
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                self::walkElements($element['elements']);
            }
        }
    }
}
