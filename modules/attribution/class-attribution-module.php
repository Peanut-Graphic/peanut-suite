<?php
/**
 * Attribution Module
 *
 * Multi-touch attribution modeling for marketing analytics.
 *
 * @package PeanutSuite\Attribution
 */

namespace PeanutSuite\Attribution;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main attribution module.
 */
class Attribution_Module {

    /**
     * Module instance.
     *
     * @var Attribution_Module|null
     */
    private static ?Attribution_Module $instance = null;

    /**
     * REST controller.
     *
     * @var Attribution_Controller|null
     */
    private ?Attribution_Controller $controller = null;

    /**
     * Get module instance.
     *
     * @return Attribution_Module
     */
    public static function instance(): Attribution_Module {
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
        $base_path = PEANUT_PATH . 'modules/attribution/';

        require_once $base_path . 'class-attribution-database.php';
        require_once $base_path . 'class-attribution-models.php';
        require_once $base_path . 'class-attribution-calculator.php';
        require_once $base_path . 'class-markov-attribution.php';
        require_once $base_path . 'api/class-attribution-controller.php';
    }

    /**
     * Initialize the module.
     */
    public function init(): void {
        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_api_routes']);

        // Register Markov attribution model
        add_filter('peanut_attribution_models', ['Peanut_Markov_Attribution', 'register_model']);

        // Register cron jobs
        add_action('peanut_attribution_calculate', [$this, 'run_attribution_calculation']);
        add_action('peanut_attribution_cleanup', [$this, 'run_cleanup']);
        add_action('peanut_markov_train', ['Peanut_Markov_Attribution', 'train']);

        // Schedule cron jobs
        if (!wp_next_scheduled('peanut_attribution_calculate')) {
            wp_schedule_event(time(), 'peanut_fifteen_minutes', 'peanut_attribution_calculate');
        }

        if (!wp_next_scheduled('peanut_attribution_cleanup')) {
            wp_schedule_event(time(), 'daily', 'peanut_attribution_cleanup');
        }

        // Schedule Markov training if not already scheduled
        if (!wp_next_scheduled('peanut_markov_train')) {
            wp_schedule_event(time(), 'daily', 'peanut_markov_train');
        }

        // Hook into visitor events to record touches
        add_action('peanut_visitor_event', [$this, 'handle_visitor_event'], 10, 3);

        // Hook into webhook events for conversions
        add_action('peanut_webhook_formflow-lite_form.submitted', [$this, 'handle_form_submission'], 10, 1);
        add_action('peanut_webhook_formflow-lite_enrollment.completed', [$this, 'handle_enrollment'], 10, 1);
    }

    /**
     * Register API routes.
     */
    public function register_api_routes(): void {
        $this->controller = new Attribution_Controller();
        $this->controller->register_routes();
    }

    /**
     * Run attribution calculation.
     */
    public function run_attribution_calculation(): void {
        $result = Attribution_Calculator::process_pending_conversions(50);

        if ($result['processed'] > 0 || $result['errors'] > 0) {
            error_log(sprintf(
                'Peanut Suite Attribution: Processed %d conversions, %d errors',
                $result['processed'],
                $result['errors']
            ));
        }
    }

    /**
     * Run cleanup task.
     */
    public function run_cleanup(): void {
        $settings = get_option('peanut_settings', []);
        $retention_days = $settings['attribution_retention_days'] ?? 90;

        $deleted = Attribution_Database::cleanup_old_touches((int) $retention_days);

        if ($deleted > 0) {
            error_log("Peanut Suite: Cleaned up {$deleted} old attribution touches");
        }
    }

    /**
     * Handle visitor event to record touch.
     *
     * @param string $visitor_id Visitor identifier.
     * @param string $event_type Event type.
     * @param array  $event_data Event data.
     */
    public function handle_visitor_event(string $visitor_id, string $event_type, array $event_data): void {
        // Only record touches for significant events
        $touch_events = ['pageview', 'click', 'form_view', 'form_start'];

        if (!in_array($event_type, $touch_events, true)) {
            return;
        }

        // Only record if there's UTM data or external referrer
        $has_utm = !empty($event_data['utm_source']) || !empty($event_data['utm_campaign']);
        $has_referrer = !empty($event_data['referrer']);

        if (!$has_utm && !$has_referrer) {
            // First pageview in a session should still be recorded
            if ($event_type !== 'pageview') {
                return;
            }
        }

        $event_data['event_type'] = $event_type;
        Attribution_Calculator::record_touch_from_event($visitor_id, $event_data);
    }

    /**
     * Handle form submission webhook as conversion.
     *
     * @param array $payload Webhook payload.
     */
    public function handle_form_submission(array $payload): void {
        $visitor_id = $payload['visitor_id'] ?? null;

        if (!$visitor_id) {
            return;
        }

        // Check if this is a conversion event (lead form, not just any form)
        $form_type = $payload['form_type'] ?? 'general';
        if (!in_array($form_type, ['lead', 'contact', 'signup', 'newsletter'], true)) {
            return;
        }

        Attribution_Calculator::record_conversion($visitor_id, 'form_submission', [
            'source' => 'formflow-lite',
            'source_id' => $payload['submission_id'] ?? null,
            'email' => $payload['email'] ?? null,
            'name' => $payload['name'] ?? null,
            'metadata' => [
                'form_id' => $payload['form_id'] ?? null,
                'form_name' => $payload['form_name'] ?? null,
            ],
        ]);
    }

    /**
     * Handle enrollment webhook as conversion.
     *
     * @param array $payload Webhook payload.
     */
    public function handle_enrollment(array $payload): void {
        $visitor_id = $payload['visitor_id'] ?? null;

        if (!$visitor_id) {
            return;
        }

        Attribution_Calculator::record_conversion($visitor_id, 'enrollment', [
            'source' => 'formflow-lite',
            'source_id' => $payload['enrollment_id'] ?? null,
            'email' => $payload['email'] ?? null,
            'name' => $payload['name'] ?? null,
            'metadata' => [
                'workflow_id' => $payload['workflow_id'] ?? null,
                'workflow_name' => $payload['workflow_name'] ?? null,
            ],
        ]);
    }

    /**
     * Get module info.
     *
     * @return array
     */
    public static function get_info(): array {
        return [
            'id' => 'attribution',
            'name' => 'Attribution',
            'description' => 'Multi-touch attribution modeling for marketing analytics',
            'icon' => 'git-branch',
            'tier' => 'pro',
            'version' => '1.0.0',
        ];
    }

    /**
     * Activation hook.
     */
    public static function activate(): void {
        Attribution_Database::create_tables();

        // Add default settings
        $settings = get_option('peanut_settings', []);
        if (!isset($settings['attribution_retention_days'])) {
            $settings['attribution_retention_days'] = 90;
            $settings['default_attribution_model'] = 'last_touch';
            update_option('peanut_settings', $settings);
        }

        // Schedule Markov training if class is available
        if (class_exists('Peanut_Markov_Attribution')) {
            Peanut_Markov_Attribution::schedule_training();
        }
    }

    /**
     * Deactivation hook.
     */
    public static function deactivate(): void {
        // Clear scheduled events
        wp_clear_scheduled_hook('peanut_attribution_calculate');
        wp_clear_scheduled_hook('peanut_attribution_cleanup');
        wp_clear_scheduled_hook('peanut_markov_train');

        // Unschedule Markov training if class is available
        if (class_exists('Peanut_Markov_Attribution')) {
            Peanut_Markov_Attribution::unschedule_training();
        }
    }
}
