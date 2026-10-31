<?php

namespace App\Service\Api;

if (!defined('ABSPATH')) exit;

final class ApiIdempotency
{
    /** Claim a finalize key across attempts without storing secrets or patient data. */
    public static function claim(string $identity, string $key, string $attemptId)
    {
        global $wpdb;
        if (get_option('wp_cliniko_api_schema') !== '2') return new \WP_Error('api_storage_unavailable', 'API storage is not ready.', ['status' => 503]);
        $keyHash = hash_hmac('sha256', $identity . '|finalize|' . $key, wp_salt('auth'));
        $requestHash = hash_hmac('sha256', $attemptId, wp_salt('auth'));
        $result = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->prefix}cliniko_api_idempotency (key_hash, request_hash, expires_at) VALUES (%s, %s, %d)", $keyHash, $requestHash, time() + DAY_IN_SECONDS));
        if ($result === false) return new \WP_Error('api_storage_unavailable', 'API storage is temporarily unavailable.', ['status' => 503]);
        $stored = $wpdb->get_var($wpdb->prepare("SELECT request_hash FROM {$wpdb->prefix}cliniko_api_idempotency WHERE key_hash = %s", $keyHash));
        if (!is_string($stored) || !hash_equals($stored, $requestHash)) return new \WP_Error('idempotency_conflict', 'This Idempotency-Key has already been used for another attempt.', ['status' => 409]);
        return true;
    }
}
