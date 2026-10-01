<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\PublicSettingsDTO;
use App\Exception\ApiException;

if (!defined('ABSPATH')) exit;

final class PublicSettings extends AbstractModel
{
    protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    public function getPublicSettingsDTO(): ?PublicSettingsDTO
    {
        return $this->getDTO() instanceof PublicSettingsDTO ? $this->getDTO() : null;
    }

    public static function fetch(ApiClientInterface $client, bool $throwOnError = false): ?self
    {
        $response = $client->get('settings/public');

        if (!$response->isSuccessful()) {
            if ($throwOnError) {
                throw new ApiException('Failed to fetch Cliniko public settings.', [
                    'error' => $response->error,
                    'status_code' => $response->statusCode,
                    'response_data' => $response->data,
                ]);
            }
            return null;
        }

        return new self(
            PublicSettingsDTO::fromArray(is_array($response->data) ? $response->data : []),
            $client
        );
    }
}
