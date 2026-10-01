<?php

namespace App\Controller;

use App\DTO\PatientAttachmentDTO;
use App\Exception\ApiException;
use App\Model\PatientAttachment;
use App\Service\ClinikoAttachmentService;
use App\Support\Auth;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientAttachmentController
{
    private ClinikoAttachmentService $attachments;

    public function __construct(?ClinikoAttachmentService $attachments = null)
    {
        $this->attachments = $attachments ?: new ClinikoAttachmentService();
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(function (string $patientId) use ($request): array {
            $result = PatientAttachment::forAuthenticatedPatient(
                null,
                max(1, (int) $request->get_param('page')),
                max(1, (int) ($request->get_param('per_page') ?: 20)),
                (string) ($request->get_param('order') ?: 'desc')
            );
            return [
                'attachments' => array_values(array_filter(array_map(
                    fn(PatientAttachment $attachment): ?array => $this->toPatientAttachment($attachment),
                    $result['items']
                ))),
                'pagination' => $result['pagination'],
            ];
        });
    }

    public function presign(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(function (string $patientId): array {
            return $this->attachments->getPresignedPost($patientId);
        });
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(function (string $patientId) use ($request): array {
            $body = json_decode($request->get_body(), true);
            $data = is_array($body) ? $body : $request->get_params();
            $uploadUrl = esc_url_raw((string) ($data['upload_url'] ?? ''));
            $description = sanitize_textarea_field((string) ($data['description'] ?? ''));
            if ($uploadUrl === '') {
                return ['__error' => 'The completed upload URL is required.', '__status' => 400];
            }
            $created = $this->attachments->createAttachmentRecord($patientId, $uploadUrl, $description);
            if (function_exists('cliniko_dashboard_cache_invalidate')) {
                cliniko_dashboard_cache_invalidate();
            }
            return ['attachment' => $this->toPatientAttachmentDTO(PatientAttachmentDTO::fromArray($created))];
        });
    }

    public function upload(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(function (string $patientId) use ($request): array {
            $files = $request->get_file_params();
            $file = is_array($files['file'] ?? null) ? $files['file'] : null;
            if ($file === null || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (string) ($file['tmp_name'] ?? '') === '') {
                return ['__error' => 'A valid file is required.', '__status' => 400];
            }
            $description = sanitize_textarea_field((string) $request->get_param('description'));
            $filename = sanitize_file_name((string) ($file['name'] ?? 'document'));
            if ($filename === '') $filename = 'document';
            $created = $this->attachments->uploadPatientAttachment($patientId, (string) $file['tmp_name'], $filename, $description);
            if (function_exists('cliniko_dashboard_cache_invalidate')) {
                cliniko_dashboard_cache_invalidate();
            }
            return ['attachment' => $this->toPatientAttachmentDTO($created)];
        });
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(function (string $patientId) use ($request): array {
            $id = sanitize_text_field((string) $request->get_param('attachment_id'));
            $body = json_decode($request->get_body(), true);
            $data = is_array($body) ? $body : $request->get_params();
            $description = sanitize_textarea_field((string) ($data['description'] ?? ''));
            $attachment = PatientAttachment::updateForAuthenticatedPatient($id, $description);
            return $attachment === null
                ? ['__error' => 'Attachment not found for this patient.', '__status' => 404]
                : ['attachment' => $this->toPatientAttachment($attachment)];
        });
    }

    public function archive(WP_REST_Request $request): WP_REST_Response
    {
        return $this->respond(function (string $patientId) use ($request): array {
            $id = sanitize_text_field((string) $request->get_param('attachment_id'));
            if (!PatientAttachment::archiveForAuthenticatedPatient($id)) {
                return ['__error' => 'Attachment not found for this patient.', '__status' => 404];
            }
            return ['archived' => true];
        });
    }

    /** @return array<string,mixed>|null */
    private function toPatientAttachment(PatientAttachment $attachment): ?array
    {
        return $this->toPatientAttachmentDTO($attachment->getAttachmentDTO());
    }

    /** @param mixed $dto @return array<string,mixed>|null */
    private function toPatientAttachmentDTO($dto): ?array
    {
        if (!is_object($dto)) return null;
        return [
            'id' => (string) ($dto->id ?? ''),
            'archived_at' => $dto->archivedAt ?? null,
            'category' => (string) ($dto->category ?? ''),
            'content_type' => (string) ($dto->contentType ?? ''),
            'created_at' => $dto->createdAt ?? null,
            'description' => (string) ($dto->description ?? ''),
            'filename' => (string) ($dto->filename ?? ''),
            'pinned_at' => $dto->pinnedAt ?? null,
            'processed_at' => $dto->processedAt ?? null,
            'processing_completed' => (bool) ($dto->processingCompleted ?? false),
            'size' => (string) ($dto->size ?? ''),
            'updated_at' => $dto->updatedAt ?? null,
        ];
    }

    /** @param callable(string):array<string,mixed> $callback */
    private function respond(callable $callback): WP_REST_Response
    {
        try {
            if (!is_user_logged_in()) {
                return new WP_REST_Response(['ok' => false, 'message' => 'Authentication required.'], 403);
            }
            $patient = Auth::user();
            if ($patient === null || (string) $patient->getId() === '') {
                return new WP_REST_Response(['ok' => false, 'message' => 'Patient not found.'], 404);
            }
            $result = $callback((string) $patient->getId());
            if (isset($result['__error'])) {
                $status = (int) ($result['__status'] ?? 400);
                return new WP_REST_Response(['ok' => false, 'message' => $result['__error']], $status);
            }
            return new WP_REST_Response(['ok' => true, 'data' => $result], 200);
        } catch (\Throwable $exception) {
            error_log('Cliniko patient attachment request failed: ' . $exception->getMessage());
            return new WP_REST_Response([
                'ok' => false,
                'code' => $exception instanceof ApiException ? 'cliniko_connection_unavailable' : 'cliniko_attachment_request_failed',
                'message' => $exception instanceof ApiException
                    ? 'Cliniko is temporarily unavailable. Please try again shortly.'
                    : 'The attachment request could not be completed.',
            ], $exception instanceof ApiException ? 503 : 502);
        }
    }
}
