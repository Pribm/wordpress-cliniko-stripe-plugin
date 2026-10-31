<?php

namespace App\Service\Api;

if (!defined('ABSPATH')) exit;

final class ApiAudit
{
    public static function install(): void
    {
        if (!current_user_can('manage_options') || get_option('wp_cliniko_api_schema') === '2') return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}cliniko_api_audit (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            event varchar(64) NOT NULL,
            operation varchar(64) NOT NULL,
            client_id varchar(64) NOT NULL,
            user_id bigint unsigned NOT NULL DEFAULT 0,
            status smallint unsigned NOT NULL DEFAULT 0,
            request_id varchar(64) NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $collate;");
        dbDelta("CREATE TABLE {$wpdb->prefix}cliniko_api_rate (
            bucket varchar(64) NOT NULL,
            window_start bigint unsigned NOT NULL,
            count bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (bucket),
            KEY window_start (window_start)
        ) $collate;");
        dbDelta("CREATE TABLE {$wpdb->prefix}cliniko_api_idempotency (
            key_hash varchar(64) NOT NULL,
            request_hash varchar(64) NOT NULL,
            expires_at bigint unsigned NOT NULL,
            PRIMARY KEY  (key_hash),
            KEY expires_at (expires_at)
        ) $collate;");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'cliniko_api_audit'))) && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'cliniko_api_rate'))) && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'cliniko_api_idempotency')))) {
            update_option('wp_cliniko_api_schema', '2', false);
        }
    }

    public static function record(string $event, string $operation, string $client = '', int $user = 0, int $status = 0, string $requestId = ''): void
    {
        if (get_option('wp_cliniko_api_schema') !== '2') return;
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'cliniko_api_audit', [
            'created_at' => gmdate('Y-m-d H:i:s'), 'event' => substr(sanitize_key($event), 0, 64),
            'operation' => substr(sanitize_key($operation), 0, 64), 'client_id' => substr(sanitize_key($client), 0, 64),
            'user_id' => $user, 'status' => $status, 'request_id' => substr(sanitize_key($requestId), 0, 64),
        ]);
    }

    public static function recent(): array
    {
        if (get_option('wp_cliniko_api_schema') !== '2') return [];
        global $wpdb;
        return (array) $wpdb->get_results("SELECT * FROM {$wpdb->prefix}cliniko_api_audit ORDER BY id DESC LIMIT 100", ARRAY_A);
    }

    public static function cleanup(): void
    {
        if (get_option('wp_cliniko_api_schema') !== '2') return;
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - (int) ApiSettings::get()['audit_days'] * DAY_IN_SECONDS);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cliniko_api_audit WHERE created_at < %s", $cutoff));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cliniko_api_rate WHERE window_start < %d", time() - DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}cliniko_api_idempotency WHERE expires_at < %d", time()));
    }
}
