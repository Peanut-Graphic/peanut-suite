<?php
/**
 * Analytics Module
 *
 * Unified analytics dashboard with aggregated stats.
 *
 * @package PeanutSuite\Analytics
 */

namespace PeanutSuite\Analytics;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main analytics module.
 */
class Analytics_Module {

    /**
     * Module instance.
     *
     * @var Analytics_Module|null
     */
    private static ?Analytics_Module $instance = null;

    /**
     * REST controller.
     *
     * @var Analytics_Controller|null
     */
    private ?Analytics_Controller $controller = null;

    /**
     * Get module instance.
     *
     * @return Analytics_Module
     */
    public static function instance(): Analytics_Module {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->load_dependencies();
    }

    /**
     * Load module dependencies.
     */
    private function load_dependencies(): void {
        $base_path = PEANUT_PATH . 'modules/analytics/';

        require_once $base_path . 'class-analytics-database.php';
        require_once $base_path . 'class-analytics-aggregator.php';
        require_once $base_path . 'class-ml-visitor-segmentation.php';
        require_once $base_path . 'api/class-analytics-controller.php';
        require_once $base_path . 'api/class-ml-segmentation-controller.php';
    }

    /**
     * Initialize the module.
     */
    public function init(): void {
        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_api_routes']);

        // Register cron jobs
        add_action('peanut_aggregate_stats', [$this, 'run_aggregation']);
        add_action('peanut_analytics_cleanup', [$this, 'run_cleanup']);
        add_action('peanut_ml_segmentation_train', [$this, 'train_segmentation_model']);

        // Schedule cron jobs
        if (!wp_next_scheduled('peanut_aggregate_stats')) {
            wp_schedule_event(time(), 'peanut_fifteen_minutes', 'peanut_aggregate_stats');
        }

        if (!wp_next_scheduled('peanut_analytics_cleanup')) {
            wp_schedule_event(time(), 'daily', 'peanut_analytics_cleanup');
        }

        // Schedule ML model training if not already scheduled
        if (!wp_next_scheduled('peanut_ml_segmentation_train')) {
            wp_schedule_event(time(), 'weekly', 'peanut_ml_segmentation_train');
        }

        // Hook into events for real-time stat updates
        add_action('peanut_visitor_event', [$this, 'handle_visitor_event'], 20, 3);
        add_action('peanut_conversion_created', [$this, 'handle_conversion'], 20, 2);
        add_action('peanut_webhook_received', [$this, 'handle_webhook'], 20, 3);
    }

    /**
     * Register API routes.
     */
    public function register_api_routes(): void {
        $this->controller = new Analytics_Controller();
        $this->controller->register_routes();

        // Register ML visitor segmentation API routes
        $segmentation_controller = new ML_Segmentation_Controller();
        $segmentation_controller->register_routes();
    }

    /**
     * Run stats aggregation.
     */
    public function run_aggregation(): void {
        // Aggregate yesterday's stats
        $yesterday = gmdate('Y-m-d', strtotime('-1 day'));
        $result = Analytics_Aggregator::aggregate($yesterday);

        // Also aggregate today's partial stats
        $today = current_time('Y-m-d');
        Analytics_Aggregator::aggregate($today);

        error_log('Peanut Suite Analytics: Aggregated stats for ' . $yesterday);
    }

    /**
     * Run cleanup task.
     */
    public function run_cleanup(): void {
        $settings = get_option('peanut_settings', []);
        $retention_days = $settings['analytics_retention_days'] ?? 365;

        $deleted = Analytics_Database::cleanup_old_stats((int) $retention_days);

        if ($deleted > 0) {
            error_log("Peanut Suite: Cleaned up {$deleted} old analytics records");
        }
    }

    /**
     * Train the visitor segmentation model.
     */
    public function train_segmentation_model(): void {
        $segmentation = new \Peanut_ML_Visitor_Segmentation();
        $result = $segmentation->train_model();

        if ($result) {
            error_log('Peanut Suite ML: Visitor segmentation model training completed');
        } else {
            error_log('Peanut Suite ML: Visitor segmentation model training failed');
        }
    }

    /**
     * Handle visitor event for real-time stats.
     *
     * @param string $visitor_id Visitor identifier.
     * @param string $event_type Event type.
     * @param array  $event_data Event data.
     */
    public function handle_visitor_event(string $visitor_id, string $event_type, array $event_data): void {
        $today = current_time('Y-m-d');

        // Increment pageviews
        if ($event_type === 'pageview') {
            Analytics_Database::increment_stat($today, 'visitors', 'pageviews');

            // Track by source
            $source = $event_data['utm_source'] ?? 'direct';
            Analytics_Database::increment_stat($today, 'visitors', 'pageviews', 1, 0, 'source', $source);
        }
    }

    /**
     * Handle conversion for real-time stats.
     *
     * @param int    $conversion_id Conversion ID.
     * @param string $visitor_id    Visitor identifier.
     */
    public function handle_conversion(int $conversion_id, string $visitor_id): void {
        $today = current_time('Y-m-d');
        Analytics_Database::increment_stat($today, 'conversions', 'total');
    }

    /**
     * Handle webhook for real-time stats.
     *
     * @param string $source  Webhook source.
     * @param string $event   Event type.
     * @param array  $payload Payload data.
     */
    public function handle_webhook(string $source, string $event, array $payload): void {
        $today = current_time('Y-m-d');
        Analytics_Database::increment_stat($today, 'webhooks', 'received');
        Analytics_Database::increment_stat($today, 'webhooks', 'received', 1, 0, 'source', $source);
    }

    /**
     * Get module info.
     *
     * @return array
     */
    public static function get_info(): array {
        return [
            'id' => 'analytics',
            'name' => 'Analytics Dashboard',
            'description' => 'Unified analytics dashboard with aggregated stats and visualizations',
            'icon' => 'bar-chart-2',
            'tier' => 'pro',
            'version' => '1.0.0',
        ];
    }

    /**
     * Activation hook.
     */
    public static function activate(): void {
        Analytics_Database::create_tables();

        // Add default settings
        $settings = get_option('peanut_settings', []);
        if (!isset($settings['analytics_retention_days'])) {
            $settings['analytics_retention_days'] = 365;
            update_option('peanut_settings', $settings);
        }

        // Backfill last 7 days
        $from = gmdate('Y-m-d', strtotime('-7 days'));
        $to = gmdate('Y-m-d', strtotime('-1 day'));
        Analytics_Aggregator::backfill($from, $to);
    }

    /**
     * Deactivation hook.
     */
    public static function deactivate(): void {
        // Clear scheduled events
        wp_clear_scheduled_hook('peanut_aggregate_stats');
        wp_clear_scheduled_hook('peanut_analytics_cleanup');
        wp_clear_scheduled_hook('peanut_ml_segmentation_train');

        // Unschedule ML segmentation training if class is available
        if (class_exists('Peanut_ML_Visitor_Segmentation')) {
            \Peanut_ML_Visitor_Segmentation::unschedule_training();
        }
    }
}
