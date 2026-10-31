<?php

namespace App\Service\Api;

if (!defined('ABSPATH')) exit;

final class ApiSettings
{
    public const OPTION = 'wp_cliniko_api_access';
    public const SECRET = 'wp_cliniko_api_introspection_secret';
    public const VERSION = '2.0.0';
    private const LOGIN_FIELDS = ['oauth_enabled', 'oauth_provider', 'issuer', 'audience', 'introspection_url', 'introspection_client'];

    public static function defaults(): array
    {
        return [
            'enabled' => false, 'slug' => 'cliniko-api', 'aliases' => [],
            'groups' => [], 'appointment_types' => [], 'form_templates' => [],
            'read_limit' => 180, 'write_limit' => 20, 'audit_days' => 14,
            'origins' => [], 'oauth_enabled' => false, 'oauth_provider' => 'custom', 'issuer' => '', 'audience' => '',
            'introspection_url' => '', 'introspection_client' => '',
            'password_login_enabled' => false, 'google_login_enabled' => false, 'apple_login_enabled' => false,
            'password_login_reviewed' => false, 'access_minutes' => 15, 'refresh_days' => 7,
            'registration_password_enabled' => false, 'registration_social_enabled' => false,
            'registration_oauth_enabled' => false, 'registration_reviewed' => false, 'registration_role' => '',
        ];
    }

    public static function get(): array
    {
        $stored = get_option(self::OPTION, []);
        return array_replace(self::defaults(), is_array($stored) ? $stored : []);
    }

    public static function namespace(): string { return self::get()['slug'] . '/v1'; }

    public static function browserOriginAllowed(string $origin): bool
    {
        return in_array($origin, self::get()['origins'], true) && self::validBrowserOrigin($origin);
    }

