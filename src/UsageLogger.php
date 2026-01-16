<?php
namespace Tainacan\AI;

/**
 * Usage and statistics logger
 *
 * Records all analyses performed for usage tracking,
 * costs and auditing.
 */
class UsageLogger {

    private string $table_name;
    private static bool $table_checked = false;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'tainacan_ai_logs';
    }

    /**
     * Log an entry
     */
    public function log(array $data): bool {
        $options = \Tainacan_AI::get_options();

        // Check if logging is enabled (default: true if not defined)
        // Use array_key_exists to distinguish between false and undefined
        if (array_key_exists('log_enabled', $options) && !$options['log_enabled']) {
            return true;
        }

        // Ensure table exists and has all required columns
        $this->maybe_create_table();
        
        // Ensure provider column exists (migration)
        $this->maybe_add_provider_column();

        global $wpdb;

        $defaults = [
            'user_id' => get_current_user_id(),
            'item_id' => null,
            'collection_id' => null,
            'attachment_id' => 0,
            'document_type' => 'unknown',
            'model' => 'gpt-4o',
            'provider' => 'openai',
            'tokens_used' => 0,
            'cost' => 0,
            'status' => 'success',
            'error_message' => null,
            'created_at' => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        // Ensure provider is defined
        if (empty($data['provider'])) {
            $data['provider'] = $this->detect_provider_from_model($data['model']);
        }

        $result = $wpdb->insert(
            $this->table_name,
            $data,
            ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%f', '%s', '%s', '%s']
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if ($result === false) {
                error_log('[TainacanAI] Failed to insert log: ' . $wpdb->last_error);
                error_log('[TainacanAI] Table: ' . $this->table_name);
                error_log('[TainacanAI] Data: ' . print_r($data, true));
            } else {
                error_log('[TainacanAI] Log recorded successfully. Tokens: ' . ($data['tokens_used'] ?? 0));
            }
        }

        return $result !== false;
    }

    /**
     * Create logs table if it doesn't exist
     */
    private function maybe_create_table(): void {
        if (self::$table_checked) {
            return;
        }

        global $wpdb;

        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name)
        );

        if (!$table_exists) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[TainacanAI] Creating logs table: ' . $this->table_name);
            }

            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                item_id bigint(20) unsigned DEFAULT NULL,
                collection_id bigint(20) unsigned DEFAULT NULL,
                attachment_id bigint(20) unsigned NOT NULL,
                document_type varchar(50) NOT NULL,
                model varchar(50) NOT NULL,
                provider varchar(50) NOT NULL DEFAULT 'openai',
                tokens_used int(11) DEFAULT 0,
                cost decimal(10,6) DEFAULT 0,
                status varchar(20) NOT NULL DEFAULT 'success',
                error_message text DEFAULT NULL,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY user_id (user_id),
                KEY item_id (item_id),
                KEY collection_id (collection_id),
                KEY provider (provider),
                KEY created_at (created_at)
            ) $charset_collate;";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);

            // Check if table was created
            $table_created = $wpdb->get_var(
                $wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name)
            );

            if (defined('WP_DEBUG') && WP_DEBUG) {
                if ($table_created) {
                    error_log('[TainacanAI] Logs table created successfully');
                } else {
                    error_log('[TainacanAI] Failed to create logs table. Last error: ' . $wpdb->last_error);
                }
            }
        }

        // Check if provider column needs to be added (migration)
        $this->maybe_add_provider_column();

        self::$table_checked = true;
    }

    /**
     * Get general statistics
     */
    public function get_stats(string $period = 'month'): array {
        global $wpdb;

        // Ensure table and columns exist
        $this->maybe_create_table();
        $this->maybe_add_provider_column();

        $date_condition = $this->get_date_condition($period);

        // Total analyses
        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'success' {$date_condition}"
        );

        // Total tokens
        $tokens = $wpdb->get_var(
            "SELECT SUM(tokens_used) FROM {$this->table_name} WHERE status = 'success' {$date_condition}"
        );

        // Total cost
        $cost = $wpdb->get_var(
            "SELECT SUM(cost) FROM {$this->table_name} WHERE status = 'success' {$date_condition}"
        );

        // Errors
        $errors = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'error' {$date_condition}"
        );

        // By document type
        $by_type = $wpdb->get_results(
            "SELECT document_type, COUNT(*) as count
            FROM {$this->table_name}
            WHERE status = 'success' {$date_condition}
            GROUP BY document_type",
            ARRAY_A
        );

        // By model
        $by_model = $wpdb->get_results(
            "SELECT model, COUNT(*) as count, SUM(tokens_used) as tokens, SUM(cost) as cost
            FROM {$this->table_name}
            WHERE status = 'success' {$date_condition}
            GROUP BY model",
            ARRAY_A
        );

        // By provider (only if column exists)
        $by_provider = [];
        if ($this->has_provider_column()) {
            $by_provider = $wpdb->get_results(
                "SELECT provider, COUNT(*) as count, SUM(tokens_used) as tokens, SUM(cost) as cost
                FROM {$this->table_name}
                WHERE status = 'success' {$date_condition}
                GROUP BY provider",
                ARRAY_A
            );
        }

        // By collection
        $by_collection = $wpdb->get_results(
            "SELECT collection_id, COUNT(*) as count
            FROM {$this->table_name}
            WHERE status = 'success' AND collection_id IS NOT NULL {$date_condition}
            GROUP BY collection_id
            ORDER BY count DESC
            LIMIT 10",
            ARRAY_A
        );

        // Active users
        $active_users = $wpdb->get_var(
            "SELECT COUNT(DISTINCT user_id) FROM {$this->table_name} WHERE status = 'success' {$date_condition}"
        );

        return [
            'total_analyses' => (int) $total,
            'total_tokens' => (int) $tokens,
            'total_cost' => (float) $cost,
            'total_errors' => (int) $errors,
            'success_rate' => $total > 0 ? round(($total / ($total + $errors)) * 100, 1) : 0,
            'by_type' => $this->format_by_type($by_type),
            'by_model' => $by_model,
            'by_provider' => $this->format_by_provider($by_provider),
            'by_collection' => $this->format_by_collection($by_collection),
            'active_users' => (int) $active_users,
            'avg_tokens_per_analysis' => $total > 0 ? round($tokens / $total) : 0,
            'avg_cost_per_analysis' => $total > 0 ? round($cost / $total, 6) : 0,
        ];
    }

    /**
     * Get usage history
     */
    public function get_history(int $limit = 50, int $offset = 0, array $filters = []): array {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['collection_id'])) {
            $where[] = 'collection_id = %d';
            $params[] = $filters['collection_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        if (!empty($filters['document_type'])) {
            $where[] = 'document_type = %s';
            $params[] = $filters['document_type'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'created_at >= %s';
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'created_at <= %s';
            $params[] = $filters['date_to'];
        }

        $where_clause = implode(' AND ', $where);

        $query = "SELECT * FROM {$this->table_name}
                  WHERE {$where_clause}
                  ORDER BY created_at DESC
                  LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $results = $wpdb->get_results(
            $wpdb->prepare($query, $params),
            ARRAY_A
        );

        // Add extra information
        foreach ($results as &$row) {
            $row['user_name'] = $this->get_user_name($row['user_id']);
            $row['collection_name'] = $this->get_collection_name($row['collection_id']);
            $row['item_title'] = $this->get_item_title($row['item_id']);
        }

        return $results;
    }

    /**
     * Get total records (for pagination)
     */
    public function get_total(array $filters = []): int {
        global $wpdb;

        $where = ['1=1'];
        $params = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = %d';
            $params[] = $filters['user_id'];
        }

        if (!empty($filters['collection_id'])) {
            $where[] = 'collection_id = %d';
            $params[] = $filters['collection_id'];
        }

        if (!empty($filters['status'])) {
            $where[] = 'status = %s';
            $params[] = $filters['status'];
        }

        $where_clause = implode(' AND ', $where);

        if (empty($params)) {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}");
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_clause}", $params)
        );
    }

    /**
     * Get daily usage (for charts)
     */
    public function get_daily_usage(int $days = 30): array {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) as date,
                        COUNT(*) as analyses,
                        SUM(tokens_used) as tokens,
                        SUM(cost) as cost
                 FROM {$this->table_name}
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                       AND status = 'success'
                 GROUP BY DATE(created_at)
                 ORDER BY date ASC",
                $days
            ),
            ARRAY_A
        );

        return $results;
    }

    /**
     * Clean old logs
     */
    public function cleanup(int $days_to_keep = 90): int {
        global $wpdb;

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days_to_keep
            )
        );
    }

    /**
     * Export logs to CSV
     */
    public function export_csv(array $filters = []): string {
        $logs = $this->get_history(10000, 0, $filters);

        $csv = "ID,Date,User,Collection,Item,Type,Model,Tokens,Cost,Status,Error\n";

        foreach ($logs as $log) {
            $csv .= sprintf(
                "%d,%s,%s,%s,%s,%s,%s,%d,%.6f,%s,%s\n",
                $log['id'],
                $log['created_at'],
                $log['user_name'],
                $log['collection_name'] ?? '-',
                $log['item_title'] ?? '-',
                $log['document_type'],
                $log['model'],
                $log['tokens_used'],
                $log['cost'],
                $log['status'],
                str_replace(["\n", "\r", ","], [" ", " ", ";"], $log['error_message'] ?? '')
            );
        }

        return $csv;
    }

    /**
     * SQL condition for period
     */
    private function get_date_condition(string $period): string {
        switch ($period) {
            case 'today':
                return "AND DATE(created_at) = CURDATE()";
            case 'week':
                return "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            case 'month':
                return "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            case 'year':
                return "AND created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR)";
            case 'all':
            default:
                return "";
        }
    }

    /**
     * Format data by type
     */
    private function format_by_type(array $data): array {
        $formatted = [];
        $labels = [
            'image' => __('Images', 'tainacan-ai'),
            'pdf' => __('PDFs', 'tainacan-ai'),
            'text' => __('Texts', 'tainacan-ai'),
            'unknown' => __('Others', 'tainacan-ai'),
        ];

        foreach ($data as $row) {
            $formatted[] = [
                'type' => $row['document_type'],
                'label' => $labels[$row['document_type']] ?? $row['document_type'],
                'count' => (int) $row['count'],
            ];
        }

        return $formatted;
    }

    /**
     * Format data by provider
     */
    private function format_by_provider(array $data): array {
        $formatted = [];
        $labels = [
            'openai' => 'OpenAI (ChatGPT)',
            'gemini' => 'Google Gemini',
            'deepseek' => 'DeepSeek',
            'ollama' => 'Ollama (Local)',
        ];

        foreach ($data as $row) {
            $provider = $row['provider'] ?? 'openai';
            $formatted[] = [
                'provider' => $provider,
                'label' => $labels[$provider] ?? $provider,
                'count' => (int) $row['count'],
                'tokens' => (int) ($row['tokens'] ?? 0),
                'cost' => (float) ($row['cost'] ?? 0),
            ];
        }

        return $formatted;
    }

    /**
     * Format data by collection
     */
    private function format_by_collection(array $data): array {
        foreach ($data as &$row) {
            $row['name'] = $this->get_collection_name($row['collection_id']);
            $row['count'] = (int) $row['count'];
        }

        return $data;
    }

    /**
     * Get user name
     */
    private function get_user_name(int $user_id): string {
        $user = get_userdata($user_id);
        return $user ? $user->display_name : __('Unknown user', 'tainacan-ai');
    }

    /**
     * Get collection name
     */
    private function get_collection_name(?int $collection_id): ?string {
        if (!$collection_id || !class_exists('\Tainacan\Repositories\Collections')) {
            return null;
        }

        $collections_repo = \Tainacan\Repositories\Collections::get_instance();
        $collection = $collections_repo->fetch($collection_id);

        return $collection ? $collection->get_name() : null;
    }

    /**
     * Get item title
     */
    private function get_item_title(?int $item_id): ?string {
        if (!$item_id) {
            return null;
        }

        return get_the_title($item_id) ?: null;
    }

    /**
     * Detect provider from model name
     */
    private function detect_provider_from_model(string $model): string {
        if (strpos($model, 'gpt') !== false) {
            return 'openai';
        }
        if (strpos($model, 'gemini') !== false) {
            return 'gemini';
        }
        if (strpos($model, 'deepseek') !== false) {
            return 'deepseek';
        }
        // Common Ollama models
        $ollama_models = ['llama', 'mistral', 'mixtral', 'phi', 'gemma', 'qwen', 'codellama', 'vicuna', 'llava', 'bakllava', 'moondream'];
        foreach ($ollama_models as $ollama_model) {
            if (strpos($model, $ollama_model) !== false) {
                return 'ollama';
            }
        }
        return 'openai';
    }

    /**
     * Check and add provider column if it doesn't exist
     */
    public function maybe_add_provider_column(): void {
        global $wpdb;

        // Check if table exists first
        $table_exists = $wpdb->get_var(
            $wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name)
        );

        if (!$table_exists) {
            return;
        }

        // More reliable column check using INFORMATION_SCHEMA
        $column_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'provider'",
                DB_NAME,
                $this->table_name
            )
        );

        if (empty($column_exists) || $column_exists == 0) {
            $result = $wpdb->query(
                "ALTER TABLE {$this->table_name} ADD COLUMN provider varchar(50) NOT NULL DEFAULT 'openai' AFTER model"
            );

            if ($result !== false) {
                // Add index for provider column
                $wpdb->query("ALTER TABLE {$this->table_name} ADD INDEX provider (provider)");

                // Update existing records
                $wpdb->query("UPDATE {$this->table_name} SET provider = 'openai' WHERE model LIKE '%gpt%'");
                $wpdb->query("UPDATE {$this->table_name} SET provider = 'gemini' WHERE model LIKE '%gemini%'");
                $wpdb->query("UPDATE {$this->table_name} SET provider = 'deepseek' WHERE model LIKE '%deepseek%'");
                $wpdb->query("UPDATE {$this->table_name} SET provider = 'ollama' WHERE model LIKE '%llama%' OR model LIKE '%mistral%' OR model LIKE '%phi%' OR model LIKE '%gemma%' OR model LIKE '%qwen%'");

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[TainacanAI] Added provider column to logs table');
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[TainacanAI] Failed to add provider column: ' . $wpdb->last_error);
                }
            }
        }
    }

    /**
     * Check if provider column exists
     */
    private function has_provider_column(): bool {
        global $wpdb;

        $column_exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = %s 
                AND TABLE_NAME = %s 
                AND COLUMN_NAME = 'provider'",
                DB_NAME,
                $this->table_name
            )
        );

        return !empty($column_exists) && $column_exists > 0;
    }
}
