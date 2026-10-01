<?php
/**
 * Peanut Suite Core
 *
 * Main orchestrator that initializes all components and modules.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Core {

    /**
     * Registered modules
     */
    private array $modules = [];

    /**
     * Module manager
     */
    private ?Peanut_Module_Manager $module_manager = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->load_dependencies();
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies(): void {
        // Core services
        require_once PEANUT_PLUGIN_DIR . 'core/services/class-peanut-encryption.php';
        require_once PEANUT_PLUGIN_DIR . 'core/services/class-peanut-security.php';
        require_once PEANUT_PLUGIN_DIR . 'core/services/class-peanut-license.php';
        require_once PEANUT_PLUGIN_DIR . 'core/services/class-peanut-integrations.php';
        require_once PEANUT_PLUGIN_DIR . 'core/services/class-peanut-webhook-replay-guard.php';

        // Database
        require_once PEANUT_PLUGIN_DIR . 'core/database/class-peanut-database.php';

        // API
        require_once PEANUT_PLUGIN_DIR . 'core/api/class-peanut-rest-controller.php';
        require_once PEANUT_PLUGIN_DIR . 'core/api/class-peanut-settings-controller.php';

        // Admin
        require_once PEANUT_PLUGIN_DIR . 'core/admin/class-peanut-admin.php';
        require_once PEANUT_PLUGIN_DIR . 'core/admin/class-peanut-module-manager.php';
    }

    /**
     * Run the plugin
     */
    public function run(): void {
        // Initialize module manager
        $this->module_manager = new Peanut_Module_Manager();

        // Register built-in modules
        $this->register_modules();

        // Initialize active modules
        $this->module_manager->init_modules();

        // Force-initialize agency modules for agency users (pages show regardless of module activation)
        $this->init_tier_modules();

        // Initialize integrations manager
        Peanut_Integrations::instance();

        // Setup hooks
        $this->define_admin_hooks();
        $this->define_api_hooks();
        $this->define_cron_hooks();

        // Fire action for add-ons
        do_action('peanut_modules_loaded', $this->module_manager);
    }

    /**
     * Force-initialize tier-gated modules for admin users
     * This ensures AJAX handlers work even if modules aren't "activated"
     * The individual handlers check permissions themselves
     */
    private function init_tier_modules(): void {
        // Only run in admin (includes AJAX requests)
        if (!is_admin()) {
            return;
        }

        // Always initialize Monitor module in admin context
        // Permission checks happen in the individual AJAX handlers
        if (!$this->module_manager->get_instance('monitor')) {
            require_once PEANUT_PLUGIN_DIR . 'modules/monitor/class-monitor-module.php';
            $monitor = new Monitor_Module();
            $monitor->init();
        }
    }

    /**
     * Register built-in modules
     */
    private function register_modules(): void {
        // UTM Module
        $this->module_manager->register('utm', [
            'name' => __('UTM Campaigns', 'peanut-suite'),
            'description' => __('Create and manage UTM tracking codes for your marketing campaigns.', 'peanut-suite'),
            'icon' => 'chart-line',
            'file' => PEANUT_PLUGIN_DIR . 'modules/utm/class-utm-module.php',
            'class' => 'UTM_Module',
            'default' => true,
            'pro' => false,
        ]);

        // Links Module
        $this->module_manager->register('links', [
            'name' => __('Link Manager', 'peanut-suite'),
            'description' => __('Shorten URLs, generate QR codes, and track link clicks.', 'peanut-suite'),
            'icon' => 'link',
            'file' => PEANUT_PLUGIN_DIR . 'modules/links/class-links-module.php',
            'class' => 'Links_Module',
            'default' => true,
            'pro' => false,
        ]);

        // Contacts Module
        $this->module_manager->register('contacts', [
            'name' => __('Contacts', 'peanut-suite'),
            'description' => __('Manage leads and contacts from all your marketing channels.', 'peanut-suite'),
            'icon' => 'users',
            'file' => PEANUT_PLUGIN_DIR . 'modules/contacts/class-contacts-module.php',
            'class' => 'Contacts_Module',
            'default' => true,
            'pro' => false,
        ]);

        // Dashboard Module
        $this->module_manager->register('dashboard', [
            'name' => __('Dashboard', 'peanut-suite'),
            'description' => __('Unified analytics dashboard for all your marketing data.', 'peanut-suite'),
            'icon' => 'layout-dashboard',
            'file' => PEANUT_PLUGIN_DIR . 'modules/dashboard/class-dashboard-module.php',
            'class' => 'Dashboard_Module',
            'default' => true,
            'pro' => false,
        ]);

        // Webhooks Module
        $this->module_manager->register('webhooks', [
            'name' => __('Webhook Receiver', 'peanut-suite'),
            'description' => __('Receive and process webhooks from FormFlow and other sources.', 'peanut-suite'),
            'icon' => 'webhook',
            'file' => PEANUT_PLUGIN_DIR . 'modules/webhooks/class-webhooks-module.php',
            'class' => 'Webhooks_Module',
            'default' => true,
            'pro' => false,
        ]);

        // Visitors Module (Pro)
        $this->module_manager->register('visitors', [
            'name' => __('Visitor Tracking', 'peanut-suite'),
            'description' => __('Track website visitors with cookie-based tracking and JavaScript snippet.', 'peanut-suite'),
            'icon' => 'users',
            'file' => PEANUT_PLUGIN_DIR . 'modules/visitors/class-visitors-module.php',
            'class' => 'PeanutSuite\\Visitors\\Visitors_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Attribution Module (Pro)
        $this->module_manager->register('attribution', [
            'name' => __('Attribution', 'peanut-suite'),
            'description' => __('Multi-touch attribution modeling for marketing analytics.', 'peanut-suite'),
            'icon' => 'git-branch',
            'file' => PEANUT_PLUGIN_DIR . 'modules/attribution/class-attribution-module.php',
            'class' => 'PeanutSuite\\Attribution\\Attribution_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Analytics Module (Pro)
        $this->module_manager->register('analytics', [
            'name' => __('Analytics Dashboard', 'peanut-suite'),
            'description' => __('Unified analytics dashboard with aggregated stats and visualizations.', 'peanut-suite'),
            'icon' => 'bar-chart-2',
            'file' => PEANUT_PLUGIN_DIR . 'modules/analytics/class-analytics-module.php',
            'class' => 'PeanutSuite\\Analytics\\Analytics_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Popups Module (Pro)
        $this->module_manager->register('popups', [
            'name' => __('Popups', 'peanut-suite'),
            'description' => __('Create exit-intent popups, slide-ins, and modals to capture leads.', 'peanut-suite'),
            'icon' => 'message-square',
            'file' => PEANUT_PLUGIN_DIR . 'modules/popups/class-popups-module.php',
            'class' => 'Popups_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Monitor Module (Agency)
        $this->module_manager->register('monitor', [
            'name' => __('Monitor', 'peanut-suite'),
            'description' => __('Manage multiple WordPress sites from one dashboard. View health, updates, and aggregated analytics.', 'peanut-suite'),
            'icon' => 'monitor',
            'file' => PEANUT_PLUGIN_DIR . 'modules/monitor/class-monitor-module.php',
            'class' => 'Monitor_Module',
            'default' => false,
            'pro' => true,
            'tier' => 'agency',
        ]);

        // Invoicing Module (Agency)
        $this->module_manager->register('invoicing', [
            'name' => __('Invoicing', 'peanut-suite'),
            'description' => __('Create and send professional invoices with Stripe payments.', 'peanut-suite'),
            'icon' => 'receipt',
            'file' => PEANUT_PLUGIN_DIR . 'modules/invoicing/class-invoicing-module.php',
            'class' => 'Invoicing_Module',
            'default' => false,
            'pro' => true,
            'tier' => 'agency',
        ]);

        // Security Module (Pro)
        $this->module_manager->register('security', [
            'name' => __('Security', 'peanut-suite'),
            'description' => __('Protect your site with login security, IP blocking, and 2FA.', 'peanut-suite'),
            'icon' => 'shield',
            'file' => PEANUT_PLUGIN_DIR . 'modules/security/class-security-module.php',
            'class' => 'Security_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Reports Module (Pro)
        $this->module_manager->register('reports', [
            'name' => __('Email Reports', 'peanut-suite'),
            'description' => __('Scheduled email digest reports with marketing analytics.', 'peanut-suite'),
            'icon' => 'email',
            'file' => PEANUT_PLUGIN_DIR . 'modules/reports/class-reports-module.php',
            'class' => 'Reports_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Backlinks Module (Pro)
        $this->module_manager->register('backlinks', [
            'name' => __('Backlink Discovery', 'peanut-suite'),
            'description' => __('Find and track sites linking to your website.', 'peanut-suite'),
            'icon' => 'admin-links',
            'file' => PEANUT_PLUGIN_DIR . 'modules/backlinks/class-backlinks-module.php',
            'class' => 'Backlinks_Module',
            'default' => false,
            'pro' => true,
        ]);

        // SEO Module (Pro) - Keyword tracking & audit
        $this->module_manager->register('seo', [
            'name' => __('SEO Tools', 'peanut-suite'),
            'description' => __('Keyword rank tracking and SEO audits.', 'peanut-suite'),
            'icon' => 'search',
            'file' => PEANUT_PLUGIN_DIR . 'modules/seo/class-seo-module.php',
            'class' => 'PeanutSuite\\SEO\\SEO_Module',
            'default' => false,
            'pro' => true,
        ]);

        // White-Label Module (Agency)
        $this->module_manager->register('whitelabel', [
            'name' => __('White-Label', 'peanut-suite'),
            'description' => __('Custom branding for reports and dashboards.', 'peanut-suite'),
            'icon' => 'art',
            'file' => PEANUT_PLUGIN_DIR . 'modules/whitelabel/class-whitelabel-module.php',
            'class' => 'PeanutSuite\\WhiteLabel\\WhiteLabel_Module',
            'default' => false,
            'pro' => true,
            'tier' => 'agency',
        ]);

        // WooCommerce Attribution (Pro)
        $this->module_manager->register('woocommerce', [
            'name' => __('WooCommerce Attribution', 'peanut-suite'),
            'description' => __('Track revenue by marketing campaign.', 'peanut-suite'),
            'icon' => 'cart',
            'file' => PEANUT_PLUGIN_DIR . 'modules/woocommerce/class-woocommerce-module.php',
            'class' => 'PeanutSuite\\WooCommerce\\WooCommerce_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Email Sequences (Pro)
        $this->module_manager->register('sequences', [
            'name' => __('Email Sequences', 'peanut-suite'),
            'description' => __('Automated drip campaigns and email sequences.', 'peanut-suite'),
            'icon' => 'email-alt',
            'file' => PEANUT_PLUGIN_DIR . 'modules/sequences/class-sequences-module.php',
            'class' => 'PeanutSuite\\Sequences\\Sequences_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Notifications Module (Pro)
        $this->module_manager->register('notifications', [
            'name' => __('Notifications', 'peanut-suite'),
            'description' => __('Slack, Discord, and Telegram notifications.', 'peanut-suite'),
            'icon' => 'bell',
            'file' => PEANUT_PLUGIN_DIR . 'modules/notifications/class-notifications-module.php',
            'class' => 'PeanutSuite\\Notifications\\Notifications_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Content Calendar (Pro)
        $this->module_manager->register('calendar', [
            'name' => __('Content Calendar', 'peanut-suite'),
            'description' => __('Editorial calendar for content planning.', 'peanut-suite'),
            'icon' => 'calendar-alt',
            'file' => PEANUT_PLUGIN_DIR . 'modules/calendar/class-calendar-module.php',
            'class' => 'PeanutSuite\\Calendar\\Calendar_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Form Analytics (Pro)
        $this->module_manager->register('forms', [
            'name' => __('Form Analytics', 'peanut-suite'),
            'description' => __('Track form submissions and abandonment.', 'peanut-suite'),
            'icon' => 'forms',
            'file' => PEANUT_PLUGIN_DIR . 'modules/forms/class-forms-module.php',
            'class' => 'PeanutSuite\\Forms\\Forms_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Accessibility Module (Pro)
        $this->module_manager->register('accessibility', [
            'name' => __('ADA Compliance', 'peanut-suite'),
            'description' => __('Accessibility widget, scanner, and compliance tools.', 'peanut-suite'),
            'icon' => 'universal-access-alt',
            'file' => PEANUT_PLUGIN_DIR . 'modules/accessibility/class-accessibility-module.php',
            'class' => 'PeanutSuite\\Accessibility\\Accessibility_Module',
            'default' => false,
            'pro' => true,
        ]);

        // Google Analytics Integration (Pro)
        $this->module_manager->register('ga-integration', [
            'name' => __('Google Analytics', 'peanut-suite'),
            'description' => __('Connect GA4 and Search Console for unified analytics.', 'peanut-suite'),
            'icon' => 'chart-area',
            'file' => PEANUT_PLUGIN_DIR . 'modules/ga-integration/class-ga-integration-module.php',
            'class' => 'PeanutSuite\\GAIntegration\\GA_Integration_Module',
            'default' => false,
            'pro' => true,
        ]);

        // GEOPETS Module (External App Integration)
        $this->module_manager->register('geopets', [
            'name' => __('GEOPETS', 'peanut-suite'),
            'description' => __('Monitor GEOPETS waitlist signups and app analytics.', 'peanut-suite'),
            'icon' => 'heart',
            'file' => PEANUT_PLUGIN_DIR . 'modules/geopets/class-geopets-module.php',
            'class' => 'Geopets_Module',
            'default' => true,
            'pro' => false,
        ]);

        // Allow add-ons to register modules
        do_action('peanut_register_modules', $this->module_manager);
    }

    /**
     * Define admin hooks
     */
    private function define_admin_hooks(): void {
        $admin = new Peanut_Admin();

        add_action('admin_menu', [$admin, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$admin, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$admin, 'enqueue_styles']);
    }

    /**
     * Define REST API hooks
     */
    private function define_api_hooks(): void {
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        // Core settings endpoint
        $settings_controller = new Peanut_Settings_Controller();
        $settings_controller->register_routes();

        // Connect API (allows this site to be monitored remotely)
        require_once PEANUT_PLUGIN_DIR . 'core/api/class-peanut-connect-controller.php';
        $connect_controller = new Peanut_Connect_Controller();
        $connect_controller->register_routes();

        // Accounts API (multi-tenancy)
        $accounts_controller = new Peanut_Accounts_Controller();
        $accounts_controller->register_routes();

        // Let modules register their routes
        do_action('peanut_register_routes');
    }

    /**
     * Define cron hooks
     */
    private function define_cron_hooks(): void {
        // Add custom cron intervals
        add_filter('cron_schedules', [$this, 'add_cron_intervals']);

        add_action('peanut_daily_maintenance', [$this, 'run_daily_maintenance']);

        // Schedule cron if not exists
        if (!wp_next_scheduled('peanut_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'peanut_daily_maintenance');
        }
    }

    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals(array $schedules): array {
        $schedules['peanut_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 Minutes', 'peanut-suite'),
        ];

        $schedules['peanut_fifteen_minutes'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 Minutes', 'peanut-suite'),
        ];

        return $schedules;
    }

    /**
     * Run daily maintenance tasks
     */
    public function run_daily_maintenance(): void {
        // License check
        $license = new Peanut_License();
        $license->validate_license(get_option('peanut_license_key', ''), true);

        // Cleanup expired data
        Peanut_Database::cleanup_expired_cache();
        Peanut_Webhook_Replay_Guard::purge_expired();

        // Let modules run maintenance
        do_action('peanut_daily_maintenance_tasks');
    }

    /**
     * Get module manager
     */
    public function get_module_manager(): Peanut_Module_Manager {
        return $this->module_manager;
    }
}
