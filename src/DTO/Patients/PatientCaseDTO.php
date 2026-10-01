<?php
namespace App\DTO;

class PatientCaseDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public bool $closed,
        public ?string $notes,
        public ?string $issueDate,
        public ?string $expiryDate,
        public ?string $maxInvoiceableAmount,
        public ?int $maxSessions,
        public bool $referral,
        public ?string $referralType,
        public array $attendeeIds,
        public array $patientAttachmentIds,
        public bool $includeCancelledAttendees,
        public bool $includeDnaAttendees,
        public ?string $createdAt,
        public ?string $updatedAt,
        public ?string $selfUrl = null,
        public ?string $patientUrl = null,
        public ?string $contactUrl = null,
        public ?string $attendeesUrl = null,
        public ?string $invoicesUrl = null,
        public ?string $bookingsUrl = null,
        public ?string $attachmentsUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            $data['name'] ?? '',
            (bool) ($data['closed'] ?? false),
            $data['notes'] ?? null,
            $data['issue_date'] ?? null,
            $data['expiry_date'] ?? null,
            $data['max_invoiceable_amount'] ?? null,
            isset($data['max_sessions']) ? (int) $data['max_sessions'] : null,
            (bool) ($data['referral'] ?? false),
            $data['referral_type'] ?? null,
            $data['attendee_ids'] ?? [],
            $data['patient_attachment_ids'] ?? [],
            (bool) ($data['include_cancelled_attendees'] ?? false),
            (bool) ($data['include_dna_attendees'] ?? false),
            $data['created_at'] ?? null,
            $data['updated_at'] ?? null,
            $data['links']['self'] ?? null,
            $data['patient']['links']['self'] ?? null,
            $data['contact']['links']['self'] ?? null,
            $data['attendees']['links']['self'] ?? null,
            $data['invoices']['links']['self'] ?? null,
            $data['bookings']['links']['self'] ?? null,
            $data['patient_attachments']['links']['self'] ?? null
        );
    }
}
