<?php
// Offline regression for the actual namespaced callback; no WordPress/DB/network.
declare(strict_types=1);
define('ABSPATH', '/synthetic/wordpress/');
class WP_REST_Request { public function get_param($key) { return ['form_id' => 'synthetic', 'form_name' => 'Synthetic Form', 'form_type' => 'test'][$key] ?? ''; } }
class WP_REST_Response { public function __construct(public array $data, public int $status) {} }
class Peanut_Security {
    public static bool $allowed = false;
    public static array $calls = [];
    public static function check_rate_limit(...$args): bool { self::$calls[] = $args; return self::$allowed; }
}
function sanitize_text_field($value) { return $value; }
function current_time($type) { return '2026-10-06 00:00:00'; }
$wpdb = new class {
    public string $prefix = 'synthetic_';
    public array $writes = [];
    public function prepare(...$args) { return 'synthetic query'; }
    public function get_var(...$args) { return false; }
    public function insert($table, $data) { $this->writes[] = $data; return 1; }
};
require dirname(__DIR__, 2) . '/modules/forms/class-forms-module.php';
$module = new PeanutSuite\Forms\Forms_Module();
$denied = $module->track_view(new WP_REST_Request());
if ($denied->status !== 429 || $wpdb->writes !== [] || Peanut_Security::$calls !== [['form_submit', 10, 60]]) {
    throw new RuntimeException('The configured global limiter must deny before writes');
}
Peanut_Security::$allowed = true;
$allowed = $module->track_view(new WP_REST_Request());
if ($allowed->status !== 200 || count($wpdb->writes) !== 1 || $wpdb->writes[0]['views'] !== 1 || count(Peanut_Security::$calls) !== 2) {
    throw new RuntimeException('An allowed view must be tracked once through the global limiter');
}
echo "deny-before-write and allowed-view checks passed\n";
