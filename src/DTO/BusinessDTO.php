<?php
namespace App\DTO;

class BusinessDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $displayName,
        public string $label,
        public ?string $emailReplyTo,
        public ?string $contactInformation,
        public ?string $websiteAddress,
        public ?string $additionalInfo,
        public ?string $invoiceInfo,
        public ?string $registrationName,
        public ?string $registrationValue,
        public ?string $address,
        public ?string $city,
        public ?string $state,
        public ?string $postCode,
        public ?string $country,
        public ?string $timeZone,
        public ?string $timeZoneIdentifier,
        public bool $appointmentRemindersEnabled,
        public bool $showInOnlineBookings,
        public string $createdAt,
        public string $updatedAt,
        public ?string $archivedAt,
        public ?string $deletedAt,
        public ?string $selfUrl,
        public ?string $appointmentsUrl,
        public ?string $practitionersUrl,
        public array $appointmentTypeIds = []
    ) {}

    public static function fromArray(array $data): self
    {
        $address = implode(', ', array_filter([
            $data['address_1'] ?? null,
            $data['address_2'] ?? null,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['post_code'] ?? null,
            $data['country'] ?? null,
        ]));

        return new self(
            $data['id'],
            $data['business_name'] ?? '',
            $data['display_name'] ?? '',
            $data['label'] ?? '',
            $data['email_reply_to'] ?? null,
            $data['contact_information'] ?? null,
            $data['website_address'] ?? null,
            $data['additional_information'] ?? null,
            $data['additional_invoice_information'] ?? null,
            $data['business_registration_name'] ?? null,
            $data['business_registration_value'] ?? null,
            $address,
            $data['city'] ?? null,
            $data['state'] ?? null,
            $data['post_code'] ?? null,
            $data['country'] ?? null,
            $data['time_zone'] ?? null,
            $data['time_zone_identifier'] ?? null,
            $data['appointment_reminders_enabled'] ?? false,
            $data['show_in_online_bookings'] ?? false,
            $data['created_at'],
            $data['updated_at'],
            $data['archived_at'] ?? null,
            $data['deleted_at'] ?? null,
            $data['links']['self'] ?? null,
            $data['appointments']['links']['self'] ?? null,
            $data['practitioners']['links']['self'] ?? null,
            $data['appointment_type_ids'] ?? []
        );
    }
}
