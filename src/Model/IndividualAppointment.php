<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;
use App\Client\ClinikoClient;
use App\DTO\IndividualAppointmentDTO;

class IndividualAppointment
{
    public function __construct(
        protected IndividualAppointmentDTO $dto,
        protected ClinikoClient $client
    ) {
    }

    public static function find(string $id, ClinikoClient $client): ?self
    {
        $data = $client->get("individual_appointments/{$id}");
        return new self(IndividualAppointmentDTO::fromArray($data), $client);
    }

    /**
     * Create an individual appointment in Cliniko.
     * @param ClinikoClient $client Instance of the Cliniko API client.
     *
     * @return self Instantiated IndividualAppointment model containing the created appointment's data.
     */
    public static function create(array $data, ClinikoClient $client): self
    {
        
        $response = $client->post('individual_appointments', $data);
        return new self(IndividualAppointmentDTO::fromArray($response), $client);
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

    // Lazy loading (exemplo: paciente)
    public function getPatient(): ?Patient
    {
        if (!$this->dto->patientUrl)
            return null;
        return Patient::findFromUrl($this->dto->patientUrl, $this->client);
    }

    public function getPractitioner(): ?Practitioner
    {
        if (!$this->dto->practitionerUrl)
            return null;
        return Practitioner::findFromUrl($this->dto->practitionerUrl, $this->client);
    }

    public function getAppointmentType(): ?AppointmentType
    {
        if (!$this->dto->appointmentTypeUrl)
            return null;
        return AppointmentType::findFromUrl($this->dto->appointmentTypeUrl, $this->client);
    }

    public function getBusiness(): ?Business
    {
        if (!$this->dto->businessUrl)
            return null;
        return Business::findFromUrl($this->dto->businessUrl, $this->client);
    }

    public function getRepeatRule(): ?array
    {
        return $this->dto->repeatRule;
    }
}
