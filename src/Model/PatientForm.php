<?php

namespace App\Model;

use App\Exception\ApiException;
if (!defined('ABSPATH'))
    exit;

use App\DTO\PatientFormDTO;
use App\DTO\CreatePatientFormDTO;
use App\Client\ClinikoClient;

class PatientForm
{
    protected PatientFormDTO $dto;
    protected ClinikoClient $client;

    public function __construct(PatientFormDTO $dto, ClinikoClient $client)
    {
        $this->dto = $dto;
        $this->client = $client;
    }

    public static function find(string $id, ClinikoClient $client): ?self
    {
        $data = $client->get("patient_forms/{$id}");
        if (!$data)
            return null;

        return new self(PatientFormDTO::fromArray($data), $client);
    }

    public static function create(CreatePatientFormDTO $dto, ClinikoClient $client): self
    {

        $data = $client->post("patient_forms", $dto->toArray());
        if (isset($data['errors'])) {
            throw new ApiException("Api Validation error", [
                "errors" => $data["errors"],
                "serialized_obj" => $dto->toArray()
            ]);
        }
        return new self(PatientFormDTO::fromArray($data), $client);
    }

    public static function findFromUrl(string $url, ClinikoClient $client): ?self
    {
        $data = $client->get($url);
        return new self(PatientFormDTO::fromArray($data), $client);
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

    public function getContent(): array
    {
        return $this->dto->content;
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

    public function getPatient()
    {
        return $this->client->get($this->dto->links['patient']['self'] ?? '');
    }

    public function getBusiness()
    {
        return $this->client->get($this->dto->links['business']['self'] ?? '');
    }

    public function getAttendee()
    {
        return $this->client->get($this->dto->links['attendee']['self'] ?? '');
    }

    public function getBooking()
    {
        return $this->client->get($this->dto->links['booking']['self'] ?? '');
    }

    public function getSignatures()
    {
        return $this->client->get($this->dto->links['signatures']['self'] ?? '');
    }
}
