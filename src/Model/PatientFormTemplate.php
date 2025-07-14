<?php

namespace App\Model;

use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;
use App\DTO\CreatePatientFormTemplateDTO;
use App\DTO\PatientFormTemplateDTO;
use App\Exception\ApiException;

if (!defined('ABSPATH')) exit;

class PatientFormTemplate
{
    protected PatientFormTemplateDTO $dto;
    protected ApiClientInterface $client;

    public function __construct(PatientFormTemplateDTO $dto, ApiClientInterface $client)
    {
        $this->dto = $dto;
        $this->client = $client;
    }

    public static function create(CreatePatientFormTemplateDTO $dto, ApiClientInterface $client): self
    {
        $response = $client->post('patient_form_templates', $dto->toArray());

        if (!$response->isSuccessful()) {
            throw new ApiException('Failed to create patient form template.', [
                'dto' => $dto,
                'error' => $response->error
            ]);
        }

        return new self(PatientFormTemplateDTO::fromArray($response->data), $client);
    }

    public static function find(string $id, ApiClientInterface $client): ?self
    {
        $response = $client->get("patient_form_templates/{$id}");

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PatientFormTemplateDTO::fromArray($response->data), $client);
    }

    /**
     * @return PatientFormTemplate[]|null
     */
    public static function all(ApiClientInterface $client): array|null
    {
        $response = $client->get('patient_form_templates');

        if (!$response->isSuccessful()) {
            return null;
        }

        return array_map(
            fn($item) => new self(PatientFormTemplateDTO::fromArray($item), $client),
            $response->data['patient_form_templates'] ?? []
        );
    }

    public static function findFromUrl(string $url, ApiClientInterface $client): ?self
    {
        $response = $client->get($url);

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PatientFormTemplateDTO::fromArray($response->data), $client);
    }

    public static function delete(string $id, ApiClientInterface $client): bool
    {
        $response = $client->post("patient_form_templates/{$id}/archive", []);

        if (!$response->isSuccessful()) {
            throw new ApiException("Failed to archive patient form template: {$id}.", [
                'id' => $id,
                'error' => $response->error
            ]);
        }

        return true;
    }

    // Getters

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
