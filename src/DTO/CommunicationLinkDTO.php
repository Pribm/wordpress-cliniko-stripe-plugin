<?php

namespace App\DTO;

if (!defined('ABSPATH')) exit;

final class CommunicationLinkDTO
{
    public function __construct(public ?string $self = null) {}

    /** @param array<string,mixed>|null $data */
    public static function fromArray(?array $data): ?self
    {
        return $data === null ? null : new self(isset($data['self']) ? (string) $data['self'] : null);
    }
}
