<?php
namespace App\DTO;

class CreatePatientCaseDTO
{
    public string $issueDate;
    public string $name;
    public string $notes;

    public string $patientId;
   

    public function toArray(): array
    {
        return [
            'issue_date' => $this->issueDate,
            'name' => $this->name,
            'notes' => $this->notes,
            'patient_id' => $this->patientId
        ];
    }
}
