<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\AppointmentTypeDTO;
use App\DTO\AppointmentTypeBillableItemDTO;
use App\DTO\PractitionerDTO;
use App\Exception\ApiException;

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

    public static function delete(string $id, ApiClientInterface $client): bool
    {
        throw new \LogicException('Appointment types can only be archived or deleted in Cliniko.');
    }

    public function getDescription(): string
    {
        return $this->dto->description;
    }

    public function getDurationInMinutes(): int
    {
        return $this->dto->durationInMinutes;
    }

    public function getCategory(): string
    {
        return (string) $this->dto->category;
    }

    public function isArchived(): bool
    {
        return !empty($this->dto->archivedAt);
    }

    /**
     * @return AppointmentTypeBillableItem[]
     */
    public function getAppointmentTypeBillableItems(bool $throwOnError = false): array
    {
        if ($this->appointmentTypeBillableItems !== null && !$throwOnError) {
            return $this->appointmentTypeBillableItems;
        }

        if (!$this->dto->billableItemsUrl) {
            $this->appointmentTypeBillableItems = [];
            return $this->appointmentTypeBillableItems;
        }

        $data = $this->safeGetLinkedEntity($this->dto->billableItemsUrl, $throwOnError);
        if ($throwOnError && !is_array($data['appointment_type_billable_items'] ?? null)) {
            throw new ApiException('Cliniko returned an invalid appointment price list.');
        }

        if (empty($data)) {
            $this->appointmentTypeBillableItems = [];
            return $this->appointmentTypeBillableItems;
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

    public function getBillableItemsFinalPrice(bool $throwOnError = false): int
    {
        $price = 0;
        foreach ($this->getAppointmentTypeBillableItems($throwOnError) as $item) {
            $billable = $item->getBillableItem($throwOnError);
            if ($billable !== null) {
                $price += $billable->getPriceInCents();
            }
        }
        return $price;
    }

    public function requiresPayment(): bool
    {
        return $this->getBillableItemsFinalPrice() > 0;
    }
}
