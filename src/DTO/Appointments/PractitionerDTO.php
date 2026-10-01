<?php
namespace App\DTO;

class PractitionerDTO
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public string $displayName,
        public ?string $designation,
        public ?string $description,
        public ?string $title,
        public bool $active,
        public bool $showInOnlineBookings,
        public string $createdAt,
        public string $updatedAt,
        public ?string $selfUrl = null,
        public ?string $defaultAppointmentTypeUrl = null,
        public ?string $appointmentTypesUrl = null,
        public ?string $appointmentsUrl = null,
        public ?string $invoicesUrl = null,
        public ?string $userUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['first_name'] ?? '',
            $data['last_name'] ?? '',
            $data['display_name'] ?? '',
            $data['designation'] ?? null,
            $data['description'] ?? null,
            $data['title'] ?? null,
            $data['active'] ?? false,
            $data['show_in_online_bookings'] ?? false,
            $data['created_at'],
            $data['updated_at'],
            $data['links']['self'] ?? null,
            $data['default_appointment_type']['links']['self'] ?? null,
            $data['appointment_types']['links']['self'] ?? null,
            $data['appointments']['links']['self'] ?? null,
            $data['invoices']['links']['self'] ?? null,
            $data['user']['links']['self'] ?? null
        );
    }
}
