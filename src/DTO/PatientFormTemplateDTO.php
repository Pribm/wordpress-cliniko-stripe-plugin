<?php
namespace App\DTO;

class PatientFormTemplateDTO
{
    public string $id;
    public string $name;
    public bool $emailToPatientOnCompletion;
    public bool $restrictedToPractitioner;

    public ?string $archivedAt = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;
    public string $selfLink;

    /** @var PatientFormTemplateSectionDTO[] */
    public array $sections = [];

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->id = $data['id'];
        $dto->name = $data['name'];
        $dto->emailToPatientOnCompletion = $data['email_to_patient_on_completion'];
        $dto->restrictedToPractitioner = $data['restricted_to_practitioner'];
        $dto->archivedAt = $data['archived_at'] ?? null;
        $dto->createdAt = $data['created_at'] ?? null;
        $dto->updatedAt = $data['updated_at'] ?? null;
        $dto->selfLink = $data['links']['self'];

        $dto->sections = array_map(function ($sectionData) {
            $section = new PatientFormTemplateSectionDTO();
            $section->name = $sectionData['name'];
            $section->description = $sectionData['description'];
            $section->questions = array_map(function ($questionData) {
                $question = new PatientFormTemplateQuestionDTO();
                $question->name = $questionData['name'];
                $question->type = $questionData['type'];
                $question->required = $questionData['required'];
                return $question;
            }, $sectionData['questions']);

            return $section;
        }, $data['content']['sections'] ?? []);

        return $dto;
    }
}
