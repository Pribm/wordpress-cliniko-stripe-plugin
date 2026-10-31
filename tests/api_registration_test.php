<?php
declare(strict_types=1);

// Synthetic WordPress transport/storage, real signup service, real Cliniko linking and email verification.
define('CLINIKO_API_TEST_BOOTSTRAP_ONLY', true);
require __DIR__ . '/managed_api_test.php';

use App\Service\Api\ApiClients;
use App\Service\Api\ApiSettings;
use App\Service\Api\EndpointCatalog;
use App\Service\Api\PatientAuthentication;
use App\Service\Api\PatientRegistration;
use App\Service\PatientLinkService;
use App\Service\PatientService;
use App\Contracts\ApiClientInterface;
use App\Contracts\ClientResponse;

function get_bloginfo(string $show = ''): string { return 'Test Clinic'; }
function current_time(string $type, bool $gmt = false): string { return gmdate('Y-m-d H:i:s'); }
function wp_cliniko_secret_option_encrypt($value): string { return 'fixture-encrypted:' . $value; }
function wp_cliniko_secret_option_decrypt(string $value): string { return str_starts_with($value, 'fixture-encrypted:') ? substr($value, 18) : $value; }
function wp_cliniko_secret_option_is_encrypted(string $value): bool { return str_starts_with($value, 'fixture-encrypted:'); }
function wp_mail($to, $subject, $message, $headers = [], $attachments = []): bool { $GLOBALS['registration_mail'][] = compact('to', 'subject', 'message'); return !$GLOBALS['registration_mail_fail']; }

final class RegistrationClinikoFixture implements ApiClientInterface
{
    public array $calls = [], $matches = [];
    public int $created = 0;
    public bool $unavailable = false;
    public function get(string $url): ClientResponse {
        $this->calls[] = ['GET', $url];
        if ($this->unavailable) return new ClientResponse(null, 'Synthetic Cliniko failure', 503);
        if (str_contains($url, '?')) return new ClientResponse(['patients' => $this->matches]);
        return new ClientResponse($this->matches[0] ?? ['id' => '9001', 'first_name' => 'New', 'last_name' => 'Patient', 'email' => 'new@example.test']);
    }
    public function post(string $url, array $data): ClientResponse { $this->calls[] = ['POST', $url]; $this->created++; return new ClientResponse(['id' => '9001', 'first_name' => $data['first_name'], 'last_name' => $data['last_name'], 'email' => $data['email']]); }
    public function put(string $url, array $data): ClientResponse { throw new RuntimeException('Signup must not update a Cliniko patient'); }
    public function patch(string $url, array $data): ClientResponse { throw new RuntimeException('Signup must not update a Cliniko patient'); }
}

function signupFixture(): array {
    resetApi(); $client = patientApp();
    ApiClients::update($client['id'], ['account:register', 'patient:read'], $client['expires_at']); $client = ApiClients::patient($client['id']);
    update_option('wp_cliniko_patient_sync_enabled', true);
    $s = ApiSettings::get(); foreach (['registration_password_enabled', 'registration_social_enabled', 'registration_oauth_enabled', 'registration_reviewed'] as $key) $s[$key] = true;
    $s['registration_role'] = 'patient'; update_option(ApiSettings::OPTION, $s);
    $GLOBALS['registration_mail'] = []; $GLOBALS['registration_mail_fail'] = false;
    $clinic = new RegistrationClinikoFixture(); $links = new PatientLinkService($clinic);
    return [$client, new PatientRegistration(new PatientService($clinic, $clinic), $links), $clinic, $links];
}
function signupBody(array $client): array { return ['client_id' => $client['id'], 'email' => 'new@example.test', 'password' => 'patient-password-2026', 'first_name' => 'New', 'last_name' => 'Patient', 'accept_privacy_policy' => true]; }
function registrationUser(): int { return (int) array_key_first($GLOBALS['registered_users']); }
function emailProof(): string {
    preg_match('/href="([^"]+)"/', $GLOBALS['registration_mail'][array_key_last($GLOBALS['registration_mail'])]['message'], $match);
    parse_str(parse_url(html_entity_decode($match[1]), PHP_URL_QUERY), $params);
    return $params['token'];
}

