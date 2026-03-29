<?php
/**
 * Popups Database Schema
 *
 * Creates and manages Popups-specific database tables.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Popups_Database {

    /**
     * Get table prefix
     */
    private static function prefix(): string {
        global $wpdb;
        return $wpdb->prefix . PEANUT_TABLE_PREFIX;
    }

    /**
     * Get popups table name
     */
    public static function popups_table(): string {
        return self::prefix() . 'popups';
    }

    /**
     * Get interactions table name
     */
    public static function interactions_table(): string {
        return self::prefix() . 'popup_interactions';
    }

    /**
     * Create all Popups tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Popups table
        // Note: dbDelta() does not support inline SQL comments - keep schema clean
        $popups_table = self::popups_table();
        $sql_popups = "CREATE TABLE $popups_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            name varchar(255) NOT NULL,
            type enum('modal','slide-in','bar','fullscreen') DEFAULT 'modal',
            position varchar(50) DEFAULT 'center',
            status enum('draft','active','paused','archived') DEFAULT 'draft',
            priority int DEFAULT 10,
            title varchar(255) DEFAULT '',
            content longtext,
            image_url varchar(2048) DEFAULT '',
            form_fields longtext,
            button_text varchar(100) DEFAULT 'Subscribe',
            success_message varchar(500) DEFAULT 'Thank you for subscribing!',
            triggers longtext,
            display_rules longtext,
            styles longtext,
            settings longtext,
            views bigint(20) UNSIGNED DEFAULT 0,
            conversions bigint(20) UNSIGNED DEFAULT 0,
            start_date datetime DEFAULT NULL,
            end_date datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY type (type)
        ) $charset_collate;";

        dbDelta($sql_popups);

        // Popup interactions table
        $interactions_table = self::interactions_table();
        $sql_interactions = "CREATE TABLE $interactions_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            popup_id bigint(20) UNSIGNED NOT NULL,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            visitor_id varchar(36) DEFAULT NULL,
            action enum('view','convert','dismiss','click') NOT NULL,
            data longtext,
            page_url varchar(2048) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            KEY popup_id (popup_id),
            KEY user_id (user_id),
            KEY visitor_id (visitor_id),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta($sql_interactions);
    }

    /**
     * Drop all Popups tables (for uninstall)
     */
    public static function drop_tables(): void {
        global $wpdb;

        $tables = [
            self::popups_table(),
            self::interactions_table(),
        ];

        foreach ($tables as $table) {
            $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table));
        }
    }

    /**
     * Clean up old interaction records (called via cron)
     */
    public static function cleanup_old_records(): void {
        global $wpdb;

        // Keep 90 days of interactions
        $interactions_table = self::interactions_table();
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)", $interactions_table));
    }

    /**
     * Get default popup structure
     */
    public static function get_default_popup(): array {
        return [
            'name' => '',
            'type' => 'modal',
            'position' => 'center',
            'status' => 'draft',
            'priority' => 10,
            'title' => '',
            'content' => '',
            'image_url' => '',
            'form_fields' => [
                ['name' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
            ],
            'button_text' => 'Subscribe',
            'success_message' => 'Thank you for subscribing!',
            'triggers' => [
                'type' => 'time_delay',
                'delay' => 5,
            ],
            'display_rules' => [
                'pages' => 'all',
                'devices' => ['desktop', 'tablet', 'mobile'],
                'user_status' => 'all',
            ],
            'styles' => [
                'background_color' => '#ffffff',
                'text_color' => '#333333',
                'button_color' => '#0073aa',
                'button_text_color' => '#ffffff',
                'border_radius' => 8,
                'max_width' => 500,
            ],
            'settings' => [
                'animation' => 'fade',
                'overlay' => true,
                'overlay_color' => 'rgba(0,0,0,0.5)',
                'close_button' => true,
                'close_on_overlay' => true,
                'close_on_esc' => true,
                'hide_after_dismiss_days' => 7,
                'hide_after_convert_days' => 365,
            ],
        ];
    }
}
