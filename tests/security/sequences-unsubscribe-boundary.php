<?php
// Offline regression for sequence-email unsubscribe links: the link every
// sequence e-mail carries (?peanut_unsubscribe=1&sid=&token=) had no handler,
// so recipients could not unsubscribe. Runs the real Sequences_Module against
// an in-memory subscribers table. No WordPress/DB/network.
declare(strict_types=1);
namespace {
    define('ABSPATH', '/synthetic/wordpress/');
    define('PEANUT_API_NAMESPACE', 'peanut/v1');
    final class Died extends RuntimeException {
        public function __construct(public string $html, public int $status) { parent::__construct($html); }
    }
    $GLOBALS['hooks'] = [];
    $GLOBALS['mail'] = [];
    function add_action($h, $cb, $p = 10, $a = 1) { $GLOBALS['hooks'][$h][] = $cb; }
    function do_action($h, ...$a) { foreach ($GLOBALS['hooks'][$h] ?? [] as $cb) { $cb(...$a); } }
    function wp_next_scheduled($h) { return 1; }
    function wp_salt($s = 'auth') { return 'synthetic-salt'; }
    function wp_hash($d) { return hash_hmac('md5', (string) $d, 'synthetic-salt'); }
    function home_url($p = '') { return 'https://site.example' . $p; }
    function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
    function absint($v) { return abs((int) $v); }
    function wp_unslash($v) { return $v; }
    function nocache_headers() {}
    function current_time($t) { return '2026-10-09 12:00:00'; }
    function __($t, $d = null) { return $t; }
    function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
    function esc_html__($t, $d = null) { return esc_html($t); }
    function esc_url($u) { return htmlspecialchars((string) $u, ENT_QUOTES); }
    function esc_url_raw($u) { return (string) $u; }
    function wp_die($html, $title = '', $args = []) { throw new Died((string) $html, (int) ($args['response'] ?? 500)); }
    function apply_filters($h, $v, ...$a) { return $v; }
    function get_bloginfo($k) { return 'Synthetic'; }
    function wp_mail($to, $subject, $html, $headers = []) { $GLOBALS['mail'][] = compact('to', 'subject', 'html', 'headers'); return true; }

    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
        public array $subs = [];
        public array $contacts = [7 => 'contact@example.com'];
        public function prepare($q, ...$a) { return [$q, $a]; }
        public function get_row($p, $o = null) {
            [$q, $a] = is_array($p) ? $p : [$p, []];
            if (str_contains($q, 'peanut_sequence_subscribers WHERE id = %d')) {
                return isset($this->subs[$a[0]]) ? (object) $this->subs[$a[0]] : null;
            }
            if (str_contains($q, 'peanut_contacts WHERE id = %d')) {
                return isset($this->contacts[$a[0]]) ? (object) ['email' => $this->contacts[$a[0]]] : null;
            }
            return null;
        }
        public function get_var($p) {
            [$q, $a] = is_array($p) ? $p : [$p, []];
            if (str_contains($q, "status = 'unsubscribed'")) {
                foreach ($this->subs as $r) {
                    if ($r['status'] === 'unsubscribed' && (($r['email'] !== '' && $r['email'] === $a[0]) || ($r['contact_id'] > 0 && $r['contact_id'] === $a[1]))) { return $r['id']; }
                }
                return null;
            }
            if (str_contains($q, 'WHERE sequence_id = %d AND (contact_id')) {
                foreach ($this->subs as $r) {
                    if ($r['sequence_id'] === $a[0] && ($r['contact_id'] === $a[1] || $r['email'] === $a[2])) { return $r['id']; }
                }
            }
            return null;
        }
        public function update($t, $data, $where) { $this->subs[$where['id']] = array_merge($this->subs[$where['id']], $data); return 1; }
        public function insert($t, $data) { $id = count($this->subs) + 1; $this->subs[$id] = $data + ['id' => $id, 'emails_sent' => 0]; return 1; }
        public function query($p) {
            [$q, $a] = $p;
            if (str_contains($q, "SET status = 'unsubscribed'")) {
                foreach ($this->subs as $id => $r) {
                    if ($r['status'] === 'active' && (($r['email'] !== '' && $r['email'] === $a[1]) || ($r['contact_id'] > 0 && $r['contact_id'] === $a[2]))) {
                        $this->subs[$id]['status'] = 'unsubscribed';
                        $this->subs[$id]['next_send_at'] = null;
                    }
                }
            }
            return 1;
        }
    };
    $db = $GLOBALS['wpdb'];
    $row = static fn(int $id, int $seq, int $contact, string $email) => ['id' => $id, 'sequence_id' => $seq, 'contact_id' => $contact, 'email' => $email, 'status' => 'active', 'next_send_at' => '2026-10-10 00:00:00', 'current_email_id' => 1, 'emails_sent' => 0];
    $db->subs = [
        1 => $row(1, 10, 0, 'reader@example.com'),
        2 => $row(2, 11, 0, 'reader@example.com'),   // same person, other sequence
        3 => $row(3, 10, 7, ''),                       // contact-based subscriber
        4 => $row(4, 10, 0, 'other@example.com'),
    ];

    require dirname(__DIR__, 2) . '/modules/sequences/class-sequences-module.php';
    $module = new PeanutSuite\Sequences\Sequences_Module();
    $fail = static function (string $why): void { fwrite(STDERR, "FAIL: $why\n"); exit(1); };
    $hit = static function (array $get, string $method = 'GET'): Died {
        $_GET = $get; $_SERVER['REQUEST_METHOD'] = $method;
        try { do_action('template_redirect'); } catch (Died $d) { return $d; }
        throw new RuntimeException('no unsubscribe handler responded (template_redirect)');
    };
    $tok = static fn(int $sid, string $to) => PeanutSuite\Sequences\Sequences_Module::unsubscribe_token($sid, $to);

    // 0. The link already in every sent e-mail (wp_hash(id . email)) must work.
    //    Links already mailed before this fix (wp_hash(id . email)) still work.
    $db->subs[5] = $row(5, 10, 0, 'legacy@example.com');
    try { $d = $hit(['peanut_unsubscribe' => 1, 'sid' => 5, 'token' => wp_hash('5legacy@example.com')], 'POST'); } catch (RuntimeException $e) { $fail($e->getMessage()); }
    if ($db->subs[5]['status'] !== 'unsubscribed') { $fail('legacy unsubscribe links must be honored'); }

    // 1. A link with a forged / other subscriber's token is refused, nothing changes.
    try { $d = $hit(['peanut_unsubscribe' => 1, 'sid' => 4, 'token' => $tok(1, 'reader@example.com')], 'POST'); }
    catch (RuntimeException $e) { $fail($e->getMessage()); }
    if ($d->status !== 400 || $db->subs[4]['status'] !== 'active') { $fail('a token for another subscriber must be refused'); }
    $d = $hit(['peanut_unsubscribe' => 1, 'sid' => 999, 'token' => str_repeat('a', 64)], 'POST');
    if ($d->status !== 400) { $fail('an unknown subscriber must get the same 400'); }

    // 2. GET shows a confirmation (prefetch-safe) and does not unsubscribe.
    $d = $hit(['peanut_unsubscribe' => 1, 'sid' => 1, 'token' => $tok(1, 'reader@example.com')]);
    if ($d->status !== 200 || !str_contains($d->html, 'method="post"') || $db->subs[1]['status'] !== 'active') { $fail('GET must show a confirm form and not unsubscribe'); }

    // 3. POST unsubscribes this and every other active enrollment for the address.
    $d = $hit(['peanut_unsubscribe' => 1, 'sid' => 1, 'token' => $tok(1, 'reader@example.com')], 'POST');
    if ($d->status !== 200 || $db->subs[1]['status'] !== 'unsubscribed' || $db->subs[2]['status'] !== 'unsubscribed') { $fail('POST must unsubscribe the address from all sequences: ' . json_encode($db->subs)); }
    if ($db->subs[4]['status'] !== 'active') { $fail('other recipients must be untouched'); }

    // 4. Unsubscribed people are never re-enrolled.
    if ($module->enroll(12, 0, 'reader@example.com') !== false) { $fail('an unsubscribed address must not be re-enrolled'); }

    // 5. Contact-based subscriber (address resolved from the contact).
    $d = $hit(['peanut_unsubscribe' => 1, 'sid' => 3, 'token' => $tok(3, 'contact@example.com')], 'POST');
    if ($db->subs[3]['status'] !== 'unsubscribed') { $fail('a contact-based subscriber must be able to unsubscribe'); }
    if ($module->enroll(13, 7) !== false) { $fail('an unsubscribed contact must not be re-enrolled'); }

    // 7. Outgoing mail carries the signed link and one-click headers.
    $db->subs[6] = $row(6, 10, 0, 'new@example.com');
    $send = new ReflectionMethod($module, 'send_sequence_email');
    $send->invoke($module, 'new@example.com', (object) ['subject' => 'Hi', 'body' => 'Body', 'sequence_id' => 10, 'id' => 1], (object) $db->subs[6]);
    $mail = end($GLOBALS['mail']);
    if (!str_contains($mail['html'], 'token=' . $tok(6, 'new@example.com'))) { $fail('mail must link the signed unsubscribe URL'); }
    if (!in_array('List-Unsubscribe-Post: List-Unsubscribe=One-Click', $mail['headers'], true)) { $fail('mail must send List-Unsubscribe-Post'); }

    echo "sequence unsubscribe checks passed\n";
}
