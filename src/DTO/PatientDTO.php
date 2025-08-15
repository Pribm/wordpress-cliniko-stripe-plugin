<?php
namespace App\DTO;

class PatientDTO
{
    public function __construct(
        public string $id,
        public string $firstName,
        public string $lastName,
        public ?string $preferredFirstName,
        public ?string $email,
        public ?string $dateOfBirth,
        public ?string $gender,
        public ?string $phone,
        public ?string $address,
        public ?string $notes,
        public ?string $occupation,
        public ?string $selfUrl
    ) {}

    public static function fromArray(array $data): self
    {
        $primaryPhone = $data['patient_phone_numbers'][0]['number'] ?? null;
        $address = implode(', ', array_filter([
            $data['address_1'] ?? null,
            $data['address_2'] ?? null,
            $data['address_3'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['post_code'] ?? null,
            $data['country'] ?? null,
        ]));

        return new self(
            $data['id'] ?? '',
            $data['first_name'] ?? '',
            $data['last_name'] ?? '',
            $data['preferred_first_name'] ?? null,
            $data['email'] ?? null,
            $data['date_of_birth'] ?? null,
            $data['gender'] ?? null,
            $primaryPhone,
            $address,
            $data['notes'] ?? null,
            $data['occupation'] ?? null,
            $data['links']['self'] ?? null
        );
    }
}
