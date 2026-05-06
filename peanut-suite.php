<?php
/**
 * Plugin Name: Peanut Suite
 * Plugin URI: https://peanutgraphic.com/peanut-suite
 * Description: Complete marketing toolkit - UTM campaigns, link management, lead tracking, and analytics in one unified dashboard.
 * Version: 4.1.9
 * Author: Peanut Graphic
 * Author URI: https://peanutgraphic.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: peanut-suite
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Prevent loading twice
if (defined('PEANUT_VERSION')) {
    return;
}

/**
 * Plugin constants
 */
define('PEANUT_VERSION', '4.1.9');
define('PEANUT_SUITE_VERSION', '4.1.9'); // Alias for updater
define('PEANUT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PEANUT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PEANUT_PLUGIN_BASENAME', plugin_basename(__FILE__));

// API namespace
define('PEANUT_API_NAMESPACE', 'peanut/v1');

// Table prefix
define('PEANUT_TABLE_PREFIX', 'peanut_');

/**
 * Check if Suite is disabled by Peanut Connect Hub Mode
 * When Hub Mode is set to 'disable_suite', Connect sets this filter to true
 */
if (apply_filters('peanut_suite_disabled', false)) {
    // Suite is disabled - don't load anything
    return;
}

/**
 * Load traits (not autoloadable)
 */
require_once PEANUT_PLUGIN_DIR . 'core/api/trait-peanut-rest-response.php';

/**
 * Autoloader for plugin classes
 */
