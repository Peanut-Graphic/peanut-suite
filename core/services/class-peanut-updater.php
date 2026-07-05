<?php
/**
 * Peanut Updater Service
 *
 * Handles self-hosted plugin updates from peanutgraphic.com
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Updater {

    /**
     * Update server API URL
     */
    private const API_URL = 'https://www.peanutgraphic.com/wp-json/peanut-api/v1';

    /**
     * Plugin slug
     */
    private const PLUGIN_SLUG = 'peanut-suite';

    /**
     * Ed25519 public key used to verify the signature of our own
     * GitHub-release update packages. This is a PUBLIC key (verification
     * only) — it is safe to embed and is NOT a secret. Fingerprint:
     * faaad8d7a7d7eaa9. The matching private key lives only in the
     * Peanut-meta release-signing pipeline.
     */
    private const PEANUT_SIGNING_PUBKEY = 'NtHnWTBLVzCBKMAq9CO8LHDSD9ZfpGV0UloQdgToIwM=';

    /**
     * Plugin file path
     */
    private string $plugin_file;

    /**
     * License service
     */
    private ?Peanut_License $license;

    /**
     * Cached update info
     */
    private ?object $update_info = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_file = 'peanut-suite/peanut-suite.php';
        $this->license = class_exists('Peanut_License') ? new Peanut_License() : null;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Check for updates
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);

        // Plugin information popup
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);

        // Add update message
        add_action('in_plugin_update_message-' . $this->plugin_file, [$this, 'update_message'], 10, 2);

        // Clear update cache when license changes
        add_action('update_option_peanut_license_key', [$this, 'clear_update_cache']);

        // Verify the Ed25519 signature of our own update package before WP installs it.
        add_filter('upgrader_pre_download', [$this, 'verify_package_signature'], 10, 4);
    }

    /**
     * Verify the Ed25519 signature of our GitHub-release package before install.
     *
     * Hooked on 'upgrader_pre_download'. When the package being downloaded is
     * THIS plugin's own signed GitHub-release ZIP, we download it, check its
     * sha256 + detached Ed25519 signature against the accompanying
     * <package>.manifest.json, and either return the verified local file path
     * (WordPress then installs from it, skipping its own download) or a
     * WP_Error to abort the update. For our own packages this is fail-CLOSED:
     * a missing/invalid manifest or signature refuses the install. Every other
     * package (core, themes, other plugins) is passed straight through
     * untouched.
     *
     * @param bool|WP_Error $reply      Short-circuit reply (default false).
     * @param string        $package    Package URL being downloaded.
     * @param WP_Upgrader   $upgrader   Upgrader instance (unused).
     * @param array         $hook_extra Extra args; carries 'plugin' for plugin updates.
     * @return string|bool|WP_Error Verified local file path, the untouched $reply, or a WP_Error.
     */
    public function verify_package_signature($reply, $package, $upgrader = null, $hook_extra = []) {
        // Only gate OUR plugin's own GitHub-release packages; let everything else pass.
        if (!empty($hook_extra['plugin']) && $hook_extra['plugin'] !== $this->plugin_file) {
            return $reply;
        }
        if (!is_string($package) || strpos($package, 'github.com/peanutgraphic/') === false) {
            return $reply; // not one of our packages — don't interfere
        }

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $zip = download_url($package);
        if (is_wp_error($zip)) {
            return $zip;
        }

        $mresp    = wp_remote_get($package . '.manifest.json', ['timeout' => 20]);
        $manifest = json_decode(wp_remote_retrieve_body($mresp), true);

        $fail = static function (string $msg) use ($zip) {
            if (is_string($zip)) {
                @unlink($zip);
            }
            return new WP_Error('peanut_update_signature', $msg);
        };

        if (!is_array($manifest) || empty($manifest['sha256']) || empty($manifest['signature'])) {
            return $fail(__('Update signature manifest missing — refusing to install an unsigned package.', 'peanut-suite'));
        }
        if (!hash_equals((string) $manifest['sha256'], hash_file('sha256', $zip))) {
            return $fail(__('Update package hash mismatch — refusing to install.', 'peanut-suite'));
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return $fail(__('Signature verification unavailable (libsodium) — refusing to install.', 'peanut-suite'));
        }
        if (!self::verify_bytes((string) file_get_contents($zip), $manifest)) {
            return $fail(__('Update package signature invalid — refusing to install.', 'peanut-suite'));
        }

        return $zip; // verified — WP installs from this local file
    }

    /**
     * Pure signature-verification core: does $manifest correctly authenticate $bytes?
     *
     * Confirms the manifest carries a sha256 + detached Ed25519 signature, that
     * the sha256 matches the bytes, and that the signature verifies against our
     * embedded public key. Side-effect-free (no filesystem, no network, no
     * WordPress runtime) so it can be unit-tested in isolation. Returns false
     * for any missing field, hash mismatch, unavailable libsodium, or bad
     * signature — fail-closed.
     *
     * @param string      $bytes     Raw package bytes.
     * @param array       $manifest  Decoded manifest with 'sha256' and base64 'signature'.
     * @param string|null $pubkey_b64 Base64 Ed25519 public key; defaults to our embedded
     *                                release-signing key. Overridable only so the logic can
     *                                be exercised with a test keypair — production never passes it.
     * @return bool True only if the bytes are authentically signed.
     */
    public static function verify_bytes(string $bytes, array $manifest, ?string $pubkey_b64 = null): bool {
        if (empty($manifest['sha256']) || empty($manifest['signature'])) {
            return false;
        }
        if (!hash_equals((string) $manifest['sha256'], hash('sha256', $bytes))) {
            return false;
        }
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        return sodium_crypto_sign_verify_detached(
            base64_decode((string) $manifest['signature']),
            $bytes,
            base64_decode($pubkey_b64 ?? self::PEANUT_SIGNING_PUBKEY)
        );
    }

    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get current version
        $current_version = $transient->checked[$this->plugin_file] ?? '0.0.0';

        // Get remote update info
        $remote = $this->get_remote_update_info($current_version);

        if ($remote && isset($remote->version)) {
            if (version_compare($remote->version, $current_version, '>')) {
                $transient->response[$this->plugin_file] = (object) [
                    'slug' => self::PLUGIN_SLUG,
                    'plugin' => $this->plugin_file,
                    'new_version' => $remote->version,
                    'package' => $remote->download_url ?? '',
                    'url' => $remote->homepage ?? 'https://peanutgraphic.com/peanut-suite',
                    'tested' => $remote->tested ?? '',
                    'requires_php' => $remote->requires_php ?? '8.0',
                    'requires' => $remote->requires ?? '6.0',
                    'icons' => [
                        '1x' => $remote->icons->{'1x'} ?? '',
                        '2x' => $remote->icons->{'2x'} ?? '',
                    ],
                    'banners' => [
                        'low' => $remote->banners->low ?? '',
                        'high' => $remote->banners->high ?? '',
                    ],
                ];
            } else {
                // No update available
                $transient->no_update[$this->plugin_file] = (object) [
                    'slug' => self::PLUGIN_SLUG,
                    'plugin' => $this->plugin_file,
                    'new_version' => $current_version,
                    'url' => $remote->homepage ?? 'https://peanutgraphic.com/peanut-suite',
                ];
            }
        }

        return $transient;
    }

    /**
     * Plugin information for popup
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }

        $remote = $this->get_remote_plugin_info();

        if (!$remote) {
            return $result;
        }

        return (object) [
            'name' => $remote->name ?? 'Peanut Suite',
            'slug' => self::PLUGIN_SLUG,
            'version' => $remote->version ?? '1.0.0',
            'author' => $remote->author ?? '<a href="https://peanutgraphic.com">Peanut Graphic</a>',
            'author_profile' => $remote->author_profile ?? 'https://peanutgraphic.com',
            'homepage' => $remote->homepage ?? 'https://peanutgraphic.com/peanut-suite',
            'download_link' => $remote->download_url ?? '',
            'trunk' => $remote->download_url ?? '',
            'requires' => $remote->requires ?? '6.0',
            'tested' => $remote->tested ?? '',
            'requires_php' => $remote->requires_php ?? '8.0',
            'last_updated' => $remote->last_updated ?? '',
            'sections' => [
                'description' => $remote->sections->description ?? '',
                'installation' => $remote->sections->installation ?? '',
                'changelog' => $remote->sections->changelog ?? '',
                'faq' => $remote->sections->faq ?? '',
            ],
            'banners' => [
                'low' => $remote->banners->low ?? '',
                'high' => $remote->banners->high ?? '',
            ],
            'icons' => [
                '1x' => $remote->icons->{'1x'} ?? '',
                '2x' => $remote->icons->{'2x'} ?? '',
            ],
        ];
    }

    /**
     * Add update message
     */
    public function update_message($plugin_data, $response): void {
        $license_key = $this->license ? $this->license->get_license_key() : '';

        if (empty($license_key)) {
            echo '<br><br><strong>' . esc_html__('Please enter your license key to receive automatic updates.', 'peanut-suite') . '</strong> ';
            echo '<a href="' . esc_url(admin_url('admin.php?page=peanut-settings')) . '">' . esc_html__('Enter License Key', 'peanut-suite') . '</a>';
        }
    }

    /**
     * Get remote update info
     */
    private function get_remote_update_info(string $current_version): ?object {
        // Check cache
        $cache_key = 'peanut_update_check_' . md5($current_version);
        $cached = get_transient($cache_key);

        if ($cached !== false && is_object($cached)) {
            return $cached;
        }

        // Build query args
        $args = [
            'plugin' => self::PLUGIN_SLUG,
            'version' => $current_version,
            'site_url' => home_url(),
        ];

        // Add license if available
        if ($this->license) {
            $license_key = $this->license->get_license_key();
            if (!empty($license_key)) {
                $args['license'] = $license_key;
            }
        }

        // Make request
        $response = wp_remote_get(
            add_query_arg($args, self::API_URL . '/updates/check'),
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response));

        if (!$body || !isset($body->update_available)) {
            return null;
        }

        // Cache for 12 hours
        set_transient($cache_key, $body->plugin_info ?? null, 12 * HOUR_IN_SECONDS);

        return $body->plugin_info ?? null;
    }

    /**
     * Get remote plugin info
     */
    private function get_remote_plugin_info(): ?object {
        // Check cache
        $cache_key = 'peanut_plugin_info';
        $cached = get_transient($cache_key);

        if ($cached !== false && is_object($cached)) {
            return $cached;
        }

        // Build query args
        $args = [];

        if ($this->license) {
            $license_key = $this->license->get_license_key();
            if (!empty($license_key)) {
                $args['license'] = $license_key;
            }
        }

        // Make request
        $response = wp_remote_get(
            add_query_arg($args, self::API_URL . '/updates/info'),
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response));

        if (!$body) {
            return null;
        }

        // Cache for 12 hours
        set_transient($cache_key, $body, 12 * HOUR_IN_SECONDS);

        return $body;
    }

    /**
     * Clear update cache
     */
    public function clear_update_cache(): void {
        delete_transient('peanut_plugin_info');
        delete_site_transient('update_plugins');

        // Clear version-specific caches
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE option_name LIKE %s", $wpdb->options, '_transient_peanut_update_check_%'));
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE option_name LIKE %s", $wpdb->options, '_transient_timeout_peanut_update_check_%'));
    }

    /**
     * Manually check for updates
     */
    public function force_update_check(): ?object {
        $this->clear_update_cache();

        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $this->plugin_file);
        $current_version = $plugin_data['Version'] ?? '0.0.0';

        return $this->get_remote_update_info($current_version);
    }
}
