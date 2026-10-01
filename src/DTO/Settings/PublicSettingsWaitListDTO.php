<?php

namespace App\DTO;

final class PublicSettingsWaitListDTO
{
    public function __construct(public int $defaultWaitListExpiryPeriod = 0) {}

    public static function fromArray(?array $data): ?self
    {
        return $data === null ? null : new self((int) ($data['default_wait_list_expiry_period'] ?? 0));
    }
}
