<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Model\Patient;
use App\Model\PatientFormTemplate;
use App\Service\BookingAttemptService;
use App\Service\BookingAttemptStore;
use App\Service\PatientCustomFieldService;
use App\Widgets\ClinikoForm\ReturnPolicySettingsRegistry;
use App\Workers\BookingAttemptCleanupWorker;
use App\Workers\BookingReturnHistoryWorker;

if (!function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['__wp_options'][$name] ?? $default;
    }
}

function wp_is_post_revision(int $id): bool
{
    return false;
}

function sanitize_key(string $key): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower($key));
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'booking-attempt-test-salt';
}

final class wpdb
{
    public string $options = 'test_options';

    public function prepare(string $query, string $key): string
    {
        return str_replace('%s', "'" . $key . "'", $query);
    }

    public function get_var(string $query): string
    {
        return '1';
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
    /** @var array<string,array<string,mixed>|callable(array):array> */
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

        $response = $this->responses[$key];
        return new ClientResponse(is_callable($response) ? $response($data) : $response);
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
            'date_of_birth' => '1990-05-10',
            'practitioner_id' => '1547537765724333824',
            'appointment_start' => '2026-02-20T03:00:00Z',
            'medicare' => '1234 56789 0',
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

function payload_with_cliniko_custom_fields(): array
{
    $payload = base_payload();
    $payload['headless_patient_fields'] = [
        [
            'key' => 'allergies',
            'label' => 'Allergies',
            'path' => 'custom_fields.allergies',
            'type' => 'text',
            'required' => true,
            'cliniko_section_name' => 'Medical history',
            'cliniko_section_token' => 'section-token-1',
            'cliniko_field_name' => 'Allergies',
            'cliniko_field_token' => 'field-token-1',
            'cliniko_field_type' => 'text',
            'validation' => [
                'type' => 'none',
            ],
        ],
    ];
    $payload['patient']['custom_fields'] = [
        'allergies' => 'Peanuts',
    ];

    return $payload;
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
        'date_of_birth' => '1990-05-10',
        'patient_phone_numbers' => [['number' => '0412345678']],
        'appointments' => ['links' => ['self' => 'https://fake/patients/patient_1/appointments']],
        'links' => ['self' => 'https://fake/patients/patient_1'],
    ];
}

function patient_form_payload(): array
{
    return [
        'id' => 'pf_1',
        'name' => 'Consult Template appointment',
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
    $definitions = new ReflectionProperty(PatientCustomFieldService::class, 'clinikoCustomFieldSettingsCache');
    $definitions->setAccessible(true);
    $definitions->setValue(null, null);
    $GLOBALS['wpdb'] = new wpdb();
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

    $createdForms = $client->requests['POST patient_forms'] ?? [];
    assert_true(!empty($createdForms), 'Expected patient form draft request payload');
    assert_true(
        !array_key_exists('email_to_patient_on_completion', $createdForms[0]),
        'Draft patient form create must not set email_to_patient_on_completion'
    );

    $patchedForms = $client->requests['PATCH patient_forms/pf_1'] ?? [];
    assert_true(!empty($patchedForms), 'Expected patient form attach patch payload');
    assert_true(
        !array_key_exists('email_to_patient_on_completion', $patchedForms[0]),
        'Patient form attach patch must not set email_to_patient_on_completion'
    );
}

function test_preflight_maps_headless_custom_fields_into_cliniko_payload(): void
{
    $service = new BookingAttemptService(new BookingAttemptStore());
    $preflight = $service->preflight(payload_with_cliniko_custom_fields());

    assert_same(true, $preflight['ok'], 'Preflight should succeed');

    /** @var AttemptFakeApiClient $client */
    $client = $GLOBALS['__attempt_fake_client'];
    $requests = $client->requests['POST patients'] ?? [];
    assert_true(!empty($requests), 'Expected patient create request payload');

    $patientCreate = $requests[0];
    $customFields = $patientCreate['custom_fields'] ?? [];

    assert_same('Medical history', $customFields['sections'][0]['name'] ?? null, 'Section name mismatch');
    assert_same('section-token-1', $customFields['sections'][0]['token'] ?? null, 'Section token mismatch');
    assert_same('Allergies', $customFields['sections'][0]['fields'][0]['name'] ?? null, 'Field name mismatch');
    assert_same('field-token-1', $customFields['sections'][0]['fields'][0]['token'] ?? null, 'Field token mismatch');
    assert_same('text', $customFields['sections'][0]['fields'][0]['type'] ?? null, 'Field type mismatch');
    assert_same('Peanuts', $customFields['sections'][0]['fields'][0]['value'] ?? null, 'Field value mismatch');
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
    assert_true(in_array('POST patient_forms/pf_1/archive', $client->calls, true), 'Expected backend orphan patient form archive');
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

function return_policy_payload(AttemptFakeApiClient $client): array
{
    $payload = base_payload();
    $payload['content']['sections'][0]['questions'][0]['answer'] = 'medication   a';
    $template = patient_template_payload();
    $template['content'] = $payload['content'];
    $client->responses['GET patient_form_templates/999'] = $template;
    $templateModel = PatientFormTemplate::find('999', $client);
    $questionId = array_key_first(ReturnPolicySettingsRegistry::questions($templateModel));
    ReturnPolicySettingsRegistry::syncElementorPage(12, [[
        'id' => 'policy-widget',
        'widgetType' => 'cliniko_stripe_payment',
        'settings' => [
            'return_policy_enabled' => 'yes',
            'cliniko_form_template_id' => '999',
            'module_id' => '222',
            'custom_form_payment' => 'tyrohealth',
            'return_policy_appointment_type' => '223',
            'return_policy_history_field' => 'clinic-history',
            ReturnPolicySettingsRegistry::questionControl('999') => $questionId,
        ],
    ]]);
    $payload['return_policy_id'] = ReturnPolicySettingsRegistry::contextId(12, 'policy-widget');
    $client->responses[return_policy_guest_lookup($payload['patient']['email'])] = ['patients' => []];
    $client->responses['GET patients/patient_1'] = patient_payload() + [
        'custom_fields' => ['sections' => [[
            'token' => 'history',
            'fields' => [['token' => 'clinic-history', 'type' => 'paragraph', 'value' => "Medication B\r\n  Medication A  "]],
        ]]],
    ];

    foreach (['222' => '90.00', '223' => '60.00'] as $typeId => $price) {
        $typeId = (string) $typeId;
        $practitionerUrl = 'https://fake/appointment_types/' . $typeId . '/practitioners';
        $itemsUrl = 'https://fake/appointment_types/' . $typeId . '/appointment_type_billable_items';
        $itemUrl = 'https://fake/billable_items/' . $typeId;
        $client->responses['GET appointment_types/' . $typeId] = array_replace(appointment_type_payload(), [
            'id' => $typeId,
            'practitioners' => ['links' => ['self' => $practitionerUrl]],
            'appointment_type_billable_items' => ['links' => ['self' => $itemsUrl]],
        ]);
        $client->responses['GET ' . $practitionerUrl] = practitioners_payload();
        $client->responses['GET ' . $itemsUrl] = [
            'appointment_type_billable_items' => [[
                'id' => $typeId,
                'quantity' => 1,
                'created_at' => '2026-02-01T00:00:00Z',
                'updated_at' => '2026-02-01T00:00:00Z',
                'billable_item' => ['links' => ['self' => $itemUrl]],
            ]],
        ];
        $client->responses['GET ' . $itemUrl] = [
            'id' => $typeId,
            'price' => $price,
            'created_at' => '2026-02-01T00:00:00Z',
            'updated_at' => '2026-02-01T00:00:00Z',
        ];
        $client->responses['GET businesses/business_1/practitioners/1547537765724333824/appointment_types/'
            . $typeId . '/available_times?from=2026-02-20&to=2026-02-20&page=1&per_page=100'] = available_times_payload();
    }

    return $payload;
}

function test_return_policy_uses_verified_history_for_quote_and_booking(): void
{
    $client = $GLOBALS['__attempt_fake_client'];
    $payload = return_policy_payload($client);
    $service = new BookingAttemptService();
    $guest = $service->quoteReturnPolicy($payload + ['patient_id' => 'patient_1']);
    assert_same('222', $guest['quote']['appointment_type_id'], 'Guest input cannot claim a verified patient');
    assert_same(false, $guest['quote']['patient_verified'], 'The quote should show that guest pricing applies');
    assert_same(9000, $guest['quote']['amount_cents'], 'The first-time price should be quoted from Cliniko');

    $verified = ['id' => 'patient_1'];
    $result = $service->preflight($payload + ['return_policy_quote' => $guest['quote']], $verified, 10);
    assert_same('return_policy_quote_changed', $result['code'], 'Preflight must reject an outdated price');
    assert_same('223', $result['quote']['appointment_type_id'], 'The whole-line history match should choose the return type');
    assert_same(true, $result['quote']['patient_verified'], 'A verified quote should identify the authenticated patient context');
    assert_same(6000, $result['quote']['amount_cents'], 'The matched price must come from the return appointment type');
    assert_true(!isset($client->requests['POST patient_forms']), 'A stale quote must not create a patient form');

    $payload['content']['sections'][0]['questions'][0]['custom_field_token'] = 'clinic-history';
    $payload['content']['sections'][0]['questions'][0]['patient_field_connection'] = 'custom_field';
    $preflight = $service->preflight($payload + ['return_policy_quote' => $result['quote']], $verified, 10);
    assert_same(true, $preflight['ok'], 'The updated quote should be accepted');
    assert_same('tyrohealth', $preflight['payment']['gateway'], 'The saved widget gateway should control the payment');
    assert_same(6000, $preflight['payment']['amount'], 'The attempt must store the selected type price');
    $question = $client->requests['POST patient_forms'][0]['content']['sections'][0]['questions'][0];
    assert_true(!isset($question['custom_field_token']), 'A submitted form cannot connect its answer to protected history');
    $store = new BookingAttemptStore();
    $attemptId = $preflight['attempt']['id'];
    $store->update($attemptId, ['payment' => ['status' => 'verified']]);
    assert_same(true, $service->finalize($attemptId)['ok'], 'The verified attempt should finalize');
    $appointment = $client->requests['POST individual_appointments'][0];
    assert_same('223', $appointment['appointment_type_id'], 'Finalization must use the type priced in the attempt');
    assert_true(!isset($client->requests['PATCH patients/patient_1']), 'A matching line should not be appended again');
}

function test_return_policy_rejects_direct_type_selection_and_partial_matches(): void
{
    $client = $GLOBALS['__attempt_fake_client'];
    $payload = return_policy_payload($client);
    $service = new BookingAttemptService();
    $direct = $payload;
    $direct['moduleId'] = '223';
    unset($direct['return_policy_id']);
    assert_same(403, $service->preflight($direct)['status'], 'The public flow must block a direct return type request');

    $payload['content']['sections'][0]['questions'][0]['answer'] = 'Medication';
    $result = $service->preflight($payload, ['id' => 'patient_1'], 10);
    assert_same('222', $result['quote']['appointment_type_id'], 'A substring must not select the return type');

    unset($client->responses['GET https://fake/billable_items/222']);
    $result = $service->quoteReturnPolicy($payload);
    assert_same(503, $result['status'], 'A missing Cliniko price must not create a free quote');
}

function test_return_policy_history_is_preserved_on_patient_update(): void
{
    $client = $GLOBALS['__attempt_fake_client'];
    return_policy_payload($client);
    Patient::update('patient_1', [
        'custom_fields' => ['sections' => [[
            'token' => 'history',
            'fields' => [
                ['token' => 'clinic-history', 'value' => 'Submitted history'],
                ['token' => 'clinic-other', 'value' => 'Other value'],
            ],
        ]]],
    ], $client);
    $fields = $client->requests['PUT patients/patient_1'][0]['custom_fields']['sections'][0]['fields'];
    assert_same('Other value', $fields[0]['value'], 'Ordinary fields should still be editable');
    assert_same("Medication B\r\n  Medication A  ", $fields[1]['value'], 'Clinic history must come from the current record');
    Patient::update('patient_1', ['custom_fields' => null], $client);
    assert_true(
        !array_key_exists('custom_fields', $client->requests['PUT patients/patient_1'][1]),
        'A null field update must not erase the clinic history'
    );
}

function mock_patient_history_write(AttemptFakeApiClient $client): void
{
    $client->responses['PATCH patients/patient_1'] = static function (array $payload) use ($client): array {
        $patient = $client->responses['GET patients/patient_1'];
        $patient['custom_fields'] = $payload['custom_fields'];
        $client->responses['GET patients/patient_1'] = $patient;
        return $patient;
    };
}

function return_policy_guest_address(): array
{
    return [
        'address_1' => '22 West Street',
        'address_2' => 'Unit 3',
        'city' => 'Brisbane',
        'state' => 'QLD',
        'post_code' => '4000',
        'country' => 'Australia',
    ];
}

function return_policy_guest_lookup(string $email): string
{
    return 'GET patients?per_page=100&q[]=' . rawurlencode('email:=' . $email) . '&q[]=' . rawurlencode('archived_at:!?');
}

function test_successful_booking_appends_the_answer_and_qualifies_for_return_price(): void
{
    $client = $GLOBALS['__attempt_fake_client'];
    $payload = return_policy_payload($client);
    $payload['content']['sections'][0]['questions'][0]['answer'] = '  Medication   C  ';
    $existingField = ['token' => 'clinic-notes', 'type' => 'text', 'value' => 'Preserve this'];
    $client->responses['GET patients/patient_1']['custom_fields']['sections'][0]['fields'][] = $existingField;
    $client->responses[return_policy_guest_lookup($payload['patient']['email'])] = ['patients' => [patient_payload()]];
    mock_patient_history_write($client);

    $store = new BookingAttemptStore();
    $service = new BookingAttemptService($store);
    $quote = $service->quoteReturnPolicy($payload)['quote'];
    $preflight = $service->preflight($payload + ['return_policy_quote' => $quote]);
    assert_same(true, $preflight['ok'], 'A new answer should be accepted at the regular price');
    $attemptId = $preflight['attempt']['id'];
    assert_same(false, $service->finalize($attemptId)['ok'], 'An unpaid booking should not finalize');
    assert_true(!isset($client->requests['PATCH patients/patient_1']), 'Preflight and failed finalization must not update the field');

    $store->update($attemptId, ['payment' => ['status' => 'verified']]);
    assert_same(true, $service->finalize($attemptId)['ok'], 'The confirmed booking should finalize');
    assert_true(!isset($client->requests['PUT patients/patient_1']), 'Guest history updates should preserve the saved profile');
    $fields = $client->responses['GET patients/patient_1']['custom_fields']['sections'][0]['fields'];
    assert_same("Medication B\r\n  Medication A  \nMedication C", $fields[0]['value'], 'Existing lines and the new answer should be stored');
    assert_same($existingField, $fields[1], 'Unrelated custom fields should be preserved');
    assert_same(null, $store->get($attemptId)['return_policy_history'], 'The verified write should be marked complete');
    assert_same(true, $service->finalize($attemptId)['ok'], 'Repeating finalization remains successful');
    assert_same(1, count($client->requests['PATCH patients/patient_1']), 'Repeating finalization should not append again');

    $payload['content']['sections'][0]['questions'][0]['answer'] = 'medication c';
    $next = $service->quoteReturnPolicy($payload);
    assert_same('223', $next['quote']['appointment_type_id'], 'The same answer should qualify for return pricing next time');
}

function test_successful_guest_booking_writes_an_empty_field_and_retries_cliniko_failure(): void
{
    $client = $GLOBALS['__attempt_fake_client'];
    $payload = return_policy_payload($client);
    $payload['patient'] += return_policy_guest_address();
    $lookup = return_policy_guest_lookup($payload['patient']['email']);
    $client->responses[$lookup] = ['patients' => []];
    $client->responses['GET patients/patient_1'] = patient_payload() + return_policy_guest_address();
    $client->responses['GET settings/public'] = [
        'patient_custom_fields_definition' => ['sections' => [[
            'token' => 'history',
            'name' => 'History',
            'fields' => [['token' => 'clinic-history', 'name' => 'Patient Medication History', 'type' => 'paragraph']],
        ]]],
    ];

    $store = new BookingAttemptStore();
    $service = new BookingAttemptService($store);
    $quote = $service->quoteReturnPolicy($payload)['quote'];
    $preflight = $service->preflight($payload + ['return_policy_quote' => $quote]);
    assert_same(true, $preflight['ok'], 'A guest should be able to book at the regular price');
    $attemptId = $preflight['attempt']['id'];
    $store->update($attemptId, ['payment' => ['status' => 'verified']]);
    assert_same(true, $service->finalize($attemptId)['ok'], 'A failed history write should leave the booking confirmed');
    assert_same('completed', $store->get($attemptId)['status'], 'The append should not change the booking state');
    $job = $GLOBALS['__scheduled_actions'][1];
    assert_same(BookingReturnHistoryWorker::HOOK, $job['hook'], 'The history write should be retried');
    assert_same($attemptId, $job['args'][0]['attempt_id'], 'The retry should carry the attempt ID');

    mock_patient_history_write($client);
    $store->update($attemptId, ['return_policy_history_retry' => ['at' => 0]]);
    BookingReturnHistoryWorker::handle($job['args'][0]);
    $history = $client->responses['GET patients/patient_1']['custom_fields']['sections'][0];
    assert_same('history', $history['token'], 'The section should come from the configured Cliniko definition');
    assert_same('clinic-history', $history['fields'][0]['token'], 'The first answer should be stored in the configured field');
    assert_same('medication a', $history['fields'][0]['value'], 'The successful guest booking should seed the paragraph');
    BookingReturnHistoryWorker::handle($job['args'][0]);
    assert_same(2, count($client->requests['PATCH patients/patient_1']), 'Only the original write and its retry should reach Cliniko');
    $client->responses[$lookup] = ['patients' => [$client->responses['GET patients/patient_1']]];
    assert_same(6000, $service->quoteReturnPolicy($payload)['quote']['amount_cents'], 'The next guest booking should qualify');
}

function test_guest_return_price_uses_one_contact_match(): void
{
    $client = $GLOBALS['__attempt_fake_client'];
    $payload = return_policy_payload($client);
    $email = $payload['patient']['email'];
    $address = return_policy_guest_address();
    $patient = $client->responses['GET patients/patient_1'] + $address;
    $client->responses['GET patients/patient_1'] = $patient;
    $lookup = return_policy_guest_lookup($email);
    $client->responses[$lookup] = ['patients' => [
        $patient,
        array_replace($patient, ['id' => 'patient_2', 'date_of_birth' => '1990-05-11']),
    ]];
    $payload['patient'] = array_replace($payload['patient'], $address, [
        'email' => '  ' . strtoupper($email) . '  ',
        'first_name' => '  JOHN  ',
        'date_of_birth' => '10/05/1990',
        'address_1' => '22   west street',
        'country' => 'AU',
    ]);

    $service = new BookingAttemptService();
    $quoted = $service->quoteReturnPolicy($payload);
    assert_same(6000, $quoted['quote']['amount_cents'], 'Matching names, email, date of birth and history should get the return price');
    assert_same(true, $quoted['quote']['return_pricing_applied'], 'The public quote should identify when return pricing applies');
    assert_same(false, $quoted['quote']['patient_verified'], 'A guest match should not grant a verified account');
    assert_same(['ok', 'status', 'quote'], array_keys($quoted), 'The quote route should disclose only public quote fields');
    $payload['return_policy_quote'] = $quoted['quote'];

    $otherEmail = 'other@example.test';
    $client->responses[return_policy_guest_lookup($otherEmail)] = ['patients' => [$patient]];
    foreach (['email' => $otherEmail, 'first_name' => 'Other', 'last_name' => 'Jones', 'date_of_birth' => '1990-05-12'] as $field => $value) {
        $wrong = $payload;
        $wrong['patient'][$field] = $value;
        assert_same(9000, $service->quoteReturnPolicy($wrong)['quote']['amount_cents'], "A different {$field} should get the regular price");
    }
    $wrong = $payload;
    $wrong['patient']['date_of_birth'] = '';
    $regular = $service->quoteReturnPolicy($wrong)['quote'];
    assert_same(9000, $regular['amount_cents'], 'A guest missing a date of birth should get the regular price');
    assert_same(false, $regular['return_pricing_applied'], 'A regular quote should not show a return-price notice');

    $client->responses[$lookup]['patients'][] = array_replace($patient, ['id' => 'duplicate', 'address_2' => 'Unit 6']);
    assert_same(9000, $service->quoteReturnPolicy($payload)['quote']['amount_cents'], 'Multiple full matches should get the regular price');
    array_pop($client->responses[$lookup]['patients']);

    $wrong = $payload;
    $wrong['patient']['date_of_birth'] = '1990-05-12';
    assert_same('return_policy_quote_changed', $service->preflight($wrong)['code'], 'Preflight must reject a lower price when contact details change');
    assert_true(!isset($client->requests['POST patient_forms']), 'Quote lookups and stale preflight should not create a patient form');

    $preflight = $service->preflight($payload);
    assert_same(true, $preflight['ok'], 'Guest preflight should accept the matched quote');
    $attempt = (new BookingAttemptStore())->get($preflight['attempt']['id']);
    assert_same('patient_1', $attempt['patient_id'], 'The attempt must use the matching Cliniko patient');
    assert_same(null, $attempt['user_id'], 'Guest pricing should not require a WordPress account');
}

function test_guest_address_is_independent_of_pricing_and_profile_updates(): void
{
    $client = $GLOBALS['__attempt_fake_client'];
    $payload = return_policy_payload($client);
    $address = return_policy_guest_address();
    $patient = $client->responses['GET patients/patient_1'] + $address;
    $client->responses['GET patients/patient_1'] = $patient;
    $client->responses[return_policy_guest_lookup($patient['email'])] = ['patients' => [$patient]];

    $store = new BookingAttemptStore();
    $service = new BookingAttemptService($store);
    $quote = $service->quoteReturnPolicy($payload)['quote'];
    assert_same(6000, $quote['amount_cents'], 'The return quote should work without an address');

    $payload['patient'] += array_replace($address, ['address_1' => '24 West Street', 'address_2' => 'Unit 4']);
    $preflight = $service->preflight($payload + ['return_policy_quote' => $quote]);
    assert_same(true, $preflight['ok'], 'Preflight should accept the guest quote with a different address');
    assert_same(6000, $preflight['payment']['amount'], 'The address should not affect the return price');
    $attemptId = $preflight['attempt']['id'];
    assert_same('24 West Street', $store->get($attemptId)['patient']['address_1'], 'Booking should keep the submitted address');
    $store->update($attemptId, ['payment' => ['status' => 'verified']]);
    assert_same(true, $service->finalize($attemptId)['ok'], 'The matched guest booking should finalize');
    assert_true(!isset($client->requests['PUT patients/patient_1']), 'The existing Cliniko profile should be preserved');
}

function test_guest_with_ambiguous_match_can_book_without_changing_the_profile(): void
{
    $client = $GLOBALS['__attempt_fake_client'];
    $payload = return_policy_payload($client);
    $patient = $client->responses['GET patients/patient_1'] + return_policy_guest_address();
    $client->responses['GET patients/patient_1'] = $patient;
    $client->responses[return_policy_guest_lookup($patient['email'])] = ['patients' => [
        $patient,
        array_replace($patient, ['id' => 'duplicate', 'address_1' => '24 West Street']),
    ]];
    $client->responses['GET patients?q[]=email:=john%40example.com&q[]=first_name:=John&q[]=last_name:=Doe'] = ['patients' => [$patient]];
    $payload['patient'] += array_replace(return_policy_guest_address(), ['address_1' => '24 West Street']);

    $store = new BookingAttemptStore();
    $service = new BookingAttemptService($store);
    $payload['return_policy_quote'] = $service->quoteReturnPolicy($payload)['quote'];
    $preflight = $service->preflight($payload);
    assert_same(9000, $preflight['payment']['amount'], 'An ambiguous match can proceed at the regular price');
    $attemptId = $preflight['attempt']['id'];
    $store->update($attemptId, ['payment' => ['status' => 'verified']]);
    assert_same(true, $service->finalize($attemptId)['ok'], 'A regular-priced guest booking should still finalize');
    assert_true(!isset($client->requests['PUT patients/patient_1']), 'The saved address and patient profile should be preserved');
    assert_true(!isset($client->requests['PATCH patients/patient_1']), 'An unmatched guest should not write to the existing history');
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
    'preflight_maps_headless_custom_fields_into_cliniko_payload' => 'test_preflight_maps_headless_custom_fields_into_cliniko_payload',
    'cleanup_worker_archives_orphans_for_abandoned_attempt' => 'test_cleanup_worker_archives_orphans_for_abandoned_attempt',
    'preflight_validation_errors_include_summary_and_code' => 'test_preflight_validation_errors_include_summary_and_code',
    'preflight_unsets_empty_optional_text_answers_before_cliniko_submit' => 'test_preflight_unsets_empty_optional_text_answers_before_cliniko_submit',
    'return_policy_uses_verified_history_for_quote_and_booking' => 'test_return_policy_uses_verified_history_for_quote_and_booking',
    'return_policy_rejects_direct_type_selection_and_partial_matches' => 'test_return_policy_rejects_direct_type_selection_and_partial_matches',
    'return_policy_history_is_preserved_on_patient_update' => 'test_return_policy_history_is_preserved_on_patient_update',
    'successful_booking_appends_the_answer_and_qualifies_for_return_price' => 'test_successful_booking_appends_the_answer_and_qualifies_for_return_price',
    'successful_guest_booking_writes_an_empty_field_and_retries_cliniko_failure' => 'test_successful_guest_booking_writes_an_empty_field_and_retries_cliniko_failure',
    'guest_return_price_uses_one_contact_match' => 'test_guest_return_price_uses_one_contact_match',
    'guest_address_is_independent_of_pricing_and_profile_updates' => 'test_guest_address_is_independent_of_pricing_and_profile_updates',
    'guest_with_ambiguous_match_can_book_without_changing_the_profile' => 'test_guest_with_ambiguous_match_can_book_without_changing_the_profile',
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
