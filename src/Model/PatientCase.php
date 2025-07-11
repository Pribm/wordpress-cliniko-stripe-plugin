<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;
use App\DTO\CreatePatientCaseDTO;
use App\DTO\PatientCaseDTO;
use App\Client\ClinikoClient;

class PatientCase
{
    protected PatientCaseDTO $dto;
    protected ClinikoClient $client;

    public function __construct(PatientCaseDTO $dto, ClinikoClient $client)
    {
        $this->dto = $dto;
        $this->client = $client;
    }

    public static function find(string $id, ClinikoClient $client): ?self
    {
        $data = $client->get("patient_cases/{$id}");
        if (!$data)
            return null;

        return new self(PatientCaseDTO::fromArray($data), $client);
    }

    /**
     * @return PatientCase[]
     */
    public static function all(ClinikoClient $client): array
    {
        $response = $client->get("patient_cases");
        $items = [];

        foreach ($response['patient_cases'] ?? [] as $item) {
            $items[] = new self(PatientCaseDTO::fromArray($item), $client);
        }

        return $items;
    }

    public static function create(CreatePatientCaseDTO $dto, ClinikoClient $client)
    {
        $data = $client->post('patient_cases', $dto->toArray());
        return new self(PatientCaseDTO::fromArray($data), $client);
    }

    public static function findFromUrl(string $url, ClinikoClient $client): ?self
    {
        $data = $client->get($url);
        return new self(PatientCaseDTO::fromArray($data), $client);
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
        return $this->client->get($this->dto->bookingsUrl);
    }

}
