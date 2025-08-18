<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;

if (!defined('ABSPATH')) exit;

class PatientFormTemplate extends AbstractModel
{


    protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    public function isRestrictedToPractitioner(): bool
    {
        return $this->dto->restrictedToPractitioner;
    }

    public function isEmailToPatientOnCompletion(): bool
    {
        return $this->dto->emailToPatientOnCompletion;
    }

    /**
     * @return \App\DTO\PatientFormTemplateSectionDTO[]
     */
    public function getSections()
    {
        return $this->dto->sections;
    }

    public function isArchived(): bool
    {
        return !empty($this->dto->archivedAt);
    }

    public function getCreatedAt()
    {
        return $this->dto->createdAt;
    }

    public function getUpdatedAt()
    {
        return $this->dto->updatedAt;
    }
}
