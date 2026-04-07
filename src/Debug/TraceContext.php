<?php

namespace App\Debug;

if (!defined('ABSPATH')) {
    exit;
}

class TraceContext
{
    private static string $traceId = '';
    private static string $route = '';
    private static string $method = '';

    public static function begin(string $method = '', string $route = ''): string
    {
        if (self::$traceId === '') {
            self::$traceId = self::generateTraceId();
        }

        self::$method = strtoupper(trim($method));
        self::$route = trim($route);

        return self::$traceId;
    }

    public static function currentTraceId(): string
    {
        return self::$traceId;
    }

    public static function currentRoute(): string
    {
        return self::$route;
    }

    public static function currentMethod(): string
    {
        return self::$method;
    }

    public static function clear(): void
    {
        self::$traceId = '';
        self::$route = '';
        self::$method = '';
    }

    private static function generateTraceId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return substr(md5(uniqid('cliniko_debug_', true)), 0, 16);
        }
    }
}
