<?php

namespace App\Service;

use App\DTO\PatientDTO;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientFieldRegistry
{
    /** @var array<string,array<string,mixed>> */
    private const DEFINITIONS = [
        'first_name' => ['property' => 'firstName', 'label' => 'First name', 'type' => 'text', 'api_field' => 'first_name', 'editable' => true],
        'last_name' => ['property' => 'lastName', 'label' => 'Last name', 'type' => 'text', 'api_field' => 'last_name', 'editable' => true],
        'preferred_first_name' => ['property' => 'preferredFirstName', 'label' => 'Preferred first name', 'type' => 'text', 'api_field' => 'preferred_first_name', 'editable' => true],
        'email' => ['property' => 'email', 'label' => 'Email', 'type' => 'email', 'api_field' => 'email', 'editable' => true],
        'phone' => ['property' => 'phone', 'label' => 'Phone', 'type' => 'tel', 'api_field' => 'phone', 'editable' => true],
        'medicare' => ['property' => 'medicare', 'label' => 'Medicare number', 'type' => 'text', 'api_field' => 'medicare', 'editable' => true],
        'medicare_reference_number' => ['property' => 'medicareReferenceNumber', 'label' => 'Medicare reference number', 'type' => 'text', 'api_field' => 'medicare_reference_number', 'editable' => true],
        'address_1' => ['property' => 'address1', 'label' => 'Address line 1', 'type' => 'text', 'api_field' => 'address_1', 'editable' => true],
        'address_2' => ['property' => 'address2', 'label' => 'Address line 2', 'type' => 'text', 'api_field' => 'address_2', 'editable' => true],
        'city' => ['property' => 'city', 'label' => 'City', 'type' => 'text', 'api_field' => 'city', 'editable' => true],
        'state' => ['property' => 'state', 'label' => 'State', 'type' => 'text', 'api_field' => 'state', 'editable' => true],
        'post_code' => ['property' => 'postCode', 'label' => 'Post code', 'type' => 'text', 'api_field' => 'post_code', 'editable' => true],
        'country' => ['property' => 'country', 'label' => 'Country', 'type' => 'text', 'api_field' => 'country', 'editable' => true],
        'date_of_birth' => ['property' => 'dateOfBirth', 'label' => 'Date of birth', 'type' => 'date', 'api_field' => 'date_of_birth', 'editable' => true],
        'gender' => ['property' => 'gender', 'label' => 'Gender', 'type' => 'text', 'api_field' => 'gender', 'editable' => true],
        'occupation' => ['property' => 'occupation', 'label' => 'Occupation', 'type' => 'text', 'api_field' => 'occupation', 'editable' => true],
        'notes' => ['property' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'api_field' => 'notes', 'editable' => true],
    ];

    /** @return array<string,array<string,mixed>> */
    public static function editable(): array
    {
        $properties = array_fill_keys(array_map(
            static fn(\ReflectionProperty $property): string => $property->getName(),
            (new \ReflectionClass(PatientDTO::class))->getProperties()
        ), true);

        return array_filter(
            self::DEFINITIONS,
            static fn(array $definition): bool => isset($properties[$definition['property']])
        );
    }

    /** @return array<string,array<string,mixed>> */
    public static function available(): array
    {
        return array_merge(self::editable(), PatientCustomFieldService::getPatientFieldDefinitions());
    }
}
