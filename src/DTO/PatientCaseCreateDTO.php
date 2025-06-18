<?php
namespace App\DTO;

class PatientCaseCreateDTO
{
    public function __construct(
        public string $name,
        public string $patientId,
        public string $issueDate,

        public ?bool $closed = null,
        public ?string $contactId = null,
        public ?string $expiryDate = null,
        public ?bool $includeCancelledAttendees = null,
        public ?bool $includeDnaAttendees = null,
        public ?string $notes = null,
        public ?string $maxInvoiceableAmount = null,
        public ?int $maxSessions = null,
        public ?bool $referral = null,
        public ?string $referralType = null,
        public ?array $attendeeIds = null,
        public ?array $patientAttachmentIds = null,
    ) {}

    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'patient_id' => $this->patientId,
            'issue_date' => $this->issueDate,
        ];

        if (!is_null($this->closed)) $data['closed'] = $this->closed;
        if (!is_null($this->contactId)) $data['contact_id'] = $this->contactId;
        if (!is_null($this->expiryDate)) $data['expiry_date'] = $this->expiryDate;
        if (!is_null($this->includeCancelledAttendees)) $data['include_cancelled_attendees'] = $this->includeCancelledAttendees;
        if (!is_null($this->includeDnaAttendees)) $data['include_dna_attendees'] = $this->includeDnaAttendees;
        if (!is_null($this->notes)) $data['notes'] = $this->notes;
        if (!is_null($this->maxInvoiceableAmount)) $data['max_invoiceable_amount'] = $this->maxInvoiceableAmount;
        if (!is_null($this->maxSessions)) $data['max_sessions'] = $this->maxSessions;
        if (!is_null($this->referral)) $data['referral'] = $this->referral;
        if (!is_null($this->referralType)) $data['referral_type'] = $this->referralType;
        if (!is_null($this->attendeeIds)) $data['attendee_ids'] = $this->attendeeIds;
        if (!is_null($this->patientAttachmentIds)) $data['patient_attachment_ids'] = $this->patientAttachmentIds;

        return $data;
    }
}
