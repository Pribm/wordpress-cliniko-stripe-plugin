<?php

namespace App\Service;

use App\Debug\Settings as DebugSettings;

if (!defined('ABSPATH')) {
    exit;
}

final class UltimateMemberAccountService
{
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PENDING_REVIEW = 'awaiting_admin_review';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_INACTIVE = 'inactive';

    public static function isAvailable(): bool
    {
        return isset($GLOBALS['ultimatemember']) && is_object($GLOBALS['ultimatemember']);
    }

    /**
     * Return the raw Ultimate Member status without exposing the dependency
     * details to the patient synchronisation service.
     */
    public static function status(int $userId): string
    {
        if (!self::isAvailable()) {
            return '';
        }

        try {
            $users = self::usersApi();
            return $users === null
                ? ''
                : (string) self::invoke($users, 'get_status', [$userId]);
        } catch (\Throwable $exception) {
            error_log('Ultimate Member status lookup failed: ' . $exception->getMessage());
            return '';
        }
    }

    /**
     * Deactivate an approved account after two live Cliniko 404 responses.
     *
     * Ultimate Member's public deactivate() method intentionally refuses to
     * deactivate the current user. Patient validation can run during a page
     * request, so use its public status API and explicitly clear sessions.
     */
    public static function deactivateForMissingPatient(int $userId): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        try {
            $users = self::usersApi();
            if ($users === null) {
                return false;
            }

            $status = (string) self::invoke($users, 'get_status', [$userId]);
            if ($status !== self::STATUS_APPROVED) {
                // Never claim ownership of a manually inactive, rejected, or
                // pending account.
                return false;
            }

            if (!(bool) self::invoke($users, 'set_status', [$userId, self::STATUS_INACTIVE])) {
                return false;
            }

            try {
                if (is_callable([$users, 'destroy_all_sessions'])) {
                    self::invoke($users, 'destroy_all_sessions', [$userId]);
                } elseif (class_exists('\\WP_Session_Tokens')) {
                    $tokens = \WP_Session_Tokens::get_instance($userId);
                    $tokens->destroy_all();
                }
            } catch (\Throwable $sessionException) {
                // The inactive status is the security boundary. A session
                // cleanup failure must not prevent recording the suspension.
                error_log('Ultimate Member session cleanup failed: ' . $sessionException->getMessage());
            }

            if (function_exists('get_current_user_id') && (int) get_current_user_id() === $userId) {
                if (function_exists('wp_clear_auth_cookie')) {
                    wp_clear_auth_cookie();
                }
                if (function_exists('wp_set_current_user')) {
                    wp_set_current_user(0);
                }
            }

            return true;
        } catch (\Throwable $exception) {
            error_log('Ultimate Member Cliniko suspension failed: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * Reactivate only an account already known to have been suspended by the
     * Cliniko integration. Manual Ultimate Member decisions are handled by
     * the administrator and never reach this method.
     */
    public static function reactivateAfterPatientRestore(int $userId): bool
    {
        if (!self::isAvailable()) {
            return false;
        }

        try {
            $users = self::usersApi();
            if ($users === null) {
                return false;
            }

            $status = (string) self::invoke($users, 'get_status', [$userId]);
            if ($status === self::STATUS_APPROVED) {
                return true;
            }
            if ($status !== self::STATUS_INACTIVE) {
                return false;
            }

            if (is_callable([$users, 'reactivate'])
                && (bool) self::invoke($users, 'reactivate', [$userId])) {
                return true;
            }

            // reactivate() also refuses the current user. The fallback keeps
            // the transition possible after the current session was cleared.
            return (bool) self::invoke($users, 'set_status', [$userId, self::STATUS_APPROVED]);
        } catch (\Throwable $exception) {
            error_log('Ultimate Member Cliniko reactivation failed: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * Enforce Ultimate Member's own "Require Admin Review" state.
     *
     * Returns true when Ultimate Member is absent, already pending, or was
     * successfully moved to pending. Rejected and inactive accounts are never
     * overridden by the synchronization flow.
     */
    public static function requireAdminReview(int $userId): bool
    {
        if (!DebugSettings::authBehaviourEnabled(DebugSettings::AUTH_BEHAVIOUR_UM_PENDING_REVIEW)) {
            return true;
        }

        if (!self::isAvailable()) {
            return true;
        }

        try {
            $users = self::usersApi();
            if ($users === null) {
                return false;
            }
            $status = (string) self::invoke($users, 'get_status', [$userId]);
            if ($status === self::STATUS_PENDING_REVIEW) {
                return true;
            }
            if (in_array($status, [self::STATUS_REJECTED, self::STATUS_INACTIVE], true)) {
                return false;
            }

            return (bool) self::invoke($users, 'set_as_pending', [$userId, true]);
        } catch (\Throwable $exception) {
            error_log('Ultimate Member pending-review update failed: ' . $exception->getMessage());
            return false;
        }
    }

    /**
     * Perform the same approval transition as Ultimate Member's admin action.
     *
     * Only an account currently awaiting admin review is auto-approved.
     */
    public static function approveAfterPatientSync(int $userId): bool
    {
        if (!self::isAvailable()) {
            return true;
        }

        try {
            $users = self::usersApi();
            if ($users === null) {
                return false;
            }
            $status = (string) self::invoke($users, 'get_status', [$userId]);
            if ($status === self::STATUS_APPROVED) {
                return true;
            }
            if ($status !== self::STATUS_PENDING_REVIEW) {
                return false;
            }

            return (bool) self::invoke($users, 'approve', [$userId, true]);
        } catch (\Throwable $exception) {
            error_log('Ultimate Member patient approval failed: ' . $exception->getMessage());
            return false;
        }
    }

    private static function usersApi(): ?object
    {
        $ultimateMember = $GLOBALS['ultimatemember'] ?? null;
        if (!is_object($ultimateMember)) {
            return null;
        }
        $common = self::invoke($ultimateMember, 'common');
        if (!is_object($common)) {
            return null;
        }
        $users = self::invoke($common, 'users');
        return is_object($users) ? $users : null;
    }

    /** @param list<mixed> $arguments @return mixed */
    private static function invoke(object $target, string $method, array $arguments = [])
    {
        $callback = [$target, $method];
        if (!is_callable($callback)) {
            throw new \RuntimeException('Required Ultimate Member account API is unavailable.');
        }

        return call_user_func_array($callback, $arguments);
    }
}
