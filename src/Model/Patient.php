<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\CreatePatientDTO;
use App\DTO\PatientDTO;
use App\Exception\ApiException;

if (!defined('ABSPATH')) exit;

class Patient extends AbstractModel
{

        protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    public static function query(string $query, ApiClientInterface $client, bool $throwOnError = false): ?self
    {
        $instance = new static(null, $client);

        $response = $client->get("{$instance->getResourcePath()}{$query}");

        if (!$response->isSuccessful()) {
            if ($throwOnError) {
                throw new ApiException("Failed to query resources at {$instance->getResourcePath()}{$query}.", [
                    'error' => $response->error,
                ]);
            }
            return null;
        }

        $patients = $response->data['patients'] ?? [];

        if (empty($patients)) {
            return null;
        }

        $dtoClass = $instance->getDtoClass();
        return new static($dtoClass::fromArray($patients[0]), $client);
    }

    // Getters
    public function getFullName(): string
    {
        return trim("{$this->dto->firstName} {$this->dto->lastName}");
    }

    public function getEmail(): ?string
    {
        return $this->dto->email;
    }


}