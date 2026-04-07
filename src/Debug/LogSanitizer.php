<?php

namespace App\Debug;

if (!defined('ABSPATH')) {
    exit;
}

class LogSanitizer
{
    /**
     * @param mixed $value
     * @return mixed
     */
    public static function sanitizeValue($value)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                $safeKey = is_string($key) ? self::sanitizeKey($key) : $key;
                $sanitized[$safeKey] = self::sanitizeValue($item);
            }

            return $sanitized;
        }

        if (is_object($value)) {
            return self::sanitizeValue(get_object_vars($value));
        }

        if (is_string($value)) {
            return self::sanitizeString($value);
        }

        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }

        return self::sanitizeString((string) $value);
    }

    public static function sanitizeString(string $value): string
    {
        $sanitized = trim($value);
        if ($sanitized === '') {
            return '';
        }

        $sanitized = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9+\/=_\-.]+/i', '$1 [redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b[A-Fa-f0-9]{24,}\b/', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b[A-Za-z0-9_-]{32,}\b/', '[redacted]', $sanitized) ?? $sanitized;

        if (strlen($sanitized) > 500) {
            $sanitized = substr($sanitized, 0, 497) . '...';
        }

        return $sanitized;
    }

    public static function sanitizeUrl(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return self::sanitizeString($url);
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');
        $path = preg_replace('#/[A-Za-z0-9_-]{12,}(?=/|$)#', '/:id', $path) ?? $path;

        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        $queryKeys = array_slice(array_map('strval', array_keys($query)), 0, 12);
        $querySuffix = !empty($queryKeys) ? '?keys=' . implode(',', $queryKeys) : '';

        return trim($host . $path . $querySuffix);
    }

    /**
     * @param mixed $recipients
     * @return array{recipient_count:int,recipient_domains:array<int,string>}
     */
    public static function summarizeRecipients($recipients): array
    {
        $values = [];
        if (is_array($recipients)) {
            $values = $recipients;
        } elseif (is_string($recipients) && $recipients !== '') {
            $values = preg_split('/[,;]+/', $recipients) ?: [];
        }

        $domains = [];
        foreach ($values as $value) {
            $email = strtolower(trim((string) $value));
            if ($email === '' || strpos($email, '@') === false) {
                continue;
            }

            $domain = substr(strrchr($email, '@') ?: '', 1);
            if ($domain === '' || in_array($domain, $domains, true)) {
                continue;
            }

            $domains[] = $domain;
        }

        return [
            'recipient_count' => count(array_filter(array_map('trim', array_map('strval', $values)))),
            'recipient_domains' => $domains,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function encodeContext(array $context): string
    {
        $encoded = wp_json_encode(self::sanitizeValue($context), JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '{}';
    }

    private static function sanitizeKey(string $key): string
    {
        $safeKey = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', trim($key));
        return $safeKey !== null && $safeKey !== '' ? $safeKey : 'key';
    }
}
