<?php

namespace App\DTO;

class PatientFormDTO
{
    public ?string $id = null;
    public ?string $name = null;
    public ?string $url = null;
    public ?bool $email_to_patient_on_completion = null;
    public ?bool $restricted_to_practitioner = null;
    public ?string $archived_at = null;
    public ?string $completed_at = null;
    public ?string $created_at = null;
    public ?string $edited_at = null;
    public ?string $updated_at = null;

    public ?LinkDTO $links = null;
    public ?LinkDTO $attendee = null;
    public ?LinkDTO $booking = null;
    public ?LinkDTO $business = null;
    public ?LinkDTO $patient = null;
    public ?LinkDTO $signatures = null;

    /** @var SectionDTO[] */
    public array $content_sections = [];

    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->id = $data['id'] ?? null;
        $dto->name = $data['name'] ?? null;
        $dto->url = $data['url'] ?? null;
        $dto->email_to_patient_on_completion = $data['email_to_patient_on_completion'] ?? null;
        $dto->restricted_to_practitioner = $data['restricted_to_practitioner'] ?? null;
        $dto->archived_at = $data['archived_at'] ?? null;
        $dto->completed_at = $data['completed_at'] ?? null;
        $dto->created_at = $data['created_at'] ?? null;
        $dto->edited_at = $data['edited_at'] ?? null;
        $dto->updated_at = $data['updated_at'] ?? null;

        $dto->links = LinkDTO::fromArray($data['links'] ?? []);
        $dto->attendee = LinkDTO::fromArray($data['attendee']['links'] ?? []);
        $dto->booking = LinkDTO::fromArray($data['booking']['links'] ?? []);
        $dto->business = LinkDTO::fromArray($data['business']['links'] ?? []);
        $dto->patient = LinkDTO::fromArray($data['patient']['links'] ?? []);
        $dto->signatures = LinkDTO::fromArray($data['signatures']['links'] ?? []);

        foreach (($data['content']['sections'] ?? []) as $section) {
            $sectionDTO = new SectionDTO();
            $sectionDTO->name = $section['name'] ?? '';
            $sectionDTO->description = $section['description'] ?? '';
            $sectionDTO->questions = [];

            foreach (($section['questions'] ?? []) as $q) {
                $qDTO = new QuestionDTO();
                $qDTO->name = $q['name'];
                $qDTO->type = $q['type'];
                $qDTO->required = $q['required'] ?? false;
                $qDTO->answer = $q['answer'] ?? '';
                $sectionDTO->questions[] = $qDTO;
            }

            $dto->content_sections[] = $sectionDTO;
        }

        return $dto;
    }
}
