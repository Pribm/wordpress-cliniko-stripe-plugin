<?php

namespace App\Model;

use App\Contracts\ApiClientInterface;
use App\Core\Framework\AbstractModel;
use App\DTO\CreatePatientDTO;
use App\DTO\PatientDTO;
use App\Exception\ApiException;
use App\Service\PatientCustomFieldService;
use App\Widgets\ClinikoForm\ReturnPolicySettingsRegistry;

if (!defined('ABSPATH')) exit;

class Patient extends AbstractModel
{
    public static function create($data, ApiClientInterface $client): ?static
    {
        $protected = ReturnPolicySettingsRegistry::protectedFieldTokens();
        if ($protected === []) {
            return parent::create($data, $client);
        }
        $payload = is_object($data) && method_exists($data, 'toArray') ? $data->toArray() : $data;
        return parent::create(is_array($payload) ? self::protectHistory($payload, $protected) : $payload, $client);
    }

    public static function update(string $id, ?array $data, ApiClientInterface $client): ?static
    {
        $protected = array_key_exists('custom_fields', $data ?? []) ? ReturnPolicySettingsRegistry::protectedFieldTokens() : [];
        if ($data === null || $protected === []) {
            return parent::update($id, $data, $client);
        }

        return self::withCustomFieldsLock($id, static function () use ($id, $data, $protected, $client): ?static {
            return parent::update($id, self::protectHistory($data, $protected, $id, $client), $client);
        });
    }

    /**
     * Serialize custom-field read/modify/write operations for the same patient.
     *
     * @template T
     * @param callable():T $write
     * @return T
     */
    public static function withCustomFieldsLock(string $id, callable $write)
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new ApiException('The Cliniko patient custom fields could not be locked.');
        }

        $key = 'cliniko-patient-' . substr(hash('sha256', $wpdb->options . '|' . $id), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $key)) !== 1) {
            throw new ApiException('The Cliniko patient custom fields are being updated. Please try again.');
        }

        try {
            return $write();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
        }
    }

    /**
     * Remove clinic-controlled values from patient writes. Updates carrying
     * other custom fields retain the current history from Cliniko.
     *
     * @param array<string,mixed> $payload
     * @param list<string> $protected
     * @return array<string,mixed>
     */
    private static function protectHistory(array $payload, array $protected, string $patientId = '', ?ApiClientInterface $client = null): array
    {
        $customFields = $payload['custom_fields'] ?? null;
        if (!is_array($customFields)) {
            unset($payload['custom_fields']);
            return $payload;
        }

        $tokens = array_map('strtolower', $protected);
        $protectedNames = null;
        $isProtected = static function (array $field) use ($tokens, &$protectedNames): bool {
            $token = strtolower(trim((string) ($field['token'] ?? '')));
            if ($token !== '') {
                return in_array($token, $tokens, true);
            }

            if ($protectedNames === null) {
                $protectedNames = [];
                foreach (PatientCustomFieldService::getPatientFieldDefinitions() as $definition) {
                    if (in_array(strtolower($definition['cliniko_field_token']), $tokens, true)) {
                        $protectedNames[] = strtolower(trim($definition['cliniko_field_name']));
                    }
                }
            }
            return in_array(strtolower(trim((string) ($field['name'] ?? ''))), $protectedNames, true);
        };
        $sectionKey = static fn (array $section): string => !empty($section['token'])
            ? 'token:' . strtolower((string) $section['token'])
            : 'name:' . strtolower(trim((string) ($section['name'] ?? '')));
        $sections = [];
        foreach (is_array($customFields['sections'] ?? null) ? $customFields['sections'] : [] as $section) {
            $fields = array_values(array_filter(
                is_array($section['fields'] ?? null) ? $section['fields'] : [],
                static fn ($field): bool => is_array($field) && !$isProtected($field)
            ));
            if ($fields !== []) {
                $section['fields'] = $fields;
                $sections[$sectionKey($section)] = $section;
            }
        }

        if ($sections === []) {
            unset($payload['custom_fields']);
            return $payload;
        }

        if ($patientId !== '' && $client !== null) {
            $freshClient = function_exists('cliniko_client') ? cliniko_client(false) : $client;
            $response = $freshClient->get('patients/' . rawurlencode($patientId));
            if (!$response->isSuccessful() || !is_array($response->data)) {
                throw new ApiException('Could not preserve the current Cliniko patient history.');
            }
            foreach ($response->data['custom_fields']['sections'] ?? [] as $section) {
                $fields = array_values(array_filter($section['fields'] ?? [], $isProtected));
                if ($fields === []) {
                    continue;
                }
                $key = $sectionKey($section);
                $section['fields'] = array_merge($sections[$key]['fields'] ?? [], $fields);
                $sections[$key] = $section;
            }
        }

        $payload['custom_fields'] = ['sections' => array_values($sections)];
        return $payload;
    }

        protected static function newInstance(?object $dto, ApiClientInterface $client): static
    {
        return new static($dto, $client);
    }

    public static function query(string $query, ApiClientInterface $client, bool $throwOnError = false): ?self
    {
        $instance = new static(null, $client);

        $response = $client->get("{$instance->getResourcePath()}{$query}");

        if (!$response->isSuccessful()) {
            if ($throwOnError) {
                throw new ApiException("Failed to query resources at {$instance->getResourcePath()}{$query}.", [
                    'error' => $response->error,
                ]);
            }
            return null;
        }

        $patients = $response->data['patients'] ?? [];

        if (empty($patients)) {
            return null;
        }

        $dtoClass = $instance->getDtoClass();
        return new static($dtoClass::fromArray($patients[0]), $client);
    }

    // Getters
    public function getFullName(): string
    {
        return trim("{$this->dto->firstName} {$this->dto->lastName}");
    }

    public function getEmail(): ?string
    {
        return $this->dto->email;
    }

    public function getFirstName(): string
    {
        return (string) $this->dto->firstName;
    }

    public function getLastName(): string
    {
        return (string) $this->dto->lastName;
    }

    public function getPhone(): ?string
    {
        return $this->dto->phone;
    }

    public function getDateOfBirth(): ?string
    {
        return $this->dto->dateOfBirth;
    }

    public function getMedicare(): ?string
    {
        return $this->dto->medicare;
    }

    public function getMedicareReferenceNumber(): ?string
    {
        return $this->dto->medicareReferenceNumber;
    }

    public function getAddress1(): ?string
    {
        return $this->dto->address1;
    }

    public function getAddress2(): ?string
    {
        return $this->dto->address2;
    }

    public function getCity(): ?string
    {
        return $this->dto->city;
    }

    public function getState(): ?string
    {
        return $this->dto->state;
    }

    public function getPostCode(): ?string
    {
        return $this->dto->postCode;
    }

    public function getCountry(): ?string
    {
        return $this->dto->country;
    }


}
