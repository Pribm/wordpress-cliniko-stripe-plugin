<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
if (!defined('ABSPATH')) exit;
use App\Core\Framework\AbstractModel;

class PatientCase extends AbstractModel{
        protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }
}