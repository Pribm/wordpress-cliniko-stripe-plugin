<?php
namespace App\Client;

use App\Contracts\ApiClientInterface;

if (!defined('ABSPATH'))
    exit;

class ClinikoClient implements ApiClientInterface
{
    private static ?self $instance = null;

    private string $authHeader;
    private string $baseUrl;

    private const FALLBACK_SHARD = 'au1';
    private const VALID_SHARD_PATTERN = '/\b\w{2}\d{1,2}\b/i';

    /**
     * Private constructor to prevent multiple instances.
     */
    private function __construct()
    {

        $apiKey = get_option('wp_cliniko_api_key');

        $this->authHeader = 'Basic ' . base64_encode($apiKey . ':');
        $this->baseUrl = $this->buildBaseUrlFromToken($apiKey);
    }

    /**
     * Get the singleton instance of ClinikoClient.
     */
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
            error_log("[ClinikoClient] Invalid shard detected: {$shard}. Falling back to " . self::FALLBACK_SHARD);
            $shard = self::FALLBACK_SHARD;
        }

        return "https://api.{$shard}.cliniko.com/v1/";
    }

    public function get(string $endpointOrUrl): ?array
    {
        $url = str_starts_with($endpointOrUrl, 'http') ? $endpointOrUrl : $this->baseUrl . ltrim($endpointOrUrl, '/');

        $response = wp_remote_get($url, [
            'headers' => $this->getDefaultHeaders()
        ]);

        if (is_wp_error($response)) {
            error_log("[ClinikoClient] GET request failed: " . $response->get_error_message());
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function post(string $endpoint, array $data): ?array
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');

        $response = wp_remote_post($url, [
            'headers' => array_merge($this->getDefaultHeaders(), [
                'Content-Type' => 'application/json'
            ]),
            'body' => json_encode($data)
        ]);

        if (is_wp_error($response)) {
            error_log("[ClinikoClient] POST request failed: " . $response->get_error_message());
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    public function delete(string $endpoint): bool
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');

        $response = wp_remote_request($url, [
            'method' => 'DELETE',
            'headers' => $this->getDefaultHeaders(),
        ]);

        if (is_wp_error($response)) {
            error_log("[ClinikoClient] DELETE request failed: " . $response->get_error_message());
            return false;
        }

        $status = wp_remote_retrieve_response_code($response);
        return $status === 204; // Cliniko returns 204 No Content on successful delete
    }


    private function getDefaultHeaders(): array
    {
        return [
            'Authorization' => $this->authHeader,
            'Accept' => 'application/json'
        ];
    }
}
