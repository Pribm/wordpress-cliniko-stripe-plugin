<?php

namespace App\Service\Api;

use App\Service\PatientAccountClosureService;
use App\Service\PatientLinkService;
use App\Service\PatientService;
use WP_Error;

if (!defined('ABSPATH')) exit;

/** API account controls built on the same patient-account and closure rules as the portal. */
final class PatientAccountSecurity
{
    /**
     * Start the WordPress password-recovery flow without revealing whether an
     * email belongs to a patient account. WordPress generates and emails its
     * one-time reset key through the site's existing mail/template hooks.
     */
    public static function requestPasswordReset(string $email): array
    {
        $email = strtolower(trim($email));
        $generic = ['status' => 'requested', 'message' => 'If an eligible patient account exists, a password reset email has been sent.'];
        if ($email === '' || !is_email($email)) return $generic;
        if (!(new ApiRateLimiter())->consume('password-reset:' . $email, 'password-reset', 3)) return $generic;

        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User || !self::eligiblePatient($user)) return $generic;
        // Core handles the one-time user_activation_key, email content and
        // retrieve_password_* filters. Errors intentionally remain generic.
        if (function_exists('retrieve_password')) retrieve_password($email);
        return $generic;
    }

    /** Consume the one-time WordPress reset key for a patient API password. */
    public static function resetPassword(string $login, string $key, string $newPassword)
    {
        $login = trim($login);
        if ($login === '' || $key === '' || strlen($login) > 254 || strlen($key) > 512) {
            return new WP_Error('password_reset_invalid', 'This password-reset link is invalid or has expired.', ['status' => 400]);
        }
        if (!(new ApiRateLimiter())->consume('password-reset-complete:' . strtolower($login), 'password-reset-complete', 5)) {
            return new WP_Error('rate_limited', 'Too many password-reset attempts. Please try again later.', ['status' => 429]);
        }
        if (!function_exists('check_password_reset_key')) {
            return new WP_Error('password_reset_unavailable', 'Password reset is temporarily unavailable.', ['status' => 503]);
        }
        $user = check_password_reset_key($key, $login);
        if (is_wp_error($user) || !self::eligiblePatient($user)) {
            return new WP_Error('password_reset_invalid', 'This password-reset link is invalid or has expired.', ['status' => 403]);
        }
        if (str_contains($newPassword, "\0") || !preg_match('/^.{12,128}$/usD', $newPassword)) {
            return new WP_Error('new_password_invalid', 'Choose a new password with 12–128 characters.', ['status' => 400]);
        }
        if (!function_exists('reset_password')) {
            return new WP_Error('password_reset_unavailable', 'Password reset is temporarily unavailable.', ['status' => 503]);
        }
        reset_password($user, $newPassword);
        if (class_exists('\\WP_Session_Tokens')) \WP_Session_Tokens::get_instance((int) $user->ID)->destroy_all();
        do_action('wp_cliniko_patient_api_password_changed', (int) $user->ID);
        return ['password_updated' => true, 'sessions_revoked' => true, 'message' => 'Password reset. Sign in with your new password.'];
    }

    public static function updatePassword(int $userId, string $currentPassword, string $newPassword)
    {
        $user = get_userdata($userId);
        if (!$user instanceof \WP_User || user_can($user, 'manage_options') || (string) get_user_meta($userId, PatientService::META_ACCOUNT_STATUS, true) !== PatientService::ACCOUNT_ACTIVE) {
            return new WP_Error('patient_inactive', 'This patient account cannot update its password.', ['status' => 403]);
        }
        if (!(new ApiRateLimiter())->consume('patient-password:' . $userId, 'password-change', 5)) {
            return new WP_Error('rate_limited', 'Too many password-change attempts. Please try again later.', ['status' => 429]);
        }
        if (str_contains($currentPassword, "\0") || !wp_check_password($currentPassword, (string) $user->user_pass, $userId)) {
            return new WP_Error('current_password_invalid', 'The current password is not correct.', ['status' => 403]);
        }
        if (str_contains($newPassword, "\0") || !preg_match('/^.{12,128}$/usD', $newPassword)) {
            return new WP_Error('new_password_invalid', 'Choose a new password with 12–128 characters.', ['status' => 400]);
        }
        if (hash_equals($currentPassword, $newPassword)) {
            return new WP_Error('new_password_unchanged', 'Choose a new password different from the current one.', ['status' => 400]);
        }

        wp_set_password($newPassword, $userId);
        if (class_exists('\\WP_Session_Tokens')) {
            \WP_Session_Tokens::get_instance($userId)->destroy_all();
        }
        // PatientSessions fingerprints user_pass, so every existing API access
        // and refresh token fails validation after this change.
        do_action('wp_cliniko_patient_api_password_changed', $userId);
        return ['password_updated' => true, 'sessions_revoked' => true, 'message' => 'Password updated. Sign in again with the new password.'];
    }

    private static function eligiblePatient(\WP_User $user): bool
    {
        if (user_can($user, 'manage_options') || (string) get_user_meta((int) $user->ID, PatientService::META_ACCOUNT_STATUS, true) !== PatientService::ACCOUNT_ACTIVE) return false;
        $links = new PatientLinkService();
        return $links->userHasConfiguredRole((int) $user->ID) && $links->isVerifiedUser((int) $user->ID);
    }

    public static function requestClosure(int $userId, bool $acknowledged, string $returnUrl)
    {
        if (!$acknowledged) return new WP_Error('acknowledgement_required', 'Confirm that you understand what account closure affects.', ['status' => 400]);
        $returnUrl = trim($returnUrl);
        if ($returnUrl === '') return new WP_Error('return_url_required', 'Provide the same-site page that contains the account-closure shortcode.', ['status' => 400]);
        $result = (new PatientAccountClosureService())->request($userId, $returnUrl);
        if (!empty($result['ok'])) return ['status' => 'requested', 'message' => $result['message']];
        $status = (string) $result['status'];
        $httpStatus = match ($status) {
            'rate_limited' => 429,
            'not_allowed' => 403,
            default => 503,
        };
        return new WP_Error('account_closure_' . sanitize_key($status), (string) $result['message'], ['status' => $httpStatus]);
    }

    public static function confirmClosure(int $userId, bool $acknowledged, string $token)
    {
        if (!$acknowledged) return new WP_Error('acknowledgement_required', 'Confirm that you understand what account closure affects.', ['status' => 400]);
        $token = trim($token);
        if ($token === '') return new WP_Error('closure_token_required', 'Provide the confirmation token from the account-closure email.', ['status' => 400]);
        $result = (new PatientAccountClosureService())->close($userId, $userId, $token);
        if (!empty($result['ok'])) return ['status' => 'closed', 'message' => $result['message'], 'cliniko_record_deleted' => false];
        $status = (string) $result['status'];
        $httpStatus = $status === 'invalid' ? 403 : ($status === 'multisite' ? 409 : 503);
        return new WP_Error('account_closure_' . sanitize_key($status), (string) $result['message'], ['status' => $httpStatus]);
    }
}
