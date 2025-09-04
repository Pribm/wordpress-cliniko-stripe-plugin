<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\AppointmentTypeDTO;
use App\DTO\AppointmentTypeBillableItemDTO;
use App\DTO\PractitionerDTO;

if (!defined('ABSPATH')) exit;

class AppointmentType extends AbstractModel
{
    protected ?BillableItem $billableItem = null;
    protected ?array $appointmentTypeBillableItems = null;
    protected ?array $practitioners = null;

        protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    public function getDescription(): string
    {
        return $this->dto->description;
    }

    public function getDurationInMinutes(): int
    {
        return $this->dto->durationInMinutes;
    }

    /**
     * @return AppointmentTypeBillableItem[]
     */
    public function getAppointmentTypeBillableItems(): array
    {
        if (!$this->dto->billableItemsUrl) {
            return [];
        }

        $data = $this->safeGetLinkedEntity($this->dto->billableItemsUrl);

        if (empty($data)) {
            return [];
        }

        $this->appointmentTypeBillableItems = array_map(
            fn($item) => new AppointmentTypeBillableItem(
                AppointmentTypeBillableItemDTO::fromArray($item),
                $this->client
            ),
            $data['appointment_type_billable_items'] ?? []
        );

        return $this->appointmentTypeBillableItems;
    }

    /**
     * @return Practitioner[]
     */
    public function getPractitioners(): array
    {
        if (!$this->dto->practitionersUrl) {
            return [];
        }

        $data = $this->safeGetLinkedEntity($this->dto->practitionersUrl);

        if (empty($data)) {
            return [];
        }

        $this->practitioners = array_map(
            fn($item) => new Practitioner(
                PractitionerDTO::fromArray($item),
                $this->client
            ),
            $data['practitioners'] ?? []
        );

        return $this->practitioners;
    }

    public function getBillableItemsFinalPrice(): int
    {
        $billableItems = $this->getAppointmentTypeBillableItems();

        if (empty($billableItems)) {
            return 0;
        }

        return array_reduce($billableItems, function ($carry, AppointmentTypeBillableItem $item) {
            if ($item->getBillableItem() === null) {
                return $carry;
            }
            return $carry + $item->getBillableItem()->getPriceInCents();
        }, 0);
    }

    public function requiresPayment(): bool
    {
        return $this->getBillableItemsFinalPrice() > 0;
    }
}