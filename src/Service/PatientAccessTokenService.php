<?php

namespace App\Service;

if (!defined('ABSPATH')) {
    exit;
}

class PatientAccessTokenService
{
    public const ACCESS_TOKEN_TTL = 900;
    public const CHALLENGE_TOKEN_TTL = 600;
    public const HASH_FRAGMENT_KEY = 'es_patient_access_token';
    public const QUERY_PARAM_KEY = 'patient_access_token';

    /**
     * @param array<int|string,mixed> $patientIds
     */
    public function issue(
        string $email,
        string $appointmentTypeId = '',
        array $patientIds = [],
        array $latestContext = []
    ): string
    {
        return $this->issueScopedPayload(
            $email,
            $appointmentTypeId,
            $patientIds,
            $latestContext,
            'patient-access',
            self::ACCESS_TOKEN_TTL
        );
    }

    /**
     * @return array{challenge_token:string,code:string,expires_in:int}
     * @param array<int|string,mixed> $patientIds
     * @param array<string,mixed> $latestContext
     */
    public function issueChallenge(
        string $email,
        string $appointmentTypeId = '',
        array $patientIds = [],
        array $latestContext = []
    ): array
    {
        $code = $this->randomNumericCode();
        $token = $this->issueScopedPayload(
            $email,
            $appointmentTypeId,
            $patientIds,
            $latestContext,
            'patient-access-code',
            self::CHALLENGE_TOKEN_TTL,
            [
                'code' => $code,
            ]
        );

        return [
            'challenge_token' => $token,
            'code' => $code,
            'expires_in' => self::CHALLENGE_TOKEN_TTL,
        ];
    }

