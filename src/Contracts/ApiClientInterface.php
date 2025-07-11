<?php
namespace App\Contracts;

interface ApiClientInterface
{
    /**
     * Performs a GET request.
     *
     * @param string $url
     * @return array<string, mixed>|null
     */
    public function get(string $url): ?array;

    /**
     * Performs a POST request.
     *
     * @param string $url
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function post(string $url, array $data): ?array;

    // You can add put(), delete() etc. later as needed
}
