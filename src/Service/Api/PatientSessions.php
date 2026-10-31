<?php

namespace App\Service\Api;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use WP_Error;

if (!defined('ABSPATH')) exit;

/** Revocable, database-backed patient sessions. JWTs never grant WordPress core access. */
final class PatientSessions
{
    public const KEY_OPTION = 'wp_cliniko_api_patient_signing_key';
    public const SCHEMA_OPTION = 'wp_cliniko_api_patient_schema';
    public const TYPE = 'cliniko-patient+jwt';

    public static function install(): void
    {
        if (!current_user_can('manage_options') || get_option(self::SCHEMA_OPTION) === '1') return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$wpdb->prefix}cliniko_api_sessions (
            id varchar(48) NOT NULL,
            client_id varchar(24) NOT NULL,
            user_id bigint unsigned NOT NULL,
            method varchar(16) NOT NULL,
            issuer varchar(512) NOT NULL,
            subject varchar(255) NOT NULL,
            password_fingerprint varchar(64) NOT NULL,
            refresh_hash varchar(64) NOT NULL,
            created_at bigint unsigned NOT NULL,
            expires_at bigint unsigned NOT NULL,
            revoked tinyint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $collate;");
        dbDelta("CREATE TABLE {$wpdb->prefix}cliniko_api_spent_refresh (
            token_hash varchar(64) NOT NULL,
            session_id varchar(48) NOT NULL,
            expires_at bigint unsigned NOT NULL,
            PRIMARY KEY  (token_hash),
            KEY expires_at (expires_at)
        ) $collate;");
        foreach (['cliniko_api_sessions', 'cliniko_api_spent_refresh'] as $table) if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . $table)))) return;
        update_option(self::SCHEMA_OPTION, '1', false);
    }

    public static function ensureKey()
    {
        if (strlen(self::key()) >= 64) return true;
        $encrypted = \wp_cliniko_secret_option_encrypt(bin2hex(random_bytes(32)));
        if (!\wp_cliniko_secret_option_is_encrypted($encrypted)) return new WP_Error('signing_key_unavailable', 'Secret encryption must be available before enabling app login.');
        if (!add_option(self::KEY_OPTION, $encrypted, '', false)) return new WP_Error('signing_key_unavailable', 'The signing key already exists but could not be used. Restore the website encryption configuration, or retry if another administrator just initialized it.');
        return true;
    }

    private static function key(): string { return \wp_cliniko_get_secret_option(self::KEY_OPTION); }
    public static function ready(): bool { return get_option(self::SCHEMA_OPTION) === '1' && strlen(self::key()) >= 64; }
    public static function issuer(): string { return rtrim(home_url(), '/') . '/cliniko-patient-auth'; }
    public static function fingerprint($user): string { return hash_hmac('sha256', (string) $user->user_pass, wp_salt('logged_in')); }

    public static function isLocal(string $token): bool
    {
        $header = json_decode((string) base64_decode(strtr(explode('.', $token, 2)[0], '-_', '+/'), true), true);
        // Untrusted header is used only to select the verifier, never to authorize access.
        return is_array($header) && ($header['typ'] ?? '') === self::TYPE;
    }

    public static function issue(array $client, int $userId, string $method, string $issuer = '', string $subject = '')
    {
        if (!self::ready()) return self::unavailable();
        $user = get_userdata($userId);
        if (!$user) return self::invalid();
        $id = bin2hex(random_bytes(24));
        $refresh = $id . '.' . bin2hex(random_bytes(32));
        $settings = ApiSettings::get();
        $session = ['id' => $id, 'client_id' => $client['id'], 'user_id' => $userId, 'method' => $method, 'issuer' => $issuer, 'subject' => $subject, 'password_fingerprint' => self::fingerprint($user), 'refresh_hash' => self::hash($refresh), 'created_at' => time(), 'expires_at' => min(time() + $settings['refresh_days'] * DAY_IN_SECONDS, $client['expires_at']), 'revoked' => 0];
        global $wpdb;
        if ($wpdb->insert($wpdb->prefix . 'cliniko_api_sessions', $session) !== 1) return self::unavailable();
        ApiAudit::record('patient_session_issued', 'authentication', $client['id'], $userId, 200);
        return self::tokens($session, $client, $refresh);
    }

    private static function tokens(array $session, array $client, string $refresh): array
    {
        $now = time();
        $expiry = min($now + ApiSettings::get()['access_minutes'] * 60, (int) $session['expires_at'], (int) $client['expires_at']);
        $claims = ['iss' => self::issuer(), 'aud' => 'cliniko-patient-api', 'sub' => (string) $session['user_id'], 'sid' => $session['id'], 'client_id' => $client['id'], 'scope' => implode(' ', $client['scopes']), 'iat' => $now, 'nbf' => $now, 'exp' => $expiry, 'jti' => bin2hex(random_bytes(16))];
        $token = JWT::encode($claims, self::key(), 'HS256', 'cliniko-local-v1', ['typ' => self::TYPE]);
        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => $expiry - $now, 'refresh_token' => $refresh, 'refresh_expires_in' => max(0, (int) $session['expires_at'] - $now), 'scope' => $claims['scope']];
    }

    public static function identity(string $token)
    {
        if (!self::ready()) return self::unavailable();
        try {
            $headers = new \stdClass();
            $claims = (array) JWT::decode($token, new Key(self::key(), 'HS256'), $headers);
            if (($headers->typ ?? '') !== self::TYPE || ($headers->kid ?? '') !== 'cliniko-local-v1' || ($claims['iss'] ?? '') !== self::issuer() || ($claims['aud'] ?? '') !== 'cliniko-patient-api' || !is_int($claims['exp'] ?? null) || $claims['exp'] <= time() || !is_int($claims['iat'] ?? null) || $claims['iat'] > time() || !is_int($claims['nbf'] ?? null) || $claims['nbf'] > time() || !is_string($claims['scope'] ?? null)) return self::invalid();
            $session = self::find((string) ($claims['sid'] ?? ''));
            if (!$session || (string) $session['user_id'] !== ($claims['sub'] ?? '') || $session['client_id'] !== ($claims['client_id'] ?? '')) return self::invalid();
            $client = self::validateSession($session);
            if (is_wp_error($client)) return $client;
            return ['client' => $client, 'user_id' => (int) $session['user_id'], 'scopes' => array_values(array_intersect($client['scopes'], preg_split('/\s+/', $claims['scope']) ?: []))];
        } catch (\Throwable $error) { return self::invalid(); }
    }

    public static function find(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $id)) return null;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}cliniko_api_sessions WHERE id = %s", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function validateSession(array $session)
    {
        if (!empty($session['revoked']) || (int) $session['expires_at'] <= time()) return self::invalid();
        $client = ApiClients::patient($session['client_id']);
        $user = get_userdata((int) $session['user_id']);
        $settings = ApiSettings::get();
        $flag = ['password' => 'password_login_enabled', 'google' => 'google_login_enabled', 'apple' => 'apple_login_enabled'][$session['method']] ?? '';
        if (!$client || !$user || $flag === '' || empty($settings[$flag]) || user_can($user, 'manage_options') || (string) get_user_meta($user->ID, \App\Service\PatientService::META_ACCOUNT_STATUS, true) !== \App\Service\PatientService::ACCOUNT_ACTIVE || !hash_equals($session['password_fingerprint'], self::fingerprint($user))) return self::invalid();
        if ($session['method'] !== 'password' && ApiClients::userId($session['issuer'], $session['subject']) !== (int) $user->ID) return self::invalid();
        if ($session['method'] === 'password' && PatientAuthentication::passwordBlocked($user)) return self::invalid();
        return $client;
    }

    public static function refresh(string $refresh, string $clientId, bool $logout = false)
    {
        if (!self::ready()) return self::unavailable();
        if (!preg_match('/^([a-f0-9]{48})\.[a-f0-9]{64}$/D', $refresh, $matches)) return self::invalid();
        return (new OperationLock())->run('patient-session:' . $matches[1], static function () use ($matches, $refresh, $clientId, $logout) {
            $session = self::find($matches[1]);
            if (!$session || $session['client_id'] !== $clientId) return self::invalid();
            global $wpdb;
            $hash = self::hash($refresh);
            if (!hash_equals($session['refresh_hash'], $hash)) {
                $spent = $wpdb->get_var($wpdb->prepare("SELECT session_id FROM {$wpdb->prefix}cliniko_api_spent_refresh WHERE token_hash = %s", $hash));
                if ($spent === $session['id']) self::revoke($session['id']);
                return self::invalid();
            }
            if ($logout) { return self::revoke($session['id']) ? ['logged_out' => true] : self::unavailable(); }
            $client = self::validateSession($session);
            if (is_wp_error($client)) return $client;
            $allowed = PatientAuthentication::patientAllowed((int) $session['user_id']);
            if (is_wp_error($allowed)) return $allowed;
            $next = $session['id'] . '.' . bin2hex(random_bytes(32));
            if ($wpdb->insert($wpdb->prefix . 'cliniko_api_spent_refresh', ['token_hash' => $hash, 'session_id' => $session['id'], 'expires_at' => $session['expires_at']]) !== 1) return self::unavailable();
            if ($wpdb->update($wpdb->prefix . 'cliniko_api_sessions', ['refresh_hash' => self::hash($next)], ['id' => $session['id'], 'refresh_hash' => $hash, 'revoked' => 0]) !== 1) { self::revoke($session['id']); return self::unavailable(); }
            return self::tokens($session, $client, $next);
        });
    }

    public static function revoke(string $id): bool
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $id)) return false;
        global $wpdb;
        $changed = $wpdb->update($wpdb->prefix . 'cliniko_api_sessions', ['revoked' => 1], ['id' => $id]);
        if ($changed === false) return false;
        if ($changed === 1) return true;
        $session = self::find($id);
        return $session !== null && !empty($session['revoked']);
    }

    public static function recent(): array
    {
        if (get_option(self::SCHEMA_OPTION) !== '1') return [];
        global $wpdb;
        // Never expose refresh hashes, password fingerprints or signing keys to the dashboard.
        return (array) $wpdb->get_results("SELECT id, client_id, user_id, method, created_at, expires_at, revoked FROM {$wpdb->prefix}cliniko_api_sessions ORDER BY created_at DESC LIMIT 100", ARRAY_A);
    }

    public static function cleanup(): void
    {
        if (get_option(self::SCHEMA_OPTION) !== '1') return;
        global $wpdb;
        foreach (['cliniko_api_sessions', 'cliniko_api_spent_refresh'] as $table) $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}{$table} WHERE expires_at < %d", time()));
    }

    private static function hash(string $token): string { return hash_hmac('sha256', $token, wp_salt('auth')); }
    public static function invalid(): WP_Error { return new WP_Error('invalid_token', 'The login session is invalid, expired or no longer approved. Please sign in again.', ['status' => 401]); }
    private static function unavailable(): WP_Error { return new WP_Error('patient_auth_unavailable', 'Patient app sessions are not ready. Ask the website administrator to check Authentication settings.', ['status' => 503]); }
}
