<?php
namespace App\DTO;

class CreatePatientDTO
{
    public string $firstName = "";
    public string $lastName = "";
    public string $email = "";

    public ?string $phone = null;
    public ?string $medicare = null;
    public ?string $medicareReferenceNumber = null;

    public ?string $address1 = null;
    public ?string $address2 = null;
    public ?string $city = null;
    public ?string $state = null;
    public ?string $postCode = null;
    public ?string $country = null;

    public ?bool $acceptedPrivacyPolicy = false;

    public ?string $dateOfBirth = null;
    public ?string $notes = null;
    public ?string $genderIdentity = null;
    public ?string $preferredFirstName = null;

    public ?array $patientPhoneNumbers = null;

    public function toArray(): array
    {
        return [
            'first_name'                => $this->firstName,
            'last_name'                 => $this->lastName,
            'email'                     => $this->email,
            'phone'                     => $this->phone,
            'medicare'                  => $this->medicare,
            'medicare_reference_number' => $this->medicareReferenceNumber,
            'address_1'                 => $this->address1,
            'address_2'                 => $this->address2,
            'city'                      => $this->city,
            'state'                     => $this->state,
            'post_code'                 => $this->postCode,
            'country'                   => $this->country,
            'date_of_birth'             => $this->dateOfBirth,
            'notes'                     => $this->notes,
            'gender_identity'           => $this->genderIdentity,
            'preferred_first_name'      => $this->preferredFirstName,
            'patient_phone_numbers'     => $this->patientPhoneNumbers,
            'accepted_privacy_policy' => $this->acceptedPrivacyPolicy
        ];
    }
}
