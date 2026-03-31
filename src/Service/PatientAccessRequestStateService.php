<?php

namespace App\Service;

if (!defined('ABSPATH')) {
    exit;
}

class PatientAccessRequestStateService
{
    private const STORAGE_KEY_PREFIX = 'cliniko_patient_access_request_';
    private const STATUS_PENDING = 'pending';
    private const STATUS_COMPLETED = 'completed';
    private const MIN_TTL = 60;

    private PatientAccessTokenService $tokens;

    public function __construct(?PatientAccessTokenService $tokens = null)
    {
        $this->tokens = $tokens ?: new PatientAccessTokenService();
    }

    public function normalizeRequestId(string $requestId): string
    {
        $normalized = trim($requestId);
        if ($normalized === '') {
            return '';
        }

        if (!preg_match('/^[A-Za-z0-9_-]{12,160}$/', $normalized)) {
            return '';
        }

        return $normalized;
    }

    public function start(string $requestId, int $ttlSeconds = 0): bool
    {
        $normalizedRequestId = $this->normalizeRequestId($requestId);
        if ($normalizedRequestId === '') {
            return false;
        }

        $now = time();
        $ttl = $this->normalizeTtl($ttlSeconds);

        return $this->write(
            $normalizedRequestId,
            [
                'request_id' => $normalizedRequestId,
                'status' => self::STATUS_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
                'completed_at' => 0,
                'last_seen_at' => 0,
                'expires_at' => $now + $ttl,
                'access_token' => '',
                'access_token_expires_at' => 0,
            ],
            $ttl
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function getStatus(string $requestId, bool $touch = true): array
    {
        $normalizedRequestId = $this->normalizeRequestId($requestId);
        if ($normalizedRequestId === '') {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Invalid patient access request.',
            ];
        }

        $record = $this->read($normalizedRequestId);
        if ($record === null) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Patient access request not found.',
            ];
        }

        $now = time();
        if ((int) ($record['expires_at'] ?? 0) <= $now) {
            $this->delete($normalizedRequestId);

            return [
                'ok' => true,
                'status' => 200,
                'request_id' => $normalizedRequestId,
                'state' => 'expired',
                'expires_in' => 0,
                'message' => 'The secure link request has expired.',
            ];
        }

        if ($touch) {
            $record['last_seen_at'] = $now;
            $record['updated_at'] = $now;
            $this->write(
                $normalizedRequestId,
                $record,
                $this->ttlFromExpiresAt((int) ($record['expires_at'] ?? 0))
            );
        }

        $response = [
            'ok' => true,
            'status' => 200,
            'request_id' => $normalizedRequestId,
            'state' => (string) ($record['status'] ?? self::STATUS_PENDING),
            'expires_in' => max(0, ((int) ($record['expires_at'] ?? $now)) - $now),
            'last_seen_at' => (int) ($record['last_seen_at'] ?? 0),
            'completed_at' => (int) ($record['completed_at'] ?? 0),
        ];

        if (($record['status'] ?? '') === self::STATUS_COMPLETED) {
            $response['access_token'] = (string) ($record['access_token'] ?? '');
            $response['access_token_expires_in'] = max(
                0,
                ((int) ($record['access_token_expires_at'] ?? $now)) - $now
            );
        }

        return $response;
    }

    /**
     * @return array<string,mixed>
     */
    public function complete(string $requestId, string $accessToken): array
    {
        $normalizedRequestId = $this->normalizeRequestId($requestId);
        if ($normalizedRequestId === '') {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Invalid patient access request.',
            ];
        }

        $normalizedToken = trim($accessToken);
        $claims = $normalizedToken !== '' ? $this->tokens->validate($normalizedToken) : null;
        if ($claims === null) {
            return [
                'ok' => false,
                'status' => 403,
                'message' => 'Invalid or expired patient access token.',
            ];
        }

        $now = time();
        $existing = $this->read($normalizedRequestId);
        $createdAt = (int) ($existing['created_at'] ?? $now);
        $lastSeenAt = (int) ($existing['last_seen_at'] ?? 0);
        $tokenExpiresAt = max($now, (int) ($claims['exp'] ?? ($now + $this->tokens->ttl())));
        $expiresAt = max($tokenExpiresAt, $now + self::MIN_TTL);

        $record = [
            'request_id' => $normalizedRequestId,
            'status' => self::STATUS_COMPLETED,
            'created_at' => $createdAt,
            'updated_at' => $now,
            'completed_at' => $now,
            'last_seen_at' => $lastSeenAt,
            'expires_at' => $expiresAt,
            'access_token' => $normalizedToken,
            'access_token_expires_at' => $tokenExpiresAt,
        ];

        if (!$this->write($normalizedRequestId, $record, $this->ttlFromExpiresAt($expiresAt))) {
            return [
                'ok' => false,
                'status' => 500,
                'message' => 'We could not update the secure link request.',
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'request_id' => $normalizedRequestId,
            'state' => self::STATUS_COMPLETED,
            'completed_at' => $now,
            'expires_in' => max(0, $expiresAt - $now),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function read(string $requestId): ?array
    {
        $value = function_exists('get_transient')
            ? get_transient($this->storageKey($requestId))
            : null;

        if (!is_array($value)) {
            return null;
        }

        return [
            'request_id' => (string) ($value['request_id'] ?? ''),
            'status' => (string) ($value['status'] ?? self::STATUS_PENDING),
            'created_at' => (int) ($value['created_at'] ?? 0),
            'updated_at' => (int) ($value['updated_at'] ?? 0),
            'completed_at' => (int) ($value['completed_at'] ?? 0),
            'last_seen_at' => (int) ($value['last_seen_at'] ?? 0),
            'expires_at' => (int) ($value['expires_at'] ?? 0),
            'access_token' => (string) ($value['access_token'] ?? ''),
            'access_token_expires_at' => (int) ($value['access_token_expires_at'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $record
     */
    private function write(string $requestId, array $record, int $ttlSeconds): bool
    {
        if (!function_exists('set_transient')) {
            return false;
        }

        return (bool) set_transient(
            $this->storageKey($requestId),
            $record,
            $this->normalizeTtl($ttlSeconds)
        );
    }

    private function delete(string $requestId): void
    {
        if (function_exists('delete_transient')) {
            delete_transient($this->storageKey($requestId));
        }
    }

    private function storageKey(string $requestId): string
    {
        return self::STORAGE_KEY_PREFIX . substr(hash('sha256', $requestId), 0, 40);
    }

    private function normalizeTtl(int $ttlSeconds): int
    {
        $ttl = $ttlSeconds > 0 ? $ttlSeconds : $this->tokens->ttl();
        return max(self::MIN_TTL, $ttl);
    }

    private function ttlFromExpiresAt(int $expiresAt): int
    {
        return $this->normalizeTtl(max(0, $expiresAt - time()));
    }
}
