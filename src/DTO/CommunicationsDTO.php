<?php

namespace App\DTO;

if (!defined('ABSPATH')) exit;

final class CommunicationsDTO
{
    /** @param list<CommunicationDTO> $communications */
    public function __construct(public array $communications = [], public int $totalEntries = 0, public ?string $selfUrl = null, public ?string $previousUrl = null, public ?string $nextUrl = null) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ((array) ($data['communications'] ?? []) as $communication) if (is_array($communication)) $items[] = CommunicationDTO::fromArray($communication);
        $links = is_array($data['links'] ?? null) ? $data['links'] : [];
        return new self($items, (int) ($data['total_entries'] ?? count($items)), isset($links['self']) ? (string) $links['self'] : null, isset($links['previous']) ? (string) $links['previous'] : null, isset($links['next']) ? (string) $links['next'] : null);
    }
}
