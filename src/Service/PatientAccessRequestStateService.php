<?php

namespace App\Service;

if (!defined('ABSPATH')) exit;

/**
 * Compatibility shell: the unsafe cross-device bearer handoff is retired.
 * Email-code verification and direct magic-link access still use PatientAccessService.
 */
class PatientAccessRequestStateService
{
    public function normalizeRequestId(string $requestId): string
    {
        $id = trim($requestId);
        return preg_match('/^[A-Za-z0-9_-]{12,160}$/D', $id) ? $id : '';
    }

    public function start(string $requestId, int $ttlSeconds = 0): bool { return false; }

    public function getStatus(string $requestId, bool $touch = true): array { return $this->retired(); }

    public function complete(string $requestId, string $accessToken): array { return $this->retired(); }

    private function retired(): array
    {
        return ['ok' => false, 'status' => 410, 'message' => 'Cross-device patient access handoff has been retired. Use email code verification.'];
    }
}
