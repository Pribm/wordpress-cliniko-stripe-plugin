<?php

namespace App\DTO;

final class PublicPatientCustomFieldsDefinitionDTO
{
    /** @param array<int,PublicPatientCustomFieldSectionDTO> $sections */
    public function __construct(public array $sections = []) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        $sections = [];
        foreach (($data['sections'] ?? []) as $section) {
            if (is_array($section)) {
                $sections[] = PublicPatientCustomFieldSectionDTO::fromArray($section);
            }
        }

        return new self($sections);
    }
}
