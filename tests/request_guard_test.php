<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/../vendor/autoload.php';

use App\Service\BookingAttemptStore;
use App\Service\PublicRequestGuard;

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private string $body = '';
        private string $method = 'POST';
        private string $route = '';
        /** @var array<string,mixed> */
        private array $params = [];
        /** @var array<string,string> */
        private array $headers = [];

        public function __construct(string $method = 'POST', string $route = '')
        {
            $this->method = $method;
            $this->route = $route;
        }

        public function set_body(string $body): void
        {
            $this->body = $body;
        }

        public function get_body(): string
        {
            return $this->body;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        /**
         * @param array<string,mixed> $params
         */
        public function set_params(array $params): void
        {
            $this->params = $params;
        }

        /**
         * @return mixed|null
         */
        public function get_param(string $key)
        {
            return $this->params[$key] ?? null;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): ?string
        {
            $normalized = strtolower($key);
            return $this->headers[$normalized] ?? null;
        }

        /**
         * @return array<string,string>
         */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;
        private string $message;
        /** @var array<string,mixed> */
        private array $data;

        /**
         * @param array<string,mixed> $data
         */
        public function __construct(string $code, string $message, array $data = [])
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        /**
         * @return array<string,mixed>
         */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

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

if (!function_exists('get_site_url')) {
    function get_site_url(): string
    {
        return 'https://example.test';
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url)
    {
        return parse_url($url);
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'test-salt';
    }
}

function reset_guard_state(): void
{
    $GLOBALS['__wp_options'] = [];
    $GLOBALS['__wp_transients'] = [];
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_USER_AGENT'] = 'request-guard-test';
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_public_mutation_without_guard_token(): void
{
    $guard = new PublicRequestGuard(new BookingAttemptStore());
    $request = new WP_REST_Request('POST', '/v2/booking-attempts/preflight');

    $allowed = $guard->allowPublicMutation($request);
    assert_true($allowed === true, 'Expected public mutation to pass without a guard token');
}

function test_attempt_mutation_requires_matching_attempt_token(): void
{
    $store = new BookingAttemptStore();
    $attemptToken = PublicRequestGuard::issueAttemptToken();
    $attempt = $store->create([
        'status' => 'preflighted',
        'attempt_token_hash' => PublicRequestGuard::hashAttemptToken($attemptToken),
    ]);

    $guard = new PublicRequestGuard($store);
    $request = new WP_REST_Request('POST', '/v2/booking-attempts/finalize');
    $request->set_header('X-ES-Attempt-Token', $attemptToken);
    $request->set_params([
        'attempt_id' => $attempt['attempt_id'],
    ]);

    $allowed = $guard->allowAttemptMutation($request);
    assert_true($allowed === true, 'Expected matching attempt token to pass guard');

    $bad = new WP_REST_Request('POST', '/v2/booking-attempts/finalize');
    $bad->set_header('X-ES-Attempt-Token', 'wrong-token');
    $bad->set_params([
        'attempt_id' => $attempt['attempt_id'],
    ]);

    $denied = $guard->allowAttemptMutation($bad);
    assert_true($denied instanceof WP_Error, 'Expected wrong attempt token to be denied');
}

function test_public_read_without_guard_token(): void
{
    $guard = new PublicRequestGuard(new BookingAttemptStore());

    $request = new WP_REST_Request('GET', '/v1/available-times');
    $allowed = $guard->allowPublicRead($request);
    assert_true($allowed === true, 'Expected public read route to pass without a guard token');
}

function run_test(string $name, callable $test): bool
{
    reset_guard_state();

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
    'public_mutation_without_guard_token' => 'test_public_mutation_without_guard_token',
    'attempt_mutation_requires_matching_attempt_token' => 'test_attempt_mutation_requires_matching_attempt_token',
    'public_read_without_guard_token' => 'test_public_read_without_guard_token',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    if (run_test($name, $fn)) {
        $passed++;
    }
}

$total = count($tests);
echo "\n{$passed}/{$total} request guard tests passed.\n";

exit($passed === $total ? 0 : 1);
