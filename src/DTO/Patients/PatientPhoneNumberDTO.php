<?php

namespace App\DTO;

final class PatientPhoneNumberDTO
{
    public function __construct(
        public string $number,
        public string $phoneType = 'Mobile'
    ) {}

    public function toArray(): array
    {
        return ['number' => $this->number, 'phone_type' => $this->phoneType];
    }
}
