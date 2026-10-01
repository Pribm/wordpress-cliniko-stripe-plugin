<?php

namespace App\DTO;

final class PublicSettingsAccountDTO
{
    public function __construct(
        public string $country = '',
        public string $countryCode = '',
        public string $currencySymbol = '',
        public bool $hasTimeTravelers = false,
        public string $id = '',
        public int $invoiceCalculationMethod = 0,
        public string $invoiceCalculationMethodDescription = '',
        public string $name = '',
        public string $subdomain = '',
        public bool $timeZoneSupport = false
    ) {}

    public static function fromArray(?array $data): ?self
    {
        if ($data === null) return null;
        return new self(
            (string) ($data['country'] ?? ''),
            (string) ($data['country_code'] ?? ''),
            (string) ($data['currency_symbol'] ?? ''),
            (bool) ($data['has_time_travelers'] ?? false),
            (string) ($data['id'] ?? ''),
            (int) ($data['invoice_calculation_method'] ?? 0),
            (string) ($data['invoice_calculation_method_description'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['subdomain'] ?? ''),
            (bool) ($data['time_zone_support'] ?? false)
        );
    }
}
