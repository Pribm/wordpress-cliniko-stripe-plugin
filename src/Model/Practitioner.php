<?php
namespace App\Model;

use App\DTO\PractitionerDTO;
use App\Client\ClinikoClient;

class Practitioner
{
    public function __construct(
        protected PractitionerDTO $dto,
        protected ClinikoClient $client
    ) {}

    public static function find(string $id, ClinikoClient $client): ?self
    {
        $data = $client->get("practitioners/{$id}");
        return new self(PractitionerDTO::fromArray($data), $client);
    }

    public static function findFromUrl(string $url, ClinikoClient $client): ?self
    {
        $data = $client->get($url);
        return new self(PractitionerDTO::fromArray($data), $client);
    }

    public function getId(): string { return $this->dto->id; }
    public function getFullName(): string { return "{$this->dto->firstName} {$this->dto->lastName}"; }
    public function getDisplayName(): string { return $this->dto->displayName; }
    public function getDesignation(): ?string { return $this->dto->designation; }
    public function getDescription(): ?string { return $this->dto->description; }
    public function getTitle(): ?string { return $this->dto->title; }
    public function isActive(): bool { return $this->dto->active; }
    public function isVisibleOnline(): bool { return $this->dto->showInOnlineBookings; }
    public function getCreatedAt(): string { return $this->dto->createdAt; }
    public function getUpdatedAt(): string { return $this->dto->updatedAt; }

    // Lazy loading futuro (relacionamentos)
    public function getDefaultAppointmentType(): ?AppointmentType
    {
        if (!$this->dto->defaultAppointmentTypeUrl) return null;
        return AppointmentType::findFromUrl($this->dto->defaultAppointmentTypeUrl, $this->client);
    }
}
