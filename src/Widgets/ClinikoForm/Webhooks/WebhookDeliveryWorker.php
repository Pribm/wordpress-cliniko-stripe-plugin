<?php

namespace App\Widgets\ClinikoForm\Webhooks;

if (!defined('ABSPATH')) {
    exit;
}

class WebhookDeliveryWorker
{
    public static function register(): void
    {
        add_action(WebhookDispatchService::ACTION, [self::class, 'handle'], 10, 1);
    }

    /**
     * @param array<string,mixed> $args
     */
    public static function handle($args): void
    {
        if (!is_array($args)) {
            return;
        }

        (new WebhookDispatchService())->deliver($args);
    }
}
