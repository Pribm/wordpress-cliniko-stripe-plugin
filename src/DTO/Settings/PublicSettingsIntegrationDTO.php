<?php

namespace App\DTO;

final class PublicSettingsIntegrationDTO
{
    public function __construct(public bool $enabled = false) {}

    public static function fromArray(?array $data): ?self
    {
        return $data === null ? null : new self((bool) ($data['enabled'] ?? false));
    }
}
