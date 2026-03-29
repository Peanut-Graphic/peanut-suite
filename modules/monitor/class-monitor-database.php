<?php
/**
 * Monitor Database Schema
 *
 * Creates and manages Monitor-specific database tables.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Monitor_Database {

    /**
     * Get table prefix
     */
    private static function prefix(): string {
        global $wpdb;
        return $wpdb->prefix . PEANUT_TABLE_PREFIX;
    }

    /**
     * Get sites table name
     */
    public static function sites_table(): string {
        return self::prefix() . 'monitor_sites';
    }

    /**
     * Get health log table name
     */
    public static function health_log_table(): string {
        return self::prefix() . 'monitor_health_log';
    }

    /**
     * Get uptime table name
     */
    public static function uptime_table(): string {
        return self::prefix() . 'monitor_uptime';
    }

    /**
     * Get analytics table name
     */
    public static function analytics_table(): string {
        return self::prefix() . 'monitor_analytics';
    }

    /**
     * Get web vitals table name
     */
    public static function webvitals_table(): string {
        return self::prefix() . 'monitor_webvitals';
    }

    /**
     * Create all Monitor tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Connected sites table
        $sites_table = self::sites_table();
        $sql_sites = "CREATE TABLE $sites_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED NOT NULL,
            site_url varchar(255) NOT NULL,
            site_name varchar(255) DEFAULT '',
            site_key_hash varchar(64) NOT NULL,
            status enum('active','disconnected','error') DEFAULT 'active',
            last_check datetime DEFAULT NULL,
            last_health longtext,
            peanut_suite_active tinyint(1) DEFAULT 0,
            peanut_suite_version varchar(20) DEFAULT NULL,
            permissions longtext,
            last_webvitals longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY site_url_user (user_id, site_url),
            KEY user_id (user_id),
            KEY status (status)
        ) $charset_collate;";

        dbDelta($sql_sites);

        // Health log table
        $health_log_table = self::health_log_table();
        $sql_health_log = "CREATE TABLE $health_log_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id bigint(20) UNSIGNED NOT NULL,
            status enum('healthy','warning','critical','offline') NOT NULL,
            score int DEFAULT 0,
            checks longtext,
            checked_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY site_id (site_id),
            KEY checked_at (checked_at),
            KEY status (status)
        ) $charset_collate;";

        dbDelta($sql_health_log);

        // Uptime monitoring table
        $uptime_table = self::uptime_table();
        $sql_uptime = "CREATE TABLE $uptime_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id bigint(20) UNSIGNED NOT NULL,
            status enum('up','down') NOT NULL,
            response_time int DEFAULT NULL,
            status_code int DEFAULT NULL,
            error_message varchar(255) DEFAULT NULL,
            checked_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY site_id (site_id),
            KEY checked_at (checked_at),
            KEY status (status)
        ) $charset_collate;";

        dbDelta($sql_uptime);

        // Aggregated analytics table
        $analytics_table = self::analytics_table();
        $sql_analytics = "CREATE TABLE $analytics_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id bigint(20) UNSIGNED NOT NULL,
            period varchar(10) NOT NULL,
            period_start date NOT NULL,
            metrics longtext NOT NULL,
            synced_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY site_period (site_id, period, period_start),
            KEY site_id (site_id)
        ) $charset_collate;";

        dbDelta($sql_analytics);

        // Web Vitals / Performance table
        $webvitals_table = self::webvitals_table();
        $sql_webvitals = "CREATE TABLE $webvitals_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id bigint(20) UNSIGNED NOT NULL,
            source enum('pagespeed','basic') DEFAULT 'basic',
            status enum('good','needs-improvement','poor','error') DEFAULT 'good',
            score_mobile int DEFAULT NULL,
            score_desktop int DEFAULT NULL,
            lcp_mobile decimal(10,2) DEFAULT NULL,
            lcp_desktop decimal(10,2) DEFAULT NULL,
            fid_mobile decimal(10,2) DEFAULT NULL,
            fid_desktop decimal(10,2) DEFAULT NULL,
            cls_mobile decimal(10,4) DEFAULT NULL,
            cls_desktop decimal(10,4) DEFAULT NULL,
            ttfb_mobile decimal(10,2) DEFAULT NULL,
            ttfb_desktop decimal(10,2) DEFAULT NULL,
            full_data longtext,
            checked_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY site_id (site_id),
            KEY checked_at (checked_at),
            KEY status (status)
        ) $charset_collate;";

        dbDelta($sql_webvitals);
    }

    /**
     * Drop all Monitor tables (for uninstall)
     */
    public static function drop_tables(): void {
        global $wpdb;

        $tables = [
            self::sites_table(),
            self::health_log_table(),
            self::uptime_table(),
            self::analytics_table(),
            self::webvitals_table(),
        ];

        foreach ($tables as $table) {
            $wpdb->query($wpdb->prepare("DROP TABLE IF EXISTS %i", $table));
        }
    }

    /**
     * Clean up old records (called via cron)
     */
    public static function cleanup_old_records(): void {
        global $wpdb;

        // Keep 30 days of health logs
        $health_log_table = self::health_log_table();
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)", $health_log_table));

        // Keep 90 days of uptime records
        $uptime_table = self::uptime_table();
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE checked_at < DATE_SUB(NOW(), INTERVAL 90 DAY)", $uptime_table));

        // Keep 12 months of analytics
        $analytics_table = self::analytics_table();
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE period_start < DATE_SUB(NOW(), INTERVAL 12 MONTH)", $analytics_table));

        // Keep 90 days of web vitals data
        $webvitals_table = self::webvitals_table();
        $wpdb->query($wpdb->prepare("DELETE FROM %i WHERE checked_at < DATE_SUB(NOW(), INTERVAL 90 DAY)", $webvitals_table));
    }
}
