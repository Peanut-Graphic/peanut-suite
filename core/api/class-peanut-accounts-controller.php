<?php
/**
 * Accounts REST Controller
 *
 * Handles account, team members, API keys, and audit log endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Accounts_Controller extends Peanut_REST_Controller {

    protected string $rest_base = 'accounts';

    /**
     * Register routes
     */
    public function register_routes(): void {
        // Account routes
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_accounts'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/current', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_current_account'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_account'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_account'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/stats', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_account_stats'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        // Members routes
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<account_id>\d+)/members', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_members'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'add_member'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<account_id>\d+)/members/(?P<user_id>\d+)', [
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_member'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'remove_member'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<account_id>\d+)/transfer-ownership', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'transfer_ownership'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        // API Keys routes
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<account_id>\d+)/api-keys', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_api_keys'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_api_key'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<account_id>\d+)/api-keys/(?P<key_id>\d+)', [
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'revoke_api_key'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<account_id>\d+)/api-keys/(?P<key_id>\d+)/regenerate', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'regenerate_api_key'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/api-keys/scopes', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_api_key_scopes'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        // Audit Log routes
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<account_id>\d+)/audit-log', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_audit_logs'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<account_id>\d+)/audit-log/export', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'export_audit_logs'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/audit-log/filters', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_audit_log_filters'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        // Features routes
        register_rest_route($this->namespace, '/' . $this->rest_base . '/features', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_available_features'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<account_id>\d+)/my-permissions', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_my_permissions'],
                'permission_callback' => [$this, 'permission_callback'],
            ],
        ]);
    }

    // ===============================
    // Account Methods
    // ===============================

    /**
     * Get all accounts for current user
     */
    public function get_accounts(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $accounts = Peanut_Account_Service::get_accounts_for_user($user_id);

        return $this->success($accounts);
    }

    /**
     * Get or create current account
     */
    public function get_current_account(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $account = Peanut_Account_Service::get_or_create_for_user($user_id);

        if (!$account) {
            // Non-administrators are never given an auto-created account;
            // a user nobody added to a team simply has none.
            return $this->not_found('No Peanut account for this user');
        }

        return $this->success($account);
    }

    /**
     * Get single account
     */
    public function get_account(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        if (!$this->user_can_access_account($account_id, $user_id)) {
            return $this->error('Access denied', 'forbidden', 403);
        }

        $account = Peanut_Account_Service::get_by_id($account_id);
        if (!$account) {
            return $this->not_found('Account not found');
        }

        return $this->success($account);
    }

    /**
     * Update account
     */
    public function update_account(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        if (!$this->user_has_account_role($account_id, $user_id, 'admin')) {
            return $this->error('Admin access required', 'forbidden', 403);
        }

        $data = [
            'name' => $request->get_param('name'),
            'settings' => $request->get_param('settings'),
        ];

        $result = Peanut_Account_Service::update($account_id, $data);

        if ($result) {
            Peanut_Audit_Log_Service::log(
                $account_id,
                Peanut_Audit_Log_Service::ACTION_UPDATE,
                Peanut_Audit_Log_Service::RESOURCE_ACCOUNT,
                $account_id,
                ['fields' => array_keys(array_filter($data))]
            );
        }

        $account = Peanut_Account_Service::get_by_id($account_id);
        return $this->success($account);
    }

    /**
     * Get account stats
     */
    public function get_account_stats(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('id');
        $user_id = get_current_user_id();

        if (!$this->user_can_access_account($account_id, $user_id)) {
            return $this->error('Access denied', 'forbidden', 403);
        }

        $stats = Peanut_Account_Service::get_stats($account_id);
        return $this->success($stats);
    }

    // ===============================
    // Members Methods
    // ===============================

    /**
     * Get account members
     */
    public function get_members(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $user_id = get_current_user_id();

        if (!$this->user_can_access_account($account_id, $user_id)) {
            return $this->error('Access denied', 'forbidden', 403);
        }

        $members = Peanut_Account_Service::get_members($account_id);
        return $this->success($members);
    }

    /**
     * Add member to account
     */
    public function add_member(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $current_user_id = get_current_user_id();

        if (!$this->user_has_account_role($account_id, $current_user_id, 'admin')) {
            return $this->error('Admin access required', 'forbidden', 403);
        }

        $email = sanitize_email($request->get_param('email'));
        $role = sanitize_key($request->get_param('role'));
        $permissions = $request->get_param('permissions');

        if (!is_email($email)) {
            return $this->error('Invalid email address', 'invalid_email');
        }

        $user = get_user_by('email', $email);
        if (!$user) {
            return $this->error('User not found. They must have a WordPress account.', 'user_not_found', 404);
        }

        // Validate permissions if provided
        $feature_permissions = null;
        if (!empty($permissions) && is_array($permissions)) {
            $feature_permissions = $this->sanitize_permissions($permissions);
        }

        $result = Peanut_Account_Service::add_member(
            $account_id,
            $user->ID,
            $role,
            $current_user_id,
            $feature_permissions
        );

        if (!$result) {
            return $this->error('Failed to add member. They may already be a member or the team is full.', 'add_failed');
        }

        Peanut_Audit_Log_Service::log(
            $account_id,
            Peanut_Audit_Log_Service::ACTION_INVITE,
            Peanut_Audit_Log_Service::RESOURCE_MEMBER,
            $user->ID,
            ['email' => $email, 'role' => $role, 'permissions' => $feature_permissions]
        );

        $members = Peanut_Account_Service::get_members($account_id);
        return $this->success($members, 201);
    }

    /**
     * Update member role and permissions
     */
    public function update_member(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $target_user_id = (int) $request->get_param('user_id');
        $current_user_id = get_current_user_id();

        if (!$this->user_has_account_role($account_id, $current_user_id, 'admin')) {
            return $this->error('Admin access required', 'forbidden', 403);
        }

        $new_role = sanitize_key($request->get_param('role'));
        $permissions = $request->get_param('permissions');

        // Update role if provided
        if ($new_role) {
            $result = Peanut_Account_Service::update_member_role($account_id, $target_user_id, $new_role);
            if (!$result) {
                return $this->error('Failed to update member role', 'update_failed');
            }
        }

        // Update permissions if provided
        $changes = ['role' => $new_role];
        if (!empty($permissions) && is_array($permissions)) {
            $feature_permissions = $this->sanitize_permissions($permissions);
            Peanut_Account_Service::update_member_permissions($account_id, $target_user_id, $feature_permissions);
            $changes['permissions'] = $feature_permissions;
        }

        Peanut_Audit_Log_Service::log(
            $account_id,
            Peanut_Audit_Log_Service::ACTION_UPDATE,
            Peanut_Audit_Log_Service::RESOURCE_MEMBER,
            $target_user_id,
            $changes
        );

        $members = Peanut_Account_Service::get_members($account_id);
        return $this->success($members);
    }

    /**
     * Remove member from account
     */
    public function remove_member(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $target_user_id = (int) $request->get_param('user_id');
        $current_user_id = get_current_user_id();

        // User can remove themselves or admins can remove others
        $can_remove = $target_user_id === $current_user_id ||
                      $this->user_has_account_role($account_id, $current_user_id, 'admin');

        if (!$can_remove) {
            return $this->error('Permission denied', 'forbidden', 403);
        }

        $result = Peanut_Account_Service::remove_member($account_id, $target_user_id);

        if (!$result) {
            return $this->error('Failed to remove member. Cannot remove account owner.', 'remove_failed');
        }

        Peanut_Audit_Log_Service::log(
            $account_id,
            Peanut_Audit_Log_Service::ACTION_DELETE,
            Peanut_Audit_Log_Service::RESOURCE_MEMBER,
            $target_user_id
        );

        $members = Peanut_Account_Service::get_members($account_id);
        return $this->success($members);
    }

    /**
     * Transfer account ownership
     */
    public function transfer_ownership(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $new_owner_id = (int) $request->get_param('new_owner_id');
        $current_user_id = get_current_user_id();

        if (!$this->user_has_account_role($account_id, $current_user_id, 'owner')) {
            return $this->error('Only the owner can transfer ownership', 'forbidden', 403);
        }

        $result = Peanut_Account_Service::transfer_ownership($account_id, $current_user_id, $new_owner_id);

        if (!$result) {
            return $this->error('Failed to transfer ownership', 'transfer_failed');
        }

        Peanut_Audit_Log_Service::log(
            $account_id,
            Peanut_Audit_Log_Service::ACTION_UPDATE,
            Peanut_Audit_Log_Service::RESOURCE_ACCOUNT,
            $account_id,
            ['action' => 'ownership_transfer', 'new_owner_id' => $new_owner_id]
        );

        $account = Peanut_Account_Service::get_by_id($account_id);
        return $this->success($account);
    }

    // ===============================
    // API Keys Methods
    // ===============================

    /**
     * Get API keys for account
     */
    public function get_api_keys(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $user_id = get_current_user_id();

        if (!$this->user_has_account_role($account_id, $user_id, 'admin')) {
            return $this->error('Admin access required', 'forbidden', 403);
        }

        $include_revoked = (bool) $request->get_param('include_revoked');
        $keys = Peanut_Api_Keys_Service::get_by_account($account_id, $include_revoked);

        return $this->success($keys);
    }

    /**
     * Create new API key
     */
    public function create_api_key(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $user_id = get_current_user_id();

        if (!$this->user_has_account_role($account_id, $user_id, 'admin')) {
            return $this->error('Admin access required', 'forbidden', 403);
        }

        $name = sanitize_text_field($request->get_param('name'));
        $scopes = $request->get_param('scopes') ?: [];
        $expires_at = $request->get_param('expires_at');

        if (empty($name)) {
            return $this->error('Name is required', 'missing_name');
        }

        if (empty($scopes)) {
            return $this->error('At least one scope is required', 'missing_scopes');
        }

        $key = Peanut_Api_Keys_Service::create($account_id, $user_id, $name, $scopes, $expires_at);

        if (!$key) {
            return $this->error('Failed to create API key', 'create_failed');
        }

        Peanut_Audit_Log_Service::log(
            $account_id,
            Peanut_Audit_Log_Service::ACTION_CREATE,
            Peanut_Audit_Log_Service::RESOURCE_API_KEY,
            $key['id'],
            ['name' => $name, 'scopes' => $scopes]
        );

        return $this->success($key, 201);
    }

    /**
     * Revoke API key
     */
    public function revoke_api_key(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $key_id = (int) $request->get_param('key_id');
        $user_id = get_current_user_id();

        if (!$this->user_has_account_role($account_id, $user_id, 'admin')) {
            return $this->error('Admin access required', 'forbidden', 403);
        }

        // Verify key belongs to account
        $key = Peanut_Api_Keys_Service::get_by_id($key_id);
        if (!$key || $key['account_id'] !== $account_id) {
            return $this->not_found('API key not found');
        }

        $result = Peanut_Api_Keys_Service::revoke($key_id, $user_id);

        if (!$result) {
            return $this->error('Failed to revoke API key', 'revoke_failed');
        }

        Peanut_Audit_Log_Service::log(
            $account_id,
            Peanut_Audit_Log_Service::ACTION_REVOKE,
            Peanut_Audit_Log_Service::RESOURCE_API_KEY,
            $key_id,
            ['name' => $key['name']]
        );

        return $this->success(['revoked' => true]);
    }

    /**
     * Regenerate API key
     */
    public function regenerate_api_key(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $key_id = (int) $request->get_param('key_id');
        $user_id = get_current_user_id();

        if (!$this->user_has_account_role($account_id, $user_id, 'admin')) {
            return $this->error('Admin access required', 'forbidden', 403);
        }

        // Verify key belongs to account
        $old_key = Peanut_Api_Keys_Service::get_by_id($key_id);
        if (!$old_key || $old_key['account_id'] !== $account_id) {
            return $this->not_found('API key not found');
        }

        $new_key = Peanut_Api_Keys_Service::regenerate($key_id, $user_id);

        if (!$new_key) {
            return $this->error('Failed to regenerate API key', 'regenerate_failed');
        }

        Peanut_Audit_Log_Service::log(
            $account_id,
            Peanut_Audit_Log_Service::ACTION_CREATE,
            Peanut_Audit_Log_Service::RESOURCE_API_KEY,
            $new_key['id'],
            ['action' => 'regenerate', 'old_key_id' => $key_id]
        );

        return $this->success($new_key, 201);
    }

    /**
     * Get available API key scopes
     */
    public function get_api_key_scopes(WP_REST_Request $request): WP_REST_Response {
        return $this->success(Peanut_Api_Keys_Service::SCOPES);
    }

    // ===============================
    // Audit Log Methods
    // ===============================

    /**
     * Get audit logs
     */
    public function get_audit_logs(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $user_id = get_current_user_id();

        if (!$this->user_has_account_role($account_id, $user_id, 'admin')) {
            return $this->error('Admin access required', 'forbidden', 403);
        }

        $pagination = $this->get_pagination($request);

        $args = [
            'action' => $request->get_param('action'),
            'resource_type' => $request->get_param('resource_type'),
            'user_id' => $request->get_param('user_id') ? (int) $request->get_param('user_id') : null,
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
        ];

        $result = Peanut_Audit_Log_Service::get_logs($account_id, $args);

        return $this->paginated(
            $result['items'],
            $result['total'],
            $result['page'],
            $result['per_page']
        );
    }

    /**
     * Export audit logs
     */
    public function export_audit_logs(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $user_id = get_current_user_id();

        if (!$this->user_has_account_role($account_id, $user_id, 'admin')) {
            return $this->error('Admin access required', 'forbidden', 403);
        }

        $args = [
            'action' => $request->get_param('action'),
            'resource_type' => $request->get_param('resource_type'),
            'user_id' => $request->get_param('user_id') ? (int) $request->get_param('user_id') : null,
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
        ];

        $logs = Peanut_Audit_Log_Service::export($account_id, $args);

        return $this->success($logs);
    }

    /**
     * Get audit log filter options
     */
    public function get_audit_log_filters(WP_REST_Request $request): WP_REST_Response {
        return $this->success([
            'actions' => Peanut_Audit_Log_Service::get_available_actions(),
            'resource_types' => Peanut_Audit_Log_Service::get_available_resource_types(),
        ]);
    }

    // ===============================
    // Feature & Permission Methods
    // ===============================

    /**
     * Get available features for the current account
     */
    public function get_available_features(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $user_id = get_current_user_id();
        $account = Peanut_Account_Service::get_or_create_for_user($user_id);

        if (!$account) {
            return $this->not_found('No Peanut account for this user');
        }

        $features = Peanut_Account_Service::get_available_features($account['tier']);
        return $this->success($features);
    }

    /**
     * Get current user's permissions for an account
     */
    public function get_my_permissions(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $account_id = (int) $request->get_param('account_id');
        $user_id = get_current_user_id();

        if (!$this->user_can_access_account($account_id, $user_id)) {
            return $this->error('Access denied', 'forbidden', 403);
        }

        $role = Peanut_Account_Service::get_user_role($account_id, $user_id);
        $permissions = Peanut_Account_Service::get_member_permissions($account_id, $user_id);
        $account = Peanut_Account_Service::get_by_id($account_id);
        $available_features = Peanut_Account_Service::get_available_features($account['tier'] ?? 'free');

        return $this->success([
            'role' => $role,
            'permissions' => $permissions,
            'available_features' => $available_features,
        ]);
    }

    // ===============================
    // Helper Methods
    // ===============================

    /**
     * Check if user can access account
     */
    private function user_can_access_account(int $account_id, int $user_id): bool {
        return Peanut_Account_Service::get_user_role($account_id, $user_id) !== null;
    }

    /**
     * Check if user has minimum role in account
     */
    private function user_has_account_role(int $account_id, int $user_id, string $minimum_role): bool {
        return Peanut_Account_Service::user_has_role($account_id, $user_id, $minimum_role);
    }

    /**
     * Sanitize permissions array
     */
    private function sanitize_permissions(array $permissions): array {
        $valid_features = array_keys(Peanut_Account_Service::FEATURES);
        $sanitized = [];

        foreach ($permissions as $feature => $config) {
            $feature = sanitize_key($feature);
            if (!in_array($feature, $valid_features, true)) {
                continue;
            }

            $sanitized[$feature] = [
                'access' => !empty($config['access']),
            ];
        }

        return $sanitized;
    }
}
