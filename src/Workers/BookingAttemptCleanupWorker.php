<?php

namespace App\Workers;

use App\Service\BookingAttemptService;

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

        (new BookingAttemptService())->cleanupAbandonedAttempt($attemptId);
    }
}
