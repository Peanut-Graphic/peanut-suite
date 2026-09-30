<?php
/**
 * Settings REST Controller
 *
 * Handles license, modules, and general settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Settings_Controller extends Peanut_REST_Controller {

    protected string $rest_base = 'settings';

    public function register_routes(): void {
        // Get settings
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_settings'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Update settings
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'update_settings'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);

        // License endpoints
        register_rest_route($this->namespace, '/license', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_license'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/license/activate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'activate_license'],
            'permission_callback' => [$this, 'admin_permission_callback'],
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/license/deactivate', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'deactivate_license'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);

        // Modules endpoints
        register_rest_route($this->namespace, '/modules', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_modules'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/modules/(?P<id>[a-z_]+)/activate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'activate_module'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);

        register_rest_route($this->namespace, '/modules/(?P<id>[a-z_]+)/deactivate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'deactivate_module'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);

        // Test integration connection
        register_rest_route($this->namespace, '/integrations/(?P<id>[a-z0-9_]+)/test', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_integration'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);
    }

    /**
     * Get all settings
     */
    public function get_settings(WP_REST_Request $request): WP_REST_Response {
        return $this->success([
            // Secrets (GA4 Reports client secret) are never returned; the
            // response carries a "<key>_set" flag instead.
            'general' => Peanut_Settings_Secrets::redact((array) get_option('peanut_settings', [])),
            'modules' => peanut_get_active_modules(),
        ]);
    }

    /**
     * Update settings
     */
    public function update_settings(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $settings = $request->get_param('settings');

        if (!is_array($settings)) {
            return $this->error(__('Invalid settings format', 'peanut-suite'));
        }

        $current = (array) get_option('peanut_settings', []);
        // A blank secret keeps the stored one; secrets are stored encrypted
        // (a legacy plaintext secret is encrypted by any save).
        $updated = Peanut_Settings_Secrets::prepare_update(
            Peanut_Security::sanitize_fields($settings),
            $current
        );

        update_option('peanut_settings', $updated);

        return $this->success([
            'message' => __('Settings saved', 'peanut-suite'),
            'settings' => Peanut_Settings_Secrets::redact($updated),
        ]);
    }

    /**
     * Get license status
     */
    public function get_license(WP_REST_Request $request): WP_REST_Response {
        $license = new Peanut_License();
        $data = $license->get_license_data();

        return $this->success([
            'status' => $data['status'],
            'tier' => $data['tier'],
            'expires_at' => $data['expires_at'] ?? null,
            'features' => $data['features'] ?? [],
            'is_pro' => peanut_is_pro(),
        ]);
    }

    /**
     * Activate license
     */
    public function activate_license(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $key = sanitize_text_field($request->get_param('license_key'));

        if (empty($key)) {
            return $this->error(__('License key is required', 'peanut-suite'));
        }

        $license = new Peanut_License();
        $result = $license->activate($key);

        if ($result['status'] !== 'active') {
            return $this->error(
                $result['message'] ?? __('License activation failed', 'peanut-suite'),
                'license_invalid'
            );
        }

        return $this->success([
            'message' => __('License activated successfully', 'peanut-suite'),
            'license' => $result,
        ]);
    }

    /**
     * Deactivate license
     */
    public function deactivate_license(WP_REST_Request $request): WP_REST_Response {
        $license = new Peanut_License();
        $license->deactivate();

        return $this->success([
            'message' => __('License deactivated', 'peanut-suite'),
        ]);
    }

    /**
     * Get modules
     */
    public function get_modules(WP_REST_Request $request): WP_REST_Response {
        global $peanut_module_manager;

        // Get module manager from global or create display data
        $active = peanut_get_active_modules();

        $modules = [
            [
                'id' => 'utm',
                'name' => __('Campaigns', 'peanut-suite'),
                'description' => __('Create and manage UTM tracking codes', 'peanut-suite'),
                'icon' => 'target',
                'active' => $active['utm'] ?? true,
                'pro' => false,
            ],
            [
                'id' => 'links',
                'name' => __('Links', 'peanut-suite'),
                'description' => __('Shorten URLs and generate QR codes', 'peanut-suite'),
                'icon' => 'link',
                'active' => $active['links'] ?? true,
                'pro' => false,
            ],
            [
                'id' => 'contacts',
                'name' => __('Contacts', 'peanut-suite'),
                'description' => __('Manage leads and contacts', 'peanut-suite'),
                'icon' => 'users',
                'active' => $active['contacts'] ?? true,
                'pro' => false,
            ],
            [
                'id' => 'dashboard',
                'name' => __('Dashboard', 'peanut-suite'),
                'description' => __('Unified analytics dashboard', 'peanut-suite'),
                'icon' => 'layout-dashboard',
                'active' => $active['dashboard'] ?? true,
                'pro' => false,
            ],
            [
                'id' => 'popups',
                'name' => __('Popups', 'peanut-suite'),
                'description' => __('Create lead capture popups', 'peanut-suite'),
                'icon' => 'message-square',
                'active' => $active['popups'] ?? false,
                'pro' => true,
                'locked' => !peanut_is_pro(),
            ],
        ];

        return $this->success(['modules' => $modules]);
    }

    /**
     * Activate module
     */
    public function activate_module(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = $request->get_param('id');
        $active = peanut_get_active_modules();

        // Check if pro module
        $pro_modules = ['popups'];
        if (in_array($id, $pro_modules, true) && !peanut_is_pro()) {
            return $this->error(
                __('This module requires a Pro license', 'peanut-suite'),
                'pro_required',
                403
            );
        }

        $active[$id] = true;
        update_option('peanut_active_modules', $active);

        return $this->success([
            'message' => __('Module activated', 'peanut-suite'),
            'modules' => $active,
        ]);
    }

    /**
     * Deactivate module
     */
    public function deactivate_module(WP_REST_Request $request): WP_REST_Response {
        $id = $request->get_param('id');
        $active = peanut_get_active_modules();

        $active[$id] = false;
        update_option('peanut_active_modules', $active);

        return $this->success([
            'message' => __('Module deactivated', 'peanut-suite'),
            'modules' => $active,
        ]);
    }

    /**
     * Test integration connection
     */
    public function test_integration(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = $request->get_param('id');

        // Load integration classes
        $integrations_dir = PEANUT_PLUGIN_DIR . 'core/services/integrations/';

        switch ($id) {
            case 'ga4':
                require_once $integrations_dir . 'class-integration-ga4.php';
                $integration = new Peanut_Integration_GA4();
                break;

            case 'gtm':
                require_once $integrations_dir . 'class-integration-gtm.php';
                $integration = new Peanut_Integration_GTM();
                break;

            case 'mailchimp':
                require_once $integrations_dir . 'class-integration-mailchimp.php';
                $integration = new Peanut_Integration_Mailchimp();
                break;

            case 'convertkit':
                require_once $integrations_dir . 'class-integration-convertkit.php';
                $integration = new Peanut_Integration_ConvertKit();
                break;

            case 'stripe':
                require_once PEANUT_PLUGIN_DIR . 'modules/invoicing/class-invoicing-stripe.php';
                $integration = new Invoicing_Stripe();
                break;

            default:
                return $this->error(
                    __('Unknown integration', 'peanut-suite'),
                    'unknown_integration',
                    404
                );
        }

        $result = $integration->test_connection();

        if ($result['success']) {
            return $this->success([
                'message' => $result['message'],
            ]);
        } else {
            return $this->error($result['message'], 'connection_failed');
        }
    }
}
