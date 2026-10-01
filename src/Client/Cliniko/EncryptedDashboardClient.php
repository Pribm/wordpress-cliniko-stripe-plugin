<?php

namespace App\Client\Cliniko;

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Patient-dashboard-only client cache.
 *
 * Successful GET payloads are encrypted before they are written to
 * WordPress transients. The cache is scoped to a WordPress user and is never
 * used for security decisions unless the caller explicitly bypasses it.
 */
final class EncryptedDashboardClient implements ApiClientInterface
{
    private const CACHE_PREFIX = 'cliniko_dashboard_cache_v1_';
    private const INDEX_PREFIX = 'cliniko_dashboard_index_v1_';
    private const VERSION_PREFIX = 'cliniko_dashboard_version_v1_';

    public function __construct(
        private ApiClientInterface $client,
        private int $scopeId,
        private int $ttl = 7200
    ) {
        $this->ttl = max(1, $ttl);
    }

    public function get(string $url): ClientResponse
    {
        $cacheKey = $this->cacheKey($url);
        $stored = get_transient($cacheKey);
        if (is_string($stored) && $stored !== '') {
            $cached = $this->decode($stored);
            if (is_array($cached) && is_array($cached['data'] ?? null)) {
                return new ClientResponse(
                    $cached['data'],
                    null,
                    isset($cached['status']) ? (int) $cached['status'] : 200
                );
            }
            delete_transient($cacheKey);
        }

        $response = $this->client->get($url);
        $this->storeResponse($cacheKey, $response);

        return $response;
    }

    /** Force a live GET and replace the encrypted cache value on success. */
    public function refresh(string $url): ClientResponse
    {
        $response = $this->client->get($url);
        $cacheKey = $this->cacheKey($url);
        $this->storeResponse($cacheKey, $response);
        return $response;
    }

    public function invalidate(string $url): void
    {
        delete_transient($this->cacheKey($url));
    }

    public function invalidateAll(): void
    {
        $versionKey = $this->versionKey();
        $version = max(1, (int) get_transient($versionKey));
        set_transient($versionKey, $version + 1, $this->ttl);

        $indexKey = $this->indexKey();
        $keys = get_transient($indexKey);
        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (is_string($key) && $key !== '') {
                    delete_transient($key);
                }
            }
        }
        delete_transient($indexKey);
    }

    public function post(string $url, array $data): ClientResponse
    {
        $response = $this->client->post($url, $data);
        if ($response->isSuccessful()) {
            $this->invalidateAll();
        }
        return $response;
    }

    public function put(string $url, array $data): ClientResponse
    {
        $response = $this->client->put($url, $data);
        if ($response->isSuccessful()) {
            $this->invalidateAll();
            $this->storeResponse($this->cacheKey($url), $response);
        }
        return $response;
    }

    public function patch(string $url, array $data): ClientResponse
    {
        $response = $this->client->patch($url, $data);
        if ($response->isSuccessful()) {
            $this->invalidateAll();
            $this->storeResponse($this->cacheKey($url), $response);
        }
        return $response;
    }

    private function cacheKey(string $url): string
    {
        return self::CACHE_PREFIX . md5($this->scope() . '|v' . $this->version() . '|GET|' . $url);
    }

    private function indexKey(): string
    {
        return self::INDEX_PREFIX . md5($this->scope());
    }

    private function versionKey(): string
    {
        return self::VERSION_PREFIX . md5($this->scope());
    }

    private function version(): int
    {
        $version = (int) get_transient($this->versionKey());
        return max(1, $version);
    }

    private function scope(): string
    {
        $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        return $blogId . ':' . max(0, $this->scopeId);
    }

    /** @param array<string,mixed> $payload */
    private function store(string $cacheKey, array $payload): void
    {
        if (!function_exists('wp_cliniko_secret_option_encrypt')
            || !function_exists('wp_cliniko_secret_option_is_encrypted')
            || !function_exists('set_transient')
        ) {
            return;
        }

        $json = wp_json_encode($payload);
        if ($json === false) {
            return;
        }

        $encrypted = wp_cliniko_secret_option_encrypt($json);
        if ($encrypted === '' || !wp_cliniko_secret_option_is_encrypted($encrypted)) {
            // Never fall back to plaintext for medical dashboard data.
            return;
        }

        set_transient($cacheKey, $encrypted, $this->ttl);
        $index = get_transient($this->indexKey());
        $index = is_array($index) ? array_values(array_filter($index, 'is_string')) : [];
        if (!in_array($cacheKey, $index, true)) {
            $index[] = $cacheKey;
            set_transient($this->indexKey(), $index, $this->ttl);
        }
    }

    private function storeResponse(string $cacheKey, ClientResponse $response): void
    {
        if ($response->isSuccessful() && is_array($response->data) && $response->data !== []) {
            $this->store($cacheKey, [
                'data' => $response->data,
                'status' => $response->statusCode,
                'stored_at' => time(),
            ]);
        }
    }

    /** @return array<string,mixed>|null */
    private function decode(string $stored): ?array
    {
        if (!function_exists('wp_cliniko_secret_option_decrypt')) {
            return null;
        }

        $json = wp_cliniko_secret_option_decrypt($stored);
        if ($json === '') {
            return null;
        }

        $payload = json_decode($json, true);
        return is_array($payload) ? $payload : null;
    }
}
