<?php

namespace App\Model;

use App\Client\Cliniko\Client;
use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\CommunicationDTO;
use App\DTO\CommunicationsDTO;
use App\DTO\CreateCommunicationDTO;
use App\Exception\ApiException;
use App\Support\Auth;

if (!defined('ABSPATH')) exit;

/** Patient-scoped access to Cliniko communication records and received memos. */
final class Communication extends AbstractModel
{
    protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    public function getCommunicationDTO(): ?CommunicationDTO
    {
        return $this->dto instanceof CommunicationDTO ? $this->dto : null;
    }

    public function getContent(): string
    {
        $dto = $this->getCommunicationDTO();
        return $dto === null ? '' : $dto->content;
    }
    public function getDirectionCode(): ?int { return $this->getCommunicationDTO()?->directionCode; }
    public function getCreatedAt(): ?string { return $this->getCommunicationDTO()?->createdAt; }
    public function getPatientUrl(): ?string { return $this->getCommunicationDTO()?->patient?->self; }

    /** @return array{items:list<self>,pagination:CommunicationsDTO} */
    public static function forAuthenticatedPatient(?ApiClientInterface $client = null, int $page = 1, int $perPage = 20, ?int $typeCode = null): array
    {
        $patient = Auth::user();
        if ($patient === null || (string) $patient->getId() === '') return ['items' => [], 'pagination' => new CommunicationsDTO()];

        $patientId = (string) $patient->getId();
        $query = http_build_query([
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage)),
            'sort' => 'created_at:desc',
        ]);
        $filters = ['patient_id:=' . $patientId];
        if (in_array($typeCode, [1, 2, 3, 4], true)) $filters[] = 'type_code:=' . $typeCode;
        foreach ($filters as $filter) $query .= '&q%5B%5D=' . rawurlencode($filter);
        $api = $client ?: (function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 15)
            : Client::getInstance());
        $response = $api->get('communications?' . $query);
        if (!$response->isSuccessful() || !is_array($response->data)) {
            throw new ApiException('Cliniko could not load communications.', ['error' => $response->error, 'status_code' => $response->statusCode]);
        }

        $pagination = CommunicationsDTO::fromArray($response->data);
        $items = [];
        foreach ($pagination->communications as $dto) {
            $communication = new self($dto, $api);
            if (self::belongsToPatient($communication, $patientId)) $items[] = $communication;
        }
        return ['items' => $items, 'pagination' => $pagination];
    }

    public static function createReceivedMemoForAuthenticatedPatient(string $content, string $from, string $to, int $typeCode = 4, ?ApiClientInterface $client = null): ?self
    {
        $patient = Auth::user();
        if ($patient === null || (string) $patient->getId() === '') return null;
        $api = $client ?: (function_exists('cliniko_dashboard_client')
            ? cliniko_dashboard_client(null, 15)
            : Client::getInstance());
        $payload = new CreateCommunicationDTO((string) $patient->getId(), $content, 2, $from, $to, in_array($typeCode, [1, 2, 3, 4], true) ? $typeCode : 4);
        return self::create($payload, $api);
    }

    /** @return list<self> */
    public static function allForAuthenticatedPatient(?ApiClientInterface $client = null): array
    {
        $items = [];
        $page = 1;
        do {
            $result = self::forAuthenticatedPatient($client, $page, 100);
            $items = array_merge($items, $result['items']);
            $totalEntries = max(0, (int) $result['pagination']->totalEntries);
            $page++;
        } while (($page - 1) * 100 < $totalEntries && $page <= 1000);

        return $items;
    }

    private static function belongsToPatient(self $communication, string $patientId): bool
    {
        $path = (string) parse_url((string) $communication->getPatientUrl(), PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        return $parts !== [] && hash_equals($patientId, (string) end($parts));
    }
}
