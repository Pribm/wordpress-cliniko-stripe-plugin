<?php
namespace App\Service;

use App\Contracts\ApiClientInterface;
use App\DTO\AvailableTimeResultDTO;
use App\DTO\CreatePatientDTO;
use App\DTO\NextAvailableTimeDTO;
use App\Model\AvailableTimes;
use App\Model\Patient;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

if (!defined('ABSPATH')) exit;

use App\Client\Cliniko\Client;
use App\Model\AppointmentType;

class ClinikoService
{
    protected ApiClientInterface $client;

    public function __construct()
    {
        $this->client = Client::getInstance();
    }

    public function findOrCreatePatient(CreatePatientDTO $createPatientDTO){
        $patientData = Patient::query(
            "?q[]=email:=".$createPatientDTO->email.
            "&q[]=first_name:=".$createPatientDTO->firstName.
            "&q[]=last_name:=".$createPatientDTO->lastName, $this->client);

            if($patientData){
                return $patientData;
            }
            
            
            $patientData = Patient::create($createPatientDTO, $this->client);
          
            return $patientData;
    }

    public function getNextAvailableTime(
        string $businessId,
        string $practitionerId,
        string $appointmentTypeId,
        string $from,
        string $to,
        ApiClientInterface $client
    ): ?NextAvailableTimeDTO
    {
        $query = http_build_query([
            'from' => $from,
            'to' => $to
        ]);

        $endpoint = "businesses/{$businessId}/practitioners/{$practitionerId}/appointment_types/{$appointmentTypeId}/next_available_time?{$query}";
        $data = $client->get($endpoint)->data;
        if (!isset($data['appointment_start'])) return null;
        return NextAvailableTimeDTO::fromArray($data);
    }

    /**
     * Returns availability counts grouped by date and period (morning/afternoon/evening).
     *
     * @return array<string, array{morning:int, afternoon:int, evening:int}>
     */
    public function getAvailabilitySummaryForRange(
        string $businessId,
        string $practitionerId,
        string $appointmentTypeId,
        string $from,
        string $to,
        ApiClientInterface $client,
        ?DateTimeZone $timezone = null,
        int $perPage = 100
    ): array {
        $tz = $timezone ?: new DateTimeZone('UTC');
        $start = new DateTimeImmutable($from, $tz);
        $end = new DateTimeImmutable($to, $tz);

        $summary = [];
        $cursor = $start;

        while ($cursor <= $end) {
            $chunkEnd = $cursor->add(new DateInterval('P6D'));
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }

            $page = 1;
            $totalPages = 1;
            do {
                $available = AvailableTimes::findForPractitionerAppointmentType(
                    $businessId,
                    $practitionerId,
                    $appointmentTypeId,
                    $cursor->format('Y-m-d'),
                    $chunkEnd->format('Y-m-d'),
                    $client,
                    $page,
                    $perPage
                );

                if (!$available) {
                    break;
                }

                foreach ($available->getAvailableTimes() as $slot) {
                    $iso = $slot->appointmentStart ?? null;
                    if (!$iso) {
                        continue;
                    }

                    try {
                        $dt = new DateTimeImmutable($iso);
                        $dt = $dt->setTimezone($tz);
                    } catch (\Throwable $e) {
                        continue;
                    }

                    $dateKey = $dt->format('Y-m-d');
                    $hour = (int) $dt->format('G');
                    $period = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');

                    if (!isset($summary[$dateKey])) {
                        $summary[$dateKey] = ['morning' => 0, 'afternoon' => 0, 'evening' => 0];
                    }
                    $summary[$dateKey][$period] += 1;
                }

                $totalEntries = $available->getTotalEntries();
                $totalPages = max(1, (int) ceil($totalEntries / $perPage));
                $page++;
            } while ($page <= $totalPages);

            $cursor = $chunkEnd->add(new DateInterval('P1D'));
        }

        return $summary;
    }
}
