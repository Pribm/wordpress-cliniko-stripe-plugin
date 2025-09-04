<?php
namespace App\DTO;

class PatientFormTemplateQuestionOtherDTO
{
    public $enabled = false;

    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
        ];
    }
}