<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\AppointmentTypeDTO;
use App\DTO\AttendeeDTO;
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

    public function getAppointmentTypeName(): ?string
    {
        return $this->dto->appointmentTypeName;
    }

    public function getPractitionerName(): ?string
    {
        return $this->dto->practitionerName;
    }

    public function getAppointmentTypeId(): ?string
    {
        return $this->linkedResourceId($this->dto->appointmentType !== null ? $this->dto->appointmentType->url : '');
    }

    public function getPractitionerId(): ?string
    {
        return $this->linkedResourceId($this->dto->practitioner !== null ? $this->dto->practitioner->url : '');
    }

    public function getPatientId(): ?string
    {
        return $this->linkedResourceId($this->dto->patient !== null ? $this->dto->patient->url : '');
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

    // ✅ add this cache:
    /** @var Attendee[]|null */
    protected ?array $attendees = null;

    // ... your existing methods

    /**
     * Returns the list of attendees linked to this booking.
     *
     * @return Attendee[]
     */
    public function getAttendees(): array
    {
        if ($this->attendees !== null) {
            return $this->attendees;
        }

        if (!$this->dto->attendees) {
            $this->attendees = [];
            return $this->attendees;
        }

        $data = $this->safeGetLinkedEntity($this->dto->attendees->url);

        if (empty($data)) {
            $this->attendees = [];
            return $this->attendees;
        }

        // Cliniko list endpoints typically wrap results like: { "attendees": [ ... ], "links": {...}, ... }
        if (isset($data['attendees']) && is_array($data['attendees'])) {
            $items = $data['attendees'];
        }
        else {
            $items = [];
        }

        $this->attendees = array_values(array_filter(array_map(
            function ($item) {
                if (!is_array($item) || empty($item)) {
                    return null;
                }

                return new Attendee(
                    AttendeeDTO::fromArray($item),
                    $this->client
                );
            },
            $items
        )));

        return $this->attendees;
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

    private function linkedResourceId(string $url): ?string
    {
        $value = trim($url);
        if ($value === '') {
            return null;
        }

        $path = parse_url($value, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if (empty($segments)) {
            return null;
        }

        return (string) end($segments);
    }
}
