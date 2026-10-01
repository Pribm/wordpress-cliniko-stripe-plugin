<?php

namespace App\DTO;

final class PublicSettingsPatientPrivacyDTO
{
    public function __construct(
        public string $browserTitleNameFormat = '',
        public string $icalPatientNameOption = '',
        public bool $preventSendingFinancialDataByEmail = false,
        public bool $preventSendingTreatmentNotesByEmail = false,
        public bool $requiresHipaaCompliance = false
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) return null;
        return new self(
            (string) ($data['browser_title_name_format'] ?? ''),
            (string) ($data['ical_patient_name_option'] ?? ''),
            (bool) ($data['prevent_sending_financial_data_by_email'] ?? false),
            (bool) ($data['prevent_sending_treatment_notes_by_email'] ?? false),
            (bool) ($data['requires_hipaa_compliance'] ?? false)
        );
    }
}
