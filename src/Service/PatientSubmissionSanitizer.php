<?php

namespace App\Service;

if (!defined('ABSPATH')) {
    exit;
}

class PatientSubmissionSanitizer
{
    /**
     * @param array<string,mixed> $patient
     * @return array<string,mixed>
     */
    public static function sanitize(array $patient): array
    {
        $normalized = [];

        foreach ([
            'first_name',
            'last_name',
            'email',
            'address_1',
            'address_2',
            'city',
            'state',
            'country',
            'practitioner_id',
            'appointment_start',
            'appointment_date',
        ] as $key) {
            if (!array_key_exists($key, $patient)) {
                continue;
            }

            $normalized[$key] = trim((string) ($patient[$key] ?? ''));
        }

        if (array_key_exists('medicare', $patient) || array_key_exists('medicare_reference_number', $patient)) {
            [$medicare, $medicareReferenceNumber] = self::normalizeMedicareFields(
                $patient['medicare'] ?? null,
                $patient['medicare_reference_number'] ?? null
            );

            $normalized['medicare'] = $medicare;
            $normalized['medicare_reference_number'] = $medicareReferenceNumber;
        }

        if (array_key_exists('phone', $patient)) {
            $digits = self::onlyDigits($patient['phone']);
            if (str_starts_with($digits, '61')) {
                $digits = '0' . substr($digits, 2);
            }
            $normalized['phone'] = substr($digits, 0, 10);
        }

        if (array_key_exists('post_code', $patient)) {
            $digits = self::onlyDigits($patient['post_code']);
            $normalized['post_code'] = $digits !== ''
                ? substr($digits, 0, 4)
                : trim((string) ($patient['post_code'] ?? ''));
        }

        if (array_key_exists('date_of_birth', $patient)) {
            $normalized['date_of_birth'] = self::normalizeDateYmd($patient['date_of_birth']);
        }

        if (
            empty($normalized['appointment_date'])
            && !empty($normalized['appointment_start'])
            && preg_match('/^(\d{4}-\d{2}-\d{2})/', (string) $normalized['appointment_start'], $matches)
        ) {
            $normalized['appointment_date'] = $matches[1];
        }

        foreach ($patient as $key => $value) {
            if (array_key_exists($key, $normalized)) {
                continue;
            }

            $normalized[$key] = is_string($value) ? trim($value) : $value;
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     */
    private static function onlyDigits($value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    /**
     * Format a medicare card number as `xxxx xxxxx x`.
     *
     * @param string $digits
     */
    private static function formatMedicareNumber(string $digits): string
    {
        $compact = substr(self::onlyDigits($digits), 0, 10);
        if ($compact === '') {
            return '';
        }

        $parts = array_filter([
            substr($compact, 0, 4),
            substr($compact, 4, 5),
            substr($compact, 9, 1),
        ], static fn ($part) => $part !== '');

        return implode(' ', $parts);
    }

    private static function normalizeMedicareFields($medicare, $referenceNumber): array
    {
        $medicareDigits = self::onlyDigits($medicare);
        $referenceDigits = self::onlyDigits($referenceNumber);

        if ($medicareDigits === '' && $referenceDigits === '') {
            return ['', ''];
        }

        if (strlen($medicareDigits) >= 10) {
            $combined = substr($medicareDigits, 0, 10);
            return [self::formatMedicareNumber($combined), substr($referenceDigits, 0, 1)];
        }

        if (strlen($medicareDigits) === 9 && $referenceDigits !== '') {
            $combined = substr($medicareDigits . $referenceDigits, 0, 10);
            return [self::formatMedicareNumber($combined), substr($referenceDigits, 0, 1)];
        }

        return [self::formatMedicareNumber($medicareDigits), substr($referenceDigits, 0, 1)];
    }

    /**
     * @param mixed $value
     */
    private static function normalizeDateYmd($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return self::isValidDateYmd($raw) ? $raw : '';
        }

        $compact = self::onlyDigits($raw);
        if (strlen($compact) === 8) {
            $ymd = substr($compact, 4, 4) . '-' . substr($compact, 2, 2) . '-' . substr($compact, 0, 2);
            return self::isValidDateYmd($ymd) ? $ymd : '';
        }

        return '';
    }

    private static function isValidDateYmd(string $value): bool
    {
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }
}
