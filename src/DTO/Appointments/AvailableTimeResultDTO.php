<?php
namespace App\DTO;

class AvailableTimeResultDTO
{
    /**
     * @param AvailableTimeDTO[] $availableTimes
     */
    public function __construct(
        public array $availableTimes,
        public int $totalEntries,
        public ?string $nextUrl = null,
        public ?string $previousUrl = null,
        public ?string $selfUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        $availableTimes = array_map(
            fn($item) => AvailableTimeDTO::fromArray($item),
            $data['available_times'] ?? []
        );

        return new self(
            $availableTimes,
            $data['total_entries'] ?? 0,
            $data['links']['next'] ?? null,
            $data['links']['previous'] ?? null,
            $data['links']['self'] ?? null
        );
    }
}
