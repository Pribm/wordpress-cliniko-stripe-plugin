<?php

namespace App\Support;

use App\Service\PatientLinkService;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps Nextend's contextual redirects compatible with Ultimate Member pages.
 *
 * Ultimate Member renders social-login buttons with its current login or
 * registration page as Nextend's requested redirect. Nextend considers that a
 * valid explicit destination, so its Default redirect URL is never reached.
 * This adapter removes only that auth-page self-redirect. A nested redirect_to
 * destination (for example, the protected page that originally required a
 * login) remains authoritative.
 */
final class NextendRedirectCompatibility
{
    private const NEXTEND_OPTION = 'nextend_social_login';
    private static int $pendingPatientAuthentication = 0;

    public static function init(): void
    {
        // Nextend hands a newly-created account to Ultimate Member when login
        // restrictions are enabled. UM may redirect and exit before Nextend
        // gets a chance to apply its own registration redirect.
        add_filter(
            'um_registration_show_message_redirect_url',
            [self::class, 'replacePendingRegistrationRedirect'],
            20,
            4
        );
        add_filter(
            'um_registration_pending_user_redirect',
            [self::class, 'replacePendingRegistrationRedirect'],
            20,
            3
        );
        // Nextend otherwise sends every authentication restriction/error to
        // wp-login.php?nsl-notice=1. Capture a pending patient before the
        // authentication policies run, then choose a frontend destination.
        add_filter(
            'authenticate',
            [self::class, 'capturePendingPatientAuthentication'],
            39,
            3
        );
        add_filter(
            'nsl_disabled_login_redirect_url',
            [self::class, 'replaceDisabledLoginRedirect'],
            20,
            1
        );
        add_action(
            'login_init',
            [self::class, 'redirectNativeNextendNotice'],
            1
        );

        $settings = self::settings();
        $providers = $settings['enabled'] ?? [];
        if (!is_array($providers)) {
            return;
        }

        foreach ($providers as $provider) {
            $providerId = sanitize_key((string) $provider);
            if ($providerId === '') {
                continue;
            }

            // This is the hook Nextend applies after considering an explicit
            // redirect, but before it falls back to the site URL.
            add_filter(
                'nsl_' . $providerId . 'default_last_location_redirect',
                [self::class, 'replaceUltimateMemberAuthRedirect'],
                20,
                2
            );
        }
    }

    /**
     * @param mixed $redirectTo
     * @param mixed $requestedRedirectTo
     * @return mixed
     */
    public static function replaceUltimateMemberAuthRedirect($redirectTo, $requestedRedirectTo)
    {
        if (!is_string($redirectTo) || !self::isUltimateMemberAuthUrl($redirectTo)) {
            return $redirectTo;
        }

        $requested = is_string($requestedRedirectTo) && $requestedRedirectTo !== ''
            ? $requestedRedirectTo
            : $redirectTo;
        $nestedRedirect = self::nestedRedirectTo($requested);
        if ($nestedRedirect !== '' && !self::isUltimateMemberAuthUrl($nestedRedirect)) {
            return $nestedRedirect;
        }

        $settings = self::settings();
        $flow = class_exists('NextendSocialLogin', false)
            ? (string) \NextendSocialLogin::$WPLoginCurrentFlow
            : 'login';
        $setting = $flow === 'register' ? 'default_redirect_reg' : 'default_redirect';
        $defaultRedirect = wp_validate_redirect((string) ($settings[$setting] ?? ''), '');

        // Returning an empty value lets Nextend use its normal site-URL
        // fallback when no safe default has been configured.
        return $defaultRedirect !== '' && !self::isUltimateMemberAuthUrl($defaultRedirect)
            ? $defaultRedirect
            : '';
    }

    /**
     * @param mixed $redirectTo
     * @param mixed $status
     * @param mixed $userId
     * @param mixed $formData
     * @return mixed
     */
    public static function replacePendingRegistrationRedirect(
        $redirectTo,
        $status,
        $userId,
        $formData = null
    ) {
        $patientLinkStatus = (int) $userId > 0
            ? (string) get_user_meta((int) $userId, PatientLinkService::META_STATUS, true)
            : '';
        if ((string) $status !== 'pending' || $patientLinkStatus !== PatientLinkService::STATUS_PENDING) {
            return $redirectTo;
        }

        $settings = self::settings();
        $defaultRedirect = wp_validate_redirect(
            (string) ($settings['default_redirect_reg'] ?? ''),
            ''
        );

        return $defaultRedirect !== '' && !self::isUltimateMemberAuthUrl($defaultRedirect)
            ? $defaultRedirect
            : $redirectTo;
    }

