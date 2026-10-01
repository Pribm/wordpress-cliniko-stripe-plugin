<?php

namespace App\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enables one shared connection notice on pages that render a Cliniko
 * shortcode. The JavaScript remains disabled on pages without a shortcode.
 */
final class ShortcodeConnectionNotice
{
    private static bool $scriptEnqueued = false;

    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueueStyle'], 20);
        add_filter('do_shortcode_tag', [self::class, 'afterShortcode'], 10, 4);
    }

    public static function enqueueStyle(): void
    {
        $path = __DIR__ . '/../Admin/assets/shortcode-connection-notice.css';
        wp_enqueue_style(
            'cliniko-shortcode-connection-notice',
            plugins_url('../Admin/assets/shortcode-connection-notice.css', __FILE__),
            [],
            is_file($path) ? (string) filemtime($path) : null
        );
    }

    /**
     * @param mixed $output
     * @param mixed $tag
     * @param mixed $attr
     * @param mixed $m
     * @return mixed
     */
    public static function afterShortcode($output, $tag, $attr, $m)
    {
        if (!is_string($tag) || !str_starts_with($tag, 'cliniko_')) {
            return $output;
        }

        if (!self::$scriptEnqueued) {
            self::$scriptEnqueued = true;
            $path = __DIR__ . '/../Admin/assets/shortcode-connection-notice.js';
            wp_enqueue_script(
                'cliniko-shortcode-connection-notice',
                plugins_url('../Admin/assets/shortcode-connection-notice.js', __FILE__),
                [],
                is_file($path) ? (string) filemtime($path) : null,
                true
            );
        }

        $settings = [
            'enabled' => true,
            'serverUnavailable' => Auth::clinikoUnavailable(),
            'offlineMessage' => 'You appear to be offline. Check your internet connection and try again.',
            'unavailableMessage' => 'Cliniko is temporarily unavailable. Please try again shortly.',
            'dismissLabel' => 'Dismiss notification',
        ];
        wp_add_inline_script(
            'cliniko-shortcode-connection-notice',
            'window.ClinikoConnectionNoticeSettings=Object.assign(window.ClinikoConnectionNoticeSettings||{},' . wp_json_encode($settings) . ');',
            'before'
        );

        return $output;
    }
}
