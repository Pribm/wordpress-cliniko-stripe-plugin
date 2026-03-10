<?php
declare(strict_types=1);

use App\Client\Cliniko\Client;
use App\Exception\ApiException;
use App\Model\AppointmentType;
use App\Model\IndividualAppointment;
use App\Model\Patient;
use App\Model\PatientForm;
use App\Model\PatientFormTemplate;
use App\Service\BookingAttemptService;
use App\Service\BookingAttemptStore;
use App\Service\ClinikoService;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This integration test must be run from the CLI.\n");
    exit(1);
}

load_integration_env();

if (!env_bool('CLINIKO_IT_ENABLED', false)) {
    fwrite(STDOUT, "Skipping live Cliniko integration test. Set CLINIKO_IT_ENABLED=1 to run it.\n");
    exit(0);
}

require_once resolve_wp_load_path();

if (!function_exists('cliniko_client')) {
    require_once __DIR__ . '/../src/Helpers/cliniko_client.php';
}

$apiKey = env_required('CLINIKO_IT_API_KEY');
$businessId = env_required('CLINIKO_IT_BUSINESS_ID');
$practitionerId = env_required('CLINIKO_IT_PRACTITIONER_ID');
$searchDays = max(7, (int) env_value('CLINIKO_IT_SEARCH_DAYS', '60'));

configure_test_options($apiKey, $businessId);
reset_cliniko_client_singleton();

$client = cliniko_client(false);
$attemptStore = new BookingAttemptStore();
$bookingService = new BookingAttemptService($attemptStore);
$clinikoService = new ClinikoService();

$runId = live_run_id();
$label = 'IT Booking_' . $runId;

$appointmentTypeId = null;
$patientFormTemplateId = null;
$patientId = null;
$patientFormId = null;
$appointmentId = null;
$attemptId = null;
$cleanupArgs = null;

