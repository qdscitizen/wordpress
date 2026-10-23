<?php
/**
 * Plugin logger — stores API errors and events in a custom DB table.
 *
 * 本类管理插件日志系统。
 * 所有 AI API 调用错误、警告、信息都写入自定义 MySQL 表（wp_wooai_desc_logs）。
 * 记录的信息包括：级别、来源、消息内容和带上下文的 JSON 数据（如 API URL / 模型 / HTTP 状态码）。
 *
 * 日志在前端 Logs 页面可查询和筛选。
 * 自动清理：超过 30 天的日志会被删除（保留机制）。
 */

defined('ABSPATH') || exit;

class WooAI_Desc_Logger {

    /** @var string 不带前缀的表名（前缀由 $wpdb->prefix 动态拼接） */
    const TABLE = 'wooai_desc_logs';

    /**
     * 获取完整的表名（含 WordPress 前缀）。
     * @return string
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /**
     * 创建/更新日志表。
     * 插件激活时（register_activation_hook）自动调用。
     * 使用 dbDelta() 保证幂等性——表已存在则不会重复创建。
     *
     * 表结构：
     *   id          BIGINT UNSIGNED AUTO_INCREMENT (主键)
     *   level       VARCHAR(10) — debug / info / warning / error
     *   source      VARCHAR(100) — ai_provider / product_handler / settings
     *   message     TEXT — 人类可读的描述
     *   context     TEXT — 可选的结构化 JSON 数据
     *   created_at  DATETIME — 记录时间
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
     * 写入一条日志记录。
     *
     * @param string $level   日志级别：debug / info / warning / error
     * @param string $source  来源标识：ai_provider, product_handler, settings
     * @param string $message 人类可读的描述文本
     * @param array  $context 可选的结构化数据（api_url, model, http_status 等），自动 JSON 序列化
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

    // ── 便捷方法 ──

    public static function error(string $source, string $message, array $context = []): void {
        self::log('error', $source, $message, $context);
    }

    public static function warning(string $source, string $message, array $context = []): void {
        self::log('warning', $source, $message, $context);
    }

    public static function info(string $source, string $message, array $context = []): void {
        self::log('info', $source, $message, $context);
    }

    /**
     * 查询日志（支持分页和筛选）。
     *
     * @param array $args [
     *   'page'     => int (1-indexed, 默认 1)
     *   'per_page' => int (默认 50, 上限 200)
     *   'level'    => string|null (按级别筛选)
     *   'source'   => string|null (按来源筛选)
     *   'search'   => string|null (搜索 message 字段)
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

        // 查询总数（用于分页）
        $total = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wooai_desc_logs WHERE {$where_sql}", $params)
        );

        // 查询当前页数据（倒序，最新的在前）
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wooai_desc_logs WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                array_merge($params, [$per_page, $offset])
            ),
            ARRAY_A
        );

        // 解码 context 字段（JSON string → array）
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
     * 获取所有不同的 source 值（供前端筛选下拉框使用）。
     */
    public static function get_sources(): array {
        global $wpdb;
        return $wpdb->get_col("SELECT DISTINCT source FROM {$wpdb->prefix}wooai_desc_logs ORDER BY source");
    }

    /**
     * 获取所有不同的 level 值（供前端筛选下拉框使用）。
     */
    public static function get_levels(): array {
        global $wpdb;
        return $wpdb->get_col("SELECT DISTINCT level FROM {$wpdb->prefix}wooai_desc_logs ORDER BY FIELD(level, 'error','warning','info','debug')");
    }

    /**
     * 清除 N 天前的旧日志。
     * 使用方式：WooAI_Desc_Logger::clean(30);
     *
     * @param int $days 保留天数（默认 30 天前的日志删除）
     * @return int 删除的记录条数
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
