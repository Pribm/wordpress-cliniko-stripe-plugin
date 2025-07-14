<?php
namespace App\Model;

use App\Client\Cliniko\Client;
use App\Client\ClientResponse;
use App\Contracts\ApiClientInterface;
use App\DTO\BillableItemDTO;

if (!defined('ABSPATH')) exit;

class BillableItem
{
    protected ?Tax $tax = null;

    public function __construct(
        protected BillableItemDTO $dto,
        protected ApiClientInterface $client
    ) {}

    public static function find(string $id, ApiClientInterface $client): ?self
    {
        $response = $client->get("billable_items/{$id}");

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(BillableItemDTO::fromArray($response->data), $client);
    }

    /**
     * @return BillableItem[]
     */
    public static function all(ApiClientInterface $client): array
    {
        $response = $client->get("billable_items");

        if (!$response->isSuccessful()) {
            return [];
        }

        $items = [];

        foreach ($response->data['billable_items'] ?? [] as $item) {
            $items[] = new self(BillableItemDTO::fromArray($item), $client);
        }

        return $items;
    }

    public static function buildDTO(array $data): BillableItemDTO
    {
        return BillableItemDTO::fromArray($data);
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

    public function getItemCode(): string
    {
        return $this->dto->itemCode;
    }

    public function getItemType(): string
    {
        return $this->dto->itemType;
    }

    public function getPrice(): float
    {
        return $this->dto->price;
    }

    public function getPriceInCents(): int
    {
        return (int) round($this->dto->price * 100);
    }

    public function isArchived(): bool
    {
        return $this->dto->archivedAt !== null;
    }

    public function getTax(): ?Tax
    {
        if (!$this->dto->taxUrl) {
            return null;
        }

        if ($this->tax !== null) {
            return $this->tax;
        }

        $response = $this->client->get($this->dto->taxUrl);

        if (!$response->isSuccessful()) {
            return null;
        }

        $this->tax = new Tax(Tax::buildDTO($response->data), $this->client);

        return $this->tax;
    }
}
