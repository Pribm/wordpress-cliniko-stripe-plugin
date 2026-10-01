<?php
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/../');

/** @var bool */
$GLOBALS['authenticated_patient_test_logged_in'] = false;
/** @var array<int,array{namespace:string,route:string,args:array<string,mixed>}> */
$GLOBALS['authenticated_patient_test_routes'] = [];

function is_user_logged_in(): bool
{
    return (bool) $GLOBALS['authenticated_patient_test_logged_in'];
}

function wp_cliniko_get_secret_option(string $option): string
{
    return 'test-key-au1';
}

function get_option(string $option, $default = false)
{
    return $default;
}

function home_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'test-salt';
}

/**
 * @param array<string,mixed> $args
 */
function register_rest_route(string $namespace, string $route, array $args): bool
{
    $GLOBALS['authenticated_patient_test_routes'][] = compact('namespace', 'route', 'args');
    return true;
}

class WP_REST_Request
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

require __DIR__ . '/../vendor/autoload.php';

use App\Authorization\AuthenticatedPatientPolicy;
use App\Authorization\PatientLinkVerificationPolicy;
use App\Authorization\VerifiedPatientPolicy;
use App\Client\Cliniko\Client;
use App\Routes\AuthenticatedPatientRoutes;
use App\Routes\PatientLinkRoutes;
use App\Routes\PublicGuestRoutes;
use App\Routes\PublicPatientLinkRoutes;

