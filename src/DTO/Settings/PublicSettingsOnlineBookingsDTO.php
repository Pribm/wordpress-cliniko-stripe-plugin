<?php

namespace App\DTO;

final class PublicSettingsOnlineBookingsDTO
{
    /** @param array<int,string> $practitionerOrder */
    public function __construct(
        public int $allowBookingsDaysInAdvance = 0,
        public bool $allowOnlineBookingsTimeZoneChoice = false,
        public int $bookingReservationMinutes = 0,
        public string $calendarInfo = '',
        public int $dailyBookingsLimit = 0,
        public string $disabledText = '',
        public string $disabledTitle = '',
        public bool $enabled = false,
        public ?string $logoUrl = null,
        public int $maxAppointmentsPerDaySegment = 0,
        public int $minHoursAdvanceRequiredToBook = 0,
        public int $minHoursNoticeForPatientCancellation = 0,
        public bool $noticeEnabled = false,
        public string $noticeText = '',
        public string $noticeTitle = '',
        public bool $notifyPractitionerByEmail = false,
        public bool $notifyPractitionerBySms = false,
        public string $policy = '',
        public array $practitionerOrder = [],
        public ?string $privacyPolicyUrl = null,
        public bool $requirePatientAddress = false,
        public bool $showAppointmentDuration = false,
        public bool $showPrices = false
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) return null;
        $practitioners = is_array($data['practitioner_order'] ?? null) ? $data['practitioner_order'] : [];
        return new self(
            (int) ($data['allow_bookings_days_in_advance'] ?? 0),
            (bool) ($data['allow_online_bookings_time_zone_choice'] ?? false),
            (int) ($data['booking_reservation_minutes'] ?? 0),
            (string) ($data['calendar_info'] ?? ''),
            (int) ($data['daily_bookings_limit'] ?? 0),
            (string) ($data['disabled_text'] ?? ''),
            (string) ($data['disabled_title'] ?? ''),
            (bool) ($data['enabled'] ?? false),
            isset($data['logo_url']) ? (string) $data['logo_url'] : null,
            (int) ($data['max_appointments_per_day_segment'] ?? 0),
            (int) ($data['min_hours_advance_required_to_book'] ?? 0),
            (int) ($data['min_hours_notice_for_patient_cancellation'] ?? 0),
            (bool) ($data['notice_enabled'] ?? false),
            (string) ($data['notice_text'] ?? ''),
            (string) ($data['notice_title'] ?? ''),
            (bool) ($data['notify_practitioner_by_email'] ?? false),
            (bool) ($data['notify_practitioner_by_sms'] ?? false),
            (string) ($data['policy'] ?? ''),
            array_values(array_map('strval', $practitioners)),
            isset($data['privacy_policy_url']) ? (string) $data['privacy_policy_url'] : null,
            (bool) ($data['require_patient_address'] ?? false),
            (bool) ($data['show_appointment_duration'] ?? false),
            (bool) ($data['show_prices'] ?? false)
        );
    }
}
