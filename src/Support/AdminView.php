<?php

namespace App\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders WordPress admin views kept outside the module/controller classes.
 *
 * @param array<string,mixed> $context
 */
final class AdminView
{
    public static function render(string $view, array $context = []): string
    {
        if (!preg_match('/^[A-Za-z0-9\/_-]+$/', $view)) {
            throw new \InvalidArgumentException('Invalid admin view name.');
        }

        $path = dirname(__DIR__) . '/Admin/Modules/' . $view . '.phtml';
        if (!is_file($path)) {
            throw new \RuntimeException('Admin view not found: ' . $view);
        }

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
