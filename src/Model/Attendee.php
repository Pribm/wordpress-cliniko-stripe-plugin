<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\AttendeeDTO;
use App\DTO\BookingDTO;
use App\DTO\PatientDTO;

if (!defined('ABSPATH')) exit;

class Attendee extends AbstractModel
{
    protected ?Booking $booking = null;
    protected ?Patient $patient = null;

    protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    /** @return AttendeeDTO */
    protected function dto(): AttendeeDTO
    {
        /** @var AttendeeDTO $dto */
        $dto = $this->dto;
        return $dto;
    }

    public function getId(): string
    {
        return $this->dto()->id;
    }

    public function getArrived(): ?bool
    {
        return $this->dto()->arrived;
    }

    public function getNotes(): ?string
    {
        return $this->dto()->notes;
    }

    public function getTelehealthUrl(): ?string
    {
        return $this->dto()->telehealthUrl;
    }

    public function isTelehealth(): bool
    {
        return !empty($this->dto()->telehealthUrl);
    }

    public function wasCancelled(): bool
    {
        return !empty($this->dto()->cancelledAt);
    }

    public function getInvoiceStatus(): ?string
    {
        return $this->dto()->invoiceStatus;
    }

    public function getTreatmentNoteStatus(): ?string
    {
        return $this->dto()->treatmentNoteStatus;
    }

    public function getCreatedAt(): ?string
    {
        return $this->dto()->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->dto()->updatedAt;
    }

    public function getCancelledAt(): ?string
    {
        return $this->dto()->cancelledAt;
    }

    public function getCancellationReason(): ?string
    {
        return $this->dto()->cancellationReason;
    }

    public function getCancellationReasonDescription(): ?string
    {
        return $this->dto()->cancellationReasonDescription;
    }

    public function getCancellationUrl(): ?string
    {
        return $this->dto()->cancellationUrl;
    }

    public function getBooking(): ?Booking
    {
        if (!$this->dto()->booking) {
            return null;
        }

        $data = $this->safeGetLinkedEntity($this->dto()->booking->url);
        if (empty($data)) {
            return null;
        }

        $this->booking = new Booking(
            BookingDTO::fromArray($data),
            $this->client
        );

        return $this->booking;
    }

    public function getPatient(): ?Patient
    {
        if (!$this->dto()->patient) {
            return null;
        }

        $data = $this->safeGetLinkedEntity($this->dto()->patient->url);
        if (empty($data)) {
            return null;
        }

        $this->patient = new Patient(
            PatientDTO::fromArray($data),
            $this->client
        );

        return $this->patient;
    }

    /**
     * Collection link: GET this URL to list invoices for the attendee.
     * (Return the LinkedResource so your services can decide how to list/parse collections.)
     */
    public function getInvoicesLink(): ?LinkedResource
    {
        return $this->dto()->invoices;
    }

    /**
     * Collection link: GET this URL to list patient forms for the attendee.
     */
    public function getPatientFormsLink(): ?LinkedResource
    {
        return $this->dto()->patientForms;
    }

    public function getPatientCaseLink(): ?LinkedResource
    {
        return $this->dto()->patientCase;
    }

    public function getSelfLink(): ?LinkedResource
    {
        return $this->dto()->selfLink;
    }
}
