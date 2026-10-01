<?php

namespace App\DTO;

final class PublicSettingsCalendarDTO
{
    public function __construct(
        public int $endHour = 0,
        public bool $multipleAppointmentsGap = false,
        public bool $showCurrentTimeIndicator = false,
        public int $startHour = 0,
        public int $timeslotHeightInPixels = 0,
        public int $timeslotSizeInMinutes = 0
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) return null;
        return new self(
            (int) ($data['end_hour'] ?? 0),
            (bool) ($data['multiple_appointments_gap'] ?? false),
            (bool) ($data['show_current_time_indicator'] ?? false),
            (int) ($data['start_hour'] ?? 0),
            (int) ($data['timeslot_height_in_pixels'] ?? 0),
            (int) ($data['timeslot_size_in_minutes'] ?? 0)
        );
    }
}
