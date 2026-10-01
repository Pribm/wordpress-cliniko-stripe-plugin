<?php

namespace App\DTO;

final class PatientCustomFieldDTO
{
    public function __construct(
        public string $token,
        public ?string $value = null,
        public ?bool $archived = null,
        public ?string $name = null,
        public ?string $type = null
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'value' => $this->value,
            'archived' => $this->archived,
            'name' => $this->name,
            'token' => $this->token,
            'type' => $this->type,
        ], static fn($value): bool => $value !== null);
    }
}
