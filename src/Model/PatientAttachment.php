<?php

namespace App\Model;

use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\PatientAttachmentDTO;
use App\DTO\PatientAttachmentsDTO;
use App\Exception\ApiException;
use App\Support\Auth;

if (!defined('ABSPATH')) exit;

final class PatientAttachment extends AbstractModel
{
    protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    public function getAttachmentDTO(): ?PatientAttachmentDTO
    {
        return $this->dto instanceof PatientAttachmentDTO ? $this->dto : null;
    }

    public function getFilename(): string { $dto = $this->getAttachmentDTO(); return $dto ? $dto->filename : ''; }
    public function getDescription(): string { $dto = $this->getAttachmentDTO(); return $dto ? $dto->description : ''; }
    public function getCategory(): string { $dto = $this->getAttachmentDTO(); return $dto ? $dto->category : ''; }
    public function getContentType(): string { $dto = $this->getAttachmentDTO(); return $dto ? $dto->contentType : ''; }
    public function getSize(): string { $dto = $this->getAttachmentDTO(); return $dto ? $dto->size : ''; }
    public function isProcessingCompleted(): bool { $dto = $this->getAttachmentDTO(); return $dto ? $dto->processingCompleted : false; }
    public function getContentUrl(): ?string { return $this->getAttachmentDTO()?->content?->self; }
    public function getPatientUrl(): ?string { return $this->getAttachmentDTO()?->patient?->self; }
    public function getUserUrl(): ?string { return $this->getAttachmentDTO()?->user?->self; }

    public static function findForAuthenticatedPatient(string $attachmentId, ?ApiClientInterface $client = null): ?self
    {
        $patient = Auth::user();
        if ($patient === null || (string) $patient->getId() === '' || !ctype_digit($attachmentId)) {
            return null;
        }

        $api = $client ?: (function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 300)
            : Client::getInstance());
        $attachment = self::find($attachmentId, $api, false);
        if ($attachment === null) return null;

        $patientUrl = $attachment->getPatientUrl();
        $parts = $patientUrl ? array_values(array_filter(explode('/', trim((string) parse_url($patientUrl, PHP_URL_PATH), '/')))) : [];
        $linkedPatientId = $parts === [] ? '' : (string) end($parts);
        return hash_equals((string) $patient->getId(), $linkedPatientId) ? $attachment : null;
    }

    public static function updateForAuthenticatedPatient(string $attachmentId, string $description, ?ApiClientInterface $client = null): ?self
    {
        $api = $client ?: (function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 300)
            : Client::getInstance());
        $attachment = self::findForAuthenticatedPatient($attachmentId, $api);
        if ($attachment === null) return null;

        return self::update($attachmentId, ['description' => $description], $api);
    }

    public static function archiveForAuthenticatedPatient(string $attachmentId, ?ApiClientInterface $client = null): bool
    {
        $api = $client ?: (function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 300)
            : Client::getInstance());
        $attachment = self::findForAuthenticatedPatient($attachmentId, $api);
        if ($attachment === null) return false;

        $response = $api->post('patient_attachments/' . rawurlencode($attachmentId) . '/archive', []);
        if (!$response->isSuccessful()) {
            throw new ApiException('Failed to archive the authenticated patient attachment.', [
                'error' => $response->error,
                'status_code' => $response->statusCode,
            ]);
        }
        return true;
    }

    /**
     * Fetch only attachments belonging to the currently authenticated Cliniko patient.
     *
     * @return array{items:list<self>,pagination:PatientAttachmentsDTO}
     * @throws ApiException
     */
    public static function forAuthenticatedPatient(
        ?ApiClientInterface $client = null,
        int $page = 1,
        int $perPage = 20,
        string $order = 'desc'
    ): array {
        $patient = Auth::user();
        if ($patient === null || (string) $patient->getId() === '') {
            return ['items' => [], 'pagination' => new PatientAttachmentsDTO()];
        }

        $patientId = (string) $patient->getId();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $order = strtolower($order) === 'asc' ? 'asc' : 'desc';
        $query = http_build_query([
            'page' => $page,
            'per_page' => $perPage,
            'sort' => 'created_at:' . $order,
            'order' => $order,
            'q[]' => 'patient_id:=' . $patientId,
        ]);

        $api = $client ?: (function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 300)
            : Client::getInstance());
        $response = $api->get('patient_attachments?' . $query);
        if (!$response->isSuccessful() || !is_array($response->data)) {
            throw new ApiException('Failed to fetch authenticated patient attachments.', [
                'error' => $response->error,
                'status_code' => $response->statusCode,
            ]);
        }

        $pagination = PatientAttachmentsDTO::fromArray($response->data);
        $items = array_map(
            static fn(PatientAttachmentDTO $dto): self => new self($dto, $api),
            $pagination->patientAttachments
        );

        return ['items' => $items, 'pagination' => $pagination];
    }
}
