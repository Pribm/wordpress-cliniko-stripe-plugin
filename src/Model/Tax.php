<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;
use App\Client\ClinikoClient;
use App\DTO\TaxDTO;

class Tax
{
    public function __construct(
        protected TaxDTO $dto,
        protected ClinikoClient $client
    ) {}

    public static function find(string $id, ClinikoClient $client): ?self
    {
        $data = $client->get("taxes/{$id}");
        return new self(TaxDTO::fromArray($data), $client);
    }

    /**
     * @return Tax[]
     */
    public static function all(ClinikoClient $client): array
    {
        $response = $client->get("taxes");

        $items = [];

        foreach ($response['taxes'] ?? [] as $item) {
            $items[] = new self(TaxDTO::fromArray($item), $client);
        }

        return $items;
    }

    public static function buildDTO(array $data): TaxDTO
    {
        return TaxDTO::fromArray($data);
    }

    // Getters

    public function getId(): string { return $this->dto->id; }
    public function getName(): string { return $this->dto->name; }
    public function getRate(): float { return $this->dto->rate; }
    public function getAmount(): float { return $this->dto->amount; }
    public function getCreatedAt(): string { return $this->dto->createdAt; }
    public function getUpdatedAt(): string { return $this->dto->updatedAt; }
}
