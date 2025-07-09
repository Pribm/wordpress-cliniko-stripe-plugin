<?php

namespace App\Model;
if (!defined('ABSPATH')) exit;

use App\Client\ClinikoClient;
use App\DTO\PatientFormTemplateDTO;
use App\DTO\CreatePatientFormTemplateDTO;
use App\Exception\ApiException;

class PatientFormTemplate
{
    protected PatientFormTemplateDTO $dto;
    protected ClinikoClient $client;

    public function __construct(PatientFormTemplateDTO $dto, ClinikoClient $client)
    {
        $this->dto = $dto;
        $this->client = $client;
    }

    public static function create(CreatePatientFormTemplateDTO $dto, ClinikoClient $client): self
    {
        try {
            $response = $client->post('patient_form_templates', $dto->toArray());
            return new self(PatientFormTemplateDTO::fromArray($response), $client);
        } catch (\Throwable $e) {
            throw new ApiException('Failed to create patient form template.', ['dto' => $dto], 0, $e);
        }
    }

    public static function find(string $id, ClinikoClient $client): ?self
    {
        try {
            $data = $client->get("patient_form_templates/{$id}");

            if (!$data) {
                return null;
            }

            return new self(PatientFormTemplateDTO::fromArray($data), $client);
        } catch (\Throwable $e) {
            throw new ApiException("Failed to find patient form template: {$id}.", ['id' => $id], 0, $e);
        }
    }

    /**
     * @return PatientFormTemplate[]|null
     */
    public static function all(ClinikoClient $client): array|null
    {
        try {
            $response = $client->get('patient_form_templates');

            return array_map(
                fn($item) => new self(PatientFormTemplateDTO::fromArray($item), $client),
                $response['patient_form_templates'] ?? []
            );
        } catch (\Throwable $e) {
            throw new ApiException('Failed to fetch all patient form templates.', [], 0, $e);
        }
    }

    public static function findFromUrl(string $url, ClinikoClient $client): ?self
    {
        try {
            $data = $client->get($url);
            return new self(PatientFormTemplateDTO::fromArray($data), $client);
        } catch (\Throwable $e) {
            throw new ApiException("Failed to find patient form template from URL: {$url}", ['url' => $url], 0, $e);
        }
    }

    public static function delete(string $id, ClinikoClient $client): mixed
    {
        try {
            return $client->post("patient_form_templates/{$id}/archive", []);
        } catch (\Throwable $e) {
            throw new ApiException("Failed to archive patient form template: {$id}.", ['id' => $id], 0, $e);
        }
    }

    public function getDTO(): PatientFormTemplateDTO
    {
        return $this->dto;
    }

    public function getId(): string
    {
        return $this->dto->id;
    }

    public function getName(): string
    {
        return $this->dto->name;
    }

    public function isRestrictedToPractitioner(): bool
    {
        return $this->dto->restrictedToPractitioner;
    }

    public function isEmailToPatientOnCompletion(): bool
    {
        return $this->dto->emailToPatientOnCompletion;
    }

    /**
     * @return \App\DTO\PatientFormTemplateSectionDTO[]
     */
    public function getSections()
    {
        return $this->dto->sections;
    }

    public function isArchived(): bool
    {
        return !empty($this->dto->archivedAt);
    }

    public function getCreatedAt()
    {
        return $this->dto->createdAt;
    }

    public function getUpdatedAt()
    {
        return $this->dto->updatedAt;
    }

    public function getLink()
    {
        return $this->dto->selfLink;
    }
}
