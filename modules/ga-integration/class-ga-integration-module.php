<?php
/**
 * Google Analytics & Search Console Integration Module
 *
 * Connects with GA4 and GSC APIs to pull analytics data.
 */

namespace PeanutSuite\GAIntegration;

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/core/services/class-peanut-encryption.php';
require_once dirname(__DIR__, 2) . '/core/services/class-peanut-ga-credentials.php';

class GA_Integration_Module {

    /**
     * OAuth `state` storage: a per-user transient holding the SHA-256 of the
     * random state sent to Google. Short-lived and single-use.
     */
    public const OAUTH_STATE_TRANSIENT_PREFIX = 'peanut_ga_oauth_state_';
    public const OAUTH_STATE_TTL = 10 * 60;

    /** Admin page slug the OAuth redirect URI points at. */
    public const ADMIN_PAGE = 'peanut-ga-integration';

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->init();
    }

    public function init(): void {
        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_routes']);

        // Add cron for data sync
        add_action('peanut_sync_ga_data', [$this, 'sync_analytics_data']);

        // Schedule sync
        if (!wp_next_scheduled('peanut_sync_ga_data')) {
            wp_schedule_event(time(), 'daily', 'peanut_sync_ga_data');
        }

        // Admin AJAX handlers
        add_action('wp_ajax_peanut_save_ga_credentials', [$this, 'ajax_save_credentials']);
        add_action('wp_ajax_peanut_test_ga_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_peanut_disconnect_ga', [$this, 'ajax_disconnect']);
        add_action('wp_ajax_peanut_get_ga_properties', [$this, 'ajax_get_properties']);

        // OAuth redirect target: handled before any output, then redirected so
        // the authorization code never stays in the URL or browser history.
        add_action('admin_init', [$this, 'maybe_handle_oauth_callback']);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route('peanut/v1', '/ga/overview', [
            'methods' => 'GET',
            'callback' => [$this, 'get_overview'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('peanut/v1', '/ga/traffic', [
            'methods' => 'GET',
            'callback' => [$this, 'get_traffic_data'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('peanut/v1', '/ga/search-queries', [
            'methods' => 'GET',
            'callback' => [$this, 'get_search_queries'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);

        register_rest_route('peanut/v1', '/ga/pages', [
            'methods' => 'GET',
            'callback' => [$this, 'get_top_pages'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Get credentials with secrets decrypted, for talking to Google only.
     * A secret that cannot be decrypted reads as '' (not configured).
     */
    private function get_credentials(): array {
        return \Peanut_GA_Credentials::load();
    }

    /**
     * Check if connected
     */
    public function is_connected(): bool {
        $credentials = $this->get_credentials();
        return !empty($credentials['access_token']) && !empty($credentials['property_id']);
    }

    /**
     * Get access token (refresh if needed)
     */
    private function get_access_token(): ?string {
        $credentials = $this->get_credentials();

        if (empty($credentials['access_token'])) {
            return null;
        }

        // Check if token is expired
        if (time() > $credentials['token_expires'] - 300) {
            $new_token = $this->refresh_access_token($credentials['refresh_token']);
            if ($new_token) {
                return $new_token;
            }
            return null;
        }

        return $credentials['access_token'];
    }

    /**
     * Refresh access token
     */
    private function refresh_access_token(string $refresh_token): ?string {
        $credentials = $this->get_credentials();

        // Never call Google with a missing (or undecryptable) secret or token.
        if ($refresh_token === '' || $credentials['client_id'] === '' || $credentials['client_secret'] === '') {
            return null;
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['access_token'])) {
            $credentials['access_token'] = $body['access_token'];
            $credentials['token_expires'] = time() + ($body['expires_in'] ?? 3600);
            \Peanut_GA_Credentials::save($credentials);
            return $body['access_token'];
        }

        return null;
    }

    /**
     * Make GA4 API request
     */
    private function ga4_request(string $endpoint, array $body = []): ?array {
        $token = $this->get_access_token();
        $credentials = $this->get_credentials();

        if (!$token || empty($credentials['property_id'])) {
            return null;
        }

        $property = $credentials['property_id'];
        $url = "https://analyticsdata.googleapis.com/v1beta/properties/{$property}:{$endpoint}";

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Make Search Console API request
     */
    private function gsc_request(string $endpoint, array $body = []): ?array {
        $token = $this->get_access_token();
        $credentials = $this->get_credentials();

        if (!$token || empty($credentials['gsc_property'])) {
            return null;
        }

        $site_url = urlencode($credentials['gsc_property']);
        $url = "https://searchconsole.googleapis.com/webmasters/v3/sites/{$site_url}/{$endpoint}";

        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if (!empty($body)) {
            $args['body'] = json_encode($body);
            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * REST: Get overview
     */
    public function get_overview(\WP_REST_Request $request): \WP_REST_Response {
        $days = $request->get_param('days') ?: 30;
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d', strtotime('-1 day'));

        // Check cache
        $cache_key = 'peanut_ga_overview_' . $days;
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return new \WP_REST_Response($cached, 200);
        }

        // GA4 request
        $ga_data = $this->ga4_request('runReport', [
            'dateRanges' => [
                ['startDate' => $start_date, 'endDate' => $end_date],
            ],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'bounceRate'],
                ['name' => 'conversions'],
            ],
        ]);

        $overview = [
            'users' => 0,
            'sessions' => 0,
            'pageviews' => 0,
            'avg_duration' => 0,
            'bounce_rate' => 0,
            'conversions' => 0,
        ];

        if (!empty($ga_data['rows'][0]['metricValues'])) {
            $metrics = $ga_data['rows'][0]['metricValues'];
            $overview['users'] = (int) ($metrics[0]['value'] ?? 0);
            $overview['sessions'] = (int) ($metrics[1]['value'] ?? 0);
            $overview['pageviews'] = (int) ($metrics[2]['value'] ?? 0);
            $overview['avg_duration'] = round((float) ($metrics[3]['value'] ?? 0));
            $overview['bounce_rate'] = round((float) ($metrics[4]['value'] ?? 0) * 100, 1);
            $overview['conversions'] = (int) ($metrics[5]['value'] ?? 0);
        }

        // Cache for 1 hour
        set_transient($cache_key, $overview, HOUR_IN_SECONDS);

        return new \WP_REST_Response($overview, 200);
    }

    /**
     * REST: Get traffic data
     */
    public function get_traffic_data(\WP_REST_Request $request): \WP_REST_Response {
        $days = $request->get_param('days') ?: 30;
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d', strtotime('-1 day'));

        $ga_data = $this->ga4_request('runReport', [
            'dateRanges' => [
                ['startDate' => $start_date, 'endDate' => $end_date],
            ],
            'dimensions' => [
                ['name' => 'date'],
            ],
            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
            ],
            'orderBys' => [
                ['dimension' => ['dimensionName' => 'date']],
            ],
        ]);

        $traffic = [
            'labels' => [],
            'users' => [],
            'sessions' => [],
            'pageviews' => [],
        ];

        if (!empty($ga_data['rows'])) {
            foreach ($ga_data['rows'] as $row) {
                $date = $row['dimensionValues'][0]['value'];
                $formatted_date = date('M j', strtotime($date));

                $traffic['labels'][] = $formatted_date;
                $traffic['users'][] = (int) ($row['metricValues'][0]['value'] ?? 0);
                $traffic['sessions'][] = (int) ($row['metricValues'][1]['value'] ?? 0);
                $traffic['pageviews'][] = (int) ($row['metricValues'][2]['value'] ?? 0);
            }
        }

        return new \WP_REST_Response($traffic, 200);
    }

    /**
     * REST: Get search queries from GSC
     */
    public function get_search_queries(\WP_REST_Request $request): \WP_REST_Response {
        $days = $request->get_param('days') ?: 30;
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d', strtotime('-1 day'));

        $gsc_data = $this->gsc_request('searchAnalytics/query', [
            'startDate' => $start_date,
            'endDate' => $end_date,
            'dimensions' => ['query'],
            'rowLimit' => 50,
        ]);

        $queries = [];

        if (!empty($gsc_data['rows'])) {
            foreach ($gsc_data['rows'] as $row) {
                $queries[] = [
                    'query' => $row['keys'][0],
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                    'position' => round($row['position'] ?? 0, 1),
                ];
            }
        }

        return new \WP_REST_Response($queries, 200);
    }

    /**
     * REST: Get top pages
     */
    public function get_top_pages(\WP_REST_Request $request): \WP_REST_Response {
        $days = $request->get_param('days') ?: 30;
        $start_date = date('Y-m-d', strtotime("-{$days} days"));
        $end_date = date('Y-m-d', strtotime('-1 day'));

        $ga_data = $this->ga4_request('runReport', [
            'dateRanges' => [
                ['startDate' => $start_date, 'endDate' => $end_date],
            ],
            'dimensions' => [
                ['name' => 'pagePath'],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'activeUsers'],
                ['name' => 'averageSessionDuration'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
            ],
            'limit' => 20,
        ]);

        $pages = [];

        if (!empty($ga_data['rows'])) {
            foreach ($ga_data['rows'] as $row) {
                $pages[] = [
                    'path' => $row['dimensionValues'][0]['value'],
                    'pageviews' => (int) ($row['metricValues'][0]['value'] ?? 0),
                    'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
                    'avg_time' => round((float) ($row['metricValues'][2]['value'] ?? 0)),
                ];
            }
        }

        return new \WP_REST_Response($pages, 200);
    }

    /**
     * AJAX: Save credentials
     */
    public function ajax_save_credentials(): void {
        check_ajax_referer('peanut_ga', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $submitted = [
            'client_id' => sanitize_text_field(wp_unslash($_POST['client_id'] ?? '')),
            // The page never re-displays the stored secret, so a blank field
            // means "keep the saved secret" (merge_submission()).
            'client_secret' => sanitize_text_field(wp_unslash($_POST['client_secret'] ?? '')),
            'property_id' => sanitize_text_field(wp_unslash($_POST['property_id'] ?? '')),
            'gsc_property' => sanitize_text_field(wp_unslash($_POST['gsc_property'] ?? '')),
        ];

        // Tokens are preserved; secrets are stored encrypted.
        \Peanut_GA_Credentials::save(
            \Peanut_GA_Credentials::merge_submission($submitted, $this->get_credentials())
        );

        wp_send_json_success(['message' => 'Credentials saved']);
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection(): void {
        check_ajax_referer('peanut_ga', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $credentials = $this->get_credentials();

        if (empty($credentials['access_token'])) {
            wp_send_json_error('Not authenticated with Google');
        }

        // Try to get account info
        $token = $this->get_access_token();
        if (!$token) {
            wp_send_json_error('Token expired or invalid');
        }

        wp_send_json_success(['message' => 'Connection successful']);
    }

    /**
     * AJAX: Disconnect
     */
    public function ajax_disconnect(): void {
        check_ajax_referer('peanut_ga', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        delete_option(\Peanut_GA_Credentials::OPTION);
        delete_transient('peanut_ga_overview_7');
        delete_transient('peanut_ga_overview_30');
        delete_transient('peanut_ga_overview_90');

        wp_send_json_success(['message' => 'Disconnected']);
    }

    /**
     * AJAX: Get properties list
     */
    public function ajax_get_properties(): void {
        check_ajax_referer('peanut_ga', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $token = $this->get_access_token();
        if (!$token) {
            wp_send_json_error('Not authenticated');
        }

        // Get GA4 properties
        $response = wp_remote_get('https://analyticsadmin.googleapis.com/v1alpha/accounts/-/properties', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to fetch properties');
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $properties = [];

        if (!empty($data['properties'])) {
            foreach ($data['properties'] as $prop) {
                $properties[] = [
                    'id' => str_replace('properties/', '', $prop['name']),
                    'name' => $prop['displayName'],
                ];
            }
        }

        wp_send_json_success(['properties' => $properties]);
    }

    /**
     * Sync analytics data (cron job)
     */
    public function sync_analytics_data(): void {
        if (!$this->is_connected()) {
            return;
        }

        // Pre-cache common reports
        $this->get_overview(new \WP_REST_Request('GET', '/peanut/v1/ga/overview'));

        do_action('peanut_ga_data_synced');
    }

    /**
     * Redirect URI registered with Google for this module.
     */
    private function get_redirect_uri(): string {
        return admin_url('admin.php?page=' . self::ADMIN_PAGE . '&action=oauth_callback');
    }

    /**
     * Get OAuth URL
     *
     * Mints a fresh single-use `state` bound to the current user; returns ''
     * when no consent flow can be started.
     */
    public function get_oauth_url(): string {
        $credentials = $this->get_credentials();

        // Without a usable client secret the code exchange cannot succeed, so
        // do not start a consent flow (covers an undecryptable secret).
        if (empty($credentials['client_id']) || $credentials['client_secret'] === '') {
            return '';
        }

        $state = $this->create_oauth_state();
        if ($state === '') {
            return '';
        }

        $scopes = implode(' ', [
            'https://www.googleapis.com/auth/analytics.readonly',
            'https://www.googleapis.com/auth/webmasters.readonly',
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => $credentials['client_id'],
            'redirect_uri' => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope' => $scopes,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * Generate a random OAuth state for the current user and remember its hash.
     * A newer consent link replaces any earlier, unused one.
     */
    private function create_oauth_state(): string {
        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) {
            return '';
        }

        $state = bin2hex(random_bytes(32));
        set_transient(self::OAUTH_STATE_TRANSIENT_PREFIX . $user_id, hash('sha256', $state), self::OAUTH_STATE_TTL);

        return $state;
    }

    /**
     * Check a returned state against the current user's stored one. The stored
     * state is deleted on every check, so each state can be used at most once.
     */
    private function consume_oauth_state(string $state): bool {
        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) {
            return false;
        }

        $key = self::OAUTH_STATE_TRANSIENT_PREFIX . $user_id;
        $stored = get_transient($key);
        delete_transient($key);

        if (!is_string($stored) || $stored === '' || $state === '') {
            return false;
        }

        return hash_equals($stored, hash('sha256', $state));
    }

    /**
     * admin_init: complete the OAuth redirect, then send the admin back to the
     * page without the code/state in the URL.
     */
    public function maybe_handle_oauth_callback(): void {
        if (($_GET['page'] ?? '') !== self::ADMIN_PAGE || ($_GET['action'] ?? '') !== 'oauth_callback') {
            return;
        }

        $status = $this->process_oauth_callback(wp_unslash($_GET));

        wp_safe_redirect(admin_url('admin.php?page=' . self::ADMIN_PAGE . '&ga_oauth=' . rawurlencode($status)));
        exit;
    }

    /**
     * Validate and complete an OAuth callback request.
     *
     * @param array $query The callback's query parameters.
     * @return string One of: connected, failed, invalid_state, forbidden.
     */
    public function process_oauth_callback(array $query): string {
        if (!current_user_can('manage_options')) {
            return 'forbidden';
        }

        $state = isset($query['state']) && is_string($query['state']) ? $query['state'] : '';
        $code = isset($query['code']) && is_string($query['code']) ? sanitize_text_field($query['code']) : '';

        if (isset($query['error']) || $code === '') {
            // Still burn the state so the consent link cannot be reused.
            return $this->consume_oauth_state($state) ? 'failed' : 'invalid_state';
        }

        if (!$this->consume_oauth_state($state)) {
            return 'invalid_state';
        }

        return $this->exchange_code($code) ? 'connected' : 'failed';
    }

    /**
     * Handle OAuth callback: requires manage_options and a valid, unused state
     * for the current user before the code is exchanged.
     */
    public function handle_oauth_callback(string $code, string $state): bool {
        if (!current_user_can('manage_options') || !$this->consume_oauth_state($state)) {
            return false;
        }

        return $this->exchange_code($code);
    }

    /**
     * Exchange an authorization code for tokens. Callers must have validated
     * the capability and OAuth state first.
     */
    private function exchange_code(string $code): bool {
        $credentials = $this->get_credentials();

        // Never call Google with a missing (or undecryptable) client secret.
        if ($credentials['client_id'] === '' || $credentials['client_secret'] === '') {
            return false;
        }

        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->get_redirect_uri(),
            ],
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['access_token'])) {
            $credentials['access_token'] = $body['access_token'];
            $credentials['refresh_token'] = $body['refresh_token'] ?? $credentials['refresh_token'];
            $credentials['token_expires'] = time() + ($body['expires_in'] ?? 3600);
            \Peanut_GA_Credentials::save($credentials);
            return true;
        }

        return false;
    }

    /**
     * Create database tables
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        // Cache table for analytics data
        $table = $wpdb->prefix . 'peanut_ga_cache';
        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            cache_key varchar(100) NOT NULL,
            data longtext NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expires_at (expires_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
