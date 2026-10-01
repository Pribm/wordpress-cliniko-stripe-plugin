<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Service\PatientAccountClosureEmailTemplate;
use App\Service\PatientAccountClosureService;
use App\Service\PatientLinkService;
use App\Service\PatientService;

$GLOBALS['closure_test_users'] = [];
$GLOBALS['closure_test_meta'] = [];
$GLOBALS['closure_test_mail'] = [];
$GLOBALS['closure_test_cache_invalidated'] = 0;
$GLOBALS['closure_test_sessions_destroyed'] = [];
$GLOBALS['closure_test_auth_cleared'] = false;
$GLOBALS['closure_test_actions'] = [];
$GLOBALS['closure_test_options'] = [];

class WP_User
{
    public string $display_name = 'Test Patient';

    /** @param list<string> $roles */
    public function __construct(
        public int $ID,
        public string $user_email,
        public array $roles
    ) {
    }

    public function has_cap(string $capability): bool
    {
        return false;
    }
}

class WP_Session_Tokens
{
    public function __construct(private int $userId)
    {
    }

    public static function get_instance(int $userId): self
    {
        return new self($userId);
    }

    public function destroy_all(): void
    {
        $GLOBALS['closure_test_sessions_destroyed'][] = $this->userId;
    }
}

final class ClosureFakeClient implements ApiClientInterface
{
    public function get(string $url): ClientResponse { return new ClientResponse([]); }
    public function post(string $url, array $data): ClientResponse { return new ClientResponse([]); }
    public function put(string $url, array $data): ClientResponse { return new ClientResponse([]); }
    public function patch(string $url, array $data): ClientResponse { return new ClientResponse([]); }
}

function get_userdata(int $userId)
{
    return $GLOBALS['closure_test_users'][$userId] ?? false;
}

function get_option(string $option, $default = false)
{
    if ($option === 'wp_cliniko_patient_sync_roles') {
        return ['patient'];
    }
    return $GLOBALS['closure_test_options'][$option] ?? $default;
}

function get_user_meta(int $userId, string $key, bool $single = false)
{
    return $GLOBALS['closure_test_meta'][$userId][$key] ?? '';
}

function update_user_meta(int $userId, string $key, $value): bool
{
    $GLOBALS['closure_test_meta'][$userId][$key] = $value;
    return true;
}

function delete_user_meta(int $userId, string $key): bool
{
    unset($GLOBALS['closure_test_meta'][$userId][$key]);
    return true;
}

function wp_cliniko_secret_option_encrypt($value): string
{
    return 'encrypted:' . (string) $value;
}

function wp_cliniko_secret_option_decrypt(string $value): string
{
    return str_starts_with($value, 'encrypted:') ? substr($value, 10) : $value;
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'closure-test-secret';
}

function apply_filters(string $hook, $value, ...$args)
{
    return $value;
}

