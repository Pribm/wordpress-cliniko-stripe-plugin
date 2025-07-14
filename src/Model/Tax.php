<?php
namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\DTO\TaxDTO;

if (!defined('ABSPATH'))
    exit;

class Tax
{
    public function __construct(
        protected TaxDTO $dto,
        protected ApiClientInterface $client
    ) {
    }

    public static function find(string $id, ApiClientInterface $client): ?self
    {
        $response = $client->get("taxes/{$id}");

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(TaxDTO::fromArray($response->data), $client);
    }

    /**
     * @return Tax[]
     */
    public static function all(ApiClientInterface $client): array
    {
        $response = $client->get("taxes");

        if (!$response->isSuccessful()) {
            return [];
        }

        $items = [];

        foreach ($response->data['taxes'] ?? [] as $item) {
            $items[] = new self(TaxDTO::fromArray($item), $client);
        }

        return $items;
    }

    public static function buildDTO(array $data): TaxDTO
    {
        return TaxDTO::fromArray($data);
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

    public function getRate(): float
    {
        return $this->dto->rate;
    }

    public function getAmount(): float
    {
        return $this->dto->amount;
    }

    public function getCreatedAt(): string
    {
        return $this->dto->createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->dto->updatedAt;
    }
}