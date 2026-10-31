<?php

namespace App\Admin\Modules;

use App\Admin\AdminWorkspace;

use App\Debug\LogStore;
use App\Debug\Settings as DebugSettings;

if (!defined('ABSPATH')) {
    exit;
}

class Debug
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('admin_post_wp_cliniko_debug_clear_logs', [self::class, 'clearLogs']);
        add_action('admin_post_wp_cliniko_debug_export_logs', [self::class, 'exportLogs']);
        add_action('admin_post_wp_cliniko_debug_export_context', [self::class, 'exportContext']);
    }

    public static function registerMenu(): void
    {
        add_submenu_page(
            'wp-cliniko-stripe-settings',
            'Debug Observability',
            'Debug',
            'manage_options',
            'wp-cliniko-debug',
            [self::class, 'renderPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting(
            'wp_cliniko_debug_group',
            DebugSettings::OPTION_KEY,
            [
                'type' => 'array',
                'sanitize_callback' => [DebugSettings::class, 'sanitize'],
                'default' => DebugSettings::get(),
            ]
        );
    }

    public static function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $store = new LogStore();
        $store->ensureTable();

        $settings = DebugSettings::get();
        $filters = self::readFilters();
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $result = $store->query($filters, $page, 50);
        $summary = $store->summary();
        $channels = $store->distinctChannels();
        $events = $store->distinctEvents();

        AdminWorkspace::begin('wp-cliniko-debug', 'Debug & observability', 'Review plugin activity and errors. Sensitive values are excluded or redacted.');
        ?>
        <div class="cliniko-admin-page cliniko-debug-page">

            <?php if (isset($_GET['debug_cleared'])): ?>
                <div class="notice notice-success is-dismissible"><p>Debug logs were cleared.</p></div>
            <?php endif; ?>

            <?php if ($settings['enabled'] !== 'yes'): ?>
                <div class="notice notice-warning"><p>Debug capture is currently disabled.</p></div>
            <?php endif; ?>

            <div class="cliniko-debug-metrics">
                <?php self::renderMetricCard('Stored Logs', (string) $summary['total']); ?>
                <?php self::renderMetricCard('Errors', (string) $summary['errors']); ?>
                <?php self::renderMetricCard('Warnings', (string) $summary['warnings']); ?>
                <?php self::renderMetricCard('Slow Events (>=1s)', (string) $summary['slow']); ?>
            </div>

            <div class="cliniko-admin-card">
                <h2>Debug Settings</h2>
                <form method="post" action="options.php">
                    <?php settings_fields('wp_cliniko_debug_group'); ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row">Enable Debug Capture</th>
                                <td>
                                    <label>
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr(DebugSettings::OPTION_KEY); ?>[enabled]"
                                            value="yes"
                                            <?php checked($settings['enabled'], 'yes'); ?>
                                        />
                                        Capture logs for plugin flows only when explicitly enabled.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Mock Patient Rendering</th>
                                <td>
                                    <label>
                                        <input
                                            type="checkbox"
                                            name="<?php echo esc_attr(DebugSettings::OPTION_KEY); ?>[mock_patient]"
                                            value="yes"
                                            <?php checked($settings['mock_patient'], 'yes'); ?>
                                        />
                                        Use mock patient data in patient rendering shortcodes.
                                    </label>
                                    <p class="description">Development-only preview. It is used only for administrators when enabled.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Authentication Behaviour Switches</th>
                                <td>
                                    <p>Disable one authentication-related behaviour at a time while diagnosing unexpected admin logouts. All switches are enabled by default.</p>
                                    <?php foreach (DebugSettings::authBehaviours() as $key => $label): ?>
                                        <label style="display:block;margin:8px 0;">
                                            <input
                                                type="hidden"
                                                name="<?php echo esc_attr(DebugSettings::OPTION_KEY); ?>[auth_behaviours][<?php echo esc_attr($key); ?>]"
                                                value="no"
                                            />
                                            <input
                                                type="checkbox"
                                                name="<?php echo esc_attr(DebugSettings::OPTION_KEY); ?>[auth_behaviours][<?php echo esc_attr($key); ?>]"
                                                value="yes"
                                                <?php checked($settings['auth_behaviours'][$key] ?? 'yes', 'yes'); ?>
                                            />
                                            <?php echo esc_html($label); ?>
                                        </label>
                                    <?php endforeach; ?>
                                    <p class="description">Uncheck exactly one switch, save, reproduce the problem, then re-enable it before testing the next behaviour.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Retention Days</th>
                                <td>
                                    <input
                                        type="number"
                                        min="1"
                                        max="90"
                                        name="<?php echo esc_attr(DebugSettings::OPTION_KEY); ?>[retention_days]"
                                        value="<?php echo esc_attr((string) $settings['retention_days']); ?>"
                                    />
                                    <p class="description">Older logs are pruned automatically.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Max Rows</th>
                                <td>
                                    <input
                                        type="number"
                                        min="100"
                                        max="20000"
                                        step="100"
                                        name="<?php echo esc_attr(DebugSettings::OPTION_KEY); ?>[max_rows]"
                                        value="<?php echo esc_attr((string) $settings['max_rows']); ?>"
                                    />
                                    <p class="description">Keeps the newest rows and discards excess records.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <?php submit_button('Save Debug Settings'); ?>
                </form>
            </div>

            <div class="cliniko-admin-card">
                <h2>Log Actions</h2>
                <div class="cliniko-admin-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wp_cliniko_debug_clear_logs'); ?>
                        <input type="hidden" name="action" value="wp_cliniko_debug_clear_logs" />
                        <button type="submit" class="button button-secondary">Clear Logs</button>
                    </form>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wp_cliniko_debug_export_logs'); ?>
                        <input type="hidden" name="action" value="wp_cliniko_debug_export_logs" />
                        <?php foreach ($filters as $key => $value): ?>
                            <input type="hidden" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" />
                        <?php endforeach; ?>
                        <button type="submit" class="button">Export Filtered Logs (JSON)</button>
                    </form>
                </div>
            </div>

            <div class="cliniko-admin-card">
                <h2>Recent Logs</h2>

                <form method="get" class="cliniko-debug-filters">
                    <input type="hidden" name="page" value="wp-cliniko-debug" />

                    <label>
                        <span style="display:block;margin-bottom:4px;">Channel</span>
                        <select name="channel">
                            <option value="">All</option>
                            <?php foreach ($channels as $channel): ?>
                                <option value="<?php echo esc_attr($channel); ?>" <?php selected($filters['channel'], $channel); ?>>
                                    <?php echo esc_html($channel); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span style="display:block;margin-bottom:4px;">Event</span>
                        <select name="event">
                            <option value="">All</option>
                            <?php foreach ($events as $event): ?>
                                <option value="<?php echo esc_attr($event); ?>" <?php selected($filters['event'], $event); ?>>
                                    <?php echo esc_html($event); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span style="display:block;margin-bottom:4px;">Level</span>
                        <select name="level">
                            <option value="">All</option>
                            <?php foreach (['info', 'warning', 'error'] as $level): ?>
                                <option value="<?php echo esc_attr($level); ?>" <?php selected($filters['level'], $level); ?>>
                                    <?php echo esc_html($level); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span style="display:block;margin-bottom:4px;">Trace ID</span>
                        <input type="text" name="trace_id" value="<?php echo esc_attr($filters['trace_id']); ?>" />
                    </label>

                    <label>
                        <span style="display:block;margin-bottom:4px;">Route</span>
                        <input type="text" name="route" value="<?php echo esc_attr($filters['route']); ?>" />
                    </label>

                    <label>
                        <span style="display:block;margin-bottom:4px;">Search</span>
                        <input type="text" name="search" value="<?php echo esc_attr($filters['search']); ?>" />
                    </label>

                    <button type="submit" class="button">Filter</button>
                    <a class="button button-link" href="<?php echo esc_url(admin_url('admin.php?page=wp-cliniko-debug')); ?>">Reset</a>
                </form>

                <div class="cliniko-admin-table-scroll" role="region" aria-label="Recent logs table" tabindex="0">
                <table class="widefat striped cliniko-debug-table">
                    <thead>
                        <tr>
                            <th style="width:150px;">Time</th>
                            <th style="width:90px;">Level</th>
                            <th style="width:90px;">Channel</th>
                            <th style="width:120px;">Event</th>
                            <th style="width:120px;">Trace</th>
                            <th style="width:80px;">Status</th>
                            <th style="width:90px;">Duration</th>
                            <th>Route / Target</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($result['rows'])): ?>
                            <tr><td colspan="9">No logs found for the current filter.</td></tr>
                        <?php else: ?>
                            <?php foreach ($result['rows'] as $row): ?>
                                <tr>
                                    <td><?php echo esc_html(get_date_from_gmt((string) $row['created_at'], 'Y-m-d H:i:s')); ?></td>
                                    <td><?php echo esc_html((string) $row['level']); ?></td>
                                    <td><?php echo esc_html((string) $row['channel']); ?></td>
                                    <td><?php echo esc_html((string) $row['event']); ?></td>
                                    <td><code><?php echo esc_html((string) $row['trace_id']); ?></code></td>
                                    <td><?php echo esc_html((string) ($row['status_code'] ?? '')); ?></td>
                                    <td><?php echo isset($row['duration_ms']) ? esc_html((string) $row['duration_ms']) . 'ms' : ''; ?></td>
                                    <td>
                                        <?php if (!empty($row['route'])): ?>
                                            <div><strong><?php echo esc_html((string) $row['route']); ?></strong></div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['target'])): ?>
                                            <div style="color:#555;"><?php echo esc_html((string) $row['target']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?php echo esc_html((string) $row['message']); ?></div>
                                        <?php if (!empty($row['context'])): ?>
                                            <details style="margin-top:6px;">
                                                <summary>Context</summary>
                                                <pre style="white-space:pre-wrap;margin-top:8px;"><?php echo esc_html(self::prettyContext((string) $row['context'])); ?></pre>
                                            </details>
                                        <?php endif; ?>
                                        <?php if (!empty($row['id'])): ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
                                                <?php wp_nonce_field('wp_cliniko_debug_export_context_' . (int) $row['id']); ?>
                                                <input type="hidden" name="action" value="wp_cliniko_debug_export_context" />
                                                <input type="hidden" name="log_id" value="<?php echo esc_attr((string) $row['id']); ?>" />
                                                <button type="submit" class="button-link">Download full context JSON</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>

                <?php if ($result['pages'] > 1): ?>
                    <div class="tablenav" style="margin-top:16px;">
                        <div class="tablenav-pages">
                            <?php
                            echo wp_kses_post(paginate_links([
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'current' => $result['page'],
                                'total' => $result['pages'],
                            ]));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        AdminWorkspace::end();
    }

    public static function clearLogs(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('wp_cliniko_debug_clear_logs');

        $store = new LogStore();
        $store->clear();

        wp_safe_redirect(add_query_arg([
            'page' => 'wp-cliniko-debug',
            'debug_cleared' => 1,
        ], admin_url('admin.php')));
        exit;
    }

    public static function exportLogs(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('wp_cliniko_debug_export_logs');

        $store = new LogStore();
        $rows = $store->export(self::readFilters($_POST), 1000);

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="cliniko-debug-logs-' . gmdate('Ymd-His') . '.json"');

        echo wp_json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function exportContext(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $logId = absint($_POST['log_id'] ?? 0);
        check_admin_referer('wp_cliniko_debug_export_context_' . $logId);

        $store = new LogStore();
        $row = $store->find($logId);
        if ($row === null) {
            wp_die('Debug log entry not found.');
        }

        $context = json_decode((string) ($row['context'] ?? ''), true);
        $download = [
            'id' => (int) ($row['id'] ?? $logId),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'event' => (string) ($row['event'] ?? ''),
            'level' => (string) ($row['level'] ?? ''),
            'trace_id' => (string) ($row['trace_id'] ?? ''),
            'method' => (string) ($row['method'] ?? ''),
            'route' => (string) ($row['route'] ?? ''),
            'target' => (string) ($row['target'] ?? ''),
            'request_kind' => (string) ($row['request_kind'] ?? ''),
            'status_code' => $row['status_code'] !== null ? (int) $row['status_code'] : null,
            'duration_ms' => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
            'message' => (string) ($row['message'] ?? ''),
            'context' => is_array($context) ? $context : (string) ($row['context'] ?? ''),
        ];

        $event = sanitize_file_name((string) ($row['event'] ?? 'debug')) ?: 'debug';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="cliniko-debug-' . $event . '-' . $logId . '.json"');

        echo wp_json_encode($download, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @param array<string,mixed>|null $source
     * @return array{channel:string,event:string,level:string,trace_id:string,route:string,search:string}
     */
    private static function readFilters(?array $source = null): array
    {
        $data = $source ?? $_GET;

        return [
            'channel' => sanitize_text_field((string) ($data['channel'] ?? '')),
            'event' => sanitize_text_field((string) ($data['event'] ?? '')),
            'level' => sanitize_text_field((string) ($data['level'] ?? '')),
            'trace_id' => sanitize_text_field((string) ($data['trace_id'] ?? '')),
            'route' => sanitize_text_field((string) ($data['route'] ?? '')),
            'search' => sanitize_text_field((string) ($data['search'] ?? '')),
        ];
    }

    private static function prettyContext(string $json): string
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $json;
        }

        $pretty = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return is_string($pretty) ? $pretty : $json;
    }

    private static function renderMetricCard(string $label, string $value): void
    {
        ?>
        <div class="cliniko-admin-card cliniko-debug-metric">
            <div class="cliniko-debug-metric__label"><?php echo esc_html($label); ?></div>
            <div class="cliniko-debug-metric__value"><?php echo esc_html($value); ?></div>
        </div>
        <?php
    }
}