    /**
     * @param mixed $user
     * @param mixed $username
     * @param mixed $password
     * @return mixed
     */
    public static function capturePendingPatientAuthentication($user, $username = '', $password = '')
    {
        self::$pendingPatientAuthentication = 0;
        if (
            !$user instanceof \WP_User
            || (string) $password !== ''
            || !class_exists('NextendSocialLogin', false)
        ) {
            return $user;
        }

        $status = (string) get_user_meta((int) $user->ID, PatientLinkService::META_STATUS, true);
        if (in_array($status, [PatientLinkService::STATUS_PENDING, PatientLinkService::STATUS_FAILED], true)) {
            self::$pendingPatientAuthentication = (int) $user->ID;
        }

        return $user;
    }

    /**
     * @param mixed $redirectTo
     * @return mixed
     */
    public static function replaceDisabledLoginRedirect($redirectTo)
    {
        if (self::$pendingPatientAuthentication > 0) {
            self::$pendingPatientAuthentication = 0;
            $settings = self::settings();
            $registrationRedirect = wp_validate_redirect(
                (string) ($settings['default_redirect_reg'] ?? ''),
                ''
            );
            if (
                $registrationRedirect !== ''
                && !self::isUltimateMemberAuthUrl($registrationRedirect)
            ) {
                return $registrationRedirect;
            }
        }

        if (function_exists('um_get_core_page')) {
            $loginUrl = wp_validate_redirect((string) um_get_core_page('login'), '');
            if ($loginUrl !== '') {
                return $loginUrl;
            }
        }

        return $redirectTo;
    }

    /**
     * Some early provider/OAuth failures bypass Nextend's configurable
     * redirect filters and point the popup directly at wp-login.php. Catch
     * only the resulting notice request; never interfere with an active
     * loginSocial callback.
     */
    public static function redirectNativeNextendNotice(): void
    {
        if (
            !isset($_GET['nsl-notice'])
            || (string) wp_unslash($_GET['nsl-notice']) !== '1'
            || !empty($_REQUEST['loginSocial'])
            || !function_exists('um_get_core_page')
        ) {
            return;
        }

        $loginUrl = wp_validate_redirect((string) um_get_core_page('login'), '');
        if ($loginUrl === '') {
            return;
        }

        nocache_headers();
        wp_safe_redirect(add_query_arg('nsl-notice', '1', $loginUrl));
        exit;
    }

    /** @return array<string,mixed> */
    private static function settings(): array
    {
        $settings = get_option(self::NEXTEND_OPTION, []);
        $settings = maybe_unserialize($settings);
        return is_array($settings) ? $settings : [];
    }

    private static function nestedRedirectTo(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || !isset($parts['query'])) {
            return '';
        }

        parse_str((string) $parts['query'], $query);
        $redirect = $query['redirect_to'] ?? '';
        if (!is_string($redirect) || trim($redirect) === '') {
            return '';
        }

        return wp_validate_redirect(wp_unslash($redirect), '');
    }

    private static function isUltimateMemberAuthUrl(string $url): bool
    {
        if ($url === '' || !function_exists('um_get_core_page')) {
            return false;
        }

        foreach (['login', 'register'] as $page) {
            $authUrl = (string) um_get_core_page($page);
            if ($authUrl !== '' && self::sameDestination($url, $authUrl)) {
                return true;
            }
        }

        return false;
    }

    private static function sameDestination(string $first, string $second): bool
    {
        $firstParts = wp_parse_url($first);
        $secondParts = wp_parse_url($second);
        if (!is_array($firstParts) || !is_array($secondParts)) {
            return false;
        }

        $firstHost = strtolower((string) ($firstParts['host'] ?? ''));
        $secondHost = strtolower((string) ($secondParts['host'] ?? ''));
        if ($firstHost !== '' && $secondHost !== '' && $firstHost !== $secondHost) {
            return false;
        }

        $firstPort = (int) ($firstParts['port'] ?? 0);
        $secondPort = (int) ($secondParts['port'] ?? 0);
        if ($firstPort !== $secondPort) {
            return false;
        }

        $firstPath = '/' . trim((string) ($firstParts['path'] ?? ''), '/');
        $secondPath = '/' . trim((string) ($secondParts['path'] ?? ''), '/');

        return $firstPath === $secondPath;
    }
}
