<?php

namespace App\DTO;

final class PatientCustomFieldsDTO
{
    /** @param array<int,PatientCustomFieldSectionDTO|array<string,mixed>> $sections */
    public function __construct(public array $sections = []) {}

    public function toArray(): array
    {
        return ['sections' => array_map(
            static fn($section): array => $section instanceof PatientCustomFieldSectionDTO ? $section->toArray() : $section,
            $this->sections
        )];
    }
}
