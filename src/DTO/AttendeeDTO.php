<?php

namespace App\DTO;

use App\Model\LinkedResource;

class AttendeeDTO
{
    public string $id;

    public ?string $archivedAt;
    public ?bool $arrived;
    public ?string $bookingIpAddress;

    public ?string $cancellationNote;
    public ?string $cancellationReason; // keep as string (API may return int)
    public ?string $cancellationReasonDescription;
    public ?string $cancellationUrl;
    public ?string $cancelledAt;

    public ?string $createdAt;
    public ?string $updatedAt;
    public ?string $deletedAt;

    public ?string $invoiceStatus;      // keep as string (API may return int)
    public ?string $treatmentNoteStatus; // keep as string (API may return int)

    public ?string $notes;
    public ?bool $onlineBookingPolicyAccepted;

    public ?int $sentEmailFollowUpsCount;
    public ?int $sentEmailRemindersCount;
    public ?int $sentSmsFollowUpsCount;
    public ?int $sentSmsRemindersCount;

    public ?string $telehealthUrl;

    /** @deprecated (Cliniko: prefer sent_email_reminders_count) */
    public ?bool $emailReminderSent;

    /** @deprecated (Cliniko: prefer sent_sms_reminders_count) */
    public ?bool $smsReminderSent;

    // Linked resources
    public ?LinkedResource $booking;
    public ?LinkedResource $patient;
    public ?LinkedResource $patientCase;
    public ?LinkedResource $patientForms;
    public ?LinkedResource $invoices;
    public ?LinkedResource $selfLink;

    public function __construct(array $data)
    {
        $this->id = (string) ($data['id'] ?? '');

        $this->archivedAt = $data['archived_at'] ?? null;
        $this->arrived = $data['arrived'] ?? null;
        $this->bookingIpAddress = $data['booking_ip_address'] ?? null;

        $this->cancellationNote = $data['cancellation_note'] ?? null;
        $this->cancellationReason = isset($data['cancellation_reason']) ? (string) $data['cancellation_reason'] : null;
        $this->cancellationReasonDescription = $data['cancellation_reason_description'] ?? null;
        $this->cancellationUrl = $data['cancellation_url'] ?? null;
        $this->cancelledAt = $data['cancelled_at'] ?? null;

        $this->createdAt = $data['created_at'] ?? null;
        $this->updatedAt = $data['updated_at'] ?? null;
        $this->deletedAt = $data['deleted_at'] ?? null;

        $this->invoiceStatus = isset($data['invoice_status']) ? (string) $data['invoice_status'] : null;
        $this->treatmentNoteStatus = isset($data['treatment_note_status']) ? (string) $data['treatment_note_status'] : null;

        $this->notes = $data['notes'] ?? null;
        $this->onlineBookingPolicyAccepted = $data['online_booking_policy_accepted'] ?? null;

        $this->sentEmailFollowUpsCount = isset($data['sent_email_follow_ups_count']) ? (int) $data['sent_email_follow_ups_count'] : null;
        $this->sentEmailRemindersCount = isset($data['sent_email_reminders_count']) ? (int) $data['sent_email_reminders_count'] : null;
        $this->sentSmsFollowUpsCount = isset($data['sent_sms_follow_ups_count']) ? (int) $data['sent_sms_follow_ups_count'] : null;
        $this->sentSmsRemindersCount = isset($data['sent_sms_reminders_count']) ? (int) $data['sent_sms_reminders_count'] : null;

        $this->telehealthUrl = $data['telehealth_url'] ?? null;

        // Deprecated fields still present in some responses
        $this->emailReminderSent = $data['email_reminder_sent'] ?? null;
        $this->smsReminderSent = $data['sms_reminder_sent'] ?? null;

        // Linked resources
        $this->booking = isset($data['booking']['links']['self'])
            ? new LinkedResource($data['booking']['links']['self']) : null;

        $this->patient = isset($data['patient']['links']['self'])
            ? new LinkedResource($data['patient']['links']['self']) : null;

        $this->patientCase = isset($data['patient_case']['links']['self'])
            ? new LinkedResource($data['patient_case']['links']['self']) : null;

        $this->patientForms = isset($data['patient_forms']['links']['self'])
            ? new LinkedResource($data['patient_forms']['links']['self']) : null;

        $this->invoices = isset($data['invoices']['links']['self'])
            ? new LinkedResource($data['invoices']['links']['self']) : null;

        $this->selfLink = isset($data['links']['self'])
            ? new LinkedResource($data['links']['self']) : null;
    }

    public static function fromArray(array $data): static
    {
        return new static($data);
    }
}
