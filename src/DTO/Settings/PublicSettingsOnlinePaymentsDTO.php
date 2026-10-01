<?php

namespace App\DTO;

final class PublicSettingsOnlinePaymentsDTO
{
    public function __construct(public bool $activated = false) {}

    public static function fromArray(?array $data): ?self
    {
        return $data === null ? null : new self((bool) ($data['activated'] ?? false));
    }
}
