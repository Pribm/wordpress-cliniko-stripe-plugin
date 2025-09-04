<?php
namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\PractitionerDTO;
use App\Client\Cliniko\Client;

if (!defined('ABSPATH')) exit;

class Practitioner extends AbstractModel
{
        protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }
}
