<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\BusinessDTO;
use App\DTO\PractitionerDTO;
use App\DTO\IndividualAppointmentDTO;

if (!defined('ABSPATH')) exit;

class Business extends AbstractModel
{
        protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }
}