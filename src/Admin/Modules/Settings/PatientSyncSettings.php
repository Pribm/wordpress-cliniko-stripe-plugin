<?php

namespace App\Admin\Modules\Settings;

use App\Service\PatientService;

if (!defined('ABSPATH')) {
    exit;
}

class PatientSyncSettings
{
    private const GROUP = 'wp_cliniko_stripe_group';
    private const OPTION_ENABLED = 'wp_cliniko_patient_sync_enabled';
    private const OPTION_ROLES = 'wp_cliniko_patient_sync_roles';
    private const OPTION_ALLOW_FAILURE = 'wp_cliniko_patient_allow_user_without_sync';
    private const OPTION_PRIVACY_META_KEY = 'wp_cliniko_patient_privacy_meta_key';
    private const OPTION_STRICT_ROLE = 'wp_cliniko_patient_strict_role';
    private const OPTION_REDIRECT_PAGE = 'wp_cliniko_patient_data_redirect_page';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'registerSettings']);
    }

    public static function registerSettings(): void
    {
        register_setting(self::GROUP, self::OPTION_ENABLED, [
            'sanitize_callback' => static fn($value): bool => !empty($value),
            'default' => false,
        ]);
        register_setting(self::GROUP, self::OPTION_ROLES, [
            'sanitize_callback' => [self::class, 'sanitizeRoles'],
            'default' => [],
        ]);
        register_setting(self::GROUP, self::OPTION_ALLOW_FAILURE, [
            'sanitize_callback' => static fn($value): bool => !empty($value),
            'default' => false,
        ]);
        register_setting(self::GROUP, self::OPTION_PRIVACY_META_KEY, [
            'sanitize_callback' => static fn($value): string => sanitize_key((string) $value),
            'default' => 'accepted_privacy_policy',
        ]);
        register_setting(self::GROUP, self::OPTION_STRICT_ROLE, [
            'sanitize_callback' => [self::class, 'sanitizeStrictRole'],
            'default' => '',
        ]);
        register_setting(self::GROUP, self::OPTION_REDIRECT_PAGE, [
            'sanitize_callback' => static fn($value): int => absint($value),
            'default' => 0,
        ]);

        add_settings_section(
            'wp_cliniko_patient_sync_section',
            'WordPress Patient Accounts',
            static function (): void {
                echo '<p>Control which WordPress users are registered and authenticated against a Cliniko patient account.</p>';
                echo '<p>The selected strict role requires a linked Cliniko patient. The general failure option cannot override that role.</p>';
            },
            'wp-cliniko-stripe-settings'
        );

        add_settings_field(self::OPTION_ENABLED, 'Enable patient synchronization', static function (): void {
            echo '<input type="hidden" name="' . esc_attr(self::OPTION_ENABLED) . '" value="0" />';
            printf(
                '<input type="checkbox" name="%1$s" value="1" %2$s />',
                esc_attr(self::OPTION_ENABLED),
                checked((bool) get_option(self::OPTION_ENABLED, false), true, false)
            );
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_patient_sync_section');

        add_settings_field(self::OPTION_ROLES, 'Roles to synchronize', [self::class, 'renderRoles'], 'wp-cliniko-stripe-settings', 'wp_cliniko_patient_sync_section');

        add_settings_field(self::OPTION_PRIVACY_META_KEY, 'Privacy acceptance field', static function (): void {
            $value = (string) get_option(self::OPTION_PRIVACY_META_KEY, 'accepted_privacy_policy');
            printf(
                '<input type="text" name="%1$s" value="%2$s" class="regular-text" /> <p class="description">User-meta key maintained by the patient synchronization flow. It is automatically written as 1 when a configured patient user is registered.</p>',
                esc_attr(self::OPTION_PRIVACY_META_KEY),
                esc_attr($value)
            );
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_patient_sync_section');

        add_settings_field(self::OPTION_STRICT_ROLE, 'Strict synchronization role', [self::class, 'renderStrictRole'], 'wp-cliniko-stripe-settings', 'wp_cliniko_patient_sync_section');

        add_settings_field(self::OPTION_ALLOW_FAILURE, 'Allow WordPress access when Cliniko fails', static function (): void {
            echo '<input type="hidden" name="' . esc_attr(self::OPTION_ALLOW_FAILURE) . '" value="0" />';
            printf(
                '<input type="checkbox" name="%1$s" value="1" %2$s /> <span>Existing non-provisional users may log in during a Cliniko failure.</span>',
                esc_attr(self::OPTION_ALLOW_FAILURE),
                checked((bool) get_option(self::OPTION_ALLOW_FAILURE, false), true, false)
            );
            echo '<p class="description">New patient accounts are always marked Pending review and cannot log in until their Cliniko email link is approved. This option never overrides that registration contract.</p>';
        }, 'wp-cliniko-stripe-settings', 'wp_cliniko_patient_sync_section');

        add_settings_field(self::OPTION_REDIRECT_PAGE, 'Patient data unavailable redirect', [self::class, 'renderRedirectPage'], 'wp-cliniko-stripe-settings', 'wp_cliniko_patient_sync_section');

    }

    public static function renderRoles(): void
    {
        $selected = get_option(self::OPTION_ROLES, []);
        $selected = is_array($selected) ? $selected : [];
        foreach (get_editable_roles() as $slug => $role) {
            printf(
                '<label style="display:block"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
                esc_attr(self::OPTION_ROLES),
                esc_attr($slug),
                checked(in_array($slug, $selected, true), true, false),
                esc_html($role['name'])
            );
        }
    }

    public static function renderStrictRole(): void
    {
        $selectedRoles = get_option(self::OPTION_ROLES, []);
        $selectedRoles = is_array($selectedRoles) ? $selectedRoles : [];
        $strictRole = (string) get_option(self::OPTION_STRICT_ROLE, '');

        echo '<select name="' . esc_attr(self::OPTION_STRICT_ROLE) . '">';
        echo '<option value="">No role (use general failure setting)</option>';
        foreach (get_editable_roles() as $slug => $role) {
            if (!in_array($slug, $selectedRoles, true)) {
                continue;
            }

            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($slug),
                selected($strictRole, $slug, false),
                esc_html($role['name'])
            );
        }
        echo '</select>';
        echo '<p class="description">Users with this role remain Pending review and cannot authenticate until their Cliniko patient link is verified.</p>';
    }

    public static function renderRedirectPage(): void
    {
        $selected = (int) get_option(self::OPTION_REDIRECT_PAGE, 0);
        echo '<select name="' . esc_attr(self::OPTION_REDIRECT_PAGE) . '">';
        echo '<option value="0">Disabled</option>';
        foreach (get_pages(['post_status' => 'publish', 'sort_column' => 'post_title']) as $page) {
            printf(
                '<option value="%1$d" %2$s>%3$s</option>',
                (int) $page->ID,
                selected($selected, (int) $page->ID, false),
                esc_html((string) $page->post_title)
            );
        }
        echo '</select>';
        echo '<p class="description">On Ultimate Member-restricted pages, regular logged-in users are redirected here when their Cliniko patient data cannot be retrieved. Administrators bypass this redirect.</p>';
    }

    /** @param mixed $value @return array<int,string> */
    public static function sanitizeRoles($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $roles = array_keys(get_editable_roles());
        return array_values(array_intersect($roles, array_map('sanitize_key', $value)));
    }

    /** @param mixed $value */
    public static function sanitizeStrictRole($value): string
    {
        $role = sanitize_key((string) $value);
        $roles = get_option(self::OPTION_ROLES, []);
        $roles = is_array($roles) ? $roles : [];

        return in_array($role, $roles, true) ? $role : '';
    }
}
