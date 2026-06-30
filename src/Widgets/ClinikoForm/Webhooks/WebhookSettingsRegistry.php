<?php

namespace App\Widgets\ClinikoForm\Webhooks;

if (!defined('ABSPATH')) {
    exit;
}

class WebhookSettingsRegistry
{
    private const OPTION_KEY = 'wp_cliniko_form_webhook_registry';

    /**
     * @param array<int,array<string,mixed>> $editorData
     */
    public static function syncElementorPage(int $postId, array $editorData): void
    {
        $registry = self::all();
        $previous = $registry;

        foreach ($registry as $id => $config) {
            if ((int) ($config['post_id'] ?? 0) === $postId) {
                unset($registry[$id]);
            }
        }

        foreach (self::extractWidgetConfigs($postId, $editorData, $previous) as $config) {
            $registry[$config['id']] = $config;
        }

        update_option(self::OPTION_KEY, $registry, false);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);
        return is_array($stored) ? $stored : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function find(string $id): ?array
    {
        $registry = self::all();
        return isset($registry[$id]) && is_array($registry[$id]) ? $registry[$id] : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function findForBooking(string $moduleId, string $templateId): ?array
    {
        $moduleId = trim($moduleId);
        $templateId = trim($templateId);
        if ($moduleId === '' || $templateId === '') {
            return null;
        }

        $matches = [];
        foreach (self::all() as $config) {
            if (
                !empty($config['enabled'])
                && trim((string) ($config['url'] ?? '')) !== ''
                && (string) ($config['module_id'] ?? '') === $moduleId
                && (string) ($config['patient_form_template_id'] ?? '') === $templateId
            ) {
                $matches[] = $config;
            }
        }

        if (empty($matches)) {
            return null;
        }

        usort($matches, static function (array $a, array $b): int {
            return strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''));
        });

        return $matches[0];
    }

    public static function signingSecret(array $config): string
    {
        $stored = (string) ($config['signing_secret'] ?? '');
        if ($stored !== '' && function_exists('wp_cliniko_secret_option_decrypt')) {
            $decrypted = \wp_cliniko_secret_option_decrypt($stored);
            if ($decrypted !== '') {
                return $decrypted;
            }
        }

        if ($stored !== '') {
            return $stored;
        }

        return hash_hmac(
            'sha256',
            'cliniko-form-webhook|' . (string) ($config['id'] ?? ''),
            self::fallbackSecret()
        );
    }

    /**
     * @param array<int,array<string,mixed>> $elements
     * @param array<string,array<string,mixed>> $previous
     * @return array<int,array<string,mixed>>
     */
    private static function extractWidgetConfigs(int $postId, array $elements, array $previous): array
    {
        $configs = [];

        foreach ($elements as $element) {
            if (!empty($element['widgetType']) && $element['widgetType'] === 'cliniko_stripe_payment') {
                $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
                $config = self::buildConfig($postId, (string) ($element['id'] ?? ''), $settings, $previous);
                if ($config !== null) {
                    $configs[] = $config;
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $configs = array_merge($configs, self::extractWidgetConfigs($postId, $element['elements'], $previous));
            }
        }

        return $configs;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,array<string,mixed>> $previous
     * @return array<string,mixed>|null
     */
    private static function buildConfig(int $postId, string $widgetId, array $settings, array $previous): ?array
    {
        $moduleId = trim((string) ($settings['module_id'] ?? ''));
        $templateId = trim((string) ($settings['cliniko_form_template_id'] ?? ''));
        if ($widgetId === '' || $moduleId === '' || $templateId === '') {
            return null;
        }

        $url = self::normalizeUrl($settings['cliniko_webhook_url'] ?? '');
        $id = self::contextId($postId, $widgetId, $moduleId, $templateId);
        $secret = trim((string) ($settings['cliniko_webhook_signing_secret'] ?? ''));
        $storedSecret = $secret !== ''
            ? (function_exists('wp_cliniko_secret_option_encrypt') ? \wp_cliniko_secret_option_encrypt($secret) : $secret)
            : (string) ($previous[$id]['signing_secret'] ?? '');

        if ($storedSecret === '') {
            $generated = self::randomSecret();
            $storedSecret = function_exists('wp_cliniko_secret_option_encrypt')
                ? \wp_cliniko_secret_option_encrypt($generated)
                : $generated;
        }

        return [
            'id' => $id,
            'post_id' => $postId,
            'widget_id' => $widgetId,
            'enabled' => ($settings['cliniko_webhook_enabled'] ?? '') === 'yes',
            'url' => $url,
            'events' => self::normalizeEvents($settings['cliniko_webhook_events'] ?? []),
            'include_patient' => ($settings['cliniko_webhook_include_patient'] ?? '') === 'yes',
            'module_id' => $moduleId,
            'patient_form_template_id' => $templateId,
            'updated_at' => gmdate(DATE_ATOM),
            'signing_secret' => $storedSecret,
        ];
    }

    private static function contextId(int $postId, string $widgetId, string $moduleId, string $templateId): string
    {
        return substr(hash('sha256', implode('|', [$postId, $widgetId, $moduleId, $templateId])), 0, 32);
    }

    private static function normalizeUrl($value): string
    {
        if (is_array($value)) {
            $value = $value['url'] ?? '';
        }

        $url = esc_url_raw(trim((string) $value));
        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /**
     * @return string[]
     */
    private static function normalizeEvents($value): array
    {
        $allowed = [
            'booking.preflighted',
            'payment.verified',
            'booking.completed',
            'booking.failed',
        ];

        $events = is_array($value) ? $value : [$value];
        $events = array_values(array_intersect($allowed, array_map('strval', $events)));

        return !empty($events) ? $events : ['booking.completed', 'booking.failed'];
    }

    private static function randomSecret(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            return hash('sha256', uniqid('cliniko_webhook_secret_', true));
        }
    }

    private static function fallbackSecret(): string
    {
        if (defined('AUTH_KEY') && AUTH_KEY !== '') {
            return (string) AUTH_KEY;
        }

        return (string) wp_salt('auth');
    }
}
