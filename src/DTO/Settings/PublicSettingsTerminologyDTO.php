<?php

namespace App\DTO;

final class PublicSettingsTerminologyDTO
{
    /** @param array<int,string> $titles */
    public function __construct(public string $patient = '', public array $titles = []) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) return null;
        $titles = is_array($data['titles'] ?? null) ? $data['titles'] : [];
        return new self((string) ($data['patient'] ?? ''), array_values(array_map('strval', $titles)));
    }
}
