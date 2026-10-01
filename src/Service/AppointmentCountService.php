<?php

namespace App\Service;

use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;
use App\Exception\ApiException;
use App\Support\Auth;

if (!defined('ABSPATH')) {
    exit;
}

final class AppointmentCountService
{
    private ApiClientInterface $client;
    /** @var array<string,array{total:int,upcoming:int,completed:int}> */
    private static array $requestCache = [];

    public function __construct(?ApiClientInterface $client = null)
    {
        $this->client = $client ?: (function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 900)
            : Client::getInstance());
    }

    /** @return array{total:int,upcoming:int,completed:int} */
    public function forAuthenticatedPatient(): array
    {
        $patient = Auth::user();
        $patientId = $patient ? (string) $patient->getId() : '';
        if ($patientId === '') {
            return self::emptyCounts();
        }

        return $this->forPatient($patientId);
    }

    /** @return array{total:int,upcoming:int,completed:int} */
    public function forPatient(string $patientId): array
    {
        $patientId = trim($patientId);
        if ($patientId === '') {
            return self::emptyCounts();
        }

        $cacheKey = spl_object_id($this->client) . ':' . $patientId;
        if (isset(self::$requestCache[$cacheKey])) {
            return self::$requestCache[$cacheKey];
        }

        $counts = self::emptyCounts();
        $now = time();
        $url = 'individual_appointments?per_page=100&sort=starts_at&order=asc&q[]='
            . rawurlencode('patient_id:=' . $patientId);
        $seenUrls = [];

        while ($url !== '') {
            if (isset($seenUrls[$url])) {
                break;
            }
            $seenUrls[$url] = true;
            $response = $this->client->get($url);
            if (!$response->isSuccessful()) {
                throw new ApiException('Failed to count patient appointments.', [
                    'status_code' => $response->statusCode,
                    'api_error' => $response->error,
                ]);
            }

            $data = is_array($response->data) ? $response->data : [];
            $appointments = is_array($data['individual_appointments'] ?? null)
                ? $data['individual_appointments']
                : [];
            foreach ($appointments as $appointment) {
                if (!is_array($appointment)) {
                    continue;
                }
                $counts['total']++;
                $startsAt = strtotime((string) ($appointment['starts_at'] ?? '')) ?: 0;
                $endsAt = strtotime((string) ($appointment['ends_at'] ?? '')) ?: 0;
                if ($startsAt > $now) {
                    $counts['upcoming']++;
                }
                if (
                    $endsAt > 0
                    && $endsAt < $now
                    && array_key_exists('did_not_arrive', $appointment)
                    && $appointment['did_not_arrive'] === false
                ) {
                    $counts['completed']++;
                }
            }

            $next = $data['links']['next'] ?? null;
            $url = is_string($next) && trim($next) !== '' ? trim($next) : '';
        }

        self::$requestCache[$cacheKey] = $counts;
        return $counts;
    }

    /** @return array{total:int,upcoming:int,completed:int} */
    private static function emptyCounts(): array
    {
        return ['total' => 0, 'upcoming' => 0, 'completed' => 0];
    }
}
