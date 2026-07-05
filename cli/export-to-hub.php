<?php
/**
 * Export Peanut Suite data for Hub import
 *
 * Usage: wp eval-file cli/export-to-hub.php [output-path]
 */

// Hard gate: this script performs a full-database PII dump and MUST only ever
// run under WP-CLI. Without this, a direct web request to
// /wp-content/plugins/peanut-suite/cli/export-to-hub.php would bootstrap
// WordPress (via the wp-load require below) and dump the entire database — and
// register_argc_argv would populate $argv[1] (the output path) straight from the
// query string. Refuse before requiring wp-load or touching $wpdb.
if (!(defined('WP_CLI') && WP_CLI)) {
    if (!headers_sent()) {
        http_response_code(403);
    }
    exit('Forbidden: this exporter runs under WP-CLI only.');
}

if (!defined('ABSPATH')) {
    // Running via WP-CLI
    require_once(dirname(__FILE__, 5) . '/wp-load.php');
}

global $wpdb;

$output_dir = isset($argv[1]) ? $argv[1] : sys_get_temp_dir() . '/suite-export-' . time();

if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

// Defense-in-depth: if the output dir ever lands under the webroot, block direct
// web access to the JSON PII dumps (mirrors how WP core protects private dirs).
@file_put_contents(
    "{$output_dir}/.htaccess",
    "# Peanut Suite: deny web access to PII export artifacts.\n"
    . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
    . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
);
@file_put_contents("{$output_dir}/index.php", "<?php // Silence is golden\n");

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
