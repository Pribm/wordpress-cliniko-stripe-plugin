<?php

namespace App\DTO;

/** Request body for Cliniko's POST /patients endpoint. */
class CreatePatientDTO
{
    public string $firstName = '';
    public string $lastName = '';
    public string $email = '';

    public ?bool $acceptedEmailMarketing = null;
    public ?bool $acceptedPrivacyPolicy = false;
    public ?string $address1 = null;
    public ?string $address2 = null;
    public ?string $address3 = null;
    public ?string $appointmentNotes = null;
    public ?string $city = null;
    public ?string $concessionTypeId = null;
    public ?string $country = null;
    public ?string $countryCode = null;
    public PatientCustomFieldsDTO|array|null $customFields = null;
    public ?string $dateOfBirth = null;
    public ?string $dvaCardNumber = null;
    public ?string $emergencyContact = null;
    public ?string $genderIdentity = null;
    public ?string $invoiceDefaultTo = null;
    public ?string $invoiceEmail = null;
    public ?string $invoiceExtraInformation = null;
    public ?string $medicareReferenceNumber = null;
    public ?string $medicare = null;
    public ?string $notes = null;
    public ?string $occupation = null;
    public ?string $oldReferenceId = null;
    public ?string $postCode = null;
    public ?string $preferredFirstName = null;
    public PatientPronounsDTO|array|null $pronouns = null;
    public ?bool $receivesCancellationEmails = null;
    public bool $receivesConfirmationEmails = true;
    public ?string $referralSource = null;
    public ?string $referringDoctorId = null;
    public ?string $sex = null;
    public ?string $state = null;
    public ?string $timeZone = null;
    public ?string $title = null;
    public bool $unsubscribeSmsMarketing = false;
    /** @var array<int,int> */
    public array $followUpsCommunicationChannels = [];
    /** @var array<int,int> */
    public array $remindersCommunicationChannels = [];
    /** @var array<int,PatientPhoneNumberDTO|array<string,mixed>> */
    public array $patientPhoneNumbers = [];
    public ?string $reminderType = null;

    public function toArray(): array
    {
        $payload = get_object_vars($this);
        foreach (['customFields', 'pronouns'] as $property) {
            $value = $payload[$property];
            if (is_object($value) && method_exists($value, 'toArray')) {
                $payload[$property] = $value->toArray();
            }
        }
        $payload['patientPhoneNumbers'] = array_map(
            static fn($phone): array => $phone instanceof PatientPhoneNumberDTO ? $phone->toArray() : $phone,
            $payload['patientPhoneNumbers']
        );

        $result = [];
        foreach ($payload as $property => $value) {
            $key = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $property));
            $result[$key] = $value;
        }
        return $result;
    }
}
