<?php

namespace App\Model;

use App\Client\Cliniko\Client;
use App\Client\ClientResponse;
use App\Contracts\ApiClientInterface;
use App\DTO\AppointmentTypeBillableItemDTO;
use App\DTO\BillableItemDTO;

if (!defined('ABSPATH')) exit;

class AppointmentTypeBillableItem
{
    protected ?BillableItem $billableItem = null;

    public function __construct(
        protected AppointmentTypeBillableItemDTO $dto,
        protected ApiClientInterface $client
    ) {}

    public static function buildDTO(array $data): AppointmentTypeBillableItemDTO
    {
        return AppointmentTypeBillableItemDTO::fromArray($data);
    }

    public function getId(): string
    {
        return $this->dto->id;
    }

    public function getQuantity(): float
    {
        return $this->dto->quantity;
    }

    public function getDiscountedAmount(): ?float
    {
        return $this->dto->discountedAmount;
    }

    public function getDiscountPercentage(): ?float
    {
        return $this->dto->discountPercentage;
    }

    public function getBillableItem(): ?BillableItem
    {
        if (!$this->dto->billableItemUrl) {
            return null;
        }

        if ($this->billableItem) {
            return $this->billableItem;
        }

        $response = $this->client->get($this->dto->billableItemUrl);

        if (!$response->isSuccessful()) {
            return null;
        }

        $this->billableItem = new BillableItem(
            BillableItemDTO::fromArray($response->data),
            $this->client
        );

        return $this->billableItem;
    }
}
