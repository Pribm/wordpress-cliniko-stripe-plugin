<?php

namespace App\DTO;

class AvailableTimesDTO
{
    /**
     * @param list<AvailableTimeDTO> $availableTimes
     */
    public function __construct(
        public array $availableTimes,
        public int $totalEntries,
        public ?string $selfUrl = null,
        public ?string $previousUrl = null,
        public ?string $nextUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        $times = [];
        foreach (($data['available_times'] ?? []) as $row) {
            $times[] = AvailableTimeDTO::fromArray((array) $row);
        }

        $links = $data['links'] ?? [];

        return new self(
            $times,
            (int) ($data['total_entries'] ?? 0),
            $links['self'] ?? null,
            $links['previous'] ?? null,
            $links['next'] ?? null
        );
    }
}
