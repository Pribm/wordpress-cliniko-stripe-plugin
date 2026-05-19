<?php

namespace App\Service;

use WP_REST_Request;

if (!defined('ABSPATH')) {
    exit;
}

class PublicRequestGuard
{
    public const ATTEMPT_TOKEN_HEADER = 'x-es-attempt-token';
    public const PATIENT_ACCESS_TOKEN_HEADER = 'x-es-patient-access-token';

    private BookingAttemptStore $store;
    private PatientAccessTokenService $patientAccessTokens;

    public function __construct(
        ?BookingAttemptStore $store = null,
        ?PatientAccessTokenService $patientAccessTokens = null
    )
    {
        $this->store = $store ?: new BookingAttemptStore();
        $this->patientAccessTokens = $patientAccessTokens ?: new PatientAccessTokenService();
    }

    public static function issueAttemptToken(): string
    {
        return self::randomToken(24);
    }

    public static function hashAttemptToken(string $token): string
    {
        return hash_hmac('sha256', $token, self::secret());
    }

    /**
     * Public preflight / token mint / legacy sensitive POSTs.
     *
     * @return bool|mixed
     */
    public function allowPublicMutation(WP_REST_Request $request)
    {
        return $this->authorize($request, 'public-mutation', 40, 600, false);
    }

    /**
     * Attempt-bound mutations like charge/finalize/status.
     *
     * @return bool|mixed
     */
    public function allowAttemptMutation(WP_REST_Request $request)
    {
        return $this->authorize($request, 'attempt-mutation', 80, 600, true);
    }

    /**
     * Slightly tighter budget for SDK token minting.
     *
     * @return bool|mixed
     */
    public function allowSdkToken(WP_REST_Request $request)
    {
        return $this->authorize($request, 'sdk-token', 15, 600, false);
    }

    /**
     * Public read-only booking metadata routes.
     *
     * @return bool|mixed
     */
    public function allowPublicRead(WP_REST_Request $request)
    {
        return $this->authorize($request, 'public-read', 180, 600, false);
    }

    /**
     * Stateless patient-history link request route.
     *
     * @return bool|mixed
     */
    public function allowPatientAccessRequest(WP_REST_Request $request)
    {
        if (!$this->isSameOriginOrMissing($request)) {
            return $this->deny('Forbidden origin.');
        }

        return true;
    }

    /**
     * Stateless patient-history access routes.
     *
     * @return bool|mixed
     */
    public function allowPatientAccessRead(WP_REST_Request $request)
    {
        if (!$this->isSameOriginOrMissing($request)) {
            return $this->deny('Forbidden origin.');
        }

        $token = trim((string) $this->readRequestValue(
            $request,
            ['patient_access_token', 'access_token'],
            [self::PATIENT_ACCESS_TOKEN_HEADER]
        ));

        if ($token === '' || $this->patientAccessTokens->validate($token) === null) {
            return $this->deny('Invalid or expired patient access token.');
        }

        return true;
    }

    /**
     * @return bool|mixed
     */
    private function authorize(WP_REST_Request $request, string $bucket, int $limit, int $windowSeconds, bool $requireAttemptToken)
    {
        if (!$this->isSameOriginOrMissing($request)) {
            return $this->deny('Forbidden origin.');
        }

        if (!$this->consumeRateLimit($bucket, $limit, $windowSeconds)) {
            return $this->deny('Too many requests. Please wait a moment and try again.', 429);
        }

        if (!$requireAttemptToken) {
            return true;
        }

        $attemptId = trim((string) $this->readRequestValue($request, ['attempt_id'], []));
        $attemptToken = trim((string) $this->readRequestValue(
            $request,
            ['attempt_token'],
            [self::ATTEMPT_TOKEN_HEADER]
        ));

        if ($attemptId === '' || $attemptToken === '') {
            return $this->deny('Invalid booking attempt token.');
        }

        $attempt = $this->store->get($attemptId);
        $storedHash = trim((string) ($attempt['attempt_token_hash'] ?? ''));
        if ($storedHash === '' || !hash_equals($storedHash, self::hashAttemptToken($attemptToken))) {
            return $this->deny('Invalid booking attempt token.');
        }

        return true;
    }

