<?php

namespace App\Debug;

use App\Contracts\ClientResponse;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

class Runtime
{
    /**
     * @var array<int,array<string,mixed>>
     */
    private static array $restSpans = [];

    /**
     * @var array<string,array<string,mixed>>
     */
    private static array $httpSpans = [];

    /**
     * @var array<int,array<string,mixed>>
     */
    private static array $mailSpans = [];

    private static ?LogStore $store = null;
    private static bool $initialized = false;

    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        add_filter('rest_request_before_callbacks', [self::class, 'beforeRest'], 10, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'afterRest'], 10, 3);
        add_filter('http_request_args', [self::class, 'beforeHttp'], 10, 2);
        add_action('http_api_debug', [self::class, 'afterHttp'], 10, 5);
        add_filter('pre_wp_mail', [self::class, 'beforeMail'], 10, 2);
        add_action('wp_mail_succeeded', [self::class, 'mailSucceeded'], 10, 1);
        add_action('wp_mail_failed', [self::class, 'mailFailed'], 10, 1);
        add_action('shutdown', [self::class, 'onShutdown']);
    }

    public static function currentTraceId(): string
    {
        return TraceContext::currentTraceId();
    }

    public static function currentRoute(): string
    {
        return TraceContext::currentRoute();
    }

    public static function currentMethod(): string
    {
        return TraceContext::currentMethod();
    }

    /**
     * @param array<string,mixed> $entry
     */
    public static function logEvent(array $entry): void
    {
        if (!Settings::isEnabled()) {
            return;
        }

        try {
            self::store()->insert($entry);
        } catch (\Throwable $e) {
        }
    }

    /**
     * @param mixed $response
     * @param array<string,mixed> $handler
     * @return mixed
     */
    public static function beforeRest($response, array $handler, WP_REST_Request $request)
    {
        if (!Settings::isEnabled() || !self::shouldObserveRest($handler)) {
            return $response;
        }

        $traceId = TraceContext::begin($request->get_method(), $request->get_route());
        self::$restSpans[spl_object_id($request)] = [
            'started_at' => microtime(true),
            'trace_id' => $traceId,
            'route' => $request->get_route(),
            'method' => strtoupper($request->get_method()),
            'handler' => self::describeHandler($handler),
            'request_flags' => self::summarizeRequest($request),
        ];

        return $response;
    }

    /**
     * @param mixed $response
     * @param array<string,mixed> $handler
     * @return mixed
     */
    public static function afterRest($response, array $handler, WP_REST_Request $request)
    {
        $key = spl_object_id($request);
        $span = self::$restSpans[$key] ?? null;
        if ($span === null) {
            return $response;
        }

        unset(self::$restSpans[$key]);

        $statusCode = self::extractResponseStatusCode($response);
        $durationMs = (int) round((microtime(true) - (float) $span['started_at']) * 1000);
        $level = self::levelForStatus($statusCode, $response instanceof WP_Error);

        self::logEvent([
            'trace_id' => $span['trace_id'],
            'channel' => 'rest',
            'level' => $level,
            'event' => 'route_complete',
            'method' => (string) $span['method'],
            'route' => (string) $span['route'],
            'target' => (string) $span['handler'],
            'request_kind' => 'rest',
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'message' => sprintf(
                'REST %s %s completed with status %d in %dms',
                $span['method'],
                $span['route'],
                $statusCode,
                $durationMs
            ),
            'context' => [
                'handler' => (string) $span['handler'],
                'request_flags' => $span['request_flags'],
                'response_summary' => self::summarizeResponse($response),
            ],
        ]);

        TraceContext::clear();

        return $response;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function beforeHttp(array $args, string $url): array
    {
        if (!Settings::isEnabled()) {
            return $args;
        }

        $service = self::classifyService($url);
        if ($service === 'internal' && TraceContext::currentTraceId() === '') {
            return $args;
        }

        $requestId = self::generateRequestId();
        self::$httpSpans[$requestId] = [
            'started_at' => microtime(true),
            'trace_id' => TraceContext::currentTraceId(),
            'route' => TraceContext::currentRoute(),
            'method' => strtoupper((string) ($args['method'] ?? 'GET')),
            'service' => $service,
            'timeout' => isset($args['timeout']) ? (float) $args['timeout'] : null,
            'target' => LogSanitizer::sanitizeUrl($url),
        ];

        $args['_es_debug_request_id'] = $requestId;
        return $args;
    }

    /**
     * @param mixed $response
     * @param array<string,mixed> $args
     */
    public static function afterHttp($response, string $context, string $class, array $args, string $url): void
    {
        if (!Settings::isEnabled() || $context !== 'response') {
            return;
        }

        $requestId = trim((string) ($args['_es_debug_request_id'] ?? ''));
        if ($requestId === '' || !isset(self::$httpSpans[$requestId])) {
            return;
        }

        $span = self::$httpSpans[$requestId];
        unset(self::$httpSpans[$requestId]);

        $statusCode = self::extractHttpStatusCode($response);
        $durationMs = (int) round((microtime(true) - (float) $span['started_at']) * 1000);
        $level = self::levelForStatus($statusCode, $response instanceof WP_Error);
        $errorMessage = $response instanceof WP_Error
            ? LogSanitizer::sanitizeString($response->get_error_message())
            : '';

        self::logEvent([
            'trace_id' => (string) ($span['trace_id'] ?? ''),
            'channel' => 'http',
            'level' => $level,
            'event' => 'request_complete',
            'method' => (string) ($span['method'] ?? 'GET'),
            'route' => (string) ($span['route'] ?? ''),
            'target' => (string) ($span['target'] ?? LogSanitizer::sanitizeUrl($url)),
            'request_kind' => (string) ($span['service'] ?? 'external'),
            'status_code' => $statusCode,
            'duration_ms' => $durationMs,
            'message' => $errorMessage !== ''
                ? sprintf('HTTP %s failed after %dms: %s', $span['target'], $durationMs, $errorMessage)
                : sprintf('HTTP %s completed with status %d in %dms', $span['target'], $statusCode, $durationMs),
            'context' => [
                'service' => (string) ($span['service'] ?? 'external'),
                'timeout_s' => $span['timeout'],
                'transport' => $class,
                'wp_error' => $errorMessage !== '',
            ],
        ]);
    }

    /**
     * @param mixed $pre
     * @param array<string,mixed> $atts
     * @return mixed
     */
    public static function beforeMail($pre, array $atts)
    {
        if (!Settings::isEnabled()) {
            return $pre;
        }

        self::$mailSpans[] = [
            'started_at' => microtime(true),
            'trace_id' => TraceContext::currentTraceId(),
            'route' => TraceContext::currentRoute(),
            'method' => TraceContext::currentMethod(),
            'recipients' => LogSanitizer::summarizeRecipients($atts['to'] ?? []),
            'subject_hash' => md5((string) ($atts['subject'] ?? '')),
        ];

        return $pre;
    }

    /**
     * @param array<string,mixed> $mailData
     */
    public static function mailSucceeded(array $mailData): void
    {
        self::finishMail('info', 'mail_sent', '', $mailData);
    }

    public static function mailFailed(WP_Error $error): void
    {
        $data = $error->get_error_data();
        self::finishMail(
            'error',
            'mail_failed',
            LogSanitizer::sanitizeString($error->get_error_message()),
            is_array($data) ? $data : []
        );
    }

    public static function onShutdown(): void
    {
        if (!Settings::isEnabled()) {
            return;
        }

        $error = error_get_last();
        if (!is_array($error) || !in_array((int) $error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        self::logEvent([
            'trace_id' => TraceContext::currentTraceId(),
            'channel' => 'php',
            'level' => 'error',
            'event' => 'fatal_shutdown',
            'method' => TraceContext::currentMethod(),
            'route' => TraceContext::currentRoute(),
            'target' => basename((string) $error['file']),
            'request_kind' => TraceContext::currentRoute() !== '' ? 'rest' : '',
            'status_code' => 500,
            'message' => LogSanitizer::sanitizeString((string) $error['message']),
            'context' => [
                'file' => basename((string) $error['file']),
                'line' => (int) $error['line'],
                'type' => (int) $error['type'],
            ],
        ]);
    }

    public static function logClientResponse(
        string $service,
        string $method,
        string $target,
        int $durationMs,
        ?ClientResponse $response,
        bool $cacheEnabled
    ): void {
        if (!Settings::isEnabled()) {
            return;
        }

        $statusCode = $response instanceof ClientResponse ? (int) ($response->statusCode ?? 0) : 0;
        $hasError = $response instanceof ClientResponse && $response->error !== null;
        $level = self::levelForStatus($statusCode, $hasError);

        self::logEvent([
            'trace_id' => TraceContext::currentTraceId(),
            'channel' => 'client',
            'level' => $level,
            'event' => 'api_client_call',
            'method' => strtoupper($method),
            'route' => TraceContext::currentRoute(),
            'target' => LogSanitizer::sanitizeUrl($target),
            'request_kind' => $service,
            'status_code' => $statusCode > 0 ? $statusCode : null,
            'duration_ms' => $durationMs,
            'message' => $hasError
                ? sprintf('%s %s failed after %dms: %s', strtoupper($method), $target, $durationMs, $response->error)
                : sprintf('%s %s completed in %dms', strtoupper($method), $target, $durationMs),
            'context' => [
                'service' => $service,
                'cache_enabled' => $cacheEnabled,
                'client_status_code' => $statusCode > 0 ? $statusCode : null,
                'client_error' => $hasError ? LogSanitizer::sanitizeString((string) $response->error) : '',
            ],
        ]);
    }

    public static function logClientException(
        string $service,
        string $method,
        string $target,
        int $durationMs,
        \Throwable $error,
        bool $cacheEnabled
    ): void {
        if (!Settings::isEnabled()) {
            return;
        }

        self::logEvent([
            'trace_id' => TraceContext::currentTraceId(),
            'channel' => 'client',
            'level' => 'error',
            'event' => 'api_client_exception',
            'method' => strtoupper($method),
            'route' => TraceContext::currentRoute(),
            'target' => LogSanitizer::sanitizeUrl($target),
            'request_kind' => $service,
            'status_code' => null,
            'duration_ms' => $durationMs,
            'message' => LogSanitizer::sanitizeString($error->getMessage()),
            'context' => [
                'service' => $service,
                'cache_enabled' => $cacheEnabled,
                'exception_class' => get_class($error),
            ],
        ]);
    }

    private static function store(): LogStore
    {
        if (self::$store === null) {
            self::$store = new LogStore();
        }

        return self::$store;
    }

    /**
     * @param array<string,mixed> $handler
     */
    private static function shouldObserveRest(array $handler): bool
    {
        $callback = $handler['callback'] ?? null;
        if (is_array($callback)) {
            $class = is_object($callback[0] ?? null) ? get_class($callback[0]) : (string) ($callback[0] ?? '');
            return $class !== '' && str_starts_with($class, 'App\\Controller\\');
        }

        if (is_string($callback)) {
            return str_starts_with($callback, 'App\\Controller\\');
        }

        return false;
    }

    /**
     * @param array<string,mixed> $handler
     */
    private static function describeHandler(array $handler): string
    {
        $callback = $handler['callback'] ?? null;
        if (is_array($callback)) {
            $class = is_object($callback[0] ?? null) ? get_class($callback[0]) : (string) ($callback[0] ?? '');
            $method = (string) ($callback[1] ?? '');
            return trim($class . '::' . $method, ':');
        }

        if (is_string($callback)) {
            return $callback;
        }

        return 'callback';
    }

    /**
     * @return array<string,mixed>
     */
    private static function summarizeRequest(WP_REST_Request $request): array
    {
        $headers = array_change_key_case($request->get_headers(), CASE_LOWER);
        $params = $request->get_params();

        return [
            'param_count' => count($params),
            'has_email' => self::hasNonEmptyValue($params['email'] ?? null),
            'has_code' => self::hasNonEmptyValue($params['code'] ?? null),
            'has_request_id' => self::hasNonEmptyValue($params['request_id'] ?? null),
            'has_request_token' => self::hasHeader($headers, 'x-es-request-token'),
            'has_patient_access_token' => self::hasHeader($headers, 'x-es-patient-access-token')
                || self::hasNonEmptyValue($params['patient_access_token'] ?? null)
                || self::hasNonEmptyValue($params['access_token'] ?? null),
        ];
    }

    /**
     * @param mixed $response
     * @return array<string,mixed>
     */
    private static function summarizeResponse($response): array
    {
        if ($response instanceof WP_Error) {
            return [
                'wp_error' => true,
                'error_code' => LogSanitizer::sanitizeString((string) $response->get_error_code()),
            ];
        }

        if ($response instanceof WP_REST_Response) {
            $normalized = $response;
        } elseif (function_exists('rest_ensure_response')) {
            $normalized = rest_ensure_response($response);
        } else {
            return [];
        }

        $data = $normalized->get_data();
        if (!is_array($data)) {
            return [
                'response_type' => gettype($data),
            ];
        }

        $topLevelKeys = array_slice(array_map('strval', array_keys($data)), 0, 12);

        return [
            'top_level_keys' => $topLevelKeys,
            'ok' => isset($data['ok']) ? (bool) $data['ok'] : null,
            'message_present' => self::hasNonEmptyValue($data['message'] ?? null),
            'challenge_token_present' => self::hasNonEmptyValue($data['challenge_token'] ?? null),
            'access_token_present' => self::hasNonEmptyValue($data['access_token'] ?? null),
            'data_present' => array_key_exists('data', $data),
        ];
    }

    /**
     * @param mixed $response
     */
    private static function extractResponseStatusCode($response): int
    {
        if ($response instanceof WP_Error) {
            return 500;
        }

        if ($response instanceof WP_REST_Response) {
            return (int) $response->get_status();
        }

        if (function_exists('rest_ensure_response')) {
            return (int) rest_ensure_response($response)->get_status();
        }

        return 200;
    }

    /**
     * @param mixed $response
     */
    private static function extractHttpStatusCode($response): int
    {
        if ($response instanceof WP_Error) {
            return 0;
        }

        return (int) wp_remote_retrieve_response_code($response);
    }

    private static function classifyService(string $url): string
    {
        $host = strtolower((string) (wp_parse_url($url, PHP_URL_HOST) ?? ''));
        $siteHost = strtolower((string) (wp_parse_url(home_url(), PHP_URL_HOST) ?? ''));

        if ($host === '' || $host === $siteHost) {
            return 'internal';
        }

        if (str_contains($host, 'cliniko.com')) {
            return 'cliniko';
        }

        if (str_contains($host, 'stripe.com')) {
            return 'stripe';
        }

        if (str_contains($host, 'medipass.io') || str_contains($host, 'gateway.mastercard.com')) {
            return 'tyro';
        }

        return 'external';
    }

    /**
     * @param array<string,mixed> $mailData
     */
    private static function finishMail(string $level, string $event, string $errorMessage, array $mailData): void
    {
        if (!Settings::isEnabled()) {
            return;
        }

        $span = array_pop(self::$mailSpans);
        $durationMs = $span !== null
            ? (int) round((microtime(true) - (float) $span['started_at']) * 1000)
            : null;
        $recipients = is_array($span['recipients'] ?? null)
            ? $span['recipients']
            : LogSanitizer::summarizeRecipients($mailData['to'] ?? []);

        self::logEvent([
            'trace_id' => (string) ($span['trace_id'] ?? TraceContext::currentTraceId()),
            'channel' => 'mail',
            'level' => $level,
            'event' => $event,
            'method' => (string) ($span['method'] ?? TraceContext::currentMethod()),
            'route' => (string) ($span['route'] ?? TraceContext::currentRoute()),
            'target' => implode(', ', $recipients['recipient_domains']),
            'request_kind' => 'mail',
            'status_code' => $event === 'mail_sent' ? 200 : 500,
            'duration_ms' => $durationMs,
            'message' => $errorMessage !== ''
                ? $errorMessage
                : sprintf('Mail sent to %d recipient(s)', (int) $recipients['recipient_count']),
            'context' => [
                'recipient_count' => (int) $recipients['recipient_count'],
                'recipient_domains' => $recipients['recipient_domains'],
                'subject_hash' => (string) ($span['subject_hash'] ?? md5((string) ($mailData['subject'] ?? ''))),
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $headers
     */
    private static function hasHeader(array $headers, string $key): bool
    {
        if (!array_key_exists($key, $headers)) {
            return false;
        }

        $value = $headers[$key];
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::hasNonEmptyValue($item)) {
                    return true;
                }
            }

            return false;
        }

        return self::hasNonEmptyValue($value);
    }

    /**
     * @param mixed $value
     */
    private static function hasNonEmptyValue($value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    private static function levelForStatus(int $statusCode, bool $isError): string
    {
        if ($isError || $statusCode >= 500 || $statusCode === 0) {
            return 'error';
        }

        if ($statusCode >= 400) {
            return 'warning';
        }

        return 'info';
    }

    private static function generateRequestId(): string
    {
        try {
            return bin2hex(random_bytes(6));
        } catch (\Throwable $e) {
            return substr(md5(uniqid('http_', true)), 0, 12);
        }
    }
}
