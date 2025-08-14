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

        if ($response->isSuccessful()) {
            set_transient($cacheKey, $response->data, $this->ttl);
        }

        return $response;
    }

    public function refresh(string $url): ClientResponse
    {
        $cacheKey = 'cliniko_api_' . md5('GET_' . $url);

        // Force real API call
        $response = $this->client->get($url);

        if ($response->isSuccessful()) {
            set_transient($cacheKey, $response->data, $this->ttl);
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
}
