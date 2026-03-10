<?php

namespace App\Service;

use App\Admin\Modules\Credentials;

if (!defined('ABSPATH')) {
    exit;
}

class TyroHealthService
{
    /**
     * @return array<string,mixed>
     */
    public function fetchTransaction(string $transactionId): array
    {
        $apiKey = Credentials::getTyroAdminApiKey();
        $appId = Credentials::getTyroAppId();
        if (!$apiKey || !$appId) {
            throw new \RuntimeException('Tyro credentials not configured on server.');
        }

        $base = Credentials::getTyroApiBaseUrl();
        $url = rtrim($base, '/') . '/v3/transactions/' . rawurlencode($transactionId);

        $res = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'x-appid' => $appId,
                'Accept' => 'application/json',
            ],
            'timeout' => 20,
        ]);

        if (is_wp_error($res)) {
            throw new \RuntimeException('Tyro verification failed: ' . $res->get_error_message());
        }

        $statusCode = (int) wp_remote_retrieve_response_code($res);
        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if ($statusCode < 200 || $statusCode >= 300 || !is_array($body)) {
            $message = is_array($body) ? (string) ($body['message'] ?? '') : '';
            throw new \RuntimeException($message !== '' ? $message : 'Tyro verification failed.');
        }

        return $body;
    }

    /**
     * @param array<string,mixed> $transaction
     */
    public function isPaidTransaction(array $transaction, int $expectedAmountCents, string $expectedInvoiceReference): bool
    {
        $statusCandidates = [
            strtolower(trim((string) ($transaction['businessStatus'] ?? ''))),
            strtolower(trim((string) ($transaction['status'] ?? ''))),
            strtolower(trim((string) ($transaction['paymentStatus'] ?? ''))),
        ];

        $successfulStatuses = [
            'success',
            'succeeded',
            'completed',
            'complete',
            'approved',
            'authorised',
            'authorized',
            'paid',
        ];

        $statusOk = false;
        foreach ($statusCandidates as $candidate) {
            if ($candidate !== '' && in_array($candidate, $successfulStatuses, true)) {
                $statusOk = true;
                break;
            }
        }

        $invoiceReference = trim((string) (
            $transaction['invoiceReference']
            ?? $transaction['invoice_reference']
            ?? ($transaction['invoice']['reference'] ?? '')
        ));

        $amountRaw = $transaction['chargeAmount']
            ?? $transaction['charge_amount']
            ?? ($transaction['invoice']['chargeAmount'] ?? null);

        $amountOk = $this->normalizeAmountCents($amountRaw) === $expectedAmountCents;
        $invoiceOk = $invoiceReference !== '' && $invoiceReference === $expectedInvoiceReference;

        return $statusOk && $amountOk && $invoiceOk;
    }

    /**
     * @param mixed $amount
     */
    private function normalizeAmountCents($amount): int
    {
        if (is_int($amount)) {
            return $amount;
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
}
