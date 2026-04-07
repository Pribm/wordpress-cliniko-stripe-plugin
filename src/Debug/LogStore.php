<?php

namespace App\Debug;

if (!defined('ABSPATH')) {
    exit;
}

class LogStore
{
    private const SCHEMA_VERSION = '1';
    private const SCHEMA_OPTION = 'wp_cliniko_debug_log_schema_version';
    private const PRUNE_TRANSIENT = 'wp_cliniko_debug_log_pruned_at';

    private static bool $ensured = false;

    public function ensureTable(): bool
    {
        if (self::$ensured) {
            return true;
        }

        global $wpdb;
        $table = $this->table();
        $installedVersion = (string) get_option(self::SCHEMA_OPTION, '');
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));

        if ($installedVersion === self::SCHEMA_VERSION && $exists === $table) {
            self::$ensured = true;
            return true;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $sql = "
        CREATE TABLE {$table} (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          created_at DATETIME NOT NULL,
          trace_id VARCHAR(32) NOT NULL DEFAULT '',
          channel VARCHAR(32) NOT NULL DEFAULT '',
          level VARCHAR(16) NOT NULL DEFAULT '',
          event VARCHAR(64) NOT NULL DEFAULT '',
          method VARCHAR(16) NOT NULL DEFAULT '',
          route VARCHAR(191) NOT NULL DEFAULT '',
          target VARCHAR(191) NOT NULL DEFAULT '',
          request_kind VARCHAR(32) NOT NULL DEFAULT '',
          status_code SMALLINT NULL,
          duration_ms INT NULL,
          message TEXT NULL,
          context LONGTEXT NULL,
          PRIMARY KEY  (id),
          KEY idx_created_at (created_at),
          KEY idx_trace_id (trace_id),
          KEY idx_channel (channel),
          KEY idx_level (level),
          KEY idx_route (route(100))
        ) {$charset};";

        dbDelta($sql);
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        self::$ensured = true;

        return true;
    }

    /**
     * @param array<string,mixed> $entry
     */
    public function insert(array $entry): void
    {
        if (!$this->ensureTable()) {
            return;
        }

        $this->maybePrune();

        global $wpdb;
        $wpdb->insert(
            $this->table(),
            [
                'created_at' => gmdate('Y-m-d H:i:s'),
                'trace_id' => substr((string) ($entry['trace_id'] ?? ''), 0, 32),
                'channel' => substr((string) ($entry['channel'] ?? ''), 0, 32),
                'level' => substr((string) ($entry['level'] ?? 'info'), 0, 16),
                'event' => substr((string) ($entry['event'] ?? ''), 0, 64),
                'method' => substr(strtoupper((string) ($entry['method'] ?? '')), 0, 16),
                'route' => substr((string) ($entry['route'] ?? ''), 0, 191),
                'target' => substr((string) ($entry['target'] ?? ''), 0, 191),
                'request_kind' => substr((string) ($entry['request_kind'] ?? ''), 0, 32),
                'status_code' => isset($entry['status_code']) ? (int) $entry['status_code'] : null,
                'duration_ms' => isset($entry['duration_ms']) ? (int) $entry['duration_ms'] : null,
                'message' => LogSanitizer::sanitizeString((string) ($entry['message'] ?? '')),
                'context' => LogSanitizer::encodeContext(
                    is_array($entry['context'] ?? null) ? $entry['context'] : []
                ),
            ],
            [
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
            ]
        );
    }

    /**
     * @param array<string,string> $filters
     * @return array{rows:array<int,array<string,mixed>>,total:int,per_page:int,page:int,pages:int}
     */
    public function query(array $filters, int $page = 1, int $perPage = 50): array
    {
        if (!$this->ensureTable()) {
            return [
                'rows' => [],
                'total' => 0,
                'per_page' => $perPage,
                'page' => $page,
                'pages' => 0,
            ];
        }

        global $wpdb;
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        [$whereSql, $params] = $this->buildWhere($filters);
        $arrayType = 'ARRAY_A';

        $countSql = "SELECT COUNT(*) FROM {$this->table()} {$whereSql}";
        $total = (int) $wpdb->get_var($this->prepare($countSql, $params));

        $dataSql = "SELECT * FROM {$this->table()} {$whereSql} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results(
            $this->prepare($dataSql, array_merge($params, [$perPage, $offset])),
            $arrayType
        );

        return [
            'rows' => is_array($rows) ? $rows : [],
            'total' => $total,
            'per_page' => $perPage,
            'page' => $page,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * @return array{total:int,errors:int,warnings:int,slow:int}
     */
    public function summary(): array
    {
        if (!$this->ensureTable()) {
            return [
                'total' => 0,
                'errors' => 0,
                'warnings' => 0,
                'slow' => 0,
            ];
        }

        global $wpdb;
        $arrayType = 'ARRAY_A';
        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) AS errors,
                SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) AS warnings,
                SUM(CASE WHEN duration_ms IS NOT NULL AND duration_ms >= 1000 THEN 1 ELSE 0 END) AS slow
             FROM {$this->table()}",
            $arrayType
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'errors' => (int) ($row['errors'] ?? 0),
            'warnings' => (int) ($row['warnings'] ?? 0),
            'slow' => (int) ($row['slow'] ?? 0),
        ];
    }

    /**
     * @return array<int,string>
     */
    public function distinctChannels(): array
    {
        if (!$this->ensureTable()) {
            return [];
        }

        global $wpdb;
        $rows = $wpdb->get_col("SELECT DISTINCT channel FROM {$this->table()} ORDER BY channel ASC");
        return is_array($rows) ? array_values(array_filter(array_map('strval', $rows))) : [];
    }

    public function clear(): void
    {
        if (!$this->ensureTable()) {
            return;
        }

        global $wpdb;
        $wpdb->query("DELETE FROM {$this->table()}");
    }

    /**
     * @param array<string,string> $filters
     * @return array<int,array<string,mixed>>
     */
    public function export(array $filters, int $limit = 1000): array
    {
        $result = $this->query($filters, 1, max(1, min(5000, $limit)));
        return $result['rows'];
    }

    public function table(): string
    {
        global $wpdb;
        return "{$wpdb->prefix}cliniko_debug_logs";
    }

    /**
     * @param array<string,string> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildWhere(array $filters): array
    {
        $clauses = ['WHERE 1=1'];
        $params = [];

        $channel = trim((string) ($filters['channel'] ?? ''));
        if ($channel !== '') {
            $clauses[] = 'AND channel = %s';
            $params[] = $channel;
        }

        $level = trim((string) ($filters['level'] ?? ''));
        if ($level !== '') {
            $clauses[] = 'AND level = %s';
            $params[] = $level;
        }

        $traceId = trim((string) ($filters['trace_id'] ?? ''));
        if ($traceId !== '') {
            $clauses[] = 'AND trace_id = %s';
            $params[] = $traceId;
        }

        $route = trim((string) ($filters['route'] ?? ''));
        if ($route !== '') {
            $clauses[] = 'AND route LIKE %s';
            $params[] = '%' . $route . '%';
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $clauses[] = 'AND (message LIKE %s OR target LIKE %s OR route LIKE %s OR trace_id LIKE %s)';
            $needle = '%' . $search . '%';
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
            $params[] = $needle;
        }

        return [implode(' ', $clauses), $params];
    }

    /**
     * @param array<int,mixed> $params
     */
    private function prepare(string $sql, array $params): string
    {
        global $wpdb;
        return !empty($params) ? $wpdb->prepare($sql, $params) : $sql;
    }

    private function maybePrune(): void
    {
        if (get_transient(self::PRUNE_TRANSIENT)) {
            return;
        }

        set_transient(self::PRUNE_TRANSIENT, 1, 3600);

        global $wpdb;
        $table = $this->table();
        $retentionCutoff = gmdate('Y-m-d H:i:s', time() - (Settings::retentionDays() * 86400));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $retentionCutoff));

        $maxRows = Settings::maxRows();
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count <= $maxRows) {
            return;
        }

        $excess = $count - $maxRows;
        $wpdb->query(
            "DELETE FROM {$table}
             ORDER BY id ASC
             LIMIT " . (int) $excess
        );
    }
}
