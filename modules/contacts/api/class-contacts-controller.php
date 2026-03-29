<?php
/**
 * Contacts REST Controller
 */

if (!defined('ABSPATH')) {
    exit;
}

class Contacts_Controller extends Peanut_REST_Controller {

    protected string $rest_base = 'contacts';

    public function register_routes(): void {
        // List contacts
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_items'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Create contact
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Get contact
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Update contact
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Delete contact
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_item'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Get contact activities
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/activities', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_activities'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Add activity
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/activities', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'add_activity'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        // Export
        register_rest_route($this->namespace, '/' . $this->rest_base . '/export', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'export'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);
    }

    /**
     * Get contacts
     */
    public function get_items(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Peanut_Database::contacts_table();
        $user_id = get_current_user_id();

        $pagination = $this->get_pagination($request);
        $sort = $this->get_sort($request, ['created_at', 'email', 'last_name', 'score', 'last_activity_at']);

        // Build query
        $where = ['user_id = %d'];
        $params = [$user_id];

        // Status filter
        $status = $request->get_param('status');
        if (!empty($status)) {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        // Search
        $search = $request->get_param('search');
        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = '(email LIKE %s OR first_name LIKE %s OR last_name LIKE %s OR company LIKE %s)';
            $params = array_merge($params, [$like, $like, $like, $like]);
        }

        // UTM filters
        foreach (['utm_source', 'utm_campaign'] as $filter) {
            $value = $request->get_param($filter);
            if (!empty($value)) {
                $where[] = "$filter = %s";
                $params[] = $value;
            }
        }

        $where_sql = implode(' AND ', $where);

        // Count
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE $where_sql",
            ...$params
        ));

        // Get items
        $offset = ($pagination['page'] - 1) * $pagination['per_page'];
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE $where_sql
             ORDER BY {$sort['orderby']} {$sort['order']}
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
     * Get single contact
     */
    public function get_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::contacts_table();

        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND user_id = %d",
            $request->get_param('id'),
            get_current_user_id()
        ), ARRAY_A);

        if (!$item) {
            return $this->not_found(__('Contact not found', 'peanut-suite'));
        }

        return $this->success($this->prepare_item($item, true));
    }

    /**
     * Create contact
     */
    public function create_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::contacts_table();
        $user_id = get_current_user_id();

        // Check limit
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d",
            $user_id
        ));

        if (!$this->check_limit('contacts', $count)) {
            return $this->error(
                __('You have reached your contact limit. Upgrade to add more.', 'peanut-suite'),
                'limit_reached',
                403
            );
        }

        $email = sanitize_email($request->get_param('email'));
        if (empty($email)) {
            return $this->error(__('Email is required', 'peanut-suite'));
        }

        // Check duplicate
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE user_id = %d AND email = %s",
            $user_id,
            $email
        ));

        if ($exists) {
            return $this->error(__('A contact with this email already exists', 'peanut-suite'));
        }

        $data = [
            'user_id' => $user_id,
            'email' => $email,
            'first_name' => sanitize_text_field($request->get_param('first_name')),
            'last_name' => sanitize_text_field($request->get_param('last_name')),
            'phone' => sanitize_text_field($request->get_param('phone')),
            'company' => sanitize_text_field($request->get_param('company')),
            'status' => sanitize_text_field($request->get_param('status') ?: 'lead'),
            'source' => sanitize_text_field($request->get_param('source') ?: 'manual'),
            'utm_source' => sanitize_text_field($request->get_param('utm_source')),
            'utm_medium' => sanitize_text_field($request->get_param('utm_medium')),
            'utm_campaign' => sanitize_text_field($request->get_param('utm_campaign')),
            'notes' => sanitize_textarea_field($request->get_param('notes')),
            'last_activity_at' => current_time('mysql'),
        ];

        // Custom fields
        $custom = $request->get_param('custom_fields');
        if (isset($custom)) {
            if (is_string($custom)) {
                $decoded = json_decode($custom, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->error('Invalid JSON in custom_fields', 400);
                }
                $data['custom_fields'] = wp_json_encode($decoded);
            } elseif (is_array($custom)) {
                $data['custom_fields'] = wp_json_encode(Peanut_Security::sanitize_fields($custom));
            }
        }

        // Calculate score
        $data['score'] = Contacts_Module::calculate_score($data);

        $wpdb->insert($table, $data);
        $id = $wpdb->insert_id;

        if (!$id) {
            return $this->error(__('Failed to create contact', 'peanut-suite'), 'create_failed', 500);
        }

        // Add creation activity
        $contacts_module = new Contacts_Module();
        $contacts_module->add_activity($id, 'created', 'Contact created manually');

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);

        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        Peanut_Audit_Log_Service::log($account ? $account['id'] : 0, Peanut_Audit_Log_Service::ACTION_CREATE, Peanut_Audit_Log_Service::RESOURCE_CONTACT, $id);

        return $this->success($this->prepare_item($item));
    }

    /**
     * Update contact
     */
    public function update_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::contacts_table();
        $id = $request->get_param('id');
        $user_id = get_current_user_id();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));

        if (!$exists) {
            return $this->not_found(__('Contact not found', 'peanut-suite'));
        }

        $data = [];
        $fields = ['first_name', 'last_name', 'phone', 'company', 'status', 'notes'];

        foreach ($fields as $field) {
            $value = $request->get_param($field);
            if ($value !== null) {
                $data[$field] = $field === 'notes'
                    ? sanitize_textarea_field($value)
                    : sanitize_text_field($value);
            }
        }

        // Custom fields
        $custom = $request->get_param('custom_fields');
        if (isset($custom)) {
            if (is_string($custom)) {
                $decoded = json_decode($custom, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return $this->error('Invalid JSON in custom_fields', 400);
                }
                $data['custom_fields'] = wp_json_encode($decoded);
            } elseif (is_array($custom)) {
                $data['custom_fields'] = wp_json_encode(Peanut_Security::sanitize_fields($custom));
            }
        }

        if (empty($data)) {
            return $this->error(__('No data to update', 'peanut-suite'));
        }

        $data['last_activity_at'] = current_time('mysql');

        // Recalculate score
        $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);
        $merged = array_merge($current, $data);
        $data['score'] = Contacts_Module::calculate_score($merged);

        $wpdb->update($table, $data, ['id' => $id]);

        // Track status change
        if (isset($data['status']) && $data['status'] !== $current['status']) {
            $contacts_module = new Contacts_Module();
            $contacts_module->add_activity($id, 'status_change', "Status changed to {$data['status']}");
        }

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id), ARRAY_A);

        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        Peanut_Audit_Log_Service::log($account ? $account['id'] : 0, Peanut_Audit_Log_Service::ACTION_UPDATE, Peanut_Audit_Log_Service::RESOURCE_CONTACT, (int) $id);

        return $this->success($this->prepare_item($item));
    }

    /**
     * Delete contact
     */
    public function delete_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $table = Peanut_Database::contacts_table();
        $activities_table = Peanut_Database::contact_activities_table();
        $id = $request->get_param('id');
        $user_id = get_current_user_id();

        // Delete activities
        $wpdb->query($wpdb->prepare(
            "DELETE a FROM $activities_table a
             JOIN $table c ON a.contact_id = c.id
             WHERE c.id = %d AND c.user_id = %d",
            $id,
            $user_id
        ));

        // Delete contact
        $deleted = $wpdb->delete($table, ['id' => $id, 'user_id' => $user_id]);

        if (!$deleted) {
            return $this->not_found(__('Contact not found', 'peanut-suite'));
        }

        $account = Peanut_Account_Service::get_or_create_for_user($user_id);
        Peanut_Audit_Log_Service::log($account ? $account['id'] : 0, Peanut_Audit_Log_Service::ACTION_DELETE, Peanut_Audit_Log_Service::RESOURCE_CONTACT, (int) $id);

        return $this->success(['message' => __('Contact deleted', 'peanut-suite')]);
    }

    /**
     * Get contact activities
     */
    public function get_activities(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $contacts_table = Peanut_Database::contacts_table();
        $activities_table = Peanut_Database::contact_activities_table();
        $id = $request->get_param('id');
        $user_id = get_current_user_id();

        // Verify ownership
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $contacts_table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));

        if (!$exists) {
            return $this->not_found(__('Contact not found', 'peanut-suite'));
        }

        $activities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $activities_table
             WHERE contact_id = %d
             ORDER BY created_at DESC
             LIMIT 50",
            $id
        ), ARRAY_A);

        return $this->success([
            'activities' => array_map(function ($a) {
                return [
                    'id' => (int) $a['id'],
                    'type' => $a['type'],
                    'description' => $a['description'],
                    'metadata' => $a['metadata'] ? json_decode($a['metadata'], true) : null,
                    'created_at' => $a['created_at'],
                ];
            }, $activities),
        ]);
    }

    /**
     * Add activity
     */
    public function add_activity(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $contacts_table = Peanut_Database::contacts_table();
        $id = $request->get_param('id');
        $user_id = get_current_user_id();

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $contacts_table WHERE id = %d AND user_id = %d",
            $id,
            $user_id
        ));

        if (!$exists) {
            return $this->not_found(__('Contact not found', 'peanut-suite'));
        }

        $type = sanitize_text_field($request->get_param('type') ?: 'note');
        $description = sanitize_textarea_field($request->get_param('description'));

        if (empty($description)) {
            return $this->error(__('Description is required', 'peanut-suite'));
        }

        $contacts_module = new Contacts_Module();
        $contacts_module->add_activity($id, $type, $description);

        // Update last activity
        $wpdb->update($contacts_table, [
            'last_activity_at' => current_time('mysql'),
        ], ['id' => $id]);

        return $this->success(['message' => __('Activity added', 'peanut-suite')]);
    }

    /**
     * Export contacts
     */
    public function export(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $table = Peanut_Database::contacts_table();
        $user_id = get_current_user_id();

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT email, first_name, last_name, phone, company, status, source,
                    utm_source, utm_medium, utm_campaign, score, created_at
             FROM $table WHERE user_id = %d
             ORDER BY created_at DESC",
            $user_id
        ), ARRAY_A);

        return $this->success([
            'filename' => 'peanut-contacts-' . date('Y-m-d') . '.csv',
            'data' => $items,
            'headers' => ['Email', 'First Name', 'Last Name', 'Phone', 'Company', 'Status', 'Source', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'Score', 'Created'],
        ]);
    }

    /**
     * Prepare item for response
     */
    private function prepare_item(array $item, bool $full = false): array {
        $prepared = [
            'id' => (int) $item['id'],
            'email' => $item['email'],
            'first_name' => $item['first_name'],
            'last_name' => $item['last_name'],
            'full_name' => trim("{$item['first_name']} {$item['last_name']}"),
            'phone' => $item['phone'],
            'company' => $item['company'],
            'status' => $item['status'],
            'status_label' => Contacts_Module::STATUSES[$item['status']] ?? $item['status'],
            'source' => $item['source'],
            'score' => (int) $item['score'],
            'last_activity_at' => $item['last_activity_at'],
            'created_at' => $item['created_at'],
        ];

        if ($full) {
            $prepared['utm_source'] = $item['utm_source'];
            $prepared['utm_medium'] = $item['utm_medium'];
            $prepared['utm_campaign'] = $item['utm_campaign'];
            $prepared['notes'] = $item['notes'];
            $prepared['custom_fields'] = $item['custom_fields'] ? json_decode($item['custom_fields'], true) : [];
        }

        return $prepared;
    }
}
