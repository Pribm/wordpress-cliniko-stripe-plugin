<?php

namespace App\DTO;

final class PatientCustomFieldSectionDTO
{
    /** @param array<int,PatientCustomFieldDTO|array<string,mixed>> $fields */
    public function __construct(
        public string $token,
        public array $fields = [],
        public ?string $name = null,
        public ?bool $archived = null
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'token' => $this->token,
            'archived' => $this->archived,
            'fields' => array_map(static fn($field): array => $field instanceof PatientCustomFieldDTO ? $field->toArray() : $field, $this->fields),
        ], static fn($value): bool => $value !== null);
    }
}
