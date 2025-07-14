<?php
namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\DTO\BusinessDTO;
use App\DTO\PractitionerDTO;
use App\DTO\IndividualAppointmentDTO;
use App\Client\Cliniko\Client;

if (!defined('ABSPATH')) exit;

class Business
{
    public function __construct(
        protected BusinessDTO $dto,
        protected ApiClientInterface $client
    ) {}

    public static function find(string $id, ApiClientInterface $client): ?self
    {
        $response = $client->get("businesses/{$id}");

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(BusinessDTO::fromArray($response->data), $client);
    }

    public static function findFromUrl(string $url, ApiClientInterface $client): ?self
    {
        $response = $client->get($url);

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(BusinessDTO::fromArray($response->data), $client);
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

    public function getPractitioners(): array
    {
        if (!$this->dto->practitionersUrl) {
            return [];
        }

        $response = $this->client->get($this->dto->practitionersUrl);

        if (!$response->isSuccessful()) {
            return [];
        }

        return array_map(
            fn($item) => new Practitioner(PractitionerDTO::fromArray($item), $this->client),
            $response->data['practitioners'] ?? []
        );
    }

    public function getAppointments(): array
    {
        if (!$this->dto->appointmentsUrl) {
            return [];
        }

        $response = $this->client->get($this->dto->appointmentsUrl);

        if (!$response->isSuccessful()) {
            return [];
        }

        return array_map(
            fn($item) => new IndividualAppointment(IndividualAppointmentDTO::fromArray($item), $this->client),
            $response->data['appointments'] ?? []
        );
    }
}
