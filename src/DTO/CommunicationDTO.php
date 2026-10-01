<?php

namespace App\DTO;

if (!defined('ABSPATH')) exit;

final class CommunicationDTO
{
    public function __construct(
        public string $id = '',
        public ?string $archivedAt = null,
        public string $category = '',
        public ?int $categoryCode = null,
        public bool $confidential = false,
        public string $content = '',
        public ?string $createdAt = null,
        public ?int $directionCode = null,
        public string $directionDescription = '',
        public string $from = '',
        public ?CommunicationLinkDTO $links = null,
        public ?CommunicationLinkDTO $patient = null,
        public string $to = '',
        public string $type = '',
        public ?int $typeCode = null,
        public ?string $updatedAt = null
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''), isset($data['archived_at']) ? (string) $data['archived_at'] : null,
            (string) ($data['category'] ?? ''), isset($data['category_code']) ? (int) $data['category_code'] : null,
            (bool) ($data['confidential'] ?? false), (string) ($data['content'] ?? ''),
            isset($data['created_at']) ? (string) $data['created_at'] : null, isset($data['direction_code']) ? (int) $data['direction_code'] : null,
            (string) ($data['direction_description'] ?? ''), (string) ($data['from'] ?? ''),
            CommunicationLinkDTO::fromArray(is_array($data['links'] ?? null) ? $data['links'] : null),
            CommunicationLinkDTO::fromArray(is_array($data['patient']['links'] ?? null) ? $data['patient']['links'] : null),
            (string) ($data['to'] ?? ''), (string) ($data['type'] ?? ''), isset($data['type_code']) ? (int) $data['type_code'] : null,
            isset($data['updated_at']) ? (string) $data['updated_at'] : null,
        );
    }
}
