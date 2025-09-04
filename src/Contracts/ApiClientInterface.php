<?php
namespace App\Contracts;

interface ApiClientInterface
{
    /**
     * Performs a GET request.
     *
     * @param string $url
     */
    public function get(string $url): ClientResponse;

    /**
     * Performs a POST request.
     *
     * @param string $url
     * @param array<string, mixed> $data
     */
    public function post(string $url, array $data): ClientResponse;

     /**
     * Performs a PUT request.
     *
     * @param string $url
     * @param array<string, mixed> $data
     */
    public function put(string $url, array $data): ClientResponse;
}
