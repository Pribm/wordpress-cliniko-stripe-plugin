<?php

namespace App\Support;

if (!defined('ABSPATH')) exit;

final class Phtml
{
    /** @param array<string,mixed> $context */
    public static function render(string $path, array $context = []): string
    {
        if (!is_file($path)) throw new \RuntimeException('PHTML template not found: ' . $path);
        ob_start();
        try {
            extract($context, EXTR_SKIP);
            include $path;
            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }
}
