<?php

namespace App\DTO;

final class PatientAttachmentDTO
{
    public function __construct(
        public string $id = '',
        public ?string $archivedAt = null,
        public string $category = '',
        public ?PatientAttachmentLinkDTO $content = null,
        public string $contentType = '',
        public ?string $createdAt = null,
        public string $description = '',
        public string $filename = '',
        public ?PatientAttachmentLinkDTO $links = null,
        public ?PatientAttachmentLinkDTO $patient = null,
        public ?string $pinnedAt = null,
        public ?string $processedAt = null,
        public bool $processingCompleted = false,
        public string $size = '',
        public ?string $updatedAt = null,
        public ?PatientAttachmentLinkDTO $user = null
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            isset($data['archived_at']) ? (string) $data['archived_at'] : null,
            (string) ($data['category'] ?? ''),
            PatientAttachmentLinkDTO::fromArray(is_array($data['content']['links'] ?? null) ? $data['content']['links'] : null),
            (string) ($data['content_type'] ?? ''),
            isset($data['created_at']) ? (string) $data['created_at'] : null,
            (string) ($data['description'] ?? ''),
            (string) ($data['filename'] ?? ''),
            PatientAttachmentLinkDTO::fromArray(is_array($data['links'] ?? null) ? $data['links'] : null),
            PatientAttachmentLinkDTO::fromArray(is_array($data['patient']['links'] ?? null) ? $data['patient']['links'] : null),
            isset($data['pinned_at']) ? (string) $data['pinned_at'] : null,
            isset($data['processed_at']) ? (string) $data['processed_at'] : null,
            (bool) ($data['processing_completed'] ?? false),
            (string) ($data['size'] ?? ''),
            isset($data['updated_at']) ? (string) $data['updated_at'] : null,
            PatientAttachmentLinkDTO::fromArray(is_array($data['user']['links'] ?? null) ? $data['user']['links'] : null)
        );
    }
}
