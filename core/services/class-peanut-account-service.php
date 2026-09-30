<?php
/**
 * Account Service
 *
 * Handles account and team member management for multi-tenancy.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Account_Service {

    /**
     * Account statuses
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Member roles
     */
    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';
    public const ROLE_VIEWER = 'viewer';

    /**
     * Role hierarchy (higher number = more permissions)
     */
    private const ROLE_LEVELS = [
        self::ROLE_VIEWER => 1,
        self::ROLE_MEMBER => 2,
        self::ROLE_ADMIN => 3,
        self::ROLE_OWNER => 4,
    ];

    /**
     * Max users per tier
     */
    private const TIER_MAX_USERS = [
        'free' => 3,
        'pro' => 10,
        'agency' => 50,
    ];

    /**
     * Available features with their tier requirements
     */
    public const FEATURES = [
        'utm' => ['name' => 'UTM (Builder + Library)', 'tier' => 'free'],
        'links' => ['name' => 'Links', 'tier' => 'free'],
        'contacts' => ['name' => 'Contacts', 'tier' => 'free'],
        'webhooks' => ['name' => 'Webhooks', 'tier' => 'free'],
        'visitors' => ['name' => 'Visitors', 'tier' => 'pro'],
        'attribution' => ['name' => 'Attribution', 'tier' => 'pro'],
        'analytics' => ['name' => 'Analytics', 'tier' => 'pro'],
        'popups' => ['name' => 'Popups', 'tier' => 'pro'],
        'monitor' => ['name' => 'Monitor', 'tier' => 'agency'],
    ];

    /**
     * Get or create account for user
     */
    public static function get_or_create_for_user(int $user_id): ?array {
        global $wpdb;
        $table = Peanut_Database::accounts_table();
        $members_table = Peanut_Database::account_members_table();

        // First, check if user has any account membership
        $account_id = $wpdb->get_var($wpdb->prepare(
            "SELECT account_id FROM $members_table WHERE user_id = %d LIMIT 1",
            $user_id
        ));

        if ($account_id) {
            return self::get_by_id((int) $account_id);
        }

        // Check if user owns any account
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE owner_user_id = %d LIMIT 1",
            $user_id
        ), ARRAY_A);

        if ($account) {
            // Ensure owner is in members table (fix for accounts created before members table)
            $is_member = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $members_table WHERE account_id = %d AND user_id = %d",
                $account['id'],
                $user_id
            ));

            if (!$is_member) {
                $wpdb->insert($members_table, [
                    'account_id' => $account['id'],
                    'user_id' => $user_id,
                    'role' => self::ROLE_OWNER,
                    'accepted_at' => current_time('mysql'),
                ]);
            }

            return self::prepare_account($account);
        }

        // Only a site administrator gets a default account auto-created.
        // Everyone else must be added to an existing account by a team admin:
        // auto-creating an ACTIVE owned account for any logged-in user (a
        // Subscriber hitting /accounts/current) let them self-grant
        // peanut_access and the Suite app.
        if (!user_can($user_id, 'manage_options')) {
            return null;
        }

        // Create a default account for the user
        $user = get_userdata($user_id);
        if (!$user) {
            return null;
        }

        $name = $user->display_name ?: $user->user_login;
        $slug = sanitize_title($name . '-' . $user_id);

        $tier = 'free';
        $wpdb->insert($table, [
            'name' => $name . "'s Account",
            'slug' => $slug,
            'status' => self::STATUS_ACTIVE,
            'tier' => $tier,
            'max_users' => self::TIER_MAX_USERS[$tier] ?? 3,
            'owner_user_id' => $user_id,
        ]);

        $account_id = $wpdb->insert_id;
        if (!$account_id) {
            return null;
        }

        // Add owner as member
        $wpdb->insert($members_table, [
            'account_id' => $account_id,
            'user_id' => $user_id,
            'role' => self::ROLE_OWNER,
            'accepted_at' => current_time('mysql'),
        ]);

        return self::get_by_id($account_id);
    }

    /**
     * Get user's primary account (first account they belong to)
     */
    public static function get_user_account(int $user_id): ?array {
        global $wpdb;
        $table = Peanut_Database::accounts_table();
        $members_table = Peanut_Database::account_members_table();

        // Get the first account the user belongs to
        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT a.*, m.role
             FROM $table a
             JOIN $members_table m ON a.id = m.account_id
             WHERE m.user_id = %d AND a.status = %s
             ORDER BY a.id ASC
             LIMIT 1",
            $user_id,
            self::STATUS_ACTIVE
        ), ARRAY_A);

        return $account ? self::prepare_account($account) : null;
    }

    /**
     * Whether a (non-administrator) user may use the Peanut Suite app as a
     * team member, i.e. be granted the `peanut_access` capability.
     *
     * Requires a membership in an ACTIVE account that:
     *  - someone else granted (invited_by set and not the user themselves),
     *    so an account a user created for themselves never counts; and
     *  - is anchored to a site administrator: its owner has manage_options,
     *    or its owner was themselves invited by someone else (ownership
     *    transferred to an invited member). This stops a user who owns a
     *    self-created account (created before auto-creation was restricted)
     *    from inviting a second account of their own into it.
     */
    public static function has_team_access(int $user_id): bool {
        global $wpdb;
        $table = Peanut_Database::accounts_table();
        $members_table = Peanut_Database::account_members_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.role, m.invited_by, a.owner_user_id, o.invited_by AS owner_invited_by
             FROM $table a
             JOIN $members_table m ON a.id = m.account_id
             LEFT JOIN $members_table o ON o.account_id = a.id AND o.user_id = a.owner_user_id
             WHERE m.user_id = %d AND a.status = %s",
            $user_id,
            self::STATUS_ACTIVE
        ), ARRAY_A);

        foreach ((array) $rows as $row) {
            if (self::membership_grants_access($row, $user_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pure rule behind has_team_access() for one membership row.
     *
     * @param array $row Keys: invited_by, owner_user_id, owner_invited_by.
     */
    public static function membership_grants_access(array $row, int $user_id): bool {
        $invited_by = (int) ($row['invited_by'] ?? 0);
        if ($invited_by <= 0 || $invited_by === $user_id) {
            return false;
        }

        $owner_id = (int) ($row['owner_user_id'] ?? 0);
        if ($owner_id <= 0) {
            return false;
        }

        if (user_can($owner_id, 'manage_options')) {
            return true;
        }

        $owner_invited_by = (int) ($row['owner_invited_by'] ?? 0);

        return $owner_invited_by > 0 && $owner_invited_by !== $owner_id;
    }

    /**
     * Get account by ID
     */
    public static function get_by_id(int $id): ?array {
        global $wpdb;
        $table = Peanut_Database::accounts_table();

        $account = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);

        return $account ? self::prepare_account($account) : null;
    }

    /**
     * Get all accounts for user
     */
    public static function get_accounts_for_user(int $user_id): array {
        global $wpdb;
        $table = Peanut_Database::accounts_table();
        $members_table = Peanut_Database::account_members_table();

        $accounts = $wpdb->get_results($wpdb->prepare(
            "SELECT a.*, m.role
             FROM $table a
             JOIN $members_table m ON a.id = m.account_id
             WHERE m.user_id = %d AND a.status = %s
             ORDER BY a.name ASC",
            $user_id,
            self::STATUS_ACTIVE
        ), ARRAY_A);

        return array_map([self::class, 'prepare_account'], $accounts);
    }

    /**
     * Update account
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;
        $table = Peanut_Database::accounts_table();

        $allowed = ['name', 'settings'];
        $update_data = [];

        foreach ($allowed as $field) {
            if (isset($data[$field])) {
                if ($field === 'settings') {
                    $update_data[$field] = wp_json_encode($data[$field]);
                } else {
                    $update_data[$field] = sanitize_text_field($data[$field]);
                }
            }
        }

        if (empty($update_data)) {
            return false;
        }

        return (bool) $wpdb->update($table, $update_data, ['id' => $id]);
    }

    /**
     * Get account stats
     */
    public static function get_stats(int $account_id): array {
        global $wpdb;
        $members_table = Peanut_Database::account_members_table();
        $api_keys_table = Peanut_Database::api_keys_table();

        $member_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $members_table WHERE account_id = %d",
            $account_id
        ));

        $active_keys = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $api_keys_table
             WHERE account_id = %d AND revoked_at IS NULL",
            $account_id
        ));

        return [
            'member_count' => $member_count,
            'active_api_keys' => $active_keys,
        ];
    }

    /**
     * Check if user has role in account
     */
    public static function user_has_role(int $account_id, int $user_id, string $minimum_role): bool {
        global $wpdb;
        $members_table = Peanut_Database::account_members_table();

        $role = $wpdb->get_var($wpdb->prepare(
            "SELECT role FROM $members_table
             WHERE account_id = %d AND user_id = %d",
            $account_id,
            $user_id
        ));

        if (!$role) {
            return false;
        }

        $user_level = self::ROLE_LEVELS[$role] ?? 0;
        $required_level = self::ROLE_LEVELS[$minimum_role] ?? 0;

        return $user_level >= $required_level;
    }

    /**
     * Get user role in account
     */
    public static function get_user_role(int $account_id, int $user_id): ?string {
        global $wpdb;
        $members_table = Peanut_Database::account_members_table();

        return $wpdb->get_var($wpdb->prepare(
            "SELECT role FROM $members_table
             WHERE account_id = %d AND user_id = %d",
            $account_id,
            $user_id
        ));
    }

    /**
     * Get account members
     */
    public static function get_members(int $account_id): array {
        global $wpdb;
        $members_table = Peanut_Database::account_members_table();

        $members = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.user_login, u.user_email, u.display_name
             FROM $members_table m
             JOIN {$wpdb->users} u ON m.user_id = u.ID
             WHERE m.account_id = %d
             ORDER BY
                CASE m.role
                    WHEN 'owner' THEN 1
                    WHEN 'admin' THEN 2
                    WHEN 'member' THEN 3
                    ELSE 4
                END,
                m.created_at ASC",
            $account_id
        ), ARRAY_A);

        return array_map(function ($member) {
            $permissions = null;
            if (!empty($member['feature_permissions'])) {
                $permissions = json_decode($member['feature_permissions'], true);
            }

            return [
                'user_id' => (int) $member['user_id'],
                'user_login' => $member['user_login'],
                'user_email' => $member['user_email'],
                'display_name' => $member['display_name'],
                'role' => $member['role'],
                'feature_permissions' => $permissions,
                'invited_at' => $member['invited_at'],
                'accepted_at' => $member['accepted_at'],
                'created_at' => $member['created_at'],
            ];
        }, $members);
    }

    /**
     * Get a single member from account
     */
    public static function get_member(int $account_id, int $user_id): ?array {
        global $wpdb;
        $members_table = Peanut_Database::account_members_table();

        $member = $wpdb->get_row($wpdb->prepare(
            "SELECT m.*, u.user_login, u.user_email, u.display_name
             FROM $members_table m
             JOIN {$wpdb->users} u ON m.user_id = u.ID
             WHERE m.account_id = %d AND m.user_id = %d",
            $account_id,
            $user_id
        ), ARRAY_A);

        if (!$member) {
            return null;
        }

        $permissions = null;
        if (!empty($member['feature_permissions'])) {
            $permissions = json_decode($member['feature_permissions'], true);
        }

        return [
            'user_id' => (int) $member['user_id'],
            'user_login' => $member['user_login'],
            'user_email' => $member['user_email'],
            'display_name' => $member['display_name'],
            'role' => $member['role'],
            'feature_permissions' => $permissions,
            'invited_at' => $member['invited_at'],
            'accepted_at' => $member['accepted_at'],
            'created_at' => $member['created_at'],
        ];
    }

    /**
     * Add member to account
     */
    public static function add_member(
        int $account_id,
        int $user_id,
        string $role,
        int $invited_by,
        ?array $feature_permissions = null
    ): bool {
        global $wpdb;
        $members_table = Peanut_Database::account_members_table();

        // Validate role
        if (!in_array($role, [self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_VIEWER], true)) {
            return false;
        }

        // Check if already a member
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $members_table WHERE account_id = %d AND user_id = %d",
            $account_id,
            $user_id
        ));

        if ($exists) {
            return false;
        }

        // Check max users limit
        $account = self::get_by_id($account_id);
        if (!$account) {
            return false;
        }

        $current_count = count(self::get_members($account_id));
        if ($current_count >= $account['max_users']) {
            return false;
        }

        // Use provided permissions or get defaults for role
        $permissions = $feature_permissions ?? self::get_default_permissions_for_role($role);

        $result = $wpdb->insert($members_table, [
            'account_id' => $account_id,
            'user_id' => $user_id,
            'role' => $role,
            'feature_permissions' => wp_json_encode($permissions),
            'invited_by' => $invited_by,
            'invited_at' => current_time('mysql'),
            'accepted_at' => current_time('mysql'), // Auto-accept for now
        ]);

        return (bool) $result;
    }

    /**
     * Update member role
     */
    public static function update_member_role(int $account_id, int $user_id, string $new_role): bool {
        global $wpdb;
        $members_table = Peanut_Database::account_members_table();

        // Cannot change owner role directly
        $current_role = self::get_user_role($account_id, $user_id);
        if ($current_role === self::ROLE_OWNER) {
            return false;
        }

        // Validate new role
        if (!in_array($new_role, [self::ROLE_ADMIN, self::ROLE_MEMBER, self::ROLE_VIEWER], true)) {
            return false;
        }

        return (bool) $wpdb->update(
            $members_table,
            ['role' => $new_role],
            ['account_id' => $account_id, 'user_id' => $user_id]
        );
    }

    /**
     * Remove member from account
     */
    public static function remove_member(int $account_id, int $user_id): bool {
        global $wpdb;
        $members_table = Peanut_Database::account_members_table();

        // Cannot remove owner
        $role = self::get_user_role($account_id, $user_id);
        if ($role === self::ROLE_OWNER) {
            return false;
        }

        return (bool) $wpdb->delete($members_table, [
            'account_id' => $account_id,
            'user_id' => $user_id,
        ]);
    }

    /**
     * Transfer ownership
     */
    public static function transfer_ownership(int $account_id, int $current_owner_id, int $new_owner_id): bool {
        global $wpdb;
        $table = Peanut_Database::accounts_table();
        $members_table = Peanut_Database::account_members_table();

        // Verify current owner
        $account = self::get_by_id($account_id);
        if (!$account || (int) $account['owner_user_id'] !== $current_owner_id) {
            return false;
        }

        // Verify new owner is a member
        $new_owner_role = self::get_user_role($account_id, $new_owner_id);
        if (!$new_owner_role) {
            return false;
        }

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Update account owner
            $wpdb->update($table, ['owner_user_id' => $new_owner_id], ['id' => $account_id]);

            // Update roles
            $wpdb->update(
                $members_table,
                ['role' => self::ROLE_ADMIN],
                ['account_id' => $account_id, 'user_id' => $current_owner_id]
            );

            $wpdb->update(
                $members_table,
                ['role' => self::ROLE_OWNER],
                ['account_id' => $account_id, 'user_id' => $new_owner_id]
            );

            $wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return false;
        }
    }

    /**
     * Prepare account for response
     */
    private static function prepare_account(array $account): array {
        return [
            'id' => (int) $account['id'],
            'name' => $account['name'],
            'slug' => $account['slug'],
            'status' => $account['status'],
            'tier' => $account['tier'],
            'max_users' => (int) $account['max_users'],
            'owner_user_id' => (int) $account['owner_user_id'],
            'role' => $account['role'] ?? null,
            'settings' => $account['settings'] ? json_decode($account['settings'], true) : [],
            'created_at' => $account['created_at'],
            'updated_at' => $account['updated_at'],
        ];
    }

    /**
     * Get member's feature permissions
     */
    public static function get_member_permissions(int $account_id, int $user_id): ?array {
        global $wpdb;
        $members_table = Peanut_Database::account_members_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT role, feature_permissions FROM $members_table
             WHERE account_id = %d AND user_id = %d",
            $account_id,
            $user_id
        ), ARRAY_A);

        if (!$row) {
            return null;
        }

        // Owners and admins always have full access
        if (in_array($row['role'], [self::ROLE_OWNER, self::ROLE_ADMIN], true)) {
            return self::get_default_permissions_for_role(self::ROLE_OWNER);
        }

        if (!empty($row['feature_permissions'])) {
            return json_decode($row['feature_permissions'], true);
        }

        return self::get_default_permissions_for_role($row['role']);
    }

    /**
     * Update member's feature permissions
     */
    public static function update_member_permissions(int $account_id, int $user_id, array $permissions): bool {
        global $wpdb;
        $members_table = Peanut_Database::account_members_table();

        // Cannot update owner permissions
        $role = self::get_user_role($account_id, $user_id);
        if ($role === self::ROLE_OWNER) {
            return false;
        }

        return (bool) $wpdb->update(
            $members_table,
            ['feature_permissions' => wp_json_encode($permissions)],
            ['account_id' => $account_id, 'user_id' => $user_id]
        );
    }

    /**
     * Check if user has access to a specific feature
     */
    public static function user_can_access_feature(int $account_id, int $user_id, string $feature): bool {
        $permissions = self::get_member_permissions($account_id, $user_id);

        if (!$permissions) {
            return false;
        }

        return !empty($permissions[$feature]['access']);
    }

    /**
     * Get default permissions for a role
     */
    public static function get_default_permissions_for_role(string $role): array {
        $all_access = [];
        $free_access = [];

        foreach (self::FEATURES as $key => $config) {
            $all_access[$key] = ['access' => true];

            // Free tier features for members/viewers
            if ($config['tier'] === 'free') {
                $free_access[$key] = ['access' => true];
            } else {
                $free_access[$key] = ['access' => false];
            }
        }

        switch ($role) {
            case self::ROLE_OWNER:
            case self::ROLE_ADMIN:
                return $all_access;

            case self::ROLE_MEMBER:
            case self::ROLE_VIEWER:
            default:
                return $free_access;
        }
    }

    /**
     * Get max users for a tier
     */
    public static function get_max_users_for_tier(string $tier): int {
        return self::TIER_MAX_USERS[$tier] ?? 3;
    }

    /**
     * Update account tier and max_users
     */
    public static function update_tier(int $account_id, string $new_tier): bool {
        global $wpdb;
        $table = Peanut_Database::accounts_table();

        return (bool) $wpdb->update(
            $table,
            [
                'tier' => $new_tier,
                'max_users' => self::get_max_users_for_tier($new_tier),
            ],
            ['id' => $account_id]
        );
    }

    /**
     * Migrate accounts to use tier-based max_users (one-time fix)
     */
    public static function migrate_max_users(): void {
        global $wpdb;
        $table = Peanut_Database::accounts_table();

        // Update accounts where max_users = 1 to use tier-based values
        foreach (self::TIER_MAX_USERS as $tier => $max_users) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET max_users = %d WHERE tier = %s AND max_users < %d",
                $max_users,
                $tier,
                $max_users
            ));
        }
    }

    /**
     * Get available features with tier availability
     */
    public static function get_available_features(string $account_tier): array {
        $tier_levels = ['free' => 0, 'pro' => 1, 'agency' => 2];
        $account_level = $tier_levels[$account_tier] ?? 0;

        $features = [];
        foreach (self::FEATURES as $key => $config) {
            $required_level = $tier_levels[$config['tier']] ?? 0;
            $features[$key] = [
                'name' => $config['name'],
                'tier' => $config['tier'],
                'available' => $account_level >= $required_level,
            ];
        }

        return $features;
    }
}
