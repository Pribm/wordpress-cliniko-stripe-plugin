<?php
namespace App\Model;
if (!defined('ABSPATH')) exit;
use App\Client\ClinikoClient;
use App\DTO\AppointmentTypeBillableItemDTO;
use App\DTO\AppointmentTypeDTO;
use App\DTO\PractitionerDTO;

class AppointmentType
{
    protected AppointmentTypeDTO $dto;
    protected ?BillableItem $billableItem = null;
    protected ?array $appointmentTypeBillableItems = null;

    protected ?array $practitioners = null;

    protected ClinikoClient $client;

    public function __construct(AppointmentTypeDTO $dto)
    {
        $this->dto = $dto;
        $this->client = ClinikoClient::getInstance();
    }

    public static function find(string $id, ClinikoClient $client): ?self
    {
        $data = $client->get("appointment_types/{$id}");

        if (!$data)
            return null;

        return new self(AppointmentTypeDTO::fromArray($data));
    }

    /**
     * @return AppointmentType[]
     */
    public static function all(ClinikoClient $client)
    {
        $response = $client->get("appointment_types");

        $items = [];

        foreach ($response['appointment_types'] ?? [] as $data) {
            $items[] = new self(AppointmentTypeDTO::fromArray($data));
        }

        return $items;
    }

    public static function create(array $data, ClinikoClient $client): self
    {
        $created = $client->post('appointment_types', $data);

        return new self(AppointmentTypeDTO::fromArray($created));
    }

    public function getDTO(){
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
        if (!$this->dto->billableItemUrl)
            return null;
        if ($this->billableItem)
            return $this->billableItem;

        $data = $this->client->get($this->dto->billableItemUrl);
        $this->billableItem = new BillableItem(
            BillableItem::buildDTO($data),
            $this->client
        );

        return $this->billableItem;
    }

    /**
     * @return AppointmentTypeBillableItem[]
     */
    public function getAppointmentTypeBillableItems()
    {
        if (!$this->dto->billableItemsUrl) {
            return [];
        }

        $response = $this->client->get($this->dto->billableItemsUrl);

        $this->appointmentTypeBillableItems = array_map(
            fn($item) => new AppointmentTypeBillableItem(
                AppointmentTypeBillableItemDTO::fromArray($item),
                $this->client
            ),
            $response['appointment_type_billable_items'] ?? []
        );

        return $this->appointmentTypeBillableItems;
    }

    /**
     * @return Practitioner[]
     */
    public function getPractitioners(){
        if (!$this->dto->practitionersUrl) {
            return [];
        }

        $response = $this->client->get($this->dto->practitionersUrl);

        $this->practitioners = array_map(
            fn($item) => new Practitioner(
                PractitionerDTO::fromArray($item),
                $this->client
            ),
            $response['practitioners'] ?? []
        );

        return $this->practitioners;
    }

    private function sumPrices($carry, $item): int
    {
        $carry += $item;
        return $carry;
    }

    public function getBillableItemsFinalPrice(): int
    {
        $billableItems = $this->getAppointmentTypeBillableItems();

        $finalPrice = array_reduce($billableItems, function ($carry, $item) {
            return $carry + $item->getBillableItem()->getPriceInCents();
            ;
        }, 0);

        return $finalPrice;
    }

        public static function findFromUrl(string $url, ClinikoClient $client): ?self
        {
            $data = $client->get($url);
            return new self(AppointmentTypeDTO::fromArray($data));
        }
}
