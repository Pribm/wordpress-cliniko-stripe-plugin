<?php
namespace App\DTO;

class AvailableTimeDTO
{
    public function __construct(
        public string $appointmentStart
    ) {}

    public static function fromArray(array $data): self
    {
        return new self($data['appointment_start']);
    }
}
