<?php
/**
 * Plugin Activator
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Activator {

    public static function activate(): void {
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                __('Peanut Suite requires PHP 8.0 or higher.', 'peanut-suite'),
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }

        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '6.0', '<')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                __('Peanut Suite requires WordPress 6.0 or higher.', 'peanut-suite'),
                'Plugin Activation Error',
                ['back_link' => true]
            );
        }

        // Load database classes
        require_once PEANUT_PLUGIN_DIR . 'core/database/class-peanut-database.php';

        // Create core tables
        Peanut_Database::create_tables();

        // Create module-specific tables
        self::create_module_tables();

        // Set default options
        self::set_defaults();

        // Schedule cron
        if (!wp_next_scheduled('peanut_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'peanut_daily_maintenance');
        }

        // Flush rewrite rules for link redirects
        flush_rewrite_rules();

        // Set activation flag
        set_transient('peanut_activated', true, 30);
    }

    private static function set_defaults(): void {
        // Default active modules
        if (get_option('peanut_active_modules') === false) {
            update_option('peanut_active_modules', [
                'utm' => true,
                'links' => true,
                'contacts' => true,
                'dashboard' => true,
                'webhooks' => true,
                'visitors' => false,
                'attribution' => false,
                'analytics' => false,
                'popups' => false,
                'monitor' => false,
                'security' => false,
                'reports' => false,
                'backlinks' => false,
            ]);
        }

        // Default settings
        if (get_option('peanut_settings') === false) {
            update_option('peanut_settings', [
                'link_prefix' => 'go',
                'track_clicks' => true,
                'track_visitors' => true,
                'visitor_retention_days' => 90,
                'anonymize_ip' => false,
            ]);
        }

        // ML service settings
        if (get_option('peanut_ml_settings') === false) {
            update_option('peanut_ml_settings', [
                'service_url' => 'http://127.0.0.1:8100',
                'api_key' => '',
            ]);
        }
    }

    /**
     * Create module-specific tables.
     *
     * Public so Peanut_Database::maybe_upgrade() can re-run module migrations
     * on plugin self-update (the updater never re-activates). dbDelta inside
     * each module creator is idempotent/additive.
     */
    public static function create_module_tables(): void {
        // Webhooks module tables
        $webhooks_db = PEANUT_PLUGIN_DIR . 'modules/webhooks/class-webhooks-database.php';
        if (file_exists($webhooks_db)) {
            require_once $webhooks_db;
            Webhooks_Database::create_tables();
        }

        // Visitors module tables
        $visitors_db = PEANUT_PLUGIN_DIR . 'modules/visitors/class-visitors-database.php';
        if (file_exists($visitors_db)) {
            require_once $visitors_db;
            \PeanutSuite\Visitors\Visitors_Database::create_tables();
        }

        // Attribution module tables
        $attribution_db = PEANUT_PLUGIN_DIR . 'modules/attribution/class-attribution-database.php';
        if (file_exists($attribution_db)) {
            require_once $attribution_db;
            \PeanutSuite\Attribution\Attribution_Database::create_tables();
        }

        // Analytics module tables
        $analytics_db = PEANUT_PLUGIN_DIR . 'modules/analytics/class-analytics-database.php';
        if (file_exists($analytics_db)) {
            require_once $analytics_db;
            \PeanutSuite\Analytics\Analytics_Database::create_tables();
        }

        // Monitor module tables
        $monitor_db = PEANUT_PLUGIN_DIR . 'modules/monitor/class-monitor-database.php';
        if (file_exists($monitor_db)) {
            require_once $monitor_db;
            Monitor_Database::create_tables();
        }

        // Popups module tables
        $popups_db = PEANUT_PLUGIN_DIR . 'modules/popups/class-popups-database.php';
        if (file_exists($popups_db)) {
            require_once $popups_db;
            Popups_Database::create_tables();
        }

        // Security module tables
        $security_module = PEANUT_PLUGIN_DIR . 'modules/security/class-security-module.php';
        if (file_exists($security_module)) {
            require_once $security_module;
            Security_Module::create_tables();
        }

        // Backlinks module tables
        $backlinks_module = PEANUT_PLUGIN_DIR . 'modules/backlinks/class-backlinks-module.php';
        if (file_exists($backlinks_module)) {
            require_once $backlinks_module;
            Backlinks_Module::create_tables();
        }

        // SEO module tables
        $seo_module = PEANUT_PLUGIN_DIR . 'modules/seo/class-seo-module.php';
        if (file_exists($seo_module)) {
            require_once $seo_module;
            \PeanutSuite\SEO\SEO_Module::create_tables();
        }

        // WooCommerce module tables
        $woo_module = PEANUT_PLUGIN_DIR . 'modules/woocommerce/class-woocommerce-module.php';
        if (file_exists($woo_module)) {
            require_once $woo_module;
            \PeanutSuite\WooCommerce\WooCommerce_Module::create_tables();
        }

        // Sequences module tables
        $sequences_module = PEANUT_PLUGIN_DIR . 'modules/sequences/class-sequences-module.php';
        if (file_exists($sequences_module)) {
            require_once $sequences_module;
            \PeanutSuite\Sequences\Sequences_Module::create_tables();
        }

        // Calendar module tables
        $calendar_module = PEANUT_PLUGIN_DIR . 'modules/calendar/class-calendar-module.php';
        if (file_exists($calendar_module)) {
            require_once $calendar_module;
            \PeanutSuite\Calendar\Calendar_Module::create_tables();
        }

        // Forms module tables
        $forms_module = PEANUT_PLUGIN_DIR . 'modules/forms/class-forms-module.php';
        if (file_exists($forms_module)) {
            require_once $forms_module;
            \PeanutSuite\Forms\Forms_Module::create_tables();
        }

        // GA Integration module tables
        $ga_module = PEANUT_PLUGIN_DIR . 'modules/ga-integration/class-ga-integration-module.php';
        if (file_exists($ga_module)) {
            require_once $ga_module;
            \PeanutSuite\GAIntegration\GA_Integration_Module::create_tables();
        }
    }
}
