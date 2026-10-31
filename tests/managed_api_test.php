<?php
declare(strict_types=1);

namespace App\Authorization {
    // Isolate the transport policy from live Cliniko calls; real patient-link
    // behavior has its own patient_link_service_test and route tests.
    final class VerifiedPatientPolicy {
        public function authorize(\WP_REST_Request $request) {
            $id = get_current_user_id();
            $newVerified = $id >= 100 && get_user_meta($id, '_cliniko_link_status', true) === 'verified' && get_user_meta($id, '_cliniko_patient_account_status', true) === 'active';
            return $id === 7 || $newVerified ? true : new \WP_Error('patient_denied', 'Patient denied', ['status' => 403]);
        }
    }
}
namespace App\Support {
    final class Auth {
        public static function patientData(): ?array { return ['id' => $GLOBALS['linked_patient_id'] ?? '100', 'first_name' => 'Alex', 'email' => 'alex@example.test', 'medicare' => 'sensitive', 'custom_fields' => ['sensitive']]; }
    }
}
namespace {
define('ABSPATH', __DIR__ . '/../');
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');

class WP_Error {
    public function __construct(private string $code, private string $message, private array $data = []) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): array { return $this->data; }
}
class WP_User {
    public string $user_pass;
    public string $display_name = 'Alex Example';
    public string $user_email = 'alex@example.test';
    public array $roles = ['patient'];
    public function __construct(public int $ID) { $this->user_pass = $GLOBALS['patient_password_hash'] ?? 'wordpress-patient-password-hash'; }
    public function has_cap(string $capability): bool { return user_can($this, $capability); }
}
class WP_Role { public function __construct(public string $name, public array $capabilities) {} }
class WP_REST_Request {
    private array $params = [], $headers = [], $urlParams = [];
    private string $body = '';
    public function __construct(private string $method = 'GET', private string $route = '') {}
    public function get_method(): string { return $this->method; }
    public function get_route(): string { return $this->route; }
    public function get_body(): string { return $this->body; }
    public function set_body(string $body): void { $this->body = $body; $this->params = array_merge($this->params, (array) json_decode($body, true)); }
    public function get_param(string $key) { return $this->params[$key] ?? $this->urlParams[$key] ?? null; }
    public function set_param(string $key, $value): void { $this->params[$key] = $value; }
    public function get_header(string $key): string { return $this->headers[strtolower($key)] ?? ''; }
    public function set_header(string $key, string $value): void { $this->headers[strtolower($key)] = $value; }
    public function get_url_params(): array { return $this->urlParams; }
    public function set_url_params(array $params): void { $this->urlParams = $params; }
}
class WP_REST_Response {
    public array $headers = [];
    public function __construct(private $data, private int $status = 200) {}
    public function get_data() { return $this->data; }
    public function set_data($data): void { $this->data = $data; }
    public function get_status(): int { return $this->status; }
    public function header(string $key, string $value): void { $this->headers[$key] = $value; }
}
class ApiFakeDatabase {
    public string $prefix = 'wp_';
    public array $counts = [], $audit = [], $queries = [], $keys = [], $sessions = [], $spentRefresh = [];
    public bool $sessionWriteFails = false;
    public int $lastCount = 0;
    public bool $lockAvailable = true;
    public function prepare(string $sql, ...$args): string { return json_encode([$sql, $args]); }
    public function query(string $prepared) {
        [$sql, $args] = json_decode($prepared, true);
        $this->queries[] = $sql;
        if (str_contains($sql, 'cliniko_api_idempotency')) {
            $this->keys[$args[0]] = $this->keys[$args[0]] ?? $args[1];
            return 1;
        }
        if (str_contains($sql, 'cliniko_api_rate')) {
            $key = $args[0] . '-' . $args[1];
            $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;
            $this->lastCount = $this->counts[$key];
        }
        return 1;
    }
    public function get_var(string $sql) {
        if ($sql === 'SELECT LAST_INSERT_ID()') return $this->lastCount;
        [$query] = json_decode($sql, true);
        $this->queries[] = $query;
        if (str_contains($query, 'SELECT request_hash')) { [, $args] = json_decode($sql, true); return $this->keys[$args[0]] ?? null; }
        if (str_contains($query, 'cliniko_api_spent_refresh')) { [, $args] = json_decode($sql, true); return $this->spentRefresh[$args[0]]['session_id'] ?? null; }
        return str_contains($query, 'GET_LOCK') ? ($this->lockAvailable ? 1 : 0) : 1;
    }
    public function insert(string $table, array $data) {
        if ($this->sessionWriteFails && str_contains($table, 'cliniko_api_')) return false;
        if (str_ends_with($table, 'cliniko_api_sessions')) $this->sessions[$data['id']] = $data;
        elseif (str_ends_with($table, 'cliniko_api_spent_refresh')) { if (isset($this->spentRefresh[$data['token_hash']])) return false; $this->spentRefresh[$data['token_hash']] = $data; }
        else $this->audit[] = $data;
        return 1;
    }
    public function get_row(string $sql, $format = null) { [, $args] = json_decode($sql, true); return $this->sessions[$args[0]] ?? null; }
    public function update(string $table, array $data, array $where) {
        if ($this->sessionWriteFails) return false;
        $row = $this->sessions[$where['id']] ?? null;
        if (!$row) return 0;
        foreach ($where as $key => $value) if ($row[$key] !== $value) return 0;
        $this->sessions[$where['id']] = array_replace($row, $data); return 1;
    }
    public function get_results(string $sql, $format = null): array { return str_contains($sql, 'cliniko_api_sessions') ? array_values($this->sessions) : $this->audit; }
}

