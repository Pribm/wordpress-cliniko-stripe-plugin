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
