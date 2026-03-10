<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Service\BookingAttemptService;
use App\Service\BookingAttemptStore;
use App\Workers\BookingAttemptCleanupWorker;

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['__wp_options'][$name] ?? $default;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $name, $value, string $deprecated = '', bool $autoload = true): bool
    {
        if (array_key_exists($name, $GLOBALS['__wp_options'])) {
            return false;
        }
        $GLOBALS['__wp_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $name, $value, bool $autoload = true): bool
    {
        $GLOBALS['__wp_options'][$name] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset($GLOBALS['__wp_options'][$name]);
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $name)
    {
        return $GLOBALS['__wp_transients'][$name] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $name, $value, int $ttl): bool
    {
        $GLOBALS['__wp_transients'][$name] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $name): bool
    {
        unset($GLOBALS['__wp_transients'][$name]);
        return true;
    }
}

if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action(int $when, string $hook, array $args, string $group): bool
    {
        $GLOBALS['__scheduled_actions'][] = [
            'when' => $when,
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
        ];
        return true;
    }
}

final class AttemptFakeApiClient implements ApiClientInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $responses = [];

    /** @var array<int,string> */
    public array $calls = [];

    /** @var array<string,array<int,array<string,mixed>>> */
    public array $requests = [];

    public function get(string $url): ClientResponse
    {
        return $this->reply('GET', $url);
    }

    public function post(string $url, array $data): ClientResponse
    {
        return $this->reply('POST', $url, $data);
    }

    public function put(string $url, array $data): ClientResponse
    {
        return $this->reply('PUT', $url, $data);
    }

    public function patch(string $url, array $data): ClientResponse
    {
        return $this->reply('PATCH', $url, $data);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function reply(string $method, string $url, array $data = []): ClientResponse
    {
        $this->calls[] = $method . ' ' . $url;
        $this->requests[$method . ' ' . $url][] = $data;
        $key = $method . ' ' . $url;
        if (!array_key_exists($key, $this->responses)) {
            return new ClientResponse(null, 'No fake response configured for ' . $key);
        }

        return new ClientResponse($this->responses[$key]);
    }
}

if (!function_exists('cliniko_client')) {
    function cliniko_client(bool $withCache = false, int $ttl = 300): ApiClientInterface
    {
        return $GLOBALS['__attempt_fake_client'];
    }
}

function base_payload(): array
{
    return [
        'gateway' => 'stripe',
        'moduleId' => '222',
        'patient_form_template_id' => '999',
        'patient' => [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'practitioner_id' => '1547537765724333824',
            'appointment_start' => '2026-02-20T03:00:00Z',
            'medicare' => '123456789',
            'medicare_reference_number' => '1',
            'phone' => '0412345678',
        ],
        'content' => [
            'sections' => [[
                'name' => 'Basic',
                'questions' => [[
                    'name' => 'Question 1',
                    'type' => 'text',
                    'required' => true,
                    'answer' => 'Yes',
                ]],
            ]],
        ],
    ];
}

function appointment_type_payload(): array
{
    return [
        'id' => '222',
        'name' => 'Free Consult',
        'description' => 'Test appointment type',
        'category' => 'General',
        'color' => '#0099ff',
        'duration_in_minutes' => 30,
        'telehealth_enabled' => false,
        'show_in_online_bookings' => true,
        'online_payments_enabled' => false,
        'online_payments_mode' => 'optional',
        'practitioners' => ['links' => ['self' => 'https://fake/appointment_types/222/practitioners']],
        'links' => ['self' => 'https://fake/appointment_types/222'],
    ];
}

function patient_template_payload(): array
{
    return [
        'id' => '999',
        'name' => 'Consult Template',
        'email_to_patient_on_completion' => true,
        'restricted_to_practitioner' => false,
        'content' => ['sections' => []],
        'links' => ['self' => 'https://fake/patient_form_templates/999'],
    ];
}

function patient_payload(): array
{
    return [
        'id' => 'patient_1',
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'patient_phone_numbers' => [['number' => '0412345678']],
        'appointments' => ['links' => ['self' => 'https://fake/patients/patient_1/appointments']],
        'links' => ['self' => 'https://fake/patients/patient_1'],
    ];
}

function patient_form_payload(): array
{
    return [
        'id' => 'pf_1',
        'name' => 'Consult Template - Pending appointment',
        'content' => ['sections' => []],
        'email_to_patient_on_completion' => false,
        'links' => ['self' => 'https://fake/patient_forms/pf_1'],
    ];
}

