<?php
/**
 * Contacts Module
 *
 * Simple CRM for managing leads and contacts.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Contacts_Module {

    /**
     * Contact statuses
     */
    public const STATUSES = [
        'lead' => 'Lead',
        'contacted' => 'Contacted',
        'qualified' => 'Qualified',
        'customer' => 'Customer',
        'inactive' => 'Inactive',
    ];

    /**
     * Initialize module
     */
    public function init(): void {
        // Load dependencies
        $this->load_dependencies();

        // Register API routes
        add_action('peanut_register_routes', [$this, 'register_routes']);

        // Register cron jobs
        add_action('peanut_ml_lead_scoring_train', [$this, 'train_lead_scoring_model']);

        // Schedule ML model training if not already scheduled
        if (!wp_next_scheduled('peanut_ml_lead_scoring_train')) {
            wp_schedule_event(time(), 'weekly', 'peanut_ml_lead_scoring_train');
        }

        // Dashboard stats
        add_filter('peanut_dashboard_stats', [$this, 'add_dashboard_stats'], 10, 2);

        // Hook into FormFlow if available
        add_action('peanut_conversion', [$this, 'create_from_conversion'], 10, 1);
    }

    /**
     * Load module dependencies.
     */
    private function load_dependencies(): void {
        require_once __DIR__ . '/class-ml-lead-scoring.php';
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        require_once __DIR__ . '/api/class-contacts-controller.php';
        require_once __DIR__ . '/api/class-ml-lead-scoring-controller.php';

        $controller = new Contacts_Controller();
        $controller->register_routes();

        // Register ML lead scoring API routes
        $scoring_controller = new ML_Lead_Scoring_Controller();
        $scoring_controller->register_routes();
    }

    /**
     * Train the lead scoring model.
     */
    public function train_lead_scoring_model(): void {
        $scorer = new \Peanut_ML_Lead_Scoring();
        $result = $scorer->train_model();

        if ($result) {
            error_log('Peanut Suite ML: Lead scoring model training completed');
        } else {
            error_log('Peanut Suite ML: Lead scoring model training failed');
        }
    }

    /**
     * Create contact from conversion (FormFlow integration)
     */
    public function create_from_conversion(array $data): void {
        if (empty($data['email'])) {
            return;
        }

        global $wpdb;
        $table = Peanut_Database::contacts_table();

        // Check if exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE email = %s",
            $data['email']
        ));

        if ($exists) {
            // Update last activity
            $wpdb->update($table, [
                'last_activity_at' => current_time('mysql'),
                'score' => $wpdb->get_var($wpdb->prepare("SELECT score FROM $table WHERE id = %d", $exists)) + 10,
            ], ['id' => $exists]);

            // Add activity
            $this->add_activity($exists, 'conversion', 'Converted via FormFlow');
            return;
        }

        // Create new contact
        $attribution = $data['attribution'] ?? [];

        $wpdb->insert($table, [
            'user_id' => get_current_user_id() ?: 1, // Default to admin if no user
            'email' => sanitize_email($data['email']),
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($data['last_name'] ?? ''),
            'source' => 'formflow',
            'utm_source' => $attribution['utm_source'] ?? null,
            'utm_medium' => $attribution['utm_medium'] ?? null,
            'utm_campaign' => $attribution['utm_campaign'] ?? null,
            'status' => 'lead',
            'score' => 10,
            'last_activity_at' => current_time('mysql'),
        ]);

        $contact_id = $wpdb->insert_id;

        if ($contact_id) {
            $this->add_activity($contact_id, 'created', 'Contact created from FormFlow conversion');
        }
    }

    /**
     * Add activity to contact
     */
    public function add_activity(int $contact_id, string $type, string $description, array $metadata = []): void {
        global $wpdb;
        $table = Peanut_Database::contact_activities_table();

        $wpdb->insert($table, [
            'contact_id' => $contact_id,
            'type' => $type,
            'description' => $description,
            'metadata' => !empty($metadata) ? wp_json_encode($metadata) : null,
        ]);
    }

    /**
     * Calculate contact score
     */
    public static function calculate_score(array $contact): int {
        $score = 0;

        // Has email
        if (!empty($contact['email'])) $score += 10;

        // Has name
        if (!empty($contact['first_name'])) $score += 5;
        if (!empty($contact['last_name'])) $score += 5;

        // Has phone
        if (!empty($contact['phone'])) $score += 10;

        // Has company
        if (!empty($contact['company'])) $score += 10;

        // Status bonus
        $status_scores = [
            'lead' => 0,
            'contacted' => 10,
            'qualified' => 25,
            'customer' => 50,
        ];
        $score += $status_scores[$contact['status']] ?? 0;

        // Recent activity bonus
        if (!empty($contact['last_activity_at'])) {
            $days_ago = (time() - strtotime($contact['last_activity_at'])) / DAY_IN_SECONDS;
            if ($days_ago < 7) $score += 15;
            elseif ($days_ago < 30) $score += 10;
            elseif ($days_ago < 90) $score += 5;
        }

        return $score;
    }

    /**
     * Add stats to dashboard
     */
    public function add_dashboard_stats(array $stats, string $period): array {
        global $wpdb;
        $table = Peanut_Database::contacts_table();
        $user_id = get_current_user_id();

        // Total contacts
        $stats['contacts_total'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        ));

        // By status
        $stats['contacts_by_status'] = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count
             FROM $table WHERE user_id = %d
             GROUP BY status",
            $user_id
        ), ARRAY_A);

        // New in period
        $date_clause = $this->get_date_clause($period);
        $stats['contacts_new'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d $date_clause",
            $user_id
        ));

        // Top sources
        $stats['contacts_top_sources'] = $wpdb->get_results($wpdb->prepare(
            "SELECT COALESCE(source, 'direct') as source, COUNT(*) as count
             FROM $table WHERE user_id = %d
             GROUP BY source
             ORDER BY count DESC LIMIT 5",
            $user_id
        ), ARRAY_A);

        return $stats;
    }

    private function get_date_clause(string $period): string {
        return match ($period) {
            '7d' => "AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30d' => "AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            '90d' => "AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
            default => '',
        };
    }

    /**
     * Deactivation hook.
     */
    public static function deactivate(): void {
        // Clear scheduled events
        wp_clear_scheduled_hook('peanut_ml_lead_scoring_train');

        // Unschedule ML lead scoring training if class is available
        if (class_exists('Peanut_ML_Lead_Scoring')) {
            \Peanut_ML_Lead_Scoring::unschedule_training();
        }
    }
}
