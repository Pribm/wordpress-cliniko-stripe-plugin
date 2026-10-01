<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';

$GLOBALS['email_change_users'] = [];
$GLOBALS['email_change_meta'] = [];
$GLOBALS['email_change_mail'] = [];
$GLOBALS['email_change_current_user'] = 12;
$GLOBALS['email_change_sessions_destroyed'] = false;

class WP_User
{
    public string $display_name = 'Alex Patient';

    /** @param list<string> $roles */
    public function __construct(
        public int $ID,
        public string $user_email,
        public array $roles
    ) {
    }
}

class WP_Session_Tokens
{
    public static function get_instance(int $userId): self
    {
        return new self();
    }

    public function destroy_all(): void
    {
        $GLOBALS['email_change_sessions_destroyed'] = true;
    }
}

function get_current_user_id(): int { return (int) $GLOBALS['email_change_current_user']; }
function get_userdata(int $userId) { return $GLOBALS['email_change_users'][$userId] ?? false; }
function get_option(string $key, $default = false) { return $key === 'wp_cliniko_patient_sync_roles' ? ['patient'] : $default; }
function get_user_meta(int $userId, string $key, bool $single = false) { return $GLOBALS['email_change_meta'][$userId][$key] ?? ''; }
function update_user_meta(int $userId, string $key, $value): bool { $GLOBALS['email_change_meta'][$userId][$key] = $value; return true; }
function delete_user_meta(int $userId, string $key): bool { unset($GLOBALS['email_change_meta'][$userId][$key]); return true; }
function wp_cliniko_secret_option_encrypt($value): string { return 'encrypted:' . (string) $value; }
function wp_cliniko_secret_option_decrypt(string $value): string { return str_starts_with($value, 'encrypted:') ? substr($value, 10) : $value; }
function is_email(string $email): bool { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
function sanitize_email(string $email): string { return strtolower(trim($email)); }
function wp_salt(string $scheme = 'auth'): string { return 'email-change-test-salt'; }
function email_exists(string $email) { return false; }
function rest_url(string $path = ''): string { return 'https://example.test/wp-json/' . ltrim($path, '/'); }
function get_bloginfo(string $show = ''): string { return 'Example Clinic'; }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function esc_url(string $value): string { return $value; }
function apply_filters(string $hook, $value) { return $value; }
function current_time(string $type, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
function is_wp_error($value): bool { return false; }
function wp_clear_auth_cookie(): void {}

/** @param array<string,mixed> $args */
function add_query_arg(array $args, string $url = ''): string
{
    return $url . '?' . http_build_query($args);
}

function wp_mail(string $email, string $subject, string $message, array $headers = []): bool
{
    $GLOBALS['email_change_mail'][] = compact('email', 'subject', 'message');
    return true;
}

/** @param array{ID:int,user_email:string} $data */
function wp_update_user(array $data): int
{
    $user = $GLOBALS['email_change_users'][$data['ID']];
    $oldUser = new WP_User($user->ID, $user->user_email, $user->roles);
    $user->user_email = $data['user_email'];
    \App\Service\PatientLinkService::handleProfileUpdate($user->ID, $oldUser);
    return $user->ID;
}

require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Service\PatientEmailChangeService;
use App\Service\PatientLinkService;
use App\Service\PatientService;

final class PatientEmailChangeFakeClient implements ApiClientInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $patients = [
        'patient_12' => [
            'id' => 'patient_12',
            'first_name' => 'Alex',
            'last_name' => 'Patient',
            'email' => 'old@example.test',
        ],
    ];

    public function get(string $url): ClientResponse
    {
        if (str_starts_with($url, 'patients?')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $filters = (array) ($query['q'] ?? []);
            $email = '';
            foreach ($filters as $filter) {
                if (str_starts_with((string) $filter, 'email:=')) {
                    $email = substr((string) $filter, 7);
                }
            }
            $matches = array_values(array_filter(
                $this->patients,
                static fn(array $patient): bool => strtolower((string) $patient['email']) === strtolower($email)
            ));
            return new ClientResponse(['patients' => $matches]);
        }

        $id = substr($url, strlen('patients/'));
        return isset($this->patients[$id])
            ? new ClientResponse($this->patients[$id])
            : new ClientResponse(null, 'Not found.', 404);
    }

    public function post(string $url, array $data): ClientResponse { return new ClientResponse(null, 'Not used.'); }

    public function put(string $url, array $data): ClientResponse
    {
        $id = substr($url, strlen('patients/'));
        if (!isset($this->patients[$id])) {
            return new ClientResponse(null, 'Not found.', 404);
        }
        $this->patients[$id] = array_merge($this->patients[$id], $data);
        return new ClientResponse($this->patients[$id]);
    }

    public function patch(string $url, array $data): ClientResponse { return new ClientResponse(null, 'Not used.'); }
}

function assert_email_change(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$userId = 12;
$oldEmail = 'old@example.test';
$GLOBALS['email_change_users'][$userId] = new WP_User($userId, $oldEmail, ['patient']);
$GLOBALS['email_change_meta'][$userId][PatientService::META_PATIENT_ID] = wp_cliniko_secret_option_encrypt('patient_12');
$GLOBALS['email_change_meta'][$userId][PatientLinkService::META_STATUS] = PatientLinkService::STATUS_VERIFIED;
$emailHash = hash_hmac('sha256', $oldEmail, 'email-change-test-salt');
$GLOBALS['email_change_meta'][$userId][PatientLinkService::META_EMAIL_HASH] = $emailHash;

$client = new PatientEmailChangeFakeClient();
$links = new PatientLinkService($client);
$service = new PatientEmailChangeService($client, $links);
$requested = $service->request($userId, 'new@example.test');

assert_email_change($requested['status'] === PatientEmailChangeService::STATUS_PENDING, 'Email change did not enter pending state.');
assert_email_change($GLOBALS['email_change_users'][$userId]->user_email === $oldEmail, 'WordPress email changed before confirmation.');
assert_email_change($client->patients['patient_12']['email'] === $oldEmail, 'Cliniko email changed before confirmation.');
assert_email_change(count($GLOBALS['email_change_mail']) === 1, 'Confirmation email was not sent.');

preg_match('/[?&]token=([A-Za-z0-9_-]{43})/', $GLOBALS['email_change_mail'][0]['message'], $matches);
$token = isset($matches[1]) ? rawurldecode($matches[1]) : '';
assert_email_change($token !== '', 'Confirmation token was not included in the email.');

$verified = $service->verify($userId, $token);
assert_email_change($verified['ok'], 'Confirmed email change failed: ' . json_encode($verified));
assert_email_change($GLOBALS['email_change_users'][$userId]->user_email === 'new@example.test', 'WordPress email was not updated.');
assert_email_change($client->patients['patient_12']['email'] === 'new@example.test', 'Cliniko email was not updated.');
assert_email_change($links->isVerifiedUser($userId), 'Verified patient link was not preserved.');
assert_email_change((bool) $GLOBALS['email_change_sessions_destroyed'], 'Existing sessions were not destroyed.');

$conflictUserId = 13;
$GLOBALS['email_change_users'][$conflictUserId] = new WP_User($conflictUserId, 'patient13@example.test', ['patient']);
$GLOBALS['email_change_meta'][$conflictUserId][PatientService::META_PATIENT_ID] = wp_cliniko_secret_option_encrypt('patient_13');
$GLOBALS['email_change_meta'][$conflictUserId][PatientLinkService::META_STATUS] = PatientLinkService::STATUS_VERIFIED;
$GLOBALS['email_change_meta'][$conflictUserId][PatientLinkService::META_EMAIL_HASH] = hash_hmac(
    'sha256',
    'patient13@example.test',
    'email-change-test-salt'
);
$client->patients['patient_13'] = [
    'id' => 'patient_13',
    'first_name' => 'Second',
    'last_name' => 'Patient',
    'email' => 'patient13@example.test',
];
$client->patients['patient_14'] = [
    'id' => 'patient_14',
    'first_name' => 'Existing',
    'last_name' => 'Patient',
    'email' => 'used@example.test',
];
$conflict = $service->request($conflictUserId, 'used@example.test');
assert_email_change(
    $conflict['status'] === PatientEmailChangeService::STATUS_CONFLICT,
    'An email belonging to another Cliniko patient was not rejected.'
);
assert_email_change(count($GLOBALS['email_change_mail']) === 1, 'A conflict incorrectly sent a confirmation email.');

echo "Patient email change service tests passed.\n";
