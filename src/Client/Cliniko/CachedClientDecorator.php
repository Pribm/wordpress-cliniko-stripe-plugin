<?php

namespace App\Client\Cliniko;

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;

class CachedClientDecorator implements ApiClientInterface
{
    protected ApiClientInterface $client;
    protected int $ttl;

    public function __construct(ApiClientInterface $client, int $ttl = 300)
    {
        $this->client = $client;
        $this->ttl = $ttl;
    }

    public function get(string $url): ClientResponse
    {
        $cacheKey = 'cliniko_api_' . md5('GET_' . $url);

        $cached = get_transient($cacheKey);

        if ($cached !== false) {

            return new ClientResponse($cached);
        }

        $response = $this->client->get($url);

        if ($response->isSuccessful() && !empty($response->data)) {
            $this->storeCacheValue($cacheKey, $response->data);
        }

        return $response;
    }

    public function refresh(string $url): ClientResponse
    {
        $cacheKey = 'cliniko_api_' . md5('GET_' . $url);

        // Force real API call
        $response = $this->client->get($url);

        if ($response->isSuccessful()) {
            $this->storeCacheValue($cacheKey, $response->data);
        }

        return $response;
    }

    public function invalidate(string $url): void
    {
        $cacheKey = 'cliniko_api_' . md5('GET_' . $url);
        delete_transient($cacheKey);
    }

    public function post(string $url, array $data): ClientResponse
    {
        return $this->client->post($url, $data);
    }

    public function put(string $url, array $data): ClientResponse
    {
        // Invalida o cache da chave relacionada
        $this->invalidate($url);
        return $this->client->put($url, $data);
    }

    /**
     * Persist value in transient storage.
     * ttl <= 0 means persistent cache (manual invalidation), stored with autoload disabled.
     *
     * @param mixed $value
     */
    private function storeCacheValue(string $cacheKey, $value): void
    {
        if ($this->ttl > 0) {
            set_transient($cacheKey, $value, $this->ttl);
            return;
        }

        $optionName = '_transient_' . $cacheKey;
        $timeoutName = '_transient_timeout_' . $cacheKey;

        // Remove timeout to keep this entry non-expiring.
        delete_option($timeoutName);

        if (!add_option($optionName, $value, '', false)) {
            update_option($optionName, $value, false);
        }
    }
}
