<?php
/**
 * Optional real WordPress smoke check; changes options only through in-memory filters.
 * Usage: php tests/managed_api_wordpress_smoke.php /path/to/wordpress
 * Does not submit bookings, call Cliniko/payments, or save API settings.
 */
if (PHP_SAPI !== 'cli' || empty($argv[1])) { fwrite(STDERR, "Pass the WordPress installation directory.\n"); exit(1); }
define('WP_USE_THEMES', false);
ob_start();
register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['cliniko_api_smoke_booted'])) return;
    if (ob_get_level()) ob_end_clean();
    fwrite(STDERR, "[FAIL] WordPress could not boot; check the local database connection.\n");
    exit(1);
});
require rtrim($argv[1], '/\\') . '/wp-load.php';
$GLOBALS['cliniko_api_smoke_booted'] = true;
ob_end_clean();
require_once __DIR__ . '/../vendor/autoload.php';

use App\Routes\ManagedApiRoutes;
use App\Service\Api\ApiSettings;
use App\Service\Api\EndpointCatalog;
use App\Admin\Modules\ApiAccess\ApiAccess;

$settings = array_replace(ApiSettings::defaults(), ['enabled' => true, 'slug' => 'cliniko-smoke-test', 'groups' => array_keys(EndpointCatalog::groups()), 'appointment_types' => ['123'], 'form_templates' => ['456'], 'password_login_enabled' => true, 'google_login_enabled' => true, 'apple_login_enabled' => true, 'oauth_enabled' => true, 'registration_password_enabled' => true, 'registration_social_enabled' => true, 'registration_oauth_enabled' => true]);
add_filter('pre_option_' . ApiSettings::OPTION, static fn() => $settings);
$hasManagedRoutes = false;
foreach (($GLOBALS['wp_filter']['rest_api_init']->callbacks ?? []) as $callbacks) {
    foreach ($callbacks as $callback) {
        if (is_array($callback['function']) && $callback['function'][0] instanceof ManagedApiRoutes) $hasManagedRoutes = true;
    }
}
if (!$hasManagedRoutes) new ManagedApiRoutes();
$server = rest_get_server();
$definitions = EndpointCatalog::definitions();
foreach (ApiSettings::namespaces() as $namespace) {
    if (isset($server->get_routes()['/' . $namespace . '/booking-attempts'])) throw new RuntimeException('Guest booking creation still registered');
}
foreach ($definitions as $endpoint) {
    if (EndpointCatalog::state($endpoint) !== 'Active') continue;
    $path = '/' . ApiSettings::namespace() . EndpointCatalog::route($endpoint);
    if (!isset($server->get_routes()[$path])) throw new RuntimeException('Missing real registered route: ' . $path);
    $request = new WP_REST_Request($endpoint['method'], $path);
    $request->set_attributes(['args' => EndpointCatalog::args($endpoint)]);
    $request->set_url_params(['slug' => 'initial-consultation', 'id' => $endpoint['auth'] === 'attempt' ? str_repeat('a', 32) : '123']);
    $request->set_query_params(['from' => '2026-11-01', 'to' => '2026-11-07']);
    if ($endpoint['example'] !== []) { $request->set_header('Content-Type', 'application/json'); $request->set_body(wp_json_encode($endpoint['example'])); }
    $validation = $request->has_valid_params();
    if (is_wp_error($validation)) throw new RuntimeException($endpoint['key'] . ' example does not validate: ' . wp_json_encode($validation->get_error_data()));
    $sanitized = $request->sanitize_params();
    if (is_wp_error($sanitized)) throw new RuntimeException($endpoint['key'] . ' example cannot be sanitized');
}

$booking = array_values(array_filter($definitions, static fn($endpoint) => $endpoint['key'] === 'patient_booking_create'))[0];
$validatePatient = EndpointCatalog::args($booking)['patient']['validate_callback'];
$invalidPatient = $booking['example']['patient']; unset($invalidPatient['appointment_start']);
if (!is_wp_error($validatePatient($invalidPatient))) throw new RuntimeException('Missing nested required field accepted');
$invalidPatient = $booking['example']['patient']; $invalidPatient['archived_at'] = '2026-11-01';
if (!is_wp_error($validatePatient($invalidPatient))) throw new RuntimeException('Unknown nested field accepted');
foreach (['id' => '999', 'user_id' => 8, 'first_name' => 'Someone', 'email' => 'other@example.test'] as $field => $value) {
    $invalidPatient = $booking['example']['patient']; $invalidPatient[$field] = $value;
    if (!is_wp_error($validatePatient($invalidPatient))) throw new RuntimeException('Caller-selected patient identity accepted: ' . $field);
}

$adminIds = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
if ($adminIds !== []) {
    wp_set_current_user((int) $adminIds[0]);
    // Avoid reading an actual audit table; this smoke check exercises docs rendering only.
    add_filter('pre_option_wp_cliniko_api_schema', static fn() => 'not-installed');
    $_GET['tab'] = 'documentation';
    ob_start(); ApiAccess::render(); $html = ob_get_clean();
    foreach (['Live endpoint catalog', 'Example JSON request', 'Success response schema', 'Download active OpenAPI JSON'] as $label) {
        if (!str_contains($html, $label)) throw new RuntimeException('Missing documentation UI section: ' . $label);
    }
    echo "[PASS] Real WordPress endpoint documentation renders\n";
}
echo '[PASS] ' . count($definitions) . " real WordPress route schemas and examples validate\n";
