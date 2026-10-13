<?php
declare(strict_types=1);

require __DIR__ . '/../src/DTO/Patients/CreatePatientDTO.php';

use App\DTO\CreatePatientDTO;

$dto = new CreatePatientDTO();
$dto->address1 = '123 Example Street';
$dto->address2 = 'Unit 4';
$dto->address3 = 'Level 2';
$dto->city = 'Sydney';
$dto->state = 'NSW';
$dto->postCode = '2000';
$dto->countryCode = 'AU';

$expected = [
    'address_1' => '123 Example Street',
    'address_2' => 'Unit 4',
    'address_3' => 'Level 2',
    'city' => 'Sydney',
    'state' => 'NSW',
    'post_code' => '2000',
    'country_code' => 'AU',
];

$payload = $dto->toArray();
foreach ($expected as $key => $value) {
    if (!array_key_exists($key, $payload) || $payload[$key] !== $value) {
        throw new RuntimeException('Patient creation payload must preserve ' . $key . ' using the Cliniko API field name.');
    }
}

foreach (['address1', 'address2', 'address3'] as $key) {
    if (array_key_exists($key, $payload)) {
        throw new RuntimeException('Patient creation payload must not contain unsupported field ' . $key . '.');
    }
}

$emptyPayload = (new CreatePatientDTO())->toArray();
foreach (['address_1', 'address_2', 'address_3'] as $key) {
    if (!array_key_exists($key, $emptyPayload) || $emptyPayload[$key] !== null) {
        throw new RuntimeException('An omitted address line must remain null for ' . $key . '.');
    }
}

echo "Create patient DTO address test passed.\n";
