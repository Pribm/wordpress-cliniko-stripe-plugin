<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

$GLOBALS['nextend_redirect_test_options'] = [
    // Nextend serializes before calling update_option(), leaving this extra
    // serialized layer when WordPress reads the option.
    'nextend_social_login' => serialize([
        'enabled' => ['google'],
        'default_redirect' => 'https://example.test/dashboard/',
        'default_redirect_reg' => 'https://example.test/verify-your-email/',
    ]),
];
$GLOBALS['nextend_redirect_test_filters'] = [];
$GLOBALS['nextend_redirect_test_meta'] = [];

class NextendSocialLogin
{
    public static string $WPLoginCurrentFlow = 'login';
}

class WP_User
{
    public function __construct(public int $ID)
    {
    }
}

function get_option(string $option, $default = false)
{
    return $GLOBALS['nextend_redirect_test_options'][$option] ?? $default;
}

function maybe_unserialize($value)
{
    if (!is_string($value)) {
        return $value;
    }
    $unserialized = @unserialize($value);
    return $unserialized === false && $value !== 'b:0;' ? $value : $unserialized;
}

function get_user_meta(int $userId, string $key, bool $single = false)
{
    return $GLOBALS['nextend_redirect_test_meta'][$userId][$key] ?? '';
}

function sanitize_key(string $value): string
{
    return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $value));
}

function add_filter(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    $GLOBALS['nextend_redirect_test_filters'][$hook] = compact('callback', 'priority', 'acceptedArgs');
    return true;
}

function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
{
    $GLOBALS['nextend_redirect_test_filters'][$hook] = compact('callback', 'priority', 'acceptedArgs');
    return true;
}

function um_get_core_page(string $page): string
{
    return 'https://example.test/' . ($page === 'register' ? 'register' : 'login') . '/';
}

function wp_parse_url(string $url)
{
    return parse_url($url);
}

function wp_unslash(string $value): string
{
    return stripslashes($value);
}

function wp_validate_redirect(string $location, string $fallback = ''): string
{
    if ($location === '') {
        return $fallback;
    }
    $host = parse_url($location, PHP_URL_HOST);
    return $host === null || $host === false || $host === 'example.test' ? $location : $fallback;
}

require __DIR__ . '/../vendor/autoload.php';

use App\Support\NextendRedirectCompatibility;

function assert_nextend_redirect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

NextendRedirectCompatibility::init();
assert_nextend_redirect(
    isset($GLOBALS['nextend_redirect_test_filters']['nsl_googledefault_last_location_redirect']),
    'The enabled Nextend provider redirect filter was not registered.'
);
assert_nextend_redirect(
    isset($GLOBALS['nextend_redirect_test_filters']['um_registration_show_message_redirect_url'])
        && isset($GLOBALS['nextend_redirect_test_filters']['um_registration_pending_user_redirect']),
    'The Ultimate Member pending registration filters were not registered.'
);
assert_nextend_redirect(
    isset($GLOBALS['nextend_redirect_test_filters']['authenticate'])
        && isset($GLOBALS['nextend_redirect_test_filters']['nsl_disabled_login_redirect_url']),
    'The Nextend authentication-error redirect filters were not registered.'
);
assert_nextend_redirect(
    isset($GLOBALS['nextend_redirect_test_filters']['login_init']),
    'The native WordPress Nextend-notice redirect guard was not registered.'
);

$defaultLogin = NextendRedirectCompatibility::replaceUltimateMemberAuthRedirect(
    'https://example.test/login/',
    'https://example.test/login/'
);
assert_nextend_redirect(
    $defaultLogin === 'https://example.test/dashboard/',
    'A bare Ultimate Member login self-redirect did not use the default login destination.'
);

$protectedPage = 'https://example.test/dashboard/appointments/';
$contextualLogin = NextendRedirectCompatibility::replaceUltimateMemberAuthRedirect(
    'https://example.test/login/?redirect_to=' . rawurlencode($protectedPage),
    'https://example.test/login/?redirect_to=' . rawurlencode($protectedPage)
);
assert_nextend_redirect(
    $contextualLogin === $protectedPage,
    'A protected-page redirect nested in the Ultimate Member login URL was not preserved.'
);

$directProtectedPage = NextendRedirectCompatibility::replaceUltimateMemberAuthRedirect(
    $protectedPage,
    $protectedPage
);
assert_nextend_redirect(
    $directProtectedPage === $protectedPage,
    'A genuine explicit redirect was changed.'
);

NextendSocialLogin::$WPLoginCurrentFlow = 'register';
$defaultRegistration = NextendRedirectCompatibility::replaceUltimateMemberAuthRedirect(
    'https://example.test/register/',
    'https://example.test/register/'
);
assert_nextend_redirect(
    $defaultRegistration === 'https://example.test/verify-your-email/',
    'A bare Ultimate Member registration self-redirect did not use the registration default.'
);

$GLOBALS['nextend_redirect_test_meta'][12]['_cliniko_link_status'] = 'pending';
$ultimateMemberPending = NextendRedirectCompatibility::replacePendingRegistrationRedirect(
    'https://example.test/register/?message=pending&um_role=um_patient&um_form_id=',
    'pending',
    12,
    []
);
assert_nextend_redirect(
    $ultimateMemberPending === 'https://example.test/verify-your-email/',
    'Ultimate Member took over the pending social-registration redirect.'
);

$GLOBALS['nextend_redirect_test_meta'][13]['_cliniko_link_status'] = 'verified';
$unrelatedPending = NextendRedirectCompatibility::replacePendingRegistrationRedirect(
    'https://example.test/register/?message=pending',
    'pending',
    13,
    []
);
assert_nextend_redirect(
    $unrelatedPending === 'https://example.test/register/?message=pending',
    'A pending Ultimate Member registration not owned by the Cliniko link flow was changed.'
);

$ordinaryErrorRedirect = NextendRedirectCompatibility::replaceDisabledLoginRedirect(
    'https://example.test/wp-login.php'
);
assert_nextend_redirect(
    $ordinaryErrorRedirect === 'https://example.test/login/',
    'An ordinary Nextend authentication error was sent to the WordPress login page.'
);

NextendRedirectCompatibility::capturePendingPatientAuthentication(new WP_User(12), 'patient', '');
$pendingErrorRedirect = NextendRedirectCompatibility::replaceDisabledLoginRedirect(
    'https://example.test/wp-login.php'
);
assert_nextend_redirect(
    $pendingErrorRedirect === 'https://example.test/verify-your-email/',
    'A pending patient authentication error was not sent to the registration guidance page.'
);

echo "Nextend redirect compatibility tests passed.\n";