    private function isSameOriginOrMissing(WP_REST_Request $request): bool
    {
        $origin = trim((string) $this->readHeader($request, 'origin'));
        if ($origin !== '' && !$this->isAllowedOrigin($origin)) {
            return false;
        }

        $referer = trim((string) $this->readHeader($request, 'referer'));
        if ($referer !== '' && !$this->isAllowedOrigin($referer)) {
            return false;
        }

        return true;
    }

    private function isAllowedOrigin(string $url): bool
    {
        return hash_equals(self::siteOrigin(), self::normalizeOrigin($url));
    }

    private function consumeRateLimit(string $bucket, int $limit, int $windowSeconds): bool
    {
        $clientKey = hash('sha256', $bucket . '|' . $this->clientFingerprint());
        $storageKey = 'cliniko_guard_' . substr($clientKey, 0, 40);
        $current = $this->getRateLimitValue($storageKey);
        $count = is_int($current) ? $current : (int) $current;

        if ($count >= $limit) {
            return false;
        }

        $this->setRateLimitValue($storageKey, $count + 1, $windowSeconds);
        return true;
    }

    /**
     * @param string[] $paramKeys
     * @param string[] $headerKeys
     * @return mixed|null
     */
    private function readRequestValue(WP_REST_Request $request, array $paramKeys, array $headerKeys)
    {
        foreach ($paramKeys as $key) {
            $value = $request->get_param($key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        foreach ($headerKeys as $key) {
            $value = $this->readHeader($request, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return mixed|null
     */
    private function readHeader(WP_REST_Request $request, string $key)
    {
        $value = $request->get_header($key);
        if ($value !== null) {
            return $value;
        }

        $headers = $request->get_headers();
        foreach ($headers as $headerKey => $headerValue) {
            if (strcasecmp((string) $headerKey, $key) === 0) {
                /** @var mixed $normalizedHeaderValue */
                $normalizedHeaderValue = $headerValue;
                if (is_array($normalizedHeaderValue)) {
                    return $normalizedHeaderValue[0] ?? null;
                }

                return $normalizedHeaderValue;
            }
        }

        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
        return $_SERVER[$serverKey] ?? null;
    }

    private function clientFingerprint(): string
    {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? 'unknown';

        if (is_string($ip) && strpos($ip, ',') !== false) {
            $parts = explode(',', $ip);
            $ip = trim($parts[0]);
        }

        $ua = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return strtolower((string) $ip) . '|' . substr(hash('sha256', $ua), 0, 16);
    }

    /**
     * @return mixed
     */
    private function getRateLimitValue(string $key)
    {
        if (function_exists('get_transient')) {
            return get_transient($key);
        }

        return function_exists('get_option') ? get_option($key, 0) : 0;
    }

    private function setRateLimitValue(string $key, int $value, int $ttl): void
    {
        if (function_exists('set_transient')) {
            set_transient($key, $value, $ttl);
            return;
        }

        if (function_exists('update_option')) {
            update_option($key, $value, false);
        }
    }

    /**
     * @return bool|mixed
     */
    private function deny(string $message, int $status = 403)
    {
        if (class_exists('\WP_Error')) {
            return new \WP_Error('rest_forbidden', $message, ['status' => $status]);
        }

        return false;
    }

    private static function secret(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }

        if (defined('AUTH_SALT') && AUTH_SALT) {
            return (string) AUTH_SALT;
        }

        return __FILE__;
    }

    private static function randomToken(int $bytes): string
    {
        try {
            $raw = random_bytes($bytes);
        } catch (\Throwable $e) {
            $raw = md5(uniqid('cliniko_guard_', true), true);
        }

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function siteOrigin(): string
    {
        $url = function_exists('get_site_url') ? (string) get_site_url() : 'http://localhost';
        return self::normalizeOrigin($url);
    }

    private static function normalizeOrigin(string $url): string
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $host === '' ? '' : $scheme . '://' . $host . $port;
    }
}
