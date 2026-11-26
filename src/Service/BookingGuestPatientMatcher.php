<?php

namespace App\Service;

use App\Contracts\ApiClientInterface;
use App\Model\Patient;

if (!defined('ABSPATH')) {
    exit;
}

/** Match booking details for pricing only. */
final class BookingGuestPatientMatcher
{
    private const LOOKUP_LIMIT = 100;
    private const MATCH_FIELDS = ['email', 'first_name', 'last_name'];

    public function match(mixed $patientDetails, ApiClientInterface $client): ?Patient
    {
        if (!is_array($patientDetails)) {
            return null;
        }
        $submitted = self::normalizeContact($patientDetails);
        $dateOfBirth = $patientDetails['date_of_birth'] ?? null;
        $birthDate = is_string($dateOfBirth) ? PatientSubmissionSanitizer::normalizeDateYmd($dateOfBirth) : '';
        if ($birthDate === '' || in_array('', $submitted, true) || !filter_var($submitted['email'], FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $query = '?per_page=' . self::LOOKUP_LIMIT . '&q[]=' . rawurlencode('email:=' . $submitted['email'])
            . '&q[]=' . rawurlencode('archived_at:!?');
        $patients = Patient::queryManyByQueryString($query, $client, true);
        if (count($patients) >= self::LOOKUP_LIMIT) {
            return null;
        }

        $match = null;
        foreach ($patients as $patient) {
            $stored = self::normalizeContact([
                'email' => $patient->getEmail(),
                'first_name' => $patient->getFirstName(),
                'last_name' => $patient->getLastName(),
            ]);
            if ($stored !== $submitted || $patient->getDateOfBirth() !== $birthDate || $patient->getId() === '') {
                continue;
            }
            if ($match !== null) {
                return null;
            }
            $match = $patient;
        }
        return $match;
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,string>
     */
    private static function normalizeContact(array $details): array
    {
        $contact = [];
        foreach (self::MATCH_FIELDS as $field) {
            $raw = $details[$field] ?? null;
            $value = is_string($raw) ? trim(preg_replace('/\s+/u', ' ', $raw) ?? '') : '';
            $contact[$field] = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        }
        return $contact;
    }
}
