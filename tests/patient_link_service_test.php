<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

require __DIR__ . '/CompatClientResponse.php';

/** @var array<int,WP_User> */
$GLOBALS['patient_link_test_users'] = [];
/** @var array<int,array<string,mixed>> */
$GLOBALS['patient_link_test_meta'] = [];
/** @var array<int,array{email:string,subject:string,message:string}> */
$GLOBALS['patient_link_test_mail'] = [];
$GLOBALS['patient_link_test_current_user_id'] = 7;
$GLOBALS['patient_link_test_api_client'] = null;
$GLOBALS['patient_link_test_actions'] = [
    'register_new_user' => ['wp_send_new_user_notifications' => 10],
];
$GLOBALS['patient_link_test_new_user_notifications'] = [];
$GLOBALS['patient_link_test_options'] = [];

class WP_User
{
    public string $display_name = 'Patient';

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

class WP_REST_Request
{
}

class NextendSocialLogin
{
}

class WP_Error
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public string $code,
        public string $message,
        public array $data
    ) {
    }
}

function is_user_logged_in(): bool
{
    return $GLOBALS['patient_link_test_current_user_id'] > 0;
}

function get_current_user_id(): int
{
    return (int) $GLOBALS['patient_link_test_current_user_id'];
}

function get_userdata(int $userId)
{
    return $GLOBALS['patient_link_test_users'][$userId] ?? false;
}

function get_option(string $option, $default = false)
{
    if ($option === 'wp_cliniko_patient_sync_roles') {
        return ['patient'];
    }
    if ($option === 'wp_cliniko_patient_sync_enabled') {
        return true;
    }
    return $GLOBALS['patient_link_test_options'][$option] ?? $default;
}

function get_user_meta(int $userId, string $key, bool $single = false)
{
    return $GLOBALS['patient_link_test_meta'][$userId][$key] ?? '';
}

function update_user_meta(int $userId, string $key, $value): bool
{
    $GLOBALS['patient_link_test_meta'][$userId][$key] = $value;
    return true;
}

function delete_user_meta(int $userId, string $key): bool
{
    unset($GLOBALS['patient_link_test_meta'][$userId][$key]);
    return true;
}

function has_action(string $hook, $callback = false)
{
    if (!is_string($callback)) {
        return false;
    }
    return $GLOBALS['patient_link_test_actions'][$hook][$callback] ?? false;
}

function remove_action(string $hook, string $callback, int $priority = 10): bool
{
    if (($GLOBALS['patient_link_test_actions'][$hook][$callback] ?? false) !== $priority) {
        return false;
    }
    unset($GLOBALS['patient_link_test_actions'][$hook][$callback]);
    return true;
}

function add_action(string $hook, string $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    $GLOBALS['patient_link_test_actions'][$hook][$callback] = $priority;
    return true;
}

function wp_new_user_notification(int $userId, $deprecated = null, string $notify = ''): void
{
    $GLOBALS['patient_link_test_new_user_notifications'][] = [
        'user_id' => $userId,
        'notify' => $notify,
    ];
}

function wp_cliniko_secret_option_encrypt($value): string
{
    return 'encrypted:' . (string) $value;
}

function wp_cliniko_secret_option_decrypt(string $value): string
{
    return str_starts_with($value, 'encrypted:') ? substr($value, 10) : $value;
}

function wp_cliniko_get_secret_option(string $key): string
{
    return 'test-key-au1';
}

function cliniko_dashboard_client($userId = null, int $ttl = 0)
{
    return $GLOBALS['patient_link_test_api_client'];
}

function cliniko_client(bool $useCache = true)
{
    return $GLOBALS['patient_link_test_api_client'];
}

function is_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'patient-link-test-salt';
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

function rest_url(string $path = ''): string
{
    return 'https://example.test/wp-json/' . ltrim($path, '/');
}

function wp_validate_redirect(string $location, string $fallback = ''): string
{
    $host = parse_url($location, PHP_URL_HOST);
    return $host === null || $host === false || $host === 'example.test' ? $location : $fallback;
}

/** @param array<string,mixed> $args */
function add_query_arg(array $args, string $url = ''): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}

function apply_filters(string $hook, $value)
{
    return $value;
}

function wp_mail(string $email, string $subject, string $message, array $headers = []): bool
{
    $GLOBALS['patient_link_test_mail'][] = compact('email', 'subject', 'message');
    return true;
}

