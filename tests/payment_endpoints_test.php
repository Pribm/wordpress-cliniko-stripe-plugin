<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Controller\ClinikoController;
use App\Controller\PaymentController;
use App\Controller\TyroController;

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private string $body = '';

        /** @var array<string,mixed> */
        private array $params = [];

        public function __construct(string $method = 'POST', string $route = '')
        {
            // keep constructor signature compatible with WP and consume params for static analysis
            if ($method === '' && $route === '') {
                $this->body = '';
            }
        }

        public function set_body(string $body): void
        {
            $this->body = $body;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        /**
         * @param array<string,mixed> $params
         */
        public function set_params(array $params): void
        {
            $this->params = $params;
        }

        /**
         * @return array<string,mixed>
         */
        public function get_params(): array
        {
            return $this->params;
        }

        /**
         * @return mixed|null
         */
        public function get_param(string $key)
        {
            return array_key_exists($key, $this->params) ? $this->params[$key] : null;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var mixed */
        private $data;
        private int $status;
        /** @var array<string,string> */
        private array $headers = [];

        /**
         * @param mixed $data
         */
        public function __construct($data = null, int $status = 200)
        {
            $this->data = $data;
            $this->status = $status;
        }

        /**
         * @return mixed
         */
        public function get_data()
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function header(string $key, string $value, bool $replace = true): void
        {
            if (!$replace && array_key_exists($key, $this->headers)) {
                $this->headers[$key] .= ', ' . $value;
                return;
            }

            $this->headers[$key] = $value;
        }
    }
}

if (!function_exists('get_option')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['__wp_options'][$name] ?? $default;
    }
}

if (!function_exists('add_option')) {
    /**
     * @param mixed $value
     */
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
    /**
     * @param mixed $value
     */
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
    /**
     * @return mixed
     */
    function get_transient(string $name)
    {
        return $GLOBALS['__wp_transients'][$name] ?? false;
    }
}

if (!function_exists('set_transient')) {
    /**
     * @param mixed $value
     */
    function set_transient(string $name, $value, int $ttl): bool
    {
        $GLOBALS['__wp_transients'][$name] = $value;
        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value): string
    {
        return trim((string) $value);
    }
}

if (!function_exists('wp_json_encode')) {
    /**
     * @param mixed $value
     */
    function wp_json_encode($value): string
    {
        return (string) json_encode($value);
    }
}

