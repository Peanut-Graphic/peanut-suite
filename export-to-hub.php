<?php
/**
 * Peanut Suite Data Exporter for Hub Migration
 *
 * Run via WP-CLI: wp eval-file export-to-hub.php
 * Or place in theme and access via admin
 */

if (!defined('ABSPATH')) {
    // Running via WP-CLI
    if (!defined('WP_CLI')) {
        die('This script must be run via WP-CLI or WordPress admin');
    }
}

class Peanut_Hub_Exporter {

    private $export_dir;
    private $data = [];

    public function __construct() {
        $this->export_dir = WP_CONTENT_DIR . '/peanut-hub-export';

        if (!file_exists($this->export_dir)) {
            wp_mkdir_p($this->export_dir);
        }
    }

    public function export_all() {
        global $wpdb;
        $prefix = $wpdb->prefix . 'peanut_';

        $this->log("Starting Peanut Suite export...");
        $this->log("Export directory: {$this->export_dir}");

        // Export accounts (agencies)
        $this->export_table($prefix . 'accounts', 'accounts');

        // Export account members
        $this->export_table($prefix . 'account_members', 'account_members');

        // Export UTMs
        $this->export_table($prefix . 'utms', 'utms');

        // Export Links
        $this->export_table($prefix . 'links', 'links');

        // Export Link Clicks
        $this->export_table($prefix . 'link_clicks', 'link_clicks');

        // Export Contacts
        $this->export_table($prefix . 'contacts', 'contacts');

        // Export Contact Activities
        $this->export_table($prefix . 'contact_activities', 'contact_activities');

        // Export Tags
        $this->export_table($prefix . 'tags', 'tags');

        // Export Taggables
        $this->export_table($prefix . 'taggables', 'taggables');

        // Export Clients (billing clients)
        $this->export_table($prefix . 'clients', 'clients');

        // Export Projects
        $this->export_table($prefix . 'projects', 'projects');

        // Export Project Members
        $this->export_table($prefix . 'project_members', 'project_members');

        // Export Invoices
        $this->export_table($prefix . 'invoices', 'invoices');

        // Export Invoice Items
        $this->export_table($prefix . 'invoice_items', 'invoice_items');

        // Export Monitor Sites
        $this->export_table($prefix . 'monitor_sites', 'monitor_sites');

        // Export Users (WordPress users who own accounts)
        $this->export_users();

        // Create manifest
        $this->create_manifest();

        $this->log("Export complete!");
        $this->log("Files saved to: {$this->export_dir}");

        return $this->export_dir;
    }

    private function export_table($table, $name) {
        global $wpdb;

        // Check if table exists
        $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if (!$exists) {
            $this->log("Table $table does not exist, skipping...");
            return;
        }

        $this->log("Exporting $name...");

        // Get total count
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $this->log("  Found $count records");

        // Export in batches to handle large tables
        $batch_size = 1000;
        $offset = 0;
        $all_rows = [];

        while (true) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                ),
                ARRAY_A
            );

            if (empty($rows)) {
                break;
            }

            $all_rows = array_merge($all_rows, $rows);
            $offset += $batch_size;

            if ($offset % 5000 === 0) {
                $this->log("  Processed $offset records...");
            }
        }

        // Save to JSON file
        $file = $this->export_dir . "/{$name}.json";
        file_put_contents($file, json_encode($all_rows, JSON_PRETTY_PRINT));

        $this->data[$name] = [
            'count' => count($all_rows),
            'file' => "{$name}.json"
        ];

        $this->log("  Exported " . count($all_rows) . " records to {$name}.json");
    }

    private function export_users() {
        global $wpdb;

        $this->log("Exporting WordPress users...");

        // Get users who are account owners or members
        $prefix = $wpdb->prefix . 'peanut_';

        $user_ids = $wpdb->get_col("
            SELECT DISTINCT owner_user_id FROM {$prefix}accounts
            UNION
            SELECT DISTINCT user_id FROM {$prefix}account_members
        ");

        if (empty($user_ids)) {
            $this->log("  No users found");
            return;
        }

        $users = [];
        foreach ($user_ids as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $users[] = [
                    'id' => $user->ID,
                    'user_login' => $user->user_login,
                    'user_email' => $user->user_email,
                    'user_nicename' => $user->user_nicename,
                    'display_name' => $user->display_name,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'user_registered' => $user->user_registered,
                ];
            }
        }

        $file = $this->export_dir . "/users.json";
        file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT));

        $this->data['users'] = [
            'count' => count($users),
            'file' => 'users.json'
        ];

        $this->log("  Exported " . count($users) . " users");
    }

    private function create_manifest() {
        $manifest = [
            'version' => '1.0',
            'exported_at' => date('Y-m-d H:i:s'),
            'source' => get_site_url(),
            'tables' => $this->data,
        ];

        $file = $this->export_dir . "/manifest.json";
        file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT));

        $this->log("Created manifest.json");
    }

    private function log($message) {
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::log($message);
        } else {
            echo $message . "\n";
        }
    }
}

// Run the export
$exporter = new Peanut_Hub_Exporter();
$export_dir = $exporter->export_all();

// If running via WP-CLI, create a zip
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::log("\nCreating zip archive...");

    $zip_file = WP_CONTENT_DIR . '/peanut-hub-export.zip';

    // Remove old zip if exists
    if (file_exists($zip_file)) {
        unlink($zip_file);
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE) === TRUE) {
        $files = glob($export_dir . '/*.json');
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }
        $zip->close();
        WP_CLI::success("Created: $zip_file");
    } else {
        WP_CLI::error("Failed to create zip file");
    }
}
