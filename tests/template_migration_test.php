<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');
define('WP_CLINIKO_PLUGIN_VERSION', '2.1.0-test');

/** @var array<string,mixed> $templateMigrationOptions */
$templateMigrationOptions = [];

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        global $templateMigrationOptions;
        return array_key_exists($name, $templateMigrationOptions) ? $templateMigrationOptions[$name] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool
    {
        global $templateMigrationOptions;
        $templateMigrationOptions[$name] = $value;
        return true;
    }
}

require __DIR__ . '/../vendor/autoload.php';

use App\Admin\Modules\AccountBuilders\TemplateMigration;

function assert_template_migration_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$templateMigrationOptions = [
    'wp_cliniko_patient_account_forms' => [
        'profile_a' => ['name' => 'Profile A', 'fields' => ['first_name']],
    ],
    'wp_cliniko_component_styles' => [
        'foundation' => [
            'primary' => '#112233',
            'font_family' => 'Clinic Sans',
            'installed_fonts' => [['family' => 'Clinic Sans', 'url' => 'https://source.test/font.woff2']],
        ],
    ],
    'wp_cliniko_patient_booking_forms' => ['booking_a' => ['name' => 'Booking A']],
];

$export = TemplateMigration::buildExportBundle(['patient_forms', 'component_styles']);
assert_template_migration_same('wp-cliniko-template-bundle', $export['format'], 'The export format must be versioned.');
assert_template_migration_same(1, $export['schema_version'], 'The schema version must be included.');
assert_template_migration_same(true, isset($export['sections']['patient_forms']), 'Selected groups must be exported.');
assert_template_migration_same(false, isset($export['sections']['booking_forms']), 'Unselected groups must not be exported.');
assert_template_migration_same(
    false,
    isset($export['sections']['component_styles']['wp_cliniko_component_styles']['foundation']['installed_fonts']),
    'Uploaded font references must not be exported without their files.'
);

$templateMigrationOptions['wp_cliniko_patient_account_forms'] = [
    'profile_a' => ['name' => 'Old profile', 'legacy' => true],
    'destination_only' => ['name' => 'Keep me'],
];
$mergeBundle = [
    'format' => 'wp-cliniko-template-bundle',
    'schema_version' => 1,
    'sections' => [
        'patient_forms' => [
            'wp_cliniko_patient_account_forms' => [
                'profile_a' => ['name' => 'Imported profile'],
                'profile_b' => ['name' => 'New profile'],
            ],
            'wp_cliniko_api_key' => ['must_not' => 'be imported'],
        ],
        'unknown_future_group' => ['unexpected_option' => ['unsafe' => true]],
    ],
];
$mergeResult = TemplateMigration::importBundle($mergeBundle, 'merge');
assert_template_migration_same(1, $mergeResult['sections'], 'Only supported groups must be imported.');
assert_template_migration_same(
    [
        'profile_a' => ['name' => 'Imported profile'],
        'destination_only' => ['name' => 'Keep me'],
        'profile_b' => ['name' => 'New profile'],
    ],
    $templateMigrationOptions['wp_cliniko_patient_account_forms'],
    'Merge mode must preserve destination-only templates and replace matching template IDs as a whole.'
);
assert_template_migration_same(false, isset($templateMigrationOptions['wp_cliniko_api_key']), 'Options outside the migration whitelist must not be written.');

$replaceBundle = [
    'format' => 'wp-cliniko-template-bundle',
    'schema_version' => 1,
    'sections' => [
        'booking_forms' => [
            'wp_cliniko_patient_booking_forms' => ['booking_b' => ['name' => 'Booking B']],
        ],
    ],
];
TemplateMigration::importBundle($replaceBundle, 'replace');
assert_template_migration_same(
    ['booking_b' => ['name' => 'Booking B']],
    $templateMigrationOptions['wp_cliniko_patient_booking_forms'],
    'Replace mode must replace the imported option exactly.'
);

$componentImport = [
    'format' => 'wp-cliniko-template-bundle',
    'schema_version' => 1,
    'sections' => [
        'component_styles' => [
            'wp_cliniko_component_styles' => [
                'foundation' => [
                    'primary' => '#abcdef',
                    'installed_fonts' => [['family' => 'Remote font', 'url' => 'https://other.test/font.woff2']],
                ],
            ],
        ],
    ],
];
TemplateMigration::importBundle($componentImport, 'replace');
assert_template_migration_same(
    false,
    isset($templateMigrationOptions['wp_cliniko_component_styles']['foundation']['installed_fonts']),
    'Imported component styles must not restore font references without font files.'
);

$invalidRejected = false;
try {
    TemplateMigration::importBundle(['format' => 'other', 'schema_version' => 1, 'sections' => []]);
} catch (InvalidArgumentException $exception) {
    $invalidRejected = true;
}
assert_template_migration_same(true, $invalidRejected, 'Unknown bundle formats must be rejected.');

echo "Template migration tests passed.\n";
