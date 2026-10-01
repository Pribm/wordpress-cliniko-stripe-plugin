<?php
namespace App\DTO;

class PatientFormTemplateQuestionDTO
{
    public string $name;
    public string $type = 'text';
    public bool $required = false;

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
            'answers' => $this->answers,
            'other' => $this->other
        ];
    }
}