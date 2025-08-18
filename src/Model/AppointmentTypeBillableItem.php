<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\AppointmentTypeBillableItemDTO;
use App\DTO\BillableItemDTO;

if (!defined('ABSPATH')) exit;

class AppointmentTypeBillableItem extends AbstractModel
{
    protected ?BillableItem $billableItem = null;

       protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }
    /**
     * Get the associated billable item.
     *
     * @return BillableItem|null
     */
    public function getBillableItem(): ?BillableItem
    {
        if (!$this->dto->billableItemUrl) {
            return null;
        }

        if ($this->billableItem) {
            return $this->billableItem;
        }

        $data = $this->safeGetLinkedEntity($this->dto->billableItemUrl);

        if (empty($data)) {
            return null;
        }

        $this->billableItem = new BillableItem(
            BillableItemDTO::fromArray($data),
            $this->client
        );

        return $this->billableItem;
    }
}