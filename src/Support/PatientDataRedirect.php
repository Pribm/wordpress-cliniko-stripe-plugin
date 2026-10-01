<?php

namespace App\Support;

use App\Service\PatientService;

if (!defined('ABSPATH')) {
    exit;
}

final class PatientDataRedirect
{
    public const OPTION_PAGE = 'wp_cliniko_patient_data_redirect_page';

    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'handleTemplateRedirect'], 1);
    }

    public static function handleTemplateRedirect(): void
    {
        if (!self::shouldRedirect()) {
            return;
        }

        try {
            $patient = (new PatientService())->getPatientForCurrentUser();
        } catch (\Throwable $exception) {
            // A network/API exception is transient. Do not log the user out
            // or redirect them as if their patient link were invalid.
            return;
        }

        if ($patient === null) {
            self::redirect();
        }
    }

    /** @return false */
    public static function maybeRedirect(): bool
    {
        if (!self::shouldRedirect()) {
            return false;
        }

        return self::redirect();
    }

    /** @return false */
    private static function redirect(): bool
    {
        $pageId = (int) get_option(self::OPTION_PAGE, 0);
        $target = $pageId > 0 ? get_permalink($pageId) : false;
        if (!is_string($target) || $target === '') {
            return false;
        }

        $currentUrl = self::currentUrl();
        if ($currentUrl !== '' && self::sameUrl($currentUrl, $target)) {
            return false;
        }

        if (headers_sent()) {
            return false;
        }

        wp_safe_redirect(wp_validate_redirect($target, home_url('/')));
        exit;
    }

    private static function shouldRedirect(): bool
    {
        $user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
        if (
            current_user_can('manage_options')
            || ($user instanceof \WP_User && in_array('administrator', (array) $user->roles, true))
        ) {
            return false;
        }

        if (!is_user_logged_in() || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return false;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return false;
        }

        $postId = (int) get_queried_object_id();
        if ($postId <= 0 || !is_singular()) {
            return false;
        }

        $restriction = get_post_meta($postId, 'um_content_restriction', true);
        if (!is_array($restriction) || !self::isEnabled($restriction['_um_accessible'] ?? null)) {
            return false;
        }

        return (int) get_option(self::OPTION_PAGE, 0) > 0;
    }

    /** @param mixed $value */
    private static function isEnabled($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }

    private static function currentUrl(): string
    {
        if (function_exists('get_permalink')) {
            $permalink = get_permalink((int) get_queried_object_id());
            if (is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        return '';
    }

    private static function sameUrl(string $left, string $right): bool
    {
        return untrailingslashit((string) wp_parse_url($left, PHP_URL_PATH))
            === untrailingslashit((string) wp_parse_url($right, PHP_URL_PATH));
    }
}
