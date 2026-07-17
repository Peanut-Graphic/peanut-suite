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
    /**
     * Verify a signed entitlement from the license server.
     * @return array  authenticated entitlement (trust this) when a valid signature is present
     * @return null   no signature present (rollout window) — unless require-signature is on
     * @return false  signature present but invalid/tampered/stale/mismatched — reject
     */
    private function verify_entitlement(array $body, string $key) {
        $sig     = $body['signature'] ?? null;
        $require = (bool) apply_filters('peanut_suite_require_signed_license', false);

        if (!is_array($sig) || empty($sig['signature'])) {
            return $require ? false : null;
        }
        $pubkey = $this->pinned_public_key((string) ($sig['kid'] ?? ''));
        if ($pubkey === '') {
            return $require ? false : null;
        }

        // Canonical form MUST match the server (ksort + unescaped slashes/unicode).
        $payload = $sig; unset($payload['signature']);
        ksort($payload);
        $canonical = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $raw_sig = base64_decode($sig['signature'], true);
        $raw_pk  = base64_decode($pubkey, true);
        if ($raw_sig === false || $raw_pk === false
            || strlen($raw_sig) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($raw_sig, $canonical, $raw_pk)) {
            return false;
        }
        // Binding + freshness — a signature for another key/site or a stale one is rejected.
        if (!hash_equals(hash('sha256', $key), (string) ($sig['key_hash'] ?? ''))) {
            return false;
        }
        if (($sig['site_url'] ?? null) !== home_url()) {
            return false;
        }
        if (time() > (int) ($sig['issued_at'] ?? 0) + (int) ($sig['ttl'] ?? 0)) {
            return false;
        }
        return $sig;
    }

    /**
     * Trust-on-first-use pin of the server signing key, keyed by kid.
     * (Stronger than embedding nothing; a build-time embedded key would remove
     * the first-fetch MITM window — tracked as a follow-up.)
     */
    private function pinned_public_key(string $kid): string {
        $stored = get_option('peanut_suite_ls_pubkey');
        if (is_array($stored) && ($stored['kid'] ?? '') === $kid && !empty($stored['public_key'])) {
            return $stored['public_key'];
        }
        $res = wp_remote_get(self::LICENSE_API . '/public-key', ['timeout' => 15]);
        if (is_wp_error($res)) {
            return '';
        }
        $pk = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($pk) || ($pk['alg'] ?? '') !== 'ed25519' || empty($pk['public_key'])) {
            return '';
        }
        if (!is_array($stored) || empty($stored['kid']) || ($pk['kid'] ?? '') === $kid) {
            update_option('peanut_suite_ls_pubkey', [
                'kid'        => $pk['kid'] ?? '',
                'public_key' => $pk['public_key'],
            ], false);
        }
        return (($pk['kid'] ?? '') === $kid) ? $pk['public_key'] : '';
    }

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

        // Verify the server's signed entitlement (audit 2026-07, C1b). A valid
        // signature is the source of truth for tier/status and defeats a forged or
        // replayed grant. During rollout, responses with NO signature fall through
        // to legacy handling unless the require-signature filter is enabled.
        $verified = $this->verify_entitlement($body, $key);
        if ($verified === false) {
            return [
                'status' => 'invalid',
                'tier' => 'free',
                'message' => __('License signature verification failed', 'peanut-suite'),
                'error_code' => 'signature_invalid',
            ];
        }
        if (is_array($verified)) {
            // Authenticated values override the unsigned body.
            $body['license']['tier']   = $verified['tier']   ?? ($body['license']['tier']   ?? 'free');
            $body['license']['status'] = $verified['status'] ?? ($body['license']['status'] ?? 'invalid');
            if (array_key_exists('expires_at', $verified)) {
                $body['license']['expires_at'] = $verified['expires_at'];
            }
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
