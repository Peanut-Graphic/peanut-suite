<?php
/**
 * White-Label Module
 *
 * Custom branding for agency reports and dashboards.
 */

namespace PeanutSuite\WhiteLabel;

if (!defined('ABSPATH')) {
    exit;
}

class WhiteLabel_Module {

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

    private function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_filter('peanut_report_branding', [$this, 'apply_branding']);
        add_filter('peanut_admin_branding', [$this, 'apply_admin_branding']);

        // Replace Peanut branding in admin
        if ($this->is_whitelabel_enabled()) {
            add_action('admin_head', [$this, 'custom_admin_css']);
            add_filter('admin_footer_text', [$this, 'custom_footer_text']);
        }

        // AJAX handlers
        add_action('wp_ajax_peanut_save_whitelabel_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_peanut_reset_whitelabel_settings', [$this, 'ajax_reset_settings']);
    }

    /**
     * AJAX: Save whitelabel settings
     */
    public function ajax_save_settings(): void {
        check_ajax_referer('peanut_whitelabel', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $settings = [
            'company_name' => sanitize_text_field($_POST['company_name'] ?? ''),
            'company_logo' => esc_url_raw($_POST['company_logo'] ?? ''),
            'primary_color' => sanitize_hex_color($_POST['primary_color'] ?? '#2271b1'),
            'secondary_color' => sanitize_hex_color($_POST['secondary_color'] ?? '#135e96'),
            'hide_peanut_branding' => isset($_POST['hide_peanut_branding']) && $_POST['hide_peanut_branding'] !== '' && $_POST['hide_peanut_branding'] !== '0',
            'report_logo' => esc_url_raw($_POST['report_logo'] ?? ''),
            'report_footer' => sanitize_textarea_field($_POST['report_footer'] ?? ''),
            'custom_css' => wp_strip_all_tags($_POST['custom_css'] ?? ''),
        ];

        update_option('peanut_whitelabel_settings', $settings);
        wp_send_json_success(['message' => 'Settings saved']);
    }

    /**
     * AJAX: Reset whitelabel settings
     */
    public function ajax_reset_settings(): void {
        check_ajax_referer('peanut_whitelabel', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        delete_option('peanut_whitelabel_settings');
        wp_send_json_success(['message' => 'Settings reset']);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route(PEANUT_API_NAMESPACE, '/whitelabel/settings', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_settings'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_settings'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/whitelabel/logo', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'upload_logo'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);
    }

    public function admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Check if white-label is enabled
     */
    public function is_whitelabel_enabled(): bool {
        if (!peanut_is_agency()) {
            return false;
        }
        $settings = $this->get_branding_settings();
        return !empty($settings['enabled']);
    }

    /**
     * Get branding settings
     */
    public function get_branding_settings(): array {
        return get_option('peanut_whitelabel_settings', [
            'enabled' => false,
            'company_name' => '',
            'logo_url' => '',
            'logo_width' => 150,
            'primary_color' => '#2271b1',
            'secondary_color' => '#135e96',
            'accent_color' => '#72aee6',
            'email_footer' => '',
            'report_footer' => '',
            'hide_peanut_branding' => false,
            'custom_css' => '',
        ]);
    }

    /**
     * Get settings via API
     */
    public function get_settings(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response($this->get_branding_settings(), 200);
    }

    /**
     * Save settings via API
     */
    public function save_settings(\WP_REST_Request $request): \WP_REST_Response {
        $settings = [
            'enabled' => (bool) $request->get_param('enabled'),
            'company_name' => sanitize_text_field($request->get_param('company_name')),
            'logo_url' => esc_url_raw($request->get_param('logo_url')),
            'logo_width' => (int) $request->get_param('logo_width') ?: 150,
            'primary_color' => sanitize_hex_color($request->get_param('primary_color')) ?: '#2271b1',
            'secondary_color' => sanitize_hex_color($request->get_param('secondary_color')) ?: '#135e96',
            'accent_color' => sanitize_hex_color($request->get_param('accent_color')) ?: '#72aee6',
            'email_footer' => wp_kses_post($request->get_param('email_footer')),
            'report_footer' => wp_kses_post($request->get_param('report_footer')),
            'hide_peanut_branding' => (bool) $request->get_param('hide_peanut_branding'),
            'custom_css' => sanitize_textarea_field($request->get_param('custom_css')),
        ];

        update_option('peanut_whitelabel_settings', $settings);

        return new \WP_REST_Response(['message' => 'Settings saved', 'settings' => $settings], 200);
    }

    /**
     * Upload custom logo
     */
    public function upload_logo(\WP_REST_Request $request): \WP_REST_Response {
        $files = $request->get_file_params();

        if (empty($files['logo'])) {
            return new \WP_REST_Response(['error' => 'No file uploaded'], 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_handle_upload($files['logo'], ['test_form' => false]);

        if (isset($upload['error'])) {
            return new \WP_REST_Response(['error' => $upload['error']], 400);
        }

        // Update settings with new logo
        $settings = $this->get_branding_settings();
        $settings['logo_url'] = $upload['url'];
        update_option('peanut_whitelabel_settings', $settings);

        return new \WP_REST_Response([
            'url' => $upload['url'],
            'message' => 'Logo uploaded successfully'
        ], 200);
    }

    /**
     * Apply branding to reports
     */
    public function apply_branding(array $branding): array {
        if (!$this->is_whitelabel_enabled()) {
            return $branding;
        }

        $settings = $this->get_branding_settings();

        return array_merge($branding, [
            'company_name' => $settings['company_name'] ?: $branding['company_name'] ?? 'Peanut Suite',
            'logo_url' => $settings['logo_url'] ?: $branding['logo_url'] ?? '',
            'primary_color' => $settings['primary_color'],
            'secondary_color' => $settings['secondary_color'],
            'footer_text' => $settings['report_footer'] ?: $branding['footer_text'] ?? '',
            'hide_peanut' => $settings['hide_peanut_branding'],
        ]);
    }

    /**
     * Apply branding to admin
     */
    public function apply_admin_branding(array $branding): array {
        if (!$this->is_whitelabel_enabled()) {
            return $branding;
        }

        $settings = $this->get_branding_settings();

        return [
            'name' => $settings['company_name'] ?: 'Marketing Suite',
            'logo' => $settings['logo_url'],
            'colors' => [
                'primary' => $settings['primary_color'],
                'secondary' => $settings['secondary_color'],
                'accent' => $settings['accent_color'],
            ],
        ];
    }

    /**
     * Custom admin CSS for white-labeling
     */
    public function custom_admin_css(): void {
        $settings = $this->get_branding_settings();
        $primary = $settings['primary_color'];
        $secondary = $settings['secondary_color'];
        $accent = $settings['accent_color'];

        $css = "
        <style>
            /* White-label color overrides */
            .peanut-wrap .button-primary,
            .peanut-wrap .peanut-tab.active {
                background-color: {$primary} !important;
                border-color: {$primary} !important;
            }
            .peanut-wrap .button-primary:hover {
                background-color: {$secondary} !important;
                border-color: {$secondary} !important;
            }
            .peanut-wrap .peanut-stat-card .stat-icon {
                color: {$primary};
            }
            .peanut-wrap a {
                color: {$primary};
            }
            .peanut-wrap a:hover {
                color: {$secondary};
            }
        ";

        // Hide Peanut branding
        if ($settings['hide_peanut_branding']) {
            $css .= "
            .peanut-powered-by,
            .peanut-branding {
                display: none !important;
            }
            ";
        }

        // Custom CSS
        if (!empty($settings['custom_css'])) {
            $css .= $settings['custom_css'];
        }

        $css .= "</style>";

        echo $css;
    }

    /**
     * Custom footer text
     */
    public function custom_footer_text(string $text): string {
        $screen = get_current_screen();

        if ($screen && strpos($screen->id, 'peanut') !== false) {
            $settings = $this->get_branding_settings();
            if (!empty($settings['company_name'])) {
                return 'Powered by ' . esc_html($settings['company_name']);
            }
        }

        return $text;
    }

    /**
     * Get report branding for external use
     */
    public static function get_report_branding(): array {
        $instance = self::instance();
        $default = [
            'company_name' => 'Peanut Suite',
            'logo_url' => '',
            'primary_color' => '#2271b1',
            'footer_text' => '',
        ];

        return apply_filters('peanut_report_branding', $default);
    }
}
