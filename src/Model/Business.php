<?php
namespace App\Model;

use App\DTO\BusinessDTO;
use App\Client\ClinikoClient;
use App\DTO\IndividualAppointmentDTO;
use App\DTO\PractitionerDTO;

class Business
{
    public function __construct(
        protected BusinessDTO $dto,
        protected ClinikoClient $client
    ) {}

    public static function find(string $id, ClinikoClient $client): ?self
    {
        $data = $client->get("businesses/{$id}");
        return new self(BusinessDTO::fromArray($data), $client);
    }

    public static function findFromUrl(string $url, ClinikoClient $client): ?self
    {
        $data = $client->get($url);
        return new self(BusinessDTO::fromArray($data), $client);
    }

    public function getId(): string { return $this->dto->id; }
    public function getName(): string { return $this->dto->name; }
    public function getDisplayName(): string { return $this->dto->displayName; }
    public function getLabel(): string { return $this->dto->label; }
    public function getEmail(): ?string { return $this->dto->emailReplyTo; }
    public function getContact(): ?string { return $this->dto->contactInformation; }
    public function getWebsite(): ?string { return $this->dto->websiteAddress; }
    public function getAddress(): ?string { return $this->dto->address; }
    public function getCountry(): ?string { return $this->dto->country; }
    public function getTimeZone(): ?string { return $this->dto->timeZoneIdentifier; }
    public function getAppointmentTypeIds(): array { return $this->dto->appointmentTypeIds; }
    public function isOnline(): bool { return $this->dto->showInOnlineBookings; }

    // Lazy loading futuro
    public function getPractitioners(): array
    {
        if (!$this->dto->practitionersUrl) return [];
        $response = $this->client->get($this->dto->practitionersUrl);

        return array_map(fn($item) =>
            new Practitioner(PractitionerDTO::fromArray($item), $this->client),
            $response['practitioners'] ?? []
        );
    }

    public function getAppointments(): array
    {
        if (!$this->dto->appointmentsUrl) return [];
        $response = $this->client->get($this->dto->appointmentsUrl);

        return array_map(fn($item) =>
            new IndividualAppointment(IndividualAppointmentDTO::fromArray($item), $this->client),
            $response['appointments'] ?? []
        );
    }
}