function attached_patient_form_payload(): array
{
    return array_merge(patient_form_payload(), [
        'attendee' => ['links' => ['self' => 'https://fake/attendees/att_1']],
    ]);
}

function appointment_payload(): array
{
    return [
        'id' => 'appt_1',
        'starts_at' => '2026-02-20T03:00:00Z',
        'ends_at' => '2026-02-20T03:30:00Z',
        'created_at' => '2026-02-10T00:00:00Z',
        'updated_at' => '2026-02-10T00:00:00Z',
        'links' => ['self' => 'https://fake/individual_appointments/appt_1'],
    ];
}

function booking_payload(): array
{
    return [
        'id' => 'appt_1',
        'starts_at' => '2026-02-20T03:00:00Z',
        'ends_at' => '2026-02-20T03:30:00Z',
        'attendees' => ['links' => ['self' => 'https://fake/bookings/appt_1/attendees']],
        'links' => ['self' => 'https://fake/bookings/appt_1'],
    ];
}

function attendee_collection_payload(): array
{
    return [
        'attendees' => [[
            'id' => 'att_1',
            'links' => ['self' => 'https://fake/attendees/att_1'],
        ]],
    ];
}

function available_times_payload(): array
{
    return [
        'available_times' => [[
            'appointment_start' => '2026-02-20T03:00:00Z',
        ]],
        'total_entries' => 1,
        'links' => ['self' => 'https://fake/available_times/page_1'],
    ];
}

function practitioners_payload(): array
{
    return [
        'practitioners' => [[
            'id' => '1547537765724333824',
            'first_name' => 'Doctor',
            'last_name' => 'One',
            'display_name' => 'Doctor One',
            'active' => true,
            'show_in_online_bookings' => true,
            'created_at' => '2026-02-10T00:00:00Z',
            'updated_at' => '2026-02-10T00:00:00Z',
            'links' => ['self' => 'https://fake/practitioners/1547537765724333824'],
        ]],
    ];
}

function reset_state(): void
{
    $GLOBALS['__wp_options'] = [
        'wp_cliniko_business_id' => 'business_1',
        'wp_cliniko_api_cache_ttl' => 0,
    ];
    $GLOBALS['__wp_transients'] = [];
    $GLOBALS['__scheduled_actions'] = [];
    $GLOBALS['__attempt_fake_client'] = new AttemptFakeApiClient();

    /** @var AttemptFakeApiClient $client */
    $client = $GLOBALS['__attempt_fake_client'];
    $client->responses = [
        'GET appointment_types/222' => appointment_type_payload(),
        'GET patient_form_templates/999' => patient_template_payload(),
        'GET https://fake/appointment_types/222/practitioners' => practitioners_payload(),
        'GET businesses/business_1/practitioners/1547537765724333824/appointment_types/222/available_times?from=2026-02-20&to=2026-02-20&page=1&per_page=100' => available_times_payload(),
        'GET patients?q[]=email:=john%40example.com&q[]=first_name:=John&q[]=last_name:=Doe' => ['patients' => []],
        'POST patients' => patient_payload(),
        'POST patient_forms' => patient_form_payload(),
        'PUT patients/patient_1' => patient_payload(),
        'POST individual_appointments' => appointment_payload(),
        'GET bookings/appt_1' => booking_payload(),
        'GET https://fake/bookings/appt_1/attendees' => attendee_collection_payload(),
        'PATCH patient_forms/pf_1' => array_merge(patient_form_payload(), ['id' => 'pf_1']),
        'GET patient_forms/pf_1' => attached_patient_form_payload(),
        'POST patient_forms/pf_1/archive' => [],
        'POST patients/patient_1/archive' => [],
    ];
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message . ' | expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
        );
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_preflight_creates_attempt_and_finalize_attaches_form(): void
{
    $service = new BookingAttemptService(new BookingAttemptStore());
    $preflight = $service->preflight(base_payload());

    assert_same(true, $preflight['ok'], 'Preflight should succeed');
    assert_same(false, $preflight['payment']['required'], 'Free attempt should skip payment');
    assert_same(1, count($GLOBALS['__scheduled_actions']), 'Preflight should schedule one cleanup job');
    assert_true(!empty($preflight['attempt']['token']), 'Preflight should return an attempt token');

    $attemptId = (string) $preflight['attempt']['id'];
    $finalize = $service->finalize($attemptId);

    assert_same(true, $finalize['ok'], 'Finalize should succeed');
    assert_same('appt_1', $finalize['result']['booking']['appointment_id'], 'Appointment id mismatch');
    assert_same('att_1', $finalize['result']['booking']['attendee_id'], 'Attendee id mismatch');

    $attempt = (new BookingAttemptStore())->get($attemptId);
    assert_same('completed', $attempt['status'], 'Attempt should be marked completed');

    /** @var AttemptFakeApiClient $client */
    $client = $GLOBALS['__attempt_fake_client'];
    assert_true(in_array('POST patient_forms', $client->calls, true), 'Expected patient form draft creation');
    assert_true(in_array('PATCH patient_forms/pf_1', $client->calls, true), 'Expected patient form attach patch');
}

