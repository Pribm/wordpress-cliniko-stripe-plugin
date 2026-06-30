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
            $actionId = as_enqueue_async_action($job, [$args], 'wp-cliniko', false);
            $this->promptActionSchedulerRunner($actionId);
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

    /**
     * Action Scheduler only auto-starts its async runner from WP Admin shutdown
     * in this bundled version. Public booking requests need a small nudge.
     *
     * @param int|string|null $actionId
     */
    private function promptActionSchedulerRunner($actionId): void
    {
        if (empty($actionId)) {
            error_log('[JobDispatcher] Action Scheduler did not return an action id for wp-cliniko job.');
            return;
        }

        if (!class_exists('\ActionScheduler_AsyncRequest_QueueRunner') || !class_exists('\ActionScheduler')) {
            return;
        }

        try {
            $runner = new \ActionScheduler_AsyncRequest_QueueRunner(\ActionScheduler::store());
            $runner->maybe_dispatch();
        } catch (\Throwable $e) {
            error_log('[JobDispatcher] Could not prompt Action Scheduler runner: ' . $e->getMessage());
        }
    }
}
