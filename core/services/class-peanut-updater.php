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
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_peanut_update_check_%'"
        );
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_peanut_update_check_%'"
        );
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