function test_cleanup_worker_archives_orphans_for_abandoned_attempt(): void
{
    $service = new BookingAttemptService(new BookingAttemptStore());
    $preflight = $service->preflight(base_payload());
    $attemptId = (string) $preflight['attempt']['id'];

    BookingAttemptCleanupWorker::handle(['attempt_id' => $attemptId]);

    $attempt = (new BookingAttemptStore())->get($attemptId);
    assert_same('cleaned', $attempt['status'], 'Attempt should be marked cleaned');

    /** @var AttemptFakeApiClient $client */
    $client = $GLOBALS['__attempt_fake_client'];
    assert_true(in_array('POST patient_forms/pf_1/archive', $client->calls, true), 'Expected orphan patient form archive');
    assert_true(in_array('POST patients/patient_1/archive', $client->calls, true), 'Expected orphan patient archive');
}

function test_preflight_validation_errors_include_summary_and_code(): void
{
    $service = new BookingAttemptService(new BookingAttemptStore());
    $payload = base_payload();
    $payload['moduleId'] = '';
    $payload['patient']['appointment_start'] = '';

    $result = $service->preflight($payload);

    assert_same(false, $result['ok'], 'Preflight should fail');
    assert_same(422, $result['status'], 'Validation status mismatch');
    assert_same('preflight_validation_failed', $result['code'], 'Validation code mismatch');
    assert_true(!empty($result['detail']), 'Validation detail should be present');
    assert_true(str_contains($result['detail'], 'Module'), 'Validation detail should mention module');
    assert_true(str_contains($result['detail'], 'Appointment Start'), 'Validation detail should mention appointment start');
    assert_true(is_array($result['errors'] ?? null) && count($result['errors']) >= 2, 'Validation errors should be included');
}

function test_preflight_unsets_empty_optional_text_answers_before_cliniko_submit(): void
{
    $service = new BookingAttemptService(new BookingAttemptStore());
    $payload = base_payload();
    $payload['content']['sections'][0]['questions'][] = [
        'name' => 'Optional Notes',
        'type' => 'text',
        'required' => false,
        'answer' => '',
    ];

    $result = $service->preflight($payload);

    assert_same(true, $result['ok'], 'Preflight should succeed');

    /** @var AttemptFakeApiClient $client */
    $client = $GLOBALS['__attempt_fake_client'];
    $requests = $client->requests['POST patient_forms'] ?? [];
    assert_true(!empty($requests), 'Expected patient form draft request payload');

    $submittedQuestions = $requests[0]['content']['sections'][0]['questions'] ?? [];
    assert_true(is_array($submittedQuestions), 'Submitted questions should be an array');

    $found = null;
    foreach ($submittedQuestions as $question) {
        if (($question['name'] ?? '') === 'Optional Notes') {
            $found = $question;
            break;
        }
    }

    assert_true(is_array($found), 'Optional question should still be present');
    assert_true(!array_key_exists('answer', $found), 'Optional empty answer should have been removed');
}

function run_test(string $name, callable $test): bool
{
    reset_state();

    try {
        $test();
        echo "[PASS] {$name}\n";
        return true;
    } catch (Throwable $e) {
        echo "[FAIL] {$name}: " . get_class($e) . ': ' . $e->getMessage() . " ({$e->getFile()}:{$e->getLine()})\n";
        return false;
    }
}

$tests = [
    'preflight_creates_attempt_and_finalize_attaches_form' => 'test_preflight_creates_attempt_and_finalize_attaches_form',
    'cleanup_worker_archives_orphans_for_abandoned_attempt' => 'test_cleanup_worker_archives_orphans_for_abandoned_attempt',
    'preflight_validation_errors_include_summary_and_code' => 'test_preflight_validation_errors_include_summary_and_code',
    'preflight_unsets_empty_optional_text_answers_before_cliniko_submit' => 'test_preflight_unsets_empty_optional_text_answers_before_cliniko_submit',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    if (run_test($name, $fn)) {
        $passed++;
    }
}

$total = count($tests);
echo "\n{$passed}/{$total} booking attempt tests passed.\n";

exit($passed === $total ? 0 : 1);
