<?php
namespace App\DTO;

class CreatePatientFormTemplateDTO
{
    public string $name;
    public bool $emailToPatientOnCompletion;
    public bool $restrictedToPractitioner;

    /** @var PatientFormTemplateSectionDTO[] */
    public array $sections = [];

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'email_to_patient_on_completion' => $this->emailToPatientOnCompletion,
            'restricted_to_practitioner' => $this->restrictedToPractitioner,
            'content' => [
                'sections' => array_map(fn($section) => $section->toArray(), $this->sections)
            ],
        ];
    }
}


