<?php

namespace App\Support;

use App\Model\Patient;
use App\Exception\ApiException;
use App\Service\PatientService;

if (!defined('ABSPATH')) {
    exit;
}

final class Auth
{
    private static ?Patient $patient = null;
    private static bool $resolved = false;
    /** @var array<string,mixed>|null */
    private static ?array $patientData = null;
    private static bool $dataResolved = false;
    private static bool $clinikoUnavailable = false;
    private static ?ApiException $resolutionException = null;

    public static function user(): ?Patient
    {
        self::protectPrivatePage();
        if (self::$resolved) {
            if (self::$resolutionException !== null) {
                throw self::$resolutionException;
            }
            return self::$patient;
        }

        self::$resolved = true;
        try {
            $resolved = (new PatientService())->resolvePatientForUser(
                function_exists('get_current_user_id') ? (int) get_current_user_id() : 0
            );
            self::$patient = $resolved['patient'] ?? null;
            self::$patientData = $resolved['data'] ?? null;
            self::$dataResolved = true;
        } catch (ApiException $exception) {
            self::$clinikoUnavailable = true;
            self::$resolutionException = $exception;
            throw $exception;
        } catch (\Throwable $exception) {
            // Treat unexpected response/hydration failures as unavailable for
            // the current request; they must never be interpreted as a
            // confirmed missing patient.
            self::$clinikoUnavailable = true;
            self::$resolutionException = new ApiException(
                'Cliniko patient response could not be resolved.',
                ['status_code' => 503],
                503,
                $exception
            );
            throw self::$resolutionException;
        }
        if (self::$patient === null) {
            PatientDataRedirect::maybeRedirect();
        }
        return self::$patient;
    }

    /** @return array<string,mixed>|null */
    public static function patientData(): ?array
    {
        self::protectPrivatePage();
        if (self::$dataResolved) {
            return self::$patientData;
        }

        self::user();
        self::$dataResolved = true;
        if (self::$patientData === null) {
            PatientDataRedirect::maybeRedirect();
        }
        return self::$patientData;
    }

    public static function clinikoUnavailable(): bool
    {
        return self::$clinikoUnavailable;
    }

    private static function protectPrivatePage(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (function_exists('nocache_headers') && !headers_sent()) {
            nocache_headers();
        }
    }
}
