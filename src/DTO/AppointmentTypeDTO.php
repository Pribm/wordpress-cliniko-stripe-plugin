<?php
namespace App\DTO;

class AppointmentTypeDTO
{
    public function __construct(
        public ?string $id = null,
        public string $name,
        public string $description,
        public string $category,
        public string $color,
        public int $durationInMinutes,
        public bool $telehealthEnabled,
        public bool $showInOnlineBookings,
        public bool $onlinePaymentsEnabled,
        public string $onlinePaymentsMode,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?string $archivedAt = null,
        public ?string $depositPrice = null,
        public ?int $maxAttendees = null,
        public ?string $billableItemUrl = null,
        public ?string $practitionersUrl = null,
         public ?string $billableItemsUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? null,
            $data['name'] ?? '',
            $data['description'] ?? '',
            $data['category'] ?? '',
            $data['color'] ?? '',
            $data['duration_in_minutes'] ?? 0,
            $data['telehealth_enabled'] ?? false,
            $data['show_in_online_bookings'] ?? false,
            $data['online_payments_enabled'] ?? false,
            $data['online_payments_mode'] ?? '',
            $data['created_at'] ?? null,
            $data['updated_at'] ?? null,
            $data['archived_at'] ?? null,
            $data['deposit_price'] ?? null,
            $data['max_attendees'] ?? null,
            $data['billable_item']['links']['self'] ?? null,
            $data['practitioners']['links']['self'] ?? null,
            $data['appointment_type_billable_items']['links']['self'] ?? null,
        );
    }
}