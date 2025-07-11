<?php
namespace App\DTO;

class PatientFormTemplateQuestionDTO
{
    public string $name;
    public string $type = 'text';
    public bool $required = false;

    public array $answers = [];

    public string $answer = "";

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'answers' => $this->answers
        ];
    }
}