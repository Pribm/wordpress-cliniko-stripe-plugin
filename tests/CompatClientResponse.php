<?php
declare(strict_types=1);

namespace App\Contracts;

/**
 * Runtime-compatible replacement for App\Contracts\ClientResponse on PHP < 8.1.
 * The project source uses readonly properties, which are not parsable on older runtimes.
 */
class ClientResponse
{
    public ?array $data;
    public ?string $error;

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
