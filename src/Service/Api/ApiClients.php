<?php

namespace App\Service\Api;

if (!defined('ABSPATH')) exit;

final class ApiClients
{
    public const OPTION = 'wp_cliniko_api_clients';
    public const MAPPINGS = 'wp_cliniko_api_subjects';

    public static function all(): array
    {
        $clients = get_option(self::OPTION, []);
        return is_array($clients) ? $clients : [];
    }

    public static function create(string $name, string $type, string $externalId, array $scopes, int $expires, array $social = [])
    {
        $name = sanitize_text_field($name);
        if ($name === '' || !in_array($type, ['server', 'oauth', 'patient'], true) || $expires <= time()) {
            return new \WP_Error('invalid_client', 'Provide a name, client type, and future expiry.');
        }
        if ($type === 'oauth' && ($externalId === '' || preg_match('/[\r\n]/', $externalId))) return new \WP_Error('invalid_client_id', 'Provide the exact client ID registered at the OAuth provider.');
        $clients = self::all();
        foreach ($clients as $client) {
            if ($type === 'oauth' && $client['type'] === 'oauth' && $client['external_id'] === $externalId) return new \WP_Error('duplicate_client', 'This provider client ID is already registered.');
        }
        $scopes = array_values(array_intersect(EndpointCatalog::scopes(), $scopes));
        $social = self::socialSettings($social);
        if (is_wp_error($social)) return $social;
        if ($type === 'server') $scopes = array_values(array_intersect($scopes, ['catalog:read', 'availability:read']));
        $id = bin2hex(random_bytes(12));
        $secret = $type === 'server' ? 'capi_' . $id . '.' . bin2hex(random_bytes(32)) : '';
        $clients[$id] = [
            'id' => $id, 'name' => $name, 'type' => $type, 'external_id' => $externalId,
            'scopes' => $scopes, 'enabled' => true, 'expires_at' => $expires,
            'created_at' => time(), 'last_used_at' => 0, 'key_hash' => $secret === '' ? '' : self::hash($secret),
            'google_client_id' => $type === 'patient' ? $social['google_client_id'] : '',
            'google_presenter_id' => $type === 'patient' ? $social['google_presenter_id'] : '',
            'apple_client_id' => $type === 'patient' ? $social['apple_client_id'] : '',
        ];
        update_option(self::OPTION, $clients, false);
        return ['client' => $clients[$id], 'secret' => $secret];
    }

    public static function revoke(string $id): bool
    {
        $clients = self::all();
        if (!isset($clients[$id])) return false;
        $clients[$id]['enabled'] = false;
        update_option(self::OPTION, $clients, false);
        return true;
    }

    public static function update(string $id, array $scopes, int $expires, ?array $social = null)
    {
        $clients = self::all();
        if (!isset($clients[$id]) || $expires <= time()) return new \WP_Error('invalid_client', 'Choose an existing application and a future expiry.');
        if ($social !== null && $clients[$id]['type'] === 'patient') {
            $social = self::socialSettings($social);
            if (is_wp_error($social)) return $social;
            $clients[$id] = array_replace($clients[$id], $social);
        }
        $scopes = array_values(array_intersect(EndpointCatalog::scopes(), $scopes));
        if ($clients[$id]['type'] === 'server') $scopes = array_values(array_intersect($scopes, ['catalog:read', 'availability:read']));
        $clients[$id]['scopes'] = $scopes;
        $clients[$id]['expires_at'] = $expires;
        update_option(self::OPTION, $clients, false);
        return true;
    }

    public static function rotate(string $id)
    {
        $clients = self::all();
        if (!isset($clients[$id]) || $clients[$id]['type'] !== 'server' || !self::active($clients[$id])) return new \WP_Error('invalid_client', 'Only an active server client can rotate its credential.');
        $secret = 'capi_' . $id . '.' . bin2hex(random_bytes(32));
        $clients[$id]['key_hash'] = self::hash($secret);
        update_option(self::OPTION, $clients, false);
        return $secret;
    }

    public static function server(string $token): ?array
    {
        if (!preg_match('/^capi_([a-f0-9]{24})\.([a-f0-9]{64})$/D', $token, $matches)) return null;
        $client = self::all()[$matches[1]] ?? null;
        return $client && $client['type'] === 'server' && self::active($client) && hash_equals($client['key_hash'], self::hash($token)) ? $client : null;
    }

    public static function oauth(string $externalId): ?array
    {
        foreach (self::all() as $client) {
            if ($client['type'] === 'oauth' && self::active($client) && hash_equals($client['external_id'], $externalId)) return $client;
        }
        return null;
    }

    public static function patient(string $id): ?array
    {
        $client = self::all()[$id] ?? null;
        return $client && $client['type'] === 'patient' && self::active($client) ? $client : null;
    }

    private static function socialSettings(array $input)
    {
        $result = [];
        foreach (['google_client_id', 'google_presenter_id', 'apple_client_id'] as $key) {
            $value = trim((string) ($input[$key] ?? ''));
            if (strlen($value) > 255 || ($value !== '' && !preg_match('/^[a-zA-Z0-9._-]+$/D', $value))) return new \WP_Error('invalid_social_client', 'Copy the exact Google or Apple application ID; do not enter a password, URL or multiple IDs.');
            if (str_starts_with($key, 'google_') && $value !== '' && !str_ends_with($value, '.apps.googleusercontent.com')) return new \WP_Error('invalid_google_client', 'Google client IDs end in .apps.googleusercontent.com. Use the audience/presenter supplied by your app developer.');
            $result[$key] = $value;
        }
        return $result;
    }

    public static function active(array $client): bool { return !empty($client['enabled']) && (int) $client['expires_at'] > time(); }

    public static function touch(string $id): void
    {
        $clients = self::all();
        if (isset($clients[$id]) && (int) $clients[$id]['last_used_at'] < time() - 60) {
            $clients[$id]['last_used_at'] = time();
            update_option(self::OPTION, $clients, false);
        }
    }

    public static function mappings(): array
    {
        $value = get_option(self::MAPPINGS, []);
        return is_array($value) ? $value : [];
    }

    public static function map(string $issuer, string $subject, int $userId, bool $newOnly = false)
    {
        return (new OperationLock())->run('patient-mapping-store', static function () use ($issuer, $subject, $userId, $newOnly) {
            // A request may have looked up a subject before waiting for this lock.
            // Clear request-local option caches so another signup's mappings are not lost.
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete(self::MAPPINGS, 'options');
                wp_cache_delete('notoptions', 'options');
            }
            $mappings = self::mappings();
            $key = self::subjectKey($issuer, $subject);
            if ($newOnly && isset($mappings[$key]) && (int) $mappings[$key]['user_id'] !== $userId) return new \WP_Error('identity_already_linked', 'This sign-in identity is already linked.', ['status' => 409]);
            if ($userId === 0) unset($mappings[$key]);
            else $mappings[$key] = ['issuer' => $issuer, 'subject' => $subject, 'user_id' => $userId];
            if ($mappings === self::mappings()) return true;
            return update_option(self::MAPPINGS, $mappings, false) ? true : new \WP_Error('mapping_unavailable', 'The patient identity link could not be saved.', ['status' => 503]);
        });
    }

    public static function userId(string $issuer, string $subject): int { return (int) (self::mappings()[self::subjectKey($issuer, $subject)]['user_id'] ?? 0); }
    private static function subjectKey(string $issuer, string $subject): string { return hash('sha256', $issuer . "\0" . $subject); }
    private static function hash(string $token): string { return hash_hmac('sha256', $token, wp_salt('auth')); }
}
