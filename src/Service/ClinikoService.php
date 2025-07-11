<?php
namespace App\Service;

use App\DTO\AvailableTimeResultDTO;
use App\DTO\CreatePatientDTO;
use App\DTO\NextAvailableTimeDTO;
use App\Model\Patient;

if (!defined('ABSPATH')) exit;

use App\Client\ClinikoClient;
use App\Model\AppointmentType;

class ClinikoService
{
    protected ClinikoClient $client;

    public function __construct()
    {
        $this->client = ClinikoClient::getInstance();
    }

    public function getAppointmentTypes()
    {
        $client = ClinikoClient::getInstance();
        return AppointmentType::all($client);
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

    public function findPatientByNameAndEmail(string $firstName, string $lastName, string $email): ?array
    {
        $query = "{$firstName} {$lastName} {$email}";
        $endpoint = 'patients?search=' . urlencode($query);

        $response = $this->client->get($endpoint);

        if (!$response) {
            return null;
        }

        if (!empty($response['patients'])) {
            return $response['patients'][0];
        }

        return null;
    }

     public function getNextAvailableTime(
        string $businessId,
        string $practitionerId,
        string $appointmentTypeId,
        string $from,
        string $to,
        ClinikoClient $client
    ): ?NextAvailableTimeDTO
    {
        $query = http_build_query([
            'from' => $from,
            'to' => $to
        ]);

        $endpoint = "businesses/{$businessId}/practitioners/{$practitionerId}/appointment_types/{$appointmentTypeId}/next_available_time?{$query}";
        $data = $client->get($endpoint);
        if (!isset($data['appointment_start'])) return null;
        return NextAvailableTimeDTO::fromArray($data);
    }
}
