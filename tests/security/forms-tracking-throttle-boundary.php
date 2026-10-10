<?php
// Offline regression for the public form-tracking writes (interaction,
// abandon): each must hit the global limiter and deny BEFORE any query, and
// must refuse malformed input. No WordPress/DB/network.
declare(strict_types=1);
define('ABSPATH', '/synthetic/wordpress/');
class WP_REST_Request {
    public function __construct(private array $params = []) {}
    public function get_param($key) { return $this->params[$key] ?? null; }
}
class WP_REST_Response { public function __construct(public array $data, public int $status) {} }
class Peanut_Security {
    public static bool $allowed = false;
    public static array $calls = [];
    public static function check_rate_limit(...$args): bool { self::$calls[] = $args; return self::$allowed; }
}
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function current_time($type) { return '2026-10-06 00:00:00'; }
$wpdb = new class {
    public string $prefix = 'synthetic_';
    public array $writes = [];
    public array $queries = [];
    public function prepare(...$args) { return $args; }
    public function get_var(...$args) { $this->queries[] = $args; return false; }
    public function query($q) { $this->queries[] = $q; $this->writes[] = $q; return 1; }
    public function insert($table, $data) { $this->writes[] = $data; return 1; }
};
require dirname(__DIR__, 2) . '/modules/forms/class-forms-module.php';
$module = new PeanutSuite\Forms\Forms_Module();
$fail = static function (string $why): void { fwrite(STDERR, $why . "\n"); exit(1); };

$valid = ['form_id' => 'contact', 'field_name' => 'email', 'time_spent' => 4.5, 'last_field' => 'email'];

// 1. Denied by the limiter: 429 and not a single query or write.
foreach ([['track_interaction', 'form_interaction', 60], ['track_abandonment', 'form_abandon', 10]] as [$method, $action, $limit]) {
    Peanut_Security::$calls = [];
    $response = $module->$method(new WP_REST_Request($valid));
    if ($response->status !== 429) { $fail("$method must return 429 when the limiter denies (got {$response->status})"); }
    if ($wpdb->queries !== [] || $wpdb->writes !== []) { $fail("$method must not touch the database when denied"); }
    if (Peanut_Security::$calls !== [[$action, $limit, 60]]) { $fail("$method must call the limiter as [$action, $limit, 60]: " . json_encode(Peanut_Security::$calls)); }
}

Peanut_Security::$allowed = true;

// 2. Malformed input is refused before any query.
foreach ([['track_interaction', ['form_id' => '', 'field_name' => 'x']], ['track_interaction', ['form_id' => 'f', 'field_name' => ['a']]], ['track_abandonment', ['form_id' => '']]] as [$method, $params]) {
    $response = $module->$method(new WP_REST_Request($params));
    if ($response->status !== 400 || $wpdb->queries !== [] || $wpdb->writes !== []) { $fail("$method must refuse " . json_encode($params) . ' with 400 and no query'); }
}

// 3. Allowed interaction: one row, time_spent clamped to a sane range.
$module->track_interaction(new WP_REST_Request(['form_id' => 'contact', 'field_name' => str_repeat('f', 300), 'time_spent' => 1e30]));
$row = end($wpdb->writes);
if (!is_array($row) || $row['total_time_spent'] !== 3600.0 || strlen($row['field_name']) !== 100) { $fail('interaction must clamp time_spent to 3600 and field_name to 100 chars: ' . json_encode($row)); }
$module->track_interaction(new WP_REST_Request(['form_id' => 'contact', 'field_name' => 'email', 'time_spent' => -50]));
$row = end($wpdb->writes);
if ($row['total_time_spent'] !== 0.0) { $fail('negative time_spent must be stored as 0'); }

// 4. Allowed abandon still records.
$before = count($wpdb->writes);
$response = $module->track_abandonment(new WP_REST_Request($valid));
if ($response->status !== 200 || count($wpdb->writes) <= $before) { $fail('an allowed abandonment must be recorded'); }

echo "form tracking throttle and validation checks passed\n";
