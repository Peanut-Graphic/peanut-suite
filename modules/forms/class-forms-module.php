<?php
/**
 * Form Analytics Module
 *
 * Track form submissions, abandonment, and field analytics.
 */

namespace PeanutSuite\Forms;

if (!defined('ABSPATH')) {
    exit;
}

class Forms_Module {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->init();
    }

    private function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_tracking_script']);

        // Hook into popular form plugins
        add_action('wpforms_process_complete', [$this, 'track_wpforms_submission'], 10, 4);
        add_action('gform_after_submission', [$this, 'track_gravity_forms_submission'], 10, 2);
        add_filter('wpcf7_mail_sent', [$this, 'track_cf7_submission']);
        add_action('ninja_forms_after_submission', [$this, 'track_ninja_forms_submission']);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route(PEANUT_API_NAMESPACE, '/forms', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_forms'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/forms/(?P<id>[a-zA-Z0-9_-]+)/stats', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_form_stats'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/forms/track/view', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'track_view'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/forms/track/interaction', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'track_interaction'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/forms/track/abandon', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'track_abandonment'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/forms/fields/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_field_analytics'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);
    }

    public function admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Enqueue tracking script
     */
    public function enqueue_tracking_script(): void {
        if (!$this->is_tracking_enabled()) {
            return;
        }

        wp_enqueue_script(
            'peanut-form-tracking',
            PEANUT_PLUGIN_URL . 'modules/forms/assets/form-tracking.js',
            [],
            PEANUT_VERSION,
            true
        );

        wp_localize_script('peanut-form-tracking', 'peanutForms', [
            'apiUrl' => rest_url(PEANUT_API_NAMESPACE . '/forms/track'),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Check if tracking is enabled
     */
    private function is_tracking_enabled(): bool {
        return (bool) get_option('peanut_form_tracking_enabled', true);
    }

    /**
     * Get all tracked forms
     */
    public function get_forms(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_form_stats';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table uses $wpdb->prefix which is not user-controlled
        $forms = $wpdb->get_results("
            SELECT
                form_id,
                form_name,
                form_type,
                SUM(views) as total_views,
                SUM(submissions) as total_submissions,
                SUM(abandonments) as total_abandonments,
                MAX(last_activity) as last_activity
            FROM $table
            GROUP BY form_id, form_name, form_type
            ORDER BY total_views DESC
        ", ARRAY_A);

        // Calculate conversion rates
        foreach ($forms as &$form) {
            $views = (int) $form['total_views'];
            $submissions = (int) $form['total_submissions'];
            $form['conversion_rate'] = $views > 0 ? round(($submissions / $views) * 100, 2) : 0;
            $form['abandonment_rate'] = $views > 0
                ? round(((int)$form['total_abandonments'] / $views) * 100, 2)
                : 0;
        }

        return new \WP_REST_Response(['forms' => $forms], 200);
    }

    /**
     * Get form stats
     */
    public function get_form_stats(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $form_id = sanitize_text_field($request->get_param('id'));
        $days = (int) ($request->get_param('days') ?: 30);
        $table = $wpdb->prefix . 'peanut_form_stats';

        // Get daily stats
        $daily = $wpdb->get_results($wpdb->prepare("
            SELECT
                date,
                views,
                submissions,
                abandonments
            FROM $table
            WHERE form_id = %s
            AND date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            ORDER BY date ASC
        ", $form_id, $days), ARRAY_A);

        // Get totals
        $totals = $wpdb->get_row($wpdb->prepare("
            SELECT
                SUM(views) as views,
                SUM(submissions) as submissions,
                SUM(abandonments) as abandonments,
                AVG(avg_completion_time) as avg_completion_time
            FROM $table
            WHERE form_id = %s
            AND date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
        ", $form_id, $days));

        // Get field drop-off data
        $field_stats = $wpdb->get_results($wpdb->prepare("
            SELECT
                field_name,
                SUM(interactions) as interactions,
                SUM(drop_offs) as drop_offs,
                AVG(avg_time_spent) as avg_time
            FROM {$wpdb->prefix}peanut_form_field_stats
            WHERE form_id = %s
            AND date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
            GROUP BY field_name
            ORDER BY interactions DESC
        ", $form_id, $days), ARRAY_A);

        return new \WP_REST_Response([
            'form_id' => $form_id,
            'daily' => $daily,
            'totals' => [
                'views' => (int) ($totals->views ?? 0),
                'submissions' => (int) ($totals->submissions ?? 0),
                'abandonments' => (int) ($totals->abandonments ?? 0),
                'conversion_rate' => $totals->views > 0
                    ? round(($totals->submissions / $totals->views) * 100, 2)
                    : 0,
                'avg_completion_time' => round($totals->avg_completion_time ?? 0, 1),
            ],
            'field_stats' => $field_stats,
        ], 200);
    }

    /**
     * Track form view
     */
    public function track_view(\WP_REST_Request $request): \WP_REST_Response {
        if (!Peanut_Security::check_rate_limit('form_submit', 10, 60)) {
            return new \WP_REST_Response(['message' => 'Rate limit exceeded'], 429);
        }

        $form_id = sanitize_text_field($request->get_param('form_id'));
        $form_name = sanitize_text_field($request->get_param('form_name') ?: $form_id);
        $form_type = sanitize_text_field($request->get_param('form_type') ?: 'unknown');

        $this->increment_stat($form_id, $form_name, $form_type, 'views');

        return new \WP_REST_Response(['tracked' => true], 200);
    }

    /**
     * Track field interaction
     */
    public function track_interaction(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_form_field_stats';

        $form_id = sanitize_text_field($request->get_param('form_id'));
        $field_name = sanitize_text_field($request->get_param('field_name'));
        $time_spent = (float) $request->get_param('time_spent');
        $date = date('Y-m-d');

        // Upsert field stat
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE form_id = %s AND field_name = %s AND date = %s",
            $form_id, $field_name, $date
        ));

        if ($existing) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET
                    interactions = interactions + 1,
                    total_time_spent = total_time_spent + %f,
                    avg_time_spent = (total_time_spent + %f) / (interactions + 1)
                 WHERE id = %d",
                $time_spent, $time_spent, $existing
            ));
        } else {
            $wpdb->insert($table, [
                'form_id' => $form_id,
                'field_name' => $field_name,
                'date' => $date,
                'interactions' => 1,
                'total_time_spent' => $time_spent,
                'avg_time_spent' => $time_spent,
            ]);
        }

        return new \WP_REST_Response(['tracked' => true], 200);
    }

    /**
     * Track form abandonment
     */
    public function track_abandonment(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $form_id = sanitize_text_field($request->get_param('form_id'));
        $form_name = sanitize_text_field($request->get_param('form_name') ?: $form_id);
        $form_type = sanitize_text_field($request->get_param('form_type') ?: 'unknown');
        $last_field = sanitize_text_field($request->get_param('last_field') ?: '');

        $this->increment_stat($form_id, $form_name, $form_type, 'abandonments');

        // Record drop-off field
        if ($last_field) {
            $table = $wpdb->prefix . 'peanut_form_field_stats';
            $date = date('Y-m-d');

            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET drop_offs = drop_offs + 1
                 WHERE form_id = %s AND field_name = %s AND date = %s",
                $form_id, $last_field, $date
            ));
        }

        return new \WP_REST_Response(['tracked' => true], 200);
    }

    /**
     * Track form submission
     */
    public function track_submission(string $form_id, string $form_name, string $form_type, float $completion_time = 0): void {
        $this->increment_stat($form_id, $form_name, $form_type, 'submissions', $completion_time);
    }

    /**
     * Increment form stat
     */
    private function increment_stat(string $form_id, string $form_name, string $form_type, string $stat, float $completion_time = 0): void {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_form_stats';
        $date = date('Y-m-d');

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE form_id = %s AND date = %s",
            $form_id, $date
        ));

        if ($existing) {
            $update_sql = "UPDATE $table SET $stat = $stat + 1, last_activity = NOW()";

            if ($stat === 'submissions' && $completion_time > 0) {
                $update_sql .= ", total_completion_time = total_completion_time + $completion_time,
                                  avg_completion_time = (total_completion_time + $completion_time) / (submissions + 1)";
            }

            $update_sql .= " WHERE id = %d";
            $wpdb->query($wpdb->prepare($update_sql, $existing));
        } else {
            $wpdb->insert($table, [
                'form_id' => $form_id,
                'form_name' => $form_name,
                'form_type' => $form_type,
                'date' => $date,
                $stat => 1,
                'total_completion_time' => $completion_time,
                'avg_completion_time' => $completion_time,
                'last_activity' => current_time('mysql'),
            ]);
        }
    }

    /**
     * Get field analytics
     */
    public function get_field_analytics(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $form_id = sanitize_text_field($request->get_param('id'));
        $table = $wpdb->prefix . 'peanut_form_field_stats';

        $fields = $wpdb->get_results($wpdb->prepare("
            SELECT
                field_name,
                SUM(interactions) as total_interactions,
                SUM(drop_offs) as total_drop_offs,
                AVG(avg_time_spent) as avg_time_spent
            FROM $table
            WHERE form_id = %s
            GROUP BY field_name
            ORDER BY total_interactions DESC
        ", $form_id), ARRAY_A);

        // Calculate drop-off rates
        $total_starts = $fields[0]['total_interactions'] ?? 1;

        foreach ($fields as &$field) {
            $field['drop_off_rate'] = $total_starts > 0
                ? round(($field['total_drop_offs'] / $total_starts) * 100, 2)
                : 0;
        }

        return new \WP_REST_Response(['fields' => $fields], 200);
    }

    // Form plugin integrations

    public function track_wpforms_submission($fields, $entry, $form_data, $entry_id): void {
        $this->track_submission(
            'wpforms_' . $form_data['id'],
            $form_data['settings']['form_title'] ?? 'WPForms',
            'wpforms'
        );
    }

    public function track_gravity_forms_submission($entry, $form): void {
        $this->track_submission(
            'gf_' . $form['id'],
            $form['title'] ?? 'Gravity Forms',
            'gravity_forms'
        );
    }

    public function track_cf7_submission($contact_form): bool {
        $this->track_submission(
            'cf7_' . $contact_form->id(),
            $contact_form->title(),
            'contact_form_7'
        );
        return true;
    }

    public function track_ninja_forms_submission($form_data): void {
        $form_id = $form_data['form_id'] ?? 0;
        $this->track_submission(
            'nf_' . $form_id,
            $form_data['settings']['title'] ?? 'Ninja Forms',
            'ninja_forms'
        );
    }

    /**
     * Create database tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $stats_table = $wpdb->prefix . 'peanut_form_stats';
        $field_table = $wpdb->prefix . 'peanut_form_field_stats';

        $sql = "
        CREATE TABLE $stats_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id varchar(100) NOT NULL,
            form_name varchar(255) DEFAULT '',
            form_type varchar(50) DEFAULT 'unknown',
            date date NOT NULL,
            views int(11) DEFAULT 0,
            submissions int(11) DEFAULT 0,
            abandonments int(11) DEFAULT 0,
            total_completion_time float DEFAULT 0,
            avg_completion_time float DEFAULT 0,
            last_activity datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY form_date (form_id, date),
            KEY form_id (form_id),
            KEY date (date)
        ) $charset;

        CREATE TABLE $field_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_id varchar(100) NOT NULL,
            field_name varchar(100) NOT NULL,
            date date NOT NULL,
            interactions int(11) DEFAULT 0,
            drop_offs int(11) DEFAULT 0,
            total_time_spent float DEFAULT 0,
            avg_time_spent float DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY form_field_date (form_id, field_name, date),
            KEY form_id (form_id)
        ) $charset;
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
