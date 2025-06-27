<?php
namespace App\DTO;

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