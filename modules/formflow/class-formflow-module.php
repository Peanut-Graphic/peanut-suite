<?php
/**
 * FormFlow Integration Module
 *
 * Receives and processes events from FormFlow Lite for unified
 * analytics, attribution, and visitor tracking in Peanut Suite.
 *
 * @package Peanut_Suite
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class FormFlow_Module {

    /**
     * Module version
     */
    public const VERSION = '1.0.0';

    /**
     * Initialize the module
     */
    public function init(): void {
        // Load dependencies
        $this->load_dependencies();

        // Register API routes
        add_action('peanut_register_routes', [$this, 'register_routes']);

        // Listen for FormFlow events via WordPress actions
        add_action('peanut_conversion', [$this, 'handle_conversion'], 10, 1);
        add_action('peanut_form_view', [$this, 'handle_form_view'], 10, 2);
        add_action('peanut_utm_captured', [$this, 'handle_utm_captured'], 10, 2);

        // Add to dashboard
        add_filter('peanut_dashboard_stats', [$this, 'add_dashboard_stats'], 10, 2);

        // Add FormFlow data to visitor details
        add_filter('peanut_visitor_details', [$this, 'enhance_visitor_details'], 10, 2);
    }

    /**
     * Load module dependencies
     */
    private function load_dependencies(): void {
        require_once __DIR__ . '/class-formflow-database.php';
        require_once __DIR__ . '/class-formflow-processor.php';
    }

    /**
     * Register API routes
     */
    public function register_routes(): void {
        require_once __DIR__ . '/api/class-formflow-controller.php';
        $controller = new FormFlow_Controller();
        $controller->register_routes();
    }

    /**
     * Handle conversion event from FormFlow
     *
     * @param array $data Conversion data
     */
    public function handle_conversion(array $data): void {
        $db = new FormFlow_Database();

        // Store the conversion
        $db->record_submission([
            'visitor_id' => $data['visitor_id'] ?? null,
            'instance_id' => $data['form_id'] ?? null,
            'submission_id' => $data['submission_id'] ?? null,
            'email' => $data['email'] ?? null,
            'status' => 'completed',
            'utm_source' => $data['utm_source'] ?? null,
            'utm_medium' => $data['utm_medium'] ?? null,
            'utm_campaign' => $data['utm_campaign'] ?? null,
            'conversion_type' => $data['conversion_type'] ?? 'enrollment',
            'metadata' => $data['metadata'] ?? [],
        ]);

        // Update attribution if visitors module is active
        if (class_exists('Visitors_Database')) {
            $visitors_db = new \PeanutSuite\Visitors\Visitors_Database();
            $visitors_db->record_conversion($data['visitor_id'] ?? '', 'formflow', $data);
        }

        // Log the conversion
        do_action('peanut_log', 'info', 'FormFlow conversion recorded', [
            'source' => $data['source'] ?? 'formflow-lite',
            'submission_id' => $data['submission_id'] ?? null,
        ]);
    }

    /**
     * Handle form view event
     *
     * @param int $instance_id Instance ID
     * @param string $visitor_id Visitor ID
     */
    public function handle_form_view(int $instance_id, string $visitor_id): void {
        $db = new FormFlow_Database();
        $db->record_view($instance_id, $visitor_id);
    }

    /**
     * Handle UTM captured event
     *
     * @param array $utm_data UTM parameters
     * @param string $visitor_id Visitor ID
     */
    public function handle_utm_captured(array $utm_data, string $visitor_id): void {
        // Update visitor's UTM data in the visitors module
        if (class_exists('Visitors_Database') && $visitor_id) {
            $visitors_db = new \PeanutSuite\Visitors\Visitors_Database();
            $visitors_db->update_visitor_attribution($visitor_id, $utm_data);
        }
    }

    /**
     * Add FormFlow stats to dashboard
     *
     * @param array $stats Current stats
     * @param array $date_range Date range
     * @return array Enhanced stats
     */
    public function add_dashboard_stats(array $stats, array $date_range): array {
        $db = new FormFlow_Database();

        $formflow_stats = $db->get_stats(
            $date_range['start'] ?? date('Y-m-d', strtotime('-30 days')),
            $date_range['end'] ?? date('Y-m-d')
        );

        $stats['formflow'] = [
            'label' => 'FormFlow',
            'icon' => 'forms',
            'items' => [
                [
                    'label' => 'Form Views',
                    'value' => $formflow_stats['views'] ?? 0,
                ],
                [
                    'label' => 'Submissions',
                    'value' => $formflow_stats['submissions'] ?? 0,
                ],
                [
                    'label' => 'Completion Rate',
                    'value' => ($formflow_stats['completion_rate'] ?? 0) . '%',
                ],
            ],
        ];

        return $stats;
    }

    /**
     * Enhance visitor details with FormFlow data
     *
     * @param array $details Visitor details
     * @param string $visitor_id Visitor ID
     * @return array Enhanced details
     */
    public function enhance_visitor_details(array $details, string $visitor_id): array {
        $db = new FormFlow_Database();

        $formflow_data = $db->get_visitor_submissions($visitor_id);

        if (!empty($formflow_data)) {
            $details['formflow'] = [
                'submissions' => $formflow_data,
                'total_submissions' => count($formflow_data),
                'last_submission' => $formflow_data[0] ?? null,
            ];
        }

        return $details;
    }

    /**
     * Check if FormFlow Lite is active
     *
     * @return bool
     */
    public static function is_formflow_active(): bool {
        return defined('FFFL_VERSION') || class_exists('FFFL\\Plugin');
    }

    /**
     * Get the webhook secret for verifying FormFlow requests
     *
     * @return string|null
     */
    public static function get_webhook_secret(): ?string {
        // FormFlow stores the secret when auto-configuring
        return get_option('fffl_peanut_webhook_secret', null);
    }
}
