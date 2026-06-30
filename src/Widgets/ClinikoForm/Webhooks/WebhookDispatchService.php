<?php

namespace App\Widgets\ClinikoForm\Webhooks;

use App\Infra\JobDispatcher;
use App\Service\BookingAttemptStore;

if (!defined('ABSPATH')) {
    exit;
}

class WebhookDispatchService
{
    public const ACTION = 'cliniko_form_webhook_send';

    private BookingAttemptStore $store;
    private JobDispatcher $dispatcher;

    public function __construct(?BookingAttemptStore $store = null, ?JobDispatcher $dispatcher = null)
    {
        $this->store = $store ?: new BookingAttemptStore();
        $this->dispatcher = $dispatcher ?: new JobDispatcher();
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $extra
     */
    public function queue(string $event, array $attempt, array $extra = []): void
    {
        $attemptId = trim((string) ($attempt['attempt_id'] ?? ''));
        if ($attemptId === '') {
            return;
        }

        $config = $this->resolveConfig($attempt);
        if (!$this->shouldSend($config, $event)) {
            return;
        }

        $this->dispatcher->enqueue(
            self::ACTION,
            [
                'event' => $event,
                'attempt_id' => $attemptId,
                'extra' => $extra,
                'retry' => 0,
            ],
            0,
            $event . '|' . $attemptId . '|' . time()
        );
    }

    /**
     * @param array<string,mixed> $args
     */
    public function deliver(array $args): void
    {
        $event = trim((string) ($args['event'] ?? ''));
        $attemptId = trim((string) ($args['attempt_id'] ?? ''));
        if ($event === '' || $attemptId === '') {
            return;
        }

        $attempt = $this->store->get($attemptId);
        if (!$attempt) {
            return;
        }

        $config = $this->resolveConfig($attempt);
        if (!$this->shouldSend($config, $event)) {
            return;
        }

        $payload = $this->payload($event, $attempt, is_array($args['extra'] ?? null) ? $args['extra'] : [], $config);
        $json = wp_json_encode($payload);
        if (!is_string($json) || $json === '') {
            return;
        }

        $timestamp = (string) time();
        $secret = WebhookSettingsRegistry::signingSecret($config);
        $signature = hash_hmac('sha256', $timestamp . '.' . $json, $secret);

        $response = wp_remote_post((string) $config['url'], [
            'timeout' => 8,
            'redirection' => 0,
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress-Cliniko-Stripe-Plugin/' . (defined('WP_CLINIKO_PLUGIN_VERSION') ? WP_CLINIKO_PLUGIN_VERSION : 'unknown'),
                'X-Cliniko-Webhook-Event' => $event,
                'X-Cliniko-Webhook-Timestamp' => $timestamp,
                'X-Cliniko-Webhook-Signature' => 'sha256=' . $signature,
            ],
            'body' => $json,
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            $retry = (int) ($args['retry'] ?? 0);
            if ($retry < 3) {
                $this->dispatcher->enqueue(
                    self::ACTION,
                    [
                        'event' => $event,
                        'attempt_id' => $attemptId,
                        'extra' => is_array($args['extra'] ?? null) ? $args['extra'] : [],
                        'retry' => $retry + 1,
                    ],
                    [60, 300, 900][$retry] ?? 900,
                    $event . '|' . $attemptId . '|retry|' . ($retry + 1)
                );
            }

            error_log(sprintf('[ClinikoFormWebhook] Delivery failed for %s attempt %s.', $event, $attemptId));
        }
    }

    /**
     * @param array<string,mixed> $attempt
     * @return array<string,mixed>|null
     */
    private function resolveConfig(array $attempt): ?array
    {
        $context = is_array($attempt['cliniko_form_webhook_context'] ?? null)
            ? $attempt['cliniko_form_webhook_context']
            : [];

        $id = trim((string) ($context['id'] ?? ''));
        if ($id !== '') {
            $config = WebhookSettingsRegistry::find($id);
            if ($config !== null) {
                return $config;
            }
        }

        return WebhookSettingsRegistry::findForBooking(
            (string) ($attempt['module_id'] ?? ''),
            (string) ($attempt['patient_form_template_id'] ?? '')
        );
    }

    /**
     * @param array<string,mixed>|null $config
     */
    private function shouldSend(?array $config, string $event): bool
    {
        if (!$config || empty($config['enabled']) || trim((string) ($config['url'] ?? '')) === '') {
            return false;
        }

        $events = is_array($config['events'] ?? null) ? $config['events'] : [];
        return in_array($event, $events, true);
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array<string,mixed> $extra
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function payload(string $event, array $attempt, array $extra, array $config): array
    {
        $payment = is_array($attempt['payment'] ?? null) ? $attempt['payment'] : [];
        unset($payment['card_last4'], $payment['brand']);

        $payload = [
            'event' => $event,
            'occurred_at' => gmdate(DATE_ATOM),
            'site_url' => get_site_url(),
            'form' => [
                'context_id' => (string) ($config['id'] ?? ''),
                'post_id' => (int) ($config['post_id'] ?? 0),
                'widget_id' => (string) ($config['widget_id'] ?? ''),
                'module_id' => (string) ($attempt['module_id'] ?? ''),
                'patient_form_template_id' => (string) ($attempt['patient_form_template_id'] ?? ''),
            ],
            'booking_attempt' => [
                'id' => (string) ($attempt['attempt_id'] ?? ''),
                'status' => (string) ($attempt['status'] ?? ''),
                'progress' => is_array($attempt['progress'] ?? null) ? $attempt['progress'] : [],
                'appointment_label' => (string) ($attempt['appointment_label'] ?? ''),
                'amount' => (int) ($attempt['amount'] ?? 0),
                'currency' => (string) ($attempt['currency'] ?? 'aud'),
                'gateway' => (string) ($attempt['gateway'] ?? ''),
                'patient_form_id' => (string) ($attempt['patient_form_id'] ?? ''),
            ],
            'booking' => is_array($attempt['booking'] ?? null) ? $attempt['booking'] : [],
            'payment' => $payment,
        ];

        if (!empty($attempt['error'])) {
            $payload['error'] = (string) $attempt['error'];
        }

        if (!empty($extra)) {
            $payload['extra'] = $this->sanitizeExtra($extra);
        }

        if (!empty($config['include_patient'])) {
            $patient = is_array($attempt['patient'] ?? null) ? $attempt['patient'] : [];
            $payload['patient'] = [
                'first_name' => (string) ($patient['first_name'] ?? ''),
                'last_name' => (string) ($patient['last_name'] ?? ''),
                'email' => (string) ($patient['email'] ?? ''),
                'phone' => (string) ($patient['phone'] ?? ''),
            ];
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function sanitizeExtra(array $extra): array
    {
        unset(
            $extra['patient'],
            $extra['content'],
            $extra['attempt_token'],
            $extra['stripeToken'],
            $extra['access_token']
        );

        return $extra;
    }
}
