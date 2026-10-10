<?php
// Offline regression: the public POST /track/identify must not let a caller
// link a visitor to a CRM contact of its choosing via traits.contact_id.
// Runs the real namespaced controller callback; no WordPress/DB/network.
declare(strict_types=1);
namespace {
    define('ABSPATH', '/synthetic/wordpress/');
    define('ARRAY_A', 'ARRAY_A');
    class WP_REST_Request {
        public function __construct(private array $params = []) {}
        public function get_param($key) { return $this->params[$key] ?? null; }
    }
    class WP_REST_Response { public function __construct(public array $data, public int $status = 200) {} }
    class Peanut_Security { public static function check_rate_limit(...$args): bool { return true; } }
    function is_email($e) { return (bool) filter_var($e, FILTER_VALIDATE_EMAIL); }
    function get_transient($k) { return false; }
    function set_transient($k, $v, $t) { return true; }
    function current_time($t) { return '2026-10-09 00:00:00'; }
    function absint($v) { return abs((int) $v); }
    $GLOBALS['actions'] = [];
    function do_action($hook, ...$args) { $GLOBALS['actions'][] = [$hook, $args]; }
    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
        public array $updates = [];
        public function prepare($q, ...$a) { return $q; }
        public function get_row($q, $o = null) { return ['id' => 1, 'visitor_id' => 'v', 'email' => null]; }
        public function update($table, $data, $where, ...$rest) { $this->updates[] = $data; return 1; }
        public function query($q) { return 1; }
        public function insert($t, $d) { return 1; }
    };
}
namespace {
    $base = dirname(__DIR__, 2) . '/modules/visitors/';
    require $base . 'class-visitors-database.php';
    require $base . 'class-visitors-tracker.php';
    require $base . 'api/class-visitors-controller.php';

    $fail = static function (string $why): void { fwrite(STDERR, $why . "\n"); exit(1); };
    $controller = new PeanutSuite\Visitors\Visitors_Controller();

    $response = $controller->identify_visitor(new WP_REST_Request([
        'visitor_id' => '3f2b8c1e-4d5a-4b6c-8d7e-9f0a1b2c3d4e',
        'email' => 'someone@example.com',
        'traits' => ['contact_id' => 42, 'plan' => 'pro'],
    ]));

    if ($response->status !== 200) { $fail('identify must still succeed: ' . json_encode($response->data)); }
    $updates = $GLOBALS['wpdb']->updates;
    if ($updates === []) { $fail('identify must store the email'); }
    foreach ($updates as $data) {
        if (array_key_exists('contact_id', $data)) { $fail('a public caller must not set contact_id: ' . json_encode($data)); }
    }
    if (($updates[count($updates) - 1]['email'] ?? null) !== 'someone@example.com') { $fail('email must be stored'); }
    foreach ($GLOBALS['actions'] as [$hook, $args]) {
        if ($hook === 'peanut_visitor_identified' && array_key_exists('contact_id', $args[2] ?? [])) {
            $fail('peanut_visitor_identified listeners must not receive a caller-chosen contact_id');
        }
    }
    echo "identify ignores caller contact_id checks passed\n";
}