function get_option(string $name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function update_option(string $name, $value, $autoload = true): bool { $GLOBALS['options'][$name] = $value; return true; }
function add_option(string $name, $value, $deprecated = '', $autoload = true): bool { if (array_key_exists($name, $GLOBALS['options'])) return false; $GLOBALS['options'][$name] = $value; return true; }
function wp_salt(string $type = ''): string { return 'unit-test-salt'; }
function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_parse_url(string $url, int $component = -1) { return parse_url($url, $component); }
function wp_http_validate_url(string $url) { return preg_match('#^https://[a-z][a-z0-9.-]+(?:/.*)?$#i', $url) ? $url : false; }
function rest_url(string $path = ''): string { return 'https://example.test/wp-json/' . $path; }
function home_url(): string { return 'https://example.test'; }
function is_ssl(): bool { return true; }
function wp_get_environment_type(): string { return $GLOBALS['environment_type'] ?? 'production'; }
function get_current_user_id(): int { return $GLOBALS['current_user'] ?? 0; }
function wp_set_current_user(int $id): void { $GLOBALS['current_user'] = $id; }
function get_userdata(int $id) { return $GLOBALS['registered_users'][$id] ?? (in_array($id, [7, 8], true) ? new WP_User($id) : false); }
function user_can($user, string $capability): bool { return (int) $user->ID === 8; }
function get_users(array $args): array { return [new WP_User(7)]; }
function wp_authenticate(string $identifier, string $password) { foreach ($GLOBALS['registered_users'] ?? [] as $user) if (strcasecmp($user->user_email, $identifier) === 0 && password_verify($password, $user->user_pass)) return $user; return $identifier === 'alex@example.test' && $password === 'patient-password' ? new WP_User($GLOBALS['auth_user_id'] ?? 7) : new WP_Error('bad_credentials', 'Invalid credentials'); }
function apply_filters(string $hook, $value, ...$args) { return $hook === 'wp_cliniko_api_app_login_requires_browser' ? ($GLOBALS['mfa_blocked'] ?? $value) : $value; }
function get_user_meta(int $id, string $key, bool $single = false) { if (isset($GLOBALS['registered_users'][$id])) return $GLOBALS['registered_meta'][$id][$key] ?? ''; return $key === \App\Service\PatientService::META_ACCOUNT_STATUS ? ($GLOBALS['user_status'] ?? 'active') : ($GLOBALS['user_meta'][$key] ?? ''); }
function update_user_meta(int $id, string $key, $value): bool { $GLOBALS['registered_meta'][$id][$key] = $value; return true; }
function delete_user_meta(int $id, string $key): bool { unset($GLOBALS['registered_meta'][$id][$key]); return true; }
function get_role(string $role) { return $GLOBALS['registration_roles'][$role] ?? ($role === 'patient' ? new WP_Role('patient', ['read' => true]) : null); }
function is_email(string $email) { return filter_var($email, FILTER_VALIDATE_EMAIL); }
function email_exists(string $email) { foreach ($GLOBALS['registered_users'] ?? [] as $id => $user) if (strcasecmp($user->user_email, $email) === 0) return $id; return strcasecmp($email, 'alex@example.test') === 0 ? 7 : false; }
function wp_slash($value) { return is_array($value) ? array_map('wp_slash', $value) : addslashes($value); }
function wp_insert_user(array $data) {
    if (!empty($GLOBALS['registration_insert_error'])) return new WP_Error('db_error', 'Synthetic database error');
    $id = 100 + count($GLOBALS['registered_users']); $user = new WP_User($id);
    $user->user_email = stripslashes($data['user_email']); $user->display_name = stripslashes($data['display_name']); $user->roles = [$data['role']]; $user->user_pass = password_hash($data['user_pass'], PASSWORD_DEFAULT);
    $GLOBALS['registered_users'][$id] = $user;
    $GLOBALS['registered_meta'][$id] = $data['meta_input'] + ['first_name' => stripslashes($data['first_name']), 'last_name' => stripslashes($data['last_name'])];
    $GLOBALS['registration_inserts'][] = $data;
    \App\Service\PatientService::handleUserRegistered($id); // The production defer wrapper must prevent early Cliniko calls.
    return $id;
}
function wp_cliniko_get_secret_option(string $key): string { if ($key === 'wp_cliniko_api_patient_signing_key') return $GLOBALS['options'][$key] ?? ''; return $key === 'wp_cliniko_api_key' ? ($GLOBALS['cliniko_key'] ?? 'unit-test-au1') : ($GLOBALS['oauth_secret'] ?? 'introspection-secret'); }
function wp_safe_remote_get(string $url, array $args) { $GLOBALS['social_key_calls'][] = [$url, $args]; return $GLOBALS['social_keys_response'] ?? new WP_Error('unavailable', 'Unavailable'); }
function wp_safe_remote_post(string $url, array $args) { $GLOBALS['introspection_calls'][] = [$url, $args]; return $GLOBALS['introspection_response'] ?? ['status' => 200, 'body' => json_encode($GLOBALS['claims'])]; }
function wp_remote_retrieve_response_code(array $response): int { return $response['status']; }
function wp_remote_retrieve_body(array $response): string { return $response['body']; }
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function add_action(string $hook, $callback, int $priority = 10, int $args = 1): void {}
function add_filter(string $hook, $callback, int $priority = 10, int $args = 1): void {}
function rest_get_server() { return new class { public function get_namespaces(): array { return $GLOBALS['occupied'] ?? []; } }; }
function register_rest_route(string $namespace, string $route, array $args): void { $GLOBALS['routes'][] = compact('namespace', 'route', 'args'); }
function current_user_can(string $capability): bool { return get_current_user_id() === 7; }
function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value): string { return esc_html($value); }
function esc_url($value): string { return esc_html($value); }
function esc_textarea($value): string { return esc_html($value); }
function wp_json_encode($value, int $flags = 0) { return json_encode($value, $flags); }
function get_transient(string $key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient(string $key, $value, int $expiry): bool { $GLOBALS['transients'][$key] = $value; return true; }
function delete_transient(string $key): bool { $exists = isset($GLOBALS['transients'][$key]); unset($GLOBALS['transients'][$key]); return $exists; }
function admin_url(string $path = ''): string { return 'https://example.test/wp-admin/' . $path; }
function add_query_arg($args, $value = '', $url = ''): string { if (is_array($args)) return $value . '?' . http_build_query($args); return $url . '?' . http_build_query([$args => $value]); }
function wp_nonce_url(string $url, string $action): string { return $url . '&_wpnonce=test-nonce'; }
function wp_nonce_field(string $action): void { echo '<input type="hidden" name="_wpnonce" value="test-nonce">'; }
function checked($actual, $expected, bool $echo = true): string { return $actual === $expected ? 'checked' : ''; }
function selected($actual, $expected, bool $echo = true): string { return (string) $actual === (string) $expected ? 'selected' : ''; }
function submit_button(string $label): void { echo '<button>' . esc_html($label) . '</button>'; }
function wp_die(string $message, $title = '', $args = []): void { throw new \RuntimeException($message); }
function as_schedule_single_action(int $when, string $job, array $args, string $group): int { $GLOBALS['jobs'][] = compact('when', 'job', 'args', 'group'); return 1; }

require __DIR__ . '/../vendor/autoload.php';

class ApiFakeClinikoClient implements \App\Contracts\ApiClientInterface {
    public function get(string $url): \App\Contracts\ClientResponse {
        $GLOBALS['cliniko_calls'][] = $url;
        return isset($GLOBALS['cliniko_pages'][$url])
            ? new \App\Contracts\ClientResponse($GLOBALS['cliniko_pages'][$url])
            : new \App\Contracts\ClientResponse(null, 'Synthetic Cliniko failure', 503);
    }
    public function post(string $url, array $data): \App\Contracts\ClientResponse { throw new \RuntimeException('Admin picker must not write to Cliniko'); }
    public function put(string $url, array $data): \App\Contracts\ClientResponse { throw new \RuntimeException('Admin picker must not write to Cliniko'); }
    public function patch(string $url, array $data): \App\Contracts\ClientResponse { throw new \RuntimeException('Admin picker must not write to Cliniko'); }
}
function cliniko_client(bool $withCache = false, int $ttl = 300): \App\Contracts\ApiClientInterface { return new ApiFakeClinikoClient(); }

use App\Authorization\Api\AccessPolicy;
use App\Controller\Api\ManagedController;
use App\Routes\ManagedApiRoutes;
use App\Service\Api\ApiSettings;
use App\Service\Api\ApiClients;
use App\Service\Api\ApiRateLimiter;
use App\Service\Api\ApiAudit;
use App\Service\Api\ApiIdempotency;
use App\Service\Api\EndpointCatalog;
use App\Service\Api\OperationLock;
use App\Service\Api\PatientSessions;
use App\Service\Api\PatientAuthentication;
use App\Service\BookingAttemptStore;
use App\Service\PatientAccessRequestStateService;
use App\Service\PublicRequestGuard;
use App\Admin\Modules\ApiAccess\ApiAccess;
use App\Workers\BookingAttemptCleanupWorker;

function check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
function endpoint(string $key): array { foreach (EndpointCatalog::definitions() as $item) if ($item['key'] === $key) return $item; throw new \RuntimeException('Unknown operation'); }
function resetApi(): void {
    $GLOBALS['environment_type'] = 'production';
    $GLOBALS['options'] = ['wp_cliniko_api_schema' => '2', 'wp_cliniko_patient_sync_roles' => ['patient']];
    $GLOBALS['wpdb'] = new ApiFakeDatabase();
    $GLOBALS['current_user'] = 0;
    $GLOBALS['user_status'] = 'active';
    $GLOBALS['linked_patient_id'] = '100';
    $GLOBALS['occupied'] = $GLOBALS['routes'] = $GLOBALS['introspection_calls'] = [];
    $GLOBALS['jobs'] = [];
    $GLOBALS['transients'] = $GLOBALS['cliniko_calls'] = [];
    $GLOBALS['cliniko_key'] = 'unit-test-au1';
    $GLOBALS['oauth_secret'] = 'introspection-secret';
    $GLOBALS['cliniko_pages'] = ['appointment_types?per_page=100' => ['appointment_types' => [
        ['id' => '123', 'name' => 'Consultation', 'duration_in_minutes' => 30],
        ['id' => '124', 'name' => 'Follow-up', 'duration_in_minutes' => 15],
    ], 'links' => ['next' => null]], 'patient_form_templates?per_page=100' => ['patient_form_templates' => [
        ['id' => '456', 'name' => 'Intake questionnaire', 'restricted_to_practitioner' => false],
        ['id' => '457', 'name' => 'Follow-up questionnaire', 'restricted_to_practitioner' => false],
    ], 'links' => ['next' => null]]];
    $_GET = [];
    $GLOBALS['mfa_blocked'] = false; $GLOBALS['auth_user_id'] = 7; $GLOBALS['user_meta'] = [];
    $GLOBALS['patient_password_hash'] = 'wordpress-patient-password-hash';
    $GLOBALS['social_key_calls'] = [];
    $GLOBALS['registered_users'] = $GLOBALS['registered_meta'] = $GLOBALS['registration_inserts'] = $GLOBALS['registration_roles'] = [];
    $GLOBALS['registration_insert_error'] = false;
    unset($GLOBALS['social_keys_response']);
    unset($GLOBALS['introspection_response']);
    ManagedApiRoutes::$collisions = [];
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    $GLOBALS['claims'] = ['active' => true, 'token_type' => 'Bearer', 'iss' => 'https://identity.example.test', 'aud' => 'cliniko-api', 'sub' => 'patient-seven', 'client_id' => 'mobile-client', 'exp' => time() + 600, 'scope' => 'patient:read appointments:read booking:create booking:read'];
}
function enableApi(): void {
    update_option(ApiSettings::OPTION, array_replace(ApiSettings::defaults(), ['enabled' => true, 'groups' => array_keys(EndpointCatalog::groups()), 'appointment_types' => ['123'], 'form_templates' => ['456'], 'oauth_enabled' => true, 'issuer' => 'https://identity.example.test', 'audience' => 'cliniko-api', 'introspection_url' => 'https://identity.example.test/introspect', 'introspection_client' => 'resource-client']));
}
function oauthRequest(): WP_REST_Request {
    ApiClients::create('Mobile', 'oauth', 'mobile-client', ['patient:read', 'appointments:read', 'booking:create', 'booking:read'], time() + 3600);
    ApiClients::map('https://identity.example.test', 'patient-seven', 7);
    $request = new WP_REST_Request('GET', '/cliniko-api/v1/me');
    $request->set_header('authorization', 'Bearer opaque-test-token');
    return $request;
}

function patientApp(): array {
    enableApi();
    update_option(PatientSessions::SCHEMA_OPTION, '1');
    update_option(PatientSessions::KEY_OPTION, str_repeat('a', 64));
    $s = ApiSettings::get();
    $s['password_login_enabled'] = $s['password_login_reviewed'] = $s['google_login_enabled'] = $s['apple_login_enabled'] = true;
    update_option(ApiSettings::OPTION, $s);
    return ApiClients::create('Patient mobile', 'patient', '', ['patient:read', 'appointments:read', 'booking:create', 'booking:read'], time() + 30 * DAY_IN_SECONDS, ['google_client_id' => 'test-client.apps.googleusercontent.com', 'apple_client_id' => 'com.example.patient'])['client'];
}
function authRequest(array $body): WP_REST_Request { $r = new WP_REST_Request('POST'); $r->set_body(json_encode($body)); return $r; }
function passwordLogin(array $client) { return PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'identifier' => 'alex@example.test', 'password' => 'patient-password']), 'auth_login'); }
function socialToken(array $claims, string $kid = 'test-key'): string {
    static $privateKey = null, $jwks = null;
    if ($privateKey === null) {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $localConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (is_file($localConfig)) $config['config'] = $localConfig;
        $resource = openssl_pkey_new($config);
        if ($resource === false) throw new \RuntimeException('Test RSA key generation failed: ' . openssl_error_string());
        openssl_pkey_export($resource, $privateKey, null, $config);
        $details = openssl_pkey_get_details($resource);
        $base64 = static fn(string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $jwks = ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'kid' => 'test-key', 'use' => 'sig', 'n' => $base64($details['rsa']['n']), 'e' => $base64($details['rsa']['e'])]]];
    }
    $rotated = $jwks; $rotated['keys'][0]['kid'] = $kid;
    $GLOBALS['social_keys_response'] = ['status' => 200, 'body' => json_encode($rotated)];
    return \Firebase\JWT\JWT::encode($claims, $privateKey, 'RS256', $kid);
}

// Share synthetic WordPress fixtures with focused onboarding tests, without executing this suite.
if (defined('CLINIKO_API_TEST_BOOTSTRAP_ONLY')) return;

// Read-only HTML fixtures for browser interaction tests; no WordPress database is booted.
if (isset($argv[1]) && str_starts_with($argv[1], '--render-admin=')) {
    resetApi();
    $GLOBALS['current_user'] = 7;
    $fixture = substr($argv[1], strlen('--render-admin='));
    $_GET['tab'] = $fixture === 'booking-edit' ? 'booking-flows' : (in_array($fixture, ['login-ready', 'patient-login', 'wordpress', 'social', 'sessions', 'registration'], true) ? 'patient-login' : $fixture);
    if (in_array($fixture, ['login-ready', 'patient-login'], true)) $_GET['section'] = 'oauth';
    if (in_array($fixture, ['wordpress', 'social', 'sessions', 'registration'], true)) $_GET['section'] = $fixture;
    if ($fixture === 'settings') update_option(ApiSettings::OPTION, array_replace(ApiSettings::defaults(), ['appointment_types' => ['123', '999'], 'form_templates' => ['456', '9998']]));
    if ($fixture === 'login-ready') {
        enableApi();
        $GLOBALS['introspection_response'] = ['status' => 200, 'body' => '{"active":false}'];
        \App\Admin\Modules\ApiAccess\PatientLoginSetup::checkSavedConnection();
    }
    if (in_array($fixture, ['booking-flows', 'booking-edit'], true)) {
        $config = ['name' => 'API consultation', 'appointment_type_id' => '123', 'patient_form_template_id' => '456', 'gateway' => 'stripe', 'scheduling_mode' => 'manual'];
        $GLOBALS['cliniko_pages']['patient_form_templates/456'] = ['id' => '456', 'name' => 'Intake', 'content' => ['sections' => [['name' => 'Questions', 'questions' => [['name' => 'Symptoms', 'type' => 'text', 'required' => true], ['name' => 'Date of concern', 'type' => 'date', 'required' => false]]]]]];
        \App\Service\Api\BookingFlows::saveApi('api-consultation', $config, false);
        update_option(\App\Service\PatientBookingConfiguration::OPTION, ['web-setup' => $config + ['booking_mode' => 'patient', 'flow_type' => 'web']]);
        \App\Service\Api\BookingFlows::save('legacy-consultation', 'web-setup', false);
        if ($fixture === 'booking-edit') $_GET['section'] = 'api-consultation';
    }
    ApiAccess::render();
    exit(0);
}