$cases = [
    'password signup invokes existing email flow, stays pending and creates Cliniko only after verification' => function (): void {
        [$app, $signup, $clinic, $links] = signupFixture();
        $result = PatientAuthentication::dispatch(authRequest(signupBody($app)), 'auth_register', $signup);
        check(!is_wp_error($result) && $result['status'] === 'verification_pending', 'Signup failed');
        check(!isset($result['access_token'], $result['user_id']) && $GLOBALS['wpdb']->sessions === [], 'Signup granted patient access');
        $id = registrationUser(); check(get_user_meta($id, PatientService::META_ACCOUNT_STATUS, true) === PatientService::ACCOUNT_PENDING_REVIEW, 'Account was activated before proof');
        check(count($GLOBALS['registration_mail']) === 1 && $clinic->created === 0, 'Missing/duplicate email or early Cliniko creation');
        $login = authRequest(['client_id' => $app['id'], 'identifier' => 'new@example.test', 'password' => 'patient-password-2026']);
        check(is_wp_error(PatientAuthentication::dispatch($login, 'auth_login')), 'Pending account logged in');
        check($GLOBALS['registration_inserts'][0]['role'] === 'patient' && get_user_meta($id, 'accepted_privacy_policy', true) === '1', 'Role or consent missing');
        check($links->verify($id, emailProof())['ok'] && $clinic->created === 1, 'Existing email verifier did not finish creation');
        check(get_user_meta($id, PatientService::META_ACCOUNT_STATUS, true) === PatientService::ACCOUNT_ACTIVE, 'Verified account not active');
        $state = $signup->progress(['registration_token' => $result['registration_token']], $app);
        check($state['status'] === 'ready_to_sign_in', 'App progress not ready');
        check(!isset($state['access_token'], $state['patient_id']) && $GLOBALS['wpdb']->sessions === [], 'Verification progress issued a session');
        check(!is_wp_error(PatientAuthentication::dispatch($login, 'auth_login')), 'Verified new password account could not sign in');
    },
    'existing Cliniko patient is linked after email proof and never updated during signup' => function (): void {
        [$app, $signup, $clinic, $links] = signupFixture();
        $clinic->matches = [['id' => '9001', 'first_name' => 'New', 'last_name' => 'Patient', 'email' => 'new@example.test']];
        $result = $signup->register(signupBody($app), $app, 'password');
        check(!is_wp_error($result) && $clinic->created === 0 && count($GLOBALS['registration_mail']) === 1, 'Existing match signup failed');
        check($links->verify(registrationUser(), emailProof())['ok'] && $clinic->created === 0, 'Existing patient duplicated');
        check(count(array_filter($clinic->calls, static fn($call) => $call[0] !== 'GET')) === 0, 'Signup mutated an existing Cliniko profile');
    },
    'ambiguous Cliniko matches and mail failure never activate the new account' => function (): void {
        [$app, $signup, $clinic] = signupFixture();
        $clinic->matches = [['id' => '1', 'email' => 'new@example.test'], ['id' => '2', 'email' => 'new@example.test']];
        $result = $signup->register(signupBody($app), $app, 'password');
        check(!is_wp_error($result) && count($GLOBALS['registration_mail']) === 0 && $clinic->created === 0, 'Ambiguous match issued verification/created patient');
        check(get_user_meta(registrationUser(), PatientService::META_ACCOUNT_STATUS, true) !== PatientService::ACCOUNT_ACTIVE, 'Ambiguous match activated account');
        [$app, $signup] = signupFixture(); $GLOBALS['registration_mail_fail'] = true;
        $result = $signup->register(signupBody($app), $app, 'password');
        check(!is_wp_error($result) && get_user_meta(registrationUser(), PatientLinkService::META_STATUS, true) === PatientLinkService::STATUS_FAILED, 'Mail failure was ignored');
        check(get_user_meta(registrationUser(), PatientService::META_ACCOUNT_STATUS, true) !== PatientService::ACCOUNT_ACTIVE, 'Mail failure activated account');
    },
    'existing WordPress email is not overwritten or joined to a new identity' => function (): void {
        [$app, $signup, $clinic] = signupFixture(); $body = signupBody($app); $body['email'] = 'alex@example.test';
        $result = $signup->register($body, $app, 'password');
        check(!is_wp_error($result) && $result['status'] === 'verification_pending' && $GLOBALS['registered_users'] === [] && $clinic->calls === [], 'Existing email overwritten or inspected in Cliniko');
        check($signup->progress(['registration_token' => $result['registration_token']], $app)['status'] === 'verification_pending', 'Existing account state leaked');
        unset($body['password'], $body['email']);
        $identity = ['provider' => 'google', 'issuer' => 'https://accounts.google.com', 'subject' => 'new-subject', 'email' => 'alex@example.test', 'email_verified' => true];
        $result = $signup->register($body, $app, 'social', $identity);
        check(!is_wp_error($result) && ApiClients::userId($identity['issuer'], $identity['subject']) === 0 && $GLOBALS['registered_users'] === [], 'Existing email auto-linked to social identity');
    },
    'duplicate submission does not reset passwords names roles links or send repeated emails' => function (): void {
        [$app, $signup] = signupFixture(); $body = signupBody($app);
        $first = $signup->register($body, $app, 'password'); $before = serialize([$GLOBALS['registered_users'], $GLOBALS['registered_meta']]);
        $body['password'] = 'another-password-2026'; $body['first_name'] = 'Attacker'; $second = $signup->register($body, $app, 'password');
        check(!is_wp_error($second) && $first['status'] === $second['status'] && serialize([$GLOBALS['registered_users'], $GLOBALS['registered_meta']]) === $before, 'Duplicate changed an account');
        check(count($GLOBALS['registration_mail']) === 1 && count($GLOBALS['registered_users']) === 1, 'Duplicate created account or mail');
    },
    'Google registration validates signed nonce-bound identity then emails its verified address' => function (): void {
        [$app, $signup, $clinic, $links] = signupFixture();
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $app['id'], 'provider' => 'google', 'purpose' => 'register']), 'auth_challenge');
        $token = socialToken(['iss' => 'https://accounts.google.com', 'aud' => $app['google_client_id'], 'sub' => 'new-google-subject', 'email' => 'new@example.test', 'email_verified' => true, 'nonce' => $challenge['nonce'], 'iat' => time(), 'exp' => time() + 300]);
        $body = signupBody($app); unset($body['password'], $body['email']); $body += ['provider' => 'google', 'challenge_id' => $challenge['challenge_id'], 'id_token' => $token];
        $result = PatientAuthentication::dispatch(authRequest($body), 'auth_register_social', $signup);
        check(!is_wp_error($result) && ApiClients::userId('https://accounts.google.com', 'new-google-subject') === registrationUser(), 'Verified new Google identity was not bound to its new account');
        check($GLOBALS['registration_mail'][0]['to'] === 'new@example.test' && $GLOBALS['wpdb']->sessions === [], 'Wrong social email or early JWT');
        check(is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_register_social', $signup)), 'Signup challenge replayed');
        $loginChallenge = PatientAuthentication::dispatch(authRequest(['client_id' => $app['id'], 'provider' => 'google']), 'auth_challenge');
        $body['challenge_id'] = $loginChallenge['challenge_id']; $body['id_token'] = socialToken(['iss' => 'https://accounts.google.com', 'aud' => $app['google_client_id'], 'sub' => 'new-google-subject', 'nonce' => $loginChallenge['nonce'], 'iat' => time(), 'exp' => time() + 300]);
        check(is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_social')), 'Pending Google account logged in');
        check($links->verify(registrationUser(), emailProof())['ok'], 'Google account did not verify through existing email flow');
        $loginChallenge = PatientAuthentication::dispatch(authRequest(['client_id' => $app['id'], 'provider' => 'google']), 'auth_challenge');
        $body['challenge_id'] = $loginChallenge['challenge_id']; $body['id_token'] = socialToken(['iss' => 'https://accounts.google.com', 'aud' => $app['google_client_id'], 'sub' => 'new-google-subject', 'nonce' => $loginChallenge['nonce'], 'iat' => time(), 'exp' => time() + 300]);
        check(!is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_social')), 'Verified newly mapped Google account could not sign in');
    },
    'Apple registration accepts verified relay email but still requires clinic email proof' => function (): void {
        [$app, $signup] = signupFixture();
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $app['id'], 'provider' => 'apple', 'purpose' => 'register']), 'auth_challenge');
        $token = socialToken(['iss' => 'https://appleid.apple.com', 'aud' => $app['apple_client_id'], 'sub' => 'new-apple-subject', 'email' => 'relay@privaterelay.appleid.com', 'email_verified' => 'true', 'nonce' => $challenge['nonce'], 'iat' => time(), 'exp' => time() + 300]);
        $body = signupBody($app); unset($body['password'], $body['email']); $body += ['provider' => 'apple', 'challenge_id' => $challenge['challenge_id'], 'id_token' => $token];
        $result = PatientAuthentication::dispatch(authRequest($body), 'auth_register_social', $signup);
        check(!is_wp_error($result) && $GLOBALS['registration_mail'][0]['to'] === 'relay@privaterelay.appleid.com', 'Apple verified relay signup failed');
        check(get_user_meta(registrationUser(), PatientService::META_ACCOUNT_STATUS, true) === PatientService::ACCOUNT_PENDING_REVIEW, 'Apple proof bypassed clinic verification');
    },
    'login and registration challenges cannot be substituted' => function (): void {
        [$app, $signup] = signupFixture();
        $challenge = PatientAuthentication::dispatch(authRequest(['client_id' => $app['id'], 'provider' => 'google']), 'auth_challenge');
        $body = signupBody($app); unset($body['password'], $body['email']); $body += ['provider' => 'google', 'challenge_id' => $challenge['challenge_id'], 'id_token' => 'irrelevant'];
        check(is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_register_social', $signup)) && $GLOBALS['registered_users'] === [], 'Login challenge used for signup');
    },
    'unverified provider email or a caller-chosen different clinic email cannot create accounts' => function (): void {
        [$app, $signup] = signupFixture(); $body = signupBody($app); unset($body['password']);
        $identity = ['provider' => 'google', 'issuer' => 'https://accounts.google.com', 'subject' => 'new-social', 'email' => 'new@example.test', 'email_verified' => false];
        check(is_wp_error($signup->register($body, $app, 'social', $identity)), 'Unverified email accepted');
        $identity['email_verified'] = true; $body['email'] = 'victim@example.test';
        check(is_wp_error($signup->register($body, $app, 'social', $identity)) && $GLOBALS['registered_users'] === [], 'Different caller email accepted');
    },
    'OAuth registration validates the original approved client scopes and additional email claims' => function (): void {
        [$app, $signup] = signupFixture(); $oauth = ApiClients::create('External app', 'oauth', 'mobile-client', ['account:register', 'patient:read'], time() + DAY_IN_SECONDS)['client'];
        $GLOBALS['claims']['scope'] = 'account:register patient:read'; $GLOBALS['claims']['email'] = 'new@example.test'; $GLOBALS['claims']['email_verified'] = true;
        $body = signupBody($oauth); unset($body['password'], $body['email']); $body['access_token'] = 'provider-access-token';
        $result = PatientAuthentication::dispatch(authRequest($body), 'auth_register_oauth', $signup);
        check(!is_wp_error($result) && ApiClients::userId(ApiSettings::get()['issuer'], 'patient-seven') === registrationUser(), 'OAuth proof signup failed');
        check($GLOBALS['wpdb']->sessions === [] && $GLOBALS['registration_mail'][0]['to'] === 'new@example.test', 'OAuth signup issued local session or missed email');
    },
    'OAuth registration denies missing scope mismatched app or missing verified email' => function (): void {
        [$app, $signup] = signupFixture(); $oauth = ApiClients::create('External app', 'oauth', 'mobile-client', ['account:register'], time() + DAY_IN_SECONDS)['client'];
        $body = signupBody($oauth); unset($body['password'], $body['email']); $body['access_token'] = 'provider-access-token';
        check(is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_register_oauth', $signup)), 'Missing provider scope accepted');
        $GLOBALS['claims']['scope'] = 'account:register';
        check(is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_register_oauth', $signup)), 'Missing provider email accepted');
        $GLOBALS['claims']['email'] = 'new@example.test'; $GLOBALS['claims']['email_verified'] = true;
        $body['client_id'] = $app['id']; check(is_wp_error(PatientAuthentication::dispatch(authRequest($body), 'auth_register_oauth', $signup)), 'Another local app used provider token');
        check($GLOBALS['registered_users'] === [], 'Rejected OAuth proof created user');
    },
    'consent password names signup permission sync and safe role are mandatory' => function (): void {
        [$app, $signup] = signupFixture(); $body = signupBody($app);
        foreach (['accept_privacy_policy' => false, 'password' => 'short', 'first_name' => ' ', 'email' => 'invalid'] as $key => $value) check(is_wp_error($signup->register(array_replace($body, [$key => $value]), $app, 'password')), 'Invalid field accepted: ' . $key);
        ApiClients::update($app['id'], ['patient:read'], $app['expires_at']); check(is_wp_error($signup->register($body, $app, 'password')), 'Signup scope missing but accepted');
        ApiClients::update($app['id'], ['account:register'], $app['expires_at']); update_option('wp_cliniko_patient_sync_enabled', false);
        check(is_wp_error($signup->register($body, $app, 'password')), 'Disabled sync accepted');
        update_option('wp_cliniko_patient_sync_enabled', true); $GLOBALS['registration_roles']['patient'] = new WP_Role('patient', ['read' => true, 'manage_options' => true]);
        check(is_wp_error($signup->register($body, $app, 'password')) && $GLOBALS['registered_users'] === [], 'Privileged patient role accepted');
    },
    'registration receipts reject expiry alteration email changes and other applications' => function (): void {
        [$app, $signup] = signupFixture(); $result = $signup->register(signupBody($app), $app, 'password'); $body = ['registration_token' => $result['registration_token']];
        $other = ApiClients::create('Other app', 'patient', '', ['account:register'], time() + DAY_IN_SECONDS)['client'];
        check(is_wp_error($signup->progress($body, $other)), 'Receipt used by another app');
        check(is_wp_error($signup->progress(['registration_token' => $body['registration_token'] . 'x'], $app)), 'Altered receipt accepted');
        $key = 'cliniko_api_registration_' . substr($body['registration_token'], 4, 48); $GLOBALS['transients'][$key]['expires_at'] = time() - 1;
        check(is_wp_error($signup->progress($body, $app)), 'Expired receipt accepted');
        $GLOBALS['transients'][$key]['expires_at'] = time() + 3600; get_userdata(registrationUser())->user_email = 'changed@example.test';
        check(is_wp_error($signup->progress($body, $app)), 'Receipt followed an email change');
    },
    'resend uses existing flow cooldown and email budget without granting access' => function (): void {
        [$app, $signup] = signupFixture(); $result = $signup->register(signupBody($app), $app, 'password'); $body = ['registration_token' => $result['registration_token']];
        $signup->progress($body, $app, true); check(count($GLOBALS['registration_mail']) === 1, 'Cooldown ignored');
        update_user_meta(registrationUser(), '_cliniko_link_last_sent_at', time() - 61);
        check(!is_wp_error($signup->progress($body, $app, true)) && count($GLOBALS['registration_mail']) === 2, 'Existing resend flow not invoked');
        $signup->progress($body, $app, true); check(is_wp_error($signup->progress($body, $app, true)), 'Resend budget ignored');
        check($GLOBALS['wpdb']->sessions === [], 'Resend granted a session');
    },
    'unknown role patient IDs verified flags and user IDs are rejected at the route boundary' => function (): void {
        [$app] = signupFixture();
        foreach (['role' => 'administrator', 'patient_id' => '9001', 'verified' => true, 'user_id' => 7] as $key => $value) {
            $response = (new \App\Controller\Api\ManagedController())->dispatch(authRequest(signupBody($app) + [$key => $value]), endpoint('auth_register'));
            check($response->get_status() === 400 && $GLOBALS['registered_users'] === [], 'Dangerous signup field accepted: ' . $key);
        }
    },
    'onboarding receipt cannot authorize protected data or be sent to introspection' => function (): void {
        [$app, $signup] = signupFixture(); $result = $signup->register(signupBody($app), $app, 'password');
        $request = new WP_REST_Request('GET'); $request->set_header('authorization', 'Bearer ' . $result['registration_token']);
        $denied = (new \App\Authorization\Api\AccessPolicy())->authorize($request, endpoint('me'));
        check(is_wp_error($denied) && $denied->get_error_code() === 'registration_token_not_access_token' && $GLOBALS['introspection_calls'] === [], 'Receipt reached provider or patient route');
    },
    'settings are explicit independent and registration docs use HTTP 202' => function (): void {
        [$app] = signupFixture(); $before = ApiSettings::get();
        $result = ApiSettings::validateAppLogin(['section' => 'registration']);
        check(!is_wp_error($result) && !$result['registration_password_enabled'] && $result['oauth_enabled'] === $before['oauth_enabled'] && $result['password_login_enabled'] === $before['password_login_enabled'], 'Registration save modified login settings');
        check(is_wp_error(ApiSettings::validateAppLogin(['section' => 'registration', 'registration_password_enabled' => true, 'registration_role' => 'patient'])), 'Missing signup review accepted');
        $paths = (array) EndpointCatalog::openApi()['paths'];
        foreach (['/auth/register', '/auth/register/social', '/auth/register/oauth', '/auth/registration/resend'] as $path) check(isset($paths[$path]['post']['responses']['202']) && !isset($paths[$path]['post']['responses']['200']), 'Wrong accepted response contract: ' . $path);
    },
    'receipt and signup failures deny safely without creating JWT sessions' => function (): void {
        [$app, $signup] = signupFixture(); $GLOBALS['registration_insert_error'] = true;
        check(is_wp_error($signup->register(signupBody($app), $app, 'password')) && $GLOBALS['registered_users'] === [], 'DB failure created user');
        $GLOBALS['registration_insert_error'] = false; $GLOBALS['wpdb']->lockAvailable = false;
        check(is_wp_error($signup->register(signupBody($app), $app, 'password')) && $GLOBALS['registered_users'] === [], 'Signup lock contention ignored');
        check($GLOBALS['wpdb']->sessions === [], 'Failed signup issued JWT');
    },
];
$failures = 0;
foreach ($cases as $name => $case) {
    try { $case(); echo '[PASS] ' . $name . PHP_EOL; }
    catch (Throwable $e) { $failures++; echo '[FAIL] ' . $name . ': ' . $e->getMessage() . ' (' . $e->getLine() . ')' . PHP_EOL; }
}
exit($failures ? 1 : 0);
