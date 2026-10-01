<?php

namespace App\DTO;

final class PublicSettingsRemindersDTO
{
    /** @param array<int,int> $defaultRemindersCommunicationChannels */
    public function __construct(
        public string $defaultReminderType = '',
        public array $defaultRemindersCommunicationChannels = [],
        public string $defaultRemindersCommunicationChannelsDescription = ''
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) return null;
        $channels = is_array($data['default_reminders_communication_channels'] ?? null) ? $data['default_reminders_communication_channels'] : [];
        return new self(
            (string) ($data['default_reminder_type'] ?? ''),
            array_values(array_map('intval', $channels)),
            (string) ($data['default_reminders_communication_channels_description'] ?? '')
        );
    }
}
