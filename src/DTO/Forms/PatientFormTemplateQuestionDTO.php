<?php
namespace App\DTO;

class PatientFormTemplateQuestionDTO
{
    public string $name;
    public string $type = 'text';
    public bool $required = false;

    /** Cliniko token for a patient custom field linked to this question, when configured. */
    public ?string $customFieldToken = null;

    /** Cliniko patient-field connection type, when configured. */
    public ?string $patientFieldConnection = null;

    /** @var array<int, array<string,mixed>> */
    public array $answers = [];


    public string $answer = "";

    public ?PatientFormTemplateQuestionOtherDTO $other = null;

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'custom_field_token' => $this->customFieldToken,
            'patient_field_connection' => $this->patientFieldConnection,
            'answers' => $this->answers,
            'other' => $this->other
        ];
    }
}