function esc_url(string $url): string
{
    return $url;
}

function esc_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function get_bloginfo(string $show = ''): string
{
    return 'Example Clinic';
}

function current_time(string $type, bool $gmt = false): string
{
    return gmdate('Y-m-d H:i:s');
}

function sanitize_key(string $value): string
{
    return strtolower((string) preg_replace('/[^a-z0-9_\\-]/i', '', $value));
}

function sanitize_text_field(string $value): string
{
    return trim($value);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;
use App\Authorization\AuthenticatedPatientPolicy;
use App\Authorization\VerifiedPatientPolicy;
use App\Service\PatientLinkService;
use App\Service\PatientService;

final class PatientLinkFakeClient implements ApiClientInterface
{
    public int $created = 0;
    public function __construct(private bool $patientExists = true)
    {
    }

    public function get(string $url): ClientResponse
    {
        if (str_contains($url, '?q[]=')) {
            return new ClientResponse([
                'patients' => $this->patientExists ? [[
                    'id' => 'patient_1',
                    'first_name' => 'Alex',
                    'last_name' => 'Patient',
                    'email' => 'alex@example.test',
                ]] : [],
            ]);
        }
        return new ClientResponse([
            'id' => 'patient_1',
            'first_name' => 'Alex',
            'last_name' => 'Patient',
            'email' => 'alex@example.test',
        ]);
    }

    public function post(string $url, array $data): ClientResponse
    {
        $this->created++;
        return new ClientResponse([
            'id' => 'patient_2',
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
        ]);
    }

    public function put(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not used.');
    }

    public function patch(string $url, array $data): ClientResponse
    {
        return new ClientResponse(null, 'Not used.');
    }
}

function assert_patient_link(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$GLOBALS['patient_link_test_users'][7] = new WP_User(7, 'alex@example.test', ['patient']);
$GLOBALS['patient_link_test_options'][PatientLinkService::OPTION_VERIFICATION_PAGE_URL] = 'https://example.test/verify-account/';
$patientLinkClient = new PatientLinkFakeClient();
$GLOBALS['patient_link_test_api_client'] = $patientLinkClient;
$service = new PatientLinkService($patientLinkClient);
$pending = $service->beginVerification(7, 'patient_1');

assert_patient_link($pending['status'] === PatientLinkService::STATUS_PENDING, 'Patient link did not enter pending state.');
assert_patient_link(
    get_user_meta(7, PatientService::META_ACCOUNT_STATUS, true) === PatientService::ACCOUNT_PENDING_REVIEW,
    'Unverified WordPress patient account was not marked pending review.'
);
assert_patient_link(
    PatientService::denyPendingCurrentUser(7) === 0,
    'Pending-review authentication cookie was not denied.'
);
assert_patient_link(count($GLOBALS['patient_link_test_mail']) === 1, 'Verification email was not sent.');
$message = $GLOBALS['patient_link_test_mail'][0]['message'];
assert_patient_link(
    str_contains($message, 'https://example.test/verify-account/?cliniko_patient_verification=confirm')
        && !str_contains($message, '/wp-json/v2/patient-link/verify'),
    'The verification email did not use the configured frontend verification page.'
);
preg_match('/[?&]token=([A-Za-z0-9_-]{43})/', $message, $matches);
$token = isset($matches[1]) ? rawurldecode($matches[1]) : '';
assert_patient_link($token !== '', 'Verification token was not included in the email.');
assert_patient_link(
    get_user_meta(7, '_cliniko_link_token_hash', true) !== $token,
    'Plaintext verification token was stored.'
);

$verified = $service->verify(7, $token);
assert_patient_link($verified['ok'] === true, 'Valid email verification did not succeed.');
assert_patient_link($service->isVerifiedUser(7), 'Verified patient mapping was not accepted.');
assert_patient_link(
    get_user_meta(7, PatientService::META_ACCOUNT_STATUS, true) === PatientService::ACCOUNT_ACTIVE,
    'Verified WordPress patient account was not activated.'
);
assert_patient_link(
    PatientService::denyPendingCurrentUser(7) === 7,
    'Verified WordPress patient account remained blocked.'
);
$policy = new VerifiedPatientPolicy(new AuthenticatedPatientPolicy(), $service);
assert_patient_link($policy->authorize(new WP_REST_Request()) === true, 'Verified patient policy denied a valid link.');
assert_patient_link(
    wp_cliniko_secret_option_decrypt((string) get_user_meta(7, PatientService::META_PATIENT_ID, true)) === 'patient_1',
    'Verified Cliniko patient ID was not stored.'
);
assert_patient_link(get_user_meta(7, '_cliniko_link_token_hash', true) === '', 'Used verification token was not deleted.');

$oldUserData = new WP_User(7, 'alex@example.test', ['patient']);
$GLOBALS['patient_link_test_users'][7]->user_email = 'changed@example.test';
PatientLinkService::handleProfileUpdate(7, $oldUserData);
assert_patient_link(!$service->isVerifiedUser(7), 'Email change did not invalidate the verified mapping.');
assert_patient_link(
    get_user_meta(7, PatientService::META_ACCOUNT_STATUS, true) === PatientService::ACCOUNT_PENDING_REVIEW,
    'Revoked WordPress patient account did not return to pending review.'
);
assert_patient_link($policy->authorize(new WP_REST_Request()) instanceof WP_Error, 'Stale email mapping was not denied.');

$GLOBALS['patient_link_test_users'][8] = new WP_User(8, 'new@example.test', ['patient']);
$GLOBALS['patient_link_test_meta'][8]['first_name'] = 'New';
$GLOBALS['patient_link_test_meta'][8]['last_name'] = 'Patient';
$newPatientClient = new PatientLinkFakeClient(false);
$newPatientLinks = new PatientLinkService($newPatientClient);
$newPending = $newPatientLinks->beginVerification(8, PatientLinkService::PENDING_NEW_PATIENT);
assert_patient_link($newPending['status'] === PatientLinkService::STATUS_PENDING, 'New patient verification did not become pending.');
assert_patient_link($newPatientClient->created === 0, 'Cliniko patient was created before email verification.');
$newMessage = $GLOBALS['patient_link_test_mail'][1]['message'] ?? '';
preg_match('/[?&]token=([A-Za-z0-9_-]{43})/', $newMessage, $newMatches);
$newToken = isset($newMatches[1]) ? rawurldecode($newMatches[1]) : '';
$newVerified = $newPatientLinks->verify(8, $newToken);
assert_patient_link(
    $newVerified['ok'] === true,
    'Verified new patient was not linked: ' . json_encode($newVerified)
);
assert_patient_link($newPatientClient->created === 1, 'Cliniko patient was not created after email verification.');

$GLOBALS['patient_link_test_users'][9] = new WP_User(9, 'alex@example.test', ['patient']);
$returningLinks = new PatientLinkService(new PatientLinkFakeClient());
$firstChallenge = $returningLinks->beginVerification(9, 'patient_1');
assert_patient_link(
    $firstChallenge['status'] === PatientLinkService::STATUS_PENDING,
    'Returning patient verification did not enter pending state.'
);
$firstMessageIndex = count($GLOBALS['patient_link_test_mail']) - 1;
$firstMessage = $GLOBALS['patient_link_test_mail'][$firstMessageIndex]['message'] ?? '';
preg_match('/[?&]token=([A-Za-z0-9_-]{43})/', $firstMessage, $firstMatches);
$firstToken = isset($firstMatches[1]) ? rawurldecode($firstMatches[1]) : '';
$firstTokenHash = (string) get_user_meta(9, '_cliniko_link_token_hash', true);

$freshRefresh = $returningLinks->refreshStaleVerification(9);
assert_patient_link($freshRefresh === null, 'A still-valid verification challenge was resent.');
assert_patient_link(
    count($GLOBALS['patient_link_test_mail']) === $firstMessageIndex + 1,
    'A duplicate email was sent for a still-valid verification challenge.'
);

update_user_meta(9, '_cliniko_link_expires_at', time() - 1);
update_user_meta(9, '_cliniko_link_last_sent_at', time() - 61);
$refreshedChallenge = $returningLinks->refreshStaleVerification(9);
assert_patient_link(
    is_array($refreshedChallenge) && $refreshedChallenge['http_status'] === 202,
    'An expired verification challenge was not refreshed.'
);
assert_patient_link(
    count($GLOBALS['patient_link_test_mail']) === $firstMessageIndex + 2,
    'Refreshing an expired challenge did not send exactly one new email.'
);
$secondMessage = $GLOBALS['patient_link_test_mail'][$firstMessageIndex + 1]['message'] ?? '';
preg_match('/[?&]token=([A-Za-z0-9_-]{43})/', $secondMessage, $secondMatches);
$secondToken = isset($secondMatches[1]) ? rawurldecode($secondMatches[1]) : '';
assert_patient_link($secondToken !== '' && $secondToken !== $firstToken, 'The refreshed link did not use a new token.');
assert_patient_link(
    (string) get_user_meta(9, '_cliniko_link_token_hash', true) !== $firstTokenHash,
    'The stored token hash was not rotated.'
);

$oldVerification = $returningLinks->verify(9, $firstToken);
assert_patient_link(!$oldVerification['ok'], 'The earlier verification link remained valid after a resend.');
$newVerification = $returningLinks->verify(9, $secondToken);
assert_patient_link($newVerification['ok'], 'The newest verification link was not accepted.');

$GLOBALS['patient_link_test_users'][10] = new WP_User(10, 'alex@example.test', ['patient']);
$socialLinks = new PatientLinkService(new PatientLinkFakeClient());
$socialLinks->beginVerification(10, 'patient_1');
update_user_meta(10, '_cliniko_link_expires_at', time() - 1);
update_user_meta(10, '_cliniko_link_last_sent_at', time() - 61);
$mailCountBeforeSocialRetry = count($GLOBALS['patient_link_test_mail']);
$passwordResult = PatientLinkService::handleSocialAuthentication(
    $GLOBALS['patient_link_test_users'][10],
    'alex@example.test',
    'a-password'
);
assert_patient_link(
    $passwordResult instanceof WP_User && count($GLOBALS['patient_link_test_mail']) === $mailCountBeforeSocialRetry,
    'A regular password authentication attempt triggered the social verification resend.'
);
$socialResult = PatientLinkService::handleSocialAuthentication(
    $GLOBALS['patient_link_test_users'][10],
    'alex@example.test',
    ''
);
assert_patient_link(
    $socialResult instanceof WP_Error
        && $socialResult->code === 'cliniko_patient_verification_resent',
    'An expired verification was not resent during Nextend authentication.'
);
assert_patient_link(
    count($GLOBALS['patient_link_test_mail']) === $mailCountBeforeSocialRetry + 1,
    'The Nextend retry did not send exactly one replacement verification email.'
);

$GLOBALS['patient_link_test_users'][11] = new WP_User(11, 'alex@example.test', ['patient']);
update_user_meta(11, PatientService::META_ACCOUNT_STATUS, PatientService::ACCOUNT_PENDING_REVIEW);
PatientLinkService::handleNextendPatientRegistered(11);
PatientLinkService::suppressNextendPatientWelcomeEmail(11);
assert_patient_link(
    has_action('register_new_user', 'wp_send_new_user_notifications') === false,
    'The WordPress patient password email was not suppressed during Nextend registration.'
);
assert_patient_link(
    ($GLOBALS['patient_link_test_new_user_notifications'][0]['notify'] ?? '') === 'admin',
    'The administrator-only new patient notification was not preserved.'
);
PatientLinkService::restoreNewUserNotification(11);
assert_patient_link(
    has_action('register_new_user', 'wp_send_new_user_notifications') === 10,
    'The WordPress notification hook was not restored after Nextend registration.'
);

unset($GLOBALS['patient_link_test_options'][PatientLinkService::OPTION_VERIFICATION_PAGE_URL]);
$GLOBALS['patient_link_test_users'][12] = new WP_User(12, 'alex@example.test', ['patient']);
$fallbackMailIndex = count($GLOBALS['patient_link_test_mail']);
(new PatientLinkService(new PatientLinkFakeClient()))->beginVerification(12, 'patient_1');
$fallbackMessage = $GLOBALS['patient_link_test_mail'][$fallbackMailIndex]['message'] ?? '';
assert_patient_link(
    str_contains($fallbackMessage, '/wp-admin/admin-post.php?action=' . PatientLinkService::EMAIL_VERIFY_ACTION)
        && !str_contains($fallbackMessage, '/wp-json/v2/patient-link/verify'),
    'The unconfigured verification-page fallback still exposed the raw REST response.'
);

echo "Patient link service tests passed.\n";
