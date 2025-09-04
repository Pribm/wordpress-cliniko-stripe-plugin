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

}
