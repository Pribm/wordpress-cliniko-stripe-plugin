<?php
namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\DTO\CreatePatientDTO;
use App\DTO\PatientDTO;
use App\Client\Cliniko\Client;

if (!defined('ABSPATH')) exit;

class Patient
{
    public function __construct(
        protected PatientDTO $dto,
        protected ApiClientInterface $client
    ) {}

    public static function find(string $id, ApiClientInterface $client): ?self
    {
        $response = $client->get("patients/{$id}");

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PatientDTO::fromArray($response->data), $client);
    }

    public static function create(CreatePatientDTO $dto, ApiClientInterface $client): ?self
    {
        
        $response = $client->post('patients', $dto->toArray());

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PatientDTO::fromArray($response->data), $client);
    }

    public static function findFromUrl(string $url, ApiClientInterface $client): ?self
    {
        $response = $client->get($url);

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PatientDTO::fromArray($response->data), $client);
    }

    public static function query(string $query, ApiClientInterface $client): ?self
    {
        $response = $client->get("patients" . $query);

        if (!$response->isSuccessful()) {
            return null;
        }

        $patients = $response->data["patients"] ?? [];

        if (empty($patients)) {
            return null;
        }

        return new self(PatientDTO::fromArray($patients[0]), $client);
    }

    // Getters

    public function getId(): string
    {
        return $this->dto->id;
    }

    public function getFullName(): string
    {
        return trim("{$this->dto->firstName} {$this->dto->lastName}");
    }

    public function getPreferredName(): string
    {
        return $this->dto->preferredFirstName ?? $this->getFullName();
    }

    public function getEmail(): ?string { return $this->dto->email; }
    public function getPhone(): ?string { return $this->dto->phone; }
    public function getAddress(): ?string { return $this->dto->address; }
    public function getDateOfBirth(): ?string { return $this->dto->dateOfBirth; }
    public function getGender(): ?string { return $this->dto->gender; }
    public function getOccupation(): ?string { return $this->dto->occupation; }
    public function getNotes(): ?string { return $this->dto->notes; }

    public function getDTO(): PatientDTO
    {
        return $this->dto;
    }
}
