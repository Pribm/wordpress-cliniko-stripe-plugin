<?php
/** Read-only real-WordPress admin fixture. No settings are saved and outgoing HTTP is blocked. */
if (PHP_SAPI !== 'cli' || empty($argv[1])) { fwrite(STDERR, "Pass a WordPress source directory and fixture name.\n"); exit(1); }
define('WP_USE_THEMES', false);
ob_start();
require rtrim($argv[1], '/\\') . '/wp-load.php';
ob_end_clean();
require_once __DIR__ . '/../vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use App\Admin\Modules\AccountBuilders\AccountBuilders;
use App\Admin\AdminWorkspace;
use App\Admin\Modules\Debug;
use App\Admin\Modules\Tools;
use App\Admin\Modules\UserGuide;
use App\Admin\Modules\Settings\Credentials;
use App\Admin\Modules\Settings\PatientSyncSettings;
use App\Admin\Modules\Settings\Settings;

add_filter('pre_http_request', static fn() => new WP_Error('fixture_http_blocked', 'No outgoing HTTP in UI fixtures.'), 999);
add_filter('pre_option', static function ($value, $option, $default) {
    if (str_starts_with($option, 'wp_cliniko_') || str_starts_with($option, 'wp_stripe_') || str_starts_with($option, 'wp_tyro') || $option === 'nextend_social_login') return is_array($default) ? [] : (is_string($default) ? '' : 0);
    return $value;
}, 999, 3);
$admin = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
if ($admin === []) throw new RuntimeException('A local administrator is needed to render the admin fixture.');
wp_set_current_user((int) $admin[0]);
$fixture = $argv[2] ?? 'forms';
$queries = [
    'forms' => ['builder_tab' => 'patient-forms'],
    'profile-editor' => ['builder_tab' => 'patient-forms', 'action' => 'new'],
    'onboarding' => ['builder_tab' => 'onboarding'],
    'onboarding-editor' => ['builder_tab' => 'onboarding', 'action' => 'new'],
    'bookings' => ['builder_tab' => 'booking-forms', 'booking_section' => 'patient'],
    'booking-editor' => ['builder_tab' => 'booking-forms', 'booking_section' => 'patient', 'action' => 'new'],
    'dynamic-booking' => ['builder_tab' => 'booking-forms', 'booking_section' => 'dynamic'],
    'aliases' => ['builder_tab' => 'booking-forms', 'booking_section' => 'aliases'],
    'dashboard' => ['builder_tab' => 'dashboard-modules'],
    'dashboard-editor' => ['builder_tab' => 'dashboard-modules', 'action' => 'new'],
    'attachments' => ['builder_tab' => 'dashboard-modules', 'dashboard_section' => 'patient-attachments'],
    'communications' => ['builder_tab' => 'dashboard-modules', 'dashboard_section' => 'patient-communications'],
    'components' => ['builder_tab' => 'components'],
    'shortcodes' => ['builder_tab' => 'shortcodes'],
    'redirects' => ['builder_tab' => 'redirects'],
    'emails' => ['builder_tab' => 'emails'],
    'code-editor' => ['builder_tab' => 'custom-code', 'action' => 'new'],
    'migration' => ['builder_tab' => 'migration'],
    'settings' => ['page' => 'wp-cliniko-stripe-settings'],
    'patient-settings' => ['page' => 'wp-cliniko-stripe-settings', 'tab' => 'patient-accounts'],
    'tools' => ['page' => 'wp-cliniko-tools'],
    'debug' => ['page' => 'wp-cliniko-debug'],
    'guide' => ['page' => 'wp-cliniko-user-guide'],
];
if (!isset($queries[$fixture])) throw new RuntimeException('Unknown UI fixture.');
$_GET = $queries[$fixture] + ['page' => AccountBuilders::PAGE];
$_POST = [];
if ($_GET['page'] === AccountBuilders::PAGE) {
    AccountBuilders::enqueueAssets('');
    $render = [AccountBuilders::class, 'renderPage'];
} else {
    AdminWorkspace::enqueueAssets();
    $render = match ($fixture) {
        'settings', 'patient-settings' => [Settings::class, 'renderSettingsPage'],
        'tools' => [Tools::class, 'renderPage'],
        'debug' => [Debug::class, 'renderPage'],
        'guide' => [UserGuide::class, 'renderPage'],
    };
    if (in_array($fixture, ['settings', 'patient-settings'], true)) {
        Credentials::registerSettings();
        PatientSyncSettings::registerSettings();
        Credentials::enqueueScript();
    }
    if ($fixture === 'guide') UserGuide::enqueueAssets();
    if ($fixture === 'debug') {
        // Skip schema installation in this process; never read or change live logs.
        (new ReflectionProperty(App\Debug\LogStore::class, 'ensured'))->setValue(null, true);
        add_filter('query', static function (string $query): string {
            if (!str_contains($query, 'cliniko_debug_logs')) return $query;
            if (!str_starts_with(ltrim($query), 'SELECT')) throw new RuntimeException('UI fixture attempted to change logs.');
            if (str_contains($query, 'COUNT(*) AS total')) return 'SELECT 1 AS total, 0 AS errors, 0 AS warnings, 1 AS slow';
            if (str_contains($query, 'COUNT(*)')) return 'SELECT 1';
            if (str_contains($query, 'DISTINCT event')) return "SELECT 'booking_preflight_failed' AS event";
            if (str_contains($query, 'DISTINCT channel')) return "SELECT 'fixture' AS channel";
            return "SELECT 1 AS id, '2026-10-09 00:00:00' AS created_at, 'info' AS level, 'fixture' AS channel, 'ui_test' AS event, 'ui-fixture-trace' AS trace_id, 200 AS status_code, 1200 AS duration_ms, '/cliniko-api/v1/me' AS route, 'Synthetic test data only' AS target, 'A sample log entry for layout testing.' AS message, '{\"fixture\":true}' AS context";
        }, 999);
        Debug::registerSettings();
    }
}
ob_start(); $render(); $html = ob_get_clean();
$repo = realpath(__DIR__ . '/..');
$wpRoot = realpath(ABSPATH);
$asset = static function (string $src) use ($repo, $wpRoot): ?string {
    $path = (string) parse_url($src, PHP_URL_PATH);
    $pluginMarker = '/wp-content/plugins/' . basename($repo) . '/';
    $position = strpos($path, $pluginMarker);
    if ($position !== false) $resolved = realpath($repo . '/' . substr($path, $position + strlen($pluginMarker)));
    else {
        $position = strpos($path, '/wp-includes/');
        if ($position === false) $position = strpos($path, '/wp-admin/');
        $resolved = $position === false ? false : realpath($wpRoot . substr($path, $position));
    }
    if (!$resolved || !is_file($resolved) || !in_array(pathinfo($resolved, PATHINFO_EXTENSION), ['css', 'js'], true)) return null;
    if (!str_starts_with($resolved, $repo . DIRECTORY_SEPARATOR) && !str_starts_with($resolved, $wpRoot . DIRECTORY_SEPARATOR)) return null;
    return $resolved;
};
$collect = static function ($registry) use ($asset): array {
    $items = []; $seen = [];
    $visit = static function ($handle) use (&$visit, &$items, &$seen, $registry, $asset): void {
        if (isset($seen[$handle]) || !isset($registry->registered[$handle])) return;
        $seen[$handle] = true; $item = $registry->registered[$handle];
        foreach ($item->deps as $dependency) $visit($dependency);
        $items[] = ['path' => $item->src ? $asset($item->src) : null, 'data' => $item->extra['data'] ?? '', 'before' => array_values(array_filter($item->extra['before'] ?? [], 'is_string')), 'after' => array_values(array_filter($item->extra['after'] ?? [], 'is_string'))];
    };
    foreach ($registry->queue as $handle) $visit($handle);
    return $items;
};
echo wp_json_encode(['html' => $html, 'query' => $_GET, 'styles' => $collect(wp_styles()), 'scripts' => $collect(wp_scripts())], JSON_INVALID_UTF8_SUBSTITUTE);
