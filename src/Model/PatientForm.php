<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;

if (!defined('ABSPATH')) exit;

class PatientForm extends AbstractModel
{
    protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    public function getCreatedAt(): ?string
    {
        return $this->dto->created_at ?? null;
    }

    public function getArchivedAt(): ?string
    {
        return $this->dto->archived_at ?? null;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->dto->updated_at ?? null;
    }

    public function getCompletedAt(): ?string
    {
        return $this->dto->completed_at ?? null;
    }
}
