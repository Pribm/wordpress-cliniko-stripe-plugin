<?php

namespace App\DTO;

final class PublicPatientCustomFieldSectionDTO
{
    /** @param array<int,PublicPatientCustomFieldDTO> $fields */
    public function __construct(
        public string $name,
        public string $token,
        public array $fields = [],
        public bool $archived = false
    ) {}

    public static function fromArray(array $data): self
    {
        $fields = [];
        foreach (($data['fields'] ?? []) as $field) {
            if (is_array($field)) {
                $fields[] = PublicPatientCustomFieldDTO::fromArray($field);
            }
        }

        return new self(
            trim((string) ($data['name'] ?? '')),
            trim((string) ($data['token'] ?? '')),
            $fields,
            (bool) ($data['archived'] ?? false)
        );
    }
}
