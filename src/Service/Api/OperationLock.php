<?php

namespace App\Service\Api;

if (!defined('ABSPATH')) exit;

final class OperationLock
{
    /** Serialize payment/finalization on a shared database; releases even on exceptions. */
    public function run(string $attemptId, callable $callback)
    {
        global $wpdb;
        $name = 'cliniko_api_' . substr(hash('sha256', $wpdb->prefix . '|' . $attemptId), 0, 48);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') return new \WP_Error('attempt_busy', 'Another operation is processing this attempt. Retry shortly.', ['status' => 409]);
        try { return $callback(); }
        finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name)); }
    }
}
