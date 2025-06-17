<?php

namespace App\DTO;

use App\Model\LinkedResource;

class AppointmentDTO
{
    public string $id;
    public string $startsAt;
    public string $endsAt;
    public ?string $createdAt;
    public ?string $updatedAt;
    public ?string $cancelledAt;
    public ?string $deletedAt;
    public ?string $notes;
    public ?string $cancellationNote;
    public ?string $cancellationReason;
    public ?string $patientName;
    public bool $didNotArrive;
    public bool $patientArrived;
    public bool $emailReminderSent;
    public bool $smsReminderSent;
    public ?string $invoiceStatus;
    public ?string $treatmentNoteStatus;
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
        $this->startsAt = $data['starts_at'];
        $this->endsAt = $data['ends_at'];
        $this->createdAt = $data['created_at'] ?? null;
        $this->updatedAt = $data['updated_at'] ?? null;
        $this->cancelledAt = $data['cancelled_at'] ?? null;
        $this->deletedAt = $data['deleted_at'] ?? null;
        $this->notes = $data['notes'] ?? null;
        $this->cancellationNote = $data['cancellation_note'] ?? null;
        $this->cancellationReason = $data['cancellation_reason'] ?? null;
        $this->patientName = $data['patient_name'] ?? null;
        $this->didNotArrive = $data['did_not_arrive'] ?? false;
        $this->patientArrived = $data['patient_arrived'] ?? false;
        $this->emailReminderSent = $data['email_reminder_sent'] ?? false;
        $this->smsReminderSent = $data['sms_reminder_sent'] ?? false;
        $this->invoiceStatus = $data['invoice_status'] ?? null;
        $this->treatmentNoteStatus = $data['treatment_note_status'] ?? null;
        $this->appointmentType = isset($data['appointment_type']['links']['self']) ? new LinkedResource($data['appointment_type']['links']['self']) : null;
        $this->business = isset($data['business']['links']['self']) ? new LinkedResource($data['business']['links']['self']) : null;
        $this->practitioner = isset($data['practitioner']['links']['self']) ? new LinkedResource($data['practitioner']['links']['self']) : null;
        $this->patient = isset($data['patient']['links']['self']) ? new LinkedResource($data['patient']['links']['self']) : null;
        $this->attendees = isset($data['attendees']['links']['self']) ? new LinkedResource($data['attendees']['links']['self']) : null;
        $this->conflicts = isset($data['conflicts']['links']['self']) ? new LinkedResource($data['conflicts']['links']['self']) : null;
        $this->selfLink = isset($data['links']['self']) ? new LinkedResource($data['links']['self']) : null;
    }
}
