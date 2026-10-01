<?php

namespace App\DTO;

final class PatientPronounsDTO
{
    public function __construct(
        public string $accusative,
        public string $nominative,
        public string $predicativePossessive,
        public string $pronominalPossessive,
        public string $reflexive
    ) {}

    public function toArray(): array
    {
        return [
            'accusative' => $this->accusative,
            'nominative' => $this->nominative,
            'predicative_possessive' => $this->predicativePossessive,
            'pronominal_possessive' => $this->pronominalPossessive,
            'reflexive' => $this->reflexive,
        ];
    }
}
