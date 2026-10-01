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
delete_option('peanut_settings_secrets_version');
// Third-party secrets must not survive uninstall.
delete_option('peanut_ga_credentials');
delete_option('peanut_ga_credentials_secrets_version');
delete_option('peanut_webhook_secrets');
delete_option('peanut_webhook_secrets_version');
// Webhook replay-protection claims (peanut_whr_*).
require_once __DIR__ . '/core/services/class-peanut-webhook-replay-guard.php';
Peanut_Webhook_Replay_Guard::purge_all();

// Remove transients
delete_transient('peanut_license_data');
delete_transient('peanut_activated');

// Purge Hub-migration export artifacts. These are full-database PII dumps
// (contacts, WP user emails, invoices) written under wp-content and must not
// survive uninstall.
if (defined('WP_CONTENT_DIR')) {
    $peanut_export_dir = WP_CONTENT_DIR . '/peanut-hub-export';
    if (is_dir($peanut_export_dir)) {
        foreach ((array) glob($peanut_export_dir . '/{,.}[!.]*', GLOB_BRACE) as $peanut_export_file) {
            if (is_file($peanut_export_file)) {
                @unlink($peanut_export_file);
            }
        }
        @rmdir($peanut_export_dir);
    }

    $peanut_export_zip = WP_CONTENT_DIR . '/peanut-hub-export.zip';
    if (file_exists($peanut_export_zip)) {
        @unlink($peanut_export_zip);
    }
}

// Clear scheduled hooks
wp_clear_scheduled_hook('peanut_daily_maintenance');

// Flush rewrite rules
flush_rewrite_rules();
