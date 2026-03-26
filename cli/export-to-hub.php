<?php
/**
 * Export Peanut Suite data for Hub import
 *
 * Usage: wp eval-file cli/export-to-hub.php [output-path]
 */

if (!defined('ABSPATH')) {
    // Running via WP-CLI
    require_once(dirname(__FILE__, 5) . '/wp-load.php');
}

global $wpdb;

$output_dir = isset($argv[1]) ? $argv[1] : sys_get_temp_dir() . '/suite-export-' . time();

if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

echo "Exporting Peanut Suite data to: {$output_dir}\n";

$tables_to_export = [
    'users' => "SELECT ID as id, user_login, user_email, user_registered, display_name FROM {$wpdb->users}",
    'accounts' => "SELECT * FROM {$wpdb->prefix}peanut_accounts",
    'account_members' => "SELECT * FROM {$wpdb->prefix}peanut_account_members",
    'clients' => "SELECT * FROM {$wpdb->prefix}peanut_clients",
    'projects' => "SELECT * FROM {$wpdb->prefix}peanut_projects",
    'project_members' => "SELECT * FROM {$wpdb->prefix}peanut_project_members",
    'invoices' => "SELECT * FROM {$wpdb->prefix}peanut_invoices",
    'invoice_items' => "SELECT * FROM {$wpdb->prefix}peanut_invoice_items",
    'contacts' => "SELECT * FROM {$wpdb->prefix}peanut_contacts",
    'contact_activities' => "SELECT * FROM {$wpdb->prefix}peanut_contact_activities",
    'utms' => "SELECT * FROM {$wpdb->prefix}peanut_utms",
    'links' => "SELECT * FROM {$wpdb->prefix}peanut_links",
    'link_clicks' => "SELECT * FROM {$wpdb->prefix}peanut_link_clicks",
    'tags' => "SELECT * FROM {$wpdb->prefix}peanut_tags",
    'taggables' => "SELECT * FROM {$wpdb->prefix}peanut_taggables",
    'monitor_sites' => "SELECT * FROM {$wpdb->prefix}peanut_monitor_sites",
];

$manifest = [
    'source' => home_url(),
    'exported_at' => date('Y-m-d H:i:s'),
    'version' => defined('PEANUT_VERSION') ? PEANUT_VERSION : 'unknown',
    'tables' => [],
];

foreach ($tables_to_export as $name => $query) {
    echo "  Exporting {$name}...\n";

    $results = $wpdb->get_results($query, ARRAY_A);

    if ($results === null || $wpdb->last_error) {
        echo "    Warning: Table may not exist - {$wpdb->last_error}\n";
        $results = [];
    }

    $manifest['tables'][$name] = [
        'count' => count($results),
        'file' => "{$name}.json",
    ];

    file_put_contents(
        "{$output_dir}/{$name}.json",
        json_encode($results, JSON_PRETTY_PRINT)
    );

    echo "    Exported " . count($results) . " records\n";
}

// Save manifest
file_put_contents(
    "{$output_dir}/manifest.json",
    json_encode($manifest, JSON_PRETTY_PRINT)
);

echo "\nExport complete!\n";
echo "Output directory: {$output_dir}\n";

// Create zip
$zip_file = dirname($output_dir) . '/suite-export.zip';
echo "Creating zip file: {$zip_file}\n";

$zip = new ZipArchive();
if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $files = glob("{$output_dir}/*.json");
    foreach ($files as $file) {
        $zip->addFile($file, basename($file));
    }
    $zip->close();
    echo "Zip file created: {$zip_file}\n";
} else {
    echo "Failed to create zip file\n";
}

echo "\nTo import into Hub, run:\n";
echo "  php artisan suite:import {$zip_file}\n";
