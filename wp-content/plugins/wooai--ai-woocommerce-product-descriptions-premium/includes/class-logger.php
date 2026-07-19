<?php
/**
 * Plugin logger — stores API errors and events in a custom DB table.
 * The table is queryable via WordPress admin UI (Logs tab).
 */

defined('ABSPATH') || exit;

class WooAI_Desc_Logger {

    const TABLE = 'wooai_desc_logs';

    /**
     * Get the full table name with prefix.
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Create or update the logs table.
     */
    public static function create_table(): void {
        global $wpdb;
        $table_name = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            level VARCHAR(10) NOT NULL DEFAULT 'info',
            source VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            context TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_level (level),
            INDEX idx_source (source),
            INDEX idx_created (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Write a log entry.
     *
     * @param string $level   debug | info | warning | error
     * @param string $source  e.g. 'ai_provider', 'product_handler', 'settings'
     * @param string $message Human-readable description
     * @param array  $context Optional structured data (api_url, model, http_status, etc.)
     */
    public static function log(string $level, string $source, string $message, array $context = []): void {
        global $wpdb;

        $wpdb->insert(
            self::table(),
            [
                'level'      => $level,
                'source'     => $source,
                'message'    => $message,
                'context'    => !empty($context) ? wp_json_encode($context) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Convenience: log an error.
     */
    public static function error(string $source, string $message, array $context = []): void {
        self::log('error', $source, $message, $context);
    }

    /**
     * Convenience: log a warning.
     */
    public static function warning(string $source, string $message, array $context = []): void {
        self::log('warning', $source, $message, $context);
    }

    /**
     * Convenience: log an info.
     */
    public static function info(string $source, string $message, array $context = []): void {
        self::log('info', $source, $message, $context);
    }

    /**
     * Query logs with pagination and filtering.
     *
     * @param array $args [
     *   'page'     => int (1-indexed)
     *   'per_page' => int (default 50)
     *   'level'    => string|null (filter by level)
     *   'source'   => string|null (filter by source)
     *   'search'   => string|null (search in message)
     * ]
     * @return array ['items' => [...], 'total' => int, 'page' => int, 'per_page' => int]
     */
    public static function query(array $args = []): array {
        global $wpdb;

        $page     = max(1, intval($args['page'] ?? 1));
        $per_page = min(200, max(1, intval($args['per_page'] ?? 50)));
        $offset   = ($page - 1) * $per_page;

        $where   = ['1=1'];
        $params  = [];

        if (!empty($args['level'])) {
            $where[]  = 'level = %s';
            $params[] = $args['level'];
        }
        if (!empty($args['source'])) {
            $where[]  = 'source = %s';
            $params[] = $args['source'];
        }
        if (!empty($args['search'])) {
            $where[]  = 'message LIKE %s';
            $params[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }

        $where_sql = implode(' AND ', $where);

        $total = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wooai_desc_logs WHERE {$where_sql}", $params)
        );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wooai_desc_logs WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                array_merge($params, [$per_page, $offset])
            ),
            ARRAY_A
        );

        // Decode context JSON
        if ($items) {
            foreach ($items as &$row) {
                $row['context'] = $row['context'] ? json_decode($row['context'], true) : null;
            }
        }

        return [
            'items'    => $items ?: [],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
        ];
    }

    /**
     * Get distinct source values for filter dropdowns.
     */
    public static function get_sources(): array {
        global $wpdb;
        return $wpdb->get_col("SELECT DISTINCT source FROM {$wpdb->prefix}wooai_desc_logs ORDER BY source");
    }

    /**
     * Get distinct level values for filter dropdowns.
     */
    public static function get_levels(): array {
        global $wpdb;
        return $wpdb->get_col("SELECT DISTINCT level FROM {$wpdb->prefix}wooai_desc_logs ORDER BY FIELD(level, 'error','warning','info','debug')");
    }

    /**
     * Delete old logs (older than N days).
     */
    public static function clean(int $days = 30): int {
        global $wpdb;
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}wooai_desc_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
    }
}
