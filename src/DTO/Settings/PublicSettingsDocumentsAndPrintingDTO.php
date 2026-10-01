<?php

namespace App\DTO;

final class PublicSettingsDocumentsAndPrintingDTO
{
    public function __construct(public int $logoHeight = 0, public ?string $logoUrl = null) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) return null;
        return new self((int) ($data['logo_height'] ?? 0), isset($data['logo_url']) ? (string) $data['logo_url'] : null);
    }
}
