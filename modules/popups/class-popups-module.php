<?php
/**
 * Popups Module
 *
 * Create exit-intent popups, slide-ins, and modals to capture leads.
 * Pro tier feature.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Popups_Module {

    /**
     * Initialize module
     */
    public function init(): void {
        $this->load_dependencies();

        // Register API routes
        add_action('peanut_register_routes', [$this, 'register_routes']);

        // Register hooks for frontend display
        add_action('wp_footer', [$this, 'render_popups']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

        // Register hooks for dashboard stats
        add_filter('peanut_dashboard_stats', [$this, 'add_dashboard_stats'], 10, 2);

        // AJAX handlers for popup interactions
        add_action('wp_ajax_peanut_popup_view', [$this, 'handle_popup_view']);
        add_action('wp_ajax_nopriv_peanut_popup_view', [$this, 'handle_popup_view']);
        add_action('wp_ajax_peanut_popup_convert', [$this, 'handle_popup_convert']);
        add_action('wp_ajax_nopriv_peanut_popup_convert', [$this, 'handle_popup_convert']);
        add_action('wp_ajax_peanut_popup_dismiss', [$this, 'handle_popup_dismiss']);
        add_action('wp_ajax_nopriv_peanut_popup_dismiss', [$this, 'handle_popup_dismiss']);
    }

    /**
     * Load required files
     */
    private function load_dependencies(): void {
        require_once __DIR__ . '/class-popups-database.php';
        require_once __DIR__ . '/class-popups-renderer.php';
        require_once __DIR__ . '/class-popups-triggers.php';
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        require_once __DIR__ . '/api/class-popups-controller.php';
        $controller = new Popups_Controller();
        $controller->register_routes();
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets(): void {
        // Only load if there are active popups for this page
        $popups = $this->get_popups_for_page();
        if (empty($popups)) {
            return;
        }

        wp_enqueue_style(
            'peanut-popups',
            PEANUT_PLUGIN_URL . 'modules/popups/assets/popups.css',
            [],
            PEANUT_VERSION
        );

        wp_enqueue_script(
            'peanut-popups',
            PEANUT_PLUGIN_URL . 'modules/popups/assets/popups.js',
            [],
            PEANUT_VERSION,
            true
        );

        wp_localize_script('peanut-popups', 'peanutPopups', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('peanut_popup_action'),
            'popups' => $this->prepare_popups_for_frontend($popups),
        ]);
    }

    /**
     * Render popups in footer
     */
    public function render_popups(): void {
        $popups = $this->get_popups_for_page();
        if (empty($popups)) {
            return;
        }

        $renderer = new Popups_Renderer();
        foreach ($popups as $popup) {
            echo $renderer->render($popup);
        }
    }

    /**
     * Get popups that should display on current page
     */
    private function get_popups_for_page(): array {
        global $wpdb;
        $table = Popups_Database::popups_table();

        // Get all active popups
        $popups = $wpdb->get_results(
            "SELECT * FROM $table WHERE status = 'active' ORDER BY priority DESC"
        );

        if (empty($popups)) {
            return [];
        }

        $triggers = new Popups_Triggers();
        $matching = [];

        foreach ($popups as $popup) {
            // Check if popup should show on this page
            if ($triggers->should_display($popup)) {
                // Check visitor hasn't dismissed or already converted
                if (!$this->visitor_has_interacted($popup->id)) {
                    $matching[] = $popup;
                }
            }
        }

        return $matching;
    }

    /**
     * Prepare popups data for frontend JavaScript
     */
    private function prepare_popups_for_frontend(array $popups): array {
        $prepared = [];

        foreach ($popups as $popup) {
            $settings = json_decode($popup->settings, true) ?? [];
            $triggers = json_decode($popup->triggers, true) ?? [];

            $prepared[] = [
                'id' => $popup->id,
                'type' => $popup->type,
                'position' => $popup->position,
                'triggers' => $triggers,
                'settings' => [
                    'animation' => $settings['animation'] ?? 'fade',
                    'overlay' => $settings['overlay'] ?? true,
                    'close_on_overlay' => $settings['close_on_overlay'] ?? true,
                    'close_on_esc' => $settings['close_on_esc'] ?? true,
                ],
            ];
        }

        return $prepared;
    }

    /**
     * Check if visitor has already interacted with popup
     */
    private function visitor_has_interacted(int $popup_id): bool {
        // Check cookie
        $cookie_name = 'peanut_popup_' . $popup_id;
        if (isset($_COOKIE[$cookie_name])) {
            return true;
        }

        // For logged in users, check database
        if (is_user_logged_in()) {
            global $wpdb;
            $table = Popups_Database::interactions_table();
            $user_id = get_current_user_id();

            $interaction = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table
                 WHERE popup_id = %d AND user_id = %d AND action IN ('convert', 'dismiss')
                 LIMIT 1",
                $popup_id,
                $user_id
            ));

            return !empty($interaction);
        }

        return false;
    }

    /**
     * Handle popup view AJAX
     */
    public function handle_popup_view(): void {
        check_ajax_referer('peanut_popup_action', 'nonce');

        // Rate limit the unauthenticated endpoint keyed on the TRUSTED transport IP
        // (REMOTE_ADDR), never a spoofable X-Forwarded-For, so a flood cannot inflate
        // stats or hammer the DB. Mirrors the visitors /track rate-limit budget.
        if (!Peanut_Security::check_rate_limit('popup_view', 120, MINUTE_IN_SECONDS, $this->trusted_ip())) {
            wp_send_json_error(['message' => 'Rate limit exceeded'], 429);
        }

        $popup_id = (int) ($_POST['popup_id'] ?? 0);
        if (!$popup_id) {
            wp_send_json_error('Invalid popup ID');
        }

        $this->log_interaction($popup_id, 'view');
        // Only count a given visitor's view once per dedupe window so refreshes /
        // replays don't inflate the counter.
        if ($this->should_count_stat($popup_id, 'view')) {
            $this->increment_stat($popup_id, 'views');
        }

        wp_send_json_success();
    }

    /**
     * Handle popup conversion AJAX
     */
    public function handle_popup_convert(): void {
        check_ajax_referer('peanut_popup_action', 'nonce');

        // Rate limit the unauthenticated lead-capture endpoint keyed on the TRUSTED
        // transport IP (REMOTE_ADDR), never a spoofable X-Forwarded-For. Without this
        // anyone can flood a client's CRM with fabricated contacts/interactions.
        // Tighter budget than views since each convert can touch the contacts table.
        if (!Peanut_Security::check_rate_limit('popup_convert', 30, MINUTE_IN_SECONDS, $this->trusted_ip())) {
            wp_send_json_error(['message' => 'Rate limit exceeded'], 429);
        }

        $popup_id = (int) ($_POST['popup_id'] ?? 0);
        if (!$popup_id) {
            wp_send_json_error('Invalid popup ID');
        }

        $form_data = is_array($_POST['form_data'] ?? null) ? $_POST['form_data'] : [];

        // Log interaction
        $this->log_interaction($popup_id, 'convert', $form_data);
        // Dedupe the stat bump so a visitor re-submitting doesn't inflate conversions.
        if ($this->should_count_stat($popup_id, 'convert')) {
            $this->increment_stat($popup_id, 'conversions');
        }

        // Create contact if Contacts module is active
        if (peanut_is_module_active('contacts') && !empty($form_data['email'])) {
            $this->create_contact_from_popup($popup_id, $form_data);
        }

        // Set cookie to not show again
        $this->set_interaction_cookie($popup_id, 'converted');

        // Fire action for integrations
        do_action('peanut_popup_converted', $popup_id, $form_data);

        wp_send_json_success(['message' => 'Conversion recorded']);
    }

    /**
     * Handle popup dismiss AJAX
     */
    public function handle_popup_dismiss(): void {
        check_ajax_referer('peanut_popup_action', 'nonce');

        $popup_id = (int) ($_POST['popup_id'] ?? 0);
        if (!$popup_id) {
            wp_send_json_error('Invalid popup ID');
        }

        $this->log_interaction($popup_id, 'dismiss');

        // Set cookie based on popup settings
        global $wpdb;
        $table = Popups_Database::popups_table();
        $popup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $popup_id));

        if ($popup) {
            $settings = json_decode($popup->settings, true) ?? [];
            $hide_days = $settings['hide_after_dismiss_days'] ?? 7;
            $this->set_interaction_cookie($popup_id, 'dismissed', $hide_days);
        }

        wp_send_json_success();
    }

    /**
     * Log popup interaction
     */
    private function log_interaction(int $popup_id, string $action, array $data = []): void {
        global $wpdb;
        $table = Popups_Database::interactions_table();

        $wpdb->insert($table, [
            'popup_id' => $popup_id,
            'user_id' => get_current_user_id() ?: null,
            'visitor_id' => $this->get_visitor_id(),
            'action' => $action,
            // Sanitize the attacker-controlled form payload before persisting so it
            // can't carry stored XSS / markup into the admin analytics views.
            'data' => !empty($data) ? wp_json_encode($this->sanitize_form_data($data)) : null,
            'page_url' => esc_url_raw($_SERVER['HTTP_REFERER'] ?? ''),
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Recursively sanitize captured popup form data before storage.
     *
     * Keys and scalar values are run through sanitize_text_field; nested arrays are
     * sanitized recursively. This is defence-in-depth for the interaction log — the
     * capture is unauthenticated, so nothing here is trusted.
     */
    private function sanitize_form_data(array $data): array {
        $clean = [];
        foreach ($data as $key => $value) {
            $clean_key = sanitize_text_field((string) $key);
            if (is_array($value)) {
                $clean[$clean_key] = $this->sanitize_form_data($value);
            } else {
                $clean[$clean_key] = sanitize_text_field((string) $value);
            }
        }
        return $clean;
    }

    /**
     * The trusted client IP for abuse controls.
     *
     * Uses the transport-level REMOTE_ADDR only — proxy headers like
     * X-Forwarded-For / CF-Connecting-IP are attacker-controllable and would let a
     * flooder trivially rotate past an IP-keyed rate limit.
     */
    private function trusted_ip(): string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($ip) ? $ip : '';
    }

    /**
     * Lightweight per-visitor stat dedupe.
     *
     * Returns true at most once per visitor / popup / action inside a short window
     * so page refreshes or replayed requests don't inflate view/conversion counters.
     * Keyed on the visitor id (mirrors the visitors module's per-visitor throttle).
     */
    private function should_count_stat(int $popup_id, string $action): bool {
        $visitor = $this->get_visitor_id();
        $key = 'peanut_popup_stat_' . md5($action . '|' . $popup_id . '|' . $visitor);

        if (get_transient($key) !== false) {
            return false;
        }

        set_transient($key, 1, HOUR_IN_SECONDS);
        return true;
    }

    /**
     * Increment popup stat
     */
    private function increment_stat(int $popup_id, string $field): void {
        global $wpdb;
        $table = Popups_Database::popups_table();

        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET {$field} = {$field} + 1 WHERE id = %d",
            $popup_id
        ));
    }

    /**
     * Get or create visitor ID
     */
    private function get_visitor_id(): string {
        $cookie_name = 'peanut_visitor_id';

        if (isset($_COOKIE[$cookie_name])) {
            return sanitize_text_field($_COOKIE[$cookie_name]);
        }

        $visitor_id = wp_generate_uuid4();
        if (! headers_sent()) {
            setcookie($cookie_name, $visitor_id, time() + (365 * DAY_IN_SECONDS), '/', '', is_ssl(), true);
        }

        return $visitor_id;
    }

    /**
     * Set interaction cookie
     */
    private function set_interaction_cookie(int $popup_id, string $action, int $days = 30): void {
        $cookie_name = 'peanut_popup_' . $popup_id;
        $expiry = time() + ($days * DAY_IN_SECONDS);
        setcookie($cookie_name, $action, $expiry, '/', '', is_ssl(), true);
    }

    /**
     * Create contact from popup submission
     */
    private function create_contact_from_popup(int $popup_id, array $form_data): void {
        global $wpdb;

        // Get popup for source attribution
        $popups_table = Popups_Database::popups_table();
        $popup = $wpdb->get_row($wpdb->prepare("SELECT name FROM $popups_table WHERE id = %d", $popup_id));

        $contacts_table = $wpdb->prefix . 'peanut_contacts';

        // Check if contact exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $contacts_table WHERE email = %s",
            sanitize_email($form_data['email'])
        ));

        if ($existing) {
            // SECURITY: this path is reachable UNAUTHENTICATED and is keyed only on an
            // attacker-supplied email. Never overwrite an existing contact's identity
            // fields (name/company) from a public popup submission — otherwise anyone
            // can clobber a client's CRM records by POSTing a known email. Only make
            // the non-destructive interaction touch and append the activity record.
            $wpdb->update(
                $contacts_table,
                ['updated_at' => current_time('mysql')],
                ['id' => $existing]
            );

            // Log activity
            $this->log_contact_activity($existing, 'popup_submit', [
                'popup_id' => $popup_id,
                'popup_name' => $popup->name ?? 'Unknown',
            ]);
        } else {
            // Create new contact
            $inserted = $wpdb->insert($contacts_table, [
                'user_id' => get_current_user_id() ?: 1,
                'email' => sanitize_email($form_data['email']),
                'first_name' => sanitize_text_field($form_data['first_name'] ?? ''),
                'last_name' => sanitize_text_field($form_data['last_name'] ?? ''),
                'status' => 'lead',
                'source' => 'popup',
                'source_detail' => $popup->name ?? 'Popup #' . $popup_id,
                'score' => 10,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);

            // Bail out loudly if the insert failed instead of reading a stale
            // insert_id and logging activity against the wrong (or no) contact.
            if ($inserted === false) {
                if (function_exists('peanut_log_error')) {
                    peanut_log_error(
                        'Failed to create contact from popup: ' . $wpdb->last_error,
                        'error',
                        'popups'
                    );
                }
                return;
            }

            $contact_id = (int) $wpdb->insert_id;

            // Log activity
            $this->log_contact_activity($contact_id, 'created', [
                'source' => 'popup',
                'popup_id' => $popup_id,
            ]);
        }

        do_action('peanut_contact_from_popup', $form_data, $popup_id);
    }

    /**
     * Log contact activity
     */
    private function log_contact_activity(int $contact_id, string $type, array $data): void {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_contact_activities';

        $wpdb->insert($table, [
            'contact_id' => $contact_id,
            'type' => $type,
            'description' => wp_json_encode($data),
            'created_at' => current_time('mysql'),
        ]);
    }

    /**
     * Add stats to dashboard
     */
    public function add_dashboard_stats(array $stats, string $period): array {
        global $wpdb;
        $table = Popups_Database::popups_table();

        // Total active popups
        $stats['popups_active'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table WHERE status = 'active'"
        );

        // Total views
        $stats['popups_views'] = (int) $wpdb->get_var(
            "SELECT SUM(views) FROM $table"
        );

        // Total conversions
        $stats['popups_conversions'] = (int) $wpdb->get_var(
            "SELECT SUM(conversions) FROM $table"
        );

        // Conversion rate
        if ($stats['popups_views'] > 0) {
            $stats['popups_conversion_rate'] = round(
                ($stats['popups_conversions'] / $stats['popups_views']) * 100,
                2
            );
        } else {
            $stats['popups_conversion_rate'] = 0;
        }

        // Top performing popup
        $top_popup = $wpdb->get_row(
            "SELECT id, name, views, conversions,
                    CASE WHEN views > 0 THEN (conversions / views * 100) ELSE 0 END as rate
             FROM $table
             WHERE status = 'active' AND views > 0
             ORDER BY rate DESC
             LIMIT 1"
        );

        $stats['popups_top_performer'] = $top_popup;

        return $stats;
    }
}
