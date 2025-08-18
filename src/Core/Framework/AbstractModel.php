<?php

namespace App\Core\Framework;

use App\Contracts\ApiClientInterface;
use App\Exception\ApiException;

/**
 * @phpstan-consistent-constructor
 */
abstract class AbstractModel
{
    /** @var object|null */
    protected $dto;

    protected ApiClientInterface $client;

    public function __construct(?object $dto, ApiClientInterface $client)
    {
        $this->dto    = $dto;
        $this->client = $client;
    }

    /**
     * Concrete classes must decide how to instantiate themselves.
     * Typical child:
     *   protected static function newInstance(?object $dto, ApiClientInterface $client): static
     *   {
     *       return new static($dto, $client);
     *   }
     */
    abstract protected static function newInstance(?object $dto, ApiClientInterface $client): static;

    // -----------------------------
    // Static helpers (no instantiation)
    // -----------------------------

    protected static function getResourcePath(): string
    {
        $className = (new \ReflectionClass(static::class))->getShortName();
        return self::toSnakeCase($className) . 's';
    }

    /**
     * @return class-string
     */
    protected static function getDtoClass(): string
    {
        $className = (new \ReflectionClass(static::class))->getShortName();
        $dtoClass  = "App\\DTO\\{$className}DTO";

        if (!class_exists($dtoClass)) {
            throw new \RuntimeException(
                "Default DTO class {$dtoClass} does not exist. Override getDtoClass in " . static::class
            );
        }
        return $dtoClass;
    }

    /**
     * @return class-string|null
     */
    protected static function getCreateDtoClass(): ?string
    {
        $className       = (new \ReflectionClass(static::class))->getShortName();
        $createDtoClass  = "App\\DTO\\Create{$className}DTO";
        return class_exists($createDtoClass) ? $createDtoClass : null;
    }