spl_autoload_register(function ($class) {
    // Check for Peanut_ prefix (core classes)
    if (strpos($class, 'Peanut_') === 0) {
        $class_name = str_replace('Peanut_', '', $class);
        $class_name = strtolower(str_replace('_', '-', $class_name));

        $directories = [
            PEANUT_PLUGIN_DIR . 'core/',
            PEANUT_PLUGIN_DIR . 'core/database/',
            PEANUT_PLUGIN_DIR . 'core/api/',
            PEANUT_PLUGIN_DIR . 'core/services/',
            PEANUT_PLUGIN_DIR . 'core/services/integrations/',
            PEANUT_PLUGIN_DIR . 'core/admin/',
        ];

        foreach ($directories as $directory) {
            $file = $directory . 'class-peanut-' . $class_name . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }

    // Check for Peanut_ML_ prefix (ML module classes)
    if (strpos($class, 'Peanut_ML_') === 0) {
        $class_name = str_replace('Peanut_ML_', '', $class);
        $class_name = strtolower(str_replace('_', '-', $class_name));

        $directories = [
            PEANUT_PLUGIN_DIR . 'modules/analytics/',
            PEANUT_PLUGIN_DIR . 'modules/contacts/',
            PEANUT_PLUGIN_DIR . 'modules/attribution/',
        ];

        foreach ($directories as $directory) {
            $file = $directory . 'class-ml-' . $class_name . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }

    // Check for module classes (Module_Name_Class format)
    if (preg_match('/^(UTM|Links|Contacts|Popups|Dashboard|Invoicing|Security|Reports|Backlinks|Geopets)_(.+)$/', $class, $matches)) {
        $module = strtolower($matches[1]);
        $class_name = strtolower(str_replace('_', '-', $matches[2]));

        $directories = [
            PEANUT_PLUGIN_DIR . "modules/{$module}/",
            PEANUT_PLUGIN_DIR . "modules/{$module}/api/",
        ];

        foreach ($directories as $directory) {
            $file = $directory . 'class-' . $module . '-' . $class_name . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

/**
 * Plugin activation
 */
function peanut_activate() {
    require_once PEANUT_PLUGIN_DIR . 'core/class-peanut-activator.php';
    Peanut_Activator::activate();
}
register_activation_hook(__FILE__, 'peanut_activate');

/**
 * Plugin deactivation
 */
function peanut_deactivate() {
    require_once PEANUT_PLUGIN_DIR . 'core/class-peanut-deactivator.php';
    Peanut_Deactivator::deactivate();
}
register_deactivation_hook(__FILE__, 'peanut_deactivate');

/**
 * Initialize the plugin (on init for WordPress 6.7+ translation compatibility)
 */
function peanut_init() {
    // Load text domain first
    load_plugin_textdomain(
        'peanut-suite',
        false,
        dirname(PEANUT_PLUGIN_BASENAME) . '/languages/'
    );

    // Initialize core
    require_once PEANUT_PLUGIN_DIR . 'core/class-peanut-core.php';
    $core = new Peanut_Core();
    $core->run();

    // Fire action for add-ons to hook into
    do_action('peanut_loaded');
}
add_action('init', 'peanut_init');

/**
 * Helper: Get active modules
 */
function peanut_get_active_modules(): array {
    return get_option('peanut_active_modules', [
        'utm' => true,
        'links' => true,
        'contacts' => true,
        'dashboard' => true,
        'popups' => false, // Pro feature
    ]);
}

/**
 * Helper: Check if module is active
 */
function peanut_is_module_active(string $module): bool {
    $modules = peanut_get_active_modules();
    return !empty($modules[$module]);
}

/**
 * Helper: Get license data
 */
function peanut_get_license(): array {
    static $license = null;
    if ($license === null) {
        require_once PEANUT_PLUGIN_DIR . 'core/services/class-peanut-license.php';
        $service = new Peanut_License();
        $license = $service->get_license_data();
    }
    return $license;
}

/**
 * Helper: Check if pro features available
 */
function peanut_is_pro(): bool {
    $license = peanut_get_license();
    return in_array($license['tier'] ?? '', ['pro', 'agency'], true);
}

/**
 * Helper: Check if agency tier
 */
function peanut_is_agency(): bool {
    $license = peanut_get_license();
    return ($license['tier'] ?? '') === 'agency';
}

/**
 * Helper: Format price (WooCommerce-safe)
 * Falls back to basic formatting if WooCommerce is not active
 */
function peanut_format_price(float $amount): string {
    if (function_exists('wc_price')) {
        return wc_price($amount);
    }
    // Fallback formatting
    return '$' . number_format($amount, 2);
}

/**
 * Run diagnostics checks
 */
function peanut_run_diagnostics(): array {
    global $wpdb;

    $checks = [];

    // PHP Version
    $php_version = phpversion();
    $php_ok = version_compare($php_version, '8.0', '>=');
    $checks[] = [
        'name' => 'PHP Version',
        'status' => $php_ok ? 'pass' : 'fail',
        'message' => $php_ok ? "PHP $php_version (8.0+ required)" : "PHP $php_version - Please upgrade to 8.0+",
    ];

    // WordPress Version
    $wp_version = get_bloginfo('version');
    $wp_ok = version_compare($wp_version, '5.8', '>=');
    $checks[] = [
        'name' => 'WordPress Version',
        'status' => $wp_ok ? 'pass' : 'warning',
        'message' => $wp_ok ? "WordPress $wp_version" : "WordPress $wp_version - 5.8+ recommended",
    ];

    // Database Connection
    $db_ok = method_exists($wpdb, 'check_connection') ? $wpdb->check_connection() : ($wpdb->ready ? true : false);
    $checks[] = [
        'name' => 'Database Connection',
        'status' => $db_ok ? 'pass' : 'fail',
        'message' => $db_ok ? 'Connected to database' : 'Database connection failed',
    ];

    // UTM Table
    $utm_table = $wpdb->prefix . 'peanut_utms';
    $utm_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $utm_table)) === $utm_table;
    $checks[] = [
        'name' => 'UTM Database Table',
        'status' => $utm_exists ? 'pass' : 'warning',
        'message' => $utm_exists ? 'Table exists' : 'Table not created - deactivate/reactivate plugin',
    ];

    // Links Table
    $links_table = $wpdb->prefix . 'peanut_links';
    $links_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $links_table)) === $links_table;
    $checks[] = [
        'name' => 'Links Database Table',
        'status' => $links_exists ? 'pass' : 'warning',
        'message' => $links_exists ? 'Table exists' : 'Table not created - deactivate/reactivate plugin',
    ];

    // Contacts Table
    $contacts_table = $wpdb->prefix . 'peanut_contacts';
    $contacts_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $contacts_table)) === $contacts_table;
    $checks[] = [
        'name' => 'Contacts Database Table',
        'status' => $contacts_exists ? 'pass' : 'warning',
        'message' => $contacts_exists ? 'Table exists' : 'Table not created - deactivate/reactivate plugin',
    ];

    // REST API
    $rest_url = rest_url(PEANUT_API_NAMESPACE);
    $checks[] = [
        'name' => 'REST API',
        'status' => 'pass',
        'message' => 'Endpoint: ' . $rest_url,
    ];

    // File Permissions
    $upload_dir = wp_upload_dir();
    $writable = wp_is_writable($upload_dir['basedir']);
    $checks[] = [
        'name' => 'Upload Directory',
        'status' => $writable ? 'pass' : 'warning',
        'message' => $writable ? 'Writable' : 'Not writable - some features may not work',
    ];

    // License Status
    $license = peanut_get_license();
    $license_ok = ($license['status'] ?? '') === 'active';
    $checks[] = [
        'name' => 'License Status',
        'status' => $license_ok ? 'pass' : 'warning',
        'message' => $license_ok
            ? ucfirst($license['tier'] ?? 'free') . ' license active'
            : 'No active license - using Free tier',
    ];

    $passed = 0;
    $warnings = 0;
    $failed = 0;
    foreach ($checks as $check) {
        if ($check['status'] === 'pass') $passed++;
        elseif ($check['status'] === 'warning') $warnings++;
        elseif ($check['status'] === 'fail') $failed++;
    }

    return [
        'checks' => $checks,
        'passed' => $passed,
        'warnings' => $warnings,
        'failed' => $failed,
    ];
}

