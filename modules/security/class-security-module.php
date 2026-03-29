<?php
/**
 * Security Module
 *
 * Comprehensive WordPress security features including:
 * - Hide login URL
 * - Login attempt limiting
 * - IP blocking
 * - Login notifications
 * - Two-factor authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

class Security_Module {

    /**
     * Module instance
     */
    private static ?Security_Module $instance = null;

    /**
     * Settings
     */
    private array $settings = [];

    /**
     * Get singleton instance
     */
    public static function instance(): Security_Module {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    public function __construct() {
        $this->settings = get_option('peanut_security_settings', $this->get_defaults());
        $this->init_hooks();
    }

    /**
     * Get default settings
     */
    private function get_defaults(): array {
        return [
            // Hide Login
            'hide_login_enabled' => false,
            'login_slug' => 'secure-login',
            'redirect_slug' => '404',

            // Login Limiting
            'limit_login_enabled' => true,
            'max_attempts' => 5,
            'lockout_duration' => 30, // minutes
            'lockout_increment' => true,

            // IP Blocking
            'ip_whitelist' => [],
            'ip_blacklist' => [],

            // Notifications
            'notify_login_success' => false,
            'notify_login_failed' => true,
            'notify_lockout' => true,
            'notify_email' => '',

            // 2FA
            '2fa_enabled' => false,
            '2fa_method' => 'email', // email, totp
            '2fa_roles' => ['administrator'],
        ];
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Hide login URL
        if ($this->settings['hide_login_enabled']) {
            add_action('init', [$this, 'hide_login_init'], 1);
            add_filter('site_url', [$this, 'filter_login_url'], 10, 4);
            add_filter('wp_redirect', [$this, 'filter_wp_redirect'], 10, 2);
            add_action('wp_loaded', [$this, 'handle_login_redirect']);
        }

        // Login attempt limiting
        if ($this->settings['limit_login_enabled']) {
            add_filter('authenticate', [$this, 'check_login_attempts'], 30, 3);
            add_action('wp_login_failed', [$this, 'log_failed_attempt']);
            add_action('wp_login', [$this, 'clear_login_attempts'], 10, 2);
        }

        // Login notifications
        add_action('wp_login', [$this, 'notify_login_success'], 10, 2);
        add_action('wp_login_failed', [$this, 'notify_login_failed']);

        // 2FA
        if ($this->settings['2fa_enabled']) {
            add_action('wp_login', [$this, 'init_2fa_challenge'], 5, 2);
            add_action('login_form_2fa', [$this, 'render_2fa_form']);
            add_action('login_form_2fa_verify', [$this, 'verify_2fa_code']);
        }

        // Admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);

        // REST API
        add_action('rest_api_init', [$this, 'register_routes']);

        // Cron for cleanup
        add_action('peanut_security_cleanup', [$this, 'cleanup_old_data']);
        if (!wp_next_scheduled('peanut_security_cleanup')) {
            wp_schedule_event(time(), 'daily', 'peanut_security_cleanup');
        }
    }

    /**
     * Initialize hide login
     */
    public function hide_login_init(): void {
        // Block direct access to wp-login.php
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        if (strpos($request_uri, 'wp-login.php') !== false && !$this->is_login_allowed()) {
            $this->redirect_away();
        }

        // Check for custom login slug
        $login_slug = $this->settings['login_slug'];
        if (strpos($request_uri, '/' . $login_slug) !== false) {
            // Set cookie to allow login
            setcookie('peanut_login_access', wp_hash($login_slug), time() + 300, COOKIEPATH, COOKIE_DOMAIN);
            wp_safe_redirect(wp_login_url());
            exit;
        }
    }

    /**
     * Check if login is allowed
     */
    private function is_login_allowed(): bool {
        // Allow AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return true;
        }

        // Allow POST requests (form submissions)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return true;
        }

        // Check for access cookie
        $cookie = $_COOKIE['peanut_login_access'] ?? '';
        if ($cookie === wp_hash($this->settings['login_slug'])) {
            return true;
        }

        // Allow logged in users
        if (is_user_logged_in()) {
            return true;
        }

        return false;
    }

    /**
     * Redirect away from login
     */
    private function redirect_away(): void {
        $redirect = $this->settings['redirect_slug'];

        if ($redirect === '404') {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            include get_404_template();
            exit;
        }

        wp_safe_redirect(home_url('/' . $redirect));
        exit;
    }

    /**
     * Filter login URL
     */
    public function filter_login_url(string $url, string $path, $scheme, $blog_id): string {
        if (strpos($url, 'wp-login.php') !== false && !is_admin()) {
            $url = str_replace('wp-login.php', $this->settings['login_slug'], $url);
        }
        return $url;
    }

    /**
     * Filter redirects
     */
    public function filter_wp_redirect(string $location, int $status): string {
        if (strpos($location, 'wp-login.php') !== false && !is_admin()) {
            $location = str_replace('wp-login.php', $this->settings['login_slug'], $location);
        }
        return $location;
    }

    /**
     * Handle login redirect
     */
    public function handle_login_redirect(): void {
        // Already handled in init
    }

    /**
     * Check login attempts before authentication
     */
    public function check_login_attempts($user, string $username, string $password) {
        if (empty($username)) {
            return $user;
        }

        $ip = $this->get_client_ip();

        // Check whitelist
        if ($this->is_ip_whitelisted($ip)) {
            return $user;
        }

        // Check blacklist
        if ($this->is_ip_blacklisted($ip)) {
            return new WP_Error(
                'peanut_ip_blocked',
                __('Your IP address has been blocked. Please contact the site administrator.', 'peanut-suite')
            );
        }

        // Check lockout
        $lockout = $this->get_lockout_status($ip);
        if ($lockout['locked']) {
            $remaining = ceil(($lockout['until'] - time()) / 60);
            return new WP_Error(
                'peanut_too_many_attempts',
                sprintf(
                    __('Too many failed login attempts. Please try again in %d minutes.', 'peanut-suite'),
                    $remaining
                )
            );
        }

        return $user;
    }

    /**
     * Log failed login attempt
     */
    public function log_failed_attempt(string $username): void {
        $ip = $this->get_client_ip();

        if ($this->is_ip_whitelisted($ip)) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'peanut_login_attempts';

        $wpdb->insert($table, [
            'ip_address' => $ip,
            'username' => sanitize_user($username),
            'attempt_time' => current_time('mysql'),
            'status' => 'failed',
        ]);

        // Check if we should lock out
        $attempts = $this->count_recent_attempts($ip);
        if ($attempts >= $this->settings['max_attempts']) {
            $this->create_lockout($ip, $attempts);
        }
    }

    /**
     * Count recent failed attempts
     */
    private function count_recent_attempts(string $ip): int {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_login_attempts';

        // Count attempts in the last hour
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
            WHERE ip_address = %s
            AND status = 'failed'
            AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $ip
        ));
    }

    /**
     * Create lockout
     */
    private function create_lockout(string $ip, int $attempts): void {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_lockouts';

        // Calculate duration with increment
        $duration = $this->settings['lockout_duration'] * 60; // Convert to seconds
        if ($this->settings['lockout_increment']) {
            $previous_lockouts = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
                $ip
            ));
            $duration = $duration * ($previous_lockouts + 1);
        }

        $wpdb->insert($table, [
            'ip_address' => $ip,
            'lockout_until' => date('Y-m-d H:i:s', time() + $duration),
            'attempts' => $attempts,
            'created_at' => current_time('mysql'),
        ]);

        // Send notification
        if ($this->settings['notify_lockout']) {
            $this->send_notification('lockout', [
                'ip' => $ip,
                'attempts' => $attempts,
                'duration' => $duration / 60,
            ]);
        }
    }

    /**
     * Get lockout status
     */
    private function get_lockout_status(string $ip): array {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_lockouts';

        $lockout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
            WHERE ip_address = %s
            AND lockout_until > NOW()
            ORDER BY lockout_until DESC
            LIMIT 1",
            $ip
        ));

        if ($lockout) {
            return [
                'locked' => true,
                'until' => strtotime($lockout->lockout_until),
            ];
        }

        return ['locked' => false];
    }

    /**
     * Clear login attempts on successful login
     */
    public function clear_login_attempts(string $username, WP_User $user): void {
        $ip = $this->get_client_ip();

        global $wpdb;

        // Clear attempts
        $wpdb->delete(
            $wpdb->prefix . 'peanut_login_attempts',
            ['ip_address' => $ip]
        );

        // Clear lockouts
        $wpdb->delete(
            $wpdb->prefix . 'peanut_lockouts',
            ['ip_address' => $ip]
        );

        // Log successful login
        $wpdb->insert($wpdb->prefix . 'peanut_login_attempts', [
            'ip_address' => $ip,
            'username' => $username,
            'attempt_time' => current_time('mysql'),
            'status' => 'success',
        ]);
    }

    /**
     * Notify on successful login
     */
    public function notify_login_success(string $username, WP_User $user): void {
        if (!$this->settings['notify_login_success']) {
            return;
        }

        // Only notify for specified roles
        $notify_roles = ['administrator', 'editor'];
        if (!array_intersect($notify_roles, $user->roles)) {
            return;
        }

        $this->send_notification('login_success', [
            'username' => $username,
            'ip' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'time' => current_time('mysql'),
        ]);
    }

    /**
     * Notify on failed login
     */
    public function notify_login_failed(string $username): void {
        if (!$this->settings['notify_login_failed']) {
            return;
        }

        // Only notify after multiple failures
        $ip = $this->get_client_ip();
        $attempts = $this->count_recent_attempts($ip);

        if ($attempts >= 3) {
            $this->send_notification('login_failed', [
                'username' => $username,
                'ip' => $ip,
                'attempts' => $attempts,
                'time' => current_time('mysql'),
            ]);
        }
    }

    /**
     * Send notification email
     */
    private function send_notification(string $type, array $data): void {
        $email = $this->settings['notify_email'] ?: get_option('admin_email');
        $site_name = get_bloginfo('name');

        switch ($type) {
            case 'login_success':
                $subject = sprintf(__('[%s] New Admin Login', 'peanut-suite'), $site_name);
                $message = sprintf(
                    __("A user has logged in to your site.\n\nUsername: %s\nIP Address: %s\nTime: %s\nBrowser: %s", 'peanut-suite'),
                    $data['username'],
                    $data['ip'],
                    $data['time'],
                    $data['user_agent']
                );
                break;

            case 'login_failed':
                $subject = sprintf(__('[%s] Failed Login Attempts', 'peanut-suite'), $site_name);
                $message = sprintf(
                    __("Multiple failed login attempts detected.\n\nUsername tried: %s\nIP Address: %s\nAttempts: %d\nTime: %s", 'peanut-suite'),
                    $data['username'],
                    $data['ip'],
                    $data['attempts'],
                    $data['time']
                );
                break;

            case 'lockout':
                $subject = sprintf(__('[%s] IP Lockout', 'peanut-suite'), $site_name);
                $message = sprintf(
                    __("An IP address has been locked out due to too many failed attempts.\n\nIP Address: %s\nFailed Attempts: %d\nLockout Duration: %d minutes", 'peanut-suite'),
                    $data['ip'],
                    $data['attempts'],
                    $data['duration']
                );
                break;

            default:
                return;
        }

        wp_mail($email, $subject, $message);
    }

    /**
     * Initialize 2FA challenge
     */
    public function init_2fa_challenge(string $username, WP_User $user): void {
        // Check if user's role requires 2FA
        if (!array_intersect($this->settings['2fa_roles'], $user->roles)) {
            return;
        }

        // Check if 2FA is set up for this user
        $secret = get_user_meta($user->ID, 'peanut_2fa_secret', true);
        if (empty($secret) && $this->settings['2fa_method'] === 'totp') {
            return; // TOTP not set up
        }

        // Generate and send code for email method
        if ($this->settings['2fa_method'] === 'email') {
            $code = $this->generate_2fa_code($user->ID);
            $this->send_2fa_email($user, $code);
        }

        // Log user out and redirect to 2FA form
        wp_logout();

        // Store user ID in transient for verification
        $token = wp_generate_password(32, false);
        set_transient('peanut_2fa_' . $token, $user->ID, 10 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg([
            'action' => '2fa',
            'token' => $token,
        ], wp_login_url()));
        exit;
    }

    /**
     * Generate 2FA code
     */
    private function generate_2fa_code(int $user_id): string {
        $code = sprintf('%06d', mt_rand(0, 999999));
        set_transient('peanut_2fa_code_' . $user_id, wp_hash($code), 10 * MINUTE_IN_SECONDS);
        return $code;
    }

    /**
     * Send 2FA email
     */
    private function send_2fa_email(WP_User $user, string $code): void {
        $subject = sprintf(__('[%s] Your Login Code', 'peanut-suite'), get_bloginfo('name'));
        $message = sprintf(
            __("Your verification code is: %s\n\nThis code will expire in 10 minutes.\n\nIf you didn't request this code, please ignore this email.", 'peanut-suite'),
            $code
        );

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Render 2FA form
     */
    public function render_2fa_form(): void {
        $token = $_GET['token'] ?? '';
        $user_id = get_transient('peanut_2fa_' . $token);

        if (!$user_id) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $user = get_user_by('ID', $user_id);
        $error = $_GET['error'] ?? '';

        login_header(__('Two-Factor Authentication', 'peanut-suite'));
        ?>
        <form name="2fa_form" id="2fa_form" action="<?php echo esc_url(wp_login_url()); ?>?action=2fa_verify" method="post">
            <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">

            <?php if ($error): ?>
                <div id="login_error">
                    <?php echo esc_html__('Invalid or expired code. Please try again.', 'peanut-suite'); ?>
                </div>
            <?php endif; ?>

            <p><?php printf(__('A verification code has been sent to %s', 'peanut-suite'), $this->mask_email($user->user_email)); ?></p>

            <p>
                <label for="2fa_code"><?php esc_html_e('Verification Code', 'peanut-suite'); ?></label>
                <input type="text" name="2fa_code" id="2fa_code" class="input" size="20" autocomplete="off" autofocus>
            </p>

            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e('Verify', 'peanut-suite'); ?>">
            </p>

            <p>
                <a href="<?php echo esc_url(add_query_arg(['action' => '2fa_resend', 'token' => $token], wp_login_url())); ?>">
                    <?php esc_html_e('Resend code', 'peanut-suite'); ?>
                </a>
            </p>
        </form>
        <?php
        login_footer();
        exit;
    }

    /**
     * Verify 2FA code
     */
    public function verify_2fa_code(): void {
        $token = $_POST['token'] ?? '';
        $code = $_POST['2fa_code'] ?? '';
        $user_id = get_transient('peanut_2fa_' . $token);

        if (!$user_id) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        $stored_hash = get_transient('peanut_2fa_code_' . $user_id);

        if ($stored_hash && wp_hash($code) === $stored_hash) {
            // Code verified - log user in
            delete_transient('peanut_2fa_' . $token);
            delete_transient('peanut_2fa_code_' . $user_id);

            $user = get_user_by('ID', $user_id);
            wp_set_auth_cookie($user_id, true);
            do_action('wp_login', $user->user_login, $user);

            wp_safe_redirect(admin_url());
            exit;
        }

        // Invalid code
        wp_safe_redirect(add_query_arg([
            'action' => '2fa',
            'token' => $token,
            'error' => 1,
        ], wp_login_url()));
        exit;
    }

    /**
     * Mask email address
     */
    private function mask_email(string $email): string {
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1];

        $masked_name = substr($name, 0, 2) . str_repeat('*', max(strlen($name) - 2, 3));
        return $masked_name . '@' . $domain;
    }

    /**
     * Check if IP is whitelisted
     */
    private function is_ip_whitelisted(string $ip): bool {
        return in_array($ip, $this->settings['ip_whitelist'], true);
    }

    /**
     * Check if IP is blacklisted
     */
    private function is_ip_blacklisted(string $ip): bool {
        return in_array($ip, $this->settings['ip_blacklist'], true);
    }

    /**
     * Get client IP
     */
    private function get_client_ip(): string {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        // Security is added as a submenu of Peanut Suite
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route(PEANUT_API_NAMESPACE, '/security/settings', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_settings'],
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'update_settings'],
                'permission_callback' => function() {
                    return current_user_can('manage_options');
                },
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/security/attempts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_login_attempts'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/security/lockouts', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_lockouts'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/security/unlock/(?P<ip>[^/]+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'unlock_ip'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Get settings via API
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response($this->settings, 200);
    }

    /**
     * Update settings via API
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response {
        $data = $request->get_json_params();

        $settings = wp_parse_args($data, $this->get_defaults());

        // Sanitize
        $settings['hide_login_enabled'] = (bool) ($settings['hide_login_enabled'] ?? false);
        $settings['login_slug'] = sanitize_title($settings['login_slug']);
        $settings['limit_login_enabled'] = (bool) ($settings['limit_login_enabled'] ?? true);
        $settings['max_attempts'] = absint($settings['max_attempts']);
        $settings['lockout_duration'] = absint($settings['lockout_duration']);
        $settings['notify_email'] = sanitize_email($settings['notify_email']);
        $settings['2fa_enabled'] = (bool) ($settings['2fa_enabled'] ?? false);

        update_option('peanut_security_settings', $settings);
        $this->settings = $settings;

        return new WP_REST_Response(['success' => true, 'settings' => $settings], 200);
    }

    /**
     * Get login attempts
     */
    public function get_login_attempts(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_login_attempts';

        $attempts = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY attempt_time DESC LIMIT 100"
        );

        return new WP_REST_Response($attempts, 200);
    }

    /**
     * Get lockouts
     */
    public function get_lockouts(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_lockouts';

        $lockouts = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 50"
        );

        return new WP_REST_Response($lockouts, 200);
    }

    /**
     * Unlock IP
     */
    public function unlock_ip(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $ip = $request->get_param('ip');

        $wpdb->delete($wpdb->prefix . 'peanut_lockouts', ['ip_address' => $ip]);
        $wpdb->delete($wpdb->prefix . 'peanut_login_attempts', ['ip_address' => $ip, 'status' => 'failed']);

        return new WP_REST_Response(['success' => true], 200);
    }

    /**
     * Cleanup old data
     */
    public function cleanup_old_data(): void {
        global $wpdb;

        // Delete attempts older than 30 days
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 30 DAY)", $wpdb->prefix . 'peanut_login_attempts'));

        // Delete expired lockouts
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE lockout_until < NOW()", $wpdb->prefix . 'peanut_lockouts'));
    }

    /**
     * Create database tables
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = [];

        // Login attempts table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}peanut_login_attempts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            username varchar(100) NOT NULL,
            attempt_time datetime NOT NULL,
            status enum('success','failed') NOT NULL DEFAULT 'failed',
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY attempt_time (attempt_time)
        ) $charset_collate;";

        // Lockouts table
        $sql[] = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}peanut_lockouts (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            ip_address varchar(45) NOT NULL,
            lockout_until datetime NOT NULL,
            attempts int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY ip_address (ip_address),
            KEY lockout_until (lockout_until)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ($sql as $query) {
            dbDelta($query);
        }
    }
}
