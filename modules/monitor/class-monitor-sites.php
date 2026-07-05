<?php
/**
 * Monitor Sites Manager
 *
 * Handles connected site registration, verification, and management.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-monitor-database.php';

class Monitor_Sites {

    /**
     * Get all active sites for current user
     */
    public function get_all_active(?int $user_id = null): array {
        global $wpdb;
        $table = Monitor_Database::sites_table();
        $user_id = $user_id ?? get_current_user_id();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d AND status = 'active' ORDER BY site_name ASC",
            $user_id
        ));
    }

    /**
     * Get all sites for current user (with optional filters)
     */
    public function get_all(array $args = []): array {
        global $wpdb;
        $table = Monitor_Database::sites_table();
        $user_id = $args['user_id'] ?? get_current_user_id();

        $where = ['user_id = %d'];
        $values = [$user_id];

        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if (!empty($args['search'])) {
            $where[] = '(site_url LIKE %s OR site_name LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = $search;
            $values[] = $search;
        }

        $where_clause = implode(' AND ', $where);
        $order_by = $args['order_by'] ?? 'site_name';
        $order = $args['order'] ?? 'ASC';

        // Pagination
        $per_page = (int) ($args['per_page'] ?? 20);
        $page = (int) ($args['page'] ?? 1);
        $offset = ($page - 1) * $per_page;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where_clause ORDER BY $order_by $order LIMIT %d OFFSET %d",
            array_merge($values, [$per_page, $offset])
        ));

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE $where_clause",
            $values
        ));

        return [
            'items' => $results,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'page' => $page,
        ];
    }

    /**
     * Get single site by ID
     */
    public function get(int $id): ?object {
        global $wpdb;
        $table = Monitor_Database::sites_table();
        $user_id = get_current_user_id();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));
    }

    /**
     * Get site by URL
     */
    public function get_by_url(string $url): ?object {
        global $wpdb;
        $table = Monitor_Database::sites_table();
        $user_id = get_current_user_id();
        $url = $this->normalize_url($url);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE site_url = %s AND user_id = %d",
            $url,
            $user_id
        ));
    }

    /**
     * Add a new site connection
     */
    public function add(array $data): int|WP_Error {
        global $wpdb;
        $table = Monitor_Database::sites_table();
        $user_id = get_current_user_id();

        // Validate required fields
        if (empty($data['site_url'])) {
            return new WP_Error('missing_url', __('Site URL is required.', 'peanut-suite'));
        }

        if (empty($data['site_key'])) {
            return new WP_Error('missing_key', __('Site key is required.', 'peanut-suite'));
        }

        $site_url = $this->normalize_url($data['site_url']);

        // Check if site already exists
        $existing = $this->get_by_url($site_url);
        if ($existing) {
            return new WP_Error('site_exists', __('This site is already connected.', 'peanut-suite'));
        }

        // Verify connection to child site
        $verify = $this->verify_connection($site_url, $data['site_key']);
        if (is_wp_error($verify)) {
            return $verify;
        }

        // Hash the site key for storage
        $site_key_hash = hash('sha256', $data['site_key']);

        $insert_data = [
            'user_id' => $user_id,
            'site_url' => $site_url,
            'site_name' => sanitize_text_field($data['site_name'] ?? $this->extract_site_name($site_url)),
            'site_key_hash' => $site_key_hash,
            'status' => 'active',
            'peanut_suite_active' => $verify['peanut_suite']['installed'] ?? false,
            'peanut_suite_version' => $verify['peanut_suite']['version'] ?? null,
            'permissions' => wp_json_encode($verify['permissions'] ?? []),
            'last_health' => wp_json_encode($verify['health'] ?? []),
            'last_check' => current_time('mysql'),
        ];

        $result = $wpdb->insert($table, $insert_data);

        if ($result === false) {
            return new WP_Error('db_error', __('Failed to save site connection.', 'peanut-suite'));
        }

        $site_id = $wpdb->insert_id;

        do_action('peanut_monitor_site_connected', $site_id, $insert_data);

        return $site_id;
    }

    /**
     * Update site
     */
    public function update(int $id, array $data): bool|WP_Error {
        global $wpdb;
        $table = Monitor_Database::sites_table();
        $user_id = get_current_user_id();

        // Verify ownership
        $site = $this->get($id);
        if (!$site) {
            return new WP_Error('not_found', __('Site not found.', 'peanut-suite'));
        }

        $update_data = [];

        if (isset($data['site_name'])) {
            $update_data['site_name'] = sanitize_text_field($data['site_name']);
        }

        if (isset($data['status'])) {
            $update_data['status'] = sanitize_text_field($data['status']);
        }

        if (empty($update_data)) {
            return true;
        }

        $result = $wpdb->update(
            $table,
            $update_data,
            ['id' => $id, 'user_id' => $user_id]
        );

        return $result !== false;
    }

    /**
     * Disconnect (delete) a site
     */
    public function disconnect(int $id): bool|WP_Error {
        global $wpdb;
        $table = Monitor_Database::sites_table();
        $user_id = get_current_user_id();

        $site = $this->get($id);
        if (!$site) {
            return new WP_Error('not_found', __('Site not found.', 'peanut-suite'));
        }

        // Notify child site of disconnection (optional, may fail)
        $this->notify_disconnect($site);

        // Delete associated records
        $wpdb->delete(Monitor_Database::health_log_table(), ['site_id' => $id]);
        $wpdb->delete(Monitor_Database::uptime_table(), ['site_id' => $id]);
        $wpdb->delete(Monitor_Database::analytics_table(), ['site_id' => $id]);

        // Delete site
        $result = $wpdb->delete($table, ['id' => $id, 'user_id' => $user_id]);

        if ($result) {
            do_action('peanut_monitor_site_disconnected', $id, $site);
        }

        return $result !== false;
    }

    /**
     * Verify connection to child site
     */
    public function verify_connection(string $site_url, string $site_key): array|WP_Error {
        if (!self::is_safe_remote_url($site_url)) {
            return new WP_Error(
                'blocked_url',
                __('Refusing to connect to an internal or reserved network address.', 'peanut-suite')
            );
        }

        $endpoint = trailingslashit($site_url) . 'wp-json/peanut-connect/v1/verify';

        $response = wp_remote_get($endpoint, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $site_key,
                'X-Peanut-Manager' => home_url(),
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error(
                'connection_failed',
                sprintf(__('Could not connect to site: %s', 'peanut-suite'), $response->get_error_message())
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 401) {
            return new WP_Error('invalid_key', __('Invalid site key.', 'peanut-suite'));
        }

        if ($code !== 200) {
            return new WP_Error(
                'verification_failed',
                $body['message'] ?? __('Site verification failed.', 'peanut-suite')
            );
        }

        return $body;
    }

    /**
     * Make authenticated request to child site
     */
    public function remote_request(object $site, string $endpoint, string $method = 'GET', array $body = []): array|WP_Error {
        // We need to retrieve the actual site key - but we only store the hash
        // The site key should be stored encrypted, not just hashed
        // For now, we'll need to refactor this to store the key encrypted

        if (!self::is_safe_remote_url($site->site_url)) {
            return new WP_Error(
                'blocked_url',
                __('Refusing to request an internal or reserved network address.', 'peanut-suite')
            );
        }

        $url = trailingslashit($site->site_url) . 'wp-json/peanut-connect/v1/' . ltrim($endpoint, '/');

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'X-Peanut-Manager' => home_url(),
                'Content-Type' => 'application/json',
            ],
        ];

        // Site key needs to be retrieved from secure storage
        $site_key = $this->get_site_key($site->id);
        if ($site_key) {
            $args['headers']['Authorization'] = 'Bearer ' . $site_key;
        }

        if (!empty($body) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->mark_site_error($site->id, $response->get_error_message());
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return new WP_Error(
                'remote_error',
                $body['message'] ?? sprintf(__('Remote request failed with code %d', 'peanut-suite'), $code)
            );
        }

        return $body ?? [];
    }

    /**
     * Get decrypted site key
     */
    private function get_site_key(int $site_id): ?string {
        $encrypted = get_option("peanut_monitor_site_key_{$site_id}");
        if (!$encrypted) {
            return null;
        }

        // Use Peanut encryption service
        if (class_exists('Peanut_Encryption')) {
            return Peanut_Encryption::decrypt($encrypted);
        }

        return null;
    }

    /**
     * Store encrypted site key
     */
    public function store_site_key(int $site_id, string $site_key): bool {
        if (class_exists('Peanut_Encryption')) {
            $encrypted = Peanut_Encryption::encrypt($site_key);
            return update_option("peanut_monitor_site_key_{$site_id}", $encrypted);
        }

        return false;
    }

    /**
     * Delete stored site key
     */
    public function delete_site_key(int $site_id): bool {
        return delete_option("peanut_monitor_site_key_{$site_id}");
    }

    /**
     * Mark site as having an error
     */
    private function mark_site_error(int $id, string $error): void {
        global $wpdb;
        $table = Monitor_Database::sites_table();

        $wpdb->update(
            $table,
            [
                'status' => 'error',
                'last_health' => wp_json_encode(['error' => $error]),
            ],
            ['id' => $id]
        );
    }

    /**
     * Notify child site of disconnection
     */
    private function notify_disconnect(object $site): void {
        $this->remote_request($site, 'disconnect', 'POST');
    }

    /**
     * SSRF guard — is this URL safe to fetch server-side?
     *
     * Monitor fetches admin-supplied URLs (site connect/verify, health, uptime).
     * Even though those surfaces are admin-gated, we refuse to let the manager be
     * pointed at internal infrastructure — loopback, RFC1918 private ranges,
     * link-local (incl. the 169.254.169.254 cloud-metadata endpoint) and IPv6
     * unique-local/loopback. Returns true only when the host resolves EXCLUSIVELY
     * to public, routable unicast addresses. Fails closed on unresolvable hosts.
     *
     * Pure PHP (parse_url + filter_var) so it is unit-testable without WordPress.
     */
    public static function is_safe_remote_url(string $url): bool {
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return false;
        }

        // Strip brackets from IPv6 literals: [::1] -> ::1
        $host = trim($host, '[]');

        // Build the set of candidate IPs. IP literals resolve to themselves;
        // hostnames are resolved to every A/AAAA record so a DNS answer that
        // includes an internal address can't sneak past.
        $ips = [];
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $a = gethostbynamel($host);
            if (is_array($a)) {
                $ips = array_merge($ips, $a);
            }
            if (function_exists('dns_get_record')) {
                $aaaa = @dns_get_record($host, DNS_AAAA);
                if (is_array($aaaa)) {
                    foreach ($aaaa as $rec) {
                        if (!empty($rec['ipv6'])) {
                            $ips[] = $rec['ipv6'];
                        }
                    }
                }
            }
            // Could not resolve at all -> fail closed.
            if (empty($ips)) {
                return false;
            }
        }

        foreach ($ips as $ip) {
            if (self::is_blocked_ip($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * True if an IP is loopback / private / link-local / reserved (i.e. NOT a
     * public routable unicast address we should ever fetch from the server).
     *
     * FILTER_FLAG_NO_PRIV_RANGE rejects 10/8, 172.16/12, 192.168/16 and fc00::/7.
     * FILTER_FLAG_NO_RES_RANGE rejects 0/8, 127/8 (loopback), 169.254/16
     * (link-local + 169.254.169.254 metadata) and other reserved ranges. The
     * explicit IPv6 checks are belt-and-braces for builds that don't flag ::1 /
     * fe80:: / fc00:: as reserved.
     */
    private static function is_blocked_ip(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        $lower = strtolower($ip);
        if ($lower === '::1'
            || str_starts_with($lower, 'fe80:')
            || str_starts_with($lower, 'fc')
            || str_starts_with($lower, 'fd')) {
            return true;
        }

        return false;
    }

    /**
     * Normalize URL for storage
     */
    private function normalize_url(string $url): string {
        $url = strtolower(trim($url));
        $url = preg_replace('#^https?://#', '', $url);
        $url = rtrim($url, '/');
        return 'https://' . $url;
    }

    /**
     * Extract site name from URL
     */
    private function extract_site_name(string $url): string {
        $parsed = wp_parse_url($url);
        $host = $parsed['host'] ?? $url;
        $host = preg_replace('#^www\.#', '', $host);
        return ucfirst(explode('.', $host)[0]);
    }

    /**
     * Get site count for user
     */
    public function get_count(?int $user_id = null): int {
        global $wpdb;
        $table = Monitor_Database::sites_table();
        $user_id = $user_id ?? get_current_user_id();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND status = 'active'",
            $user_id
        ));
    }

    /**
     * Check if user can add more sites (license limit)
     */
    public function can_add_site(?int $user_id = null): bool {
        $current_count = $this->get_count($user_id);

        if (class_exists('Peanut_License')) {
            $license = new Peanut_License();
            $limit = $license->get_limit('monitor_sites');

            // -1 means unlimited
            if ($limit === -1) {
                return true;
            }

            return $current_count < $limit;
        }

        // Default limit if license system not available
        return $current_count < 25;
    }

    /**
     * Update last health data for site
     */
    public function update_health(int $id, array $health_data): void {
        global $wpdb;
        $table = Monitor_Database::sites_table();

        $wpdb->update(
            $table,
            [
                'last_health' => wp_json_encode($health_data),
                'last_check' => current_time('mysql'),
                'status' => $health_data['status'] === 'offline' ? 'error' : 'active',
            ],
            ['id' => $id]
        );
    }
}
