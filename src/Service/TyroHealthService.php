<?php

namespace App\Service;

use App\Admin\Modules\Credentials;
use App\DTO\TyroHealthTransactionDTO;

if (!defined('ABSPATH')) {
    exit;
}

class TyroHealthService
{
    /**
     * @return TyroHealthTransactionDTO
     */
    public function fetchTransaction(string $transactionId): TyroHealthTransactionDTO
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

        return TyroHealthTransactionDTO::fromArray($body);
    }

    /**
     * @return bool
     */
    public function isPaidTransaction(TyroHealthTransactionDTO $transaction, int $expectedAmountCents, string $expectedInvoiceReference): bool
    {
        return $transaction->isPaidFor($expectedAmountCents, $expectedInvoiceReference);
    }
}
