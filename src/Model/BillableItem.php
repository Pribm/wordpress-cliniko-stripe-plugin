<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\BillableItemDTO;

if (!defined('ABSPATH')) exit;

class BillableItem extends AbstractModel
{

        protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    /**
     * Get the price of the billable item in cents.
     *
     * @return int
     */
    public function getPriceInCents(): int
    {
        return (int) round($this->dto->price * 100);
    }
}