<?php

namespace App\Workers;

use App\Service\BookingAttemptService;

if (!defined('ABSPATH')) {
    exit;
}

final class BookingReturnHistoryWorker
{
    public const HOOK = 'cliniko_sync_return_policy_history';

    public static function register(): void
    {
        add_action(self::HOOK, [self::class, 'handle'], 10, 1);
    }

    /** @param array<string,mixed> $args */
    public static function handle(array $args): void
    {
        $attemptId = trim((string) ($args['attempt_id'] ?? ''));
        if ($attemptId !== '') {
            (new BookingAttemptService())->syncReturnPolicyHistory($attemptId);
        }
    }
}
