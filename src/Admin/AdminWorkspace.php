<?php

namespace App\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/** Shared presentation for the plugin's settings, operations and guide pages. */
final class AdminWorkspace
{
    private const PAGES = ['wp-cliniko-stripe-settings', 'wp-cliniko-tools', 'wp-cliniko-debug', 'wp-cliniko-user-guide'];

    public static function init(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
    }

    public static function enqueueAssets(): void
    {
        $page = sanitize_key((string) ($_GET['page'] ?? ''));
        if (!in_array($page, self::PAGES, true)) {
            return;
        }
        foreach (['admin-workspace' => [], 'admin-pages' => ['cliniko-admin-workspace']] as $name => $dependencies) {
            $path = __DIR__ . '/assets/' . $name . '.css';
            wp_enqueue_style('cliniko-' . $name, plugins_url('assets/' . $name . '.css', __FILE__), $dependencies, (string) filemtime($path));
        }
        wp_enqueue_script('cliniko-admin-pages', plugins_url('assets/admin-pages.js', __FILE__), [], (string) filemtime(__DIR__ . '/assets/admin-pages.js'), true);
    }

    public static function begin(string $page, string $title, string $description): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        $sections = [
            'wp-cliniko-user-guide' => ['Getting started', 'welcome-learn-more'],
            'wp-cliniko-stripe-settings' => ['Settings', 'admin-settings'],
            'wp-cliniko-account-builders' => ['Template Builder', 'layout'],
            'wp-cliniko-api-access' => ['API Access', 'rest-api'],
            'wp-cliniko-tools' => ['Tools', 'admin-tools'],
            'wp-cliniko-debug' => ['Debug', 'visibility'],
        ];
        ?>
        <div class="wrap cliniko-admin-ui cliniko-plugin-admin">
            <header class="cliniko-admin-topbar">
                <div>
                    <span class="cliniko-admin-eyebrow">Cliniko + Stripe</span>
                    <h1><?php echo esc_html($title); ?></h1>
                    <p><?php echo esc_html($description); ?></p>
                </div>
            </header>
            <div class="cliniko-admin-shell">
                <aside class="cliniko-admin-sidebar">
                    <p class="cliniko-admin-nav-label">Plugin workspace</p>
                    <nav class="cliniko-admin-nav" aria-label="Plugin sections">
                        <?php foreach ($sections as $slug => [$label, $icon]): ?>
                            <a href="<?php echo esc_url(add_query_arg('page', $slug, admin_url('admin.php'))); ?>"<?php echo $slug === $page ? ' class="is-active" aria-current="page"' : ''; ?>>
                                <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                                <span><?php echo esc_html($label); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                    <div class="cliniko-admin-sidebar-note">Manage your integrations, patient portal and app access here. These settings are available to administrators only.</div>
                </aside>
                <div class="cliniko-admin-content" role="region" aria-label="<?php echo esc_attr($title); ?>">
        <?php
    }

    public static function end(): void
    {
        echo '</div></div></div>';
    }
}
