<?php

namespace App\Service;

if (!defined('ABSPATH')) {
    exit;
}

class BookingAttemptStore
{
    private const OPTION_PREFIX = 'cliniko_booking_attempt_';

    public function create(array $payload): array
    {
        $attemptId = $this->generateAttemptId();
        $record = array_merge($payload, [
            'attempt_id' => $attemptId,
            'created_at' => gmdate(DATE_ATOM),
            'updated_at' => gmdate(DATE_ATOM),
        ]);

        $this->save($attemptId, $record);
        return $record;
    }

    public function get(string $attemptId): ?array
    {
        $stored = get_option($this->optionKey($attemptId));
        return is_array($stored) ? $stored : null;
    }

    public function save(string $attemptId, array $record): void
    {
        $record['attempt_id'] = $attemptId;
        $record['updated_at'] = gmdate(DATE_ATOM);
        $key = $this->optionKey($attemptId);

        if (!add_option($key, $record, '', false)) {
            update_option($key, $record, false);
        }
    }

    public function update(string $attemptId, array $patch): ?array
    {
        $record = $this->get($attemptId);
        if (!$record) {
            return null;
        }

        $merged = $this->mergeRecursiveDistinct($record, $patch);
        $this->save($attemptId, $merged);
        return $merged;
    }

    public function delete(string $attemptId): void
    {
        delete_option($this->optionKey($attemptId));
    }

    private function optionKey(string $attemptId): string
    {
        return self::OPTION_PREFIX . $attemptId;
    }

    private function generateAttemptId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return md5(uniqid('cliniko_attempt_', true));
        }
    }

    private function mergeRecursiveDistinct(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeRecursiveDistinct($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
