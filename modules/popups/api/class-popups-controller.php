<?php
/**
 * Popups REST API Controller
 *
 * Handles all Popups-related API endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Popups_Controller extends Peanut_REST_Controller {

    /**
     * Constructor
     */
    public function __construct() {
        $this->rest_base = 'popups';
    }

    /**
     * Permission callback for the popup-management endpoints.
     *
     * These are admin CRUD/bulk/stat routes for managing a site's popups, so they
     * require an authenticated administrator (manage_options) with a valid REST
     * nonce. Previously the routes referenced a `check_permission` method that did
     * not exist on this class or its base, so every popups REST route either
     * fataled or was mis-gated. Mirror the base controller's admin gate.
     *
     * @see Peanut_REST_Controller::admin_permission_callback()
     */
    public function check_permission(WP_REST_Request $request): bool|WP_Error {
        return $this->admin_permission_callback($request);
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // List / Create popups
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_popups'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => $this->get_collection_params(),
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_popup'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => $this->get_popup_params(),
            ],
        ]);

        // Single popup CRUD
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_popup'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_popup'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => $this->get_popup_params(),
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_popup'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        // Duplicate popup
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/(?P<id>\d+)/duplicate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'duplicate_popup'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Popup stats
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/(?P<id>\d+)/stats', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_popup_stats'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'days' => [
                    'type' => 'integer',
                    'default' => 30,
                ],
            ],
        ]);

        // Bulk actions
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/bulk', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulk_action'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'action' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['delete', 'activate', 'pause', 'archive'],
                ],
                'ids' => [
                    'required' => true,
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                ],
            ],
        ]);

        // Get trigger types
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/triggers', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_trigger_types'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Get default popup structure
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/defaults', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_defaults'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Get popups list
     */
    public function get_popups(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Popups_Database::popups_table();
        $user_id = get_current_user_id();

        // Build query
        $where = ['user_id = %d'];
        $values = [$user_id];

        // Status filter
        $status = $request->get_param('status');
        if ($status && $status !== 'all') {
            $where[] = 'status = %s';
            $values[] = $status;
        }

        // Type filter
        $type = $request->get_param('type');
        if ($type) {
            $where[] = 'type = %s';
            $values[] = $type;
        }

        // Search
        $search = $request->get_param('search');
        if ($search) {
            $where[] = '(name LIKE %s OR title LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $values[] = $search_term;
            $values[] = $search_term;
        }

        $where_clause = implode(' AND ', $where);

        // Sorting
        $order_by = $request->get_param('order_by') ?: 'created_at';
        $order = $request->get_param('order') ?: 'DESC';
        $allowed_order_by = ['name', 'type', 'status', 'views', 'conversions', 'created_at', 'updated_at'];
        if (!in_array($order_by, $allowed_order_by)) {
            $order_by = 'created_at';
        }

        // Pagination
        $per_page = (int) ($request->get_param('per_page') ?: 20);
        $page = (int) ($request->get_param('page') ?: 1);
        $offset = ($page - 1) * $per_page;

        // Get results
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE $where_clause ORDER BY $order_by $order LIMIT %d OFFSET %d",
            array_merge($values, [$per_page, $offset])
        ));

        // Get total
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE $where_clause",
            $values
        ));

        // Parse JSON fields
        foreach ($results as &$popup) {
            $popup = $this->prepare_popup_for_response($popup);
        }

        return $this->success([
            'items' => $results,
            'total' => $total,
            'pages' => ceil($total / $per_page),
            'page' => $page,
        ]);
    }

    /**
     * Get single popup
     */
    public function get_popup(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Popups_Database::popups_table();
        $user_id = get_current_user_id();
        $id = (int) $request->get_param('id');

        $popup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));

        if (!$popup) {
            return $this->error('not_found', __('Popup not found.', 'peanut-suite'), 404);
        }

        return $this->success($this->prepare_popup_for_response($popup));
    }

    /**
     * Create popup
     */
    public function create_popup(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Popups_Database::popups_table();
        $user_id = get_current_user_id();

        $data = [
            'user_id' => $user_id,
            'name' => sanitize_text_field($request->get_param('name') ?: 'New Popup'),
            'type' => sanitize_text_field($request->get_param('type') ?: 'modal'),
            'position' => sanitize_text_field($request->get_param('position') ?: 'center'),
            'status' => 'draft',
            'priority' => (int) ($request->get_param('priority') ?: 10),
            'title' => sanitize_text_field($request->get_param('title') ?: ''),
            'content' => wp_kses_post($request->get_param('content') ?: ''),
            'image_url' => esc_url_raw($request->get_param('image_url') ?: ''),
            'form_fields' => wp_json_encode($request->get_param('form_fields') ?: []),
            'button_text' => sanitize_text_field($request->get_param('button_text') ?: 'Subscribe'),
            'success_message' => sanitize_text_field($request->get_param('success_message') ?: 'Thank you!'),
            'triggers' => wp_json_encode($request->get_param('triggers') ?: []),
            'display_rules' => wp_json_encode($request->get_param('display_rules') ?: []),
            'styles' => wp_json_encode($request->get_param('styles') ?: []),
            'settings' => wp_json_encode($request->get_param('settings') ?: []),
            'start_date' => $request->get_param('start_date'),
            'end_date' => $request->get_param('end_date'),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $result = $wpdb->insert($table, $data);

        if ($result === false) {
            return $this->error('create_failed', __('Failed to create popup.', 'peanut-suite'));
        }

        $popup_id = $wpdb->insert_id;
        $popup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $popup_id));

        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        Peanut_Audit_Log_Service::log($account ? $account['id'] : 0, Peanut_Audit_Log_Service::ACTION_CREATE, 'popup', $popup_id);

        return $this->success([
            'id' => $popup_id,
            'popup' => $this->prepare_popup_for_response($popup),
            'message' => __('Popup created.', 'peanut-suite'),
        ], 201);
    }

    /**
     * Update popup
     */
    public function update_popup(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Popups_Database::popups_table();
        $user_id = get_current_user_id();
        $id = (int) $request->get_param('id');

        // Verify ownership
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));

        if (!$existing) {
            return $this->error('not_found', __('Popup not found.', 'peanut-suite'), 404);
        }

        $data = ['updated_at' => current_time('mysql')];

        // Update fields if provided
        $fields = [
            'name' => 'sanitize_text_field',
            'type' => 'sanitize_text_field',
            'position' => 'sanitize_text_field',
            'status' => 'sanitize_text_field',
            'priority' => 'intval',
            'title' => 'sanitize_text_field',
            'content' => 'wp_kses_post',
            'image_url' => 'esc_url_raw',
            'button_text' => 'sanitize_text_field',
            'success_message' => 'sanitize_text_field',
        ];

        foreach ($fields as $field => $sanitizer) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $data[$field] = $sanitizer($value);
            }
        }

        // JSON fields
        $json_fields = ['form_fields', 'triggers', 'display_rules', 'styles', 'settings'];
        foreach ($json_fields as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $data[$field] = wp_json_encode($value);
            }
        }

        // Date fields
        if ($request->has_param('start_date')) {
            $data['start_date'] = $request->get_param('start_date') ?: null;
        }
        if ($request->has_param('end_date')) {
            $data['end_date'] = $request->get_param('end_date') ?: null;
        }

        $wpdb->update($table, $data, ['id' => $id]);

        $popup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        Peanut_Audit_Log_Service::log($account ? $account['id'] : 0, Peanut_Audit_Log_Service::ACTION_UPDATE, 'popup', $id);

        return $this->success([
            'popup' => $this->prepare_popup_for_response($popup),
            'message' => __('Popup updated.', 'peanut-suite'),
        ]);
    }

    /**
     * Delete popup
     */
    public function delete_popup(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Popups_Database::popups_table();
        $interactions_table = Popups_Database::interactions_table();
        $user_id = get_current_user_id();
        $id = (int) $request->get_param('id');

        // Verify ownership
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));

        if (!$existing) {
            return $this->error('not_found', __('Popup not found.', 'peanut-suite'), 404);
        }

        // Delete interactions
        $wpdb->delete($interactions_table, ['popup_id' => $id]);

        // Delete popup
        $wpdb->delete($table, ['id' => $id]);

        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        Peanut_Audit_Log_Service::log($account ? $account['id'] : 0, Peanut_Audit_Log_Service::ACTION_DELETE, 'popup', $id);

        return $this->success(['message' => __('Popup deleted.', 'peanut-suite')]);
    }

    /**
     * Duplicate popup
     */
    public function duplicate_popup(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Popups_Database::popups_table();
        $user_id = get_current_user_id();
        $id = (int) $request->get_param('id');

        // Get original
        $original = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ), ARRAY_A);

        if (!$original) {
            return $this->error('not_found', __('Popup not found.', 'peanut-suite'), 404);
        }

        // Prepare copy
        unset($original['id']);
        $original['name'] = $original['name'] . ' (Copy)';
        $original['status'] = 'draft';
        $original['views'] = 0;
        $original['conversions'] = 0;
        $original['created_at'] = current_time('mysql');
        $original['updated_at'] = current_time('mysql');

        $wpdb->insert($table, $original);
        $new_id = $wpdb->insert_id;

        $popup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $new_id));

        return $this->success([
            'id' => $new_id,
            'popup' => $this->prepare_popup_for_response($popup),
            'message' => __('Popup duplicated.', 'peanut-suite'),
        ], 201);
    }

    /**
     * Get popup stats
     */
    public function get_popup_stats(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $interactions_table = Popups_Database::interactions_table();
        $popup_id = (int) $request->get_param('id');
        $days = (int) ($request->get_param('days') ?: 30);

        // Daily stats
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(created_at) as date,
                SUM(CASE WHEN action = 'view' THEN 1 ELSE 0 END) as views,
                SUM(CASE WHEN action = 'convert' THEN 1 ELSE 0 END) as conversions,
                SUM(CASE WHEN action = 'dismiss' THEN 1 ELSE 0 END) as dismissals
             FROM $interactions_table
             WHERE popup_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            $popup_id,
            $days
        ), ARRAY_A);

        // Totals
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(CASE WHEN action = 'view' THEN 1 ELSE 0 END) as views,
                SUM(CASE WHEN action = 'convert' THEN 1 ELSE 0 END) as conversions,
                SUM(CASE WHEN action = 'dismiss' THEN 1 ELSE 0 END) as dismissals
             FROM $interactions_table
             WHERE popup_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $popup_id,
            $days
        ), ARRAY_A);

        $conversion_rate = 0;
        if ($totals['views'] > 0) {
            $conversion_rate = round(($totals['conversions'] / $totals['views']) * 100, 2);
        }

        return $this->success([
            'daily' => $daily,
            'totals' => [
                'views' => (int) $totals['views'],
                'conversions' => (int) $totals['conversions'],
                'dismissals' => (int) $totals['dismissals'],
                'conversion_rate' => $conversion_rate,
            ],
            'period_days' => $days,
        ]);
    }

    /**
     * Bulk action
     */
    public function bulk_action(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Popups_Database::popups_table();
        $user_id = get_current_user_id();

        $action = $request->get_param('action');
        $ids = $request->get_param('ids');

        if (empty($ids)) {
            return $this->error('no_ids', __('No popups selected.', 'peanut-suite'));
        }

        $ids_placeholder = implode(',', array_fill(0, count($ids), '%d'));
        $values = array_merge($ids, [$user_id]);

        switch ($action) {
            case 'delete':
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table WHERE id IN ($ids_placeholder) AND user_id = %d",
                    $values
                ));
                $message = __('Popups deleted.', 'peanut-suite');
                break;

            case 'activate':
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET status = 'active' WHERE id IN ($ids_placeholder) AND user_id = %d",
                    $values
                ));
                $message = __('Popups activated.', 'peanut-suite');
                break;

            case 'pause':
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET status = 'paused' WHERE id IN ($ids_placeholder) AND user_id = %d",
                    $values
                ));
                $message = __('Popups paused.', 'peanut-suite');
                break;

            case 'archive':
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET status = 'archived' WHERE id IN ($ids_placeholder) AND user_id = %d",
                    $values
                ));
                $message = __('Popups archived.', 'peanut-suite');
                break;

            default:
                return $this->error('invalid_action', __('Invalid action.', 'peanut-suite'));
        }

        return $this->success(['message' => $message]);
    }

    /**
     * Get trigger types
     */
    public function get_trigger_types(WP_REST_Request $request): WP_REST_Response {
        return $this->success([
            'triggers' => Popups_Triggers::get_trigger_types(),
            'positions' => Popups_Triggers::POSITIONS,
        ]);
    }

    /**
     * Get default popup structure
     */
    public function get_defaults(WP_REST_Request $request): WP_REST_Response {
        return $this->success(Popups_Database::get_default_popup());
    }

    /**
     * Prepare popup for response
     */
    private function prepare_popup_for_response(object $popup): object {
        $popup->form_fields = json_decode($popup->form_fields, true) ?? [];
        $popup->triggers = json_decode($popup->triggers, true) ?? [];
        $popup->display_rules = json_decode($popup->display_rules, true) ?? [];
        $popup->styles = json_decode($popup->styles, true) ?? [];
        $popup->settings = json_decode($popup->settings, true) ?? [];

        // Calculate conversion rate
        $popup->conversion_rate = 0;
        if ($popup->views > 0) {
            $popup->conversion_rate = round(($popup->conversions / $popup->views) * 100, 2);
        }

        return $popup;
    }

    /**
     * Get collection params
     */
    protected function get_collection_params(): array {
        return [
            'page' => ['type' => 'integer', 'default' => 1],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'search' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['all', 'draft', 'active', 'paused', 'archived']],
            'type' => ['type' => 'string', 'enum' => ['modal', 'slide-in', 'bar', 'fullscreen']],
            'order_by' => ['type' => 'string', 'default' => 'created_at'],
            'order' => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
        ];
    }

    /**
     * Get popup params for create/update
     */
    protected function get_popup_params(): array {
        return [
            'name' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => ['modal', 'slide-in', 'bar', 'fullscreen']],
            'position' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'paused', 'archived']],
            'priority' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'image_url' => ['type' => 'string'],
            'form_fields' => ['type' => 'array'],
            'button_text' => ['type' => 'string'],
            'success_message' => ['type' => 'string'],
            'triggers' => ['type' => 'object'],
            'display_rules' => ['type' => 'object'],
            'styles' => ['type' => 'object'],
            'settings' => ['type' => 'object'],
            'start_date' => ['type' => 'string'],
            'end_date' => ['type' => 'string'],
        ];
    }
}
