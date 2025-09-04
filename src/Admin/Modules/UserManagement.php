<?php

namespace App\Admin\Modules;

use App\Service\NotificationService;

if (!defined('ABSPATH')) {
    exit;
}

class UserManagement
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_wp_cliniko_test_confirm_email', [self::class, 'handleTestEmail']);
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_wp_cliniko_test_confirm_email', [self::class, 'handleTestConfirmEmail']);
        add_action('admin_post_wp_cliniko_test_welcome_email', [self::class, 'handleTestWelcomeEmail']);
        add_action('admin_post_wp_cliniko_test_password_reset', [self::class, 'handleTestPasswordResetEmail']);
        add_action('admin_post_wp_cliniko_preview_confirm_email', [self::class, 'handlePreviewConfirmEmail']);
        add_action('admin_post_wp_cliniko_preview_welcome_email', [self::class, 'handlePreviewWelcomeEmail']);
        add_action('admin_post_wp_cliniko_preview_password_reset', [self::class, 'handlePreviewPasswordResetEmail']);


        add_filter('retrieve_password_message', function ($message, $key, $user_login, $user_data) {
            // Build reset URL
            $resetUrl = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user_login), 'login');

            $patient = [
                'first_name' => get_user_meta($user_data->ID, 'first_name', true),
                'last_name' => get_user_meta($user_data->ID, 'last_name', true),
                'email' => $user_data->user_email,
            ];

            // Call your NotificationService
            (new NotificationService())->sendPasswordReset($patient, $resetUrl);

            // Return empty string to suppress WP’s default email
            return '';
        }, 10, 4);

    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'wp-cliniko-stripe-settings',       // parent slug (your plugin main menu)
            'User Management & Emails',         // page title
            'User Management',                  // menu title
            'manage_options',                   // capability
            'wp-cliniko-user-management',       // slug
            [self::class, 'renderPage']         // render callback
        );
    }

    public static function registerSettings(): void
    {
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_send_email_on_confirm');
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_confirm_email_subject');
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_confirm_email_tpl');

        add_settings_section('confirm_email_section', 'Confirmation Email', null, 'wp-cliniko-user-management');

        add_settings_field('send_email_on_confirm', 'Enable Confirmation Email', function () {
            $val = get_option('wp_cliniko_send_email_on_confirm', 'yes');
            echo '<input type="checkbox" name="wp_cliniko_send_email_on_confirm" value="yes" ' . checked('yes', $val, false) . '> Enable';
        }, 'wp-cliniko-user-management', 'confirm_email_section');

        add_settings_field('confirm_email_subject', 'Email Subject', function () {
            $val = esc_attr(get_option('wp_cliniko_confirm_email_subject', 'Confirm your Us account'));
            echo '<input type="text" style="width:400px" name="wp_cliniko_confirm_email_subject" value="' . $val . '">';
        }, 'wp-cliniko-user-management', 'confirm_email_section');

        add_settings_field('confirm_email_tpl', 'Email Template', function () {
            $val = esc_textarea(get_option(
                'wp_cliniko_confirm_email_tpl',
                '<p>Hi {first_name},</p><p>Click <a href="{confirmation_url}">here</a> to confirm your account.</p>'
            ));
            echo '<textarea rows="12" cols="70" style="font-family:monospace;" name="wp_cliniko_confirm_email_tpl">' . $val . '</textarea>';
            echo '<p class="description">Available tags: {first_name}, {last_name}, {email}, {confirmation_url}</p>';
        }, 'wp-cliniko-user-management', 'confirm_email_section');

        // --- Confirmation ---
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_send_email_on_confirm');
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_confirm_email_subject');
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_confirm_email_tpl');

        // --- Welcome ---
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_send_email_on_welcome');
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_welcome_email_subject');
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_welcome_email_tpl');

        // --- Password Reset ---
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_send_email_on_password_reset');
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_password_reset_subject');
        register_setting('wp_cliniko_user_mgmt', 'wp_cliniko_password_reset_tpl');
    }

    public static function renderPage(): void
    {
        ?>
        <div class="wrap">
            <h1>User Management & Email Settings</h1>

            <?php if (isset($_GET['test_sent'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success:</strong> Test email sent.</p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('wp_cliniko_user_mgmt');
                ?>

                <h2>Confirmation Email</h2>
                <p>Sent after registration, requires user to confirm email before account activation.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable</th>
                        <td><input type="checkbox" name="wp_cliniko_send_email_on_confirm" value="yes" <?php checked('yes', get_option('wp_cliniko_send_email_on_confirm', 'yes')); ?>></td>
                    </tr>
                    <tr>
                        <th scope="row">Subject</th>
                        <td><input type="text" name="wp_cliniko_confirm_email_subject" style="width:400px"
                                value="<?php echo esc_attr(get_option('wp_cliniko_confirm_email_subject', 'Confirm your Us account')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Template</th>
                        <td>
                            <textarea name="wp_cliniko_confirm_email_tpl" rows="10" cols="70"
                                style="font-family:monospace;"><?php echo esc_textarea(get_option('wp_cliniko_confirm_email_tpl', '<p>Hi {first_name},</p><p>Click <a href="{confirmation_url}">here</a> to confirm your account.</p>')); ?></textarea>
                            <p class="description">Tags: {first_name}, {last_name}, {email}, {confirmation_url}</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Preview</th>
                        <td>
                            <p>
                                <a href="<?php echo esc_url(
                                    wp_nonce_url(
                                        add_query_arg(['action' => 'wp_cliniko_preview_confirm_email'], admin_url('admin-post.php')),
                                        'wp_cliniko_preview_confirm_email'
                                    )
                                ); ?>" target="_blank" class="button">Preview Template</a>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Welcome Email (placeholder)</h2>
                <p>Sent after email confirmation. You can customize this in the future.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable</th>
                        <td><input type="checkbox" name="wp_cliniko_send_email_on_welcome" value="yes" <?php checked('yes', get_option('wp_cliniko_send_email_on_welcome', 'no')); ?>></td>
                    </tr>
                    <tr>
                        <th scope="row">Subject</th>
                        <td><input type="text" name="wp_cliniko_welcome_email_subject" style="width:400px"
                                value="<?php echo esc_attr(get_option('wp_cliniko_welcome_email_subject', 'Welcome to Us!')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Template</th>
                        <td>
                            <textarea name="wp_cliniko_welcome_email_tpl" rows="10" cols="70"
                                style="font-family:monospace;"><?php echo esc_textarea(get_option('wp_cliniko_welcome_email_tpl', '<p>Hi {first_name},</p><p>Welcome aboard! You can now book consultations easily.</p>')); ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            Preview
                        </th>
                        <td>
                            <p>
                                <a href="<?php echo esc_url(
                                    wp_nonce_url(
                                        add_query_arg(['action' => 'wp_cliniko_preview_welcome_email'], admin_url('admin-post.php')),
                                        'wp_cliniko_preview_welcome_email'
                                    )
                                ); ?>" target="_blank" class="button">Preview Template</a>
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Password Reset Email</h2>
                <p>Sent when the user requests a password reset.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable</th>
                        <td><input type="checkbox" name="wp_cliniko_send_email_on_password_reset" value="yes" <?php checked('yes', get_option('wp_cliniko_send_email_on_password_reset', 'no')); ?>></td>
                    </tr>
                    <tr>
                        <th scope="row">Subject</th>
                        <td><input type="text" name="wp_cliniko_password_reset_subject" style="width:400px"
                                value="<?php echo esc_attr(get_option('wp_cliniko_password_reset_subject', 'Reset your Us password')); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Template</th>
                        <td>
                            <textarea name="wp_cliniko_password_reset_tpl" rows="10" cols="70"
                                style="font-family:monospace;"><?php echo esc_textarea(get_option('wp_cliniko_password_reset_tpl', '<p>Hi {first_name},</p><p>Click <a href="{reset_url}">here</a> to reset your password.</p>')); ?></textarea>
                            <p class="description">Tags: {first_name}, {last_name}, {email}, {reset_url}</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            Preview
                        </th>
                        <td>
                            <p>
                                <a href="<?php echo esc_url(
                                    wp_nonce_url(
                                        add_query_arg(['action' => 'wp_cliniko_preview_password_reset'], admin_url('admin-post.php')),
                                        'wp_cliniko_preview_password_reset'
                                    )
                                ); ?>" target="_blank" class="button">Preview Template</a>
                            </p>
                        </td>
                    </tr>
                </table>


                <?php submit_button(); ?>
            </form>

            <div class="postbox" style="padding:20px; margin-top:30px;">
                <h2>Send Test Emails</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px;">
                    <?php wp_nonce_field('wp_cliniko_test_confirm_email'); ?>
                    <input type="hidden" name="action" value="wp_cliniko_test_confirm_email">
                    <input type="email" name="test_email" placeholder="Destination email"
                        value="<?php echo esc_attr(get_option('admin_email')); ?>" style="width:300px">
                    <button class="button">Send Test Confirmation Email</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:10px;">
                    <?php wp_nonce_field('wp_cliniko_test_welcome_email'); ?>
                    <input type="hidden" name="action" value="wp_cliniko_test_welcome_email">
                    <input type="email" name="test_email" placeholder="Destination email"
                        value="<?php echo esc_attr(get_option('admin_email')); ?>" style="width:300px">
                    <button class="button">Send Test Welcome Email</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('wp_cliniko_test_password_reset'); ?>
                    <input type="hidden" name="action" value="wp_cliniko_test_password_reset">
                    <input type="email" name="test_email" placeholder="Destination email"
                        value="<?php echo esc_attr(get_option('admin_email')); ?>" style="width:300px">
                    <button class="button">Send Test Password Reset Email</button>


                </form>
            </div>
        </div>
        <?php
    }

    public static function handleTestEmail(): void
    {
        check_admin_referer('wp_cliniko_test_confirm_email');

        $email = sanitize_email($_POST['test_email'] ?? get_option('admin_email'));
        $patient = [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $email,
        ];
        $token = wp_generate_password(32, false);

        (new NotificationService())->sendConfirmation($patient, $token);

        wp_safe_redirect(add_query_arg(['page' => 'wp-cliniko-user-management', 'test_sent' => 1], admin_url('admin.php')));
        exit;
    }

    public static function handlePreviewConfirmEmail(): void
    {
        check_admin_referer('wp_cliniko_preview_confirm_email');

        $patient = [
            'first_name' => 'Alice',
            'last_name' => 'Example',
            'email' => 'alice@example.com',
        ];
        $token = 'FAKETOKEN123';
        $confirmUrl = site_url('/?confirm_token=' . urlencode($token));

        $tpl = get_option('wp_cliniko_confirm_email_tpl', '<p>Hi {first_name},</p><p>Please confirm your account: <a href="{confirmation_url}">{confirmation_url}</a></p>');
        $subject = get_option('wp_cliniko_confirm_email_subject', 'Confirm your EasyScripts account');

        $vars = [
            '{first_name}' => esc_html($patient['first_name']),
            '{last_name}' => esc_html($patient['last_name']),
            '{email}' => esc_html($patient['email']),
            '{confirmation_url}' => esc_url($confirmUrl),
        ];
        $body = strtr($tpl, $vars);

        header('Content-Type: text/html; charset=UTF-8');
        echo "<h2>Subject: " . esc_html($subject) . "</h2>";
        echo "<div style='border:1px solid #ccc; padding:20px;'>" . $body . "</div>";
        exit;
    }

    public static function handlePreviewWelcomeEmail(): void
    {
        check_admin_referer('wp_cliniko_preview_welcome_email');

        $patient = [
            'first_name' => 'Alice',
            'last_name' => 'Example',
            'email' => 'alice@example.com',
        ];

        $tpl = get_option('wp_cliniko_welcome_email_tpl', '<p>Hi {first_name},</p><p>Welcome to EasyScripts!</p>');
        $subject = get_option('wp_cliniko_welcome_email_subject', 'Welcome to EasyScripts!');

        $vars = [
            '{first_name}' => esc_html($patient['first_name']),
            '{last_name}' => esc_html($patient['last_name']),
            '{email}' => esc_html($patient['email']),
        ];
        $body = strtr($tpl, $vars);

        header('Content-Type: text/html; charset=UTF-8');
        echo "<h2>Subject: " . esc_html($subject) . "</h2>";
        echo "<div style='border:1px solid #ccc; padding:20px;'>" . $body . "</div>";
        exit;
    }

    public static function handlePreviewPasswordResetEmail(): void
    {
        check_admin_referer('wp_cliniko_preview_password_reset');

        $patient = [
            'first_name' => 'Alice',
            'last_name' => 'Example',
            'email' => 'alice@example.com',
        ];
        $resetUrl = network_site_url('wp-login.php?action=rp&key=FAKEKEY&login=alice', 'login');

        $tpl = get_option('wp_cliniko_password_reset_tpl', '<p>Hi {first_name},</p><p>Reset your password: <a href="{reset_url}">{reset_url}</a></p>');
        $subject = get_option('wp_cliniko_password_reset_subject', 'Reset your EasyScripts password');

        $vars = [
            '{first_name}' => esc_html($patient['first_name']),
            '{last_name}' => esc_html($patient['last_name']),
            '{email}' => esc_html($patient['email']),
            '{reset_url}' => esc_url($resetUrl),
        ];
        $body = strtr($tpl, $vars);

        header('Content-Type: text/html; charset=UTF-8');
        echo "<h2>Subject: " . esc_html($subject) . "</h2>";
        echo "<div style='border:1px solid #ccc; padding:20px;'>" . $body . "</div>";
        exit;
    }



    public static function handleTestWelcomeEmail(): void
    {
        check_admin_referer('wp_cliniko_test_welcome_email');
        $email = sanitize_email($_POST['test_email'] ?? get_option('admin_email'));
        // Placeholder for NotificationService::sendWelcome (to be implemented later)
        wp_mail($email, 'Welcome Email Test', 'This is a placeholder welcome email.');
        wp_safe_redirect(add_query_arg(['page' => 'wp-cliniko-user-management', 'test_sent' => 1], admin_url('admin.php')));
        exit;
    }

    public static function handleTestPasswordResetEmail(): void
    {
        check_admin_referer('wp_cliniko_test_password_reset');

        $email = sanitize_email($_POST['test_email'] ?? get_option('admin_email'));

        // Lookup WP user (or fallback to admin)
        $user = get_user_by('email', $email);
        if (!$user) {
            $user = get_user_by('id', get_current_user_id());
        }

        if (!$user) {
            wp_die('<div class="notice notice-error"><p>No valid user found for test email.</p></div>');
        }

        // Generate a fake reset key & URL like WP core does
        $key = wp_generate_password(20, false);
        $resetUrl = network_site_url("wp-login.php?action=rp&key=$key&login=" . rawurlencode($user->user_login), 'login');

        // Patient-style array for NotificationService
        $patient = [
            'first_name' => get_user_meta($user->ID, 'first_name', true) ?: 'Test',
            'last_name' => get_user_meta($user->ID, 'last_name', true) ?: 'User',
            'email' => $user->user_email,
        ];

        // Send email using your NotificationService
        (new NotificationService())->sendPasswordReset($patient, $resetUrl);

        wp_safe_redirect(add_query_arg([
            'page' => 'wp-cliniko-user-management',
            'test_sent' => 1,
        ], admin_url('admin.php')));
        exit;
    }


    public static function handleTestConfirmEmail(): void
    {
        check_admin_referer('wp_cliniko_test_confirm_email');
        $email = sanitize_email($_POST['test_email'] ?? get_option('admin_email'));
        $patient = ['first_name' => 'Test', 'last_name' => 'User', 'email' => $email];
        $token = wp_generate_password(32, false);
        (new NotificationService())->sendConfirmation($patient, $token);
        wp_safe_redirect(add_query_arg(['page' => 'wp-cliniko-user-management', 'test_sent' => 1], admin_url('admin.php')));
        exit;
    }


}