<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Admin\Modules\AccountBuilders\Shortcodes\PatientVerificationConfirmation;
use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Service\PatientLinkService;
use App\Service\PatientService;

$GLOBALS['verification_login_current_user'] = 0;
$GLOBALS['verification_login_users'] = [];
$GLOBALS['verification_login_meta'] = [];
$GLOBALS['verification_login_filters'] = [];
$GLOBALS['verification_login_cookie'] = [];
$GLOBALS['verification_login_cookie_cleared'] = 0;
$GLOBALS['verification_login_actions'] = [];

class WP_User
{
    /** @param list<string> $roles */
    public function __construct(
        public int $ID,
        public string $user_email,
        public string $user_login,
        public array $roles
    ) {
    }

    public function has_cap(string $capability): bool
    {
        return false;
    }
}

final class VerificationLoginFakeClient implements ApiClientInterface
{
    public function get(string $url): ClientResponse { return new ClientResponse([]); }
    public function post(string $url, array $data): ClientResponse { return new ClientResponse([]); }
    public function put(string $url, array $data): ClientResponse { return new ClientResponse([]); }
    public function patch(string $url, array $data): ClientResponse { return new ClientResponse([]); }
}

function get_current_user_id(): int
{
    return (int) $GLOBALS['verification_login_current_user'];
}

function get_userdata(int $userId)
{
    return $GLOBALS['verification_login_users'][$userId] ?? false;
}

function get_option(string $option, $default = false)
{
    return $option === 'wp_cliniko_patient_sync_roles' ? ['patient'] : $default;
}

function get_user_meta(int $userId, string $key, bool $single = false)
{
    return $GLOBALS['verification_login_meta'][$userId][$key] ?? '';
}

function wp_cliniko_secret_option_decrypt(string $value): string
{
    return str_starts_with($value, 'encrypted:') ? substr($value, 10) : $value;
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'verification-login-test-salt';
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    $GLOBALS['verification_login_filters'][$hook][$priority][] = $callback;
    return true;
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool
{
    foreach ($GLOBALS['verification_login_filters'][$hook][$priority] ?? [] as $index => $registered) {
        if ($registered === $callback) {
            unset($GLOBALS['verification_login_filters'][$hook][$priority][$index]);
            return true;
        }
    }
    return false;
}

function apply_filters(string $hook, $value, ...$args)
{
    $priorities = $GLOBALS['verification_login_filters'][$hook] ?? [];
    ksort($priorities);
    foreach ($priorities as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$args);
        }
    }
    return $value;
}

function wp_clear_auth_cookie(): void
{
    $GLOBALS['verification_login_cookie_cleared']++;
}

function wp_set_current_user(int $userId): WP_User
{
    $GLOBALS['verification_login_current_user'] = $userId;
    return $GLOBALS['verification_login_users'][$userId];
}

function wp_set_auth_cookie(int $userId, bool $remember = false, $secure = '', string $token = ''): void
{
    $GLOBALS['verification_login_cookie'] = [
        'user_id' => $userId,
        'remember' => $remember,
        'secure' => $secure,
        'duration' => apply_filters('auth_cookie_expiration', 172800, $userId, $remember),
    ];
}

function is_ssl(): bool
{
    return true;
}

function do_action(string $hook, ...$args): void
{
    $GLOBALS['verification_login_actions'][] = [$hook, $args];
}

function assert_verification_login(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$userId = 31;
$email = 'verified@example.test';
$user = new WP_User($userId, $email, 'verified-patient', ['patient']);
$GLOBALS['verification_login_users'][$userId] = $user;
$GLOBALS['verification_login_meta'][$userId] = [
    PatientLinkService::META_STATUS => PatientLinkService::STATUS_VERIFIED,
    PatientLinkService::META_EMAIL_HASH => hash_hmac('sha256', $email, wp_salt('auth')),
    PatientService::META_PATIENT_ID => 'encrypted:patient_31',
];

$links = new PatientLinkService(new VerificationLoginFakeClient());
$method = new ReflectionMethod(PatientVerificationConfirmation::class, 'loginVerifiedPatient');
$loggedIn = $method->invoke(null, $userId, $links);

assert_verification_login($loggedIn === true, 'A fully verified patient was not logged in.');
assert_verification_login(
    $GLOBALS['verification_login_cookie'] === [
        'user_id' => $userId,
        'remember' => false,
        'secure' => true,
        'duration' => 3600,
    ],
    'The verification login did not create the expected secure one-hour session cookie.'
);
assert_verification_login($GLOBALS['verification_login_cookie_cleared'] === 1, 'The browser authentication cookie was not rotated.');
assert_verification_login(
    ($GLOBALS['verification_login_actions'][0][0] ?? '') === 'wp_login',
    'The standard WordPress login action was not fired.'
);
assert_verification_login(
    empty($GLOBALS['verification_login_filters']['auth_cookie_expiration'][999]),
    'The temporary patient-session duration filter was not removed.'
);

$GLOBALS['verification_login_current_user'] = 99;
$GLOBALS['verification_login_cookie'] = [];
$blocked = $method->invoke(null, $userId, $links);
assert_verification_login($blocked === false, 'A different signed-in browser account was silently replaced.');
assert_verification_login($GLOBALS['verification_login_cookie'] === [], 'A login cookie was created during an account conflict.');

echo "Patient verification login tests passed.\n";
