<?php

namespace App\DTO;

final class PublicSettingsSmsDTO
{
    public function __construct(
        public bool $alphanumericSourceNumberRequired = false,
        public string $defaultAlphanumericSourceNumber = '',
        public int $maxMessageLength = 0,
        public int $numberOfUsableCharactersPerMessage = 0,
        public bool $repliesSupported = false
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) return null;
        return new self(
            (bool) ($data['alphanumeric_source_number_required'] ?? false),
            (string) ($data['default_alphanumeric_source_number'] ?? ''),
            (int) ($data['max_message_length'] ?? 0),
            (int) ($data['number_of_usable_characters_per_message'] ?? 0),
            (bool) ($data['replies_supported'] ?? false)
        );
    }
}
