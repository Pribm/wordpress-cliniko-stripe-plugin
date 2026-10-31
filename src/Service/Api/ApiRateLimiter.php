<?php

namespace App\Service\Api;

if (!defined('ABSPATH')) exit;

final class ApiRateLimiter
{
    /** Atomic SQL counter; all site servers share the same authoritative store. */
    public function consume(string $identity, string $bucket, int $limit): bool
    {
        // Local and development environments are commonly exercised by a
        // browser, simulator and test runner at the same loopback address.
        // Do not let those tools exhaust production-style quotas. Never use
        // this bypass on staging or production; the WordPress environment
        // type must be configured correctly in wp-config.php.
        if (in_array(wp_get_environment_type(), ['local', 'development'], true)) return true;
        if (get_option('wp_cliniko_api_schema') !== '2') return false;
        global $wpdb;
        $key = hash_hmac('sha256', $identity . '|' . $bucket, wp_salt('auth'));
        $window = (int) (floor(time() / 600) * 600);
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}cliniko_api_rate (bucket, window_start, count) VALUES (%s, %d, LAST_INSERT_ID(1))
            ON DUPLICATE KEY UPDATE count = LAST_INSERT_ID(IF(window_start = VALUES(window_start), count + 1, 1)), window_start = VALUES(window_start)",
            $key, $window
        ));
        return $result !== false && (int) $wpdb->get_var('SELECT LAST_INSERT_ID()') <= $limit;
    }

    public static function remoteAddress(): string
    {
        // CDN/proxy deployments must overwrite REMOTE_ADDR at the server layer.
        // Never trust request-supplied forwarded headers or User-Agent for limits.
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }
}
