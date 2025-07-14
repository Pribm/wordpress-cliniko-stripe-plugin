<?php
namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\DTO\CreatePatientCaseDTO;
use App\DTO\PatientCaseDTO;
use App\Client\Cliniko\Client;

if (!defined('ABSPATH')) exit;

class PatientCase
{
    protected PatientCaseDTO $dto;
    protected ApiClientInterface $client;

    public function __construct(PatientCaseDTO $dto, ApiClientInterface $client)
    {
        $this->dto = $dto;
        $this->client = $client;
    }

    public static function find(string $id, ApiClientInterface $client): ?self
    {
        $response = $client->get("patient_cases/{$id}");

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PatientCaseDTO::fromArray($response->data), $client);
    }

    /**
     * @return PatientCase[]
     */
    public static function all(ApiClientInterface $client): array
    {
        $response = $client->get("patient_cases");

        if (!$response->isSuccessful()) {
            return [];
        }

        $items = [];

        foreach ($response->data['patient_cases'] ?? [] as $item) {
            $items[] = new self(PatientCaseDTO::fromArray($item), $client);
        }

        return $items;
    }

    public static function create(CreatePatientCaseDTO $dto, ApiClientInterface $client): ?self
    {
        $response = $client->post('patient_cases', $dto->toArray());

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PatientCaseDTO::fromArray($response->data), $client);
    }

    public static function findFromUrl(string $url, ApiClientInterface $client): ?self
    {
        $response = $client->get($url);

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PatientCaseDTO::fromArray($response->data), $client);
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

    public function getNotes(): string
    {
        return $this->dto->notes;
    }

    public function isClosed(): bool
    {
        return $this->dto->closed;
    }

    public function isReferral(): bool
    {
        return $this->dto->referral;
    }

    public function getBookings(): array
    {
        $response = $this->client->get($this->dto->bookingsUrl);

        if (!$response->isSuccessful()) {
            return [];
        }

        return $response->data['bookings'] ?? [];
    }
}
