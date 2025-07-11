<?php
namespace App\DTO;

class BillableItemDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $itemCode,
        public string $itemType,
        public float $price,
        public string $createdAt,
        public string $updatedAt,
        public ?string $archivedAt = null,
        public ?string $taxUrl = null,
        public ?string $selfUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name'] ?? '',
            $data['item_code'] ?? '',
            $data['item_type'] ?? '',
            $data['price'] ? (float) $data['price'] : 0.0,
            $data['created_at'],
            $data['updated_at'],
            $data['archived_at'] ?? null,
            $data['tax']['links']['self'] ?? null,
            $data['links']['self'] ?? null
        );
    }
}
