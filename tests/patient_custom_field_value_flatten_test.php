<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/../vendor/autoload.php';

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/', '', $value));
    }
}

$reflection = new ReflectionClass(App\Service\PatientService::class);
$service = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('flattenCustomFieldValues');
$method->setAccessible(true);

$actual = $method->invoke($service, [
    'sections' => [[
        'fields' => [
            [
                'token' => 'abc',
                'type' => 'radiobuttons',
                'options' => [
                    ['name' => 'phone call', 'selected' => true],
                    ['name' => 'email', 'selected' => false],
                ],
            ],
            [
                'token' => 'xyz',
                'type' => 'checkboxes',
                'options' => [
                    ['name' => 'SMS', 'selected' => true],
                    ['name' => 'Email', 'selected' => true],
                ],
            ],
        ],
    ]],
]);

$expected = [
    'custom_abc' => 'phone call',
    'custom_xyz' => 'SMS, Email',
];

if ($actual !== $expected) {
    throw new RuntimeException('Selected custom field options were not flattened into onboarding values.');
}

echo "Patient custom field choice flatten test passed.\n";