$tests = [
    'disabled-by-default and docs match registration' => function (): void {
        $routes = new ManagedApiRoutes(); $routes->register();
        check($GLOBALS['routes'] === [], 'Default must expose no new routes');
        check((array) EndpointCatalog::openApi()['paths'] === [], 'Disabled API must produce no active OpenAPI operations');
        enableApi(); $routes->register();
        check(count($GLOBALS['routes']) === count(array_filter(EndpointCatalog::definitions(), static fn($item) => EndpointCatalog::state($item) === 'Active')), 'Every active definition must be registered exactly once');
        $operations = 0; foreach ((array) EndpointCatalog::openApi()['paths'] as $path) $operations += count($path);
        check($operations === count($GLOBALS['routes']), 'OpenAPI and runtime operation counts differ');
        foreach ($GLOBALS['routes'] as $route) {
            check(is_callable($route['args']['permission_callback']), 'A permission callback is required');
            foreach ($route['args']['args'] as $arg) check(is_callable($arg['validate_callback']), 'All parameters need schema validation');
        }
        $s = ApiSettings::get(); $s['groups'] = ['patient']; update_option(ApiSettings::OPTION, $s);
        check(count((array) EndpointCatalog::openApi()['paths']) === 16, 'Disabled discovery/booking routes must disappear from export');
    },
    'HTTP browser origins are limited to local or development and rejected after environment promotion' => function (): void {
        foreach (['local', 'development', 'staging', 'production'] as $environment) {
            $GLOBALS['environment_type'] = $environment;
            foreach (['http://localhost:3000', 'http://127.0.0.1:8081', 'http://[::1]:5173', 'http://dev.example.test:8000'] as $origin) {
                $settings = ApiSettings::validateGeneral(['slug' => 'cliniko-api', 'origins' => $origin]);
                check(is_wp_error($settings) === !in_array($environment, ['local', 'development'], true), 'Wrong HTTP origin policy in ' . $environment);
                if (!is_wp_error($settings)) check($settings['origins'] === [$origin], 'HTTP origin or port not preserved');
            }
            check(!is_wp_error(ApiSettings::validateGeneral(['slug' => 'cliniko-api', 'origins' => 'https://portal.example.test:8443'])), 'HTTPS origin rejected in ' . $environment);
            foreach (['http://localhost:3000/login', 'http://localhost?test=1', 'http://localhost#fragment', 'http://user:password@localhost', 'http://*.example.test', 'https://*.example.test', 'ftp://localhost', 'null'] as $bad) {
                check(is_wp_error(ApiSettings::validateGeneral(['slug' => 'cliniko-api', 'origins' => $bad])), 'Invalid origin accepted in ' . $environment . ': ' . $bad);
            }
        }
        enableApi(); $GLOBALS['environment_type'] = 'development';
        $settings = ApiSettings::get(); $settings['origins'] = ['http://localhost:3000', 'https://portal.example.test']; update_option(ApiSettings::OPTION, $settings);
        $request = new WP_REST_Request('GET'); $request->set_header('origin', 'http://localhost:3000');
        check(ApiSettings::browserOriginAllowed('http://localhost:3000'), 'Saved development origin denied');
        check((new AccessPolicy())->authorize($request, endpoint('status')) === true, 'Development origin cannot call API');
        $request->set_header('origin', 'http://localhost:3001');
        check(is_wp_error((new AccessPolicy())->authorize($request, endpoint('status'))), 'Origin port mismatch accepted');
        $request->set_header('origin', 'http://localhost:3000');
        foreach (['staging', 'production'] as $environment) {
            $GLOBALS['environment_type'] = $environment;
            check(!ApiSettings::browserOriginAllowed('http://localhost:3000'), 'Saved HTTP origin accepted after promotion');
            check((new AccessPolicy())->authorize($request, endpoint('status'))->get_error_code() === 'origin_denied', 'Runtime did not reject development origin after promotion');
            check(ApiSettings::browserOriginAllowed('https://portal.example.test'), 'HTTPS origin denied after promotion');
        }
    },
    'namespace validation, migration and collisions' => function (): void {
        check(is_wp_error(ApiSettings::validate(['slug' => 'wp'])), 'Reserved slug accepted');
        check(is_wp_error(ApiSettings::validate(['slug' => 'api'], ['api/v1'])), 'Collision accepted');
        enableApi(); check(is_wp_error(ApiSettings::validate(['slug' => 'api'])), 'Enabled namespace changed without confirmation');
        $s = ApiSettings::validate(['slug' => 'api', 'enabled' => 1, 'confirm_namespace_change' => 1]);
        check(!is_wp_error($s) && isset($s['aliases']['cliniko-api']), 'Old namespace must retain an expiring alias');
        update_option(ApiSettings::OPTION, $s); check(ApiSettings::namespaces() === ['api/v1', 'cliniko-api/v1'], 'Alias namespace mismatch');
        enableApi(); $GLOBALS['occupied'] = ['cliniko-api/v1']; (new ManagedApiRoutes())->register();
        check($GLOBALS['routes'] === [] && ManagedApiRoutes::$collisions === ['cliniko-api/v1'], 'Collision must not override another plugin');
    },
    'server key scope, rotation, expiry and revocation' => function (): void {
        enableApi(); $result = ApiClients::create('Backend', 'server', '', ['catalog:read', 'patient:read', 'booking:create', 'booking:read'], time() + 3600);
        check($result['client']['scopes'] === ['catalog:read'], 'Server key received patient scopes');
        check(!str_contains(json_encode(ApiClients::all()), $result['secret']), 'Plaintext credential persisted');
        $request = new WP_REST_Request(); $request->set_header('authorization', 'Bearer ' . $result['secret']);
        $policy = new AccessPolicy(); check($policy->authorize($request, endpoint('appointment_types')) === true, 'Scoped server request denied');
        check(is_wp_error($policy->authorize($request, endpoint('me'))), 'Server key reached patient account');
        $rotated = ApiClients::rotate($result['client']['id']); check(ApiClients::server($result['secret']) === null && ApiClients::server($rotated) !== null, 'Rotation failed');
        ApiClients::revoke($result['client']['id']); check(ApiClients::server($rotated) === null, 'Revoked key accepted');
    },
    'OAuth claims, scope and subject must all match' => function (): void {
        enableApi(); $request = oauthRequest(); $policy = new AccessPolicy();
        check($policy->authorize($request, endpoint('me')) === true, 'Valid OAuth identity rejected');
        check(get_current_user_id() === 0 && $request->get_param('_cliniko_api_user') === 7, 'Global user context leaked from policy');
        $call = $GLOBALS['introspection_calls'][0]; check($call[1]['redirection'] === 0 && $call[1]['timeout'] === 10, 'Introspection transport must be bounded and not follow redirects');
        foreach (['active' => false, 'token_type' => 'refresh_token', 'iss' => 'https://other.test', 'aud' => 'another-api', 'sub' => 'someone-else', 'scope' => '', 'client_id' => 'other-client', 'exp' => time() - 1] as $name => $bad) {
            $old = $GLOBALS['claims'][$name]; $GLOBALS['claims'][$name] = $bad;
            check(is_wp_error($policy->authorize($request, endpoint('me'))), 'Invalid claim accepted: ' . $name);
            $GLOBALS['claims'][$name] = $old;
        }
        $GLOBALS['user_status'] = 'inactive'; check(is_wp_error($policy->authorize($request, endpoint('me'))), 'Inactive patient accepted');
        $GLOBALS['user_status'] = 'active'; ApiClients::map('https://identity.example.test', 'patient-seven', 0);
        check(is_wp_error($policy->authorize($request, endpoint('me'))), 'Removed subject mapping accepted');
    },
    'credentials cannot be bypassed by cookies or parameter injection' => function (): void {
        enableApi(); $GLOBALS['current_user'] = 7;
        $request = new WP_REST_Request(); $request->set_param('_cliniko_api_user', 7);
        $denied = (new AccessPolicy())->authorize($request, endpoint('me'));
        check(is_wp_error($denied) && $denied->get_error_data()['status'] === 401, 'Cookie/parameter bypass accepted');
        $request->set_header('authorization', 'Bearer invalid');
        $s = ApiSettings::get(); $s['oauth_enabled'] = false; update_option(ApiSettings::OPTION, $s);
        check(is_wp_error((new AccessPolicy())->authorize($request, endpoint('appointment_types'))), 'Invalid credentials silently downgraded to anonymous');
    },
    'provider connection test uses an invalid synthetic token' => function (): void {
        enableApi(); $GLOBALS['introspection_response'] = ['status' => 200, 'body' => '{"active":false}'];
        check(AccessPolicy::testProvider() === true, 'Correct inactive response rejected');
        check(str_starts_with($GLOBALS['introspection_calls'][0][1]['body']['token'], 'cliniko-connectivity-test-'), 'Provider test used a real credential');
        $GLOBALS['introspection_response'] = ['status' => 200, 'body' => '{"active":true}'];
        check(is_wp_error(AccessPolicy::testProvider()), 'Suspicious active synthetic token accepted');
    },
    'rate limit ignores caller-controlled forwarded IP and agent' => function (): void {
        enableApi(); $limiter = new ApiRateLimiter();
        for ($i = 0; $i < 3; $i++) {
            $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.' . $i; $_SERVER['HTTP_USER_AGENT'] = 'agent-' . $i;
            check($limiter->consume(ApiRateLimiter::remoteAddress(), 'test', 3), 'Within-budget request rejected');
        }
        check(!$limiter->consume(ApiRateLimiter::remoteAddress(), 'test', 3), 'Header spoofing reset the budget');
        check(str_contains($GLOBALS['wpdb']->queries[0], 'ON DUPLICATE KEY'), 'Counter must be atomic in the shared database');
    },
    'attempt token and original application binding are enforced' => function (): void {
        enableApi(); $id = str_repeat('a', 32); $secret = 'attempt-secret';
        $request = oauthRequest(); $client = ApiClients::oauth('mobile-client');
        (new BookingAttemptStore())->save($id, ['created_at' => gmdate(DATE_ATOM), 'attempt_token_hash' => PublicRequestGuard::hashAttemptToken($secret), 'api_namespace' => ApiSettings::namespace(), 'api_client_id' => 'another-app', 'patient_mode' => 'account', 'user_id' => 7, 'patient_id' => '100']);
        $request->set_url_params(['id' => $id]); $request->set_header('x-es-attempt-token', $secret);
        check(is_wp_error((new AccessPolicy())->authorize($request, endpoint('booking_status'))), 'Original app binding bypassed');
        (new BookingAttemptStore())->update($id, ['api_client_id' => '']);
        check(is_wp_error((new AccessPolicy())->authorize($request, endpoint('booking_status'))), 'Unbound attempt accepted');
        (new BookingAttemptStore())->update($id, ['api_client_id' => $client['id']]);
        check((new AccessPolicy())->authorize($request, endpoint('booking_status')) === true, 'Original verified patient attempt denied');
        $request->set_header('x-es-attempt-token', 'wrong'); check(is_wp_error((new AccessPolicy())->authorize($request, endpoint('booking_status'))), 'Forged attempt token accepted');
        $request->set_header('x-es-attempt-token', $secret);
        (new BookingAttemptStore())->update($id, ['created_at' => gmdate(DATE_ATOM, time() - 90000)]);
        check(is_wp_error((new AccessPolicy())->authorize($request, endpoint('booking_status'))), 'Expired attempt access accepted');
    },
    'patient responses omit sensitive fields and restore user context' => function (): void {
        enableApi(); $request = oauthRequest(); check((new AccessPolicy())->authorize($request, endpoint('me')) === true, 'Policy failed');
        $response = (new ManagedController())->dispatch($request, endpoint('me'));
        check($response->get_status() === 200 && get_current_user_id() === 0, 'Controller did not restore user');
        check(!isset($response->get_data()['data']['medicare'], $response->get_data()['data']['custom_fields']), 'Sensitive field exposed');
    },
    'account attempt requires the original active verified patient' => function (): void {
        enableApi(); $request = oauthRequest(); $id = str_repeat('b', 32);
        $client = ApiClients::oauth('mobile-client');
        (new BookingAttemptStore())->save($id, ['created_at' => gmdate(DATE_ATOM), 'attempt_token_hash' => PublicRequestGuard::hashAttemptToken('secret'), 'api_namespace' => ApiSettings::namespace(), 'api_client_id' => $client['id'], 'patient_mode' => 'account', 'user_id' => 7, 'patient_id' => '100']);
        $request->set_url_params(['id' => $id]); $request->set_header('x-es-attempt-token', 'secret');
        check((new AccessPolicy())->authorize($request, endpoint('finalize')) === true, 'Original patient attempt rejected');
        $GLOBALS['user_status'] = 'inactive';
        check(is_wp_error((new AccessPolicy())->authorize($request, endpoint('finalize'))), 'Inactive patient progressed account attempt');
        check(get_current_user_id() === 0, 'Account-attempt authorization leaked current user');
    },
    'managed booking creation is patient-only and guest routes stay absent including aliases' => function (): void {
        $client = patientApp();
        $s = ApiSettings::get(); $s['aliases'] = ['previous-api' => time() + DAY_IN_SECONDS]; update_option(ApiSettings::OPTION, $s);
        (new ManagedApiRoutes())->register();
        foreach ($GLOBALS['routes'] as $route) check($route['route'] !== '/booking-attempts', 'Guest creation route still registered');
        foreach (EndpointCatalog::definitions() as $endpoint) {
            check($endpoint['key'] !== 'booking_create', 'Guest creation remains in canonical catalog');
            if ($endpoint['group'] === 'booking' || $endpoint['group'] === 'patient_booking') check(in_array($endpoint['auth'], ['patient', 'attempt'], true), 'Anonymous booking operation remains');
            if ($endpoint['auth'] === 'public') check($endpoint['method'] === 'GET', 'Anonymous write operation remains');
        }
        $paths = (array) EndpointCatalog::openApi()['paths'];
        check(!isset($paths['/booking-attempts']), 'Guest creation still exported');
        foreach (['stripe', 'tyro', 'finalize', 'booking_status'] as $key) {
            $endpoint = endpoint($key);
            check($paths[$endpoint['path']][strtolower($endpoint['method'])]['security'] === [['oauthBearer' => [], 'bookingAttempt' => []]], 'OpenAPI must require both patient and attempt credentials');
        }
        check($paths['/payments/tyro-sdk-token']['post']['security'] === [['oauthBearer' => []]], 'SDK token lacks patient authentication');
        check(EndpointCatalog::openApi()['components']['securitySchemes']['bookingAttempt']['name'] === 'X-ES-Attempt-Token', 'Attempt security scheme missing');
        $tokens = passwordLogin($client);
        foreach (['patient_booking_create', 'sdk_token'] as $key) {
            $request = new WP_REST_Request('POST'); $request->set_body(json_encode(endpoint($key)['example']));
            $GLOBALS['current_user'] = 7; $request->set_param('_cliniko_api_user', 7);
            $denied = (new AccessPolicy())->authorize($request, endpoint($key));
            check(is_wp_error($denied) && $denied->get_error_data()['status'] === 401, 'Cookie or request-param bypass accepted for ' . $key);
            check((new ManagedController())->dispatch($request, endpoint($key))->get_status() === 401, 'Controller accepted missing policy identity for ' . $key);
            $request->set_header('authorization', 'Bearer ' . $tokens['access_token']);
            check((new AccessPolicy())->authorize($request, endpoint($key)) === true, 'Verified local JWT denied for ' . $key);
        }
        check($GLOBALS['cliniko_calls'] === [], 'Rejected creation or policy check wrote/read Cliniko');
    },
    'all attempt operations require both credentials and original verified patient app and Cliniko identity' => function (): void {
        enableApi(); $oauth = oauthRequest(); $client = ApiClients::oauth('mobile-client');
        $id = str_repeat('c', 32); $store = new BookingAttemptStore();
        $original = ['created_at' => gmdate(DATE_ATOM), 'attempt_token_hash' => PublicRequestGuard::hashAttemptToken('private-secret'), 'api_namespace' => ApiSettings::namespace(), 'api_client_id' => $client['id'], 'patient_mode' => 'account', 'user_id' => 7, 'patient_id' => '100'];
        $server = ApiClients::create('Metadata only', 'server', '', ['catalog:read', 'booking:create', 'booking:read'], time() + 3600);
        foreach (['stripe', 'tyro', 'finalize', 'booking_status'] as $key) {
            $GLOBALS['wpdb']->counts = []; $store->save($id, $original);
            $request = new WP_REST_Request(endpoint($key)['method']); $request->set_url_params(['id' => $id]); $request->set_header('x-es-attempt-token', 'private-secret');
            $policy = new AccessPolicy();
            $denied = $policy->authorize($request, endpoint($key));
            check(is_wp_error($denied) && $denied->get_error_data()['status'] === 401, 'Booking secret alone accepted for ' . $key);
            $request->set_header('authorization', 'Bearer ' . $server['secret']);
            check($policy->authorize($request, endpoint($key))->get_error_code() === 'patient_required', 'Server key accepted for ' . $key);
            $request->set_header('authorization', $oauth->get_header('authorization')); $request->set_header('x-es-attempt-token', '');
            check($policy->authorize($request, endpoint($key))->get_error_code() === 'invalid_attempt_token', 'Patient token alone accepted for ' . $key);
            $request->set_header('x-es-attempt-token', 'private-secret');
            check($policy->authorize($request, endpoint($key)) === true, 'Original verified patient denied for ' . $key);
            foreach (['patient_mode' => 'guest', 'user_id' => 8, 'patient_id' => '999', 'api_client_id' => '', 'api_namespace' => '', 'created_at' => gmdate(DATE_ATOM, time() - 90000)] as $field => $bad) {
                $store->save($id, array_replace($original, [$field => $bad]));
                check(is_wp_error($policy->authorize($request, endpoint($key))), 'Broken ' . $field . ' binding accepted for ' . $key);
            }
            $store->save($id, $original); $GLOBALS['linked_patient_id'] = '999';
            check($policy->authorize($request, endpoint($key))->get_error_code() === 'attempt_patient_mismatch', 'Relinked patient accessed old booking');
            $GLOBALS['linked_patient_id'] = '100'; $GLOBALS['user_status'] = 'inactive';
            check(is_wp_error($policy->authorize($request, endpoint($key))), 'Inactive patient accepted for ' . $key);
            $GLOBALS['user_status'] = 'active'; $GLOBALS['claims']['scope'] = 'patient:read';
            check($policy->authorize($request, endpoint($key))->get_error_code() === 'insufficient_scope', 'Missing booking scope accepted for ' . $key);
            $GLOBALS['claims']['scope'] = 'patient:read appointments:read booking:create booking:read';
            check(get_current_user_id() === 0, 'Attempt check leaked user context');
        }
        check($GLOBALS['cliniko_calls'] === [], 'Authorization made booking or payment calls');
        $GLOBALS['wpdb']->counts = [];
        $localClient = patientApp(); $tokens = passwordLogin($localClient);
        $store->save($id, array_replace($original, ['api_client_id' => $localClient['id']]));
        foreach (['stripe', 'tyro', 'finalize', 'booking_status'] as $key) {
            $request = new WP_REST_Request(endpoint($key)['method']); $request->set_url_params(['id' => $id]);
            $request->set_header('authorization', 'Bearer ' . $tokens['access_token']); $request->set_header('x-es-attempt-token', 'private-secret');
            check((new AccessPolicy())->authorize($request, endpoint($key)) === true, 'Local patient JWT denied for ' . $key);
        }
    },
    'unknown JSON fields and unpublished resource IDs are rejected' => function (): void {
        enableApi(); $controller = new ManagedController();
        $request = new WP_REST_Request('POST'); $request->set_body('{"paid":true}');
        $request->set_param('_cliniko_api_user', 7);
        check($controller->dispatch($request, endpoint('patient_booking_create'))->get_status() === 400, 'Client payment assertion accepted');
        $request = new WP_REST_Request('GET'); $request->set_url_params(['id' => '999']);
        check($controller->dispatch($request, endpoint('appointment_type'))->get_status() === 404, 'Unpublished resource exposed');
    },
    'database operation locks release on failure and prevent concurrent processing' => function (): void {
        $lock = new OperationLock(); $GLOBALS['wpdb']->lockAvailable = false;
        check(is_wp_error($lock->run('attempt', fn() => true)), 'Busy attempt processed');
        $GLOBALS['wpdb']->lockAvailable = true;
        try { $lock->run('attempt', function () { throw new \RuntimeException('test'); }); } catch (\RuntimeException $e) {}
        check(str_contains(end($GLOBALS['wpdb']->queries), 'RELEASE_LOCK'), 'Lock leaked after exception');
    },
    'idempotency key cannot finalize a different attempt for the same caller' => function (): void {
        check(ApiIdempotency::claim('app-one', 'key-example', 'attempt-one') === true, 'Initial key claim failed');
        check(ApiIdempotency::claim('app-one', 'key-example', 'attempt-one') === true, 'Identical retry rejected');
        check(is_wp_error(ApiIdempotency::claim('app-one', 'key-example', 'attempt-two')), 'Key reused for another attempt');
        check(ApiIdempotency::claim('app-two', 'key-example', 'attempt-two') === true, 'Unrelated caller conflicted');
    },
    'cleanup cannot race payment or blindly archive interrupted finalization' => function (): void {
        $store = new BookingAttemptStore(); $store->save('attempt', ['api_namespace' => 'cliniko-api/v1', 'status' => 'finalizing']);
        $GLOBALS['wpdb']->lockAvailable = false; BookingAttemptCleanupWorker::handle(['attempt_id' => 'attempt']);
        check(count($GLOBALS['jobs']) === 1, 'Busy cleanup should be retried, not race finalization');
        $GLOBALS['wpdb']->lockAvailable = true; BookingAttemptCleanupWorker::handle(['attempt_id' => 'attempt']);
        check($store->get('attempt')['progress']['code'] === 'review_required', 'Interrupted finalize requires review instead of cleanup/refund');
    },
    'response metadata and audit contain no bearer or patient payload' => function (): void {
        enableApi(); $request = new WP_REST_Request('GET', '/cliniko-api/v1/status'); $request->set_header('authorization', 'Bearer private-token');
        $routes = new ManagedApiRoutes(); $routes->register();
        $response = $routes->response(new WP_REST_Response(['ok' => true, 'data' => ['available' => true]]), null, $request);
        check(isset($response->get_data()['meta']['request_id']) && $response->headers['Cache-Control'] === 'private, no-store', 'Response metadata/cache control missing');
        check(!str_contains(json_encode($GLOBALS['wpdb']->audit), 'private-token'), 'Token persisted in audit');
    },
    'retired handoff cannot disclose stored bearer tokens' => function (): void {
        $state = new PatientAccessRequestStateService();
        check(!$state->start('attacker-request-id'), 'Unsafe handoff can still be started');
        check($state->getStatus('attacker-request-id')['status'] === 410 && !isset($state->getStatus('attacker-request-id')['access_token']), 'Bearer token disclosed');
        check($state->complete('attacker-request-id', 'secret')['status'] === 410, 'Bearer publication still possible');
    },
    'getting started, detailed help modals and dashboard guide render' => function (): void {
        $GLOBALS['current_user'] = 7;
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'Build your first connection') && str_contains($html, 'Choose patient login') && str_contains($html, 'Configured badges'), 'Initial setup guidance missing');
        $_GET['tab'] = 'settings'; ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'Open Patient login') && !str_contains($html, 'data-login-setup') && !str_contains($html, 'name="oauth_enabled"'), 'General settings still contain login configuration');
        $_GET['tab'] = 'patient-login'; $_GET['section'] = 'oauth'; ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'data-api-help="audience"') && str_contains($html, 'Help: API name at the sign-in service'), 'Plain-language field help button missing');
        check(substr_count($html, '<dialog ') === 1 && str_contains($html, 'aria-labelledby="cliniko-api-help-title"'), 'Shared accessible modal missing');
        check(!str_contains($html, '<details class="cliniko-api-help"'), 'Old help dropdown remains');
        check(str_contains($html, 'What should I enter or choose?') && str_contains($html, '#oauth'), 'Practical field content or tutorial link missing');
        check(strpos($html, '<dialog ') > strrpos($html, '</form>'), 'Modal must be outside settings forms');
        preg_match_all('/id="(cliniko-api-help-[^"]+)"/', $html, $ids);
        check(count($ids[1]) === count(array_unique($ids[1])), 'Duplicate modal/template IDs');
        $_GET['tab'] = 'guide'; ob_start(); ApiAccess::render(); $html = ob_get_clean();
        foreach (['API master switch', 'Scopes and application expiry', 'Patient links', 'Booking retry and idempotency rules', 'Legacy website compatibility'] as $section) check(str_contains($html, $section), 'Guide section missing: ' . $section);
        $_GET['tab'] = 'documentation'; ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(substr_count($html, 'data-search=') === count(EndpointCatalog::definitions()), 'Catalog should document every operation');
        check(str_contains($html, 'Success response schema') && str_contains($html, 'Download active OpenAPI JSON'), 'Developer contract UI missing');
    },
    'endpoint documentation groups every operation and distinguishes each access requirement' => function (): void {
        $docs = \App\Admin\Modules\ApiAccess\EndpointDocumentation::class;
        $categories = []; $accessKinds = [];
        foreach (EndpointCatalog::definitions() as $endpoint) {
            $category = $docs::category($endpoint);
            check(isset($docs::categories()[$category]) && $category !== 'other', 'Missing category for ' . $endpoint['key']);
            $categories[$category] = ($categories[$category] ?? 0) + 1;
            $access = $docs::access($endpoint);
            check($access['kind'] !== 'unknown' && $access['proof'] !== '' && $access['send'] !== '', 'Missing access instructions for ' . $endpoint['key']);
            $accessKinds[$access['kind']] = ($accessKinds[$access['kind']] ?? 0) + 1;
            check(($access['kind'] === 'public') === ($endpoint['auth'] === 'public'), 'Protected operation mislabeled public: ' . $endpoint['key']);
            if ($endpoint['group'] === 'authentication') check(str_contains($access['note'], 'Do not send an Authorization header'), 'Body-proof route must explain header rejection');
            if ($endpoint['auth'] === 'patient') check(str_contains($access['send'], 'Authorization: Bearer'), 'Patient bearer header missing');
            if ($endpoint['auth'] === 'attempt') check(str_contains($access['send'], 'X-ES-Attempt-Token') && str_contains($access['note'], 'original active, verified patient'), 'Attempt secret or patient binding missing');
        }
        check($categories === ['system' => 2, 'discovery' => 4, 'templates' => 1, 'booking' => 5, 'patient_booking' => 2, 'patient' => 18, 'booking_flows' => 2, 'onboarding' => 3, 'sessions' => 7, 'registration' => 5], 'Unexpected category membership');
        check($accessKinds === ['public' => 7, 'attempt' => 4, 'patient' => 26, 'credentials' => 1, 'password_reset' => 2, 'app' => 1, 'social' => 2, 'refresh' => 2, 'registration' => 1, 'oauth' => 1, 'receipt' => 2], 'Unexpected access classification');
        check(str_contains($docs::access(endpoint('finalize'))['note'], 'not authentication'), 'Retry key confused with authentication');
        check(str_contains($docs::access(endpoint('auth_register_oauth'))['send'], 'internal app ID'), 'Provider ID confused with internal app ID');
        $GLOBALS['current_user'] = 7; $_GET['tab'] = 'documentation';
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(substr_count($html, 'data-doc-category=') === 10 && substr_count($html, 'data-doc-jump=') === 10, 'Category headings or jump links missing');
        check(substr_count($html, 'class="cliniko-api-access-badge ') === count(EndpointCatalog::definitions()), 'Every endpoint needs a collapsed access badge');
        check(substr_count($html, '<h4>Access requirements</h4>') === count(EndpointCatalog::definitions()), 'Every endpoint needs expanded access instructions');
        check(str_contains($html, 'id="cliniko-api-access-filter"') && str_contains($html, 'Application permission (only when a Bearer credential is supplied)'), 'Access filter or conditional public permissions missing');
        preg_match_all('/<summary>(.*?)<\/summary>/s', $html, $summaries);
        foreach ($summaries[1] as $summary) if (str_contains($summary, 'cliniko-api-method')) check(str_contains($summary, 'cliniko-api-access-badge'), 'Access badge hidden inside expanded details');
    },
    'tutorials explain OAuth, bookings and current API configuration safely' => function (): void {
        $GLOBALS['current_user'] = 7; $_GET['tab'] = 'tutorials';
        update_option(ApiSettings::OPTION, array_replace(ApiSettings::defaults(), ['slug' => 'patient-api']));
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        foreach (\App\Admin\Modules\ApiAccess\HelpContent::tutorials() as $key => $tutorial) {
            check(str_contains($html, 'id="' . $key . '"') && str_contains($html, $tutorial['title']), 'Tutorial missing: ' . $key);
        }
        foreach (['Three identifiers that are easy to confuse', 'PKCE', 'RFC 7662', 'invalid_token_claims', 'patient_mapping_required', 'Idempotency-Key', 'https://example.test/wp-json/patient-api/v1/me'] as $term) check(str_contains($html, $term), 'Tutorial detail missing: ' . $term);
        check(!str_contains($html, 'introspection-secret') && $GLOBALS['introspection_calls'] === [], 'Tutorial accessed provider credentials or made a network request');
        ob_start(); \App\Admin\Modules\ApiAccess\HelpContent::renderBlocks([['title' => '<script>evil()</script>', 'code' => '<img src=x onerror=evil()>']]); $escaped = ob_get_clean();
        check(!str_contains($escaped, '<script>') && !str_contains($escaped, '<img '), 'Help content was not escaped');
    },
    'staff help uses plain language and keeps technical instructions separate' => function (): void {
        $help = \App\Admin\Modules\ApiAccess\HelpContent::class;
        foreach (['slug', 'appointment_types', 'form_templates', 'read_limit', 'write_limit', 'audit_days', 'issuer', 'audience', 'introspection_url', 'introspection_client', 'introspection_secret', 'name', 'external_id', 'expires_at', 'subject', 'user_id', 'api-master-switch', 'route-groups', 'allowed-browser-origins', 'application-type', 'approved-scopes', 'bearer-authentication'] as $key) {
            $field = $help::field($key);
            check($field['intro'] !== '' && count($field['sections']) === 4, 'Missing practical field guidance: ' . $key);
            check(!preg_match('/RFC|PKCE|JWT|REMOTE_ADDR|\bclaim\b|\bresource.server\b/i', $field['intro'] . implode(' ', array_column($field['sections'], 'text'))), 'Unexplained technical language in staff help: ' . $key);
        }
        foreach ($help::tutorials() as $key => $tutorial) {
            $blocks = $help::tutorial($key);
            $reference = array_pop($blocks);
            check(isset($reference['developer']), 'Developer reference not separated: ' . $key);
            foreach ($blocks as $block) check(!isset($block['code']) && !isset($block['developer']), 'Code in staff tutorial: ' . $key);
        }
        check(str_contains($help::field('bearer-authentication')['sections'][2]['text'], 'temporary login pass'), 'Login concept lacks a plain-language example');
    },
    'appointment type picker paginates, caches per account and never auto-publishes' => function (): void {
        $GLOBALS['cliniko_pages']['appointment_types?per_page=100']['links']['next'] = 'https://api.au1.cliniko.com/v1/appointment_types?page=2';
        $GLOBALS['cliniko_pages']['https://api.au1.cliniko.com/v1/appointment_types?page=2'] = ['appointment_types' => [
            ['id' => '125', 'name' => 'Another service', 'duration_in_minutes' => 20],
            ['id' => '126', 'name' => 'Archived service', 'archived_at' => '2026-01-01'],
        ]];
        $result = \App\Admin\Modules\ApiAccess\AppointmentTypeChoices::load();
        check($result['error'] === '' && count($result['items']) === 3 && $result['items'][0]['id'] === '125', 'Picker did not load/sort all active types');
        check(count($GLOBALS['cliniko_calls']) === 2, 'Pagination was not followed');
        \App\Admin\Modules\ApiAccess\AppointmentTypeChoices::load();
        check(count($GLOBALS['cliniko_calls']) === 2, 'Admin reference cache was not reused');
        check(ApiSettings::get()['appointment_types'] === [], 'Loading choices published services');
        $GLOBALS['cliniko_key'] = 'another-account-au1';
        \App\Admin\Modules\ApiAccess\AppointmentTypeChoices::load();
        check(count($GLOBALS['cliniko_calls']) === 4, 'New Cliniko credentials reused the old account cache');
    },
    'picker safely preserves missing selections and escapes service names' => function (): void {
        $GLOBALS['current_user'] = 7; $_GET['tab'] = 'settings';
        update_option(ApiSettings::OPTION, array_replace(ApiSettings::defaults(), ['appointment_types' => ['123', '999']]));
        $GLOBALS['cliniko_pages']['appointment_types?per_page=100']['appointment_types'][0]['name'] = '<script>bad()</script>';
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'Published appointment types') && !str_contains($html, 'name="appointment_types"'), 'Manual ID input remains');
        check(str_contains($html, 'name="appointment_types[]" value="123" checked') && str_contains($html, 'name="appointment_types[]" value="999" checked'), 'Existing choices were silently lost');
        check(!str_contains($html, 'name="appointment_types[]" value="124" checked'), 'Newly fetched service was automatically published');
        check(!str_contains($html, '<script>bad()') && str_contains($html, '&lt;script&gt;'), 'Cliniko service name injected HTML');
        $GLOBALS['transients'] = []; $GLOBALS['cliniko_pages'] = [];
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'We could not load your services') && str_contains($html, 'value="123" checked') && str_contains($html, 'value="999" checked'), 'Provider failure lost saved approvals');
        $GLOBALS['cliniko_key'] = '';
        $result = \App\Admin\Modules\ApiAccess\AppointmentTypeChoices::load();
        check($result['items'] === [] && str_contains($result['error'], 'credentials settings'), 'Missing credentials have no helpful error');
    },
    'settings accepts checkbox selections, rejects malformed IDs and can publish none' => function (): void {
        $base = ['slug' => 'cliniko-api'];
        $result = ApiSettings::validate($base + ['appointment_types' => ['123', '124', '123'], 'form_templates' => '456, 457']);
        check(!is_wp_error($result) && $result['appointment_types'] === ['123', '124'] && $result['form_templates'] === ['456', '457'], 'Checkbox selection or older text format failed');
        foreach ([['123', '<script>'], [['123']], [true]] as $invalid) check(is_wp_error(ApiSettings::validate($base + ['appointment_types' => $invalid])), 'Malformed checkbox values accepted');
        update_option(ApiSettings::OPTION, array_replace(ApiSettings::defaults(), ['appointment_types' => ['123']]));
        $result = ApiSettings::validate($base);
        check(!is_wp_error($result) && $result['appointment_types'] === [], 'Unticking all services failed to clear the allowlist');
    },
    'form template picker paginates, caches names only and never auto-publishes' => function (): void {
        $GLOBALS['cliniko_pages']['patient_form_templates?per_page=100']['links']['next'] = 'https://api.au1.cliniko.com/v1/patient_form_templates?page=2';
        $GLOBALS['cliniko_pages']['https://api.au1.cliniko.com/v1/patient_form_templates?page=2'] = ['patient_form_templates' => [
            ['id' => '458', 'name' => 'Another questionnaire', 'content' => ['sections' => [['name' => 'Do not cache questions']]]],
            ['id' => '459', 'name' => 'Archived questionnaire', 'archived_at' => '2026-01-01'],
        ]];
        $loader = \App\Admin\Modules\ApiAccess\FormTemplateChoices::class;
        $result = $loader::load();
        check($result['error'] === '' && count($result['items']) === 3 && $result['items'][0]['id'] === '458', 'Templates not paginated, filtered or sorted');
        foreach ($result['items'] as $item) check(array_keys($item) === ['id', 'name'], 'Picker cache contains template content');
        check(count($GLOBALS['cliniko_calls']) === 2 && ApiSettings::get()['form_templates'] === [], 'Loading templates made writes or published forms');
        $loader::load(); check(count($GLOBALS['cliniko_calls']) === 2, 'Template cache not reused');
        $GLOBALS['cliniko_key'] = 'another-account-au1';
        $loader::load(); check(count($GLOBALS['cliniko_calls']) === 4, 'Template cache not scoped to Cliniko credentials');
        foreach ($GLOBALS['cliniko_calls'] as $url) check(str_contains($url, 'patient_form_templates'), 'Template picker read completed patient forms');
    },
    'form picker preserves missing selections through outages and escapes names' => function (): void {
        $GLOBALS['current_user'] = 7; $_GET['tab'] = 'settings';
        update_option(ApiSettings::OPTION, array_replace(ApiSettings::defaults(), ['form_templates' => ['456', '9998']]));
        $GLOBALS['cliniko_pages']['patient_form_templates?per_page=100']['patient_form_templates'][0]['name'] = '<script>bad-template()</script>';
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'Published form templates') && !str_contains($html, 'name="form_templates"'), 'Manual template ID input remains');
        check(str_contains($html, 'name="form_templates[]" value="456" checked') && str_contains($html, 'name="form_templates[]" value="9998" checked'), 'Template approval lost');
        check(!str_contains($html, 'name="form_templates[]" value="457" checked') && !str_contains($html, '<script>bad-template()'), 'Unselected template auto-published or template name injected HTML');
        check(str_contains($html, 'Completed patient answers are not shared'), 'Blank-form privacy explanation missing');
        $GLOBALS['transients'] = []; unset($GLOBALS['cliniko_pages']['patient_form_templates?per_page=100']);
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'We could not load your form templates') && str_contains($html, 'value="456" checked') && str_contains($html, 'value="9998" checked'), 'Cliniko outage removed approved templates');
        $GLOBALS['cliniko_key'] = '';
        check(str_contains(\App\Admin\Modules\ApiAccess\FormTemplateChoices::load()['error'], 'credentials settings'), 'Missing credentials have no template help');
    },
    'form checkbox settings remain compatible and support clearing all forms' => function (): void {
        $base = ['slug' => 'cliniko-api', 'appointment_types' => ['123']];
        $result = ApiSettings::validate($base + ['form_templates' => ['456', '457', '456']]);
        check(!is_wp_error($result) && $result['form_templates'] === ['456', '457'], 'Template checkbox selection failed');
        check(ApiSettings::validate($base + ['form_templates' => '456, 457'])['form_templates'] === ['456', '457'], 'Older template ID submissions rejected');
        foreach ([['456', 'bad'], [['456']], [true]] as $invalid) check(is_wp_error(ApiSettings::validate($base + ['form_templates' => $invalid])), 'Malformed template values accepted');
        update_option(ApiSettings::OPTION, array_replace(ApiSettings::defaults(), ['form_templates' => ['456']]));
        check(ApiSettings::validate($base)['form_templates'] === [], 'Unticking every form did not clear approvals');
    },
    'patient-login wizard renders a safe complete setup without JavaScript' => function (): void {
        $GLOBALS['current_user'] = 7; $_GET['tab'] = 'patient-login'; $_GET['section'] = 'oauth';
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(substr_count($html, ' data-login-panel') === 5, 'Patient-login wizard must have five steps');
        foreach (\App\Admin\Modules\ApiAccess\PatientLoginSetup::fields() as $key => $field) {
            check(substr_count($html, 'name="' . $key . '"') === 1, 'Wizard duplicated a connection field: ' . $key);
            check(str_contains($html, $field['label']), 'Plain-language connection label missing: ' . $key);
        }
        check(str_contains($html, 'form="cliniko-api-provider-test-form"') && strpos($html, 'id="cliniko-api-provider-test-form"') > strpos($html, '</form>'), 'Connection check has a nested form');
        check(str_contains($html, 'No sign-in service yet?') && str_contains($html, 'Copy developer checklist') && str_contains($html, 'all five setup sections are shown'), 'New/no-JavaScript setup guidance missing');
        check(!str_contains($html, 'introspection-secret') && $GLOBALS['introspection_calls'] === [], 'Rendering setup exposed a saved secret or contacted the provider');
        check(str_contains($html, 'value="cliniko_api_patient_login"') && !str_contains($html, 'name="appointment_types[]"') && !str_contains($html, 'name="slug"'), 'Login form still includes general-setting controls');
        $request = \App\Admin\Modules\ApiAccess\PatientLoginSetup::requestText();
        check(str_contains($request, rest_url(ApiSettings::namespace())) && !str_contains($request, 'introspection-secret'), 'Developer checklist contains a secret or lacks the API address');
    },
    'saved connection check is not login proof and becomes stale after settings or secret changes' => function (): void {
        enableApi();
        $setup = \App\Admin\Modules\ApiAccess\PatientLoginSetup::class;
        $GLOBALS['introspection_response'] = ['status' => 200, 'body' => '{"active":false}'];
        check($setup::checkSavedConnection() === true && $setup::state()['check_passed'], 'Passing check not recorded');
        $record = get_option($setup::CHECK_OPTION);
        check(!str_contains(json_encode($record), 'introspection-secret') && !str_contains(json_encode($record), 'cliniko-connectivity-test'), 'Connection history stores a secret/token');
        $settings = ApiSettings::get(); $settings['audience'] = 'changed-api'; update_option(ApiSettings::OPTION, $settings);
        check(!$setup::state()['check_current'], 'Changed connection details reuse old check');
        $settings['audience'] = 'cliniko-api'; update_option(ApiSettings::OPTION, $settings);
        $GLOBALS['oauth_secret'] = 'replacement-password';
        check(!$setup::state()['check_passed'], 'Password rotation reuses old connection check');
        $GLOBALS['introspection_response'] = ['status' => 200, 'body' => '{"active":true}'];
        check(is_wp_error($setup::checkSavedConnection()) && !$setup::state()['check_passed'], 'Unexpected provider response recorded as passing');
        check(str_contains($setup::failureMessage('oauth_test_failed'), 'format this plugin needs'), 'Failed checks lack a practical next step');
    },
    'login setup permits disabled drafts but names missing items before enabling' => function (): void {
        $setup = \App\Admin\Modules\ApiAccess\PatientLoginSetup::class;
        $GLOBALS['oauth_secret'] = '';
        check(count($setup::state()['missing']) === 5, 'Setup failed to identify five missing values');
        $base = ['slug' => 'cliniko-api'];
        check(!is_wp_error(ApiSettings::validate($base + ['issuer' => 'https://identity.example.test'])), 'Incomplete disabled login draft rejected');
        $result = ApiSettings::validate($base + ['oauth_enabled' => '1']);
        check(is_wp_error($result) && $result->get_error_code() === 'oauth_incomplete' && str_contains($result->get_error_message(), 'Sign-in service address'), 'Enabling login does not explain missing values');
        $values = ['issuer' => 'https://identity.example.test', 'audience' => 'patient-api', 'introspection_url' => 'https://identity.example.test/introspect', 'introspection_client' => 'website-id'];
        $result = ApiSettings::validate($base + $values + ['oauth_enabled' => '1']);
        check(is_wp_error($result) && $result->get_error_code() === 'oauth_secret_missing', 'Enabling sign-in without a secret accepted');
        check(!is_wp_error(ApiSettings::validate($base + $values + ['oauth_enabled' => '1', 'introspection_secret' => 'new-password'])), 'Complete login configuration rejected');
    },
    'named service selection is validated without changing legacy setup or credentials' => function (): void {
        $base = ['slug' => 'cliniko-api'];
        check(ApiSettings::validate($base)['oauth_provider'] === 'custom', 'Older configurations lost the compatible custom-service path');
        $settings = array_replace(ApiSettings::defaults(), ['oauth_provider' => 'keycloak']); update_option(ApiSettings::OPTION, $settings);
        check(ApiSettings::validate($base)['oauth_provider'] === 'keycloak', 'Missing provider field reset the saved service');
        check(ApiSettings::validate($base + ['oauth_provider' => 'okta'])['oauth_provider'] === 'okta', 'Named service draft cannot be saved');
        foreach (['invalid', ['keycloak']] as $id) check(is_wp_error(ApiSettings::validate($base + ['oauth_provider' => $id])), 'Unrecognised/malformed service choice accepted');
        $request = \App\Admin\Modules\ApiAccess\PatientLoginSetup::requestText('keycloak');
        check(str_contains($request, 'Selected sign-in service: Keycloak') && !str_contains($request, 'introspection-secret'), 'Service-specific handoff is missing or exposes a credential');
    },
    'services needing other validation cannot be enabled but can be saved as drafts' => function (): void {
        $base = ['slug' => 'cliniko-api', 'issuer' => 'https://identity.example.test', 'audience' => 'patient-api', 'introspection_url' => 'https://identity.example.test/introspect', 'introspection_client' => 'website-id'];
        foreach (['auth0', 'entra', 'google', 'firebase', 'cognito'] as $id) {
            check(!is_wp_error(ApiSettings::validate($base + ['oauth_provider' => $id])), 'Blocked service cannot be saved with sign-in off');
            $result = ApiSettings::validate($base + ['oauth_provider' => $id, 'oauth_enabled' => '1']);
            check(is_wp_error($result) && $result->get_error_code() === 'oauth_provider_not_supported', 'Unsupported validation advertised as ready');
        }
        foreach (['custom', 'keycloak', 'okta', 'authentik', 'google_keycloak'] as $id) check(!is_wp_error(ApiSettings::validate($base + ['oauth_provider' => $id, 'oauth_enabled' => '1'])), 'Conditional compatible path blocked: ' . $id);
        $catalog = \App\Service\Api\SignInProviders::catalog();
        check(str_contains($catalog['authentik']['notice'], 'may lack token_type, client_id and scope'), 'authentik default-response compatibility warning missing');
        foreach ($catalog as $guide) check($guide['steps'] !== [] && $guide['links'] !== [], 'Service has no actionable guide or primary references');
    },
    'changing the named service invalidates a previous saved connection check' => function (): void {
        enableApi();
        $GLOBALS['introspection_response'] = ['status' => 200, 'body' => '{"active":false}'];
        $setup = \App\Admin\Modules\ApiAccess\PatientLoginSetup::class;
        $setup::checkSavedConnection();
        $settings = ApiSettings::get(); $settings['oauth_provider'] = 'keycloak'; update_option(ApiSettings::OPTION, $settings);
        check(!$setup::state()['check_current'], 'Named service changed without invalidating the previous check');
    },
    'Google and managed-login guides collect useful details without pretending to add validation' => function (): void {
        $catalog = \App\Service\Api\SignInProviders::catalog();
        $setup = \App\Admin\Modules\ApiAccess\PatientLoginSetup::class;
        foreach (['google', 'firebase', 'cognito'] as $id) {
            $guide = $catalog[$id];
            check(!$guide['can_enable'] && $guide['collect'] !== [] && !isset($guide['endpoint_hint']), 'Direct managed service falsely advertised as compatible');
            $request = $setup::requestText($id);
            check(str_contains($request, 'integration-planning request') && str_contains($request, $guide['name']), 'Managed-service checklist still asks for a ready introspection connection');
            check(!str_contains($request, 'introspection-secret'), 'Managed-service checklist exposes saved credentials');
        }
        $broker = $catalog['google_keycloak'];
        check($broker['can_enable'] && $broker['endpoint_hint'] === $catalog['keycloak']['endpoint_hint'], 'Google broker should use the unchanged Keycloak connection');
        check(str_contains($broker['locations']['issuer'], 'not https://accounts.google.com'), 'Broker guide confuses Google and Keycloak issuers');
        check(str_contains(implode(' ', $broker['steps']), 'Keycloak subject ID, not their Google email'), 'Broker patient-link identifier unclear');
        $GLOBALS['current_user'] = 7; $_GET['tab'] = 'patient-login'; $_GET['section'] = 'oauth';
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        foreach (['Google Firebase Authentication', 'Google Sign-In — direct', 'Amazon Cognito', 'Google sign-in through Keycloak', 'Information to collect for your developer', 'data-login-direct-warning'] as $text) check(str_contains($html, $text), 'New guidance missing from dashboard: ' . $text);
    },
    'general settings save ignores login fields and preserves current provider configuration' => function (): void {
        enableApi(); $old = ApiSettings::get();
        $input = ['slug' => 'cliniko-api', 'enabled' => '1', 'groups' => ['discovery'], 'appointment_types' => ['124'], 'form_templates' => ['457'], 'origins' => 'https://portal.example.test', 'oauth_enabled' => '', 'issuer' => 'https://wrong.example.test', 'oauth_provider' => 'firebase', 'introspection_client' => 'wrong-client', 'introspection_secret' => 'do-not-save-this'];
        $result = ApiSettings::validateGeneral($input);
        check(!is_wp_error($result), 'General save revalidates or uses injected login values');
        foreach (['oauth_enabled', 'oauth_provider', 'issuer', 'audience', 'introspection_url', 'introspection_client'] as $key) check($result[$key] === $old[$key], 'General save changed login field: ' . $key);
        check(!isset($result['introspection_secret']) && $result['appointment_types'] === ['124'] && $result['origins'] === ['https://portal.example.test'], 'General save leaked a password or failed its own update');
        $result = ApiSettings::validateGeneral(['slug' => 'cliniko-api']);
        check(!is_wp_error($result) && !$result['enabled'] && $result['groups'] === [] && $result['appointment_types'] === [], 'Unticked general controls did not clear');
        check($result['oauth_enabled'] === $old['oauth_enabled'] && $result['issuer'] === $old['issuer'], 'Unticked general controls disabled patient login');
    },
    'patient login save ignores general fields and preserves the current API configuration' => function (): void {
        enableApi(); $old = ApiSettings::get();
        $old['origins'] = ['https://portal.example.test']; $old['aliases'] = ['older-api' => time() + 3600]; update_option(ApiSettings::OPTION, $old);
        $input = ['oauth_provider' => 'keycloak', 'issuer' => 'https://login.example.test/realms/clinic', 'audience' => 'clinic-api', 'introspection_url' => 'https://login.example.test/realms/clinic/protocol/openid-connect/token/introspect', 'introspection_client' => 'website-client', 'slug' => 'wrong-slug', 'enabled' => '', 'groups' => [], 'appointment_types' => [], 'form_templates' => [], 'origins' => 'bad-origin'];
        $result = ApiSettings::validatePatientLogin($input);
        check(!is_wp_error($result), 'Login save revalidates unrelated general values');
        foreach (['slug', 'enabled', 'groups', 'appointment_types', 'form_templates', 'read_limit', 'write_limit', 'audit_days', 'origins', 'aliases'] as $key) check($result[$key] === $old[$key], 'Login save changed API field: ' . $key);
        check(!$result['oauth_enabled'] && $result['oauth_provider'] === 'keycloak' && $result['issuer'] === $input['issuer'], 'Login draft did not save independently');
        $result = ApiSettings::validatePatientLogin($input + ['oauth_enabled' => '1']);
        check(!is_wp_error($result) && $result['oauth_enabled'] && $result['slug'] === $old['slug'], 'Complete login save failed or renamed the API');
        check(is_wp_error(ApiSettings::validatePatientLogin(['oauth_enabled' => '1'])), 'Login-tab enable bypassed required values');
    },
    'WordPress app login issues a JWT without a cookie or stored patient password' => function (): void {
        $client = patientApp(); $request = authRequest(['client_id' => $client['id'], 'identifier' => 'alex@example.test', 'password' => 'patient-password']);
        check((new AccessPolicy())->authorize($request, endpoint('auth_login')) === true, 'Login entry rejected');
        $response = (new ManagedController())->dispatch($request, endpoint('auth_login'));
        check($response instanceof WP_REST_Response && $response->get_status() === 200, 'Login did not produce response');
        $tokens = $response->get_data()['data'];
        check(PatientSessions::isLocal($tokens['access_token']) && $tokens['token_type'] === 'Bearer' && $tokens['expires_in'] === 900, 'Login returned invalid token format/lifetime');
        check(get_current_user_id() === 0, 'Login changed global user context');
        $identity = PatientSessions::identity($tokens['access_token']);
        check(!is_wp_error($identity) && $identity['user_id'] === 7 && $identity['client']['id'] === $client['id'], 'JWT identity invalid');
        $stored = json_encode($GLOBALS['wpdb']->sessions);
        check(!str_contains($stored, 'patient-password') && !str_contains($stored, $tokens['refresh_token']) && !str_contains($stored, $tokens['access_token']), 'Session persisted plaintext password/tokens');
        $protected = new WP_REST_Request('GET'); $protected->set_header('authorization', 'Bearer ' . $tokens['access_token']);
        check((new AccessPolicy())->authorize($protected, endpoint('me')) === true && $protected->get_param('_cliniko_api_user') === 7, 'JWT could not reach own profile');
        check($GLOBALS['introspection_calls'] === [], 'Local JWT was sent to external provider');
    },
    'WordPress app login rejects wrong credentials administrator accounts MFA and unapproved clients' => function (): void {
        $client = patientApp();
        $bad = authRequest(['client_id' => $client['id'], 'identifier' => 'alex@example.test', 'password' => 'wrong']);
        check(is_wp_error(PatientAuthentication::dispatch($bad, 'auth_login')), 'Bad password accepted');
        $bad = authRequest(['client_id' => str_repeat('f', 24), 'identifier' => 'alex@example.test', 'password' => 'patient-password']);
        check(is_wp_error(PatientAuthentication::dispatch($bad, 'auth_login')), 'Unknown client accepted');
        $GLOBALS['auth_user_id'] = 8; check(is_wp_error(passwordLogin($client)), 'Administrator obtained patient session');
        $GLOBALS['auth_user_id'] = 7; $GLOBALS['mfa_blocked'] = true;
        check(passwordLogin($client)->get_error_code() === 'interactive_login_required', 'Interactive MFA bypassed');
        check($GLOBALS['wpdb']->sessions === [], 'Failed login issued a session');
    },
    'local tokens cannot be forged or substituted with another issuer audience algorithm or subject' => function (): void {
        $client = patientApp(); $tokens = passwordLogin($client);
        $claims = (array) \Firebase\JWT\JWT::decode($tokens['access_token'], new \Firebase\JWT\Key(str_repeat('a', 64), 'HS256'));
        foreach (['iss' => 'https://attacker.test', 'aud' => 'wordpress-admin', 'sub' => '8', 'client_id' => str_repeat('f', 24), 'exp' => time() - 1, 'iat' => time() + 500] as $key => $value) {
            $forged = \Firebase\JWT\JWT::encode(array_replace($claims, [$key => $value]), str_repeat('a', 64), 'HS256', 'cliniko-local-v1', ['typ' => PatientSessions::TYPE]);
            check(is_wp_error(PatientSessions::identity($forged)), 'Invalid claim accepted: ' . $key);
        }
        $forged = \Firebase\JWT\JWT::encode($claims, str_repeat('z', 64), 'HS256', 'cliniko-local-v1', ['typ' => PatientSessions::TYPE]);
        check(is_wp_error(PatientSessions::identity($forged)), 'Wrong signing key accepted');
        $forged = \Firebase\JWT\JWT::encode($claims, str_repeat('a', 64), 'HS512', 'cliniko-local-v1', ['typ' => PatientSessions::TYPE]);
        check(is_wp_error(PatientSessions::identity($forged)), 'Unapproved algorithm accepted');
        $parts = explode('.', $tokens['access_token']); $parts[0] = rtrim(strtr(base64_encode('{"typ":"cliniko-patient+jwt","alg":"none"}'), '+/', '-_'), '=');
        check(is_wp_error(PatientSessions::identity(implode('.', $parts))), 'Unsigned algorithm accepted');
    },
    'local access always respects live app scope account state password changes and method disabling' => function (): void {
        $client = patientApp(); $tokens = passwordLogin($client); $s = ApiSettings::get();
        $GLOBALS['user_status'] = 'suspended'; check(is_wp_error(PatientSessions::identity($tokens['access_token'])), 'Suspended account accepted');
        $GLOBALS['user_status'] = 'active'; $GLOBALS['patient_password_hash'] = 'changed-password-hash';
        check(is_wp_error(PatientSessions::identity($tokens['access_token'])), 'Password reset did not invalidate session');
        $GLOBALS['patient_password_hash'] = 'wordpress-patient-password-hash'; $s['password_login_enabled'] = false; update_option(ApiSettings::OPTION, $s);
        check(is_wp_error(PatientSessions::identity($tokens['access_token'])), 'Disabled login method accepted');
        $s['password_login_enabled'] = true; update_option(ApiSettings::OPTION, $s);
        ApiClients::update($client['id'], ['patient:read'], time() + DAY_IN_SECONDS);
        $request = new WP_REST_Request('GET'); $request->set_header('authorization', 'Bearer ' . $tokens['access_token']);
        check((new AccessPolicy())->authorize($request, endpoint('appointments'))->get_error_code() === 'insufficient_scope', 'Removed app permission still allowed');
        ApiClients::revoke($client['id']); check(is_wp_error(PatientSessions::identity($tokens['access_token'])), 'Revoked app accepted');
    },
    'refresh rotates tokens keeps the original session deadline and detects replay' => function (): void {
        $client = patientApp(); $original = passwordLogin($client);
        $next = PatientSessions::refresh($original['refresh_token'], $client['id']);
        check(!is_wp_error($next) && $next['refresh_token'] !== $original['refresh_token'], 'Refresh did not rotate');
        check($next['refresh_expires_in'] <= $original['refresh_expires_in'], 'Refresh extended absolute session');
        $third = PatientSessions::refresh($next['refresh_token'], $client['id']);
        check(!is_wp_error($third), 'Second refresh rejected');
        check(is_wp_error(PatientSessions::refresh($original['refresh_token'], $client['id'])), 'Spent refresh token replayed');
        check(is_wp_error(PatientSessions::identity($third['access_token'])) && is_wp_error(PatientSessions::refresh($third['refresh_token'], $client['id'])), 'Replay did not revoke entire session');
    },
    'logout and dashboard revocation immediately reject outstanding access tokens' => function (): void {
        $client = patientApp(); $tokens = passwordLogin($client);
        check(is_wp_error(PatientSessions::refresh($tokens['refresh_token'], str_repeat('f', 24), true)), 'Other application logged out session');
        check(!is_wp_error(PatientSessions::identity($tokens['access_token'])), 'Wrong-app logout revoked legitimate session');
        check(PatientSessions::refresh($tokens['refresh_token'], $client['id'], true)['logged_out'], 'Logout failed');
        check(is_wp_error(PatientSessions::identity($tokens['access_token'])), 'Logged-out access token accepted');
        $tokens = passwordLogin($client); $id = explode('.', $tokens['refresh_token'])[0];
        check(PatientSessions::revoke($id) && is_wp_error(PatientSessions::identity($tokens['access_token'])), 'Admin revoke failed');
    },
    'sessions fail closed on missing storage keys lock contention and write errors' => function (): void {
        $client = patientApp(); update_option(PatientSessions::SCHEMA_OPTION, '');
        check(is_wp_error(passwordLogin($client)), 'Missing schema ignored');
        update_option(PatientSessions::SCHEMA_OPTION, '1'); update_option(PatientSessions::KEY_OPTION, '');
        check(is_wp_error(passwordLogin($client)), 'Missing signing key ignored');
        update_option(PatientSessions::KEY_OPTION, str_repeat('a', 64));
        $tokens = passwordLogin($client); $GLOBALS['wpdb']->lockAvailable = false;
        check(PatientSessions::refresh($tokens['refresh_token'], $client['id'])->get_error_data()['status'] === 409, 'Concurrent refresh not serialized');
        $GLOBALS['wpdb']->lockAvailable = true; $GLOBALS['wpdb']->sessionWriteFails = true;
        check(is_wp_error(passwordLogin($client)) && is_wp_error(PatientSessions::refresh($tokens['refresh_token'], $client['id'])), 'Failed DB write issued tokens');
    },
    'Google social exchange verifies nonce and explicit subject mapping and rejects replay' => function (): void {
        $client = patientApp(); ApiClients::map('https://accounts.google.com', 'google-patient-seven', 7);
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'google']), 'auth_challenge');
        check(!is_wp_error($challenge) && $challenge['expires_in'] === 300, 'Social challenge failed');
        $token = socialToken(['iss' => 'accounts.google.com', 'aud' => $client['google_client_id'], 'sub' => 'google-patient-seven', 'nonce' => $challenge['nonce'], 'iat' => time(), 'exp' => time() + 300]);
        $request = authRequest(['client_id' => $client['id'], 'provider' => 'google', 'challenge_id' => $challenge['challenge_id'], 'id_token' => $token]);
        $result = PatientAuthentication::dispatch($request, 'auth_social');
        check(!is_wp_error($result) && !is_wp_error(PatientSessions::identity($result['access_token'])), 'Valid Google exchange failed');
        check(is_wp_error(PatientAuthentication::dispatch($request, 'auth_social')), 'Challenge replay accepted');
        ApiClients::map('https://accounts.google.com', 'google-patient-seven', 0);
        check(is_wp_error(PatientSessions::identity($result['access_token'])), 'Unlinked social session remained authorized');
        $call = $GLOBALS['social_key_calls'][0]; check($call[0] === 'https://www.googleapis.com/oauth2/v3/certs' && $call[1]['redirection'] === 0 && $call[1]['limit_response_size'] === 65536, 'Unbounded or untrusted key fetch');
    },
    'social identity with matching email never automatically links an account' => function (): void {
        $client = patientApp();
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'google']), 'auth_challenge');
        $token = socialToken(['iss' => 'https://accounts.google.com', 'aud' => $client['google_client_id'], 'sub' => 'unlinked-google-user', 'email' => 'alex@example.test', 'email_verified' => true, 'nonce' => $challenge['nonce'], 'iat' => time(), 'exp' => time() + 300]);
        $result = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'google', 'challenge_id' => $challenge['challenge_id'], 'id_token' => $token]), 'auth_social');
        check(is_wp_error($result) && $result->get_error_code() === 'patient_mapping_required' && $GLOBALS['wpdb']->sessions === [] && ApiClients::mappings() === [], 'Social email auto-linked or created a patient');
    },
    'social exchange rejects wrong issuer audience nonce missing nonce expiry or signature' => function (): void {
        $client = patientApp(); ApiClients::map('https://accounts.google.com', 'google-patient-seven', 7);
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'google']), 'auth_challenge');
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => $client['google_client_id'], 'sub' => 'google-patient-seven', 'nonce' => $challenge['nonce'], 'iat' => time(), 'exp' => time() + 300];
        foreach (['iss' => 'https://attacker.test', 'aud' => 'another-app.apps.googleusercontent.com', 'nonce' => 'wrong-nonce', 'exp' => time() - 1, 'sub' => '', 'azp' => 'unapproved-app'] as $key => $bad) {
            $token = socialToken(array_replace($claims, [$key => $bad]));
            $result = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'google', 'challenge_id' => $challenge['challenge_id'], 'id_token' => $token]), 'auth_social');
            check(is_wp_error($result), 'Bad social claim accepted: ' . $key);
        }
        $missing = $claims; unset($missing['nonce']);
        $token = socialToken($missing);
        check(is_wp_error(PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'google', 'challenge_id' => $challenge['challenge_id'], 'id_token' => $token]), 'auth_social')), 'Missing nonce accepted');
        check($GLOBALS['wpdb']->sessions === [], 'Bad social tokens created sessions');
    },
    'Apple social login binds exact audience and already transformed nonce' => function (): void {
        $client = patientApp(); ApiClients::map('https://appleid.apple.com', 'apple-patient-seven', 7);
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'apple']), 'auth_challenge');
        $token = socialToken(['iss' => 'https://appleid.apple.com', 'aud' => $client['apple_client_id'], 'sub' => 'apple-patient-seven', 'nonce' => $challenge['nonce'], 'iat' => time(), 'exp' => time() + 300]);
        $result = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'apple', 'challenge_id' => $challenge['challenge_id'], 'id_token' => $token]), 'auth_social');
        check(!is_wp_error($result) && !is_wp_error(PatientSessions::identity($result['access_token'])), 'Valid Apple exchange rejected');
    },
    'social exchange binds client challenge expiry and signature before creating a session' => function (): void {
        $client = patientApp(); ApiClients::map('https://accounts.google.com', 'google-patient-seven', 7);
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'google']), 'auth_challenge');
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => $client['google_client_id'], 'sub' => 'google-patient-seven', 'nonce' => $challenge['nonce'], 'iat' => time(), 'exp' => time() + 300];
        $valid = socialToken($claims);
        $forged = \Firebase\JWT\JWT::encode($claims, str_repeat('z', 64), 'HS256', 'test-key');
        $body = ['client_id' => $client['id'], 'provider' => 'google', 'challenge_id' => $challenge['challenge_id'], 'id_token' => $forged];
        check(is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_social')), 'Forged social signature accepted');
        $other = ApiClients::create('Other patient app', 'patient', '', ['patient:read'], time() + DAY_IN_SECONDS, ['google_client_id' => $client['google_client_id']])['client'];
        $body['id_token'] = $valid; $body['client_id'] = $other['id'];
        check(is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_social')), 'Other app consumed challenge');
        $body['client_id'] = $client['id']; $GLOBALS['transients']['cliniko_api_social_' . $challenge['challenge_id']]['expires_at'] = time() - 1;
        check(is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_social')), 'Expired challenge consumed');
        check($GLOBALS['wpdb']->sessions === [], 'Bad proofs created patient session');
    },
    'Google hybrid native presenter must match the app registration' => function (): void {
        $client = patientApp(); $presenter = 'native-app.apps.googleusercontent.com';
        ApiClients::update($client['id'], $client['scopes'], $client['expires_at'], array_replace($client, ['google_presenter_id' => $presenter]));
        $client = ApiClients::patient($client['id']); ApiClients::map('https://accounts.google.com', 'google-patient-seven', 7);
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'google']), 'auth_challenge');
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => $client['google_client_id'], 'sub' => 'google-patient-seven', 'nonce' => $challenge['nonce'], 'iat' => time(), 'exp' => time() + 300];
        $body = ['client_id' => $client['id'], 'provider' => 'google', 'challenge_id' => $challenge['challenge_id']];
        $body['id_token'] = socialToken($claims);
        check(is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_social')), 'Configured native presenter was not required');
        $claims['azp'] = $presenter; $body['id_token'] = socialToken($claims);
        check(!is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_social')), 'Valid hybrid presenter denied');
    },
    'provider key rotation refetches once and transport failure denies exchange' => function (): void {
        $client = patientApp(); ApiClients::map('https://accounts.google.com', 'google-patient-seven', 7);
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'google']), 'auth_challenge');
        $claims = ['iss' => 'https://accounts.google.com', 'aud' => $client['google_client_id'], 'sub' => 'google-patient-seven', 'nonce' => $challenge['nonce'], 'iat' => time(), 'exp' => time() + 300];
        socialToken($claims); set_transient('cliniko_api_social_keys_google', json_decode($GLOBALS['social_keys_response']['body'], true), 3600);
        $body = ['client_id' => $client['id'], 'provider' => 'google', 'challenge_id' => $challenge['challenge_id'], 'id_token' => socialToken($claims, 'rotated-provider-key')];
        check(!is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_social')) && count($GLOBALS['social_key_calls']) === 1, 'Key rotation did not perform one bounded refetch');
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $client['id'], 'provider' => 'google']), 'auth_challenge');
        $body['challenge_id'] = $challenge['challenge_id']; $claims['nonce'] = $challenge['nonce']; $body['id_token'] = socialToken($claims);
        delete_transient('cliniko_api_social_keys_google'); $GLOBALS['social_keys_response'] = new WP_Error('network_failed', 'Unavailable');
        check(PatientAuthentication::dispatch(authRequest($body), 'auth_social')->get_error_code() === 'social_provider_unavailable', 'Network failure allowed exchange');
    },
    'authentication methods remain off by default and independent from OAuth and publication saves' => function (): void {
        $s = ApiSettings::get();
        check(!$s['password_login_enabled'] && !$s['google_login_enabled'] && !$s['apple_login_enabled'], 'Native/social login enabled by default');
        check(is_wp_error(ApiSettings::validateAppLogin(['password_login_enabled' => 1])), 'Password login without compatibility review accepted');
        $client = patientApp(); $old = ApiSettings::get();
        $next = ApiSettings::validateAppLogin(['section' => 'social', 'google_login_enabled' => 1, 'issuer' => 'https://wrong.test', 'slug' => 'wrong', 'password_login_enabled' => 0, 'access_minutes' => 999]);
        check(!is_wp_error($next) && $next['issuer'] === $old['issuer'] && $next['slug'] === $old['slug'] && $next['password_login_enabled'] && $next['access_minutes'] === 15 && !$next['apple_login_enabled'], 'Social save changed another section');
        check(is_wp_error(ApiSettings::validateAppLogin(['section' => 'sessions', 'access_minutes' => 999])), 'Unbounded access lifetime accepted');
        $next = ApiSettings::validateGeneral(['slug' => $old['slug']]);
        check($next['password_login_enabled'] && $next['google_login_enabled'], 'Publication save disabled authentication');
        $next = ApiSettings::validatePatientLogin([]);
        check($next['password_login_enabled'] && $next['google_login_enabled'], 'OAuth save disabled native authentication');
    },
    'authentication registration export schemas and permission callbacks stay aligned' => function (): void {
        patientApp(); (new ManagedApiRoutes())->register();
        $definitions = array_filter(EndpointCatalog::definitions(), static fn($item) => EndpointCatalog::state($item) === 'Active'); check(count($GLOBALS['routes']) === count($definitions), 'Enabled auth operations missing registration');
        $paths = (array) EndpointCatalog::openApi()['paths'];
        foreach (['/auth/login', '/auth/challenge', '/auth/social', '/auth/refresh', '/auth/logout'] as $path) check(isset($paths[$path]['post']) && $paths[$path]['post']['security'] === [], 'Auth endpoint docs require an access token before login');
        check($paths['/auth/login']['post']['requestBody']['content']['application/json']['schema']['properties']['password']['writeOnly'], 'Password schema not write-only');
        $request = authRequest(['client_id' => str_repeat('a', 24)]); $request->set_header('authorization', 'Bearer unwanted-token');
        check((new AccessPolicy())->authorize($request, endpoint('auth_login'))->get_error_code() === 'unexpected_authorization', 'Ambiguous auth header accepted at login');
    },
    'new dashboard separates login methods patient links and synthetic request flows' => function (): void {
        $GLOBALS['current_user'] = 7; $_GET['tab'] = 'patient-login';
        ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'WordPress login · JWT') && str_contains($html, 'name="password_login_enabled"') && !str_contains($html, 'data-login-setup'), 'Native default view or separate OAuth view missing');
        check(!str_contains($html, 'name="groups[]"') && !str_contains($html, 'name="oauth_enabled"'), 'Native form posts other settings');
        $_GET['section'] = 'social'; ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(str_contains($html, 'name="google_login_enabled"') && str_contains($html, 'name="apple_login_enabled"') && !str_contains($html, 'name="password_login_enabled"'), 'Social form mixed with local password fields');
        $_GET['tab'] = 'flows'; ob_start(); ApiAccess::render(); $html = ob_get_clean();
        check(substr_count($html, 'data-flow-panel=') === 7 && str_contains($html, 'App → website') && str_contains($html, 'Where does each piece of data go?'), 'Request/response walkthrough incomplete');
        check($GLOBALS['introspection_calls'] === [] && $GLOBALS['social_key_calls'] === [], 'Read-only walkthrough makes authentication requests');
    },
    'client names are escaped and non-admins cannot render dashboard' => function (): void {
        ApiClients::create('Partner <script>evil()</script>', 'server', '', ['catalog:read'], time() + 3600);
        $GLOBALS['current_user'] = 7; $_GET['tab'] = 'clients';
        ob_start(); ApiAccess::render(); $html = ob_get_clean(); check(!str_contains($html, '<script>'), 'Client label injected HTML');
        $GLOBALS['current_user'] = 0;
        try { ApiAccess::render(); } catch (\RuntimeException $e) { check($e->getMessage() === 'Unauthorized', 'Wrong admin error'); return; }
        throw new \RuntimeException('Non-admin rendered the dashboard');
    },
];
$failures = 0;
foreach ($tests as $name => $test) {
    resetApi();
    $bufferLevel = ob_get_level();
    try { $test(); echo '[PASS] ' . $name . PHP_EOL; }
    catch (\Throwable $e) { while (ob_get_level() > $bufferLevel) ob_end_clean(); $failures++; echo '[FAIL] ' . $name . ': ' . $e->getMessage() . ' (' . $e->getLine() . ')' . PHP_EOL; }
}
exit($failures ? 1 : 0);
}
