<?php

namespace App\DTO;

/** Request body for Cliniko's PUT /patients/{id} endpoint. */
final class PatientUpdateDTO
{
    /** @var array<string,mixed> */
    private array $values;

    /** @param array<string,mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $allowed = array_flip([
            'accepted_email_marketing', 'accepted_privacy_policy', 'address_1', 'address_2', 'address_3',
            'appointment_notes', 'city', 'concession_type_id', 'country', 'country_code', 'custom_fields',
            'date_of_birth', 'dva_card_number', 'email', 'emergency_contact', 'first_name', 'gender_identity',
            'invoice_default_to', 'invoice_email', 'invoice_extra_information', 'last_name',
            'medicare_reference_number', 'medicare', 'notes', 'occupation', 'old_reference_id', 'post_code',
            'preferred_first_name', 'pronouns', 'receives_cancellation_emails', 'receives_confirmation_emails',
            'referral_source', 'referring_doctor_id', 'sex', 'state', 'time_zone', 'title',
            'unsubscribe_sms_marketing', 'follow_ups_communication_channels', 'reminders_communication_channels',
            'patient_phone_numbers', 'reminder_type',
        ]);
        return array_intersect_key($this->values, $allowed);
    }
}
