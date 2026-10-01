<?php

namespace App\DTO;

/** DTO for Cliniko's public GET /settings/public response. */
final class PublicSettingsDTO
{
    public function __construct(
        public ?PublicSettingsAccountDTO $account = null,
        public ?PublicSettingsCalendarDTO $calendar = null,
        public ?PublicSettingsDocumentsAndPrintingDTO $documentsAndPrinting = null,
        public ?PublicSettingsIntegrationsDTO $integrations = null,
        public ?PublicSettingsOnlineBookingsDTO $onlineBookings = null,
        public ?PublicSettingsOnlinePaymentsDTO $onlinePayments = null,
        public ?PublicPatientCustomFieldsDefinitionDTO $patientCustomFieldsDefinition = null,
        public ?PublicSettingsPatientPrivacyDTO $patientPrivacy = null,
        public ?PublicSettingsRemindersDTO $reminders = null,
        public ?PublicSettingsSmsDTO $sms = null,
        public ?PublicSettingsTerminologyDTO $terminology = null,
        public ?PublicSettingsWaitListDTO $waitList = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            PublicSettingsAccountDTO::fromArray(self::arrayValue($data, 'account')),
            PublicSettingsCalendarDTO::fromArray(self::arrayValue($data, 'calendar')),
            PublicSettingsDocumentsAndPrintingDTO::fromArray(self::arrayValue($data, 'documents_and_printing')),
            PublicSettingsIntegrationsDTO::fromArray(self::arrayValue($data, 'integrations')),
            PublicSettingsOnlineBookingsDTO::fromArray(self::arrayValue($data, 'online_bookings')),
            PublicSettingsOnlinePaymentsDTO::fromArray(self::arrayValue($data, 'online_payments')),
            PublicPatientCustomFieldsDefinitionDTO::fromArray(self::arrayValue($data, 'patient_custom_fields_definition')),
            PublicSettingsPatientPrivacyDTO::fromArray(self::arrayValue($data, 'patient_privacy')),
            PublicSettingsRemindersDTO::fromArray(self::arrayValue($data, 'reminders')),
            PublicSettingsSmsDTO::fromArray(self::arrayValue($data, 'sms')),
            PublicSettingsTerminologyDTO::fromArray(self::arrayValue($data, 'terminology')),
            PublicSettingsWaitListDTO::fromArray(self::arrayValue($data, 'wait_list'))
        );
    }

    /** @return array<string,mixed>|null */
    private static function arrayValue(array $data, string $key): ?array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : null;
    }
}
