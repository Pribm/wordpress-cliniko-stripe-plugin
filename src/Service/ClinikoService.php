<?php
namespace App\Service;

use App\Contracts\ApiClientInterface;
use App\DTO\AvailableTimeResultDTO;
use App\DTO\CreatePatientDTO;
use App\DTO\NextAvailableTimeDTO;
use App\Model\Patient;

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

        return Patient::create($createPatientDTO, $this->client);
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
}
