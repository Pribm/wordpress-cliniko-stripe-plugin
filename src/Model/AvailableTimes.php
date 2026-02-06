<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\AvailableTimesDTO;

if (!defined('ABSPATH')) exit;

class AvailableTimes extends AbstractModel
{
    protected ?self $previousPage = null;
    protected ?self $nextPage = null;

    protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    /**
     * IMPORTANT:
     * This endpoint list key is "available_times" and the resource name isn't a normal plural.
     */
    protected static function getResourcePath(): string
    {
        return 'available_times';
    }

    protected static function getListKey(): string
    {
        return 'available_times';
    }

    // -----------------------------
    // Finders (this endpoint is nested, so we provide a dedicated finder)
    // -----------------------------

    /**
     * Fetch available times for a practitioner + appointment type.
     *
     * @return static|null
     */
    public static function findForPractitionerAppointmentType(
        string $businessId,
        string $practitionerId,
        string $appointmentTypeId,
        string $from, // YYYY-MM-DD
        string $to,   // YYYY-MM-DD
        ApiClientInterface $client,
        ?int $page = null,
        ?int $perPage = null,
        bool $throwOnError = false
    ): ?static {
        $basePath = "businesses/{$businessId}/practitioners/{$practitionerId}/appointment_types/{$appointmentTypeId}/available_times";

        $params = [
            'from' => $from,
            'to'   => $to,
        ];

        if ($page !== null) {
            $params['page'] = (string) $page;
        }
        if ($perPage !== null) {
            $params['per_page'] = (string) $perPage;
        }

        $url = $basePath . '?' . http_build_query($params);

        // Reuse your existing plumbing (this will hydrate via DTO class)
        return static::findFromUrl($url, $client, $throwOnError);
    }

    // -----------------------------
    // DTO-friendly getters
    // -----------------------------

    /**
     * @return list<\App\DTO\AvailableTimeDTO>
     */
    public function getAvailableTimes(): array
    {
        /** @var AvailableTimesDTO|null $dto */
        $dto = $this->dto instanceof AvailableTimesDTO ? $this->dto : null;
        return $dto ? $dto->availableTimes : [];
    }

    public function getTotalEntries(): int
    {
        /** @var AvailableTimesDTO|null $dto */
        $dto = $this->dto instanceof AvailableTimesDTO ? $this->dto : null;
        return $dto ? $dto->totalEntries : 0;
    }

    public function getSelfUrl(): ?string
    {
        /** @var AvailableTimesDTO|null $dto */
        $dto = $this->dto instanceof AvailableTimesDTO ? $this->dto : null;
        return $dto?->selfUrl;
    }

    public function getPreviousUrl(): ?string
    {
        /** @var AvailableTimesDTO|null $dto */
        $dto = $this->dto instanceof AvailableTimesDTO ? $this->dto : null;
        return $dto?->previousUrl;
    }

    public function getNextUrl(): ?string
    {
        /** @var AvailableTimesDTO|null $dto */
        $dto = $this->dto instanceof AvailableTimesDTO ? $this->dto : null;
        return $dto?->nextUrl;
    }

    public function hasPreviousPage(): bool
    {
        return (bool) $this->getPreviousUrl();
    }

    public function hasNextPage(): bool
    {
        return (bool) $this->getNextUrl();
    }

    public function getPreviousPage(): ?self
    {
        $url = $this->getPreviousUrl();
        if (!$url) return null;
        if ($this->previousPage) return $this->previousPage;

        $data = $this->safeGetLinkedEntity($url);
        if (empty($data)) return null;

        $this->previousPage = new self(
            AvailableTimesDTO::fromArray($data),
            $this->client
        );

        return $this->previousPage;
    }

    public function getNextPage(): ?self
    {
        $url = $this->getNextUrl();
        if (!$url) return null;
        if ($this->nextPage) return $this->nextPage;

        $data = $this->safeGetLinkedEntity($url);
        if (empty($data)) return null;

        $this->nextPage = new self(
            AvailableTimesDTO::fromArray($data),
            $this->client
        );

        return $this->nextPage;
    }

    /**
     * Convenience: just the ISO strings (UTC).
     *
     * @return list<string>
     */
    public function getAppointmentStartStrings(): array
    {
        $out = [];
        foreach ($this->getAvailableTimes() as $slot) {
            if (!empty($slot->appointmentStart)) {
                $out[] = $slot->appointmentStart;
            }
        }
        return $out;
    }

    /**
     * Convenience: DateTimeImmutable objects, optionally in a given timezone.
     *
     * @return list<\DateTimeImmutable>
     */
    public function getAppointmentStartsAsDateTimes(?string $timezone = null): array
    {
        $tz = $timezone ? new \DateTimeZone($timezone) : new \DateTimeZone('UTC');
        $out = [];

        foreach ($this->getAvailableTimes() as $slot) {
            if (empty($slot->appointmentStart)) continue;

            try {
                $dt = new \DateTimeImmutable($slot->appointmentStart); // parses Z as UTC
                if ($timezone) {
                    $dt = $dt->setTimezone($tz);
                }
                $out[] = $dt;
            } catch (\Throwable $e) {
                // ignore invalid
            }
        }

        return $out;
    }
}
