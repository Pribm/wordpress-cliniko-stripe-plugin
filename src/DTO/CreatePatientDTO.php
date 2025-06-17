<?php
namespace App\DTO;

class CreatePatientDTO
{
    public string $firstName;
    public string $lastName;
    public string $email;

    public ?string $address1 = null;
    public ?string $countryCode = null;
    public ?string $dateOfBirth = null;
    public ?string $phone = null;
    public ?string $notes = null;
    public ?string $genderIdentity = null;
    public ?string $preferredFirstName = null;

    public ?array $patientPhoneNumbers = null;

    // Você pode continuar expandindo com os demais campos conforme necessário...

    public function toArray(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'address_1' => $this->address1,
            'country_code' => $this->countryCode,
            'date_of_birth' => $this->dateOfBirth,
            'notes' => $this->notes,
            'gender_identity' => $this->genderIdentity,
            'preferred_first_name' => $this->preferredFirstName,
            'patient_phone_numbers' => $this->patientPhoneNumbers,
        ];
    }
}
