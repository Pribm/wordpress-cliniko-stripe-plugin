<?php
namespace App\DTO;

class PatientFormTemplateQuestionDTO
{
    public string $name;
    public string $type = 'text'; // Cliniko supports: text, checkbox, select, etc.
    public bool $required = false;

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
        ];
    }
}

class PatientFormTemplateSectionDTO
{
    public string $name;
    public ?string $description = null;

    /** @var PatientFormTemplateQuestionDTO[] */
    public array $questions = [];

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'questions' => array_map(fn($q) => $q->toArray(), $this->questions),
        ];
    }
}


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


