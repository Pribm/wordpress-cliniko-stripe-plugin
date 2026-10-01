<?php

namespace App\Debug;

if (!defined('ABSPATH')) {
    exit;
}

class Settings
{
    public const OPTION_KEY = 'wp_cliniko_debug_settings';

    public const AUTH_BEHAVIOUR_SESSION_GUARD = 'session_guard';
    public const AUTH_BEHAVIOUR_EMAIL_CHANGE_SESSIONS = 'email_change_sessions';
    public const AUTH_BEHAVIOUR_PENDING_CURRENT_USER = 'pending_current_user';
    public const AUTH_BEHAVIOUR_LOGIN_AUTHENTICATION = 'login_authentication';
    public const AUTH_BEHAVIOUR_PROFILE_EMAIL_REVOKE = 'profile_email_revoke';
    public const AUTH_BEHAVIOUR_ROLE_REVOKE = 'role_revoke';
    public const AUTH_BEHAVIOUR_UM_PENDING_REVIEW = 'um_pending_review';

    /** @var array<string,string> */
    private const AUTH_BEHAVIOURS = [
        self::AUTH_BEHAVIOUR_SESSION_GUARD => 'Destroy sessions when a patient link is invalid',
        self::AUTH_BEHAVIOUR_EMAIL_CHANGE_SESSIONS => 'Destroy sessions after a patient email change',
        self::AUTH_BEHAVIOUR_PENDING_CURRENT_USER => 'Treat pending patient accounts as logged out',
        self::AUTH_BEHAVIOUR_LOGIN_AUTHENTICATION => 'Block login when patient authentication fails',
        self::AUTH_BEHAVIOUR_PROFILE_EMAIL_REVOKE => 'Revoke patient links after profile email changes',
        self::AUTH_BEHAVIOUR_ROLE_REVOKE => 'Revoke patient links after role changes',
        self::AUTH_BEHAVIOUR_UM_PENDING_REVIEW => 'Move patient accounts to Ultimate Member pending review',
    ];

    /**
     * @return array{enabled:string,retention_days:int,max_rows:int,mock_patient:string,auth_behaviours:array<string,string>}
     */
    public static function get(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        return self::sanitize(is_array($stored) ? $stored : []);
    }

    public static function isEnabled(): bool
    {
        return self::get()['enabled'] === 'yes';
    }

    public static function retentionDays(): int
    {
        return self::get()['retention_days'];
    }

    public static function maxRows(): int
    {
        return self::get()['max_rows'];
    }

    public static function mockPatientEnabled(): bool
    {
        return self::get()['mock_patient'] === 'yes';
    }

    public static function authBehaviourEnabled(string $behaviour): bool
    {
        $settings = self::get();
        return ($settings['auth_behaviours'][$behaviour] ?? 'yes') === 'yes';
    }

    /** @return array<string,string> */
    public static function authBehaviours(): array
    {
        return self::AUTH_BEHAVIOURS;
    }

    /**
     * @param mixed $value
     * @return array{enabled:string,retention_days:int,max_rows:int,mock_patient:string,auth_behaviours:array<string,string>}
     */
    public static function sanitize($value): array
    {
        $input = is_array($value) ? $value : [];

        $enabled = !empty($input['enabled']) && $input['enabled'] !== 'no' ? 'yes' : 'no';
        $retentionDays = max(1, min(90, (int) ($input['retention_days'] ?? 7)));
        $maxRows = max(100, min(20000, (int) ($input['max_rows'] ?? 5000)));
        $mockPatient = !empty($input['mock_patient']) && $input['mock_patient'] !== 'no' ? 'yes' : 'no';
        $authInput = is_array($input['auth_behaviours'] ?? null) ? $input['auth_behaviours'] : [];
        $authBehaviours = [];
        foreach (self::AUTH_BEHAVIOURS as $key => $label) {
            $authBehaviours[$key] = array_key_exists($key, $authInput) && $authInput[$key] === 'no' ? 'no' : 'yes';
        }

        return [
            'enabled' => $enabled,
            'retention_days' => $retentionDays,
            'max_rows' => $maxRows,
            'mock_patient' => $mockPatient,
            'auth_behaviours' => $authBehaviours,
        ];
    }
}
