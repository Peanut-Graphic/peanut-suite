<?php
/**
 * License Service
 *
 * Handles license validation and feature access with remote license server.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_License {

    /**
     * License server API URL
     */
    private const LICENSE_API = 'https://peanutgraphic.com/wp-json/peanut-api/v1';

    /**
     * Cache duration (12 hours)
     */
    private const CACHE_DURATION = 12 * HOUR_IN_SECONDS;

    /**
     * Tier features
     */
    private const TIER_FEATURES = [
        'free' => [
            'utm_limit' => -1, // Unlimited
            'links_limit' => 100,
            'contacts_limit' => 500,
            'modules' => ['utm', 'links', 'contacts', 'dashboard'],
        ],
        'pro' => [
            'utm_limit' => -1,
            'links_limit' => -1,
            'contacts_limit' => -1,
            'modules' => ['utm', 'links', 'contacts', 'dashboard', 'popups'],
            'analytics' => true,
            'export' => true,
            'api_access' => true,
        ],
        'agency' => [
            'utm_limit' => -1,
            'links_limit' => -1,
            'contacts_limit' => -1,
            'monitor_sites_limit' => 25,
            'modules' => ['utm', 'links', 'contacts', 'dashboard', 'popups', 'monitor'],
            'analytics' => true,
            'export' => true,
            'api_access' => true,
            'white_label' => true,
            'priority_support' => true,
        ],
    ];

    /**
     * Validate license key
     */
    public function validate_license(string $key, bool $force_refresh = false): array {
        if (empty($key)) {
            return $this->free_license();
        }

        // Dev/demo/test license shortcuts are ONLY honored outside production.
        // In prod they must NOT unlock tiers (prevents the PEANUT-DEV-AGENCY
        // prefix from granting paid access on live sites). Audit 2026-07.
        if ($this->is_dev_license($key) && $this->dev_license_allowed()) {
            return $this->dev_license($key);
        }

        // Check cache
        if (!$force_refresh) {
            $cached = get_transient('peanut_license_data');
            if ($cached !== false) {
                return $cached;
            }
        }

        // Validate remotely
        $result = $this->remote_validate($key);

        if ($result['status'] === 'active') {
            set_transient('peanut_license_data', $result, self::CACHE_DURATION);
        }

        return $result;
    }

    /**
     * Remote license validation and activation
     */
    private function remote_validate(string $key): array {
        $response = wp_remote_post(self::LICENSE_API . '/license/validate', [
            'timeout' => 15,
            'body' => [
                'license_key' => $key,
                'site_url' => home_url(),
                'site_name' => get_bloginfo('name'),
                'plugin_version' => defined('PEANUT_SUITE_VERSION') ? PEANUT_SUITE_VERSION : '1.0.0',
            ],
        ]);

        if (is_wp_error($response)) {
            // Fall back to cached/offline mode
            $cached = get_transient('peanut_license_data');
            if ($cached !== false) {
                return $cached;
            }
            return $this->free_license();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        // Handle API error response
        if (!isset($body['success']) || !$body['success']) {
            return [
                'status' => 'invalid',
                'tier' => 'free',
                'message' => $body['message'] ?? __('Invalid license key', 'peanut-suite'),
                'error_code' => $body['error'] ?? 'unknown',
            ];
        }

        // Extract license data from response
        $license = $body['license'] ?? [];
        $tier = $license['tier'] ?? 'free';

        // Build features from API response or fall back to defaults
        $features = self::TIER_FEATURES[$tier] ?? self::TIER_FEATURES['free'];

        // If API returns specific feature flags, merge them
        if (!empty($license['features'])) {
            $features['modules'] = [];
            $feature_to_module = [
                'utm' => 'utm',
                'links' => 'links',
                'contacts' => 'contacts',
                'dashboard' => 'dashboard',
                'popups' => 'popups',
                'monitor' => 'monitor',
            ];
            foreach ($feature_to_module as $feat => $module) {
                if (!empty($license['features'][$feat])) {
                    $features['modules'][] = $module;
                }
            }
            $features['analytics'] = !empty($license['features']['analytics']);
            $features['export'] = !empty($license['features']['export']);
            $features['white_label'] = !empty($license['features']['white_label']);
            $features['priority_support'] = !empty($license['features']['priority_support']);
        }

        return [
            'status' => $license['status'] ?? 'active',
            'tier' => $tier,
            'tier_name' => $license['tier_name'] ?? ucfirst($tier),
            'expires_at' => $license['expires_at'] ?? null,
            'expires_at_formatted' => $license['expires_at_formatted'] ?? null,
            'activations_used' => $license['activations_used'] ?? 0,
            'activations_limit' => $license['activations_limit'] ?? 1,
            'features' => $features,
        ];
    }

    /**
     * Check if dev license
     */
    /**
     * Whether dev/demo/test license shortcuts may be honored in this environment.
     * Production returns false unless PEANUT_ALLOW_DEV_LICENSE is explicitly defined true.
     */
    private function dev_license_allowed(): bool {
        if (defined('PEANUT_ALLOW_DEV_LICENSE')) {
            return (bool) PEANUT_ALLOW_DEV_LICENSE;
        }
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        return in_array($env, ['local', 'development'], true);
    }

    private function is_dev_license(string $key): bool {
        $dev_prefixes = ['PEANUT-DEV-', 'PEANUT-DEMO-', 'PEANUT-TEST-'];
        foreach ($dev_prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dev license data
     */
    private function dev_license(string $key): array {
        $tier = 'pro';
        if (str_contains($key, 'AGENCY')) {
            $tier = 'agency';
        }

        return [
            'status' => 'active',
            'tier' => $tier,
            'email' => 'dev@peanutgraphic.com',
            'expires_at' => date('Y-m-d', strtotime('+1 year')),
            'features' => self::TIER_FEATURES[$tier],
            'is_dev' => true,
        ];
    }

    /**
     * Free license data
     */
    private function free_license(): array {
        return [
            'status' => 'free',
            'tier' => 'free',
            'features' => self::TIER_FEATURES['free'],
        ];
    }

    /**
     * Get current license data
     */
    public function get_license_data(): array {
        $key = get_option('peanut_license_key', '');
        return $this->validate_license($key);
    }

    /**
     * Check if feature is available
     */
    public function has_feature(string $feature): bool {
        $license = $this->get_license_data();
        $features = $license['features'] ?? self::TIER_FEATURES['free'];

        return !empty($features[$feature]);
    }

    /**
     * Get feature limit
     */
    public function get_limit(string $feature): int {
        $license = $this->get_license_data();
        $features = $license['features'] ?? self::TIER_FEATURES['free'];

        return $features[$feature . '_limit'] ?? 0;
    }

    /**
     * Activate license
     */
    public function activate(string $key): array {
        $result = $this->validate_license($key, true);

        if ($result['status'] === 'active') {
            update_option('peanut_license_key', $key);
            delete_transient('peanut_license_data');
            set_transient('peanut_license_data', $result, self::CACHE_DURATION);
        }

        return $result;
    }

    /**
     * Deactivate license
     */
    public function deactivate(): bool {
        $key = get_option('peanut_license_key', '');

        // Deactivate from remote server
        if (!empty($key) && !$this->is_dev_license($key)) {
            wp_remote_post(self::LICENSE_API . '/license/deactivate', [
                'timeout' => 10,
                'body' => [
                    'license_key' => $key,
                    'site_url' => home_url(),
                ],
            ]);
        }

        delete_option('peanut_license_key');
        delete_transient('peanut_license_data');
        return true;
    }

    /**
     * Get stored license key
     */
    public function get_license_key(): string {
        return get_option('peanut_license_key', '');
    }

    /**
     * Check if current tier meets minimum requirement
     */
    public function has_tier(string $required_tier): bool {
        $license = $this->get_license_data();
        $current_tier = $license['tier'] ?? 'free';

        $tier_levels = ['free' => 0, 'pro' => 1, 'agency' => 2];
        $current_level = $tier_levels[$current_tier] ?? 0;
        $required_level = $tier_levels[$required_tier] ?? 0;

        return $current_level >= $required_level;
    }

    /**
     * Check if module is available for current license
     */
    public function can_access_module(string $module): bool {
        $license = $this->get_license_data();
        $modules = $license['features']['modules'] ?? self::TIER_FEATURES['free']['modules'];

        return in_array($module, $modules, true);
    }

    /**
     * Check if pro or above
     */
    public function is_pro(): bool {
        return $this->has_tier('pro');
    }

    /**
     * Check if agency tier
     */
    public function is_agency(): bool {
        return $this->has_tier('agency');
    }

    /**
     * Get current tier name
     */
    public function get_tier(): string {
        $license = $this->get_license_data();
        return $license['tier'] ?? 'free';
    }
}