function esc_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function esc_url_raw(string $value): string
{
    return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

function sanitize_textarea_field(string $value): string
{
    return trim(strip_tags($value));
}

function sanitize_hex_color(string $value)
{
    return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : null;
}

function home_url(string $path = ''): string
{
    return 'https://example.test/' . ltrim($path, '/');
}

function wp_validate_redirect(string $location, string $fallback = ''): string
{
    $host = parse_url($location, PHP_URL_HOST);
    return $host === null || $host === false || $host === 'example.test' ? $location : $fallback;
}

function remove_query_arg($keys, string $url): string
{
    $keys = is_array($keys) ? $keys : [$keys];
    $parts = parse_url($url);
    parse_str((string) ($parts['query'] ?? ''), $query);
    foreach ($keys as $key) unset($query[$key]);
    $base = (string) ($parts['scheme'] ?? '') . '://' . (string) ($parts['host'] ?? '') . (string) ($parts['path'] ?? '');
    return $query === [] ? $base : $base . '?' . http_build_query($query);
}

/** @param array<string,mixed>|string $args */
function add_query_arg($args, $value = '', string $url = ''): string
{
    if (is_string($args)) {
        $args = [$args => $value];
    } else {
        $url = is_string($value) ? $value : $url;
    }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function get_bloginfo(string $show = ''): string
{
    return 'Example Clinic';
}

function wp_specialchars_decode(string $text, int $quoteStyle = ENT_NOQUOTES): string
{
    return html_entity_decode($text, $quoteStyle, 'UTF-8');
}

function wp_mail(string $to, string $subject, string $message, array $headers = []): bool
{
    $GLOBALS['closure_test_mail'][] = compact('to', 'subject', 'message', 'headers');
    return true;
}

function is_multisite(): bool
{
    return false;
}

function cliniko_dashboard_cache_invalidate(): void
{
    $GLOBALS['closure_test_cache_invalidated']++;
}

function wp_delete_user(int $userId): bool
{
    unset($GLOBALS['closure_test_users'][$userId], $GLOBALS['closure_test_meta'][$userId]);
    return true;
}

function wp_clear_auth_cookie(): void
{
    $GLOBALS['closure_test_auth_cleared'] = true;
}

function do_action(string $hook, ...$args): void
{
    $GLOBALS['closure_test_actions'][] = [$hook, $args];
}

function assert_closure(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$userId = 21;
$email = 'patient@example.test';
$GLOBALS['closure_test_users'][$userId] = new WP_User($userId, $email, ['patient']);
$GLOBALS['closure_test_meta'][$userId] = [
    PatientLinkService::META_STATUS => PatientLinkService::STATUS_VERIFIED,
    PatientLinkService::META_EMAIL_HASH => hash_hmac('sha256', $email, wp_salt('auth')),
    PatientLinkService::META_VERIFIED_AT => '2026-09-15 00:00:00',
    PatientService::META_PATIENT_ID => wp_cliniko_secret_option_encrypt('patient_21'),
];

$links = new PatientLinkService(new ClosureFakeClient());
$service = new PatientAccountClosureService($links);
$GLOBALS['closure_test_options'][PatientAccountClosureEmailTemplate::REQUEST_OPTION] = [
    'subject' => 'Close {first_name}\'s portal account',
    'heading' => 'Custom closure heading',
    'button_color' => '#123456',
];
$GLOBALS['closure_test_options'][PatientAccountClosureEmailTemplate::RECEIPT_OPTION] = [
    'subject' => 'Closure receipt for {site_name}',
    'heading' => 'Custom receipt heading',
];
$requested = $service->request($userId, 'https://example.test/my-account/?old=1');
assert_closure($requested['ok'] && $requested['status'] === 'requested', 'A verified patient could not request account closure.');
assert_closure(count($GLOBALS['closure_test_mail']) === 1, 'The closure confirmation email was not sent exactly once.');
assert_closure(
    str_contains((string) $GLOBALS['closure_test_mail'][0]['message'], '<!doctype html>')
        && (string) $GLOBALS['closure_test_mail'][0]['subject'] === "Close Test Patient's portal account"
        && str_contains((string) $GLOBALS['closure_test_mail'][0]['message'], 'Custom closure heading')
        && str_contains((string) $GLOBALS['closure_test_mail'][0]['message'], 'background:#123456')
        && in_array('Content-Type: text/html; charset=UTF-8', $GLOBALS['closure_test_mail'][0]['headers'], true),
    'The closure request did not use the saved branded HTML email template.'
);

$emailMessage = (string) $GLOBALS['closure_test_mail'][0]['message'];
preg_match('/token=([A-Za-z0-9_-]{43})/', $emailMessage, $matches);
$token = isset($matches[1]) ? rawurldecode($matches[1]) : '';
assert_closure($token !== '', 'The confirmation email did not contain a secure token.');
assert_closure(
    get_user_meta($userId, PatientAccountClosureService::META_TOKEN_HASH, true) !== $token,
    'The plaintext account-closure token was stored in user metadata.'
);
assert_closure(
    !$service->isValidConfirmation(22, $userId, $token),
    'A different signed-in user could use the account-closure confirmation.'
);
assert_closure(
    $service->isValidConfirmation($userId, $userId, $token),
    'The valid closure confirmation was rejected.'
);

$closed = $service->close($userId, $userId, $token);
assert_closure($closed['ok'] && $closed['status'] === 'closed', 'The confirmed portal account was not closed.');
assert_closure(!isset($GLOBALS['closure_test_users'][$userId]), 'The WordPress patient user was not deleted.');
assert_closure($GLOBALS['closure_test_cache_invalidated'] === 1, 'Encrypted dashboard data was not invalidated.');
assert_closure($GLOBALS['closure_test_sessions_destroyed'] === [$userId], 'Patient sessions were not destroyed.');
assert_closure($GLOBALS['closure_test_auth_cleared'] === true, 'The current authentication cookie was not cleared.');
assert_closure(
    count($GLOBALS['closure_test_mail']) === 2
        && (string) $GLOBALS['closure_test_mail'][1]['subject'] === 'Closure receipt for Example Clinic'
        && str_contains((string) $GLOBALS['closure_test_mail'][1]['message'], '<!doctype html>')
        && str_contains((string) $GLOBALS['closure_test_mail'][1]['message'], 'Custom receipt heading')
        && !str_contains((string) $GLOBALS['closure_test_mail'][1]['message'], '<a href='),
    'The patient did not receive the saved portal account-closure receipt template.'
);

echo "Patient account closure service tests passed.\n";
