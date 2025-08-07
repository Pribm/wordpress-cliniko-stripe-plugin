<?php
namespace App\Model;

use App\Contracts\ApiClientInterface;
if (!defined('ABSPATH')) exit;
use App\Client\Cliniko\Client;
use App\DTO\AppointmentTypeBillableItemDTO;
use App\DTO\AppointmentTypeDTO;
use App\DTO\PractitionerDTO;

class AppointmentType
{
    protected AppointmentTypeDTO $dto;
    protected ?BillableItem $billableItem = null;
    protected ?array $appointmentTypeBillableItems = null;
    protected ?array $practitioners = null;
    protected ApiClientInterface $client;

    public function __construct(AppointmentTypeDTO $dto)
    {
        $this->dto = $dto;
        $this->client = Client::getInstance();
    }

    public static function find(string $id, ApiClientInterface $client): ?self
    {
        $response = $client->get("appointment_types/{$id}");

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(AppointmentTypeDTO::fromArray($response->data));
    }

    /**
     * @return AppointmentType[]
     */
    public static function all(ApiClientInterface $client): array
    {
        $response = $client->get("appointment_types");

        if (!$response->isSuccessful()) {
            return [];
        }

        $items = [];
        foreach ($response->data['appointment_types'] ?? [] as $data) {
            $items[] = new self(AppointmentTypeDTO::fromArray($data));
        }

        return $items;
    }

    public static function create(array $data, ApiClientInterface $client): ?self
    {
        $response = $client->post('appointment_types', $data);

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(AppointmentTypeDTO::fromArray($response->data));
    }

    public function getDTO(): AppointmentTypeDTO
    {
        return $this->dto;
    }

    public function getId(): string
    {
        return $this->dto->id;
    }

    public function getName(): string
    {
        return $this->dto->name;
    }

    public function getDescription(): string
    {
        return $this->dto->description;
    }

    public function getDurationInMinutes(): int
    {
        return $this->dto->durationInMinutes;
    }

    public function isTelehealth(): bool
    {
        return $this->dto->telehealthEnabled;
    }

    public function isArchived(): bool
    {
        return !empty($this->dto->archivedAt);
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
            BillableItem::buildDTO($response->data),
            $this->client
        );

        return $this->billableItem;
    }

    /**
     * @return AppointmentTypeBillableItem[]
     */
    public function getAppointmentTypeBillableItems(): array
    {
        if (!$this->dto->billableItemsUrl) {
            return [];
        }

        $response = $this->client->get($this->dto->billableItemsUrl);

        if (!$response->isSuccessful()) {
            return [];
        }

        $this->appointmentTypeBillableItems = array_map(
            fn($item) => new AppointmentTypeBillableItem(
                AppointmentTypeBillableItemDTO::fromArray($item),
                $this->client
            ),
            $response->data['appointment_type_billable_items'] ?? []
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

        $response = $this->client->get($this->dto->practitionersUrl);

        if (!$response->isSuccessful()) {
            return [];
        }

        $this->practitioners = array_map(
            fn($item) => new Practitioner(
                PractitionerDTO::fromArray($item),
                $this->client
            ),
            $response->data['practitioners'] ?? []
        );

        return $this->practitioners;
    }

    public function getBillableItemsFinalPrice(): int
    {
        $billableItems = $this->getAppointmentTypeBillableItems();

        if (count($billableItems) === 0) return 0;

        return array_reduce($billableItems, function ($carry, $item) {
            return $carry + $item->getBillableItem()->getPriceInCents();
        }, 0);
    }

    public function requiresPayment(): bool
    {
        return $this->getBillableItemsFinalPrice() > 0;
    }

    public static function findFromUrl(string $url, ApiClientInterface $client): ?self
    {
        $response = $client->get($url);

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(AppointmentTypeDTO::fromArray($response->data));
    }
}

