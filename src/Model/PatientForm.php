<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\DTO\PatientFormDTO;
use App\DTO\CreatePatientFormDTO;
use App\Client\Cliniko\Client;
use App\Exception\ApiException;

if (!defined('ABSPATH')) exit;

class PatientForm
{
    protected PatientFormDTO $dto;
    protected ApiClientInterface $client;

    public function __construct(PatientFormDTO $dto, ApiClientInterface $client)
    {
        $this->dto = $dto;
        $this->client = $client;
    }

    public static function find(string $id, ApiClientInterface $client): ?self
    {
        $response = $client->get("patient_forms/{$id}");

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PatientFormDTO::fromArray($response->data), $client);
    }

    public static function create(CreatePatientFormDTO $dto, ApiClientInterface $client): self
    {
        $response = $client->post("patient_forms", $dto->toArray());

        if (!$response->isSuccessful()) {
            throw new ApiException("Request to create patient form failed.", [
                "error" => $response->error,
                "serialized_obj" => $dto->toArray()
            ]);
        }

        if (isset($response->data['errors'])) {
            throw new ApiException("API Validation error", [
                "errors" => $response->data["errors"],
                "serialized_obj" => $dto->toArray()
            ]);
        }

        return new self(PatientFormDTO::fromArray($response->data), $client);
    }

    public static function findFromUrl(string $url, ApiClientInterface $client): ?self
    {
        $response = $client->get($url);

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PatientFormDTO::fromArray($response->data), $client);
    }

    // Getters
    public function getId(): string
    {
        return $this->dto->id;
    }

    public function getName(): string
    {
        return $this->dto->name;
    }

    public function getUrl(): string
    {
        return $this->dto->url;
    }

    public function getSections(): array
    {
        return $this->dto->content['sections'] ?? [];
    }

    public function isCompleted(): bool
    {
        return !empty($this->dto->completed_at);
    }

    public function isEmailToPatientOnCompletion(): bool
    {
        return $this->dto->email_to_patient_on_completion;
    }

    public function isRestrictedToPractitioner(): bool
    {
        return $this->dto->restricted_to_practitioner;
    }

    public function getPatient(): array
    {
        return $this->safeGetLinkedEntity($this->dto->links['patient']['self'] ?? '');
    }

    public function getBusiness(): array
    {
        return $this->safeGetLinkedEntity($this->dto->links['business']['self'] ?? '');
    }

    public function getAttendee(): array
    {
        return $this->safeGetLinkedEntity($this->dto->links['attendee']['self'] ?? '');
    }

    public function getBooking(): array
    {
        return $this->safeGetLinkedEntity($this->dto->links['booking']['self'] ?? '');
    }

    public function getSignatures(): array
    {
        return $this->safeGetLinkedEntity($this->dto->links['signatures']['self'] ?? '');
    }

    /**
     * Shared handler for linked entity fetches
     */
    protected function safeGetLinkedEntity(string $url): array
    {
        if (!$url) {
            return [];
        }

        $response = $this->client->get($url);

        if (!$response->isSuccessful()) {
            return [];
        }

        return $response->data;
    }
}
