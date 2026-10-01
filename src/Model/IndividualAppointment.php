<?php
namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\IndividualAppointmentDTO;
use App\Exception\ApiException;

if (!defined('ABSPATH'))
    exit;

class IndividualAppointment extends AbstractModel
{
        protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }
    public function getStartsAt(): string
    {
        return $this->dto->startsAt;
    }

    public function getEndsAt(): string
    {
        return $this->dto->endsAt;
    }


    public function getTelehealthUrl(): ?string
    {
        return $this->dto->telehealthUrl;
    }

    public function getNotes(): ?string
    {
        return $this->dto->notes;
    }

    public function getPatientId(): ?string
    {
        return $this->linkedResourceId($this->dto->patientUrl);
    }

    public function getAppointmentTypeId(): ?string
    {
        return $this->linkedResourceId($this->dto->appointmentTypeUrl);
    }

    public function getPractitionerUrl(): ?string
    {
        return $this->dto->practitionerUrl;
    }

    private function linkedResourceId(?string $url): ?string
    {
        $path = $url ? parse_url($url, PHP_URL_PATH) : null;
        if (!is_string($path) || $path === '') {
            return null;
        }

        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        return $parts === [] ? null : (string) end($parts);
    }

}
