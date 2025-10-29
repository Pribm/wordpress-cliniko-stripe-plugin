<?php

namespace App\DTO;

use App\Model\LinkedResource;

class BookingDTO
{
    public string $id;
    public string $bookingIpAddress;
    public ?string $createdAt;
    public ?string $updatedAt;
    public ?string $archivedAt;
    public ?string $cancelledAt;
    public ?string $deletedAt;
    public ?string $startsAt;
    public ?string $endsAt;
    public ?string $notes;
    public ?string $cancellationNote;
    public ?string $cancellationReason;
    public ?string $cancellationReasonDescription;
    public ?bool $didNotArrive;
    public ?bool $patientArrived;
    public ?bool $emailReminderSent;
    public ?bool $smsReminderSent;
    public ?bool $hasPatientAppointmentNotes;
    public ?bool $onlineBookingPolicyAccepted;
    public ?string $invoiceStatus;
    public ?string $treatmentNoteStatus;
    public ?string $patientName;
    public ?string $telehealthUrl;

    public ?LinkedResource $appointmentType;
    public ?LinkedResource $business;
    public ?LinkedResource $practitioner;
    public ?LinkedResource $patient;
    public ?LinkedResource $attendees;
    public ?LinkedResource $conflicts;
    public ?LinkedResource $selfLink;

    public function __construct(array $data)
    {
        $this->id = $data['id'];
        $this->bookingIpAddress = $data['booking_ip_address'] ?? '';
        $this->createdAt = $data['created_at'] ?? null;
        $this->updatedAt = $data['updated_at'] ?? null;
        $this->archivedAt = $data['archived_at'] ?? null;
        $this->cancelledAt = $data['cancelled_at'] ?? null;
        $this->deletedAt = $data['deleted_at'] ?? null;
        $this->startsAt = $data['starts_at'] ?? null;
        $this->endsAt = $data['ends_at'] ?? null;
        $this->notes = $data['notes'] ?? null;
        $this->cancellationNote = $data['cancellation_note'] ?? null;
        $this->cancellationReason = $data['cancellation_reason'] ?? null;
        $this->cancellationReasonDescription = $data['cancellation_reason_description'] ?? null;
        $this->didNotArrive = $data['did_not_arrive'] ?? false;
        $this->patientArrived = $data['patient_arrived'] ?? false;
        $this->emailReminderSent = $data['email_reminder_sent'] ?? false;
        $this->smsReminderSent = $data['sms_reminder_sent'] ?? false;
        $this->hasPatientAppointmentNotes = $data['has_patient_appointment_notes'] ?? false;
        $this->onlineBookingPolicyAccepted = $data['online_booking_policy_accepted'] ?? null;
        $this->invoiceStatus = $data['invoice_status'] ?? null;
        $this->treatmentNoteStatus = $data['treatment_note_status'] ?? null;
        $this->patientName = $data['patient_name'] ?? null;
        $this->telehealthUrl = $data['telehealth_url'] ?? null;

        // Linked resources
        $this->appointmentType = isset($data['appointment_type']['links']['self'])
            ? new LinkedResource($data['appointment_type']['links']['self']) : null;

        $this->business = isset($data['business']['links']['self'])
            ? new LinkedResource($data['business']['links']['self']) : null;

        $this->practitioner = isset($data['practitioner']['links']['self'])
            ? new LinkedResource($data['practitioner']['links']['self']) : null;

        $this->patient = isset($data['patient']['links']['self'])
            ? new LinkedResource($data['patient']['links']['self']) : null;

        $this->attendees = isset($data['attendees']['links']['self'])
            ? new LinkedResource($data['attendees']['links']['self']) : null;

        $this->conflicts = isset($data['conflicts']['links']['self'])
            ? new LinkedResource($data['conflicts']['links']['self']) : null;

        $this->selfLink = isset($data['links']['self'])
            ? new LinkedResource($data['links']['self']) : null;
    }

    public static function fromArray(array $data): static
    {
        return new static($data);
    }
}
