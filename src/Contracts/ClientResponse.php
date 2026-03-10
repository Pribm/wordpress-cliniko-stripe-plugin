<?php

namespace App\Contracts;

if (!defined('ABSPATH')) exit;

class ClientResponse
{
    public ?array $data;
    public ?string $error;
    public ?int $statusCode;
    public ?string $rawBody;

    public function __construct(
        ?array $data = null,
        ?string $error = null,
        ?int $statusCode = null,
        ?string $rawBody = null
    )
    {
        $this->data = $data;
        $this->error = $error;
        $this->statusCode = $statusCode;
        $this->rawBody = $rawBody;
    }

    public function isSuccessful(): bool
    {
        return $this->error === null;
    }
}
