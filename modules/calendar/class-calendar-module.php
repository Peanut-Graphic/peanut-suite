<?php
/**
 * Content Calendar Module
 *
 * Editorial calendar for content planning and scheduling.
 */

namespace PeanutSuite\Calendar;

if (!defined('ABSPATH')) {
    exit;
}

class Calendar_Module {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // init() is called by Peanut_Module_Manager after instantiation.
    }

    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);

        // AJAX handlers
        add_action('wp_ajax_peanut_save_calendar_event', [$this, 'ajax_save_event']);
    }

    /**
     * AJAX: Save calendar event
     */
    public function ajax_save_event(): void {
        check_ajax_referer('peanut_calendar', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'peanut_calendar_events';

        $data = [
            'title' => sanitize_text_field($_POST['title'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'type' => sanitize_text_field($_POST['type'] ?? 'other'),
            'scheduled_date' => sanitize_text_field($_POST['scheduled_date'] ?? ''),
        ];

        $id = isset($_POST['id']) && $_POST['id'] !== '' ? intval($_POST['id']) : 0;

        if ($id > 0) {
            $wpdb->update($table, $data, ['id' => $id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            $id = $wpdb->insert_id;
        }

        wp_send_json_success(['id' => $id]);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route(PEANUT_API_NAMESPACE, '/calendar/events', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_events'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_event'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/calendar/events/(?P<id>\d+)', [
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_event'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_event'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/calendar/posts', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_scheduled_posts'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/calendar/ideas', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_ideas'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_idea'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/calendar/ideas/(?P<id>\d+)', [
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_idea'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_idea'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);
    }

    public function admin_permission(): bool {
        return current_user_can('edit_posts');
    }

    /**
     * Get calendar events
     */
    public function get_events(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $start = $request->get_param('start') ?: date('Y-m-01');
        $end = $request->get_param('end') ?: date('Y-m-t');

        $table = $wpdb->prefix . 'peanut_calendar_events';

        $events = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM $table
            WHERE event_date BETWEEN %s AND %s
            ORDER BY event_date, event_time
        ", $start, $end), ARRAY_A);

        // Also get scheduled WordPress posts
        $posts = $this->get_scheduled_posts_data($start, $end);

        // Combine and format for calendar
        $calendar_events = [];

        foreach ($events as $event) {
            $calendar_events[] = [
                'id' => 'event_' . $event['id'],
                'title' => $event['title'],
                'start' => $event['event_date'] . ($event['event_time'] ? 'T' . $event['event_time'] : ''),
                'type' => $event['event_type'],
                'color' => $this->get_event_color($event['event_type']),
                'description' => $event['description'],
                'status' => $event['status'],
            ];
        }

        foreach ($posts as $post) {
            $calendar_events[] = [
                'id' => 'post_' . $post->ID,
                'title' => $post->post_title,
                'start' => $post->post_date,
                'type' => 'wordpress_post',
                'color' => '#3b82f6',
                'post_type' => $post->post_type,
                'status' => $post->post_status,
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
            ];
        }

        return new \WP_REST_Response(['events' => $calendar_events], 200);
    }

    /**
     * Get scheduled posts data
     */
    private function get_scheduled_posts_data(string $start, string $end): array {
        return get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => ['future', 'publish', 'draft'],
            'date_query' => [
                [
                    'after' => $start,
                    'before' => $end,
                    'inclusive' => true,
                ],
            ],
            'posts_per_page' => 100,
        ]);
    }

    /**
     * Get scheduled posts via API
     */
    public function get_scheduled_posts(\WP_REST_Request $request): \WP_REST_Response {
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'future',
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        $formatted = array_map(function($post) {
            return [
                'id' => $post->ID,
                'title' => $post->post_title,
                'type' => $post->post_type,
                'scheduled_for' => $post->post_date,
                'edit_url' => get_edit_post_link($post->ID, 'raw'),
            ];
        }, $posts);

        return new \WP_REST_Response(['posts' => $formatted], 200);
    }

    /**
     * Create calendar event
     */
    public function create_event(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_calendar_events';

        $wpdb->insert($table, [
            'title' => sanitize_text_field($request->get_param('title')),
            'description' => sanitize_textarea_field($request->get_param('description') ?: ''),
            'event_type' => sanitize_text_field($request->get_param('event_type') ?: 'content'),
            'event_date' => sanitize_text_field($request->get_param('event_date')),
            'event_time' => sanitize_text_field($request->get_param('event_time') ?: ''),
            'status' => 'planned',
            'assigned_to' => (int) $request->get_param('assigned_to'),
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);

        return new \WP_REST_Response(['id' => $wpdb->insert_id], 201);
    }

    /**
     * Update calendar event
     */
    public function update_event(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'peanut_calendar_events';

        $data = [];

        if ($request->has_param('title')) {
            $data['title'] = sanitize_text_field($request->get_param('title'));
        }
        if ($request->has_param('description')) {
            $data['description'] = sanitize_textarea_field($request->get_param('description'));
        }
        if ($request->has_param('event_type')) {
            $data['event_type'] = sanitize_text_field($request->get_param('event_type'));
        }
        if ($request->has_param('event_date')) {
            $data['event_date'] = sanitize_text_field($request->get_param('event_date'));
        }
        if ($request->has_param('event_time')) {
            $data['event_time'] = sanitize_text_field($request->get_param('event_time'));
        }
        if ($request->has_param('status')) {
            $data['status'] = sanitize_text_field($request->get_param('status'));
        }
        if ($request->has_param('assigned_to')) {
            $data['assigned_to'] = (int) $request->get_param('assigned_to');
        }

        if (!empty($data)) {
            $wpdb->update($table, $data, ['id' => $id]);
        }

        return new \WP_REST_Response(['message' => 'Updated'], 200);
    }

    /**
     * Delete calendar event
     */
    public function delete_event(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');

        $wpdb->delete($wpdb->prefix . 'peanut_calendar_events', ['id' => $id]);

        return new \WP_REST_Response(['message' => 'Deleted'], 200);
    }

    /**
     * Get content ideas
     */
    public function get_ideas(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_content_ideas';

        $status = $request->get_param('status');
        $where = $status ? $wpdb->prepare("WHERE status = %s", $status) : "";

        $ideas = $wpdb->get_results("
            SELECT * FROM $table $where ORDER BY priority DESC, created_at DESC
        ", ARRAY_A);

        return new \WP_REST_Response(['ideas' => $ideas], 200);
    }

    /**
     * Create content idea
     */
    public function create_idea(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_content_ideas';

        $wpdb->insert($table, [
            'title' => sanitize_text_field($request->get_param('title')),
            'description' => sanitize_textarea_field($request->get_param('description') ?: ''),
            'content_type' => sanitize_text_field($request->get_param('content_type') ?: 'blog_post'),
            'keywords' => sanitize_text_field($request->get_param('keywords') ?: ''),
            'priority' => (int) ($request->get_param('priority') ?: 0),
            'status' => 'idea',
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql'),
        ]);

        return new \WP_REST_Response(['id' => $wpdb->insert_id], 201);
    }

    /**
     * Update content idea
     */
    public function update_idea(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'peanut_content_ideas';

        $data = [];

        if ($request->has_param('title')) {
            $data['title'] = sanitize_text_field($request->get_param('title'));
        }
        if ($request->has_param('description')) {
            $data['description'] = sanitize_textarea_field($request->get_param('description'));
        }
        if ($request->has_param('content_type')) {
            $data['content_type'] = sanitize_text_field($request->get_param('content_type'));
        }
        if ($request->has_param('keywords')) {
            $data['keywords'] = sanitize_text_field($request->get_param('keywords'));
        }
        if ($request->has_param('priority')) {
            $data['priority'] = (int) $request->get_param('priority');
        }
        if ($request->has_param('status')) {
            $data['status'] = sanitize_text_field($request->get_param('status'));
        }

        if (!empty($data)) {
            $wpdb->update($table, $data, ['id' => $id]);
        }

        return new \WP_REST_Response(['message' => 'Updated'], 200);
    }

    /**
     * Delete content idea
     */
    public function delete_idea(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');

        $wpdb->delete($wpdb->prefix . 'peanut_content_ideas', ['id' => $id]);

        return new \WP_REST_Response(['message' => 'Deleted'], 200);
    }

    /**
     * Get event color by type
     */
    private function get_event_color(string $type): string {
        return match($type) {
            'content' => '#3b82f6',      // Blue
            'social' => '#8b5cf6',       // Purple
            'email' => '#22c55e',        // Green
            'campaign' => '#f59e0b',     // Amber
            'meeting' => '#ef4444',      // Red
            'deadline' => '#dc2626',     // Dark red
            default => '#6b7280',        // Gray
        };
    }

    /**
     * Create database tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $events_table = $wpdb->prefix . 'peanut_calendar_events';
        $ideas_table = $wpdb->prefix . 'peanut_content_ideas';

        $sql = "
        CREATE TABLE $events_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description text,
            event_type varchar(50) DEFAULT 'content',
            event_date date NOT NULL,
            event_time time DEFAULT NULL,
            status varchar(20) DEFAULT 'planned',
            assigned_to bigint(20) UNSIGNED DEFAULT 0,
            created_by bigint(20) UNSIGNED DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_date (event_date),
            KEY status (status)
        ) $charset;

        CREATE TABLE $ideas_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            description text,
            content_type varchar(50) DEFAULT 'blog_post',
            keywords varchar(500) DEFAULT '',
            priority int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'idea',
            created_by bigint(20) UNSIGNED DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY priority (priority)
        ) $charset;
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
