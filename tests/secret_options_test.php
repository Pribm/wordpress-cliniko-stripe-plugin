<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/../vendor/autoload.php';

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

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'test-salt';
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

require_once __DIR__ . '/../src/Helpers/secret-options.php';

function reset_secret_state(): void
{
    $GLOBALS['__wp_options'] = [];
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test_secret_option_round_trip_encrypts_and_decrypts(): void
{
    $encoded = wp_cliniko_secret_option_encrypt('sk_test_12345');
    assert_true($encoded !== 'sk_test_12345', 'Encrypted value should not match plaintext');
    assert_true(wp_cliniko_secret_option_is_encrypted($encoded), 'Encrypted value should be marked with the encrypted prefix');

    update_option('wp_stripe_secret_key', $encoded, false);
    assert_same('sk_test_12345', wp_cliniko_get_secret_option('wp_stripe_secret_key'), 'Encrypted secret should decrypt back to the original value');
}

function test_secret_option_read_migrates_plaintext(): void
{
    update_option('wp_cliniko_api_key', 'cliniko_live_plaintext', false);

    $value = wp_cliniko_get_secret_option('wp_cliniko_api_key');
    assert_same('cliniko_live_plaintext', $value, 'Plaintext secret should still be readable');

    $stored = get_option('wp_cliniko_api_key');
    assert_true(
        is_string($stored) && wp_cliniko_secret_option_is_encrypted($stored),
        'Plaintext secret should be migrated to encrypted storage on read'
    );
}

function run_test(string $name, callable $test): bool
{
    reset_secret_state();

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
    'secret_option_round_trip_encrypts_and_decrypts' => 'test_secret_option_round_trip_encrypts_and_decrypts',
    'secret_option_read_migrates_plaintext' => 'test_secret_option_read_migrates_plaintext',
];

$passed = 0;
foreach ($tests as $name => $fn) {
    if (run_test($name, $fn)) {
        $passed++;
    }
}

$total = count($tests);
echo "\n{$passed}/{$total} secret option tests passed.\n";

exit($passed === $total ? 0 : 1);
