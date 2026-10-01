<?php

namespace App\Support;

use App\Debug\Settings as DebugSettings;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientSessionGuard
{
    public const OPTION_DESTROY_ON_FAILURE = 'wp_cliniko_patient_destroy_session_on_failure';

    public static function maybeDestroy(): void
    {
        if (!DebugSettings::authBehaviourEnabled(DebugSettings::AUTH_BEHAVIOUR_SESSION_GUARD)) {
            return;
        }

        if (!(bool) get_option(self::OPTION_DESTROY_ON_FAILURE, false)) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        $userId = (int) get_current_user_id();
        if ($userId <= 0) {
            return;
        }

        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        if (
            current_user_can('manage_options')
            || ($user instanceof \WP_User && in_array('administrator', (array) $user->roles, true))
        ) {
            return;
        }

        if (class_exists('WP_Session_Tokens')) {
            \WP_Session_Tokens::get_instance($userId)->destroy_all();
        }

        if (function_exists('wp_clear_auth_cookie')) {
            wp_clear_auth_cookie();
        }
    }
}
