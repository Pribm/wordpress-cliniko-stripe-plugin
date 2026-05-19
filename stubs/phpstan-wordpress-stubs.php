<?php
declare(strict_types=1);

class WP_REST_Request
{
    /** @var array<string,mixed> */
    protected array $params = [];

    /** @var array<string,mixed> */
    protected array $headers = [];

    protected string $body = '';
    protected string $method = '';
    protected string $route = '';

    /**
     * @param array<string,mixed> $attributes
     */
    public function __construct(string $method = '', string $route = '', array $attributes = [])
    {
    }

    public function get_method(): string
    {
    }

    public function set_method(string $method): void
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function get_headers(): array
    {
    }

    /**
     * @return string|null
     */
    public function get_header(string $key): ?string
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get_header_as_array(string $key): ?array
    {
    }

    /**
     * @param array<string, mixed>|string $value
     */
    public function set_header(string $key, array|string $value): void
    {
    }

    /**
     * @param array<string, mixed>|string $value
     */
    public function add_header(string $key, array|string $value): void
    {
    }

    public function remove_header(string $key): void
    {
    }

    /**
     * @param array<string,mixed> $headers
     */
    public function set_headers(array $headers, bool $override = true): void
    {
    }

    public function get_body(): string
    {
    }

    public function set_body(string $data): void
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function get_params(): array
    {
    }

    /**
     * @return mixed|null
     */
    public function get_param(string $key): mixed
    {
    }

    /**
     * @param array<string,mixed> $params
     */
    public function set_params(array $params): void
    {
    }

    public function get_route(): string
    {
    }

    public function set_route(string $route): void
    {
    }
}

class WP_Error
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(string $code = '', string $message = '', array $data = [])
    {
    }

    public function get_error_message(): string
    {
    }

    public function get_error_code(): string
    {
    }

    /**
     * @return mixed
     */
    public function get_error_data(string $code = ''): mixed
    {
    }
}

class WP_REST_Response
{
    /**
     * @param mixed $data
     */
    public function __construct($data = null, int $status = 200)
    {
    }

    /**
     * @return mixed
     */
    public function get_data()
    {
    }

    public function get_status(): int
    {
    }

    public function header(string $key, string $value, bool $replace = true): void
    {
    }
}

/**
 * @return mixed
 */
function wp_parse_url(string $url, int $component = -1): mixed
{
}