    private static function validBrowserOrigin(string $origin): bool
    {
        $schemes = in_array(wp_get_environment_type(), ['local', 'development'], true) ? ['https', 'http'] : ['https'];
        $parts = wp_parse_url($origin);
        return is_array($parts) && in_array($parts['scheme'] ?? '', $schemes, true)
            && !empty($parts['host']) && !str_contains($parts['host'], '*')
            && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['path']) && !isset($parts['query']) && !isset($parts['fragment']);
    }

    public static function namespaces(): array
    {
        $settings = self::get();
        $names = [self::namespace()];
        foreach ($settings['aliases'] as $slug => $expires) {
            if ((int) $expires > time()) $names[] = $slug . '/v1';
        }
        return array_unique($names);
    }

    public static function ownsRoute(string $route): bool
    {
        foreach (self::namespaces() as $namespace) {
            if ($route === '/' . $namespace || str_starts_with($route, '/' . $namespace . '/')) return true;
        }
        return false;
    }

    public static function groupEnabled(string $group): bool
    {
        $settings = self::get();
        return (bool) $settings['enabled'] && ($group === 'system' || in_array($group, $settings['groups'], true));
    }

    /** Validate a whole settings submission before persisting any part of it. */
    public static function validate(array $input, array $occupiedNamespaces = [])
    {
        $general = self::validateGeneral($input, $occupiedNamespaces);
        if (is_wp_error($general)) return $general;
        $login = self::validatePatientLogin($input);
        if (is_wp_error($login)) return $login;
        return array_replace($general, array_intersect_key($login, array_flip(self::LOGIN_FIELDS)));
    }

    /** General settings own routes/publication only; ignore posted login values. */
    public static function validateGeneral(array $input, array $occupiedNamespaces = [])
    {
        $old = self::get();
        $slug = trim(strtolower((string) ($input['slug'] ?? '')), '/ ');
        if (!preg_match('/^[a-z][a-z0-9-]{1,47}$/D', $slug) || in_array($slug, ['wp', 'wp-json', 'oembed', 'v1', 'v2'], true)) {
            return new \WP_Error('invalid_namespace', 'Choose a 2–48 character slug using lowercase letters, numbers, and hyphens.');
        }
        if (in_array($slug . '/v1', $occupiedNamespaces, true) && !in_array($slug . '/v1', self::namespaces(), true)) {
            return new \WP_Error('namespace_collision', 'Another REST API already uses this namespace.');
        }
        if ($slug !== $old['slug'] && $old['enabled'] && empty($input['confirm_namespace_change'])) {
            return new \WP_Error('confirm_namespace_change', 'Confirm the namespace change; installed applications must update their base URL.');
        }
        $settings = $old;
        $settings['slug'] = $slug;
        $settings['enabled'] = !empty($input['enabled']);
        $settings['aliases'] = array_filter($old['aliases'], static fn($expiry): bool => (int) $expiry > time());
        unset($settings['aliases'][$slug]);
        if ($slug !== $old['slug'] && $old['enabled']) $settings['aliases'][$old['slug']] = time() + 30 * DAY_IN_SECONDS;
        $settings['groups'] = array_values(array_intersect(array_keys(EndpointCatalog::groups()), (array) ($input['groups'] ?? [])));
        foreach (['appointment_types', 'form_templates'] as $key) {
            $value = $input[$key] ?? '';
            // Checkbox lists and older comma-separated settings submissions use the same storage.
            $raw = is_array($value) ? $value : (preg_split('/[\s,]+/', trim((string) $value)) ?: []);
            $ids = [];
            foreach ($raw as $id) {
                if (!is_string($id) && !is_int($id)) return new \WP_Error('invalid_ids', 'Select valid Cliniko services or enter numeric Cliniko IDs.');
                $id = trim((string) $id);
                if ($id !== '' && !ctype_digit($id)) return new \WP_Error('invalid_ids', 'Published Cliniko IDs must be numeric.');
                if ($id !== '') $ids[] = $id;
            }
            $settings[$key] = array_values(array_unique($ids));
        }
        foreach (['read_limit' => [1, 1000], 'write_limit' => [1, 100], 'audit_days' => [1, 90]] as $key => $bounds) {
            $settings[$key] = max($bounds[0], min($bounds[1], (int) ($input[$key] ?? $old[$key])));
        }
        $settings['origins'] = [];
        foreach (preg_split('/\r?\n/', trim((string) ($input['origins'] ?? ''))) ?: [] as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin === '') continue;
            if (!self::validBrowserOrigin($origin)) {
                return new \WP_Error('invalid_origin', 'Browser origins must be exact HTTPS origins (HTTP is allowed only in local or development mode), without paths, credentials or wildcards.');
            }
            $settings['origins'][] = $origin;
        }
        return $settings;
    }

    /** Login settings own provider/credentials only; ignore posted API exposure values. */
    public static function validatePatientLogin(array $input)
    {
        $old = self::get();
        $settings = $old;
        $settings['oauth_enabled'] = !empty($input['oauth_enabled']);
        $provider = $input['oauth_provider'] ?? $old['oauth_provider'];
        if (!is_string($provider) || !isset(SignInProviders::catalog()[$provider])) return new \WP_Error('invalid_oauth_provider', 'Choose one of the sign-in services listed in the setup guide.');
        $settings['oauth_provider'] = $provider;
        if ($settings['oauth_enabled'] && !SignInProviders::guide($provider)['can_enable']) return new \WP_Error('oauth_provider_not_supported', SignInProviders::guide($provider)['name'] . ' needs a validation method this plugin does not implement. Leave patient sign-in off, or use a separately implemented and verified compatible adapter under Other compatible service / custom adapter.');
        foreach (['issuer', 'introspection_url'] as $key) {
            $url = trim((string) ($input[$key] ?? ''));
            if ($url !== '' && (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https' || !wp_http_validate_url($url))) {
                return new \WP_Error('invalid_oauth_url', ($key === 'issuer' ? 'Sign-in service address' : 'Address for checking patient logins') . ' must be a full, public HTTPS address. Copy the value supplied by your sign-in developer.');
            }
            $settings[$key] = $url;
        }
        foreach (['audience', 'introspection_client'] as $key) $settings[$key] = sanitize_text_field((string) ($input[$key] ?? ''));
        if ($settings['oauth_enabled']) {
            $missing = [];
            foreach (['issuer' => 'Sign-in service address', 'audience' => 'API name at the sign-in service', 'introspection_url' => 'Address for checking patient logins', 'introspection_client' => 'Website connection ID'] as $key => $label) if ($settings[$key] === '') $missing[] = $label;
            if ($missing !== []) return new \WP_Error('oauth_incomplete', 'Before enabling patient sign-in, enter: ' . implode(', ', $missing) . '. Or leave patient sign-in off to save your progress.');
            if (empty($input['introspection_secret']) && \wp_cliniko_get_secret_option(self::SECRET) === '') {
                return new \WP_Error('oauth_secret_missing', 'Enter the website connection password supplied by your sign-in developer before enabling patient sign-in.');
            }
        }
        return $settings;
    }

    /** Native/social login owns its own fields, never publication or OAuth credentials. */
    public static function validateAppLogin(array $input)
    {
        $settings = self::get();
        $section = (string) ($input['section'] ?? 'wordpress');
        if (!in_array($section, ['wordpress', 'social', 'sessions', 'registration'], true)) return new \WP_Error('invalid_auth_section', 'Choose a valid authentication settings section.');
        if ($section === 'registration') {
            foreach (['registration_password_enabled', 'registration_social_enabled', 'registration_oauth_enabled', 'registration_reviewed'] as $key) $settings[$key] = !empty($input[$key]);
            $settings['registration_role'] = sanitize_key((string) ($input['registration_role'] ?? ''));
            if ($settings['registration_role'] !== '' && !isset(PatientRegistration::eligibleRoles()[$settings['registration_role']])) return new \WP_Error('invalid_registration_role', 'Choose a configured patient role with no elevated WordPress permissions.');
            if ($settings['registration_password_enabled'] || $settings['registration_social_enabled'] || $settings['registration_oauth_enabled']) {
                if (!$settings['registration_reviewed']) return new \WP_Error('registration_review_required', 'Confirm that your developer reviewed signup, consent, spam protection and approval requirements.');
                if ($settings['registration_role'] === '' || !get_option('wp_cliniko_patient_sync_enabled', false)) return new \WP_Error('registration_sync_required', 'Enable patient sync and select a safe patient role before opening app registration.');
                if ($settings['registration_social_enabled'] && !$settings['google_login_enabled'] && !$settings['apple_login_enabled']) return new \WP_Error('registration_social_required', 'Enable and configure Google and/or Apple sign-in before allowing social registration.');
                if ($settings['registration_oauth_enabled'] && !$settings['oauth_enabled']) return new \WP_Error('registration_oauth_required', 'Configure and enable external OAuth before allowing its registration flow.');
            }
        }
        $fields = $section === 'wordpress' ? ['password_login_enabled', 'password_login_reviewed'] : ($section === 'social' ? ['google_login_enabled', 'apple_login_enabled'] : []);
        foreach ($fields as $key) $settings[$key] = !empty($input[$key]);
        if ($settings['password_login_enabled'] && !$settings['password_login_reviewed']) return new \WP_Error('login_review_required', 'Confirm that your developer has checked the website login and two-factor requirements before enabling password login in apps.');
        foreach ($section === 'sessions' ? ['access_minutes' => [5, 30], 'refresh_days' => [1, 30]] : [] as $key => $bounds) {
            $value = filter_var($input[$key] ?? $settings[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < $bounds[0] || $value > $bounds[1]) return new \WP_Error('invalid_session_lifetime', 'Choose an access-token lifetime of 5–30 minutes and a maximum session lifetime of 1–30 days.');
            $settings[$key] = $value;
        }
        return $settings;
    }
}
