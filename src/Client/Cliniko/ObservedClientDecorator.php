<?php

namespace App\Client\Cliniko;

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Debug\Runtime;

if (!defined('ABSPATH')) {
    exit;
}

class ObservedClientDecorator implements ApiClientInterface
{
    private ApiClientInterface $client;
    private string $service;
    private bool $cacheEnabled;

    public function __construct(ApiClientInterface $client, string $service = 'cliniko', bool $cacheEnabled = false)
    {
        $this->client = $client;
        $this->service = $service;
        $this->cacheEnabled = $cacheEnabled;
    }

    public function get(string $url): ClientResponse
    {
        return $this->observe('GET', $url, static fn (ApiClientInterface $client): ClientResponse => $client->get($url));
    }

    /**
     * @param array<string,mixed> $data
     */
    public function post(string $url, array $data): ClientResponse
    {
        return $this->observe('POST', $url, static fn (ApiClientInterface $client): ClientResponse => $client->post($url, $data));
    }

    /**
     * @param array<string,mixed> $data
     */
    public function put(string $url, array $data): ClientResponse
    {
        return $this->observe('PUT', $url, static fn (ApiClientInterface $client): ClientResponse => $client->put($url, $data));
    }

    /**
     * @param array<string,mixed> $data
     */
    public function patch(string $url, array $data): ClientResponse
    {
        return $this->observe('PATCH', $url, static fn (ApiClientInterface $client): ClientResponse => $client->patch($url, $data));
    }

    /**
     * @param callable(ApiClientInterface):ClientResponse $callback
     */
    private function observe(string $method, string $target, callable $callback): ClientResponse
    {
        $startedAt = microtime(true);

        try {
            $response = $callback($this->client);
            Runtime::logClientResponse(
                $this->service,
                $method,
                $target,
                (int) round((microtime(true) - $startedAt) * 1000),
                $response,
                $this->cacheEnabled
            );

            return $response;
        } catch (\Throwable $e) {
            Runtime::logClientException(
                $this->service,
                $method,
                $target,
                (int) round((microtime(true) - $startedAt) * 1000),
                $e,
                $this->cacheEnabled
            );

            throw $e;
        }
    }
}
