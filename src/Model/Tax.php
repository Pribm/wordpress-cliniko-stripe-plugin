<?php
namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;

if (!defined('ABSPATH'))
    exit;

class Tax extends AbstractModel
{
        protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }
}