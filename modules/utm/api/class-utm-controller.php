<?php
/**
 * UTM REST Controller
 */

if (!defined('ABSPATH')) {
    exit;
}

class UTM_Controller extends Peanut_REST_Controller {

    protected string $rest_base = 'utms';

    public function register_routes(): void {
        // List UTMs
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_items'],
            'permission_callback' => [$this, 'permission_callback'],
            'args' => $this->get_collection_args(),
        ]);

        // Create UTM
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Get single UTM
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Update UTM
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Delete UTM
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Bulk delete
        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk-delete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulk_delete'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Export
        register_rest_route($this->namespace, '/' . $this->rest_base . '/export', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'export'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // UTM Access routes
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/access', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_utm_access'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/assign', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'assign_utm_access'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/assign/(?P<user_id>\d+)', [
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'revoke_utm_access'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);
    }

    /**
     * Get UTMs - filters by user access for non-admin members
     */
    public function get_items(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Peanut_Database::utms_table();
        $access_table = Peanut_Database::utm_access_table();
        $user_id = get_current_user_id();

        // Get current account
        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        $account_id = $account ? $account['id'] : 0;
        $is_admin = $account && Peanut_Account_Service::user_has_role($account_id, $user_id, 'admin');

        $pagination = $this->get_pagination($request);
        $sort = $this->get_sort($request, ['created_at', 'name', 'utm_campaign', 'click_count']);

        // Build query - admins see all account UTMs, others only see assigned
        if ($is_admin) {
            // Admins see all UTMs in account (or their own if no account_id set)
            $where = ['(u.account_id = %d OR (u.account_id IS NULL AND u.user_id = %d))'];
            $params = [$account_id, $user_id];
            $from = "$table u";
        } else {
            // Non-admins only see UTMs they have access to
            $where = ['ua.user_id = %d', 'ua.account_id = %d'];
            $params = [$user_id, $account_id];
            $from = "$table u INNER JOIN $access_table ua ON u.id = ua.utm_id";
        }

        // Archived filter
        $archived = $request->get_param('archived');
        if ($archived === 'true') {
            $where[] = 'u.is_archived = 1';
        } else {
            $where[] = 'u.is_archived = 0';
        }

        // Search
        $search = $request->get_param('search');
        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(u.name LIKE %s OR u.utm_campaign LIKE %s OR u.base_url LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        // Filters
        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $filter) {
            $value = $request->get_param($filter);
            if (!empty($value)) {
                $where[] = "u.$filter = %s";
                $params[] = $value;
            }
        }

        $where_sql = implode(' AND ', $where);

        // Count
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT u.id) FROM $from WHERE $where_sql",
            ...$params
        ));

        // Get items
        $offset = ($pagination['page'] - 1) * $pagination['per_page'];
        $order_col = 'u.' . $sort['orderby'];
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT u.* FROM $from
             WHERE $where_sql
             ORDER BY {$order_col} {$sort['order']}
             LIMIT %d OFFSET %d",
            ...array_merge($params, [$pagination['per_page'], $offset])
        ), ARRAY_A);

        return $this->paginated(
            array_map([$this, 'prepare_item'], $items),
            $total,
            $pagination['page'],
            $pagination['per_page']
        );
    }

    /**
     * Get single UTM
     */
    public function get_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::utms_table();

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d",
            $request->get_param('id'),
            get_current_user_id()
        ), ARRAY_A);

        if (!$item) {
            return $this->not_found(__('UTM not found', 'peanut-suite'));
        }

        return $this->success($this->prepare_item($item));
    }

    /**
     * Create UTM
     */
    public function create_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::utms_table();
        $user_id = get_current_user_id();

        // Get current account
        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        $account_id = $account ? $account['id'] : null;

        // Check limit
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND is_archived = 0",
            $user_id
        ));

        if (!$this->check_limit('utm', $count)) {
            return $this->error(
                __('You have reached your UTM limit. Upgrade to create more.', 'peanut-suite'),
                'limit_reached',
                403
            );
        }

        // Validate
        $base_url = esc_url_raw($request->get_param('base_url'));
        $utm_source = sanitize_text_field($request->get_param('utm_source'));
        $utm_medium = sanitize_text_field($request->get_param('utm_medium'));
        $utm_campaign = sanitize_text_field($request->get_param('utm_campaign'));

        if (empty($base_url) || empty($utm_source) || empty($utm_medium) || empty($utm_campaign)) {
            return $this->error(__('Base URL, source, medium, and campaign are required', 'peanut-suite'));
        }

        // Build full URL
        $full_url = UTM_Module::build_url([
            'base_url' => $base_url,
            'utm_source' => $utm_source,
            'utm_medium' => $utm_medium,
            'utm_campaign' => $utm_campaign,
            'utm_term' => $request->get_param('utm_term'),
            'utm_content' => $request->get_param('utm_content'),
        ]);

        if (strlen($full_url) > 2048) {
            return $this->error('URL exceeds maximum length of 2048 characters', 400);
        }

        $data = [
            'user_id' => $user_id,
            'account_id' => $account_id,
            'name' => sanitize_text_field($request->get_param('name') ?: $utm_campaign),
            'base_url' => $base_url,
            'utm_source' => $utm_source,
            'utm_medium' => $utm_medium,
            'utm_campaign' => $utm_campaign,
            'utm_term' => sanitize_text_field($request->get_param('utm_term')),
            'utm_content' => sanitize_text_field($request->get_param('utm_content')),
            'full_url' => $full_url,
            'notes' => sanitize_textarea_field($request->get_param('notes')),
        ];

        $wpdb->insert($table, $data);
        $id = $wpdb->insert_id;

        if (!$id) {
            return $this->error(__('Failed to create UTM', 'peanut-suite'), 'create_failed', 500);
        }

        // Auto-assign access to creator
        if ($account_id) {
            Peanut_UTM_Access_Service::grant_access(
                $id,
                $user_id,
                $account_id,
                Peanut_UTM_Access_Service::ACCESS_FULL,
                $user_id
            );

            // Also assign to any specified users
            $assigned_users = $request->get_param('assigned_users');
            if (!empty($assigned_users) && is_array($assigned_users)) {
                $assigned_users = array_map('absint', $assigned_users);
                $assigned_users = array_diff($assigned_users, [$user_id]); // Exclude creator
                if (!empty($assigned_users)) {
                    Peanut_UTM_Access_Service::bulk_assign(
                        [$id],
                        $assigned_users,
                        $account_id,
                        Peanut_UTM_Access_Service::ACCESS_VIEW,
                        $user_id
                    );
                }
            }
        }

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);

        return $this->success($this->prepare_item($item));
    }

    /**
     * Update UTM
     */
    public function update_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::utms_table();
        $id = $request->get_param('id');
        $user_id = get_current_user_id();

        // Check exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));

        if (!$exists) {
            return $this->not_found(__('UTM not found', 'peanut-suite'));
        }

        $data = [];
        $fields = ['name', 'base_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'notes'];

        foreach ($fields as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $data[$field] = $field === 'base_url'
                    ? esc_url_raw($value)
                    : sanitize_text_field($value);
            }
        }

        // Handle archive
        $is_archived = $request->get_param('is_archived');
        if ($is_archived !== null) {
            $data['is_archived'] = (int) $is_archived;
        }

        if (empty($data)) {
            return $this->error(__('No data to update', 'peanut-suite'));
        }

        // Rebuild URL if UTM params changed
        if (array_intersect(['base_url', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'], array_keys($data))) {
            $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
            $merged = array_merge($current, $data);

            $data['full_url'] = UTM_Module::build_url($merged);
        }

        $wpdb->update($table, $data, ['id' => $id]);

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);

        return $this->success($this->prepare_item($item));
    }

    /**
     * Delete UTM
     */
    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::utms_table();

        $deleted = $wpdb->delete($table, [
            'id' => $request->get_param('id'),
            'user_id' => get_current_user_id(),
        ]);

        if (!$deleted) {
            return $this->not_found(__('UTM not found', 'peanut-suite'));
        }

        return $this->success(['message' => __('UTM deleted', 'peanut-suite')]);
    }

    /**
     * Bulk delete
     */
    public function bulk_delete(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::utms_table();
        $ids = $request->get_param('ids');
        $user_id = get_current_user_id();

        if (empty($ids) || !is_array($ids)) {
            return $this->error(__('No IDs provided', 'peanut-suite'));
        }

        $ids = array_map('absint', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE id IN ($placeholders) AND user_id = %d",
            ...array_merge($ids, [$user_id])
        ));

        return $this->success([
            'message' => sprintf(__('%d UTMs deleted', 'peanut-suite'), $deleted),
            'deleted' => $deleted,
        ]);
    }

    /**
     * Export UTMs as CSV
     */
    public function export(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Peanut_Database::utms_table();
        $user_id = get_current_user_id();

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT name, base_url, utm_source, utm_medium, utm_campaign, utm_term, utm_content, full_url, click_count, created_at
             FROM $table WHERE user_id = %d AND is_archived = 0
             ORDER BY created_at DESC",
            $user_id
        ), ARRAY_A);

        return $this->success([
            'filename' => 'peanut-utms-' . date('Y-m-d') . '.csv',
            'data' => $items,
            'headers' => ['Name', 'Base URL', 'Source', 'Medium', 'Campaign', 'Term', 'Content', 'Full URL', 'Clicks', 'Created'],
        ]);
    }

    /**
     * Prepare item for response
     */
    private function prepare_item(array $item): array {
        return [
            'id' => (int) $item['id'],
            'name' => $item['name'],
            'base_url' => $item['base_url'],
            'utm_source' => $item['utm_source'],
            'utm_medium' => $item['utm_medium'],
            'utm_campaign' => $item['utm_campaign'],
            'utm_term' => $item['utm_term'],
            'utm_content' => $item['utm_content'],
            'full_url' => $item['full_url'],
            'click_count' => (int) $item['click_count'],
            'notes' => $item['notes'],
            'is_archived' => (bool) $item['is_archived'],
            'created_at' => $item['created_at'],
            'updated_at' => $item['updated_at'],
        ];
    }

    /**
     * Collection args
     */
    private function get_collection_args(): array {
        return [
            'page' => ['type' => 'integer', 'default' => 1],
            'per_page' => ['type' => 'integer', 'default' => 20],
            'search' => ['type' => 'string'],
            'utm_source' => ['type' => 'string'],
            'utm_medium' => ['type' => 'string'],
            'utm_campaign' => ['type' => 'string'],
            'archived' => ['type' => 'string'],
            'sort_by' => ['type' => 'string', 'default' => 'created_at'],
            'sort_order' => ['type' => 'string', 'default' => 'DESC'],
        ];
    }

    // ===========================
    // UTM Access Methods
    // ===========================

    /**
     * Get users with access to a UTM
     */
    public function get_utm_access(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $utm_id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        // Check if user is admin
        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        if (!$account || !Peanut_Account_Service::user_has_role($account['id'], $user_id, 'admin')) {
            return $this->error(__('Admin access required', 'peanut-suite'), 'forbidden', 403);
        }

        // Verify UTM belongs to account
        global $wpdb;
        $table = Peanut_Database::utms_table();
        $utm = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND account_id = %d",
            $utm_id,
            $account['id']
        ), ARRAY_A);

        if (!$utm) {
            return $this->not_found(__('UTM not found', 'peanut-suite'));
        }

        $access = Peanut_UTM_Access_Service::get_utm_access_users($utm_id);

        return $this->success($access);
    }

    /**
     * Assign users to a UTM
     */
    public function assign_utm_access(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $utm_id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        // Check if user is admin
        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        if (!$account || !Peanut_Account_Service::user_has_role($account['id'], $user_id, 'admin')) {
            return $this->error(__('Admin access required', 'peanut-suite'), 'forbidden', 403);
        }

        // Verify UTM belongs to account
        global $wpdb;
        $table = Peanut_Database::utms_table();
        $utm = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND (account_id = %d OR user_id = %d)",
            $utm_id,
            $account['id'],
            $user_id
        ), ARRAY_A);

        if (!$utm) {
            return $this->not_found(__('UTM not found', 'peanut-suite'));
        }

        // Update UTM account_id if not set
        if (empty($utm['account_id'])) {
            $wpdb->update($table, ['account_id' => $account['id']], ['id' => $utm_id]);
        }

        $user_ids = $request->get_param('user_ids');
        $access_level = sanitize_key($request->get_param('access_level')) ?: 'view';

        if (empty($user_ids) || !is_array($user_ids)) {
            return $this->error(__('No users specified', 'peanut-suite'));
        }

        $user_ids = array_map('absint', $user_ids);

        // Verify all users are members of the account
        $members = Peanut_Account_Service::get_members($account['id']);
        $member_ids = array_column($members, 'user_id');
        $invalid_users = array_diff($user_ids, $member_ids);

        if (!empty($invalid_users)) {
            return $this->error(__('Some users are not members of this account', 'peanut-suite'));
        }

        $result = Peanut_UTM_Access_Service::bulk_assign(
            [$utm_id],
            $user_ids,
            $account['id'],
            $access_level,
            $user_id
        );

        if (!$result) {
            return $this->error(__('Failed to assign access', 'peanut-suite'), 'assign_failed', 500);
        }

        $access = Peanut_UTM_Access_Service::get_utm_access_users($utm_id);

        return $this->success($access);
    }

    /**
     * Revoke user access to a UTM
     */
    public function revoke_utm_access(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $utm_id = (int) $request->get_param('id');
        $target_user_id = (int) $request->get_param('user_id');
        $user_id = get_current_user_id();

        // Check if user is admin
        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        if (!$account || !Peanut_Account_Service::user_has_role($account['id'], $user_id, 'admin')) {
            return $this->error(__('Admin access required', 'peanut-suite'), 'forbidden', 403);
        }

        // Verify UTM belongs to account
        global $wpdb;
        $table = Peanut_Database::utms_table();
        $utm = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND account_id = %d",
            $utm_id,
            $account['id']
        ), ARRAY_A);

        if (!$utm) {
            return $this->not_found(__('UTM not found', 'peanut-suite'));
        }

        Peanut_UTM_Access_Service::revoke_access($utm_id, $target_user_id);

        $access = Peanut_UTM_Access_Service::get_utm_access_users($utm_id);

        return $this->success($access);
    }
}
