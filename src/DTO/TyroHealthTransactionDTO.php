<?php

namespace App\DTO;

if (!defined('ABSPATH')) {
    exit;
}

class TyroHealthTransactionDTO
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
    public ?string $transactionId;
    public ?string $invoiceReference;
    public ?string $status;
    public ?string $businessStatus;
    public ?string $transactionType;
    public ?string $originatingFlow;
    public ?string $currencyCode;
    public int $amountChargedCents;
    public int $amountOutstandingCents;
    public int $amountBalanceCents;
    public ?string $amountChargedString;
    public ?string $amountOutstandingString;
    public ?string $amountBalanceString;
    public ?string $created;
    public ?string $modified;

    /**
     * @var TyroHealthTransactionPaymentDTO[]
     */
    public array $payments;

    public function __construct(array $data)
    {
        $this->id = (string) ($data['_id'] ?? $data['id'] ?? '');
        $this->transactionId = $this->normalizeNullableString($data['transactionId'] ?? $data['transaction_id'] ?? null);
        $this->invoiceReference = $this->normalizeNullableString($data['invoiceReference'] ?? $data['invoice_reference'] ?? null);
        $this->status = $this->normalizeNullableString($data['status'] ?? null);
        $this->businessStatus = $this->normalizeNullableString($data['businessStatus'] ?? $data['business_status'] ?? null);
        $this->transactionType = $this->normalizeNullableString($data['transactionType'] ?? $data['transaction_type'] ?? null);
        $this->originatingFlow = $this->normalizeNullableString($data['originatingFlow'] ?? $data['originating_flow'] ?? null);
        $this->currencyCode = $this->normalizeNullableString($data['currencyCode'] ?? $data['currency_code'] ?? null);
        $this->amountChargedCents = $this->normalizeAmountCents($data['amountCharged'] ?? $data['amount_charged'] ?? null);
        $this->amountOutstandingCents = $this->normalizeAmountCents($data['amountOutstanding'] ?? $data['amount_outstanding'] ?? null);
        $this->amountBalanceCents = $this->normalizeAmountCents($data['amountBalance'] ?? $data['amount_balance'] ?? null);
        $this->amountChargedString = $this->normalizeNullableString($data['amountChargedString'] ?? $data['amount_charged_string'] ?? null);
        $this->amountOutstandingString = $this->normalizeNullableString($data['amountOutstandingString'] ?? $data['amount_outstanding_string'] ?? null);
        $this->amountBalanceString = $this->normalizeNullableString($data['amountBalanceString'] ?? $data['amount_balance_string'] ?? null);
        $this->created = $this->normalizeNullableString($data['created'] ?? null);
        $this->modified = $this->normalizeNullableString($data['modified'] ?? null);

        $rawPayments = is_array($data['payments'] ?? null) ? $data['payments'] : [];
        $this->payments = array_values(array_filter(array_map(
            static fn($payment) => is_array($payment) ? TyroHealthTransactionPaymentDTO::fromArray($payment) : null,
            $rawPayments
        )));
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function isPaidFor(int $expectedAmountCents, string $expectedInvoiceReference): bool
    {
        return $this->hasSuccessfulStatus()
            && $this->hasApprovedPayment()
            && $this->matchesExpectedAmount($expectedAmountCents)
            && $this->matchesExpectedInvoiceReference($expectedInvoiceReference)
            && $this->isSettled();
    }

    public function hasSuccessfulStatus(): bool
    {
        $candidates = [
            $this->status,
            $this->businessStatus,
        ];

        $seen = false;
        foreach ($candidates as $candidate) {
            $normalized = strtolower(trim((string) $candidate));
            if ($normalized === '') {
                continue;
            }

            $seen = true;
            if (!in_array($normalized, self::SUCCESS_STATUSES, true)) {
                return false;
            }
        }

        return $seen;
    }

    public function hasApprovedPayment(): bool
    {
        return $this->approvedPaymentAmountCents() > 0;
    }

    public function approvedPaymentAmountCents(): int
    {
        $total = 0;
        foreach ($this->payments as $payment) {
            if ($payment->isSuccessful()) {
                $total += $payment->getAmountCents();
            }
        }

        return $total;
    }

    public function matchesExpectedAmount(int $expectedAmountCents): bool
    {
        return $this->amountChargedCents === $expectedAmountCents
            && $this->approvedPaymentAmountCents() === $expectedAmountCents;
    }

    public function matchesExpectedInvoiceReference(string $expectedInvoiceReference): bool
    {
        return trim((string) $this->invoiceReference) !== ''
            && trim((string) $this->invoiceReference) === trim($expectedInvoiceReference);
    }

    public function isSettled(): bool
    {
        return $this->amountOutstandingCents === 0
            && $this->amountBalanceCents === 0;
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