    /**
     * @param array<int|string,mixed> $patientIds
     * @param array<string,mixed> $latestContext
     * @param array<string,mixed> $patientPrefill
     */
    public function issueLookupCache(
        string $email,
        string $appointmentTypeId = '',
        array $patientIds = [],
        array $latestContext = [],
        array $patientPrefill = [],
        int $ttlSeconds = 0
    ): string {
        return $this->issueScopedPayload(
            $email,
            $appointmentTypeId,
            $patientIds,
            $latestContext,
            'patient-access-lookup',
            $ttlSeconds > 0 ? $ttlSeconds : self::CHALLENGE_TOKEN_TTL,
            [
                'patient_prefill' => $this->normalizePatientPrefill($patientPrefill),
            ]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function validate(string $token): ?array
    {
        $payload = $this->decryptPayload($token);
        if (!is_array($payload)) {
            [$payloadJson, $signature] = $this->splitSignedToken($token);
            if ($payloadJson === null || $signature === null) {
                return null;
            }

            $expected = hash_hmac('sha256', $payloadJson, $this->secret());
            if (!hash_equals($expected, $signature)) {
                return null;
            }

            $decoded = json_decode($payloadJson, true);
            if (!is_array($decoded)) {
                return null;
            }

            $payload = $decoded;
        }

        return $this->normalizeValidatedPayload($payload, 'patient-access', false);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function validateLookupCache(string $token): ?array
    {
        $payload = $this->decryptPayload($token);
        if (!is_array($payload)) {
            return null;
        }

        return $this->normalizeValidatedPayload($payload, 'patient-access-lookup', false);
    }

    public function ttl(): int
    {
        return self::ACCESS_TOKEN_TTL;
    }

    public function challengeTtl(): int
    {
        return self::CHALLENGE_TOKEN_TTL;
    }

    public function normalizeEmail(string $email): string
    {
        $normalized = trim(strtolower($email));
        if (function_exists('sanitize_email')) {
            $normalized = sanitize_email($normalized);
        }

        return $normalized;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function validateChallenge(string $challengeToken): ?array
    {
        $payload = $this->decryptPayload($challengeToken);
        if (!is_array($payload)) {
            return null;
        }

        return $this->normalizeValidatedPayload($payload, 'patient-access-code', true);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function verifyChallenge(string $challengeToken, string $email, string $code): ?array
    {
        $payload = $this->validateChallenge($challengeToken);
        if ($payload === null) {
            return null;
        }

        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedCode = preg_replace('/\D+/', '', $code);

        if (
            $normalizedEmail === ''
            || !hash_equals((string) $payload['sub'], $normalizedEmail)
            || !preg_match('/^\d{6}$/', $normalizedCode)
            || !hash_equals((string) $payload['code'], $normalizedCode)
        ) {
            return null;
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function normalizeValidatedPayload(array $payload, string $scope, bool $requiresCode): ?array
    {
        if (trim((string) ($payload['scope'] ?? '')) !== $scope) {
            return null;
        }

        $expiresAt = (int) ($payload['exp'] ?? 0);
        if ($expiresAt <= time()) {
            return null;
        }

        $host = trim((string) ($payload['host'] ?? ''));
        if ($host === '' || !hash_equals($host, $this->siteOrigin())) {
            return null;
        }

        $payload['sub'] = $this->normalizeEmail((string) ($payload['sub'] ?? ''));
        $payload['appointment_type_id'] = trim((string) ($payload['appointment_type_id'] ?? ''));
        $payload['patient_ids'] = $this->normalizePatientIds(
            is_array($payload['patient_ids'] ?? null) ? $payload['patient_ids'] : []
        );
        $payload['latest_booking_id'] = $this->normalizeId((string) ($payload['latest_booking_id'] ?? ''));
        $payload['latest_patient_id'] = $this->normalizeId((string) ($payload['latest_patient_id'] ?? ''));
        $payload['latest_starts_at'] = trim((string) ($payload['latest_starts_at'] ?? ''));
        $payload['latest_appointment_label'] = trim((string) ($payload['latest_appointment_label'] ?? ''));
        $payload['latest_practitioner_id'] = $this->normalizeId((string) ($payload['latest_practitioner_id'] ?? ''));
        $payload['latest_practitioner_name'] = trim((string) ($payload['latest_practitioner_name'] ?? ''));
        $payload['patient_prefill'] = $this->normalizePatientPrefill(
            is_array($payload['patient_prefill'] ?? null) ? $payload['patient_prefill'] : []
        );

        if ($payload['sub'] === '' || empty($payload['patient_ids'])) {
            return null;
        }

        if ($requiresCode) {
            $payload['code'] = preg_replace('/\D+/', '', (string) ($payload['code'] ?? ''));
            if (!preg_match('/^\d{6}$/', (string) $payload['code'])) {
                return null;
            }
        }

        return $payload;
    }

    /**
     * @param array<int|string,mixed> $patientIds
     * @return array<int,string>
     */
    public function normalizePatientIds(array $patientIds): array
    {
        $normalized = [];
        foreach ($patientIds as $patientId) {
            $value = $this->normalizeId((string) $patientId);
            if ($value === '' || isset($normalized[$value])) {
                continue;
            }

            $normalized[$value] = $value;
        }

        return array_values($normalized);
    }

    public function normalizeId(string $value): string
    {
        return trim($value);
    }

    /**
     * @param array<string,scalar|null> $extraParams
     */
    public function buildMagicLink(string $returnUrl, string $token, array $extraParams = []): string
    {
        return $this->buildMagicLinkUrl($returnUrl, $token, $extraParams, false);
    }

    /**
     * @param array<string,scalar|null> $extraParams
     */
    public function buildMagicHashLink(string $returnUrl, string $token, array $extraParams = []): string
    {
        return $this->buildMagicLinkUrl($returnUrl, $token, $extraParams, true);
    }

    /**
     * @param array<string,scalar|null> $extraParams
     */
    private function buildMagicLinkUrl(string $returnUrl, string $token, array $extraParams, bool $useHash): string
    {
        $base = $this->normalizeReturnUrl($returnUrl);
        if ($token === '') {
            return $base;
        }

        $parts = function_exists('wp_parse_url') ? wp_parse_url($base) : parse_url($base);
        if (!is_array($parts)) {
            return $base;
        }

        $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== ''
            ? $parts['path']
            : '/';

        $queryParams = [];
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $queryParams);
        }

        unset($queryParams[self::QUERY_PARAM_KEY], $queryParams['access_token'], $queryParams['request_id']);

        $params = [];
        if (!$useHash) {
            $params = $queryParams;
            $params[self::QUERY_PARAM_KEY] = $token;
            unset($params['access_token']);
        } else {
            $params[self::HASH_FRAGMENT_KEY] = $token;
        }

        foreach ($extraParams as $key => $value) {
            $normalizedKey = trim((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            if ($value === null || $value === '') {
                unset($params[$normalizedKey]);
                continue;
            }

            $params[$normalizedKey] = (string) $value;
        }

        $query = http_build_query($queryParams);
        $suffix = http_build_query($params);

        if ($useHash) {
            return $this->siteOrigin()
                . $path
                . ($query !== '' ? '?' . $query : '')
                . ($suffix !== '' ? '#' . $suffix : '');
        }

        return $this->siteOrigin()
            . $path
            . ($suffix !== '' ? '?' . $suffix : '');
    }

    public function normalizeReturnUrl(string $returnUrl): string
    {
        $fallback = $this->siteUrl();
        $raw = trim($returnUrl);
        if ($raw === '') {
            return $fallback;
        }

        if (!preg_match('#^https?://#i', $raw)) {
            $raw = '/' . ltrim($raw, '/');
            if (function_exists('home_url')) {
                $raw = (string) home_url($raw);
            } else {
                $raw = rtrim($fallback, '/') . $raw;
            }
        }

        $parts = function_exists('wp_parse_url') ? wp_parse_url($raw) : parse_url($raw);
        if (!is_array($parts)) {
            return $fallback;
        }

        $origin = $this->normalizeOrigin($raw);
        if ($origin === '' || !hash_equals($origin, $this->siteOrigin())) {
            return $fallback;
        }

        $path = isset($parts['path']) && is_string($parts['path']) && $parts['path'] !== ''
            ? $parts['path']
            : '/';
        $query = isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== ''
            ? '?' . $parts['query']
            : '';

        return $this->siteOrigin() . $path . $query;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function signPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '';
        }

        $signature = hash_hmac('sha256', $json, $this->secret());
        return $this->base64UrlEncode($json) . '.' . $signature;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function splitSignedToken(string $token): array
    {
        $parts = explode('.', trim($token), 2);
        if (count($parts) !== 2) {
            return [null, null];
        }

        $payloadJson = $this->base64UrlDecode($parts[0]);
        $signature = trim((string) $parts[1]);
        if ($payloadJson === null || $signature === '') {
            return [null, null];
        }

        return [$payloadJson, $signature];
    }

    /**
     * @param array<int|string,mixed> $patientIds
     * @param array<string,mixed> $latestContext
     * @param array<string,mixed> $extraClaims
     */
    private function issueScopedPayload(
        string $email,
        string $appointmentTypeId,
        array $patientIds,
        array $latestContext,
        string $scope,
        int $ttlSeconds,
        array $extraClaims = []
    ): string {
        $normalizedEmail = $this->normalizeEmail($email);
        $normalizedPatientIds = $this->normalizePatientIds($patientIds);
        if ($normalizedEmail === '' || empty($normalizedPatientIds)) {
            return '';
        }

        $payload = array_merge(
            [
                'sub' => $normalizedEmail,
                'exp' => time() + max(1, $ttlSeconds),
                'iat' => time(),
                'host' => $this->siteOrigin(),
                'scope' => $scope,
                'appointment_type_id' => trim($appointmentTypeId),
                'patient_ids' => $normalizedPatientIds,
                'nonce' => $this->randomToken(10),
                'latest_booking_id' => $this->normalizeId((string) ($latestContext['booking_id'] ?? '')),
                'latest_patient_id' => $this->normalizeId((string) ($latestContext['patient_id'] ?? '')),
                'latest_starts_at' => trim((string) ($latestContext['starts_at'] ?? '')),
                'latest_appointment_label' => trim((string) ($latestContext['appointment_label'] ?? '')),
                'latest_practitioner_id' => $this->normalizeId((string) ($latestContext['practitioner_id'] ?? '')),
                'latest_practitioner_name' => trim((string) ($latestContext['practitioner_name'] ?? '')),
            ],
            $extraClaims
        );

        return $this->encryptPayload($payload);
    }

    /**
     * @param array<string,mixed> $patientPrefill
     * @return array<string,string>
     */
    private function normalizePatientPrefill(array $patientPrefill): array
    {
        $allowedKeys = [
            'first_name',
            'last_name',
            'email',
            'phone',
            'medicare',
            'medicare_reference_number',
            'address_1',
            'address_2',
            'city',
            'state',
            'post_code',
            'country',
            'date_of_birth',
            'practitioner_id',
            'appointment_start',
            'appointment_date',
        ];

        $normalized = [];
        foreach ($allowedKeys as $key) {
            $normalized[$key] = trim((string) ($patientPrefill[$key] ?? ''));
        }

        return $normalized;
    }

    private function secret(): string
    {
        if (function_exists('wp_salt')) {
            return (string) wp_salt('auth');
        }

        if (defined('AUTH_SALT') && AUTH_SALT) {
            return (string) AUTH_SALT;
        }

        return __FILE__;
    }

    private function encryptionKey(): string
    {
        return hash_hmac('sha256', 'patient-access|enc', $this->secret(), true);
    }

    private function macKey(): string
    {
        return hash_hmac('sha256', 'patient-access|mac', $this->secret(), true);
    }

    private function siteUrl(): string
    {
        return function_exists('get_site_url') ? (string) get_site_url() : 'http://localhost';
    }

    private function siteOrigin(): string
    {
        return $this->normalizeOrigin($this->siteUrl());
    }

    private function normalizeOrigin(string $url): string
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

    private function randomToken(int $bytes): string
    {
        try {
            $raw = random_bytes($bytes);
        } catch (\Throwable $e) {
            $raw = md5(uniqid('patient_access_', true), true);
        }

        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function randomNumericCode(): string
    {
        try {
            $value = random_int(0, 999999);
        } catch (\Throwable $e) {
            $value = (int) (microtime(true) * 1000000) % 1000000;
        }

        return str_pad((string) $value, 6, '0', STR_PAD_LEFT);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $mod = strlen($padded) % 4;
        if ($mod > 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }

        $decoded = base64_decode($padded, true);
        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function encryptPayload(array $payload): string
    {
        if (!function_exists('openssl_encrypt')) {
            return '';
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '';
        }

        $cipherMethod = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($cipherMethod);
        if ($ivLength <= 0) {
            return '';
        }

        try {
            $iv = random_bytes($ivLength);
        } catch (\Throwable $e) {
            return '';
        }

        $ciphertext = openssl_encrypt(
            $json,
            $cipherMethod,
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if (!is_string($ciphertext) || $ciphertext === '') {
            return '';
        }

        $mac = hash_hmac('sha256', $iv . $ciphertext, $this->macKey(), true);
        return $this->base64UrlEncode($iv . $mac . $ciphertext);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decryptPayload(string $token): ?array
    {
        if (!function_exists('openssl_decrypt')) {
            return null;
        }

        $decoded = $this->base64UrlDecode(trim($token));
        if ($decoded === null || $decoded === '') {
            return null;
        }

        $cipherMethod = 'aes-256-cbc';
        $ivLength = openssl_cipher_iv_length($cipherMethod);
        if ($ivLength <= 0 || strlen($decoded) <= ($ivLength + 32)) {
            return null;
        }

        $iv = substr($decoded, 0, $ivLength);
        $mac = substr($decoded, $ivLength, 32);
        $ciphertext = substr($decoded, $ivLength + 32);

        $expectedMac = hash_hmac('sha256', $iv . $ciphertext, $this->macKey(), true);
        if (!hash_equals($expectedMac, $mac)) {
            return null;
        }

        $json = openssl_decrypt(
            $ciphertext,
            $cipherMethod,
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv
        );

        if (!is_string($json) || $json === '') {
            return null;
        }

        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }
}
