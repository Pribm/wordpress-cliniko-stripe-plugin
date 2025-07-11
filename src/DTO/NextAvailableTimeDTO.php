<?php
namespace App\DTO;

class NextAvailableTimeDTO
{
    public function __construct(
        public string $appointmentStart,
        public int $totalEntries,
        public ?string $selfUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            $data['appointment_start'],
            $data['total_entries'] ?? 0,
            $data['links']['self'] ?? null
        );
    }
}