try {
    fwrite(STDOUT, "Creating live Cliniko fixtures...\n");

    $template = PatientFormTemplate::create(build_patient_form_template_payload($label), $client);
    assert_true($template !== null, 'Failed to create patient form template.');
    $patientFormTemplateId = (string) $template->getId();

    $appointmentType = AppointmentType::create(
        build_appointment_type_payload($label, $businessId, $practitionerId),
        $client
    );
    assert_true($appointmentType !== null, 'Failed to create appointment type.');
    $appointmentTypeId = (string) $appointmentType->getId();

    fwrite(STDOUT, "Resolving next available appointment slot...\n");
    $appointmentStart = resolve_next_available_slot(
        $clinikoService,
        $client,
        $businessId,
        $practitionerId,
        $appointmentTypeId,
        $searchDays
    );

    $payload = build_booking_attempt_payload(
        $runId,
        $appointmentTypeId,
        $patientFormTemplateId,
        $practitionerId,
        $appointmentStart
    );

    fwrite(STDOUT, "Running preflight...\n");
    $preflight = $bookingService->preflight($payload);
    assert_true(($preflight['ok'] ?? false) === true, 'Preflight failed: ' . encode_debug($preflight));

    $attemptId = (string) ($preflight['attempt']['id'] ?? '');
    assert_true($attemptId !== '', 'Preflight did not return an attempt id.');
    $cleanupArgs = ['attempt_id' => $attemptId, '_unique' => $attemptId];

    $attempt = $attemptStore->get($attemptId);
    assert_true(is_array($attempt), 'Attempt record was not stored.');

    $patientId = trim((string) ($attempt['patient_id'] ?? ''));
    $patientFormId = trim((string) ($attempt['patient_form_id'] ?? ''));

    assert_true($patientId !== '', 'Preflight did not create a patient.');
    assert_true($patientFormId !== '', 'Preflight did not create a patient form.');

    fwrite(STDOUT, "Finalizing booking...\n");
    $finalize = $bookingService->finalize($attemptId);
    assert_true(($finalize['ok'] ?? false) === true, 'Finalize failed: ' . encode_debug($finalize));

    $attempt = $attemptStore->get($attemptId);
    assert_true(is_array($attempt), 'Attempt record disappeared after finalize.');
    assert_true(($attempt['status'] ?? '') === 'completed', 'Attempt was not marked completed.');

    $appointmentId = trim((string) ($attempt['booking']['appointment_id'] ?? ''));
    $attendeeId = trim((string) ($attempt['booking']['attendee_id'] ?? ''));
    assert_true($appointmentId !== '', 'Finalize did not return an appointment id.');
    assert_true($attendeeId !== '', 'Finalize did not return an attendee id.');

    $patientFormRaw = $client->get('patient_forms/' . $patientFormId)->data ?? [];
    assert_true(is_array($patientFormRaw), 'Could not fetch created patient form.');
    $linkedAttendeeId = extract_linked_resource_id($patientFormRaw['attendee']['links']['self'] ?? null)
        ?? trim((string) ($patientFormRaw['attendee_id'] ?? ''));
    assert_true(
        $linkedAttendeeId === $attendeeId,
        'Patient form was not attached to the expected attendee. Raw form: ' . encode_debug($patientFormRaw)
    );

    fwrite(STDOUT, "PASS: live Cliniko booking attempt completed and attached the form.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . format_exception_details($e) . "\n");
    exitCode(1);
} finally {
    fwrite(STDOUT, "Cleaning up live Cliniko fixtures...\n");

    if ($cleanupArgs !== null && function_exists('as_unschedule_all_actions')) {
        try {
            as_unschedule_all_actions('cliniko_cleanup_booking_attempt', [$cleanupArgs], 'wp-cliniko');
        } catch (Throwable $e) {
            fwrite(STDERR, "Cleanup warning: failed to unschedule Action Scheduler job: {$e->getMessage()}\n");
        }
    }

    if ($attemptId !== null) {
        try {
            $attemptStore->delete($attemptId);
        } catch (Throwable $e) {
            fwrite(STDERR, "Cleanup warning: failed to delete attempt store record: {$e->getMessage()}\n");
        }
    }

    archive_if_present('appointment', $appointmentId, static function (string $id) use ($client): void {
        IndividualAppointment::delete($id, $client);
    });

    archive_if_present('patient form', $patientFormId, static function (string $id) use ($client): void {
        PatientForm::delete($id, $client);
    });

    archive_if_present('patient', $patientId, static function (string $id) use ($client): void {
        Patient::delete($id, $client);
    });

    archive_if_present('patient form template', $patientFormTemplateId, static function (string $id) use ($client): void {
        PatientFormTemplate::delete($id, $client);
    });

    archive_if_present('appointment type', $appointmentTypeId, static function (string $id) use ($client): void {
        AppointmentType::delete($id, $client);
    });
}

function load_integration_env(): void
{
    $root = dirname(__DIR__);
    $files = [];

    foreach (['.env.integration', '.env.integration.local'] as $candidate) {
        if (is_file($root . DIRECTORY_SEPARATOR . $candidate)) {
            $files[] = $candidate;
        }
    }

    if (empty($files)) {
        return;
    }

    Dotenv::createImmutable($root, $files)->safeLoad();
}

function resolve_wp_load_path(): string
{
    $fromEnv = trim((string) env_value('CLINIKO_IT_WP_LOAD_PATH', ''));
    if ($fromEnv !== '') {
        if (!is_file($fromEnv)) {
            throw new RuntimeException('CLINIKO_IT_WP_LOAD_PATH does not point to a valid wp-load.php file.');
        }
        return $fromEnv;
    }

    $cursor = dirname(__DIR__);
    for ($i = 0; $i < 6; $i++) {
        $candidate = $cursor . DIRECTORY_SEPARATOR . 'wp-load.php';
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($cursor);
        if ($parent === $cursor) {
            break;
        }
        $cursor = $parent;
    }

    throw new RuntimeException('Could not locate wp-load.php. Set CLINIKO_IT_WP_LOAD_PATH in .env.integration.');
}

function configure_test_options(string $apiKey, string $businessId): void
{
    update_option('wp_cliniko_api_key', $apiKey, false);
    update_option('wp_cliniko_business_id', $businessId, false);
    update_option('wp_cliniko_api_cache_ttl', 0, false);
}

function reset_cliniko_client_singleton(): void
{
    $ref = new ReflectionClass(Client::class);
    $prop = $ref->getProperty('instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
}

function live_run_id(): string
{
    return gmdate('YmdHis') . '-' . substr(bin2hex(random_bytes_safe(4)), 0, 8);
}

function build_patient_form_template_payload(string $label): array
{
    return [
        'name' => $label . ' Template',
        'email_to_patient_on_completion' => false,
        'restricted_to_practitioner' => false,
        'content' => [
            'sections' => [[
                'name' => 'Integration Intake',
                'description' => 'Auto-created patient form template for live integration tests.',
                'questions' => [
                    [
                        'name' => 'Primary concern',
                        'type' => 'text',
                        'required' => true,
                    ],
                    [
                        'name' => 'Symptoms today',
                        'type' => 'radiobuttons',
                        'required' => true,
                        'answers' => [
                            ['value' => 'Yes'],
                            ['value' => 'No'],
                        ],
                    ],
                ],
            ]],
        ],
    ];
}

function build_appointment_type_payload(string $label, string $businessId, string $practitionerId): array
{
    return [
        'name' => $label . ' Type',
        'description' => 'Auto-created appointment type for live integration tests.',
        'category' => 'Integration Tests',
        'color' => '#1F7A5A',
        'duration_in_minutes' => 15,
        'max_attendees' => 1,
        'telehealth_enabled' => false,
        'show_in_online_bookings' => true,
        'business_ids' => [$businessId],
        'practitioner_ids' => [$practitionerId],
    ];
}

function resolve_next_available_slot(
    ClinikoService $clinikoService,
    $client,
    string $businessId,
    string $practitionerId,
    string $appointmentTypeId,
    int $searchDays
): string {
    $utc = new DateTimeZone('UTC');
    $cursor = new DateTimeImmutable('today', $utc);
    $end = $cursor->add(new DateInterval('P' . $searchDays . 'D'));

    while ($cursor <= $end) {
        $windowEnd = $cursor->add(new DateInterval('P13D'));
        if ($windowEnd > $end) {
            $windowEnd = $end;
        }

        $next = $clinikoService->getNextAvailableTime(
            $businessId,
            $practitionerId,
            $appointmentTypeId,
            $cursor->format('Y-m-d'),
            $windowEnd->format('Y-m-d'),
            $client
        );

        if ($next && !empty($next->appointmentStart)) {
            return (string) $next->appointmentStart;
        }

        $cursor = $windowEnd->add(new DateInterval('P1D'));
    }

    throw new RuntimeException(
        "No available appointment time was found within {$searchDays} days for practitioner {$practitionerId}."
    );
}

function build_booking_attempt_payload(
    string $runId,
    string $appointmentTypeId,
    string $patientFormTemplateId,
    string $practitionerId,
    string $appointmentStart
): array {
    return [
        'gateway' => 'stripe',
        'moduleId' => $appointmentTypeId,
        'patient_form_template_id' => $patientFormTemplateId,
        'patient' => [
            'first_name' => 'Integration',
            'last_name' => 'Verifier',
            'email' => "cliniko-it+{$runId}@example.test",
            'phone' => '0412345678',
            'medicare' => '123456789',
            'medicare_reference_number' => '1',
            'practitioner_id' => $practitionerId,
            'appointment_start' => $appointmentStart,
            'appointment_date' => substr($appointmentStart, 0, 10),
            'date_of_birth' => '1990-01-01',
        ],
        'content' => [
            'sections' => [[
                'name' => 'Integration Intake',
                'questions' => [
                    [
                        'name' => 'Primary concern',
                        'type' => 'text',
                        'required' => true,
                        'answer' => 'Live integration test booking.',
                    ],
                    [
                        'name' => 'Symptoms today',
                        'type' => 'radiobuttons',
                        'required' => true,
                        'answers' => [
                            ['value' => 'Yes', 'selected' => true],
                            ['value' => 'No', 'selected' => false],
                        ],
                    ],
                ],
            ]],
        ],
    ];
}

function archive_if_present(string $label, ?string $id, callable $archiver): void
{
    $resourceId = trim((string) ($id ?? ''));
    if ($resourceId === '') {
        return;
    }

    try {
        $archiver($resourceId);
    } catch (Throwable $e) {
        fwrite(STDERR, "Cleanup warning: failed to archive {$label} {$resourceId}: {$e->getMessage()}\n");
    }
}

function env_required(string $key): string
{
    $value = trim((string) env_value($key, ''));
    if ($value === '') {
        throw new RuntimeException("Missing required integration env var {$key}.");
    }
    return $value;
}

function env_bool(string $key, bool $default): bool
{
    $value = strtolower(trim((string) env_value($key, $default ? '1' : '0')));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * @return mixed
 */
function env_value(string $key, $default = null)
{
    if (array_key_exists($key, $_ENV)) {
        return $_ENV[$key];
    }
    if (array_key_exists($key, $_SERVER)) {
        return $_SERVER[$key];
    }
    $value = getenv($key);
    return $value === false ? $default : $value;
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function encode_debug(array $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '[unserializable]';
}

function random_bytes_safe(int $length): string
{
    try {
        return random_bytes($length);
    } catch (Throwable $e) {
        return substr(hash('sha256', uniqid('cliniko-it', true), true), 0, $length);
    }
}

function extract_linked_resource_id($url): ?string
{
    $value = trim((string) $url);
    if ($value === '') {
        return null;
    }

    $path = parse_url($value, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return null;
    }

    $segments = array_values(array_filter(explode('/', trim($path, '/'))));
    if (empty($segments)) {
        return null;
    }

    return (string) end($segments);
}

function format_exception_details(Throwable $e): string
{
    $parts = [get_class($e) . ': ' . $e->getMessage()];

    if ($e instanceof ApiException) {
        $context = $e->getContext();
        if ($context !== []) {
            $parts[] = 'Context: ' . encode_debug($context);
        }
    }

    if ($e->getPrevious() !== null) {
        $parts[] = 'Previous: ' . get_class($e->getPrevious()) . ': ' . $e->getPrevious()->getMessage();
    }

    return implode("\n", $parts);
}

function exitCode(int $code): void
{
    exit($code);
}
