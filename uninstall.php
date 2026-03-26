<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Uninstall Peanut Suite
 *
 * Removes all plugin data when uninstalled.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Define required constant for database class
if (!defined('PEANUT_TABLE_PREFIX')) {
    define('PEANUT_TABLE_PREFIX', 'peanut_');
}

// Load database class
require_once __DIR__ . '/core/database/class-peanut-database.php';

// Drop all tables
Peanut_Database::drop_tables();

// Remove options
delete_option('peanut_license_key');
delete_option('peanut_settings');
delete_option('peanut_active_modules');
delete_option('peanut_db_version');

// Remove transients
delete_transient('peanut_license_data');
delete_transient('peanut_activated');

// Clear scheduled hooks
wp_clear_scheduled_hook('peanut_daily_maintenance');

// Flush rewrite rules
flush_rewrite_rules();
