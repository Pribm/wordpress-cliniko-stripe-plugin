<?php

namespace App\DTO;

final class PatientAttachmentLinkDTO
{
    public function __construct(public ?string $self = null) {}

    public static function fromArray(?array $data): ?self
    {
        return $data === null ? null : new self(isset($data['self']) ? (string) $data['self'] : null);
    }
}