    private static function toSnakeCase(string $input): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
    }

    // -----------------------------
    // Instance getters
    // -----------------------------

    public function getId(): string
    {
        if (!$this->dto || !property_exists($this->dto, 'id')) {
            throw new \RuntimeException("DTO is null or missing 'id' property in " . static::class);
        }
        /** @phpstan-ignore-next-line */
        return $this->dto->id;
    }

    public function getName(): ?string
    {
        /** @phpstan-ignore-next-line */
        return $this->dto && property_exists($this->dto, 'name') ? $this->dto->name : null;
    }

    public function isArchived(): bool
    {
        /** @phpstan-ignore-next-line */
        return $this->dto && property_exists($this->dto, 'archivedAt') && $this->dto->archivedAt !== null;
    }

    public function getDTO(): ?object
    {
        return $this->dto;
    }

    // -----------------------------
    // Static CRUD
    // -----------------------------

    /**
     * @return static|null
     * @throws ApiException
     */
    public static function find(string $id, ApiClientInterface $client, bool $throwOnError = true): ?static
    {
        $path     = static::getResourcePath();
        $response = $client->get("{$path}/{$id}");

        if (!$response->isSuccessful()) {
            if ($throwOnError) {
                throw new ApiException("Failed to find resource at {$path}/{$id}.", ['error' => $response->error]);
            }
            return null;
        }

        if (empty($response->data)) {
            // Bodyless success (e.g., 204/202) -> nothing to hydrate
            return null;
        }

        $dtoClass = static::getDtoClass();
        /** @var object $dto */
        $dto = $dtoClass::fromArray($response->data);

        return static::newInstance($dto, $client);
    }

    /**
     * @phpstan-return list<static>
     * @throws ApiException
     */
    public static function all(ApiClientInterface $client, bool $throwOnError = false): array
    {
        $path     = static::getResourcePath();
        $response = $client->get($path);

        if (!$response->isSuccessful()) {
            if ($throwOnError) {
                throw new ApiException("Failed to list resources at {$path}.", ['error' => $response->error]);
            }
            return [];
        }

        $items    = [];
        $dtoClass = static::getDtoClass();
        $listKey  = $path;

        $rows = $response->data[$listKey] ?? [];
        if (!is_array($rows)) {
            // Defensive: unexpected payload -> empty list
            return [];
        }

        foreach ($rows as $item) {
            /** @var object $dto */
            $dto     = $dtoClass::fromArray($item);
            $items[] = static::newInstance($dto, $client);
        }

        return $items;
    }

    /**
     * @param array<string,mixed>|object|null $data  DTO with toArray(), assoc array, or null for empty body
     * @return static|null
     * @throws ApiException
     */
    public static function create($data, ApiClientInterface $client): ?static
    {
        $path           = static::getResourcePath();
        $createDtoClass = static::getCreateDtoClass();

        // If it's an object with toArray(), prefer it. Allow null (bodyless endpoints).
        $payload = [];
        if (is_object($data) && method_exists($data, 'toArray')) {
            /** @var array<string,mixed> $payload */
            $payload = $data->toArray();
        } elseif (is_array($data)) {
            /** @var array<string,mixed> $payload */
            $payload = $data;
        }

        $response = $client->post($path, $payload);

        if (!$response->isSuccessful()) {
            throw new ApiException("Failed to create resource at {$path}.", [
                'error' => $response->error,
                'serialized_obj' => $payload,
            ]);
        }

        if (isset($response->data['errors'])) {
            throw new ApiException("API validation error at {$path}.", [
                'errors' => $response->data['errors'],
                'serialized_obj' => $payload,
            ]);
        }

        if (empty($response->data)) {
            // Some APIs return 201 with no body; signal "nothing to hydrate"
            return null;
        }

        $dtoClass = static::getDtoClass();
        /** @var object $dto */
        $dto = $dtoClass::fromArray($response->data);

        return static::newInstance($dto, $client);
    }

    /**
     * @param array<string,mixed>|null $data
     * @return static|null
     * @throws ApiException
     */
    public static function update(string $id, ?array $data, ApiClientInterface $client): ?static
    {
        $path     = static::getResourcePath();
        $payload  = $data ?? [];

        $response = $client->put("{$path}/{$id}", $payload);

        if (!$response->isSuccessful()) {
            throw new ApiException("Failed to update resource at {$path}/{$id}.", [
                'error' => $response->error,
                'serialized_obj' => $payload,
            ]);
        }

        if (isset($response->data['errors'])) {
            throw new ApiException("API validation error at {$path}/{$id}.", [
                'errors' => $response->data['errors'],
                'serialized_obj' => $payload,
            ]);
        }

        if (empty($response->data)) {
            // Bodyless success (e.g., 204)
            return null;
        }

        $dtoClass = static::getDtoClass();
        /** @var object $dto */
        $dto = $dtoClass::fromArray($response->data);

        return static::newInstance($dto, $client);
    }

    /**
     * @throws ApiException
     */
    public static function delete(string $id, ApiClientInterface $client): bool
    {
        $path     = static::getResourcePath();
        $response = $client->post("{$path}/{$id}/archive", []);

        if (!$response->isSuccessful()) {
            throw new ApiException("Failed to delete resource at {$path}/{$id}.", ['error' => $response->error]);
        }

        return true;
    }

    /**
     * @return static|null
     * @throws ApiException
     */
    public static function findFromUrl(string $url, ApiClientInterface $client, bool $throwOnError = true): ?static
    {
        $response = $client->get($url);

        if (!$response->isSuccessful()) {
            if ($throwOnError) {
                throw new ApiException("Failed to find resource at {$url}.", ['error' => $response->error]);
            }
            return null;
        }

        if (empty($response->data)) {
            return null;
        }

        $dtoClass = static::getDtoClass();
        /** @var object $dto */
        $dto = $dtoClass::fromArray($response->data);

        return static::newInstance($dto, $client);
    }

    // -----------------------------
    // Utilities
    // -----------------------------

    /**
     * @phpstan-return array<string,mixed>
     */
    protected function safeGetLinkedEntity(string $url): array
    {
        if ($url === '') {
            return [];
        }

        $response = $this->client->get($url);
        if (!$response->isSuccessful()) {
            return [];
        }

        /** @var array<string,mixed> $data */
        $data = is_array($response->data) ? $response->data : [];
        return $data;
    }
}
