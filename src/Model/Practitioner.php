<?php
namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\DTO\PractitionerDTO;
use App\Client\Cliniko\Client;

if (!defined('ABSPATH')) exit;

class Practitioner
{
    public function __construct(
        protected PractitionerDTO $dto,
        protected ApiClientInterface $client
    ) {}

    public static function find(string $id, ApiClientInterface $client): ?self
    {
        $response = $client->get("practitioners/{$id}");

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PractitionerDTO::fromArray($response->data), $client);
    }

    public static function findFromUrl(string $url, ApiClientInterface $client): ?self
    {
        $response = $client->get($url);

        if (!$response->isSuccessful()) {
            return null;
        }

        return new self(PractitionerDTO::fromArray($response->data), $client);
    }

    public function getId(): string
    {
        return $this->dto->id;
    }

    public function getFullName(): string
    {
        return "{$this->dto->firstName} {$this->dto->lastName}";
    }

    public function getDisplayName(): string
    {
        return $this->dto->displayName;
    }

    public function getDesignation(): ?string
    {
        return $this->dto->designation;
    }

    public function getDescription(): ?string
    {
        return $this->dto->description;
    }

    public function getTitle(): ?string
    {
        return $this->dto->title;
    }

    public function isActive(): bool
    {
        return $this->dto->active;
    }

    public function isVisibleOnline(): bool
    {
        return $this->dto->showInOnlineBookings;
    }

    public function getCreatedAt(): string
    {
        return $this->dto->createdAt;
    }

    public function getUpdatedAt(): string
    {
        return $this->dto->updatedAt;
    }

    public function getDefaultAppointmentType(): ?AppointmentType
    {
        if (!$this->dto->defaultAppointmentTypeUrl) {
            return null;
        }

        return AppointmentType::findFromUrl($this->dto->defaultAppointmentTypeUrl, $this->client);
    }
}