if (!function_exists('as_schedule_single_action')) {
    /**
     * @param array<int,mixed> $args
     */
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

if (!function_exists('wp_schedule_single_event')) {
    /**
     * @param array<int,mixed> $args
     */
    function wp_schedule_single_event(int $when, string $hook, array $args): bool
    {
        $GLOBALS['__scheduled_actions'][] = [
            'when' => $when,
            'hook' => $hook,
            'args' => $args,
            'group' => 'wp-cron',
        ];

        return true;
    }
}

final class FakeApiClient implements ApiClientInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $responsesByUrl = [];

    /** @var array<int,string> */
    public array $calls = [];

    public function get(string $url): ClientResponse
    {
        $this->calls[] = 'GET ' . $url;

        if (!array_key_exists($url, $this->responsesByUrl)) {
            return new ClientResponse(null, 'No fake response configured for ' . $url);
        }

        return new ClientResponse($this->responsesByUrl[$url]);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function post(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not implemented in tests');
    }

    /**
     * @param array<string,mixed> $data
     */
    public function put(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not implemented in tests');
    }

    /**
     * @param array<string,mixed> $data
     */
    public function patch(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not implemented in tests');
    }
}

if (!function_exists('cliniko_client')) {
    function cliniko_client(bool $withCache = false, int $ttl = 300): ApiClientInterface
    {
        $GLOBALS['__cliniko_client_calls'][] = [
            'withCache' => $withCache,
            'ttl' => $ttl,
        ];

        return $GLOBALS['__fake_cliniko_client'];
    }
}

/**
 * @param array<string,mixed> $payload
 */
function make_request(array $payload): WP_REST_Request
{
    $request = new WP_REST_Request('POST', '/v1/test');
    $request->set_body((string) json_encode($payload));
    $request->set_params($payload);
    return $request;
}

/**
 * @return array<string,mixed>
 */
function base_patient(): array
{
    return [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'practitioner_id' => '1547537765724333824',
        'medicare' => '1234 56789',
        'medicare_reference_number' => '1',
        'phone' => '0412345678',
    ];
}

/**
 * @return array<string,mixed>
 */
function appointment_type_payload(string $id, string $name, ?string $billableItemsUrl): array
{
    $payload = [
        'id' => $id,
        'name' => $name,
        'description' => 'Test appointment type',
        'category' => 'General',
        'color' => '#0099ff',
        'duration_in_minutes' => 30,
        'telehealth_enabled' => false,
        'show_in_online_bookings' => true,
        'online_payments_enabled' => true,
        'online_payments_mode' => 'required',
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
    ];

    if ($billableItemsUrl !== null) {
        $payload['appointment_type_billable_items'] = [
            'links' => ['self' => $billableItemsUrl],
        ];
    }

    return $payload;
}

/**
 * @return array<string,mixed>
 */
function paid_billable_items_payload(): array
{
    return [
        'appointment_type_billable_items' => [[
            'id' => 'atbi_1',
            'quantity' => 1,
            'discounted_amount' => null,
            'discount_percentage' => null,
            'is_monetary_discount' => false,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'appointment_type' => ['links' => ['self' => 'https://fake/appointment_types/123']],
            'billable_item' => ['links' => ['self' => 'https://fake/billable_items/1']],
            'links' => ['self' => 'https://fake/appointment_type_billable_items/123/atbi_1'],
        ]],
    ];
}

/**
 * @return array<string,mixed>
 */
function paid_billable_item_payload(): array
{
    return [
        'id' => 'bi_1',
        'name' => 'Consult',
        'item_code' => 'CONSULT',
        'item_type' => 'service',
        'price' => 125.00,
        'created_at' => '2026-01-01T00:00:00Z',
        'updated_at' => '2026-01-01T00:00:00Z',
        'links' => ['self' => 'https://fake/billable_items/1'],
    ];
}

function reset_state(): void
{
    $GLOBALS['__wp_options'] = [
        // Explicitly test "forever cache" wiring.
        'wp_cliniko_api_cache_ttl' => 0,
    ];
    $GLOBALS['__wp_transients'] = [];
    $GLOBALS['__scheduled_actions'] = [];
    $GLOBALS['__cliniko_client_calls'] = [];
    $GLOBALS['__fake_cliniko_client'] = new FakeApiClient();
}

/**
 * @param mixed $expected
 * @param mixed $actual
 */
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

function test_payment_missing_required_fields_returns_422(): void
{
    $controller = new PaymentController();

    $response = $controller->charge(make_request([
        'patient' => [],
        'content' => ['sections' => []],
    ]));

    assert_same(422, $response->get_status(), 'Payment should return 422 when required fields are missing');
}

function test_payment_free_booking_queues_action_scheduler_job(): void
{
    /** @var FakeApiClient $client */
    $client = $GLOBALS['__fake_cliniko_client'];
    $client->responsesByUrl = [
        'appointment_types/222' => appointment_type_payload('222', 'Free Consult', null),
    ];

    $payload = [
        'moduleId' => '222',
        'patient_form_template_id' => '999',
        'patient' => base_patient(),
        'content' => ['sections' => []],
    ];

    $controller = new PaymentController();
    $start = time();
    $response = $controller->charge(make_request($payload));

    $data = $response->get_data();
    assert_same(200, $response->get_status(), 'Free payment flow should return 200');
    assert_same('success', $data['status'], 'Free flow status should be success');
    assert_same(null, $data['payment']['id'], 'Free flow should not return payment id');
    assert_same('queued', $data['scheduling']['status'], 'Free flow should queue scheduling');

    assert_same(1, count($GLOBALS['__scheduled_actions']), 'Free flow should enqueue exactly one background action');

    $job = $GLOBALS['__scheduled_actions'][0];
    assert_same('cliniko_schedule_appointment', $job['hook'], 'Wrong scheduled hook');
    assert_same('wp-cliniko', $job['group'], 'Wrong scheduled group');
    assert_true($job['when'] >= $start + 4 && $job['when'] <= $start + 6, 'Expected ~5s scheduling delay');

    /** @var array<string,mixed> $args */
    $args = $job['args'][0];
    assert_same('222', $args['moduleId'], 'Queued module id mismatch');
    assert_same('999', $args['patient_form_template_id'], 'Queued template id mismatch');
    assert_same(null, $args['payment_reference'], 'Free flow should enqueue null payment reference');
    assert_same(0, $args['amount'], 'Free flow amount should be 0');

    assert_same(1, count($GLOBALS['__cliniko_client_calls']), 'Expected one cliniko_client call');
    assert_same(true, $GLOBALS['__cliniko_client_calls'][0]['withCache'], 'Payment flow should use cached client');
    assert_same(0, $GLOBALS['__cliniko_client_calls'][0]['ttl'], 'TTL should be forwarded as 0 (forever cache)');
}

function test_payment_paid_missing_stripe_token_returns_422_and_avoids_duplicate_price_walk(): void
{
    /** @var FakeApiClient $client */
    $client = $GLOBALS['__fake_cliniko_client'];
    $client->responsesByUrl = [
        'appointment_types/123' => appointment_type_payload(
            '123',
            'Paid Consult',
            'https://fake/appointment_type_billable_items/123'
        ),
        'https://fake/appointment_type_billable_items/123' => paid_billable_items_payload(),
        'https://fake/billable_items/1' => paid_billable_item_payload(),
    ];

    $payload = [
        'moduleId' => '123',
        'patient_form_template_id' => '999',
        'patient' => base_patient(),
        'content' => ['sections' => []],
    ];

    $controller = new PaymentController();
    $response = $controller->charge(make_request($payload));

    $data = $response->get_data();
    assert_same(422, $response->get_status(), 'Paid flow without stripe token should return 422');
    assert_same('Missing required field: stripeToken.', $data['message'], 'Expected missing stripe token message');
    assert_same(0, count($GLOBALS['__scheduled_actions']), 'No worker job should be queued for missing stripe token');

    // Expected call sequence after optimization:
    // 1) appointment_types/{id}
    // 2) appointment_type_billable_items link
    // 3) billable_item link
    assert_same(3, count($client->calls), 'Expected a single amount computation path (no duplicate billable traversal)');
}

function test_tyro_paid_missing_transaction_id_returns_422(): void
{
    /** @var FakeApiClient $client */
    $client = $GLOBALS['__fake_cliniko_client'];
    $client->responsesByUrl = [
        'appointment_types/123' => appointment_type_payload(
            '123',
            'Paid Consult',
            'https://fake/appointment_type_billable_items/123'
        ),
        'https://fake/appointment_type_billable_items/123' => paid_billable_items_payload(),
        'https://fake/billable_items/1' => paid_billable_item_payload(),
    ];

    $payload = [
        'moduleId' => '123',
        'patient_form_template_id' => '999',
        'patient' => base_patient(),
        'content' => ['sections' => []],
    ];

    $response = TyroController::charge(make_request($payload));
    $data = $response->get_data();

    assert_same(422, $response->get_status(), 'Tyro paid flow without transaction id should return 422');
    assert_same('Missing required field: tyroTransactionId.', $data['message'], 'Expected missing tyro transaction id');
    assert_same(0, count($GLOBALS['__scheduled_actions']), 'No worker job should be queued for missing tyro transaction');
}

function test_tyro_free_booking_queues_action_scheduler_job(): void
{
    /** @var FakeApiClient $client */
    $client = $GLOBALS['__fake_cliniko_client'];
    $client->responsesByUrl = [
        'appointment_types/222' => appointment_type_payload('222', 'Free Consult', null),
    ];

    $payload = [
        'moduleId' => '222',
        'patient_form_template_id' => '999',
        'patient' => base_patient(),
        'content' => ['sections' => []],
    ];

    $response = TyroController::charge(make_request($payload));
    $data = $response->get_data();

    assert_same(200, $response->get_status(), 'Tyro free flow should return 200');
    assert_same('success', $data['status'], 'Tyro free flow status should be success');
    assert_same(null, $data['payment']['id'], 'Tyro free flow should not return payment id');
    assert_same(1, count($GLOBALS['__scheduled_actions']), 'Tyro free flow should enqueue one worker job');
}

function test_tyro_invoice_returns_price_for_paid_module(): void
{
    /** @var FakeApiClient $client */
    $client = $GLOBALS['__fake_cliniko_client'];
    $client->responsesByUrl = [
        'appointment_types/123' => appointment_type_payload(
            '123',
            'Paid Consult',
            'https://fake/appointment_type_billable_items/123'
        ),
        'https://fake/appointment_type_billable_items/123' => paid_billable_items_payload(),
        'https://fake/billable_items/1' => paid_billable_item_payload(),
    ];

    $response = TyroController::createInvoice(make_request(['moduleId' => '123']));
    $data = $response->get_data();

    assert_same(200, $response->get_status(), 'Tyro invoice should return 200 for valid paid module');
    assert_same(true, $data['success'], 'Tyro invoice should mark success=true');
    assert_same('125.00', $data['data']['chargeAmount'], 'Tyro invoice chargeAmount mismatch');
    assert_same('Paid Consult', $data['data']['invoiceReference'], 'Tyro invoice reference mismatch');
}

function test_iframe_create_patient_form_valid_payload_returns_202_and_queues_job(): void
{
    $payload = [
        'moduleId' => '123',
        'patient_form_template_id' => '999',
        'patient' => [
            'email' => 'john@example.com',
            'patient_booked_time' => '2026-02-10T01:30:00Z',
        ],
        'content' => [
            'sections' => [[
                'name' => 'Basic',
                'questions' => [],
            ]],
        ],
    ];

    $controller = new ClinikoController();
    $start = time();
    $response = $controller->createPatientForm(make_request($payload));
    $data = $response->get_data();

    assert_same(202, $response->get_status(), 'Iframe endpoint should return 202 on valid payload');
    assert_same(true, $data['success'], 'Iframe endpoint success flag should be true');
    assert_same('queued', $data['queued']['status'], 'Iframe endpoint should return queued status');
    assert_same(1, count($GLOBALS['__scheduled_actions']), 'Iframe endpoint should enqueue one job');

    $job = $GLOBALS['__scheduled_actions'][0];
    assert_same('cliniko_async_create_patient_form', $job['hook'], 'Wrong iframe worker hook');
    assert_same('wp-cliniko', $job['group'], 'Wrong iframe worker group');
    assert_true($job['when'] >= $start + 1 && $job['when'] <= $start + 3, 'Expected ~2s iframe scheduling delay');

    /** @var array<string,mixed> $args */
    $args = $job['args'][0];
    $payloadKey = (string) ($args['payload_key'] ?? '');
    assert_true($payloadKey !== '', 'Queued iframe payload_key should exist');

    $storedPayload = get_option($payloadKey);
    assert_true(is_array($storedPayload), 'Stored iframe payload should exist in options');
}

function test_iframe_create_patient_form_invalid_payload_returns_400(): void
{
    $payload = [
        'moduleId' => '123',
        'patient_form_template_id' => '999',
        'patient' => [
            'email' => 'john@example.com',
            // missing patient_booked_time on purpose
        ],
        'content' => [
            'sections' => [[
                'name' => 'Basic',
                'questions' => [],
            ]],
        ],
    ];

    $controller = new ClinikoController();
    $response = $controller->createPatientForm(make_request($payload));
    $data = $response->get_data();

    assert_same(400, $response->get_status(), 'Iframe endpoint should return 400 on invalid payload');
    assert_same(false, $data['success'], 'Iframe endpoint success flag should be false');
    assert_same(0, count($GLOBALS['__scheduled_actions']), 'Invalid iframe payload should not enqueue job');
}

/**
 * @param callable():void $test
 */
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
    'payment_missing_required_fields_returns_422' => 'test_payment_missing_required_fields_returns_422',
    'payment_free_booking_queues_action_scheduler_job' => 'test_payment_free_booking_queues_action_scheduler_job',
    'payment_paid_missing_stripe_token_returns_422_and_avoids_duplicate_price_walk' => 'test_payment_paid_missing_stripe_token_returns_422_and_avoids_duplicate_price_walk',
    'tyro_paid_missing_transaction_id_returns_422' => 'test_tyro_paid_missing_transaction_id_returns_422',
    'tyro_free_booking_queues_action_scheduler_job' => 'test_tyro_free_booking_queues_action_scheduler_job',
    'tyro_invoice_returns_price_for_paid_module' => 'test_tyro_invoice_returns_price_for_paid_module',
    'iframe_create_patient_form_valid_payload_returns_202_and_queues_job' => 'test_iframe_create_patient_form_valid_payload_returns_202_and_queues_job',
    'iframe_create_patient_form_invalid_payload_returns_400' => 'test_iframe_create_patient_form_invalid_payload_returns_400',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    if (run_test($name, $fn)) {
        $passed++;
    }
}

$total = count($tests);
echo "\n{$passed}/{$total} tests passed.\n";

exit($passed === $total ? 0 : 1);
