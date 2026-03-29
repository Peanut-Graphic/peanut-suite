<?php
/**
 * Webhooks Database
 *
 * Manages database tables for webhook storage.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Webhooks_Database {

    /**
     * Table prefix for webhooks module
     */
    public static function prefix(): string {
        global $wpdb;
        return $wpdb->prefix . PEANUT_TABLE_PREFIX;
    }

    /**
     * Get webhooks received table name
     */
    public static function webhooks_table(): string {
        return self::prefix() . 'webhooks_received';
    }

    /**
     * Create database tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Webhooks received table
        $sql = "CREATE TABLE " . self::webhooks_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source varchar(50) NOT NULL,
            event varchar(100) NOT NULL,
            payload longtext NOT NULL,
            signature varchar(255) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            processed_at datetime DEFAULT NULL,
            status enum('pending','processing','processed','failed') DEFAULT 'pending',
            error_message text DEFAULT NULL,
            retry_count int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source (source),
            KEY event (event),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;";

        dbDelta($sql);
    }

    /**
     * Drop database tables
     */
    public static function drop_tables(): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", self::webhooks_table()));
    }

    /**
     * Insert a webhook record
     */
    public static function insert(array $data): int|false {
        global $wpdb;

        $defaults = [
            'source' => '',
            'event' => '',
            'payload' => '{}',
            'signature' => null,
            'ip_address' => null,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        // Ensure payload is JSON string
        if (is_array($data['payload'])) {
            $data['payload'] = wp_json_encode($data['payload']);
        }

        $result = $wpdb->insert(self::webhooks_table(), $data);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Update webhook status
     */
    public static function update_status(int $id, string $status, ?string $error = null): bool {
        global $wpdb;

        $data = ['status' => $status];

        if ($status === 'processed') {
            $data['processed_at'] = current_time('mysql');
        }

        if ($error !== null) {
            $data['error_message'] = $error;
        }

        return $wpdb->update(self::webhooks_table(), $data, ['id' => $id]) !== false;
    }

    /**
     * Increment retry count
     */
    public static function increment_retry(int $id): bool {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "UPDATE " . self::webhooks_table() . " SET retry_count = retry_count + 1 WHERE id = %d",
            $id
        )) !== false;
    }

    /**
     * Get pending webhooks for processing
     */
    public static function get_pending(int $limit = 50): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::webhooks_table() . "
             WHERE status IN ('pending', 'failed')
             AND retry_count < 3
             ORDER BY created_at ASC
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    /**
     * Get webhook by ID
     */
    public static function get(int $id): ?array {
        global $wpdb;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::webhooks_table() . " WHERE id = %d",
            $id
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Get webhooks with pagination and filters
     */
    public static function get_all(array $args = []): array {
        global $wpdb;

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'source' => '',
            'event' => '',
            'status' => '',
            'search' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $params = [];

        if (!empty($args['source'])) {
            $where[] = 'source = %s';
            $params[] = $args['source'];
        }

        if (!empty($args['event'])) {
            $where[] = 'event = %s';
            $params[] = $args['event'];
        }

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $params[] = $args['status'];
        }

        if (!empty($args['search'])) {
            $like = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(event LIKE %s OR payload LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        // Allowed columns for ordering
        $allowed_orderby = ['created_at', 'source', 'event', 'status'];
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM " . self::webhooks_table() . " WHERE $where_sql";
        $total = (int) $wpdb->get_var(
            !empty($params) ? $wpdb->prepare($count_sql, ...$params) : $count_sql
        );

        // Get items
        $offset = ($args['page'] - 1) * $args['per_page'];
        $query = "SELECT * FROM " . self::webhooks_table() . "
                  WHERE $where_sql
                  ORDER BY $orderby $order
                  LIMIT %d OFFSET %d";

        $query_params = array_merge($params, [$args['per_page'], $offset]);
        $items = $wpdb->get_results($wpdb->prepare($query, ...$query_params), ARRAY_A);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $args['page'],
            'per_page' => $args['per_page'],
        ];
    }

    /**
     * Get webhook statistics
     */
    public static function get_stats(): array {
        global $wpdb;
        $table = self::webhooks_table();

        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM $table",
            ARRAY_A
        );

        // Get today's count
        $today = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE DATE(created_at) = %s",
            current_time('Y-m-d')
        ));

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'pending' => (int) ($stats['pending'] ?? 0),
            'processing' => (int) ($stats['processing'] ?? 0),
            'processed' => (int) ($stats['processed'] ?? 0),
            'failed' => (int) ($stats['failed'] ?? 0),
            'today' => (int) $today,
        ];
    }

    /**
     * Cleanup old webhooks
     */
    public static function cleanup(int $days = 30): int {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM " . self::webhooks_table() . "
             WHERE status = 'processed'
             AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    /**
     * Get distinct sources
     */
    public static function get_sources(): array {
        global $wpdb;

        return $wpdb->get_col(
            "SELECT DISTINCT source FROM " . self::webhooks_table() . " ORDER BY source ASC"
        );
    }

    /**
     * Get distinct events
     */
    public static function get_events(): array {
        global $wpdb;

        return $wpdb->get_col(
            "SELECT DISTINCT event FROM " . self::webhooks_table() . " ORDER BY event ASC"
        );
    }
}
