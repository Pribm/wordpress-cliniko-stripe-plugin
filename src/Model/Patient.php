<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;
use App\DTO\CreatePatientDTO;
use App\DTO\PatientDTO;
use App\Client\ClinikoClient;

class Patient
{
    public function __construct(
        protected PatientDTO $dto,
        protected ClinikoClient $client
    ) {}

    public static function find(string $id, ClinikoClient $client): ?self
    {
        $data = $client->get("patients/{$id}");
        return new self(PatientDTO::fromArray($data), $client);
    }

    public static function create(CreatePatientDTO $dto, ClinikoClient $client): self
    {
        $response = $client->post('patients', $dto->toArray());
        return new self(PatientDTO::fromArray($response), $client);
    }

    public static function findFromUrl(string $url, ClinikoClient $client): ?self
    {
        $data = $client->get($url);
        return new self(PatientDTO::fromArray($data), $client);
    }

    public static function query(string $query, ClinikoClient $client)
    {
        $t = "patients".$query;
        $data = $client->get("patients".$query);

        $filteredPatients = $data["patients"];

        $firstFiltered = $filteredPatients[0];

        if(!$firstFiltered) return null;

        return new self(PatientDTO::fromArray($firstFiltered), $client);
    }

    // Getters

    public function getId(): string { return $this->dto->id; }

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

    public function getDTO(){return $this->dto;}
}
