<?php
/**
 * Webhook signing secrets could not be configured: nothing called
 * Webhooks_Signature::set_secret() and there was no UI, so /webhooks/receive
 * stayed an unsigned open ingest on every site that had not hand-edited the
 * peanut_webhook_secrets option. Secrets that were set were stored in
 * plaintext, and the headers FormFlow actually signs with (X-FFFL-Signature,
 * X-ISF-Signature) were never read, so a configured FormFlow secret would
 * have rejected every genuine FormFlow webhook.
 *
 * Contract pinned here:
 *  - admin-only (manage_options + wp_rest nonce) REST routes list per-source
 *    signing status, set/rotate (generate or paste) and clear a secret;
 *  - a generated secret is returned exactly once and never re-displayed;
 *    no listing ever contains a secret or its ciphertext;
 *  - secrets are stored as Peanut_Encryption V2; legacy plaintext still
 *    verifies and is encrypted by a flag-gated one-time upgrade;
 *  - a configured secret that cannot be decrypted fails CLOSED (401), never
 *    open;
 *  - FormFlow Lite / FormFlow signature headers verify;
 *  - sources with no secret are still accepted unsigned (unchanged product
 *    behavior), and the status lists which sources are unsigned.
 *
 * SELF-CONTAINED: pure PHP against the standalone WordPress mocks plus the
 * guarded shims below (same semantics as the sibling Regression tests).
 *
 * @package Peanut_Suite
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    if (!defined('PEANUT_API_NAMESPACE')) {
        define('PEANUT_API_NAMESPACE', 'peanut/v1');
    }
    if (!defined('PEANUT_TABLE_PREFIX')) {
        define('PEANUT_TABLE_PREFIX', 'peanut_');
    }
    if (!function_exists('register_rest_route')) {
        function register_rest_route($namespace, $route, $args = [], $override = false) {
            $GLOBALS['__peanut_test_routes'][] = ['route' => '/' . $namespace . $route, 'args' => $args];
            return true;
        }
    }
    if (!function_exists('is_user_logged_in')) {
        function is_user_logged_in(): bool { return (bool) ($GLOBALS['__peanut_test_logged_in'] ?? false); }
    }
    if (!function_exists('wp_verify_nonce')) {
        function wp_verify_nonce($nonce, $action = -1) { return $nonce === 'valid-nonce' ? 1 : false; }
    }
    if (!function_exists('current_user_can')) {
        function current_user_can($cap, ...$args): bool {
            return in_array($cap, $GLOBALS['__peanut_test_caps'] ?? [], true);
        }
    }
    if (!function_exists('rest_url')) {
        function rest_url($path = '') {
            return 'https://suite.example/wp-json/' . ltrim((string) $path, '/');
        }
    }
    if (!class_exists('WP_REST_Server')) {
        class WP_REST_Server {
            const READABLE = 'GET';
            const CREATABLE = 'POST';
            const EDITABLE = 'POST, PUT, PATCH';
            const DELETABLE = 'DELETE';
            const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
        }
    }
    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request {
            private array $headers;
            public function __construct(array $headers = []) { $this->headers = $headers; }
            public function get_header($key) { return $this->headers[$key] ?? null; }
            public function get_param($key) { return null; }
        }
    }
    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response {
            public $data; public int $status;
            public function __construct($data = null, int $status = 200) { $this->data = $data; $this->status = $status; }
            public function get_data() { return $this->data; }
            public function get_status() { return $this->status; }
        }
    }
}

namespace PeanutSuite\Tests\Regression {

    use PHPUnit\Framework\TestCase;
    use Peanut_Encryption;
    use Webhooks_Signature;

    /** $wpdb double: DISTINCT sources from a fixed list; records inserts. */
    final class WebhookSigningFakeWpdb
    {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public array $inserts = [];
        public array $sources = [];

        public string $options = 'wp_options';
        /** Replay-guard claims (option names) recorded by INSERT IGNORE. */
        public array $claims = [];

        public function prepare($query, ...$args) { return $query . "\0" . json_encode($args); }
        public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }
        public function get_col($sql) { return str_contains($sql, 'DISTINCT source') ? $this->sources : []; }
        public function get_var($sql) { return null; }
        public function get_row($sql, $output = null) { return null; }
        public function get_results($sql, $output = null) { return []; }
        public function insert($table, $data) {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            $this->insert_id = count($this->inserts);
            return 1;
        }
        public function update($table, $data, $where, $format = null, $where_format = null) { return 1; }
        public function query($sql) {
            [$query, $args] = array_pad(explode("\0", (string) $sql, 2), 2, '[]');
            $args = json_decode($args, true) ?: [];
            if (str_starts_with($query, 'INSERT IGNORE INTO wp_options')) {
                if (isset($this->claims[$args[0]])) {
                    return 0;
                }
                $this->claims[$args[0]] = true;
                return 1;
            }
            if (str_starts_with($query, 'DELETE FROM wp_options WHERE option_name = ')) {
                unset($this->claims[$args[0]]);
                return 1;
            }
            return 0;
        }
    }

    final class WebhookSigningSecretsRegressionTest extends TestCase
    {
        private const ADMIN = ['read', 'manage_options'];
        private const SUBSCRIBER = ['read'];

        public static function setUpBeforeClass(): void
        {
            $root = dirname(__DIR__, 2);
            require_once $root . '/core/services/class-peanut-encryption.php';
            require_once $root . '/core/services/class-peanut-security.php';
            require_once $root . '/core/api/class-peanut-rest-controller.php';
            require_once $root . '/modules/webhooks/class-webhooks-database.php';
            require_once $root . '/modules/webhooks/class-webhooks-processor.php';
            require_once $root . '/modules/webhooks/class-webhooks-signature.php';
            require_once $root . '/modules/webhooks/api/class-webhooks-controller.php';
        }

        private array $serverBackup = [];
        private WebhookSigningFakeWpdb $wpdb;
        private $wpdbBackup = null;

        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_transients'] = [];
            $GLOBALS['__peanut_test_routes'] = [];
            $this->serverBackup = $_SERVER;
            $this->wpdbBackup = $GLOBALS['wpdb'] ?? null;
            $this->wpdb = $GLOBALS['wpdb'] = new WebhookSigningFakeWpdb();
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->serverBackup;
            $GLOBALS['wpdb'] = $this->wpdbBackup;
            $GLOBALS['mock_options'] = [];
            unset($GLOBALS['__peanut_test_logged_in'], $GLOBALS['__peanut_test_caps'], $GLOBALS['__peanut_test_routes']);
            parent::tearDown();
        }

        // -- storage -------------------------------------------------------------

        public function test_set_secret_stores_v2_ciphertext_not_plaintext(): void
        {
            $this->assertTrue(Webhooks_Signature::set_secret('formflow-lite', 'a-long-shared-signing-secret-123'));

            $stored = get_option('peanut_webhook_secrets')['formflow-lite'];
            $this->assertTrue(Peanut_Encryption::is_v2($stored));
            $this->assertStringNotContainsString('a-long-shared-signing-secret-123', serialize(get_option('peanut_webhook_secrets')));
            $this->assertSame('a-long-shared-signing-secret-123', Webhooks_Signature::get_secret('formflow-lite'));
        }

        public function test_generated_secret_is_strong_and_random(): void
        {
            $a = Webhooks_Signature::generate_secret();
            $b = Webhooks_Signature::generate_secret();

            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a, '256 bits, hex, safe to paste into any sender.');
            $this->assertNotSame($a, $b);
        }

        public function test_invalid_source_names_and_short_secrets_are_refused(): void
        {
            $this->assertFalse(Webhooks_Signature::set_secret('bad source!', str_repeat('x', 32)));
            $this->assertFalse(Webhooks_Signature::set_secret('', str_repeat('x', 32)));
            $this->assertFalse(Webhooks_Signature::set_secret('stripe', 'short'));
            $this->assertFalse(get_option('peanut_webhook_secrets'));
        }

        // -- REST: authorization -------------------------------------------------

        /** @return array<string, array{0: string, 1: string}> */
        public static function signingRoutes(): array
        {
            return [
                'list' => ['/peanut/v1/webhooks/signing', 'GET'],
                'set' => ['/peanut/v1/webhooks/signing', 'POST'],
                'clear' => ['/peanut/v1/webhooks/signing/(?P<source>[A-Za-z0-9._-]{1,64})', 'DELETE'],
            ];
        }

        /** @dataProvider signingRoutes */
        public function test_signing_routes_deny_non_admins_and_missing_nonce(string $route, string $method): void
        {
            $permission = $this->permissionFor($route, $method);

            $GLOBALS['__peanut_test_logged_in'] = true;
            $GLOBALS['__peanut_test_caps'] = self::SUBSCRIBER;
            $this->assertNotTrue($permission(new \WP_REST_Request(['X-WP-Nonce' => 'valid-nonce'])), "$method $route: subscriber");

            $GLOBALS['__peanut_test_caps'] = self::ADMIN;
            $this->assertNotTrue($permission(new \WP_REST_Request([])), "$method $route: admin without nonce");
            $this->assertTrue($permission(new \WP_REST_Request(['X-WP-Nonce' => 'valid-nonce'])), "$method $route: admin with nonce");
        }

        // -- REST: set / rotate / clear -----------------------------------------

        public function test_generate_returns_the_secret_once_and_it_verifies(): void
        {
            $response = $this->controller()->set_signing_secret($this->request(['source' => 'formflow-lite', 'generate' => true]));

            $this->assertSame(200, $response->status);
            $secret = $response->get_data()['data']['secret'];
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $secret);
            $this->assertSame($secret, Webhooks_Signature::get_secret('formflow-lite'));

            // Never re-displayed: the listing carries status only.
            $listing = json_encode($this->controller()->get_signing_status($this->request([]))->get_data());
            $this->assertStringNotContainsString($secret, $listing);
            $this->assertStringNotContainsString(get_option('peanut_webhook_secrets')['formflow-lite'], $listing);
        }

        public function test_regenerating_rotates_the_secret(): void
        {
            $first = $this->controller()->set_signing_secret($this->request(['source' => 'stripe', 'generate' => true]))->get_data()['data']['secret'];
            $second = $this->controller()->set_signing_secret($this->request(['source' => 'stripe', 'generate' => true]))->get_data()['data']['secret'];

            $this->assertNotSame($first, $second);
            $this->assertSame($second, Webhooks_Signature::get_secret('stripe'));
        }

        public function test_a_pasted_secret_is_stored_and_not_echoed_back(): void
        {
            $pasted = 'sender-issued-secret-0123456789';
            $response = $this->controller()->set_signing_secret($this->request(['source' => 'custom', 'secret' => $pasted]));

            $this->assertSame(200, $response->status);
            $this->assertStringNotContainsString($pasted, json_encode($response->get_data()));
            $this->assertSame($pasted, Webhooks_Signature::get_secret('custom'));
        }

        public function test_set_rejects_a_bad_source_or_a_short_pasted_secret(): void
        {
            $bad = $this->controller()->set_signing_secret($this->request(['source' => '../evil', 'generate' => true]));
            $this->assertInstanceOf(\WP_Error::class, $bad);
            $this->assertSame(400, $bad->get_error_data()['status']);

            $short = $this->controller()->set_signing_secret($this->request(['source' => 'custom', 'secret' => 'abc']));
            $this->assertInstanceOf(\WP_Error::class, $short);
            $this->assertFalse(get_option('peanut_webhook_secrets'));
        }

        public function test_clear_removes_the_secret_and_the_source_is_unsigned_again(): void
        {
            Webhooks_Signature::set_secret('stripe', str_repeat('k', 40));

            $response = $this->controller()->delete_signing_secret($this->request(['source' => 'stripe']));

            $this->assertSame(200, $response->status);
            $this->assertFalse(Webhooks_Signature::has_secret('stripe'));
            $this->assertAccepted($this->receive(['source' => 'stripe', 'event' => 'x']));
        }

        // -- REST: status --------------------------------------------------------

        public function test_status_lists_seen_known_and_configured_sources_with_unsigned_flagged(): void
        {
            $this->wpdb->sources = ['formflow-lite', 'unknown', 'zapier'];
            Webhooks_Signature::set_secret('formflow-lite', str_repeat('s', 40));
            Webhooks_Signature::set_secret('stripe', str_repeat('t', 40));

            $data = $this->controller()->get_signing_status($this->request([]))->get_data()['data'];

            $bySource = array_column($data['sources'], null, 'source');
            $this->assertTrue($bySource['formflow-lite']['signed']);
            $this->assertTrue($bySource['formflow-lite']['seen']);
            $this->assertTrue($bySource['stripe']['signed']);
            $this->assertFalse($bySource['stripe']['seen']);
            $this->assertFalse($bySource['zapier']['signed']);
            $this->assertFalse($bySource['unknown']['signed']);
            $this->assertArrayHasKey('formflow', $bySource, 'Built-in senders are always listed.');
            $this->assertSame(['unknown', 'zapier'], $data['unsigned_seen_sources']);
            $this->assertStringEndsWith('/webhooks/receive', $data['endpoint_url']);
        }

        public function test_status_flags_an_undecryptable_secret(): void
        {
            Webhooks_Signature::set_secret('stripe', str_repeat('t', 40));
            $this->tamperStored('stripe');

            $bySource = array_column($this->controller()->get_signing_status($this->request([]))->get_data()['data']['sources'], null, 'source');

            $this->assertTrue($bySource['stripe']['signed']);
            $this->assertFalse($bySource['stripe']['readable']);
        }

        // -- receive -------------------------------------------------------------

        public function test_formflow_lite_signature_header_verifies(): void
        {
            Webhooks_Signature::set_secret('formflow-lite', 'formflow-shared-secret-abcdef');
            // FormFlow Lite signs a body that carries timestamp = current_time('c').
            $payload = ['source' => 'formflow-lite', 'event' => 'form.submitted', 'timestamp' => gmdate('c')];
            $_SERVER['HTTP_X_FFFL_SIGNATURE'] = hash_hmac('sha256', json_encode($payload), 'formflow-shared-secret-abcdef');

            $this->assertAccepted($this->receive($payload));
        }

        public function test_formflow_pro_signature_header_verifies(): void
        {
            // FormFlow Pro sends no source, so it arrives as "unknown".
            Webhooks_Signature::set_secret('unknown', 'formflow-pro-secret-abcdef');
            $payload = ['event' => 'form.submitted', 'data' => ['a' => 1], 'timestamp' => gmdate('c')];
            $_SERVER['HTTP_X_ISF_SIGNATURE'] = hash_hmac('sha256', json_encode($payload), 'formflow-pro-secret-abcdef');

            $this->assertAccepted($this->receive($payload));
        }

        public function test_wrong_secret_is_rejected(): void
        {
            Webhooks_Signature::set_secret('formflow-lite', 'formflow-shared-secret-abcdef');
            $payload = ['source' => 'formflow-lite', 'event' => 'x'];
            $_SERVER['HTTP_X_FFFL_SIGNATURE'] = hash_hmac('sha256', json_encode($payload), 'some-other-secret-000000');

            $this->assertRejected($this->receive($payload));
        }

        public function test_an_undecryptable_secret_fails_closed(): void
        {
            Webhooks_Signature::set_secret('stripe', 'stripe-shared-secret-abcdef');
            $this->tamperStored('stripe');
            $payload = ['source' => 'stripe', 'event' => 'x'];

            // Unsigned, and signed with the real secret: both rejected.
            $this->assertRejected($this->receive($payload));
            $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'stripe-shared-secret-abcdef');
            $this->assertRejected($this->receive($payload));
            $this->assertFalse(Webhooks_Signature::verify(json_encode($payload), 'anything', 'stripe'));
        }

        public function test_unsigned_sources_are_still_accepted(): void
        {
            // Unchanged product behavior: a source with no secret is accepted.
            $this->assertAccepted($this->receive(['source' => 'custom', 'event' => 'x']));
        }

        // -- legacy plaintext ----------------------------------------------------

        public function test_legacy_plaintext_secret_still_verifies(): void
        {
            update_option('peanut_webhook_secrets', ['stripe' => 'whsec_configured']);
            $payload = ['source' => 'stripe', 'event' => 'x', 'timestamp' => time()];
            $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', json_encode($payload), 'whsec_configured');

            $this->assertAccepted($this->receive($payload));
        }

        public function test_one_time_upgrade_encrypts_legacy_plaintext_and_is_flag_gated(): void
        {
            update_option('peanut_webhook_secrets', ['stripe' => 'whsec_configured', 'formflow-lite' => 'ff-plain']);

            $this->assertTrue(Webhooks_Signature::maybe_upgrade());

            $stored = get_option('peanut_webhook_secrets');
            $this->assertTrue(Peanut_Encryption::is_v2($stored['stripe']));
            $this->assertTrue(Peanut_Encryption::is_v2($stored['formflow-lite']));
            $this->assertSame('whsec_configured', Webhooks_Signature::get_secret('stripe'));
            $this->assertSame(Webhooks_Signature::UPGRADE_VERSION, get_option(Webhooks_Signature::UPGRADE_FLAG));

            $stored['stripe'] = 'plain-again';
            update_option('peanut_webhook_secrets', $stored);
            $this->assertFalse(Webhooks_Signature::maybe_upgrade());
            $this->assertSame('plain-again', get_option('peanut_webhook_secrets')['stripe']);
        }

        public function test_upgrade_is_wired_into_plugin_boot(): void
        {
            $main = (string) file_get_contents(dirname(__DIR__, 2) . '/peanut-suite.php');
            $this->assertMatchesRegularExpression('/Webhooks_Signature::maybe_upgrade\(\);/', $main);
        }

        // -- helpers -------------------------------------------------------------

        private function controller(): \Webhooks_Controller
        {
            return new \Webhooks_Controller();
        }

        private function request(array $params): \WP_REST_Request
        {
            return new class($params) extends \WP_REST_Request {
                private array $params;
                public function __construct(array $params) { parent::__construct(['X-WP-Nonce' => 'valid-nonce']); $this->params = $params; }
                public function get_param($key) { return $this->params[$key] ?? null; }
            };
        }

        private function receive(array $payload)
        {
            $request = new class($payload) extends \WP_REST_Request {
                private array $payload;
                public function __construct(array $payload) { parent::__construct([]); $this->payload = $payload; }
                public function get_body() { return json_encode($this->payload); }
                public function get_json_params() { return $this->payload; }
            };
            return $this->controller()->receive_webhook($request);
        }

        private function assertAccepted($result): void
        {
            $this->assertInstanceOf(\WP_REST_Response::class, $result);
            $this->assertSame(202, $result->status);
        }

        private function assertRejected($result): void
        {
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame(401, $result->get_error_data()['status'] ?? null);
        }

        private function tamperStored(string $source): void
        {
            $secrets = get_option('peanut_webhook_secrets');
            $raw = base64_decode(substr($secrets[$source], strlen(Peanut_Encryption::V2_PREFIX)), true);
            $raw[30] = chr(ord($raw[30]) ^ 0x01);
            $secrets[$source] = Peanut_Encryption::V2_PREFIX . base64_encode($raw);
            update_option('peanut_webhook_secrets', $secrets);
        }

        private function permissionFor(string $route, string $method): callable
        {
            $GLOBALS['__peanut_test_routes'] = [];
            $this->controller()->register_routes();

            foreach ($GLOBALS['__peanut_test_routes'] as $r) {
                if ($r['route'] !== $route) {
                    continue;
                }
                $handlers = isset($r['args']['methods']) ? [$r['args']] : array_values(array_filter($r['args'], 'is_array'));
                foreach ($handlers as $h) {
                    $methods = array_map('trim', explode(',', strtoupper((string) $h['methods'])));
                    if (in_array($method, $methods, true)) {
                        return $h['permission_callback'];
                    }
                }
            }
            $this->fail("Route $method $route is not registered.");
        }
    }
}
