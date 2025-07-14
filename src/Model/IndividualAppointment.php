<?php
namespace App\Model;

use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;
use App\DTO\IndividualAppointmentDTO;

if (!defined('ABSPATH')) exit;

class IndividualAppointment
{
    public function __construct(
        protected IndividualAppointmentDTO $dto,
        protected ApiClientInterface $client
    ) {}

    public static function find(string $id, ApiClientInterface $client): ?self
    {
        $response = $client->get("individual_appointments/{$id}");

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(IndividualAppointmentDTO::fromArray($response->data), $client);
    }

    /**
     * Create an individual appointment in Cliniko.
     * @param ApiClientInterface $client Instance of the Cliniko API client.
     *
     * @return self|null Instantiated IndividualAppointment model or null if failed.
     */
    public static function create(array $data, ApiClientInterface $client): ?self
    {
        $response = $client->post('individual_appointments', $data);

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(IndividualAppointmentDTO::fromArray($response->data), $client);
    }

    public function getId(): string
    {
        return $this->dto->id;
    }

    public function getStartsAt(): string
    {
        return $this->dto->startsAt;
    }

    public function getEndsAt(): string
    {
        return $this->dto->endsAt;
    }

    public function getNotes(): ?string
    {
        return $this->dto->notes;
    }

    public function getTelehealthUrl(): ?string
    {
        return $this->dto->telehealthUrl;
    }

    public function getRepeatRule(): ?array
    {
        return $this->dto->repeatRule;
    }

    public function getPatient(): ?Patient
    {
        if (!$this->dto->patientUrl) {
            return null;
        }

        return Patient::findFromUrl($this->dto->patientUrl, $this->client);
    }

    public function getPractitioner(): ?Practitioner
    {
        if (!$this->dto->practitionerUrl) {
            return null;
        }

        return Practitioner::findFromUrl($this->dto->practitionerUrl, $this->client);
    }

    public function getAppointmentType(): ?AppointmentType
    {
        if (!$this->dto->appointmentTypeUrl) {
            return null;
        }

        return AppointmentType::findFromUrl($this->dto->appointmentTypeUrl, $this->client);
    }

    public function getBusiness(): ?Business
    {
        if (!$this->dto->businessUrl) {
            return null;
        }

        return Business::findFromUrl($this->dto->businessUrl, $this->client);
    }
}
