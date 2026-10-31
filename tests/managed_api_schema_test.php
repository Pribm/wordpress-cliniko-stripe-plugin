<?php
/** Run the canonical input schemas through WordPress's actual validator without a database. */
if (PHP_SAPI !== 'cli' || empty($argv[1])) { fwrite(STDERR, "Pass a WordPress source directory.\n"); exit(1); }
define('ABSPATH', rtrim($argv[1], '/\\') . '/');
function __(string $value): string { return $value; }
function _n(string $single, string $plural, $number): string { return $number === 1 ? $single : $plural; }
function apply_filters(string $hook, $value, ...$args) { return $value; }
function do_action(string $hook, ...$args): void {}
function is_wp_error($value): bool { return $value instanceof WP_Error; }
function _doing_it_wrong(string $name, string $message, string $version): void { throw new RuntimeException($message); }
function wp_is_numeric_array($value): bool { if (!is_array($value)) return false; foreach (array_keys($value) as $key) if (!is_int($key)) return false; return true; }
require ABSPATH . 'wp-includes/class-wp-error.php';
require ABSPATH . 'wp-includes/formatting.php';
require ABSPATH . 'wp-includes/rest-api.php';
require __DIR__ . '/../vendor/autoload.php';

use App\Service\Api\EndpointCatalog;

foreach (EndpointCatalog::definitions() as $endpoint) {
    $args = EndpointCatalog::args($endpoint);
    foreach ($endpoint['fields'] as $name => $field) {
        if ($field['in'] === 'header') continue;
        $value = $endpoint['example'][$name] ?? $field['schema']['default'] ?? null;
        if ($field['in'] === 'path') $value = $name === 'slug' ? 'initial-consultation' : ($endpoint['auth'] === 'attempt' ? str_repeat('a', 32) : '123');
        if (in_array($name, ['from', 'to'], true)) $value = '2026-11-01';
        if ($value === null) continue;
        $result = $args[$name]['validate_callback']($value);
        if (is_wp_error($result)) throw new RuntimeException($endpoint['key'] . '/' . $name . ': ' . $result->get_error_message());
    }
}
$booking = array_values(array_filter(EndpointCatalog::definitions(), static fn($endpoint) => $endpoint['key'] === 'patient_booking_create'))[0];
$validate = EndpointCatalog::args($booking)['patient']['validate_callback'];
$patient = $booking['example']['patient']; unset($patient['appointment_start']);
if (!is_wp_error($validate($patient))) throw new RuntimeException('Required nested appointment time accepted when missing');
$patient = $booking['example']['patient']; $patient['archived_at'] = '2026-11-01';
if (!is_wp_error($validate($patient))) throw new RuntimeException('Unknown nested patient field accepted');
foreach (['id' => '999', 'user_id' => 8, 'first_name' => 'Someone', 'email' => 'other@example.test'] as $field => $value) {
    $patient = $booking['example']['patient']; $patient[$field] = $value;
    if (!is_wp_error($validate($patient))) throw new RuntimeException('Caller-selected patient identity accepted: ' . $field);
}
$availability = array_values(array_filter(EndpointCatalog::definitions(), static fn($endpoint) => $endpoint['key'] === 'availability'))[0];
$validateDate = EndpointCatalog::args($availability)['from']['validate_callback'];
if (!is_wp_error($validateDate('2026-02-31'))) throw new RuntimeException('Impossible calendar date accepted');
$flow = array_values(array_filter(EndpointCatalog::definitions(), static fn($endpoint) => $endpoint['key'] === 'booking_flow_create'))[0];
$validateFlowPatient = EndpointCatalog::args($flow)['patient']['validate_callback'];
if (is_wp_error($validateFlowPatient([]))) throw new RuntimeException('Automatic flow empty scheduling object rejected');
if (!is_wp_error($validateFlowPatient(['email' => 'other@example.test']))) throw new RuntimeException('Flow identity override accepted');
echo "[PASS] Canonical schemas and examples validate with actual WordPress REST validators\n";
