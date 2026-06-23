<?php
/**
 * Database Manager
 *
 * Handles table creation and database operations for all modules.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Database {

    /**
     * Database version
     */
    private const DB_VERSION = '2.2.0';

    /**
     * Table names
     */
    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . PEANUT_TABLE_PREFIX . $name;
    }

    // Shorthand methods
    public static function utms_table(): string { return self::table('utms'); }
    public static function links_table(): string { return self::table('links'); }
    public static function link_clicks_table(): string { return self::table('link_clicks'); }
    public static function contacts_table(): string { return self::table('contacts'); }
    public static function contact_activities_table(): string { return self::table('contact_activities'); }
    public static function tags_table(): string { return self::table('tags'); }
    public static function taggables_table(): string { return self::table('taggables'); }
    public static function analytics_cache_table(): string { return self::table('analytics_cache'); }

    // Multi-tenancy tables
    public static function accounts_table(): string { return self::table('accounts'); }
    public static function account_members_table(): string { return self::table('account_members'); }
    public static function api_keys_table(): string { return self::table('api_keys'); }
    public static function audit_log_table(): string { return self::table('audit_log'); }
    public static function utm_access_table(): string { return self::table('utm_access'); }

    /**
     * Create all tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // UTMs table
        $sql = "CREATE TABLE " . self::utms_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            account_id bigint(20) UNSIGNED DEFAULT NULL,
            name varchar(255) NOT NULL,
            base_url varchar(2048) NOT NULL,
            utm_source varchar(255) NOT NULL,
            utm_medium varchar(255) NOT NULL,
            utm_campaign varchar(255) NOT NULL,
            utm_term varchar(255) DEFAULT NULL,
            utm_content varchar(255) DEFAULT NULL,
            full_url text NOT NULL,
            click_count bigint(20) UNSIGNED DEFAULT 0,
            notes text DEFAULT NULL,
            is_archived tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY account_id (account_id),
            KEY utm_source (utm_source),
            KEY utm_campaign (utm_campaign),
            KEY created_at (created_at),
            KEY is_archived (is_archived)
        ) $charset;";
        dbDelta($sql);

        // Links table
        $sql = "CREATE TABLE " . self::links_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            slug varchar(50) NOT NULL,
            destination_url varchar(2048) NOT NULL,
            title varchar(255) DEFAULT NULL,
            utm_id bigint(20) UNSIGNED DEFAULT NULL,
            click_count bigint(20) UNSIGNED DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            expires_at datetime DEFAULT NULL,
            password_hash varchar(255) DEFAULT NULL,
            qr_code_path varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY user_id (user_id),
            KEY utm_id (utm_id),
            KEY is_active (is_active)
        ) $charset;";
        dbDelta($sql);

        // Link clicks table
        $sql = "CREATE TABLE " . self::link_clicks_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            link_id bigint(20) UNSIGNED NOT NULL,
            visitor_id varchar(64) DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            referer varchar(2048) DEFAULT NULL,
            country varchar(2) DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            device_type varchar(20) DEFAULT NULL,
            browser varchar(50) DEFAULT NULL,
            os varchar(50) DEFAULT NULL,
            clicked_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY link_id (link_id),
            KEY clicked_at (clicked_at),
            KEY visitor_id (visitor_id)
        ) $charset;";
        dbDelta($sql);

        // Contacts table
        $sql = "CREATE TABLE " . self::contacts_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            email varchar(255) NOT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            company varchar(255) DEFAULT NULL,
            status varchar(50) DEFAULT 'lead',
            source varchar(100) DEFAULT NULL,
            source_detail varchar(255) DEFAULT NULL,
            utm_source varchar(255) DEFAULT NULL,
            utm_medium varchar(255) DEFAULT NULL,
            utm_campaign varchar(255) DEFAULT NULL,
            custom_fields text DEFAULT NULL,
            notes text DEFAULT NULL,
            score int DEFAULT 0,
            last_activity_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY email (email),
            KEY status (status),
            KEY source (source),
            KEY utm_campaign (utm_campaign),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta($sql);

        // Contact activities table
        $sql = "CREATE TABLE " . self::contact_activities_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id bigint(20) UNSIGNED NOT NULL,
            type varchar(50) NOT NULL,
            description text DEFAULT NULL,
            metadata text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY contact_id (contact_id),
            KEY type (type),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta($sql);

        // Tags table (polymorphic)
        $sql = "CREATE TABLE " . self::tags_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            name varchar(100) NOT NULL,
            slug varchar(100) NOT NULL,
            color varchar(7) DEFAULT '#6366f1',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_slug (user_id, slug),
            KEY user_id (user_id)
        ) $charset;";
        dbDelta($sql);

        // Taggables (polymorphic pivot)
        $sql = "CREATE TABLE " . self::taggables_table() . " (
            tag_id bigint(20) UNSIGNED NOT NULL,
            taggable_id bigint(20) UNSIGNED NOT NULL,
            taggable_type varchar(50) NOT NULL,
            PRIMARY KEY (tag_id, taggable_id, taggable_type),
            KEY taggable (taggable_type, taggable_id)
        ) $charset;";
        dbDelta($sql);

        // Analytics cache
        $sql = "CREATE TABLE " . self::analytics_cache_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cache_key varchar(64) NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            module varchar(50) NOT NULL,
            data longtext NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY expires_at (expires_at),
            KEY user_module (user_id, module)
        ) $charset;";
        dbDelta($sql);

        // ===== Multi-tenancy Tables =====

        // Accounts table
        $sql = "CREATE TABLE " . self::accounts_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(255) NOT NULL,
            slug varchar(100) NOT NULL,
            status varchar(20) DEFAULT 'active',
            tier varchar(20) DEFAULT 'free',
            max_users int DEFAULT 1,
            owner_user_id bigint(20) UNSIGNED NOT NULL,
            settings longtext DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY owner_user_id (owner_user_id),
            KEY status (status),
            KEY tier (tier)
        ) $charset;";
        dbDelta($sql);

        // Account members table
        $sql = "CREATE TABLE " . self::account_members_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            role varchar(20) NOT NULL DEFAULT 'member',
            feature_permissions longtext DEFAULT NULL,
            invited_by bigint(20) UNSIGNED DEFAULT NULL,
            invited_at datetime DEFAULT NULL,
            accepted_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY account_user (account_id, user_id),
            KEY account_id (account_id),
            KEY user_id (user_id),
            KEY role (role)
        ) $charset;";
        dbDelta($sql);

        // API keys table
        $sql = "CREATE TABLE " . self::api_keys_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id bigint(20) UNSIGNED NOT NULL,
            key_id varchar(32) NOT NULL,
            key_hash varchar(255) NOT NULL,
            name varchar(255) NOT NULL,
            scopes text NOT NULL,
            created_by bigint(20) UNSIGNED NOT NULL,
            last_used_at datetime DEFAULT NULL,
            last_used_ip varchar(45) DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            revoked_at datetime DEFAULT NULL,
            revoked_by bigint(20) UNSIGNED DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY key_id (key_id),
            KEY account_id (account_id),
            KEY created_by (created_by),
            KEY revoked_at (revoked_at)
        ) $charset;";
        dbDelta($sql);

        // Audit log table
        $sql = "CREATE TABLE " . self::audit_log_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            api_key_id bigint(20) UNSIGNED DEFAULT NULL,
            action varchar(50) NOT NULL,
            resource_type varchar(50) NOT NULL,
            resource_id bigint(20) UNSIGNED DEFAULT NULL,
            details longtext DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            user_agent text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY account_id (account_id),
            KEY user_id (user_id),
            KEY api_key_id (api_key_id),
            KEY action (action),
            KEY resource_type (resource_type),
            KEY created_at (created_at)
        ) $charset;";
        dbDelta($sql);

        // UTM access table (for data segmentation)
        $sql = "CREATE TABLE " . self::utm_access_table() . " (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            utm_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED NOT NULL,
            account_id bigint(20) UNSIGNED NOT NULL,
            access_level varchar(20) DEFAULT 'view',
            assigned_by bigint(20) UNSIGNED NOT NULL,
            assigned_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY utm_user (utm_id, user_id),
            KEY utm_id (utm_id),
            KEY user_id (user_id),
            KEY account_id (account_id)
        ) $charset;";
        dbDelta($sql);

        // Run migrations for existing data
        self::run_migrations();

        update_option('peanut_db_version', self::DB_VERSION);
    }

    /**
     * Drop all tables
     */
    public static function drop_tables(): void {
        global $wpdb;

        $tables = [
            self::utms_table(),
            self::links_table(),
            self::link_clicks_table(),
            self::contacts_table(),
            self::contact_activities_table(),
            self::tags_table(),
            self::taggables_table(),
            self::analytics_cache_table(),
            // Multi-tenancy tables
            self::utm_access_table(),
            self::audit_log_table(),
            self::api_keys_table(),
            self::account_members_table(),
            self::accounts_table(),
        ];

        foreach ($tables as $table) {
            // Table names are safe (from static methods), use query directly
            $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table));
        }

        delete_option('peanut_db_version');
    }

    /**
     * Run data migrations for existing users
     */
    private static function run_migrations(): void {
        global $wpdb;

        $current_version = get_option('peanut_db_version', '0');

        // Migration to 2.1.0: Set up feature permissions and UTM access
        if (version_compare($current_version, '2.1.0', '<')) {
            self::migrate_to_2_1_0();
        }
    }

    /**
     * Migration to 2.1.0
     * - Set default feature permissions for existing members
     * - Set account_id on existing UTMs
     * - Grant UTM access to account owners
     */
    private static function migrate_to_2_1_0(): void {
        global $wpdb;

        $members_table = self::account_members_table();
        $accounts_table = self::accounts_table();
        $utms_table = self::utms_table();
        $utm_access_table = self::utm_access_table();

        // Default permissions by role
        $default_permissions = [
            'owner' => json_encode([
                'utm' => ['access' => true],
                'links' => ['access' => true],
                'contacts' => ['access' => true],
                'webhooks' => ['access' => true],
                'visitors' => ['access' => true],
                'attribution' => ['access' => true],
                'analytics' => ['access' => true],
                'popups' => ['access' => true],
                'monitor' => ['access' => true],
            ]),
            'admin' => json_encode([
                'utm' => ['access' => true],
                'links' => ['access' => true],
                'contacts' => ['access' => true],
                'webhooks' => ['access' => true],
                'visitors' => ['access' => true],
                'attribution' => ['access' => true],
                'analytics' => ['access' => true],
                'popups' => ['access' => true],
                'monitor' => ['access' => true],
            ]),
            'member' => json_encode([
                'utm' => ['access' => true],
                'links' => ['access' => true],
                'contacts' => ['access' => true],
                'webhooks' => ['access' => true],
                'visitors' => ['access' => false],
                'attribution' => ['access' => false],
                'analytics' => ['access' => false],
                'popups' => ['access' => false],
                'monitor' => ['access' => false],
            ]),
            'viewer' => json_encode([
                'utm' => ['access' => true],
                'links' => ['access' => true],
                'contacts' => ['access' => true],
                'webhooks' => ['access' => true],
                'visitors' => ['access' => false],
                'attribution' => ['access' => false],
                'analytics' => ['access' => false],
                'popups' => ['access' => false],
                'monitor' => ['access' => false],
            ]),
        ];

        // Update existing members with default permissions (only if null)
        foreach ($default_permissions as $role => $permissions) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$members_table}
                 SET feature_permissions = %s
                 WHERE role = %s AND feature_permissions IS NULL",
                $permissions,
                $role
            ));
        }

        // Set account_id on existing UTMs based on user's account
        // Table names are safely generated, values are not interpolated in WHERE clause
        $wpdb->query(
            "UPDATE {$utms_table} u
             INNER JOIN {$accounts_table} a ON u.user_id = a.owner_user_id
             SET u.account_id = a.id
             WHERE u.account_id IS NULL"
        );

        // Grant full access to existing UTMs for account owners
        // Table names are safely generated, literal 'full' is hardcoded for access_level
        $wpdb->query(
            "INSERT IGNORE INTO {$utm_access_table} (utm_id, user_id, account_id, access_level, assigned_by)
             SELECT u.id, u.user_id, u.account_id, 'full', u.user_id
             FROM {$utms_table} u
             WHERE u.account_id IS NOT NULL"
        );
    }

    /**
     * Cleanup expired cache
     */
    public static function cleanup_expired_cache(): void {
        global $wpdb;
        $table = self::analytics_cache_table();

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table WHERE expires_at < %s",
                current_time('mysql')
            )
        );
    }

    /**
     * Check if tables need upgrade
     */
    public static function needs_upgrade(): bool {
        $current = get_option('peanut_db_version', '0');
        return version_compare($current, self::DB_VERSION, '<');
    }

    /**
     * The DB schema version this build of the plugin ships.
     */
    public static function current_db_version(): string {
        return self::DB_VERSION;
    }

    /**
     * Self-heal the schema on plugin upgrade.
     *
     * Migrations historically ran ONLY in the activation hook, but the GitHub
     * self-updater swaps files in place and never re-activates — so schema
     * changes shipped in an update never reached existing installs. This is the
     * structural root cause behind the silent write-vs-schema drift bugs.
     *
     * maybe_upgrade() is hooked on plugins_loaded. It is cheap: a single
     * get_option gate. Only when the stored peanut_db_version is older than the
     * version this build ships (or the option is missing) does it re-run the
     * core + module table creators. dbDelta is idempotent and additive, so
     * re-running it on an up-to-date DB is harmless, and re-running it on a
     * drifted one adds the missing columns.
     *
     * Mirrors peanut-connect's check_db_version() self-heal pattern.
     *
     * @param callable|null $migrator Optional override (for tests). When null,
     *                                runs the real core + module migrations.
     * @return bool True if a migration ran, false if the schema was current.
     */
    public static function maybe_upgrade(?callable $migrator = null): bool {
        $installed = get_option('peanut_db_version', '0');

        // Cheap gate: nothing to do once we are at (or past) the shipped version.
        if (version_compare((string) $installed, self::DB_VERSION, '>=')) {
            return false;
        }

        if ($migrator === null) {
            self::run_all_migrations();
        } else {
            $migrator();
        }

        update_option('peanut_db_version', self::DB_VERSION);
        return true;
    }

    /**
     * Run the core table creators plus every active module's table creator.
     *
     * Re-uses the activator's module-table routine so there is a single source
     * of truth for which modules own tables. dbDelta inside each is idempotent.
     */
    private static function run_all_migrations(): void {
        self::create_tables();

        // Re-run module table creators (additive/idempotent). The activator
        // already enumerates every module DB class; reuse it rather than
        // duplicating the list here.
        if (!class_exists('Peanut_Activator')) {
            $activator = PEANUT_PLUGIN_DIR . 'core/class-peanut-activator.php';
            if (defined('PEANUT_PLUGIN_DIR') && file_exists($activator)) {
                require_once $activator;
            }
        }
        if (method_exists('Peanut_Activator', 'create_module_tables')) {
            Peanut_Activator::create_module_tables();
        }
    }
}
