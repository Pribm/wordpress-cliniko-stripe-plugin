<?php

namespace App\Admin\Modules\ApiAccess;

use App\Authorization\Api\AccessPolicy;
use App\Service\Api\ApiClients;
use App\Service\Api\ApiSettings;
use App\Service\Api\SignInProviders;

if (!defined('ABSPATH')) exit;

/** Admin setup progress, not an authentication or patient-verification policy. */
final class PatientLoginSetup
{
    public const CHECK_OPTION = 'wp_cliniko_api_login_connection_check';

    public static function fields(): array
    {
        return [
            'issuer' => ['label' => 'Sign-in service address', 'technical' => 'Issuer', 'type' => 'url', 'help' => 'Copy the exact issuer address from your sign-in developer. This tells your website which service to trust.'],
            'audience' => ['label' => 'API name at the sign-in service', 'technical' => 'Audience', 'type' => 'text', 'help' => 'Copy the API audience assigned by your sign-in developer. Do not invent an app name or use the API base slug unless they confirm it.'],
            'introspection_url' => ['label' => 'Address for checking patient logins', 'technical' => 'Introspection endpoint', 'type' => 'url', 'help' => 'Copy the secure token-checking address from your developer. It is not the address of the login page.'],
            'introspection_client' => ['label' => 'Website connection ID', 'technical' => 'Introspection client ID', 'type' => 'text', 'help' => 'Use the ID assigned to this WordPress website for checking logins, not the separate mobile app ID.'],
            'introspection_secret' => ['label' => 'Website connection password', 'technical' => 'Introspection client secret', 'type' => 'password', 'help' => 'Paste the private password supplied for the website connection. Leave blank to keep the saved password. Never put it in the phone app.'],
        ];
    }

    public static function requestText(?string $provider = null): string
    {
        $guide = SignInProviders::guide($provider ?? ApiSettings::get()['oauth_provider']);
        if (!$guide['can_enable']) {
            $collect = $guide['collect'] ?? ['Review the service\'s official token-validation requirements.', 'Plan the missing integration or a security-reviewed compatible introspection adapter.', 'Keep application permissions and verified patient linking; do not link accounts automatically by email.'];
            return "Please plan patient app sign-in for our Cliniko WordPress API.\n"
                . 'Our API address: ' . rest_url(ApiSettings::namespace()) . "\n"
                . 'Selected sign-in service: ' . $guide['name'] . "\n"
                . 'Compatibility note: ' . $guide['notice'] . "\n\n"
                . "Information to collect / confirm:\n- " . implode("\n- ", $collect) . "\n\n"
                . "This is an integration-planning request, not a completed connection. Please implement and test the required validation before enabling patient access. Do not invent current OAuth connection values or send private keys, passwords or patient login tokens in ordinary email.";
        }
        return "Please prepare patient app sign-in for our Cliniko WordPress API.\n"
            . 'Selected sign-in service: ' . $guide['name'] . "\n"
            . 'Compatibility note: ' . $guide['notice'] . "\n"
            . 'Our API address: ' . rest_url(ApiSettings::namespace()) . "\n\n"
            . "Please supply:\n- Sign-in service address (exact issuer)\n- API name at that service (audience)\n- Address for checking logins (introspection endpoint)\n- Website connection ID (introspection client ID)\n- Website connection password, delivered separately through a secure channel\n- The patient app's separate registered client ID\n- A test patient's subject ID to link to their existing WordPress account\n\n"
            . "Please confirm the service supports this plugin's token-checking requirements and build/test the patient app login. The technical reference is in API Access > Tutorials > Patient mobile login with OAuth. Do not send private passwords or patient login tokens in an ordinary email.";
    }

    private static function fingerprint(): string
    {
        $settings = ApiSettings::get();
        $values = ['oauth_enabled' => (bool) $settings['oauth_enabled'], 'oauth_provider' => $settings['oauth_provider']];
        foreach (array_keys(self::fields()) as $key) $values[$key] = $key === 'introspection_secret' ? \wp_cliniko_get_secret_option(ApiSettings::SECRET) : $settings[$key];
        return hash_hmac('sha256', (string) wp_json_encode($values), wp_salt('auth'));
    }

    public static function checkSavedConnection()
    {
        // Capture before the network call so a concurrent settings change invalidates the result.
        $fingerprint = self::fingerprint();
        $result = AccessPolicy::testProvider();
        update_option(self::CHECK_OPTION, [
            'fingerprint' => $fingerprint, 'checked_at' => time(),
            'passed' => $result === true, 'error_code' => is_wp_error($result) ? $result->get_error_code() : '',
        ], false);
        return $result;
    }

    public static function state(): array
    {
        $settings = ApiSettings::get();
        $secretSaved = \wp_cliniko_get_secret_option(ApiSettings::SECRET) !== '';
        $missing = [];
        foreach (self::fields() as $key => $field) {
            if ($key === 'introspection_secret' ? !$secretSaved : trim((string) $settings[$key]) === '') $missing[$key] = $field['label'];
        }
        $check = get_option(self::CHECK_OPTION, []);
        $current = is_array($check) && isset($check['fingerprint']) && is_string($check['fingerprint']) && hash_equals(self::fingerprint(), $check['fingerprint']);
        $apps = array_filter(ApiClients::all(), static fn(array $client): bool => $client['type'] === 'oauth' && ApiClients::active($client));
        $mappings = array_filter(ApiClients::mappings(), static fn(array $mapping): bool => $settings['issuer'] !== '' && $mapping['issuer'] === $settings['issuer']);
        return [
            'missing' => $missing, 'secret_saved' => $secretSaved, 'configured' => $missing === [],
            'check_current' => $current, 'check_passed' => $current && !empty($check['passed']),
            'checked_at' => $current ? (int) ($check['checked_at'] ?? 0) : 0,
            'error_code' => $current ? (string) ($check['error_code'] ?? '') : '',
            'apps' => count($apps), 'mappings' => count($mappings),
        ];
    }

    public static function failureMessage(string $code): string
    {
        if ($code === 'oauth_unconfigured') return 'Patient sign-in is not enabled with a saved website password. Complete the setup, enable patient sign-in and save before checking the connection.';
        if (in_array($code, ['oauth_test_failed', 'oauth_response_invalid'], true)) return 'The sign-in service did not reply in the format this plugin needs. Ask your developer to check compatibility using the technical reference in the patient-login tutorial.';
        return 'The website could not check a login with the sign-in service. Ask your developer to check the saved login-checking address, website connection ID/password and whether the service is reachable.';
    }
}
