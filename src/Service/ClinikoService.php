<?php
namespace App\Service;

use App\DTO\AvailableTimeResultDTO;
use App\DTO\NextAvailableTimeDTO;

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

    public function createPatient(array $data): ?array
    {
        return $this->client->post('patients', $data);
    }

    public function createAppointmentWithPatient(array $data): ?array
    {
        $firstName = $data['first_name'] ?? '';
        $lastName = $data['last_name'] ?? '';
        $email = $data['email'] ?? '';

        $existing = $this->findPatientByNameAndEmail($firstName, $lastName, $email);

        if ($existing && !empty($existing['id'])) {
            $patientId = $existing['id'];
        } else {
            $patient = $this->createPatient($data);
            $patientId = $patient['id'] ?? null;
        }

        if (!$patientId) {
            throw new \RuntimeException('Failed to identify or create the patient.');
        }

        $slot = $this->findNextAvailableSlot($data['appointment_type_id']);

        if (!$slot) {
            throw new \RuntimeException('No available appointment slots found.');
        }

        $appointmentPayload = [
            'patient_id' => $patientId,
            'appointment_type_id' => $data['appointment_type_id'],
            'start_time' => $slot['start_time'],
            'practitioner_id' => $slot['practitioner_id'],
        ];

        return $this->client->post('appointments', $appointmentPayload);
    }

    public function listAvailableTimes(
        string $businessId,
        string $practitionerId,
        string $appointmentTypeId,
        string $from,
        string $to,
        ClinikoClient $client,
        int $page = 1,
        int $perPage = 100
    ): AvailableTimeResultDTO
    {
        $query = http_build_query([
            'from' => $from,
            'to' => $to,
            'page' => $page,
            'per_page' => $perPage
        ]);

        $endpoint = "businesses/{$businessId}/practitioners/{$practitionerId}/appointment_types/{$appointmentTypeId}/available_times?$query";

        $response = $client->get($endpoint);

        return AvailableTimeResultDTO::fromArray($response);
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
