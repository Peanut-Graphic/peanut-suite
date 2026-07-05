<?php
/**
 * Links Module
 *
 * URL shortening, QR code generation, and click tracking.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Links_Module {

    /**
     * Initialize module
     */
    public function init(): void {
        // Register API routes
        add_action('peanut_register_routes', [$this, 'register_routes']);

        // Register rewrite rules for redirects
        add_action('init', [$this, 'register_rewrite_rules']);

        // Handle redirects
        add_action('template_redirect', [$this, 'handle_redirect']);

        // Dashboard stats
        add_filter('peanut_dashboard_stats', [$this, 'add_dashboard_stats'], 10, 2);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        require_once __DIR__ . '/api/class-links-controller.php';
        $controller = new Links_Controller();
        $controller->register_routes();
    }

    /**
     * Register rewrite rules
     */
    public function register_rewrite_rules(): void {
        $prefix = $this->get_link_prefix();
        add_rewrite_rule(
            "^{$prefix}/([a-zA-Z0-9]+)/?$",
            'index.php?peanut_link=$matches[1]',
            'top'
        );
        add_rewrite_tag('%peanut_link%', '([a-zA-Z0-9]+)');
    }

    /**
     * Handle link redirects
     */
    public function handle_redirect(): void {
        $slug = get_query_var('peanut_link');

        if (empty($slug)) {
            return;
        }

        global $wpdb;
        $table = Peanut_Database::links_table();

        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE slug = %s AND is_active = 1",
            $slug
        ));

        if (!$link) {
            status_header(404);
            include get_404_template();
            exit;
        }

        // Check expiration
        if ($link->expires_at && strtotime($link->expires_at) < time()) {
            status_header(410);
            wp_die(__('This link has expired.', 'peanut-suite'), __('Link Expired', 'peanut-suite'), 410);
        }

        // Check password
        if ($link->password_hash) {
            // Handle password protection (simplified - would need a form)
            if (!$this->verify_password($link->id)) {
                wp_die(__('This link is password protected.', 'peanut-suite'));
            }
        }

        // Validate the redirect target BEFORE tracking or redirecting.
        //
        // destination_url is attacker-controllable: any logged-in user (incl. a
        // Subscriber, via the create endpoint) can set it. Short-links may point
        // to EXTERNAL domains, so wp_safe_redirect (which forces a same-host
        // fallback) would break the feature. Instead we scheme-allowlist to a
        // well-formed http(s) URL with a host, which still permits legitimate
        // external links while blocking javascript:/data:/vbscript: and malformed
        // schemes used for open-redirect + credential-phishing.
        if (!self::is_safe_redirect_target($link->destination_url)) {
            status_header(400);
            wp_die(
                __('This link points to an invalid destination.', 'peanut-suite'),
                __('Invalid Link', 'peanut-suite'),
                400
            );
        }

        // Track click
        $this->track_click($link->id);

        // Update click count
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET click_count = click_count + 1 WHERE id = %d",
            $link->id
        ));

        // Redirect. Target already validated as an http(s) URL with a host; pass
        // it through esc_url_raw with an explicit protocol allowlist as a final
        // egress guard (mirrors what wp_safe_redirect does internally).
        $target = function_exists('esc_url_raw')
            ? esc_url_raw($link->destination_url, ['http', 'https'])
            : $link->destination_url;
        wp_redirect($target, 301);
        exit;
    }

    /**
     * Validate that a short-link destination is safe to redirect to.
     *
     * Short-links legitimately point to external domains, so we cannot force a
     * same-host redirect. Instead we require a well-formed absolute http(s) URL
     * with a host and reject everything else — in particular javascript:, data:,
     * vbscript:, protocol-relative (`//evil.example`) and otherwise malformed
     * schemes that enable open-redirect / credential-phishing.
     *
     * Pure enough to unit-test without a WordPress runtime: the parse_url +
     * scheme-allowlist check does the work; the WordPress validators below are
     * applied as defense-in-depth only when available.
     *
     * @param mixed $url Candidate destination URL.
     */
    public static function is_safe_redirect_target($url): bool {
        if (!is_string($url)) {
            return false;
        }

        $url = trim($url);
        if ($url === '') {
            return false;
        }

        // Reject embedded control characters / whitespace (obfuscation vectors
        // such as "java\tscript:alert(1)").
        if (preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        // Defense-in-depth inside WordPress: esc_url_raw with an explicit
        // protocol allowlist blanks out anything that is not http(s).
        if (function_exists('esc_url_raw')) {
            $clean = esc_url_raw($url, ['http', 'https']);
            if (!is_string($clean) || $clean === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Track click details
     */
    private function track_click(int $link_id): void {
        global $wpdb;
        $table = Peanut_Database::link_clicks_table();

        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $device_info = $this->parse_user_agent($user_agent);

        $wpdb->insert($table, [
            'link_id' => $link_id,
            'visitor_id' => $_COOKIE['peanut_visitor'] ?? null,
            'ip_address' => Peanut_Security::get_client_ip(),
            'user_agent' => $user_agent,
            'referer' => $_SERVER['HTTP_REFERER'] ?? null,
            'device_type' => $device_info['device'],
            'browser' => $device_info['browser'],
            'os' => $device_info['os'],
        ]);
    }

    /**
     * Parse user agent
     */
    private function parse_user_agent(string $ua): array {
        $device = 'desktop';
        $browser = 'unknown';
        $os = 'unknown';

        // Device
        if (preg_match('/mobile|android|iphone|ipad/i', $ua)) {
            $device = preg_match('/ipad|tablet/i', $ua) ? 'tablet' : 'mobile';
        }

        // Browser
        if (preg_match('/chrome/i', $ua)) $browser = 'Chrome';
        elseif (preg_match('/safari/i', $ua)) $browser = 'Safari';
        elseif (preg_match('/firefox/i', $ua)) $browser = 'Firefox';
        elseif (preg_match('/edge/i', $ua)) $browser = 'Edge';
        elseif (preg_match('/msie|trident/i', $ua)) $browser = 'IE';

        // OS
        if (preg_match('/windows/i', $ua)) $os = 'Windows';
        elseif (preg_match('/macintosh|mac os/i', $ua)) $os = 'macOS';
        elseif (preg_match('/linux/i', $ua)) $os = 'Linux';
        elseif (preg_match('/android/i', $ua)) $os = 'Android';
        elseif (preg_match('/iphone|ipad/i', $ua)) $os = 'iOS';

        return compact('device', 'browser', 'os');
    }

    /**
     * Verify password (simplified)
     */
    private function verify_password(int $link_id): bool {
        // Would need session/cookie handling for real implementation
        return false;
    }

    /**
     * Get link prefix setting
     */
    public function get_link_prefix(): string {
        $settings = get_option('peanut_settings', []);
        return $settings['link_prefix'] ?? 'go';
    }

    /**
     * Generate short slug
     */
    public static function generate_slug(int $length = 6): string {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $slug = '';

        for ($i = 0; $i < $length; $i++) {
            $slug .= $chars[random_int(0, strlen($chars) - 1)];
        }

        // Check uniqueness
        global $wpdb;
        $table = Peanut_Database::links_table();
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE slug = %s",
            $slug
        ));

        if ($exists) {
            return self::generate_slug($length);
        }

        return $slug;
    }

    /**
     * Generate QR code URL (using external service)
     */
    public static function get_qr_code_url(string $url, int $size = 200): string {
        // Use QR Server API (free, no key needed)
        return 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query([
            'size' => "{$size}x{$size}",
            'data' => $url,
        ]);
    }

    /**
     * Add stats to dashboard
     */
    public function add_dashboard_stats(array $stats, string $period): array {
        global $wpdb;
        $links_table = Peanut_Database::links_table();
        $clicks_table = Peanut_Database::link_clicks_table();
        $user_id = get_current_user_id();

        // Total links
        $stats['links_total'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $links_table WHERE user_id = %d",
            $user_id
        ));

        // Total clicks
        $stats['links_clicks'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(click_count) FROM $links_table WHERE user_id = %d",
            $user_id
        ));

        // Clicks in period
        $date_clause = $this->get_date_clause($period);
        $stats['links_clicks_period'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $clicks_table c
             JOIN $links_table l ON c.link_id = l.id
             WHERE l.user_id = %d $date_clause",
            $user_id
        ));

        return $stats;
    }

    private function get_date_clause(string $period): string {
        return match ($period) {
            '7d' => "AND c.clicked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            '30d' => "AND c.clicked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            '90d' => "AND c.clicked_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)",
            default => '',
        };
    }
}
