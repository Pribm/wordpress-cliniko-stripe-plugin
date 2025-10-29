<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\AppointmentTypeDTO;
use App\DTO\PractitionerDTO;
use App\DTO\PatientDTO;

if (!defined('ABSPATH')) exit;

class Booking extends AbstractModel
{
    protected ?AppointmentType $appointmentType = null;
    protected ?Practitioner $practitioner = null;
    protected ?Patient $patient = null;

    protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    public function getId(): string
    {
        return $this->dto->id;
    }

    public function getStartsAt(): ?string
    {
        return $this->dto->startsAt;
    }

    public function getEndsAt(): ?string
    {
        return $this->dto->endsAt;
    }

    public function getTelehealthUrl(): ?string
    {
        return $this->dto->telehealthUrl;
    }

    public function getPatientName(): ?string
    {
        return $this->dto->patientName;
    }

    public function getCreatedAt(): ?string
    {
        return $this->dto->createdAt;
    }

    public function getNotes(): ?string
    {
        return $this->dto->notes;
    }

    public function getInvoiceStatus(): ?string
    {
        return $this->dto->invoiceStatus;
    }

    public function getTreatmentNoteStatus(): ?string
    {
        return $this->dto->treatmentNoteStatus;
    }

    public function getPractitioner(): ?Practitioner
    {
        if (!$this->dto->practitioner) {
            return null;
        }

        $data = $this->safeGetLinkedEntity($this->dto->practitioner->url);

        if (empty($data)) {
            return null;
        }

        $this->practitioner = new Practitioner(
            PractitionerDTO::fromArray($data),
            $this->client
        );

        return $this->practitioner;
    }

    public function getAppointmentType(): ?AppointmentType
    {
        if (!$this->dto->appointmentType) {
            return null;
        }

        $data = $this->safeGetLinkedEntity($this->dto->appointmentType->url);

        if (empty($data)) {
            return null;
        }

        $this->appointmentType = new AppointmentType(
            AppointmentTypeDTO::fromArray($data),
            $this->client
        );

        return $this->appointmentType;
    }

    public function getPatient(): ?Patient
    {
        if (!$this->dto->patient) {
            return null;
        }

        $data = $this->safeGetLinkedEntity($this->dto->patient->url);

        if (empty($data)) {
            return null;
        }

        $this->patient = new Patient(
            PatientDTO::fromArray($data),
            $this->client
        );

        return $this->patient;
    }

    public function isTelehealth(): bool
    {
        return !empty($this->dto->telehealthUrl);
    }

    public function wasCancelled(): bool
    {
        return !empty($this->dto->cancelledAt);
    }

    public function didNotArrive(): bool
    {
        return (bool) $this->dto->didNotArrive;
    }
}
