<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/../vendor/autoload.php';

use App\Service\PatientAccessTokenService;

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
        return 'patient-access-challenge-test-salt';
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}

function assert_challenge_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reset_challenge_state(): void
{
    $GLOBALS['__wp_transients'] = [];
}

function issue_test_challenge(PatientAccessTokenService $tokens): array
{
    return $tokens->issueChallenge(
        'patient@example.test',
        'appointment-type-1',
        ['patient-1'],
        [
            'booking_id' => 'booking-1',
            'patient_id' => 'patient-1',
        ]
    );
}

function test_challenge_locks_after_three_failed_codes(): void
{
    $tokens = new PatientAccessTokenService();
    $challenge = issue_test_challenge($tokens);

    for ($attempt = 1; $attempt <= PatientAccessTokenService::MAX_CHALLENGE_FAILURES; $attempt++) {
        $result = $tokens->verifyChallenge(
            $challenge['challenge_token'],
            'patient@example.test',
            '000000' === $challenge['code'] ? '000001' : '000000'
        );
        assert_challenge_true($result === null, "Expected incorrect code attempt {$attempt} to fail");
    }

    $lockedResult = $tokens->verifyChallenge(
        $challenge['challenge_token'],
        'patient@example.test',
        $challenge['code']
    );
    assert_challenge_true($lockedResult === null, 'Expected the correct code to be rejected after three failures');
}

function test_correct_code_still_succeeds_before_failure_limit(): void
{
    $tokens = new PatientAccessTokenService();
    $challenge = issue_test_challenge($tokens);
    $wrongCode = '000000' === $challenge['code'] ? '000001' : '000000';

    assert_challenge_true(
        $tokens->verifyChallenge($challenge['challenge_token'], 'patient@example.test', $wrongCode) === null,
        'Expected the first incorrect code to fail'
    );
    assert_challenge_true(
        $tokens->verifyChallenge($challenge['challenge_token'], 'patient@example.test', $wrongCode) === null,
        'Expected the second incorrect code to fail'
    );

    $verified = $tokens->verifyChallenge(
        $challenge['challenge_token'],
        'patient@example.test',
        $challenge['code']
    );
    assert_challenge_true(is_array($verified), 'Expected the correct code to retain the existing success behavior');
    assert_challenge_true(
        ($verified['latest_patient_id'] ?? '') === 'patient-1',
        'Expected verified challenge claims to remain unchanged'
    );
}

$tests = [
    'challenge_locks_after_three_failed_codes' => 'test_challenge_locks_after_three_failed_codes',
    'correct_code_still_succeeds_before_failure_limit' => 'test_correct_code_still_succeeds_before_failure_limit',
];

$passed = 0;
foreach ($tests as $name => $test) {
    reset_challenge_state();

    try {
        $test();
        echo "[PASS] {$name}\n";
        $passed++;
    } catch (Throwable $exception) {
        echo "[FAIL] {$name}: {$exception->getMessage()}\n";
    }
}

$total = count($tests);
echo "\n{$passed}/{$total} patient access challenge tests passed.\n";

exit($passed === $total ? 0 : 1);
