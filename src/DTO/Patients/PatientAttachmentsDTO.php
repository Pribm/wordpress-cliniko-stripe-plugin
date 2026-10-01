<?php

namespace App\DTO;

final class PatientAttachmentsDTO
{
    /** @param list<PatientAttachmentDTO> $patientAttachments */
    public function __construct(
        public array $patientAttachments = [],
        public int $totalEntries = 0,
        public ?string $selfUrl = null,
        public ?string $previousUrl = null,
        public ?string $nextUrl = null
    ) {}

    public static function fromArray(array $data): self
    {
        $attachments = [];
        foreach (($data['patient_attachments'] ?? []) as $attachment) {
            if (is_array($attachment)) {
                $attachments[] = PatientAttachmentDTO::fromArray($attachment);
            }
        }

        $links = is_array($data['links'] ?? null) ? $data['links'] : [];
        return new self(
            $attachments,
            (int) ($data['total_entries'] ?? count($attachments)),
            isset($links['self']) ? (string) $links['self'] : null,
            isset($links['previous']) ? (string) $links['previous'] : null,
            isset($links['next']) ? (string) $links['next'] : null
        );
    }
}
