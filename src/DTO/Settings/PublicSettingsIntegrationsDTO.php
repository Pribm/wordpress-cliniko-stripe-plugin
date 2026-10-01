<?php

namespace App\DTO;

final class PublicSettingsIntegrationsDTO
{
    public function __construct(
        public ?PublicSettingsIntegrationDTO $mailChimp = null,
        public ?PublicSettingsIntegrationDTO $xero = null
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) return null;
        return new self(
            PublicSettingsIntegrationDTO::fromArray(is_array($data['mail_chimp'] ?? null) ? $data['mail_chimp'] : null),
            PublicSettingsIntegrationDTO::fromArray(is_array($data['xero'] ?? null) ? $data['xero'] : null)
        );
    }
}
