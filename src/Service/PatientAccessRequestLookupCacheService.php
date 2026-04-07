<?php

namespace App\Service;

if (!defined('ABSPATH')) {
    exit;
}

class PatientAccessRequestLookupCacheService
{
    private const STORAGE_KEY_PREFIX = 'cliniko_patient_access_lookup_';
    private const MIN_TTL = 60;

    private PatientAccessTokenService $tokens;

    public function __construct(?PatientAccessTokenService $tokens = null)
    {
        $this->tokens = $tokens ?: new PatientAccessTokenService();
    }

    /**
     * @return array{patient_ids:array<int,string>,latest_booking_context:array<string,mixed>,patient_prefill:array<string,string>}|null
     */
    public function load(
        string $email,
        string $appointmentTypeId,
        int $ttlSeconds = 0,
        bool $refreshTtl = true
    ): ?array
    {
        $normalizedEmail = $this->tokens->normalizeEmail($email);
        $normalizedAppointmentTypeId = trim($appointmentTypeId);
        if ($normalizedEmail === '' || $normalizedAppointmentTypeId === '') {
            return null;
        }

        $stored = function_exists('get_transient')
            ? get_transient($this->storageKey($normalizedEmail, $normalizedAppointmentTypeId))
            : null;

        $token = is_string($stored) ? trim($stored) : '';
        if ($token === '') {
            return null;
        }

        $claims = $this->tokens->validateLookupCache($token);
        if ($claims === null) {
            $this->delete($normalizedEmail, $normalizedAppointmentTypeId);
            return null;
        }

        if (
            !hash_equals($normalizedEmail, (string) ($claims['sub'] ?? ''))
            || !hash_equals($normalizedAppointmentTypeId, trim((string) ($claims['appointment_type_id'] ?? '')))
        ) {
            $this->delete($normalizedEmail, $normalizedAppointmentTypeId);
            return null;
        }

        $patientIds = $this->tokens->normalizePatientIds(
            is_array($claims['patient_ids'] ?? null) ? $claims['patient_ids'] : []
        );
        $latestBookingContext = [
            'booking_id' => trim((string) ($claims['latest_booking_id'] ?? '')),
            'patient_id' => trim((string) ($claims['latest_patient_id'] ?? '')),
            'starts_at' => trim((string) ($claims['latest_starts_at'] ?? '')),
            'appointment_label' => trim((string) ($claims['latest_appointment_label'] ?? '')),
            'practitioner_id' => trim((string) ($claims['latest_practitioner_id'] ?? '')),
            'practitioner_name' => trim((string) ($claims['latest_practitioner_name'] ?? '')),
        ];
        $patientPrefill = is_array($claims['patient_prefill'] ?? null) ? $claims['patient_prefill'] : [];

        if (empty($patientIds) || $latestBookingContext['booking_id'] === '' || $latestBookingContext['patient_id'] === '') {
            $this->delete($normalizedEmail, $normalizedAppointmentTypeId);
            return null;
        }

        if ($refreshTtl) {
            $this->store(
                $normalizedEmail,
                $normalizedAppointmentTypeId,
                $patientIds,
                $latestBookingContext,
                $patientPrefill,
                $ttlSeconds
            );
        }

        return [
            'patient_ids' => $patientIds,
            'latest_booking_context' => $latestBookingContext,
            'patient_prefill' => $patientPrefill,
        ];
    }

    /**
     * @param array<int,string> $patientIds
     * @param array<string,mixed> $latestBookingContext
     * @param array<string,mixed> $patientPrefill
     */
    public function store(
        string $email,
        string $appointmentTypeId,
        array $patientIds,
        array $latestBookingContext,
        array $patientPrefill = [],
        int $ttlSeconds = 0
    ): bool {
        $normalizedEmail = $this->tokens->normalizeEmail($email);
        $normalizedAppointmentTypeId = trim($appointmentTypeId);
        $normalizedPatientIds = $this->tokens->normalizePatientIds($patientIds);
        if ($normalizedEmail === '' || $normalizedAppointmentTypeId === '' || empty($normalizedPatientIds)) {
            return false;
        }

        $token = $this->tokens->issueLookupCache(
            $normalizedEmail,
            $normalizedAppointmentTypeId,
            $normalizedPatientIds,
            $latestBookingContext,
            $patientPrefill,
            $this->normalizeTtl($ttlSeconds)
        );
        if ($token === '') {
            return false;
        }

        return $this->touch($normalizedEmail, $normalizedAppointmentTypeId, $token, $ttlSeconds);
    }

    public function delete(string $email, string $appointmentTypeId): void
    {
        if (!function_exists('delete_transient')) {
            return;
        }

        delete_transient($this->storageKey(
            $this->tokens->normalizeEmail($email),
            trim($appointmentTypeId)
        ));
    }

    private function touch(string $email, string $appointmentTypeId, string $token, int $ttlSeconds): bool
    {
        if (!function_exists('set_transient')) {
            return false;
        }

        return (bool) set_transient(
            $this->storageKey($email, $appointmentTypeId),
            $token,
            $this->normalizeTtl($ttlSeconds)
        );
    }

    private function normalizeTtl(int $ttlSeconds): int
    {
        return max(self::MIN_TTL, $ttlSeconds > 0 ? $ttlSeconds : $this->tokens->challengeTtl());
    }

    private function storageKey(string $email, string $appointmentTypeId): string
    {
        return self::STORAGE_KEY_PREFIX . substr(hash('sha256', $email . '|' . $appointmentTypeId), 0, 40);
    }
}
