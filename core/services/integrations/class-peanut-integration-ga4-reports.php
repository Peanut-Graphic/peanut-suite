<?php
/**
 * Google Analytics 4 Reports Integration
 *
 * Reads data FROM GA4 via OAuth and the Analytics Data API.
 * (Complements class-integration-ga4.php which SENDS events to GA4)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Integration_GA4_Reports {

    /**
     * OAuth URLs
     */
    private const OAUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GA4_API_URL = 'https://analyticsdata.googleapis.com/v1beta';

    /**
     * Required OAuth scopes
     */
    private const SCOPES = [
        'https://www.googleapis.com/auth/analytics.readonly',
    ];

    /**
     * Cache duration (1 hour)
     */
    private const CACHE_DURATION = HOUR_IN_SECONDS;

    /**
     * OAuth `state` storage: a per-user transient holding the SHA-256 of the
     * random state sent to Google. Short-lived and single-use.
     */
    public const OAUTH_STATE_TRANSIENT_PREFIX = 'peanut_ga4_reports_oauth_state_';
    public const OAUTH_STATE_TTL = 10 * 60;

    /** Admin page the OAuth redirect URI points at. */
    public const ADMIN_PAGE = 'peanut-settings';
    public const CALLBACK_FLAG = 'ga4_reports_callback';

    /**
     * Client credentials
     */
    private string $client_id;
    private string $client_secret;
    private string $redirect_uri;

    /**
     * Constructor
     */
    public function __construct() {
        $settings = (array) get_option('peanut_settings', []);
        $this->client_id = $settings['ga4_reports_client_id'] ?? '';
        // Stored encrypted; '' when unset or undecryptable (= not configured),
        // so ciphertext is never sent to Google.
        $this->client_secret = Peanut_Settings_Secrets::get($settings, 'ga4_reports_client_secret');
        $this->redirect_uri = admin_url('admin.php?page=' . self::ADMIN_PAGE . '&' . self::CALLBACK_FLAG . '=1');
    }

    /**
     * Check if credentials are configured
     */
    public function has_credentials(): bool {
        return !empty($this->client_id) && !empty($this->client_secret);
    }

    /**
     * Get OAuth authorization URL
     *
     * Mints a fresh single-use `state` bound to the current user; returns ''
     * when no consent flow can be started.
     */
    public function get_auth_url(): string {
        if (!$this->has_credentials()) {
            return '';
        }

        $state = $this->create_oauth_state();
        if ($state === '') {
            return '';
        }

        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return self::OAUTH_URL . '?' . http_build_query($params);
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
     * admin_init handler: complete the OAuth redirect, then send the admin back
     * to the settings page without the code/state in the URL.
     *
     * NOTE: nothing registers this hook yet. The GA4 Reports integration is not
     * wired into the plugin (no production code constructs this class), so the
     * redirect URI currently lands on a page that ignores it. Whoever wires the
     * feature must add `add_action('admin_init', [$instance, 'maybe_handle_oauth_callback'])`.
     */
    public function maybe_handle_oauth_callback(): void {
        if (($_GET['page'] ?? '') !== self::ADMIN_PAGE || ($_GET[self::CALLBACK_FLAG] ?? '') !== '1') {
            return;
        }

        $status = $this->process_oauth_callback(wp_unslash($_GET));

        wp_safe_redirect(admin_url('admin.php?page=' . self::ADMIN_PAGE . '&ga4_reports_oauth=' . rawurlencode($status)));
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

        return $this->request_tokens($code)['success'] ? 'connected' : 'failed';
    }

    /**
     * Exchange authorization code for tokens. Requires manage_options and a
     * valid, unused state for the current user before anything reaches Google.
     */
    public function exchange_code(string $code, string $state): array {
        if (!current_user_can('manage_options')) {
            return [
                'success' => false,
                'error' => __('You are not allowed to connect Google Analytics', 'peanut-suite'),
            ];
        }

        if (!$this->consume_oauth_state($state)) {
            return [
                'success' => false,
                'error' => __('Invalid OAuth state', 'peanut-suite'),
            ];
        }

        return $this->request_tokens($code);
    }

    /**
     * Exchange an authorization code with Google. Callers must have validated
     * the capability and OAuth state first.
     */
    private function request_tokens(string $code): array {
        if (!$this->has_credentials()) {
            return [
                'success' => false,
                'error' => __('GA4 Reports OAuth credentials are not configured', 'peanut-suite'),
            ];
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'code' => $code,
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'redirect_uri' => $this->redirect_uri,
                'grant_type' => 'authorization_code',
            ],
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return [
                'success' => false,
                'error' => $body['error_description'] ?? $body['error'],
            ];
        }

        $this->store_tokens(
            $body['access_token'],
            $body['refresh_token'] ?? '',
            $body['expires_in'] ?? 3600
        );

        return [
            'success' => true,
            'access_token' => $body['access_token'],
        ];
    }

    /**
     * Store OAuth tokens
     */
    private function store_tokens(string $access_token, string $refresh_token, int $expires_in): void {
        $encryption = new Peanut_Encryption();

        update_option('peanut_ga4_reports_tokens', [
            'access_token' => $encryption->encrypt($access_token),
            'refresh_token' => $encryption->encrypt($refresh_token),
            'expires_at' => time() + $expires_in,
        ]);
    }

    /**
     * Get valid access token (refresh if needed)
     */
    public function get_access_token(): ?string {
        $tokens = get_option('peanut_ga4_reports_tokens', []);

        if (empty($tokens['access_token'])) {
            return null;
        }

        $encryption = new Peanut_Encryption();

        // Check if token is expired
        if (($tokens['expires_at'] ?? 0) < time()) {
            $refresh_token = $encryption->decrypt($tokens['refresh_token'] ?? '');
            if (!$refresh_token) {
                return null;
            }

            $new_token = $this->refresh_access_token($refresh_token);
            if (!$new_token) {
                return null;
            }

            return $new_token;
        }

        return $encryption->decrypt($tokens['access_token']);
    }

    /**
     * Refresh access token
     */
    private function refresh_access_token(string $refresh_token): ?string {
        if (!$this->has_credentials()) {
            return null;
        }

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token',
            ],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return null;
        }

        $this->store_tokens(
            $body['access_token'],
            $refresh_token,
            $body['expires_in'] ?? 3600
        );

        return $body['access_token'];
    }

    /**
     * Get list of GA4 properties
     */
    public function get_properties(): array {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return [];
        }

        $response = wp_remote_get(
            'https://analyticsadmin.googleapis.com/v1beta/accountSummaries',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                ],
            ]
        );

        if (is_wp_error($response)) {
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $properties = [];

        if (isset($body['accountSummaries'])) {
            foreach ($body['accountSummaries'] as $account) {
                if (isset($account['propertySummaries'])) {
                    foreach ($account['propertySummaries'] as $property) {
                        $properties[] = [
                            'id' => str_replace('properties/', '', $property['property']),
                            'name' => $property['displayName'],
                            'account' => $account['displayName'],
                        ];
                    }
                }
            }
        }

        return $properties;
    }

    /**
     * Set active property
     */
    public function set_property(string $property_id, string $property_name = ''): bool {
        update_option('peanut_ga4_reports_property', [
            'id' => $property_id,
            'name' => $property_name,
        ]);
        return true;
    }

    /**
     * Get connection status
     */
    public function get_connection_status(): array {
        $tokens = get_option('peanut_ga4_reports_tokens', []);

        if (empty($tokens['access_token'])) {
            return ['connected' => false];
        }

        $property = get_option('peanut_ga4_reports_property', []);

        return [
            'connected' => true,
            'property_id' => $property['id'] ?? '',
            'property_name' => $property['name'] ?? '',
            'expires_at' => date('Y-m-d H:i:s', $tokens['expires_at'] ?? 0),
        ];
    }

    /**
     * Disconnect GA4
     */
    public function disconnect(): bool {
        delete_option('peanut_ga4_reports_tokens');
        delete_option('peanut_ga4_reports_property');
        return true;
    }

    /**
     * Run a GA4 report
     */
    public function run_report(array $params): array {
        $access_token = $this->get_access_token();
        if (!$access_token) {
            return ['error' => 'Not connected to Google Analytics'];
        }

        $status = $this->get_connection_status();
        if (empty($status['property_id'])) {
            return ['error' => 'No GA4 property selected'];
        }

        // Check cache
        $cache_key = 'peanut_ga4_report_' . md5(wp_json_encode($params));
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $property_id = $status['property_id'];
        $url = self::GA4_API_URL . "/properties/{$property_id}:runReport";

        $body = [
            'dateRanges' => [
                [
                    'startDate' => $params['start_date'],
                    'endDate' => $params['end_date'],
                ],
            ],
            'dimensions' => array_map(fn($d) => ['name' => $d], $params['dimensions']),
            'metrics' => array_map(fn($m) => ['name' => $m], $params['metrics']),
        ];

        if (!empty($params['filters'])) {
            $body['dimensionFilter'] = $this->build_dimension_filter($params['filters']);
        }

        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($data['error'])) {
            return ['error' => $data['error']['message'] ?? 'API error'];
        }

        $result = $this->format_report_response($data, $params);

        // Cache the result
        set_transient($cache_key, $result, self::CACHE_DURATION);

        return $result;
    }

    /**
     * Build dimension filter
     */
    private function build_dimension_filter(array $filters): array {
        $expressions = [];

        foreach ($filters as $filter) {
            $expressions[] = [
                'filter' => [
                    'fieldName' => $filter[0],
                    'stringFilter' => [
                        'matchType' => 'EXACT',
                        'value' => $filter[2],
                    ],
                ],
            ];
        }

        if (count($expressions) === 1) {
            return $expressions[0];
        }

        return [
            'andGroup' => [
                'expressions' => $expressions,
            ],
        ];
    }

    /**
     * Format report response
     */
    private function format_report_response(array $data, array $params): array {
        $rows = [];

        if (!empty($data['rows'])) {
            foreach ($data['rows'] as $row) {
                $formatted_row = [];

                foreach ($row['dimensionValues'] ?? [] as $i => $dim) {
                    $formatted_row[$params['dimensions'][$i]] = $dim['value'];
                }

                foreach ($row['metricValues'] ?? [] as $i => $metric) {
                    $formatted_row[$params['metrics'][$i]] = floatval($metric['value']);
                }

                $rows[] = $formatted_row;
            }
        }

        return [
            'rows' => $rows,
            'row_count' => count($rows),
            'totals' => $data['totals'] ?? [],
        ];
    }

    /**
     * Predefined report: Traffic by source/medium
     */
    public function get_traffic_by_source(string $start_date, string $end_date): array {
        return $this->run_report([
            'start_date' => $start_date,
            'end_date' => $end_date,
            'dimensions' => ['sessionSource', 'sessionMedium'],
            'metrics' => ['sessions', 'activeUsers', 'bounceRate', 'averageSessionDuration'],
        ]);
    }

    /**
     * Predefined report: Campaign performance
     */
    public function get_campaign_performance(string $start_date, string $end_date): array {
        return $this->run_report([
            'start_date' => $start_date,
            'end_date' => $end_date,
            'dimensions' => ['sessionCampaignName', 'sessionSource', 'sessionMedium'],
            'metrics' => ['sessions', 'activeUsers', 'conversions'],
            'filters' => [
                ['sessionCampaignName', '!=', '(not set)'],
            ],
        ]);
    }

    /**
     * Predefined report: Platform breakdown
     */
    public function get_platform_breakdown(string $start_date, string $end_date): array {
        return $this->run_report([
            'start_date' => $start_date,
            'end_date' => $end_date,
            'dimensions' => ['deviceCategory'],
            'metrics' => ['sessions', 'activeUsers'],
        ]);
    }

    /**
     * Predefined report: Traffic over time
     */
    public function get_traffic_timeline(string $start_date, string $end_date): array {
        return $this->run_report([
            'start_date' => $start_date,
            'end_date' => $end_date,
            'dimensions' => ['date'],
            'metrics' => ['sessions', 'activeUsers', 'newUsers'],
        ]);
    }
}
