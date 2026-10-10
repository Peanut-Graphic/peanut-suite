<?php
/**
 * Webhooks Module
 *
 * Receives and processes webhooks from FormFlow and other sources.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Webhooks_Module {

    /**
     * Initialize the module
     */
    public function init(): void {
        // Load dependencies
        $this->load_dependencies();

        // Register API routes
        add_action('peanut_register_routes', [$this, 'register_routes']);

        // Register cron jobs
        add_action('peanut_process_webhooks', [$this, 'process_webhooks']);

        // Schedule cron if not exists
        if (!wp_next_scheduled('peanut_process_webhooks')) {
            wp_schedule_event(time(), 'peanut_every_minute', 'peanut_process_webhooks');
        }

        // Add custom cron interval
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);

        // Add dashboard stats
        add_filter('peanut_dashboard_stats', [$this, 'add_dashboard_stats'], 10, 2);

        // Daily cleanup
        add_action('peanut_daily_maintenance_tasks', [$this, 'cleanup_old_webhooks']);

        // Tell administrators which senders are being refused for lack of a
        // signing secret (unsigned webhooks fail closed).
        add_action('admin_notices', [$this, 'render_unsigned_rejections_notice']);
    }

    /**
     * Admin notice: unsigned webhooks were refused because their source has
     * no signing secret. Shown to administrators until each named source has
     * a secret, or 30 days pass without another refused delivery.
     */
    public function render_unsigned_rejections_notice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $sources = Webhooks_Signature::rejected_unsigned_sources();
        if (empty($sources)) {
            return;
        }

        $url = admin_url('admin.php?page=peanut-app#/webhooks');

        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p><a href="%3$s">%4$s</a></p></div>',
            esc_html__('Peanut Suite is refusing unsigned webhooks.', 'peanut-suite'),
            sprintf(
                /* translators: %s: comma-separated webhook source names */
                esc_html__('Webhooks from these sources were rejected because no signing secret is set for them: %s. Generate a secret for each source and add it to the sending application\'s webhook settings.', 'peanut-suite'),
                '<code>' . implode('</code>, <code>', array_map('esc_html', $sources)) . '</code>'
            ),
            esc_url($url),
            esc_html__('Set signing secrets', 'peanut-suite')
        );
    }

    /**
     * Load module dependencies
     */
    private function load_dependencies(): void {
        require_once __DIR__ . '/class-webhooks-database.php';
        require_once __DIR__ . '/class-webhooks-signature.php';
        require_once __DIR__ . '/class-webhooks-processor.php';
    }

    /**
     * Register API routes
     */
    public function register_routes(): void {
        require_once __DIR__ . '/api/class-webhooks-controller.php';
        $controller = new Webhooks_Controller();
        $controller->register_routes();
    }

    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals(array $schedules): array {
        if (!isset($schedules['peanut_every_minute'])) {
            $schedules['peanut_every_minute'] = [
                'interval' => 60,
                'display' => __('Every Minute', 'peanut-suite'),
            ];
        }

        return $schedules;
    }

    /**
     * Process pending webhooks (cron job)
     */
    public function process_webhooks(): void {
        $processed = Webhooks_Processor::process_pending();

        if ($processed > 0) {
            do_action('peanut_webhooks_batch_processed', $processed);
        }
    }

    /**
     * Add webhook stats to dashboard
     */
    public function add_dashboard_stats(array $stats, string $period): array {
        global $wpdb;
        $table = Webhooks_Database::webhooks_table();

        // Get date range
        $days = match ($period) {
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };

        $start_date = gmdate('Y-m-d', strtotime("-{$days} days"));

        // Get webhook counts
        $webhook_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
             FROM $table
             WHERE DATE(created_at) >= %s",
            $start_date
        ), ARRAY_A);

        $stats['webhooks_received'] = (int) ($webhook_stats['total'] ?? 0);
        $stats['webhooks_processed'] = (int) ($webhook_stats['processed'] ?? 0);
        $stats['webhooks_failed'] = (int) ($webhook_stats['failed'] ?? 0);

        return $stats;
    }

    /**
     * Cleanup old webhooks (daily maintenance)
     */
    public function cleanup_old_webhooks(): void {
        // Keep webhooks for 30 days by default
        $retention_days = apply_filters('peanut_webhook_retention_days', 30);
        $deleted = Webhooks_Database::cleanup($retention_days);

        if ($deleted > 0) {
            do_action('peanut_webhooks_cleaned', $deleted);
        }
    }

    /**
     * Get module info for settings
     */
    public static function get_info(): array {
        return [
            'name' => __('Webhook Receiver', 'peanut-suite'),
            'description' => __('Receive and process webhooks from FormFlow and other sources.', 'peanut-suite'),
            'version' => '1.0.0',
        ];
    }

    /**
     * Get webhook endpoint URL
     */
    public static function get_endpoint_url(): string {
        return rest_url(PEANUT_API_NAMESPACE . '/webhooks/receive');
    }

    /**
     * Create database tables on activation
     */
    public static function activate(): void {
        require_once __DIR__ . '/class-webhooks-database.php';
        Webhooks_Database::create_tables();
    }

    /**
     * Drop database tables on deactivation
     */
    public static function deactivate(): void {
        // Clear scheduled events
        wp_clear_scheduled_hook('peanut_process_webhooks');
    }

    /**
     * Uninstall - drop tables
     */
    public static function uninstall(): void {
        require_once __DIR__ . '/class-webhooks-database.php';
        Webhooks_Database::drop_tables();

        // Remove options
        delete_option('peanut_webhook_secrets');
    }
}
