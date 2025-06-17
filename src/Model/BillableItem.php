<?php
namespace App\Model;

use App\Client\ClinikoClient;
use App\DTO\BillableItemDTO;

class BillableItem
{
    protected ?Tax $tax = null;

    public function __construct(
        protected BillableItemDTO $dto,
        protected ClinikoClient $client
    ) {}

    public static function find(string $id, ClinikoClient $client): ?self
    {
        $data = $client->get("billable_items/{$id}");
        return new self(BillableItemDTO::fromArray($data), $client);
    }

    /**
     * @return BillableItem[]
     */
    public static function all(ClinikoClient $client): array
    {
        $response = $client->get("billable_items");

        $items = [];

        foreach ($response['billable_items'] ?? [] as $item) {
            $items[] = new self(BillableItemDTO::fromArray($item), $client);
        }

        return $items;
    }

    public static function buildDTO(array $data): BillableItemDTO
    {
        return BillableItemDTO::fromArray($data);
    }

    // Getters

    public function getId(): string { return $this->dto->id; }
    public function getName(): string { return $this->dto->name; }
    public function getItemCode(): string { return $this->dto->itemCode; }
    public function getItemType(): string { return $this->dto->itemType; }
    public function getPrice(): float { return $this->dto->price; }
    public function getPriceInCents(): int { return (int) round($this->dto->price * 100); }
    public function isArchived(): bool { return $this->dto->archivedAt !== null; }

    public function getTax(): ?Tax
    {
        if (!$this->dto->taxUrl) return null;
        if ($this->tax !== null) return $this->tax;

        $data = $this->client->get($this->dto->taxUrl);
        $this->tax = new Tax(Tax::buildDTO($data), $this->client);

        return $this->tax;
    }
}
