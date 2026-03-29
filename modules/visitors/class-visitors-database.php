<?php
/**
 * Visitors Database Handler
 *
 * @package PeanutSuite\Visitors
 */

namespace PeanutSuite\Visitors;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database operations for visitor tracking.
 */
class Visitors_Database {

    /**
     * Get visitors table name.
     *
     * @return string
     */
    public static function get_visitors_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_visitors';
    }

    /**
     * Get visitor events table name.
     *
     * @return string
     */
    public static function get_events_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_visitor_events';
    }

    /**
     * Create database tables.
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $visitors_table = self::get_visitors_table();
        $events_table = self::get_events_table();

        $sql = "CREATE TABLE {$visitors_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            visitor_id varchar(64) NOT NULL,
            first_seen datetime DEFAULT NULL,
            last_seen datetime DEFAULT NULL,
            total_visits int(11) DEFAULT 1,
            total_pageviews int(11) DEFAULT 0,
            device_type varchar(20) DEFAULT NULL,
            browser varchar(50) DEFAULT NULL,
            os varchar(50) DEFAULT NULL,
            country varchar(2) DEFAULT NULL,
            region varchar(100) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            customer_id bigint(20) unsigned DEFAULT NULL,
            contact_id bigint(20) unsigned DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY visitor_id (visitor_id),
            KEY email (email),
            KEY contact_id (contact_id)
        ) {$charset_collate};

        CREATE TABLE {$events_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            visitor_id varchar(64) NOT NULL,
            session_id varchar(64) DEFAULT NULL,
            event_type varchar(50) NOT NULL,
            page_url text DEFAULT NULL,
            page_title varchar(255) DEFAULT NULL,
            referrer text DEFAULT NULL,
            utm_source varchar(100) DEFAULT NULL,
            utm_medium varchar(100) DEFAULT NULL,
            utm_campaign varchar(255) DEFAULT NULL,
            utm_term varchar(255) DEFAULT NULL,
            utm_content varchar(255) DEFAULT NULL,
            custom_data longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY visitor_id (visitor_id),
            KEY session_id (session_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get or create a visitor.
     *
     * @param string $visitor_id Unique visitor identifier.
     * @param array  $data       Visitor data.
     * @return array|false
     */
    public static function get_or_create_visitor(string $visitor_id, array $data = []) {
        global $wpdb;
        $table = self::get_visitors_table();

        // Try to get existing visitor
        $visitor = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE visitor_id = %s", $visitor_id),
            ARRAY_A
        );

        if ($visitor) {
            // Update last seen and increment visits
            $wpdb->update(
                $table,
                [
                    'last_seen' => current_time('mysql'),
                    'total_visits' => $visitor['total_visits'] + 1,
                ],
                ['visitor_id' => $visitor_id],
                ['%s', '%d'],
                ['%s']
            );

            return self::get_by_visitor_id($visitor_id);
        }

        // Create new visitor
        $insert_data = array_merge([
            'visitor_id' => $visitor_id,
            'first_seen' => current_time('mysql'),
            'last_seen' => current_time('mysql'),
            'total_visits' => 1,
            'total_pageviews' => 0,
        ], $data);

        $result = $wpdb->insert($table, $insert_data);

        if ($result === false) {
            return false;
        }

        return self::get_by_visitor_id($visitor_id);
    }

    /**
     * Get visitor by visitor_id.
     *
     * @param string $visitor_id Visitor identifier.
     * @return array|null
     */
    public static function get_by_visitor_id(string $visitor_id): ?array {
        global $wpdb;
        $table = self::get_visitors_table();

        $visitor = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE visitor_id = %s", $visitor_id),
            ARRAY_A
        );

        return $visitor ?: null;
    }

    /**
     * Get visitor by ID.
     *
     * @param int $id Visitor database ID.
     * @return array|null
     */
    public static function get_by_id(int $id): ?array {
        global $wpdb;
        $table = self::get_visitors_table();

        $visitor = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id),
            ARRAY_A
        );

        return $visitor ?: null;
    }

    /**
     * Update visitor data.
     *
     * @param string $visitor_id Visitor identifier.
     * @param array  $data       Data to update.
     * @return bool
     */
    public static function update_visitor(string $visitor_id, array $data): bool {
        global $wpdb;
        $table = self::get_visitors_table();

        $result = $wpdb->update(
            $table,
            $data,
            ['visitor_id' => $visitor_id]
        );

        return $result !== false;
    }

    /**
     * Identify visitor with email.
     *
     * @param string $visitor_id Visitor identifier.
     * @param string $email      Email address.
     * @param array  $extra_data Additional data.
     * @return bool
     */
    public static function identify(string $visitor_id, string $email, array $extra_data = []): bool {
        $data = array_merge(['email' => $email], $extra_data);
        return self::update_visitor($visitor_id, $data);
    }

    /**
     * Increment pageview count.
     *
     * @param string $visitor_id Visitor identifier.
     * @return bool
     */
    public static function increment_pageviews(string $visitor_id): bool {
        global $wpdb;
        $table = self::get_visitors_table();

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET total_pageviews = total_pageviews + 1, last_seen = %s WHERE visitor_id = %s",
                current_time('mysql'),
                $visitor_id
            )
        );

        return $result !== false;
    }

    /**
     * Record a visitor event.
     *
     * @param array $data Event data.
     * @return int|false Insert ID or false on failure.
     */
    public static function record_event(array $data) {
        global $wpdb;
        $table = self::get_events_table();

        if (!empty($data['custom_data'])) {
            if (is_array($data['custom_data'])) {
                $data['custom_data'] = wp_json_encode($data['custom_data']);
            } elseif (is_string($data['custom_data'])) {
                if (json_decode($data['custom_data'], true) === null && json_last_error() !== JSON_ERROR_NONE) {
                    $data['custom_data'] = null;
                }
            }
        }

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return false;
        }

        // Increment pageview count if this is a pageview event
        if (isset($data['event_type']) && $data['event_type'] === 'pageview' && isset($data['visitor_id'])) {
            self::increment_pageviews($data['visitor_id']);
        }

        return $wpdb->insert_id;
    }

    /**
     * Get events for a visitor.
     *
     * @param string $visitor_id Visitor identifier.
     * @param array  $args       Query arguments.
     * @return array
     */
    public static function get_visitor_events(string $visitor_id, array $args = []): array {
        global $wpdb;
        $table = self::get_events_table();

        $defaults = [
            'limit' => 100,
            'offset' => 0,
            'event_type' => null,
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['visitor_id = %s'];
        $params = [$visitor_id];

        if ($args['event_type']) {
            $where[] = 'event_type = %s';
            $params[] = $args['event_type'];
        }

        $where_clause = implode(' AND ', $where);
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';

        $params[] = (int) $args['limit'];
        $params[] = (int) $args['offset'];

        $events = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY created_at {$order} LIMIT %d OFFSET %d",
                ...$params
            ),
            ARRAY_A
        );

        // Decode custom_data JSON
        foreach ($events as &$event) {
            if (!empty($event['custom_data'])) {
                $event['custom_data'] = json_decode($event['custom_data'], true);
            }
        }

        return $events ?: [];
    }

    /**
     * Get all visitors with pagination.
     *
     * @param array $args Query arguments.
     * @return array
     */
    public static function get_all(array $args = []): array {
        global $wpdb;
        $table = self::get_visitors_table();

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'search' => '',
            'order_by' => 'last_seen',
            'order' => 'DESC',
            'identified_only' => false,
        ];

        $args = wp_parse_args($args, $defaults);
        $offset = ($args['page'] - 1) * $args['per_page'];

        $where = ['1=1'];
        $params = [];

        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[] = '(email LIKE %s OR visitor_id LIKE %s)';
            $params[] = $search;
            $params[] = $search;
        }

        if ($args['identified_only']) {
            $where[] = 'email IS NOT NULL AND email != ""';
        }

        $where_clause = implode(' AND ', $where);

        $allowed_order_by = ['id', 'first_seen', 'last_seen', 'total_visits', 'total_pageviews', 'email'];
        $order_by = in_array($args['order_by'], $allowed_order_by, true) ? $args['order_by'] : 'last_seen';
        $order = $args['order'] === 'ASC' ? 'ASC' : 'DESC';

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_clause}";
        $total = $wpdb->get_var(
            empty($params) ? $count_sql : $wpdb->prepare($count_sql, ...$params)
        );

        // Get visitors
        $query_params = array_merge($params, [(int) $args['per_page'], (int) $offset]);
        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d";

        $visitors = $wpdb->get_results(
            $wpdb->prepare($sql, ...$query_params),
            ARRAY_A
        );

        return [
            'data' => $visitors ?: [],
            'total' => (int) $total,
            'total_pages' => ceil($total / $args['per_page']),
            'page' => (int) $args['page'],
            'per_page' => (int) $args['per_page'],
        ];
    }

    /**
     * Get visitor statistics.
     *
     * @return array
     */
    public static function get_stats(): array {
        global $wpdb;
        $visitors_table = self::get_visitors_table();
        $events_table = self::get_events_table();

        $today = current_time('Y-m-d');
        $week_ago = gmdate('Y-m-d', strtotime('-7 days'));

        $stats = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN email IS NOT NULL AND email != '' THEN 1 ELSE 0 END) as identified,
                    SUM(CASE WHEN DATE(first_seen) = %s THEN 1 ELSE 0 END) as today,
                    SUM(CASE WHEN DATE(first_seen) >= %s THEN 1 ELSE 0 END) as this_week,
                    SUM(total_pageviews) as total_pageviews
                FROM {$visitors_table}",
                $today,
                $week_ago
            ),
            ARRAY_A
        );

        // Get events today
        $events_today = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$events_table} WHERE DATE(created_at) = %s",
                $today
            )
        );

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'identified' => (int) ($stats['identified'] ?? 0),
            'anonymous' => (int) ($stats['total'] ?? 0) - (int) ($stats['identified'] ?? 0),
            'today' => (int) ($stats['today'] ?? 0),
            'this_week' => (int) ($stats['this_week'] ?? 0),
            'total_pageviews' => (int) ($stats['total_pageviews'] ?? 0),
            'events_today' => (int) ($events_today ?? 0),
        ];
    }

    /**
     * Delete a visitor and their events.
     *
     * @param int $id Visitor database ID.
     * @return bool
     */
    public static function delete(int $id): bool {
        global $wpdb;
        $visitors_table = self::get_visitors_table();
        $events_table = self::get_events_table();

        $visitor = self::get_by_id($id);
        if (!$visitor) {
            return false;
        }

        // Delete events first
        $wpdb->delete(
            $events_table,
            ['visitor_id' => $visitor['visitor_id']],
            ['%s']
        );

        // Delete visitor
        $result = $wpdb->delete(
            $visitors_table,
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Merge two visitors (anonymous into identified).
     *
     * @param string $from_visitor_id Anonymous visitor ID.
     * @param string $to_visitor_id   Identified visitor ID.
     * @return bool
     */
    public static function merge_visitors(string $from_visitor_id, string $to_visitor_id): bool {
        global $wpdb;
        $visitors_table = self::get_visitors_table();
        $events_table = self::get_events_table();

        $from = self::get_by_visitor_id($from_visitor_id);
        $to = self::get_by_visitor_id($to_visitor_id);

        if (!$from || !$to) {
            return false;
        }

        // Move events to the target visitor
        $wpdb->update(
            $events_table,
            ['visitor_id' => $to_visitor_id],
            ['visitor_id' => $from_visitor_id],
            ['%s'],
            ['%s']
        );

        // Update stats on target visitor
        $wpdb->update(
            $visitors_table,
            [
                'total_visits' => $to['total_visits'] + $from['total_visits'],
                'total_pageviews' => $to['total_pageviews'] + $from['total_pageviews'],
                'first_seen' => min($to['first_seen'], $from['first_seen']),
                'last_seen' => max($to['last_seen'], $from['last_seen']),
            ],
            ['visitor_id' => $to_visitor_id]
        );

        // Delete the source visitor
        $wpdb->delete(
            $visitors_table,
            ['visitor_id' => $from_visitor_id],
            ['%s']
        );

        return true;
    }
}
