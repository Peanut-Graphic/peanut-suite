<?php
/**
 * Admin Handler
 *
 * Manages admin menu, scripts, and page rendering.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Admin {

    /**
     * Admin pages handler
     */
    private ?Peanut_Admin_Pages $pages = null;

    /**
     * Admin assets handler
     */
    private ?Peanut_Admin_Assets $assets = null;

    /**
     * Help system
     */
    private ?Peanut_Help $help = null;

    /**
     * Check if React app is available
     */
    private function is_react_mode(): bool {
        return file_exists(PEANUT_PLUGIN_DIR . 'assets/dist/js/main.js');
    }

    /**
     * Initialize admin
     */
    public function __construct() {
        $this->load_dependencies();

        // Add fullscreen mode support
        if ($this->is_react_mode()) {
            add_action('admin_init', [$this, 'maybe_redirect_to_app']);
            add_action('admin_head', [$this, 'inject_fullscreen_styles']);
        }

        // Team member restrictions (hide WP admin elements)
        add_action('admin_init', [$this, 'maybe_restrict_team_member']);
        add_action('admin_menu', [$this, 'hide_admin_menus_for_team'], 999);
        add_filter('show_admin_bar', [$this, 'maybe_hide_admin_bar']);
        add_action('admin_head', [$this, 'inject_team_member_styles']);

        // Grant Peanut Suite access to team members
        add_filter('user_has_cap', [$this, 'grant_peanut_access_to_team'], 10, 4);
    }

    /**
     * Grant peanut_access capability to team members
     */
    public function grant_peanut_access_to_team(array $allcaps, array $caps, array $args, $user): array {
        // Check if we're checking for peanut_access capability
        if (!in_array('peanut_access', $caps, true)) {
            return $allcaps;
        }

        // If user already has it, return
        if (!empty($allcaps['peanut_access'])) {
            return $allcaps;
        }

        // Admins always have access
        if (!empty($allcaps['manage_options'])) {
            $allcaps['peanut_access'] = true;
            return $allcaps;
        }

        // Check if user is a Peanut team member someone else added. Merely
        // having an account is not enough: accounts used to be auto-created
        // for any logged-in user, which made every Subscriber a "member".
        if (class_exists('Peanut_Account_Service')) {
            $user_id = (int) ($user->ID ?? get_current_user_id());
            if ($user_id > 0 && Peanut_Account_Service::has_team_access($user_id)) {
                $allcaps['peanut_access'] = true;
            }
        }

        return $allcaps;
    }

    /**
     * Check if current user is a team member (not owner/admin)
     */
    private function is_team_member(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        $user_id = get_current_user_id();

        // Skip check if user is a WordPress administrator
        if (current_user_can('manage_options')) {
            return false;
        }

        // Check if user has a Peanut account and their role
        if (!class_exists('Peanut_Account_Service')) {
            return false;
        }

        $account = Peanut_Account_Service::get_user_account($user_id);
        if (!$account) {
            return false;
        }

        $member = Peanut_Account_Service::get_member($account['id'], $user_id);
        if (!$member) {
            return false;
        }

        // Owner and admin can see everything, members and viewers are restricted
        return in_array($member['role'], ['member', 'viewer'], true);
    }

    /**
     * Restrict team members to Peanut Suite only
     */
    public function maybe_restrict_team_member(): void {
        if (!$this->is_team_member()) {
            return;
        }

        // Get current page
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';

        // If not on Peanut Suite page, redirect to it
        if (!in_array($page, ['peanut-app', 'peanut-suite'], true)) {
            // Check if this is a Peanut-related page (legacy redirects)
            if (strpos($page, 'peanut-') !== 0) {
                wp_safe_redirect(admin_url('admin.php?page=peanut-app'));
                exit;
            }
        }
    }

    /**
     * Hide WordPress admin menus for team members
     */
    public function hide_admin_menus_for_team(): void {
        if (!$this->is_team_member()) {
            return;
        }

        global $menu, $submenu;

        // Keep only Peanut Suite menu
        if (is_array($menu)) {
            foreach ($menu as $key => $item) {
                // Keep Peanut Suite menu, hide everything else
                if (!isset($item[2]) || $item[2] !== 'peanut-app') {
                    remove_menu_page($item[2]);
                }
            }
        }
    }

    /**
     * Hide admin bar for team members
     */
    public function maybe_hide_admin_bar(bool $show): bool {
        if ($this->is_team_member()) {
            return false;
        }
        return $show;
    }

    /**
     * Inject CSS to hide admin bar for team members
     */
    public function inject_team_member_styles(): void {
        if (!$this->is_team_member()) {
            return;
        }

        ?>
        <style>
            /* Hide WordPress admin bar and chrome for team members */
            html.wp-toolbar {
                padding-top: 0 !important;
            }
            #wpadminbar {
                display: none !important;
            }
            #adminmenumain,
            #adminmenuback,
            #adminmenuwrap {
                display: none !important;
            }
            #wpcontent,
            #wpfooter {
                margin-left: 0 !important;
            }
            #wpbody-content {
                padding-bottom: 0 !important;
            }
            .update-nag,
            .updated,
            .notice:not(.peanut-notice),
            .error:not(.peanut-error) {
                display: none !important;
            }
            #wpfooter {
                display: none !important;
            }
        </style>
        <?php
    }

    /**
     * Load required classes
     */
    private function load_dependencies(): void {
        require_once PEANUT_PLUGIN_DIR . 'core/admin/class-peanut-admin-pages.php';
        require_once PEANUT_PLUGIN_DIR . 'core/admin/class-peanut-admin-assets.php';
        require_once PEANUT_PLUGIN_DIR . 'core/admin/class-peanut-help.php';

        $this->pages = new Peanut_Admin_Pages();
        $this->assets = new Peanut_Admin_Assets();
        $this->help = new Peanut_Help();
    }

    /**
     * Redirect old page slugs to the React app
     */
    public function maybe_redirect_to_app(): void {
        if (!isset($_GET['page'])) {
            return;
        }

        $page = sanitize_text_field($_GET['page']);

        // Map old page slugs to React routes
        $route_map = [
            'peanut-suite' => '/',
            'peanut-utm-builder' => '/utm',
            'peanut-utm-library' => '/utm/library',
            'peanut-links' => '/links',
            'peanut-contacts' => '/contacts',
            'peanut-webhooks' => '/webhooks',
            'peanut-visitors' => '/visitors',
            'peanut-attribution' => '/attribution',
            'peanut-analytics' => '/analytics',
            'peanut-popups' => '/popups',
            'peanut-popup-builder' => '/popups/new',
            'peanut-monitor' => '/monitor',
            'peanut-settings' => '/settings',
            'peanut-security' => '/security',
            'peanut-reports' => '/reports',
            'peanut-backlinks' => '/backlinks',
        ];

        // Only redirect if it's a Peanut page but not the main app page
        if (isset($route_map[$page]) && $page !== 'peanut-app') {
            $route = $route_map[$page];
            wp_redirect(admin_url('admin.php?page=peanut-app#' . $route));
            exit;
        }
    }

    /**
     * Check if current user is a WordPress admin
     */
    private function is_wp_admin(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Inject CSS to hide WordPress chrome in fullscreen mode
     * Admins see WP sidebar, non-admins get fullscreen experience
     */
    public function inject_fullscreen_styles(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'peanut-app') {
            return;
        }

        // Admins get WordPress nav visible
        if ($this->is_wp_admin()) {
            $this->inject_admin_styles();
            return;
        }

        // Non-admins (team members) get fullscreen mode
        ?>
        <style>
            /* Hide WordPress admin chrome for fullscreen React app */
            html.wp-toolbar {
                padding-top: 0 !important;
            }
            #wpadminbar {
                display: none !important;
            }
            #adminmenumain,
            #adminmenuback,
            #adminmenuwrap {
                display: none !important;
            }
            #wpcontent,
            #wpfooter {
                margin-left: 0 !important;
            }
            #wpbody-content {
                padding-bottom: 0 !important;
            }
            .update-nag,
            .updated,
            .notice,
            .error:not(.peanut-error) {
                display: none !important;
            }
            #wpfooter {
                display: none !important;
            }
            /* Fullscreen container */
            .peanut-fullscreen-app {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 99999;
                background: #f8fafc;
                overflow: hidden;
            }
            #peanut-app {
                height: 100%;
                width: 100%;
                overflow: auto;
            }

            /* ===== CSS ISOLATION FOR TAILWIND ===== */
            /* Override WordPress's .hidden class that may have !important */
            #peanut-app .hidden {
                display: none;
            }
            /* Tailwind responsive utilities - override any WP conflicts */
            @media (min-width: 768px) {
                #peanut-app .md\:block {
                    display: block !important;
                }
                #peanut-app .md\:hidden {
                    display: none !important;
                }
                #peanut-app .md\:flex {
                    display: flex !important;
                }
                #peanut-app .md\:ml-56 {
                    margin-left: 14rem !important;
                }
                #peanut-app .md\:ml-16 {
                    margin-left: 4rem !important;
                }
            }
            /* Ensure fixed positioning works inside the app */
            #peanut-app .fixed {
                position: fixed;
            }
            /* Ensure sidebar z-index is high enough */
            #peanut-app aside.fixed {
                z-index: 100;
            }
            /* Reset WordPress button styles inside the app */
            #peanut-app button {
                box-shadow: none;
                text-shadow: none;
            }
            /* Ensure Tailwind button colors override WordPress admin */
            #peanut-app .bg-primary-600 {
                background-color: var(--color-primary-600, #2563eb) !important;
            }
            #peanut-app .bg-primary-700 {
                background-color: var(--color-primary-700, #1d4ed8) !important;
            }
            #peanut-app .text-white {
                color: #ffffff !important;
            }
            #peanut-app .bg-red-600 {
                background-color: #dc2626 !important;
            }
            #peanut-app .bg-green-600 {
                background-color: #16a34a !important;
            }
            #peanut-app .bg-slate-100 {
                background-color: #f1f5f9 !important;
            }
            /* Reset WordPress form field styles */
            #peanut-app input[type="text"],
            #peanut-app input[type="email"],
            #peanut-app input[type="url"],
            #peanut-app input[type="password"],
            #peanut-app input[type="search"],
            #peanut-app input[type="number"],
            #peanut-app textarea,
            #peanut-app select {
                background-color: white;
                border: 1px solid #e2e8f0;
                box-shadow: none;
                border-radius: 0.375rem;
            }
            /* Reset WordPress link colors */
            #peanut-app a {
                color: inherit;
                text-decoration: none;
            }
            #peanut-app a:hover {
                color: inherit;
            }
            #peanut-app a:focus {
                box-shadow: none;
                outline: none;
            }
        </style>
        <?php
    }

    /**
     * Inject styles for admins - WP nav visible alongside Peanut Suite
     */
    private function inject_admin_styles(): void {
        ?>
        <style>
            /* Admin mode: Keep WP sidebar and admin bar visible */

            /* Ensure WP admin bar stays on top */
            #wpadminbar {
                z-index: 100000 !important;
            }

            /* Ensure WP admin menu stays visible */
            #adminmenumain,
            #adminmenuback,
            #adminmenuwrap {
                z-index: 99998 !important;
            }

            /* Position Peanut app alongside WP nav */
            .peanut-fullscreen-app {
                position: fixed;
                top: 32px; /* Below WP admin bar */
                left: 160px; /* Next to WP menu (collapsed width) */
                right: 0;
                bottom: 0;
                z-index: 9999; /* Below WP admin elements */
                background: #f8fafc;
                overflow: hidden;
            }

            /* WP menu expanded (default on large screens) */
            @media screen and (min-width: 961px) {
                .peanut-fullscreen-app {
                    left: 160px;
                }
                body.folded .peanut-fullscreen-app {
                    left: 36px;
                }
            }

            /* WP menu auto-folded on medium screens */
            @media screen and (max-width: 960px) and (min-width: 783px) {
                .peanut-fullscreen-app {
                    left: 36px;
                }
                body:not(.auto-fold) .peanut-fullscreen-app {
                    left: 160px;
                }
            }

            /* Mobile - full width */
            @media screen and (max-width: 782px) {
                .peanut-fullscreen-app {
                    top: 46px;
                    left: 0;
                }
            }

            #peanut-app {
                height: 100%;
                width: 100%;
                overflow: auto;
            }

            /* Hide WordPress footer and notices */
            #wpfooter {
                display: none !important;
            }
            .update-nag,
            .updated,
            .notice,
            .error:not(.peanut-error) {
                display: none !important;
            }

            /* ===== CSS ISOLATION FOR TAILWIND ===== */
            #peanut-app .hidden {
                display: none;
            }
            @media (min-width: 768px) {
                #peanut-app .md\:block {
                    display: block !important;
                }
                #peanut-app .md\:hidden {
                    display: none !important;
                }
                #peanut-app .md\:flex {
                    display: flex !important;
                }
                #peanut-app .md\:ml-56 {
                    margin-left: 14rem !important;
                }
                #peanut-app .md\:ml-16 {
                    margin-left: 4rem !important;
                }
            }
            #peanut-app .fixed {
                position: fixed;
            }
            /* Offset React sidebar by WP menu width */
            #peanut-app aside.fixed {
                z-index: 100;
                left: 160px !important;
                top: 32px !important;
            }
            body.folded #peanut-app aside.fixed {
                left: 36px !important;
            }
            @media screen and (max-width: 960px) and (min-width: 783px) {
                #peanut-app aside.fixed {
                    left: 36px !important;
                }
            }
            @media screen and (max-width: 782px) {
                #peanut-app aside.fixed {
                    left: 0 !important;
                    top: 46px !important;
                }
            }
            #peanut-app button {
                box-shadow: none;
                text-shadow: none;
            }
            /* Ensure Tailwind button colors override WordPress admin */
            #peanut-app .bg-primary-600 {
                background-color: var(--color-primary-600, #2563eb) !important;
            }
            #peanut-app .bg-primary-700 {
                background-color: var(--color-primary-700, #1d4ed8) !important;
            }
            #peanut-app .text-white {
                color: #ffffff !important;
            }
            #peanut-app .bg-red-600 {
                background-color: #dc2626 !important;
            }
            #peanut-app .bg-green-600 {
                background-color: #16a34a !important;
            }
            #peanut-app .bg-slate-100 {
                background-color: #f1f5f9 !important;
            }
            #peanut-app input[type="text"],
            #peanut-app input[type="email"],
            #peanut-app input[type="url"],
            #peanut-app input[type="password"],
            #peanut-app input[type="search"],
            #peanut-app input[type="number"],
            #peanut-app textarea,
            #peanut-app select {
                background-color: white;
                border: 1px solid #e2e8f0;
                box-shadow: none;
                border-radius: 0.375rem;
            }
            #peanut-app a {
                color: inherit;
                text-decoration: none;
            }
            #peanut-app a:hover {
                color: inherit;
            }
            #peanut-app a:focus {
                box-shadow: none;
                outline: none;
            }
        </style>
        <?php
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        // React mode: Single entry point with fullscreen app
        if ($this->is_react_mode()) {
            // Main menu - launches React app
            add_menu_page(
                __('Peanut Suite', 'peanut-suite'),
                __('Peanut Suite', 'peanut-suite'),
                'peanut_access',
                'peanut-app',
                [$this, 'render_react_app'],
                $this->get_menu_icon(),
                30
            );

            // Legacy menu items that redirect to React app routes
            // These are hidden from menu but handle old bookmarks
            $legacy_pages = [
                'peanut-suite' => 'render_dashboard',
                'peanut-utm-builder' => 'render_utm_builder',
                'peanut-links' => 'render_links',
                'peanut-contacts' => 'render_contacts',
                'peanut-webhooks' => 'render_webhooks',
                'peanut-visitors' => 'render_visitors',
                'peanut-attribution' => 'render_attribution',
                'peanut-analytics' => 'render_analytics',
                'peanut-popups' => 'render_popups',
                'peanut-settings' => 'render_settings',
            ];

            foreach ($legacy_pages as $slug => $callback) {
                add_submenu_page(
                    '', // Hidden from menu (empty string avoids PHP 8.1+ null deprecation)
                    '',
                    '',
                    'manage_options',
                    $slug,
                    [$this, $callback]
                );
            }

            return;
        }

        // Legacy PHP mode: Full menu structure
        // Main menu
        add_menu_page(
            __('Peanut Suite', 'peanut-suite'),
            __('Peanut Suite', 'peanut-suite'),
            'peanut_access',
            'peanut-suite',
            [$this, 'render_dashboard'],
            $this->get_menu_icon(),
            30
        );

        // Dashboard (same as main)
        add_submenu_page(
            'peanut-suite',
            __('Dashboard', 'peanut-suite'),
            __('Dashboard', 'peanut-suite'),
            'manage_options',
            'peanut-suite',
            [$this, 'render_dashboard']
        );

        // UTM Builder
        add_submenu_page(
            'peanut-suite',
            __('UTM Builder', 'peanut-suite'),
            __('UTM Builder', 'peanut-suite'),
            'manage_options',
            'peanut-utm-builder',
            [$this, 'render_utm_builder']
        );

        // Links
        add_submenu_page(
            'peanut-suite',
            __('Links', 'peanut-suite'),
            __('Links', 'peanut-suite'),
            'manage_options',
            'peanut-links',
            [$this, 'render_links']
        );

        // Contacts
        add_submenu_page(
            'peanut-suite',
            __('Contacts', 'peanut-suite'),
            __('Contacts', 'peanut-suite'),
            'manage_options',
            'peanut-contacts',
            [$this, 'render_contacts']
        );

        // Webhooks
        add_submenu_page(
            'peanut-suite',
            __('Webhooks', 'peanut-suite'),
            __('Webhooks', 'peanut-suite'),
            'manage_options',
            'peanut-webhooks',
            [$this, 'render_webhooks']
        );

        // Pro tier features
        if (peanut_is_pro()) {
            add_submenu_page(
                'peanut-suite',
                __('Visitors', 'peanut-suite'),
                __('Visitors', 'peanut-suite'),
                'manage_options',
                'peanut-visitors',
                [$this, 'render_visitors']
            );

            add_submenu_page(
                'peanut-suite',
                __('Attribution', 'peanut-suite'),
                __('Attribution', 'peanut-suite'),
                'manage_options',
                'peanut-attribution',
                [$this, 'render_attribution']
            );

            add_submenu_page(
                'peanut-suite',
                __('Analytics', 'peanut-suite'),
                __('Analytics', 'peanut-suite'),
                'manage_options',
                'peanut-analytics',
                [$this, 'render_analytics']
            );

            add_submenu_page(
                'peanut-suite',
                __('Popups', 'peanut-suite'),
                __('Popups', 'peanut-suite'),
                'manage_options',
                'peanut-popups',
                [$this, 'render_popups']
            );

            add_submenu_page(
                'peanut-suite',
                __('Security', 'peanut-suite'),
                __('Security', 'peanut-suite'),
                'manage_options',
                'peanut-security',
                [$this, 'render_security']
            );

            add_submenu_page(
                'peanut-suite',
                __('Email Reports', 'peanut-suite'),
                __('Email Reports', 'peanut-suite'),
                'manage_options',
                'peanut-reports',
                [$this, 'render_reports']
            );

            add_submenu_page(
                'peanut-suite',
                __('Backlinks', 'peanut-suite'),
                __('Backlinks', 'peanut-suite'),
                'manage_options',
                'peanut-backlinks',
                [$this, 'render_backlinks']
            );
        }

        // Agency tier features
        if (peanut_is_agency()) {
            add_submenu_page(
                'peanut-suite',
                __('Monitor', 'peanut-suite'),
                __('Monitor', 'peanut-suite'),
                'manage_options',
                'peanut-monitor',
                [$this, 'render_monitor']
            );
        }

        // Settings (always visible)
        add_submenu_page(
            'peanut-suite',
            __('Settings', 'peanut-suite'),
            __('Settings', 'peanut-suite'),
            'manage_options',
            'peanut-settings',
            [$this, 'render_settings']
        );

        // Hidden pages (no menu item) - use empty string to avoid PHP 8.1+ null deprecation
        add_submenu_page(
            '',
            __('UTM Library', 'peanut-suite'),
            __('UTM Library', 'peanut-suite'),
            'manage_options',
            'peanut-utm-library',
            [$this, 'render_utm_library']
        );

        add_submenu_page(
            '',
            __('Visitor Details', 'peanut-suite'),
            __('Visitor Details', 'peanut-suite'),
            'manage_options',
            'peanut-visitor-detail',
            [$this, 'render_visitor_detail']
        );

        add_submenu_page(
            '',
            __('Popup Builder', 'peanut-suite'),
            __('Popup Builder', 'peanut-suite'),
            'manage_options',
            'peanut-popup-builder',
            [$this, 'render_popup_builder']
        );

        add_submenu_page(
            '',
            __('Site Details', 'peanut-suite'),
            __('Site Details', 'peanut-suite'),
            'manage_options',
            'peanut-site-detail',
            [$this, 'render_site_detail']
        );
    }

    /**
     * Render fullscreen React app
     */
    public function render_react_app(): void {
        ?>
        <div class="peanut-fullscreen-app">
            <div id="peanut-app">
                <div style="display: flex; align-items: center; justify-content: center; height: 100%; flex-direction: column; color: #64748b;">
                    <div style="width: 40px; height: 40px; border: 3px solid #e2e8f0; border-top-color: #3b82f6; border-radius: 50%; animation: spin 0.8s linear infinite;"></div>
                    <p style="margin-top: 16px;">Loading Peanut Suite...</p>
                </div>
            </div>
        </div>
        <style>
            @keyframes spin { to { transform: rotate(360deg); } }
        </style>
        <?php
    }

    // ========================================
    // Page Render Methods
    // ========================================

    public function render_dashboard(): void {
        $this->pages->render('dashboard');
    }

    public function render_utm_builder(): void {
        $this->pages->render('utm-builder');
    }

    public function render_utm_library(): void {
        $this->pages->render('utm-library');
    }

    public function render_links(): void {
        $this->pages->render('links');
    }

    public function render_contacts(): void {
        $this->pages->render('contacts');
    }

    public function render_webhooks(): void {
        $this->pages->render('webhooks');
    }

    public function render_visitors(): void {
        $this->pages->render('visitors');
    }

    public function render_visitor_detail(): void {
        $this->pages->render('visitor-detail');
    }

    public function render_attribution(): void {
        $this->pages->render('attribution');
    }

    public function render_analytics(): void {
        $this->pages->render('analytics');
    }

    public function render_popups(): void {
        $this->pages->render('popups');
    }

    public function render_popup_builder(): void {
        $this->pages->render('popup-builder');
    }

    public function render_monitor(): void {
        $this->pages->render('monitor');
    }

    public function render_site_detail(): void {
        $this->pages->render('site-detail');
    }

    public function render_settings(): void {
        $this->pages->render('settings');
    }

    public function render_security(): void {
        $this->pages->render('security');
    }

    public function render_reports(): void {
        $this->pages->render('reports');
    }

    public function render_backlinks(): void {
        $this->pages->render('backlinks');
    }

    // ========================================
    // Legacy Methods (kept for compatibility)
    // ========================================

    /**
     * @deprecated Use Peanut_Admin_Assets instead
     */
    public function enqueue_scripts(string $hook): void {
        // Handled by Peanut_Admin_Assets
    }

    /**
     * @deprecated Use Peanut_Admin_Assets instead
     */
    public function enqueue_styles(string $hook): void {
        // Handled by Peanut_Admin_Assets
    }

    /**
     * Get menu icon (SVG)
     */
    private function get_menu_icon(): string {
        // Peanut icon as base64 SVG
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M8 12a4 4 0 0 1 8 0"/><circle cx="9" cy="9" r="1"/><circle cx="15" cy="9" r="1"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
