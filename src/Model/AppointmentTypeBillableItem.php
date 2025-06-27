<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;
use App\Client\ClinikoClient;
use App\DTO\AppointmentTypeBillableItemDTO;
use App\Client\ClinikoApiClient;
use App\DTO\BillableItemDTO;

class AppointmentTypeBillableItem
{
    protected ?BillableItem $billableItem = null;

    public function __construct(
        protected AppointmentTypeBillableItemDTO $dto,
        protected ClinikoClient $client
    ) {}

    public static function buildDTO(array $data): AppointmentTypeBillableItemDTO
    {
        return AppointmentTypeBillableItemDTO::fromArray($data);
    }

    public function getId(): string { return $this->dto->id; }

    public function getQuantity(): float { return $this->dto->quantity; }

    public function getDiscountedAmount(): ?float { return $this->dto->discountedAmount; }

    public function getDiscountPercentage(): ?float { return $this->dto->discountPercentage; }

    public function getBillableItem(): ?BillableItem
    {
        if (!$this->dto->billableItemUrl) return null;
        if ($this->billableItem) return $this->billableItem;

        $data = $this->client->get($this->dto->billableItemUrl);
        $this->billableItem = new BillableItem(BillableItemDTO::fromArray($data), $this->client);

        return $this->billableItem;
    }
}