function assert_authenticated_patient_route(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$clientReflection = new ReflectionClass(Client::class);
$clientInstance = $clientReflection->getProperty('instance');
$clientInstance->setAccessible(true);
$authenticatedRoutes = new AuthenticatedPatientRoutes();
$patientLinkRoutes = new PatientLinkRoutes();
$publicPatientLinkRoutes = new PublicPatientLinkRoutes();
assert_authenticated_patient_route(
    $clientInstance->getValue() === null,
    'Constructing route definitions initialized the Cliniko client before REST registration.'
);

$authenticatedRoutes->register();
$routes = $GLOBALS['authenticated_patient_test_routes'];
$expected = [
    ['v2', '/patient/me', 'GET'],
    ['v2', '/patient/me/appointments', 'GET'],
    ['v2', '/patient/me/appointments/(?P<appointment_id>[\w-]+)', 'GET'],
    ['v2', '/patient/me', ['PUT', 'PATCH']],
    ['v2', '/patient/me/email-change', 'POST'],
    ['v2', '/patient/me/attachments', 'GET'],
    ['v2', '/patient/me/attachments/presign', 'POST'],
    ['v2', '/patient/me/attachments', 'POST'],
    ['v2', '/patient/me/attachments/upload', 'POST'],
    ['v2', '/patient/me/attachments/(?P<attachment_id>[0-9]+)', ['PATCH']],
    ['v2', '/patient/me/attachments/(?P<attachment_id>[0-9]+)', ['DELETE']],
    ['v2', '/patient/me/communications', 'GET'],
    ['v2', '/patient/me/communications/unread', 'GET'],
    ['v2', '/patient/me/communications/read', 'POST'],
    ['v2', '/patient/me/communications', 'POST'],
];

assert_authenticated_patient_route(count($routes) === count($expected), 'Unexpected number of authenticated patient routes.');
foreach ($expected as $index => [$namespace, $route, $methods]) {
    $registered = $routes[$index];
    assert_authenticated_patient_route($registered['namespace'] === $namespace, 'Route namespace changed.');
    assert_authenticated_patient_route($registered['route'] === $route, 'Route path changed.');
    assert_authenticated_patient_route($registered['args']['methods'] === $methods, 'Route methods changed.');
    assert_authenticated_patient_route(
        is_array($registered['args']['permission_callback'])
        && $registered['args']['permission_callback'][0] instanceof VerifiedPatientPolicy,
        'Verified patient policy was not applied.'
    );
}

$policy = new AuthenticatedPatientPolicy();
$denied = $policy->authorize(new WP_REST_Request());
assert_authenticated_patient_route($denied instanceof WP_Error, 'Anonymous patient request was not denied.');
assert_authenticated_patient_route($denied->data['status'] === 403, 'Anonymous patient request did not return 403.');

$GLOBALS['authenticated_patient_test_logged_in'] = true;
assert_authenticated_patient_route($policy->authorize(new WP_REST_Request()) === true, 'Logged-in patient request was not allowed.');

$GLOBALS['authenticated_patient_test_routes'] = [];
$patientLinkRoutes->register();
$linkRoutes = $GLOBALS['authenticated_patient_test_routes'];
assert_authenticated_patient_route(count($linkRoutes) === 2, 'Unexpected patient-link route count.');
assert_authenticated_patient_route($linkRoutes[0]['route'] === '/patient-link/request', 'Patient-link request route changed.');
assert_authenticated_patient_route($linkRoutes[0]['args']['methods'] === 'POST', 'Patient-link request method changed.');
assert_authenticated_patient_route($linkRoutes[1]['route'] === '/patient-link/status', 'Patient-link status route changed.');
assert_authenticated_patient_route($linkRoutes[1]['args']['methods'] === 'GET', 'Patient-link status method changed.');

$GLOBALS['authenticated_patient_test_routes'] = [];
$publicPatientLinkRoutes->register();
$verificationRoutes = $GLOBALS['authenticated_patient_test_routes'];
assert_authenticated_patient_route(count($verificationRoutes) === 2, 'Unexpected public verification route count.');
assert_authenticated_patient_route(
    $verificationRoutes[0]['route'] === '/patient-link/verify',
    'Public patient-link verification route changed.'
);
assert_authenticated_patient_route(
    $verificationRoutes[0]['args']['methods'] === ['GET', 'POST'],
    'Public patient-link verification methods changed.'
);
assert_authenticated_patient_route(
    is_array($verificationRoutes[0]['args']['permission_callback'])
    && $verificationRoutes[0]['args']['permission_callback'][0] instanceof PatientLinkVerificationPolicy,
    'Token verification policy was not applied.'
);
assert_authenticated_patient_route(
    $verificationRoutes[1]['route'] === '/patient/email-change/verify',
    'Public patient email-change verification route was not registered.'
);
assert_authenticated_patient_route(
    $verificationRoutes[1]['args']['methods'] === ['GET', 'POST'],
    'Public patient email-change verification methods changed.'
);
assert_authenticated_patient_route(
    is_array($verificationRoutes[1]['args']['permission_callback'])
    && $verificationRoutes[1]['args']['permission_callback'][0] instanceof PatientLinkVerificationPolicy,
    'Patient email-change token policy was not applied.'
);

$GLOBALS['authenticated_patient_test_routes'] = [];
(new PublicGuestRoutes())->register();
$publicRoutes = $GLOBALS['authenticated_patient_test_routes'];
$expectedPublicRoutes = [
    ['v1', '/send-patient-form', 'POST', 'allowPublicMutation'],
    ['v1', '/available-times', 'GET', 'allowPublicRead'],
    ['v1', '/practitioners', 'GET', 'allowPublicRead'],
    ['v1', '/appointment-type', 'GET', 'allowPublicRead'],
    ['v1', '/patient-form-template', 'GET', 'allowPublicRead'],
    ['v1', '/appointment-calendar', 'GET', 'allowPublicRead'],
    ['v1', '/next-available-times', 'GET', 'allowPublicRead'],
    ['v1', '/payments/charge', 'POST', 'allowPublicMutation'],
    ['v1', '/tyrohealth/sdk-token', 'POST', 'allowSdkToken'],
    ['v1', '/tyrohealth/charge', 'POST', 'allowPublicMutation'],
    ['v1', '/tyrohealth/invoice', 'POST', 'allowPublicMutation'],
    ['v2', '/booking-attempts/preflight', 'POST', 'allowPublicMutation'],
    ['v2', '/booking-attempts/charge-stripe', 'POST', 'allowAttemptMutation'],
    ['v2', '/booking-attempts/confirm-tyro', 'POST', 'allowAttemptMutation'],
    ['v2', '/booking-attempts/finalize', 'POST', 'allowAttemptMutation'],
    ['v2', '/booking-attempts/status', 'GET', 'allowAttemptMutation'],
    ['v2', '/patient-access/request', 'POST', 'allowPatientAccessRequest'],
    ['v2', '/patient-access/verify', 'POST', 'allowPatientAccessRequest'],
    ['v2', '/patient-access/request-status', 'GET', 'allowPatientAccessRequest'],
    ['v2', '/patient-access/request-complete', 'POST', 'allowPatientAccessRead'],
    ['v2', '/patient-access/appointments', 'GET', 'allowPatientAccessRead'],
    ['v2', '/patient-access/appointments/(?P<booking_id>[\w-]+)/prefill', 'GET', 'allowPatientAccessRead'],
    ['v2', '/patient-access/latest', 'GET', 'allowPatientAccessRead'],
];

assert_authenticated_patient_route(count($publicRoutes) === count($expectedPublicRoutes), 'Public/guest route count changed.');
foreach ($expectedPublicRoutes as $index => [$namespace, $route, $methods, $policyMethod]) {
    $registered = $publicRoutes[$index];
    assert_authenticated_patient_route($registered['namespace'] === $namespace, 'Public/guest namespace changed.');
    assert_authenticated_patient_route($registered['route'] === $route, 'Public/guest route changed.');
    assert_authenticated_patient_route($registered['args']['methods'] === $methods, 'Public/guest methods changed.');
    assert_authenticated_patient_route(
        is_array($registered['args']['permission_callback'])
        && ($registered['args']['permission_callback'][1] ?? '') === $policyMethod,
        'Public/guest permission callback changed.'
    );
}

echo "Authenticated patient route tests passed.\n";
