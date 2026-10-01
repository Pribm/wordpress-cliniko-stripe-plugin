<?php

namespace App\DTO;

if (!defined('ABSPATH')) {
    exit;
}

class TyroHealthTransactionPaymentDTO
{
    private const SUCCESS_STATUSES = [
        'success',
        'succeeded',
        'completed',
        'complete',
        'approved',
        'authorised',
        'authorized',
        'paid',
    ];

    public string $id;
    public ?string $status;
    public int $amount;
    public ?string $amountString;
    public ?string $paymentType;
    public ?string $gatewayRefId;
    public ?string $created;
    public ?string $responded;
    public ?string $statementDescriptor;
    public ?string $statusDescription;
    public ?string $origination;
    public ?bool $cardHolderAuthorisedPayment;

    public function __construct(array $data)
    {
        $this->id = (string) ($data['_id'] ?? $data['id'] ?? '');
        $this->status = $this->normalizeNullableString($data['status'] ?? null);
        $this->amount = $this->normalizeAmountCents($data['amount'] ?? $data['amountCharged'] ?? $data['amount_charged'] ?? null);
        $this->amountString = $this->normalizeNullableString($data['amountString'] ?? $data['amount_string'] ?? null);
        $this->paymentType = $this->normalizeNullableString($data['paymentType'] ?? $data['payment_type'] ?? null);
        $this->gatewayRefId = $this->normalizeNullableString($data['gatewayRefId'] ?? $data['gateway_ref_id'] ?? null);
        $this->created = $this->normalizeNullableString($data['created'] ?? null);
        $this->responded = $this->normalizeNullableString($data['responded'] ?? null);
        $this->statementDescriptor = $this->normalizeNullableString($data['statementDescriptor'] ?? $data['statement_descriptor'] ?? null);
        $this->statusDescription = $this->normalizeNullableString($data['statusDescription'] ?? $data['status_description'] ?? null);
        $this->origination = $this->normalizeNullableString($data['origination'] ?? null);
        $this->cardHolderAuthorisedPayment = array_key_exists('cardHolderAuthorisedPayment', $data)
            ? (bool) $data['cardHolderAuthorisedPayment']
            : null;
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function isSuccessful(): bool
    {
        return $this->isSuccessfulStatus($this->status);
    }

    public function getAmountCents(): int
    {
        return $this->amount;
    }

    private function isSuccessfulStatus(?string $status): bool
    {
        $normalized = strtolower(trim((string) $status));
        return $normalized !== '' && in_array($normalized, self::SUCCESS_STATUSES, true);
    }

    /**
     * @param mixed $amount
     */
    private function normalizeAmountCents($amount): int
    {
        if (is_int($amount)) {
            return $amount;
        }

        if (is_float($amount)) {
            return (int) round($amount);
        }

        $raw = trim((string) $amount);
        if ($raw === '') {
            return 0;
        }

        $raw = ltrim($raw, '$');
        if (preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return (int) round(((float) $raw) * 100);
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);
        return $normalized === '' ? null : $normalized;
    }
}