/**
 * Log an error
 */
function peanut_log_error(string $message, string $type = 'error', string $context = ''): void {
    $log = get_option('peanut_error_log', []);

    // Keep only last 100 entries
    if (count($log) >= 100) {
        $log = array_slice($log, -99);
    }

    $log[] = [
        'time' => time(),
        'type' => $type,
        'message' => $message,
        'context' => $context,
    ];

    update_option('peanut_error_log', $log);
}

/**
 * AJAX: Clear error log
 */
function peanut_ajax_clear_error_log(): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }

    if (!wp_verify_nonce($_POST['nonce'] ?? '', 'peanut_clear_log')) {
        wp_send_json_error('Invalid nonce');
    }

    delete_option('peanut_error_log');
    wp_send_json_success();
}
add_action('wp_ajax_peanut_clear_error_log', 'peanut_ajax_clear_error_log');

/**
 * AJAX: Dismiss welcome message
 */
function peanut_ajax_dismiss_welcome(): void {
    check_ajax_referer('peanut_dismiss_welcome', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
    }

    update_option('peanut_welcome_dismissed', true);
    wp_send_json_success();
}
add_action('wp_ajax_peanut_dismiss_welcome', 'peanut_ajax_dismiss_welcome');

/**
 * AJAX: Complete feature tour
 */
function peanut_ajax_complete_feature_tour(): void {
    check_ajax_referer('peanut_complete_feature_tour', 'nonce');
    if (!current_user_can('read')) {
        wp_send_json_error('Permission denied');
    }

    $version = sanitize_text_field($_POST['version'] ?? '');
    if (empty($version)) {
        wp_send_json_error('Missing version');
    }

    // Save to user meta
    $user_id = get_current_user_id();
    $completed_tours = get_user_meta($user_id, 'peanut_completed_tours', true) ?: [];
    $completed_tours[$version] = current_time('mysql');
    update_user_meta($user_id, 'peanut_completed_tours', $completed_tours);

    wp_send_json_success();
}
add_action('wp_ajax_peanut_complete_feature_tour', 'peanut_ajax_complete_feature_tour');

/**
 * Initialize self-hosted updater
 * Uses 'init' hook instead of 'admin_init' to ensure updates work
 * in all contexts (admin, WP-CLI, cron)
 */
function peanut_init_updater(): void {
    require_once PEANUT_PLUGIN_DIR . 'core/services/class-peanut-updater.php';
    new Peanut_Updater();
}
add_action('init', 'peanut_init_updater');
