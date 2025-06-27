<?php

namespace App\DTO;

class CreatePatientFormDTO
{
    public string $attendee_id;
    public string $business_id;
    public bool $completed;
    public bool $email_to_patient_on_completion;
    public string $patient_id;
    public string $patient_form_template_id;

    /** @var PatientFormTemplateSectionDTO[] */
    public array $content_sections = [];

    public function toArray(): array
    {
        return [
            'attendee_id' => $this->attendee_id,
            'business_id' => $this->business_id,
            'completed' => $this->completed,
            'email_to_patient_on_completion' => $this->email_to_patient_on_completion,
            'patient_id' => $this->patient_id,
            'patient_form_template_id' => $this->patient_form_template_id,
            'content' => [
                'sections' => array_map(fn($section) => $section->toArray(), $this->content_sections),
            ],
        ];
    }
}
