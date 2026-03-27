<?php
/**
 * Visitors Tracking Snippet Generator
 *
 * @package PeanutSuite\Visitors
 */

namespace PeanutSuite\Visitors;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generates and serves the tracking snippet.
 */
class Visitors_Snippet {

    /**
     * Initialize the snippet handler.
     */
    public function init(): void {
        // Add tracking script to frontend
        add_action('wp_enqueue_scripts', [$this, 'enqueue_tracking_script']);

        // Serve the tracking script via REST API for external sites
        add_action('rest_api_init', [$this, 'register_snippet_endpoint']);
    }

    /**
     * Enqueue tracking script on the frontend.
     */
    public function enqueue_tracking_script(): void {
        // Only load if tracking is enabled
        $settings = get_option('peanut_settings', []);
        if (empty($settings['track_visitors']) && !apply_filters('peanut_force_tracking', false)) {
            return;
        }

        // Inline the tracking script for best performance
        $script_path = PEANUT_PATH . 'modules/visitors/assets/peanut-tracking.js';
        if (!file_exists($script_path)) {
            return;
        }

        $script_content = file_get_contents($script_path);

        // Add configuration
        $config = [
            'endpoint' => rest_url('peanut/v1'),
            'siteId' => $this->get_site_id(),
            'autoTrack' => true,
            'trackHistory' => true,
            'debug' => defined('WP_DEBUG') && WP_DEBUG,
        ];

        $inline_script = sprintf(
            'window.peanutConfig = %s;',
            wp_json_encode($config)
        );

        // Register and enqueue
        wp_register_script('peanut-tracking', '', [], PEANUT_VERSION, true);
        wp_enqueue_script('peanut-tracking');
        wp_add_inline_script('peanut-tracking', $inline_script . "\n" . $script_content);
    }

    /**
     * Register REST endpoint for serving the tracking script.
     */
    public function register_snippet_endpoint(): void {
        register_rest_route('peanut/v1', '/track/snippet.js', [
            'methods' => 'GET',
            'callback' => [$this, 'serve_tracking_script'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('peanut/v1', '/visitors/snippet', [
            'methods' => 'GET',
            'callback' => [$this, 'get_embed_code'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Serve the tracking script with appropriate headers.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response|void
     */
    public function serve_tracking_script(\WP_REST_Request $request) {
        $script_path = PEANUT_PATH . 'modules/visitors/assets/peanut-tracking.js';

        if (!file_exists($script_path)) {
            return new \WP_REST_Response(['error' => 'Script not found'], 404);
        }

        $script = file_get_contents($script_path);

        // Add cache headers
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=86400'); // 24 hour cache
        header('X-Content-Type-Options: nosniff');

        // Allow CORS for external embedding — validate origin against site URL
        $allowed_origin = get_site_url();
        if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowed_origin) {
            header("Access-Control-Allow-Origin: {$allowed_origin}");
        }
        header('Access-Control-Allow-Methods: GET');

        echo $script;
        exit;
    }

    /**
     * Get the embed code for external sites.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_embed_code(\WP_REST_Request $request): \WP_REST_Response {
        $site_id = $this->get_site_id();
        $script_url = rest_url('peanut/v1/track/snippet.js');
        $api_url = rest_url('peanut/v1');

        // Generate the embed snippet
        $snippet = $this->generate_embed_snippet($script_url, $api_url, $site_id);

        return new \WP_REST_Response([
            'snippet' => $snippet,
            'script_url' => $script_url,
            'api_url' => $api_url,
            'site_id' => $site_id,
        ]);
    }

    /**
     * Generate embed snippet HTML.
     *
     * @param string $script_url Script URL.
     * @param string $api_url    API base URL.
     * @param string $site_id    Site identifier.
     * @return string
     */
    public function generate_embed_snippet(string $script_url, string $api_url, string $site_id): string {
        $config = wp_json_encode([
            'endpoint' => $api_url,
            'siteId' => $site_id,
        ]);

        return <<<HTML
<!-- Peanut Suite Tracking -->
<script>
window.peanutConfig = {$config};
</script>
<script src="{$script_url}" async></script>
<!-- End Peanut Suite Tracking -->
HTML;
    }

    /**
     * Get or generate site ID.
     *
     * @return string
     */
    public function get_site_id(): string {
        $site_id = get_option('peanut_site_id');

        if (empty($site_id)) {
            $site_id = wp_generate_uuid4();
            update_option('peanut_site_id', $site_id);
        }

        return $site_id;
    }

    /**
     * Get tracking script content for manual embedding.
     *
     * @return string
     */
    public function get_script_content(): string {
        $script_path = PEANUT_PATH . 'modules/visitors/assets/peanut-tracking.js';

        if (!file_exists($script_path)) {
            return '';
        }

        return file_get_contents($script_path);
    }
}
