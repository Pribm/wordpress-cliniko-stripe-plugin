<?php
namespace App\DTO;

class AppointmentTypeBillableItemDTO
{
    public function __construct(
        public string $id,
        public float $quantity,
        public ?float $discountedAmount,
        public ?float $discountPercentage,
        public bool $isMonetaryDiscount,
        public string $createdAt,
        public string $updatedAt,
        public string $appointmentTypeUrl,
        public string $billableItemUrl,
        public ?string $selfUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            (float) $data['quantity'],
            isset($data['discounted_amount']) ? (float) $data['discounted_amount'] : null,
            isset($data['discount_percentage']) ? (float) $data['discount_percentage'] : null,
            $data['is_monetary_discount'] ?? false,
            $data['created_at'],
            $data['updated_at'],
            $data['appointment_type']['links']['self'] ?? '',
            $data['billable_item']['links']['self'] ?? '',
            $data['links']['self'] ?? null
        );
    }
}
