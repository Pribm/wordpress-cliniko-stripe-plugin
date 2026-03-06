<?php
namespace App\Infra;

if (!defined('ABSPATH')) exit;

interface JobDispatcherInterface {
    public function enqueue(string $job, array $args = [], int $delaySeconds = 0, ?string $uniqueKey = null): void;
}

class JobDispatcher implements JobDispatcherInterface
{
    public function enqueue(string $job, array $args = [], int $delaySeconds = 0, ?string $uniqueKey = null): void
    {
        if ($uniqueKey) { $args['_unique'] = $uniqueKey; }

        if ($delaySeconds <= 0 && function_exists('as_enqueue_async_action')) {
            // Keep a single array argument shape for worker handlers that expect handle(array $args).
            as_enqueue_async_action($job, [$args], 'wp-cliniko', false);
            return;
        }

        if (function_exists('as_schedule_single_action')) {
            $when = time() + max(0, $delaySeconds);
            as_schedule_single_action($when, $job, [ $args ], 'wp-cliniko');
            return;
        }

        // Fallback: WP-Cron
        wp_schedule_single_event(time() + max(0, $delaySeconds), $job, [$args]);
    }
}
