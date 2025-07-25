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

    public function post(string $url, array $data): ClientResponse
    {
        return $this->client->post($url, $data);
    }
}
