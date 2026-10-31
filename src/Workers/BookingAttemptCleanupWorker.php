<?php

namespace App\Workers;

use App\Service\BookingAttemptService;
use App\Service\BookingAttemptStore;
use App\Service\Api\OperationLock;
use App\Infra\JobDispatcher;

if (!defined('ABSPATH')) {
    exit;
}

class BookingAttemptCleanupWorker
{
    public static function register(): void
    {
        add_action('cliniko_cleanup_booking_attempt', [self::class, 'handle'], 10, 1);
    }

    /**
     * @param array<string,mixed> $args
     */
    public static function handle($args): void
    {
        $attemptId = trim((string) ($args['attempt_id'] ?? ''));
        if ($attemptId === '') {
            return;
        }

        $store = new BookingAttemptStore();
        $attempt = $store->get($attemptId);
        if (!empty($attempt['api_namespace'])) {
            $result = (new OperationLock())->run($attemptId, static function () use ($attemptId, $store) {
                $current = $store->get($attemptId);
                if (($current['status'] ?? '') === 'finalizing') {
                    $store->update($attemptId, ['progress' => ['code' => 'review_required', 'message' => 'Booking finalization needs clinic review.']]);
                    return;
                }
                (new BookingAttemptService($store))->cleanupAbandonedAttempt($attemptId);
            });
            if ($result instanceof \WP_Error) (new JobDispatcher())->enqueue('cliniko_cleanup_booking_attempt', ['attempt_id' => $attemptId], 60);
            return;
        }
        (new BookingAttemptService($store))->cleanupAbandonedAttempt($attemptId);
    }
}
