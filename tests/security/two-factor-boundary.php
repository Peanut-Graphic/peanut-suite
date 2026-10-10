<?php
// Offline regression for the Security module's e-mail 2FA login flow. Runs the
// real Security_Module against minimal WordPress stand-ins; redirects throw so
// the callbacks' `exit` is never reached. No WordPress/DB/network.
declare(strict_types=1);

define('ABSPATH', '/synthetic/wordpress/');
define('MINUTE_IN_SECONDS', 60);
define('PEANUT_API_NAMESPACE', 'peanut/v1');

final class Redirected extends RuntimeException {
    public function __construct(public string $location) { parent::__construct($location); }
}
class WP_User {
    public function __construct(public int $ID, public string $user_login, public string $user_email, public array $roles) {}
}
class WP_REST_Request {
    public function __construct(private array $json = []) {}
    public function get_json_params() { return $this->json; }
}
class WP_REST_Response { public function __construct(public $data = null, public int $status = 200) {} }
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; const DELETABLE = 'DELETE'; }

$GLOBALS['opts'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['mail'] = [];
$GLOBALS['hooks'] = [];
$GLOBALS['cookies'] = [];
$GLOBALS['now_offset'] = 0;

function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['opts'][$k] = $v; return true; }
function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
function do_action($hook, ...$args) { foreach ($GLOBALS['hooks'][$hook] ?? [] as $cb) { $cb(...$args); } }
function did_action($h) { return 0; }
function doing_action($h) { return false; }
function wp_next_scheduled($h) { return 1; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $ttl) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function wp_hash($d) { return hash_hmac('md5', (string) $d, 'salt'); }
function wp_salt($s = 'auth') { return 'synthetic-salt'; }
function wp_unslash($v) { return $v; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $v)); }
function sanitize_title($v) { return sanitize_key($v); }
function sanitize_email($v) { return (string) $v; }
function absint($v) { return abs((int) $v); }
function wp_parse_args($a, $d) { return array_merge($d, (array) $a); }
function __($t, $d = null) { return $t; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function esc_html($t) { return htmlspecialchars((string) $t); }
function esc_html__($t, $d = null) { return esc_html($t); }
function esc_attr($t) { return htmlspecialchars((string) $t); }
function get_bloginfo($k) { return 'Synthetic'; }
function wp_mail($to, $subject, $message) { $GLOBALS['mail'][] = compact('to', 'subject', 'message'); return true; }
function wp_logout() { $GLOBALS['cookies'] = []; }
function wp_set_auth_cookie($id, $remember = false) { $GLOBALS['cookies'][] = $id; }
function wp_set_current_user($id) {}
function wp_login_url() { return 'https://site.example/wp-login.php'; }
function admin_url($p = '') { return 'https://site.example/wp-admin/' . $p; }
function add_query_arg($args, $url = '') {
    if (is_string($args)) { $args = [$args => $url]; $url = func_get_arg(2); }
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}
function wp_safe_redirect($location) { throw new Redirected($location); }
function current_user_can($c) { return true; }
function get_user_meta($id, $k, $single = false) { return ''; }
function wp_generate_password($n = 12, $s = true, $e = false) { return bin2hex(random_bytes(intdiv($n, 2))); }

$GLOBALS['users'] = [7 => new WP_User(7, 'admin', 'admin@example.com', ['administrator'])];
function get_user_by($field, $id) { return $GLOBALS['users'][(int) $id] ?? false; }

require dirname(__DIR__, 2) . '/modules/security/class-security-module.php';

$fail = static function (string $why): void { fwrite(STDERR, "FAIL: $why\n"); exit(1); };
$redirect = static function (callable $fn): string {
    try { $fn(); } catch (Redirected $r) { return $r->location; }
    return '';
};
$settings = static function (string $method): void {
    $GLOBALS['opts']['peanut_security_settings'] = [
        'hide_login_enabled' => false, 'limit_login_enabled' => false,
        'notify_login_success' => false, 'notify_login_failed' => false,
        '2fa_enabled' => true, '2fa_method' => $method, '2fa_roles' => ['administrator'],
        'ip_whitelist' => [], 'ip_blacklist' => [],
    ];
};
$reset = static function () { $GLOBALS['transients'] = []; $GLOBALS['mail'] = []; $GLOBALS['hooks'] = []; $GLOBALS['cookies'] = []; };
$login = static function () use ($redirect): array {
    $location = $redirect(static fn() => do_action('wp_login', 'admin', $GLOBALS['users'][7]));
    parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
    $mail = end($GLOBALS['mail']);
    preg_match('/code is: (\d+)/', $mail['message'] ?? '', $m);
    return ['location' => $location, 'token' => $q['token'] ?? '', 'code' => $m[1] ?? ''];
};
$verify = static function (string $token, string $code) use ($redirect): string {
    $_POST = ['token' => $token, '2fa_code' => $code];
    return $redirect(static fn() => do_action('login_form_2fa_verify'));
};

// 0. The code generator is the CSPRNG.
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/security/class-security-module.php');
if (str_contains($source, 'mt_rand(')) { $fail('2FA codes must not come from mt_rand()'); }

// 1. Login issues a challenge: 6-digit code mailed, never stored in clear.
$reset(); $settings('email'); new Security_Module();
$c = $login();
if (!str_contains($c['location'], 'action=2fa') || $c['token'] === '' || !preg_match('/^\d{6}$/', $c['code'])) { $fail('login must redirect to the code form and mail a 6-digit code: ' . json_encode($c)); }
if (str_contains(json_encode($GLOBALS['transients']), $c['code'])) { $fail('the code must not be stored in clear'); }

// 2. The correct code signs the user in, once, without a new challenge.
$mails = count($GLOBALS['mail']);
$to = $verify($c['token'], $c['code']);
if ($to !== admin_url()) { $fail('a correct code must land on wp-admin, got ' . $to); }
if ($GLOBALS['cookies'] !== [7]) { $fail('a correct code must set the auth cookie: ' . json_encode($GLOBALS['cookies'])); }
if (count($GLOBALS['mail']) !== $mails) { $fail('completing 2FA must not start a new challenge (login loop)'); }
if (str_contains($verify($c['token'], $c['code']), 'wp-admin')) { $fail('a used code must not work twice'); }

// 3. Wrong codes are capped; the challenge then dies, even for the right code.
$reset(); new Security_Module();
$c = $login();
$wrong = $c['code'] === '000000' ? '111111' : '000000';
for ($i = 1; $i <= Security_Module::TWO_FA_MAX_ATTEMPTS; $i++) {
    $to = $verify($c['token'], $wrong);
    if (str_contains($to, 'wp-admin')) { $fail('a wrong code must not sign in'); }
}
if (!str_contains($to, 'peanut_2fa=locked')) { $fail('the last allowed wrong code must lock the challenge, got ' . $to); }
if (str_contains($verify($c['token'], $c['code']), 'wp-admin')) { $fail('after ' . Security_Module::TWO_FA_MAX_ATTEMPTS . ' wrong codes the right code must no longer work'); }
if (Security_Module::TWO_FA_MAX_ATTEMPTS > 10) { $fail('attempt budget too large'); }

// 4. An expired challenge is refused.
$reset(); new Security_Module();
$c = $login();
$key = 'peanut_2fa_' . $c['token'];
$GLOBALS['transients'][$key]['expires'] = time() - 1;
if (str_contains($verify($c['token'], $c['code']), 'wp-admin')) { $fail('an expired code must not sign in'); }

// 5. Resend replaces the code, is capped, and does not reset attempts.
$reset(); new Security_Module();
$c = $login();
$verify($c['token'], $wrong);
$_GET = ['token' => $c['token']];
$redirect(static fn() => do_action('login_form_2fa_resend'));
$new = end($GLOBALS['mail']);
preg_match('/code is: (\d+)/', $new['message'], $m);
if (count($GLOBALS['mail']) !== 2) { $fail('resend must mail a new code'); }
if (($GLOBALS['transients'][$key = 'peanut_2fa_' . $c['token']]['attempts'] ?? null) !== 1) { $fail('resend must not reset the attempt counter'); }
for ($i = 0; $i < Security_Module::TWO_FA_MAX_RESENDS + 2; $i++) { $redirect(static fn() => do_action('login_form_2fa_resend')); }
if (count($GLOBALS['mail']) !== 1 + Security_Module::TWO_FA_MAX_RESENDS) { $fail('resends must be capped at ' . Security_Module::TWO_FA_MAX_RESENDS . ', mailed ' . count($GLOBALS['mail'])); }
$latest = end($GLOBALS['mail']); preg_match('/code is: (\d+)/', $latest['message'], $m);
if (!str_contains($verify($c['token'], $m[1]), 'wp-admin')) { $fail('the latest resent code must work'); }

// 6. A stored "totp" method (never implemented) still challenges by e-mail
//    instead of silently skipping 2FA.
$reset(); $settings('totp'); new Security_Module();
$c = $login();
if ($c['token'] === '' || $c['code'] === '') { $fail('2FA must not be skipped when the method is totp'); }

// 7. The settings API refuses totp.
$reset(); $settings('email');
$response = (new Security_Module())->update_settings(new WP_REST_Request(['2fa_enabled' => true, '2fa_method' => 'totp']));
if ($response->status !== 400) { $fail('update_settings must refuse 2fa_method=totp with 400, got ' . $response->status); }

echo "two-factor flow checks passed\n";
