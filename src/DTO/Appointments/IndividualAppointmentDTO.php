<?php
namespace App\DTO;

class IndividualAppointmentDTO
{
    public function __construct(
        public string $id,
        public string $startsAt,
        public string $endsAt,
        public string $createdAt,
        public string $updatedAt,
        public ?string $notes = null,
        public ?string $telehealthUrl = null,
        public ?string $appointmentTypeUrl = null,
        public ?string $patientUrl = null,
        public ?string $practitionerUrl = null,
        public ?string $businessUrl = null,
        public ?string $patientCaseUrl = null,
        public ?array $repeatRule = null,
        public ?string $selfUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['starts_at'],
            $data['ends_at'],
            $data['created_at'],
            $data['updated_at'],
            $data['notes'] ?? null,
            $data['telehealth_url'] ?? null,
            $data['appointment_type']['links']['self'] ?? null,
            $data['patient']['links']['self'] ?? null,
            $data['practitioner']['links']['self'] ?? null,
            $data['business']['links']['self'] ?? null,
            $data['patient_case']['links']['self'] ?? null,
            $data['repeat_rule'] ?? null,
            $data['links']['self'] ?? null
        );
    }
}
