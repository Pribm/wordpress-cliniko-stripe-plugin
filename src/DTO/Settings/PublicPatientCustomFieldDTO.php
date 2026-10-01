<?php

namespace App\DTO;

final class PublicPatientCustomFieldDTO
{
    /** @param array<int,mixed> $options */
    public function __construct(
        public string $name,
        public string $token,
        public string $type = 'text',
        public bool $archived = false,
        public array $options = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            trim((string) ($data['name'] ?? '')),
            trim((string) ($data['token'] ?? '')),
            trim((string) ($data['type'] ?? 'text')) ?: 'text',
            (bool) ($data['archived'] ?? false),
            is_array($data['options'] ?? null) ? $data['options'] : []
        );
    }
}
