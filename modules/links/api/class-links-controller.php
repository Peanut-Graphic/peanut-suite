<?php
/**
 * Links REST Controller
 */

if (!defined('ABSPATH')) {
    exit;
}

class Links_Controller extends Peanut_REST_Controller {

    protected string $rest_base = 'links';

    public function register_routes(): void {
        // List links
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_items'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Create link
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Get link
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Update link
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Delete link
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Get click stats
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/clicks', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_clicks'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Quick create from UTM
        register_rest_route($this->namespace, '/' . $this->rest_base . '/from-utm', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_from_utm'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);
    }

    /**
     * Get links
     */
    public function get_items(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Peanut_Database::links_table();
        $user_id = get_current_user_id();

        $pagination = $this->get_pagination($request);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        ));

        $offset = ($pagination['page'] - 1) * $pagination['per_page'];
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            $user_id,
            $pagination['per_page'],
            $offset
        ), ARRAY_A);

        return $this->paginated(
            array_map([$this, 'prepare_item'], $items),
            $total,
            $pagination['page'],
            $pagination['per_page']
        );
    }

    /**
     * Get single link
     */
    public function get_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::links_table();

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d",
            $request->get_param('id'),
            get_current_user_id()
        ), ARRAY_A);

        if (!$item) {
            return $this->not_found(__('Link not found', 'peanut-suite'));
        }

        return $this->success($this->prepare_item($item));
    }

    /**
     * Create link
     */
    public function create_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::links_table();
        $user_id = get_current_user_id();

        // Check limit
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        ));

        if (!$this->check_limit('links', $count)) {
            return $this->error(
                __('You have reached your link limit. Upgrade to create more.', 'peanut-suite'),
                'limit_reached',
                403
            );
        }

        $destination = esc_url_raw($request->get_param('destination_url'));
        if (empty($destination)) {
            return $this->error(__('Destination URL is required', 'peanut-suite'));
        }

        if (strlen($destination) > 2048) {
            return $this->error('URL exceeds maximum length of 2048 characters', 400);
        }

        // Custom slug or generate
        $slug = $request->get_param('slug');
        if ($slug) {
            $slug = sanitize_title($slug);
            // Check uniqueness
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE slug = %s",
                $slug
            ));
            if ($exists) {
                return $this->error(__('This slug is already taken', 'peanut-suite'));
            }
        } else {
            $slug = Links_Module::generate_slug();
        }

        $data = [
            'user_id' => $user_id,
            'slug' => $slug,
            'destination_url' => $destination,
            'title' => sanitize_text_field($request->get_param('title')),
            'utm_id' => absint($request->get_param('utm_id')) ?: null,
        ];

        // Optional: expiration
        $expires = $request->get_param('expires_at');
        if ($expires) {
            $data['expires_at'] = sanitize_text_field($expires);
        }

        // Optional: password
        $password = $request->get_param('password');
        if ($password) {
            $data['password_hash'] = wp_hash_password($password);
        }

        $wpdb->insert($table, $data);
        $id = $wpdb->insert_id;

        if (!$id) {
            return $this->error(__('Failed to create link', 'peanut-suite'), 'create_failed', 500);
        }

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);

        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        Peanut_Audit_Log_Service::log($account ? $account['id'] : 0, Peanut_Audit_Log_Service::ACTION_CREATE, Peanut_Audit_Log_Service::RESOURCE_LINK, $id);

        return $this->success($this->prepare_item($item));
    }

    /**
     * Update link
     */
    public function update_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::links_table();
        $id = $request->get_param('id');
        $user_id = get_current_user_id();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));

        if (!$exists) {
            return $this->not_found(__('Link not found', 'peanut-suite'));
        }

        $data = [];

        $destination = $request->get_param('destination_url');
        if ($destination !== null) {
            $data['destination_url'] = esc_url_raw($destination);
        }

        $title = $request->get_param('title');
        if ($title !== null) {
            $data['title'] = sanitize_text_field($title);
        }

        $is_active = $request->get_param('is_active');
        if ($is_active !== null) {
            $data['is_active'] = (int) $is_active;
        }

        if (empty($data)) {
            return $this->error(__('No data to update', 'peanut-suite'));
        }

        $wpdb->update($table, $data, ['id' => $id]);

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);

        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        Peanut_Audit_Log_Service::log($account ? $account['id'] : 0, Peanut_Audit_Log_Service::ACTION_UPDATE, Peanut_Audit_Log_Service::RESOURCE_LINK, (int) $id);

        return $this->success($this->prepare_item($item));
    }

    /**
     * Delete link
     */
    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::links_table();
        $clicks_table = Peanut_Database::link_clicks_table();
        $id = $request->get_param('id');
        $user_id = get_current_user_id();

        // Delete clicks first
        $wpdb->query($wpdb->prepare(
            "DELETE c FROM $clicks_table c
             JOIN $table l ON c.link_id = l.id
             WHERE l.id = %d AND l.user_id = %d",
            $id,
            $user_id
        ));

        // Delete link
        $deleted = $wpdb->delete($table, ['id' => $id, 'user_id' => $user_id]);

        if (!$deleted) {
            return $this->not_found(__('Link not found', 'peanut-suite'));
        }

        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        Peanut_Audit_Log_Service::log($account ? $account['id'] : 0, Peanut_Audit_Log_Service::ACTION_DELETE, Peanut_Audit_Log_Service::RESOURCE_LINK, (int) $id);

        return $this->success(['message' => __('Link deleted', 'peanut-suite')]);
    }

    /**
     * Get click statistics
     */
    public function get_clicks(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $links_table = Peanut_Database::links_table();
        $clicks_table = Peanut_Database::link_clicks_table();
        $id = $request->get_param('id');
        $user_id = get_current_user_id();

        // Verify ownership
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $links_table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));

        if (!$link) {
            return $this->not_found(__('Link not found', 'peanut-suite'));
        }

        // Get click breakdown
        $by_device = $wpdb->get_results($wpdb->prepare(
            "SELECT device_type, COUNT(*) as count
             FROM $clicks_table WHERE link_id = %d
             GROUP BY device_type",
            $id
        ), ARRAY_A);

        $by_browser = $wpdb->get_results($wpdb->prepare(
            "SELECT browser, COUNT(*) as count
             FROM $clicks_table WHERE link_id = %d
             GROUP BY browser
             ORDER BY count DESC LIMIT 5",
            $id
        ), ARRAY_A);

        $by_date = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(clicked_at) as date, COUNT(*) as count
             FROM $clicks_table WHERE link_id = %d
             GROUP BY DATE(clicked_at)
             ORDER BY date DESC LIMIT 30",
            $id
        ), ARRAY_A);

        $top_referers = $wpdb->get_results($wpdb->prepare(
            "SELECT referer, COUNT(*) as count
             FROM $clicks_table WHERE link_id = %d AND referer IS NOT NULL
             GROUP BY referer
             ORDER BY count DESC LIMIT 10",
            $id
        ), ARRAY_A);

        return $this->success([
            'total' => (int) $link->click_count,
            'by_device' => $by_device,
            'by_browser' => $by_browser,
            'by_date' => $by_date,
            'top_referers' => $top_referers,
        ]);
    }

    /**
     * Create link from existing UTM
     */
    public function create_from_utm(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $utm_table = Peanut_Database::utms_table();
        $utm_id = absint($request->get_param('utm_id'));
        $user_id = get_current_user_id();

        $utm = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $utm_table WHERE id = %d AND user_id = %d",
            $utm_id,
            $user_id
        ));

        if (!$utm) {
            return $this->not_found(__('UTM not found', 'peanut-suite'));
        }

        // Create link with UTM's full URL
        $request->set_param('destination_url', $utm->full_url);
        $request->set_param('title', $utm->name);
        $request->set_param('utm_id', $utm_id);

        return $this->create_item($request);
    }

    /**
     * Prepare item for response
     */
    private function prepare_item(array $item): array {
        $settings = get_option('peanut_settings', []);
        $prefix = $settings['link_prefix'] ?? 'go';
        $short_url = home_url("/{$prefix}/{$item['slug']}");

        return [
            'id' => (int) $item['id'],
            'slug' => $item['slug'],
            'short_url' => $short_url,
            'destination_url' => $item['destination_url'],
            'title' => $item['title'],
            'utm_id' => $item['utm_id'] ? (int) $item['utm_id'] : null,
            'click_count' => (int) $item['click_count'],
            'is_active' => (bool) $item['is_active'],
            'expires_at' => $item['expires_at'],
            'has_password' => !empty($item['password_hash']),
            'qr_code_url' => Links_Module::get_qr_code_url($short_url),
            'created_at' => $item['created_at'],
        ];
    }
}
