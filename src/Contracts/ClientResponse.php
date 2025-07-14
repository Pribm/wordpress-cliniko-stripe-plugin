<?php

namespace App\Contracts;

if (!defined('ABSPATH')) exit;

class ClientResponse
{
    public readonly ?array $data;
    public readonly ?string $error;

    public function __construct(?array $data = null, ?string $error = null)
    {
        $this->data = $data;
        $this->error = $error;
    }

    public function isSuccessful(): bool
    {
        return $this->error === null;
    }
}
