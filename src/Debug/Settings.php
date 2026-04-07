<?php

namespace App\Debug;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    public const OPTION_KEY = 'wp_cliniko_debug_settings';

    /**
     * @return array{enabled:string,retention_days:int,max_rows:int}
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        return self::sanitize(is_array($stored) ? $stored : []);
    }

    public static function isEnabled(): bool
    {
        return self::get()['enabled'] === 'yes';
    }

    public static function retentionDays(): int
    {
        return self::get()['retention_days'];
    }

    public static function maxRows(): int
    {
        return self::get()['max_rows'];
    }

    /**
     * @param mixed $value
     * @return array{enabled:string,retention_days:int,max_rows:int}
     */
    public static function sanitize($value): array
    {
        $input = is_array($value) ? $value : [];

        $enabled = !empty($input['enabled']) && $input['enabled'] !== 'no' ? 'yes' : 'no';
        $retentionDays = max(1, min(90, (int) ($input['retention_days'] ?? 7)));
        $maxRows = max(100, min(20000, (int) ($input['max_rows'] ?? 5000)));

        return [
            'enabled' => $enabled,
            'retention_days' => $retentionDays,
            'max_rows' => $maxRows,
        ];
    }
}
