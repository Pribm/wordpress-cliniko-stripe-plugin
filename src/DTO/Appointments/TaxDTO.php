<?php
namespace App\DTO;

class TaxDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public float $rate,
        public float $amount,
        public string $createdAt,
        public string $updatedAt,
        public ?string $selfUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name'] ?? '',
            (float) ($data['rate'] ?? 0),
            (float) ($data['amount'] ?? 0),
            $data['created_at'],
            $data['updated_at'],
            $data['links']['self'] ?? null
        );
    }
}
