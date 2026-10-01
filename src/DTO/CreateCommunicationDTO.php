<?php

namespace App\DTO;

if (!defined('ABSPATH')) exit;

/** A Cliniko communication API create request. The API creates memo records only. */
final class CreateCommunicationDTO
{
    public function __construct(public string $patientId, public string $content, public int $directionCode = 2, public string $from = '', public string $to = '', public int $typeCode = 4, public bool $confidential = false) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['patient_id' => $this->patientId, 'category_code' => 12, 'content' => $this->content, 'direction_code' => $this->directionCode, 'from' => $this->from, 'to' => $this->to, 'type_code' => $this->typeCode, 'confidential' => $this->confidential];
    }
}
