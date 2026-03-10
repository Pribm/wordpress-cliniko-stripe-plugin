<?php
namespace App\Client\Cliniko;

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;

if (!defined('ABSPATH')) exit;

class Client implements ApiClientInterface
{
    private static ?self $instance = null;

    private string $authHeader;
    private string $baseUrl;

    private const FALLBACK_SHARD = 'au1';
    private const VALID_SHARD_PATTERN = '/\b\w{2}\d{1,2}\b/i';

    private function __construct()
    {
        $apiKey = get_option('wp_cliniko_api_key');
        $this->authHeader = 'Basic ' . base64_encode($apiKey . ':');
        $this->baseUrl = $this->buildBaseUrlFromToken($apiKey);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function buildBaseUrlFromToken(string $token): string
    {
        $parts = explode('-', $token);
        $shard = end($parts);

        if (!preg_match(self::VALID_SHARD_PATTERN, $shard)) {
            $shard = self::FALLBACK_SHARD;
        }

        return "https://api.{$shard}.cliniko.com/v1/";
    }

    public function get(string $endpointOrUrl): ClientResponse
    {
        $url = str_starts_with($endpointOrUrl, 'http') ? $endpointOrUrl : $this->baseUrl . ltrim($endpointOrUrl, '/');

        $response = wp_remote_get($url, [
            'headers' => $this->getDefaultHeaders()
        ]);

        return $this->buildClientResponse($response);
    }

    public function post(string $endpoint, array $data): ClientResponse
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');

        $toCopy = json_encode($data);

        $response = wp_remote_post($url, [
            'headers' => array_merge($this->getDefaultHeaders(), [
                'Content-Type' => 'application/json'
            ]),
            'body' => json_encode($data)
        ]);

        return $this->buildClientResponse($response);
    }

    public function put(string $endpoint, array $data): ClientResponse
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');

        $response = wp_remote_request($url, [
            'method' => 'PUT',
            'headers' => array_merge($this->getDefaultHeaders(), [
                'Content-Type' => 'application/json'
            ]),
            'body' => json_encode($data)
        ]);

        return $this->buildClientResponse($response);
    }

    public function patch(string $endpoint, array $data): ClientResponse
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');

        $response = wp_remote_request($url, [
            'method' => 'PATCH',
            'headers' => array_merge($this->getDefaultHeaders(), [
                'Content-Type' => 'application/json'
            ]),
            'body' => json_encode($data)
        ]);

        return $this->buildClientResponse($response);
    }

    public function delete(string $endpoint): ClientResponse
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');

        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'headers' => $this->getDefaultHeaders(),
        ]);

        if (is_wp_error($response)) {
            return new ClientResponse(null, $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = $this->decodeBody($body);

        if ($status !== 204) {
            return new ClientResponse(
                $data,
                $this->formatHttpError($status, $data, $body),
                $status,
                $body
            );
        }

        return new ClientResponse(['deleted' => true], null, $status, $body);
    }

    private function getDefaultHeaders(): array
    {
        return [
            'Authorization' => $this->authHeader,
            'Accept' => 'application/json'
        ];
    }

    /**
     * @param array<string,mixed>|\WP_Error $response
     */
    private function buildClientResponse($response): ClientResponse
    {
        if (is_wp_error($response)) {
            return new ClientResponse(null, $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = $this->decodeBody($body);

        if ($status < 200 || $status >= 300) {
            return new ClientResponse(
                $data,
                $this->formatHttpError($status, $data, $body),
                $status,
                $body
            );
        }

        return new ClientResponse($data, null, $status, $body);
    }

    private function decodeBody(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function formatHttpError(int $status, ?array $data, string $body): string
    {
        $parts = ["HTTP {$status}"];

        if (is_array($data)) {
            if (isset($data['message']) && is_string($data['message']) && $data['message'] !== '') {
                $parts[] = $data['message'];
            } elseif (isset($data['errors'])) {
                $encoded = json_encode($data['errors'], JSON_UNESCAPED_SLASHES);
                if (is_string($encoded) && $encoded !== '') {
                    $parts[] = $encoded;
                }
            }
        }

        return implode(' - ', $parts);
    }
}
