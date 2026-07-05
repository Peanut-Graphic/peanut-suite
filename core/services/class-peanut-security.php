<?php
/**
 * Security Service
 *
 * Input sanitization, rate limiting, and security utilities.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Security {

    private const DEFAULT_RATE_LIMIT = 60;
    private const DEFAULT_RATE_WINDOW = 60;

    /**
     * Sanitize field by type
     */
    public static function sanitize_field(string $key, mixed $value): string {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $value = (string) $value;
        $key_lower = strtolower($key);

        // Email
        if (str_contains($key_lower, 'email')) {
            $filtered = filter_var($value, FILTER_VALIDATE_EMAIL);
            return $filtered ? sanitize_email($value) : '';
        }

        // Phone
        if (str_contains($key_lower, 'phone') || str_contains($key_lower, 'mobile') || str_contains($key_lower, 'tel')) {
            return preg_replace('/[^\d\-\(\)\+\s]/', '', $value);
        }

        // ZIP/Postal code
        if (str_contains($key_lower, 'zip') || str_contains($key_lower, 'postal')) {
            return preg_replace('/[^\dA-Za-z\-\s]/', '', $value);
        }

        // State/Province
        if ($key_lower === 'state' || $key_lower === 'province') {
            $clean = preg_replace('/[^A-Za-z]/', '', $value);
            return strtoupper(substr($clean, 0, 2));
        }

        // URL
        if (str_contains($key_lower, 'url') || str_contains($key_lower, 'website') || str_contains($key_lower, 'link')) {
            return esc_url_raw($value);
        }

        // Account/ID numbers
        if (str_contains($key_lower, 'account') || str_contains($key_lower, '_id') || str_contains($key_lower, '_number')) {
            return preg_replace('/[^A-Za-z0-9\-]/', '', $value);
        }

        // UTM parameters
        if (str_starts_with($key_lower, 'utm_')) {
            return preg_replace('/[^A-Za-z0-9\-_\+\%]/', '', $value);
        }

        return sanitize_text_field($value);
    }

    /**
     * Sanitize array of fields
     */
    public static function sanitize_fields(array $data, array $skip_keys = []): array {
        $sanitized = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $skip_keys, true)) {
                $sanitized[$key] = $value;
                continue;
            }
            if (is_array($value)) {
                $sanitized[$key] = self::sanitize_fields($value, $skip_keys);
            } else {
                $sanitized[$key] = self::sanitize_field($key, $value);
            }
        }
        return $sanitized;
    }

    /**
     * Check rate limit
     */
    public static function check_rate_limit(
        string $action,
        int $max_requests = self::DEFAULT_RATE_LIMIT,
        int $window_seconds = self::DEFAULT_RATE_WINDOW,
        ?string $identifier = null
    ): bool {
        $identifier = $identifier ?? self::get_client_ip();
        $key = 'peanut_rate_' . md5($action . '_' . $identifier);

        $data = get_transient($key);

        if ($data === false) {
            set_transient($key, ['count' => 1, 'started' => time()], $window_seconds);
            return true;
        }

        if (time() - $data['started'] > $window_seconds) {
            set_transient($key, ['count' => 1, 'started' => time()], $window_seconds);
            return true;
        }

        $data['count']++;
        set_transient($key, $data, $window_seconds);

        if ($data['count'] > $max_requests) {
            self::log_security_event('rate_limit_exceeded', [
                'action' => $action,
                'ip' => $identifier,
                'count' => $data['count'],
            ]);
            return false;
        }

        return true;
    }

    /**
     * Get remaining rate limit
     */
    public static function get_rate_limit_remaining(
        string $action,
        int $max_requests = self::DEFAULT_RATE_LIMIT,
        ?string $identifier = null
    ): int {
        $identifier = $identifier ?? self::get_client_ip();
        $key = 'peanut_rate_' . md5($action . '_' . $identifier);

        $data = get_transient($key);

        if ($data === false) {
            return $max_requests;
        }

        return max(0, $max_requests - $data['count']);
    }

    /**
     * Get client IP.
     *
     * Security: the connecting peer (REMOTE_ADDR) is the only value an attacker
     * cannot forge. Client-supplied forwarding headers (X-Forwarded-For,
     * CF-Connecting-IP, X-Real-IP, ...) are trusted ONLY when the direct peer is
     * a configured trusted proxy — otherwise anyone could spoof their IP to evade
     * rate limiting or poison security logs. Configure real proxies/load-balancers
     * via the PEANUT_TRUSTED_PROXIES constant or the `peanut_trusted_proxies`
     * option (comma-separated list, or array). Empty by default = REMOTE_ADDR only.
     */
    public static function get_client_ip(): string {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';

        if ($remote !== '' && self::is_trusted_proxy($remote)) {
            // Behind a trusted proxy: honor the forwarding headers it set.
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $key) {
                if (empty($_SERVER[$key])) {
                    continue;
                }
                $ip = (string) $_SERVER[$key];
                // XFF is "client, proxy1, proxy2" — the left-most is the client.
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        if (filter_var($remote, FILTER_VALIDATE_IP)) {
            return $remote;
        }

        return '0.0.0.0';
    }

    /**
     * Whether a direct-peer IP is a configured trusted proxy.
     *
     * Reads the PEANUT_TRUSTED_PROXIES constant first, then the
     * `peanut_trusted_proxies` option (comma-separated string or array).
     * Exact IP match only — CIDR ranges are intentionally out of scope here.
     */
    private static function is_trusted_proxy(string $ip): bool {
        $proxies = [];

        if (defined('PEANUT_TRUSTED_PROXIES') && PEANUT_TRUSTED_PROXIES) {
            $proxies = explode(',', (string) PEANUT_TRUSTED_PROXIES);
        } elseif (function_exists('get_option')) {
            $opt = get_option('peanut_trusted_proxies', '');
            if (is_array($opt)) {
                $proxies = $opt;
            } elseif (is_string($opt) && $opt !== '') {
                $proxies = explode(',', $opt);
            }
        }

        $proxies = array_filter(array_map('trim', $proxies));

        return $proxies !== [] && in_array($ip, $proxies, true);
    }

    /**
     * Validate nonce
     */
    public static function verify_nonce(string $nonce, string $action): bool {
        return wp_verify_nonce($nonce, $action) !== false;
    }

    /**
     * Check if admin request
     */
    public static function is_admin_request(): bool {
        return is_admin() && current_user_can('manage_options');
    }

    /**
     * Log a security event
     */
    public static function log_security_event(string $event, array $data = []): void {
        $log_data = array_merge([
            'event' => $event,
            'ip' => self::get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'timestamp' => current_time('mysql'),
        ], $data);

        do_action('peanut_security_event', $event, $log_data);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Peanut Security Event: ' . wp_json_encode($log_data));
        }
    }

    /**
     * Validate URL is safe
     */
    public static function is_safe_url(string $url, array $allowed_hosts = []): bool {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return false;
        }

        if (!in_array($parsed['scheme'] ?? '', ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'];
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {
            return false;
        }

        $ip = gethostbyname($host);
        if ($ip !== $host && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (!empty($allowed_hosts) && !in_array($host, $allowed_hosts, true)) {
            return false;
        }

        return true;
    }

    /**
     * Create CSRF token
     */
    public static function create_csrf_token(string $action): string {
        return wp_create_nonce('peanut_csrf_' . $action);
    }

    /**
     * Verify CSRF token
     */
    public static function verify_csrf_token(string $token, string $action): bool {
        return wp_verify_nonce($token, 'peanut_csrf_' . $action) !== false;
    }
}
