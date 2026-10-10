<?php
/**
 * Inbound webhooks verified a signature but had no replay protection, and the
 * webhook source (which picks the secret and the handlers) came from request
 * input (X-Webhook-Source header or the body "source" field).
 *
 *  - A captured signed delivery could be re-posted forever: no timestamp
 *    tolerance and no dedupe on POST /webhooks/receive or POST
 *    /formflow/event; POST /webhooks/stripe checked Stripe's t= window but
 *    accepted the same event any number of times inside it.
 *  - An unsigned X-Webhook-Source header could relabel a body-signed payload.
 *
 * Contract pinned here:
 *  - signed deliveries must carry a SIGNED timestamp ("<ts>.<body>" with
 *    X-Peanut-Timestamp, or a "timestamp" field inside the signed body) within
 *    300 s of the server clock; stale, future and missing -> 401;
 *  - each signed delivery is accepted once: a replay gets 200 {duplicate}
 *    (receive, Stripe) or 409 (FormFlow) and is not stored or handled again;
 *  - POST /webhooks/receive/{source} binds the source to the route; a request
 *    naming another source is refused, and a signature made for one source
 *    does not verify on another source's route;
 *  - the deprecated POST /webhooks/receive refuses a header/body source
 *    disagreement;
 *  - unsigned sources (no secret) are refused and record no claim.
 *
 * SELF-CONTAINED: standalone WordPress mocks plus guarded shims (same pattern
 * as the sibling Regression tests).
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
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('PEANUT_PLUGIN_BASENAME')) {
        define('PEANUT_PLUGIN_BASENAME', 'peanut-suite/peanut-suite.php');
    }
    if (!function_exists('register_rest_route')) {
        function register_rest_route($namespace, $route, $args = [], $override = false) {
            $GLOBALS['__peanut_test_routes'][] = ['route' => '/' . $namespace . $route, 'args' => $args];
            return true;
        }
    }
    if (!function_exists('wp_parse_args')) {
        function wp_parse_args($args, $defaults = []) {
            return array_merge((array) $defaults, (array) $args);
        }
    }
    if (!function_exists('register_activation_hook')) {
        function register_activation_hook($file, $callback) {}
    }
    if (!function_exists('peanut_log_error')) {
        function peanut_log_error($message, $level = 'error', $context = '') {}
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
    use Peanut_Webhook_Replay_Guard;
    use Webhooks_Signature;

    /** $wpdb double: emulates the options UNIQUE key for replay claims. */
    class WebhookReplayFakeWpdb
    {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public int $insert_id = 0;
        public array $inserts = [];
        public array $updates = [];
        /** @var array<string, int> option_name => expiry */
        public array $claims = [];

        public function prepare($query, ...$args) { return $query . "\0" . json_encode($args); }
        public function esc_like($text) { return addcslashes((string) $text, '_%\\'); }
        public function get_col($sql) { return []; }
        public function get_var($sql) { return null; }
        public function get_row($sql, $output = null) { return null; }
        public function get_results($sql, $output = null) { return []; }
        public function insert($table, $data) {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            $this->insert_id = count($this->inserts);
            return 1;
        }
        public function update($table, $data, $where, $format = null, $where_format = null) {
            $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
            return 1;
        }
        public function query($sql) {
            [$query, $args] = array_pad(explode("\0", (string) $sql, 2), 2, '[]');
            $args = json_decode($args, true) ?: [];
            if (str_starts_with($query, 'INSERT IGNORE INTO wp_options')) {
                if (isset($this->claims[$args[0]])) {
                    return 0;
                }
                $this->claims[$args[0]] = (int) $args[1];
                return 1;
            }
            if (str_starts_with($query, 'DELETE FROM wp_options WHERE option_name = ')) {
                $had = isset($this->claims[$args[0]]);
                unset($this->claims[$args[0]]);
                return $had ? 1 : 0;
            }
            if (str_starts_with($query, 'DELETE FROM wp_options WHERE option_name LIKE')) {
                $before = count($this->claims);
                $this->claims = array_filter($this->claims, static fn(int $expiry) => $expiry >= (int) ($args[1] ?? PHP_INT_MAX));
                return $before - count($this->claims);
            }
            return 0;
        }
    }

    final class WebhookReplayProtectionRegressionTest extends TestCase
    {
        private const SECRET = 'route-bound-secret-0123456789abcdef';

        public static function setUpBeforeClass(): void
        {
            $root = dirname(__DIR__, 2);
            require_once $root . '/core/services/class-peanut-encryption.php';
            require_once $root . '/core/services/class-peanut-security.php';
            require_once $root . '/core/services/class-peanut-webhook-replay-guard.php';
            require_once $root . '/core/api/class-peanut-rest-controller.php';
            require_once $root . '/modules/webhooks/class-webhooks-database.php';
            require_once $root . '/modules/webhooks/class-webhooks-processor.php';
            require_once $root . '/modules/webhooks/class-webhooks-signature.php';
            require_once $root . '/modules/webhooks/api/class-webhooks-controller.php';
            require_once $root . '/modules/formflow/class-formflow-module.php';
            require_once $root . '/modules/formflow/api/class-formflow-controller.php';
            require_once $root . '/modules/invoicing/class-invoicing-module.php';
        }

        private array $serverBackup = [];
        private WebhookReplayFakeWpdb $wpdb;
        private $wpdbBackup = null;

        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_transients'] = [];
            $GLOBALS['mock_filter_overrides'] = [];
            $GLOBALS['__peanut_test_routes'] = [];
            $this->serverBackup = $_SERVER;
            $this->wpdbBackup = $GLOBALS['wpdb'] ?? null;
            $this->wpdb = $GLOBALS['wpdb'] = new WebhookReplayFakeWpdb();
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->serverBackup;
            $GLOBALS['wpdb'] = $this->wpdbBackup;
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_filter_overrides'] = [];
            unset($GLOBALS['__peanut_test_routes']);
            parent::tearDown();
        }

        // -- valid ---------------------------------------------------------------

        public function test_valid_timestamped_signature_is_accepted_on_the_source_route(): void
        {
            Webhooks_Signature::set_secret('zapier', self::SECRET);
            $body = json_encode(['event' => 'lead.created', 'email' => 'a@example.com']);
            $this->signTimestamped($body, time());

            $result = $this->receiveFor('zapier', $body);

            $this->assertAccepted($result);
            $this->assertSame('zapier', $this->stored()[0]['data']['source']);
        }

        public function test_valid_body_signed_timestamp_is_accepted_formflow_lite_format(): void
        {
            // FormFlow Lite: bare-hex HMAC of the body, body timestamp = current_time('c').
            Webhooks_Signature::set_secret('formflow-lite', self::SECRET);
            $body = json_encode(['event' => 'form.viewed', 'timestamp' => date('c'), 'source' => 'formflow-lite']);
            $_SERVER['HTTP_X_FFFL_SIGNATURE'] = hash_hmac('sha256', $body, self::SECRET);

            $this->assertAccepted($this->receiveFor('formflow-lite', $body));
        }

        // -- stale / future / missing -------------------------------------------

        public function test_stale_timestamp_is_rejected_and_nothing_is_stored(): void
        {
            Webhooks_Signature::set_secret('zapier', self::SECRET);
            $body = json_encode(['event' => 'x']);
            $this->signTimestamped($body, time() - 301);

            $result = $this->receiveFor('zapier', $body);

            $this->assertRejected($result, 401, 'stale_timestamp');
            $this->assertSame([], $this->stored());
        }

        public function test_stale_body_timestamp_is_rejected(): void
        {
            Webhooks_Signature::set_secret('formflow-lite', self::SECRET);
            $body = json_encode(['event' => 'x', 'timestamp' => gmdate('c', time() - 3600)]);
            $_SERVER['HTTP_X_FFFL_SIGNATURE'] = hash_hmac('sha256', $body, self::SECRET);

            $this->assertRejected($this->receiveFor('formflow-lite', $body), 401, 'stale_timestamp');
        }

        public function test_future_timestamp_is_rejected(): void
        {
            Webhooks_Signature::set_secret('zapier', self::SECRET);
            $body = json_encode(['event' => 'x']);
            $this->signTimestamped($body, time() + 301);

            $this->assertRejected($this->receiveFor('zapier', $body), 401, 'future_timestamp');
            $this->assertSame([], $this->stored());
        }

        public function test_a_signature_without_a_signed_timestamp_is_rejected(): void
        {
            Webhooks_Signature::set_secret('zapier', self::SECRET);
            $body = json_encode(['event' => 'x']);
            $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);

            $this->assertRejected($this->receiveFor('zapier', $body), 401, 'missing_timestamp');
        }

        public function test_an_unsigned_timestamp_header_cannot_freshen_an_old_body_signature(): void
        {
            // Body-only signature over a stale body; attacker adds a fresh
            // X-Peanut-Timestamp. The header is not covered, so it is ignored.
            Webhooks_Signature::set_secret('zapier', self::SECRET);
            $body = json_encode(['event' => 'x', 'timestamp' => time() - 3600]);
            $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);
            $_SERVER['HTTP_X_PEANUT_TIMESTAMP'] = (string) time();

            $this->assertRejected($this->receiveFor('zapier', $body), 401, 'stale_timestamp');
        }

        public function test_tolerance_is_filterable_and_clamped(): void
        {
            $GLOBALS['mock_filter_overrides']['peanut_webhook_timestamp_tolerance'] = 60;
            $this->assertSame(60, Peanut_Webhook_Replay_Guard::tolerance());
            $this->assertSame(Peanut_Webhook_Replay_Guard::STALE, Peanut_Webhook_Replay_Guard::check_timestamp(1000, 1061));

            $GLOBALS['mock_filter_overrides']['peanut_webhook_timestamp_tolerance'] = 0;
            $this->assertSame(30, Peanut_Webhook_Replay_Guard::tolerance(), 'A zero window would reject everything.');

            $GLOBALS['mock_filter_overrides']['peanut_webhook_timestamp_tolerance'] = 999999;
            $this->assertSame(3600, Peanut_Webhook_Replay_Guard::tolerance());
        }

        public function test_timestamp_parsing(): void
        {
            $this->assertSame(1767225600, Peanut_Webhook_Replay_Guard::parse_timestamp(1767225600));
            $this->assertSame(1767225600, Peanut_Webhook_Replay_Guard::parse_timestamp('1767225600'));
            $this->assertSame(1767225600, Peanut_Webhook_Replay_Guard::parse_timestamp(1767225600123), 'milliseconds');
            $this->assertSame(1767225600, Peanut_Webhook_Replay_Guard::parse_timestamp('2025-12-31T19:00:00-05:00'));
            $this->assertNull(Peanut_Webhook_Replay_Guard::parse_timestamp('now'), 'Relative strtotime phrases are refused.');
            $this->assertNull(Peanut_Webhook_Replay_Guard::parse_timestamp('+1 year'));
            $this->assertNull(Peanut_Webhook_Replay_Guard::parse_timestamp(null));
            $this->assertNull(Peanut_Webhook_Replay_Guard::parse_timestamp(['x']));
        }

        // -- replay --------------------------------------------------------------

        public function test_a_replayed_delivery_is_acknowledged_but_not_stored_or_handled_again(): void
        {
            Webhooks_Signature::set_secret('zapier', self::SECRET);
            $body = json_encode(['event' => 'x']);
            $this->signTimestamped($body, time());

            $this->assertAccepted($this->receiveFor('zapier', $body));
            $replay = $this->receiveFor('zapier', $body);

            $this->assertInstanceOf(\WP_REST_Response::class, $replay);
            $this->assertSame(200, $replay->status);
            $this->assertTrue($replay->data['data']['duplicate']);
            $this->assertCount(1, $this->stored(), 'The replay must not be stored (or dispatched) a second time.');
        }

        public function test_a_replay_on_the_legacy_route_is_also_caught(): void
        {
            Webhooks_Signature::set_secret('zapier', self::SECRET);
            $body = json_encode(['source' => 'zapier', 'event' => 'x', 'timestamp' => time()]);
            $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, self::SECRET);

            $this->assertAccepted($this->receiveLegacy($body));
            $this->assertSame(200, $this->receiveLegacy($body)->status);
            $this->assertCount(1, $this->stored());
        }

        public function test_a_failed_store_releases_the_claim_so_the_sender_can_retry(): void
        {
            Webhooks_Signature::set_secret('zapier', self::SECRET);
            $body = json_encode(['event' => 'x']);
            $this->signTimestamped($body, time());

            $this->wpdb = $GLOBALS['wpdb'] = new class extends WebhookReplayFakeWpdb {
                public function insert($table, $data) { return false; }
            };
            $this->assertRejected($this->receiveFor('zapier', $body), 500, 'storage_failed');
            $this->assertSame([], $this->wpdb->claims);
        }

        public function test_a_claim_that_cannot_be_recorded_is_a_retryable_503_not_an_ack(): void
        {
            Webhooks_Signature::set_secret('zapier', self::SECRET);
            $body = json_encode(['event' => 'x']);
            $this->signTimestamped($body, time());

            $this->wpdb = $GLOBALS['wpdb'] = new class extends WebhookReplayFakeWpdb {
                public function query($sql) { return false; }
            };

            $this->assertRejected($this->receiveFor('zapier', $body), 503, 'replay_check_unavailable');
            $this->assertSame([], $this->stored());

            update_option('fffl_peanut_webhook_secret', self::SECRET);
            $ff = json_encode(['event' => 'x', 'timestamp' => date('c')]);
            $result = (new \FormFlow_Controller())->verify_request($this->formflowRequest($ff, hash_hmac('sha256', $ff, self::SECRET)));
            $this->assertSame(503, $result->get_error_data()['status'] ?? null);
        }

        public function test_unsigned_sources_are_refused_without_a_claim(): void
        {
            $body = json_encode(['event' => 'x']);

            $this->assertRejected($this->receiveFor('custom', $body), 401, 'signature_required');
            $this->assertSame([], $this->stored());
            $this->assertSame([], $this->wpdb->claims, 'A refused unsigned delivery records no replay claim.');
        }

        // -- source binding --------------------------------------------------------

        public function test_the_per_source_route_is_registered_and_the_source_comes_from_the_path(): void
        {
            (new \Webhooks_Controller())->register_routes();
            $routes = array_column($GLOBALS['__peanut_test_routes'], 'args', 'route');

            $route = '/peanut/v1/webhooks/receive/(?P<source>[A-Za-z0-9._-]{1,64})';
            $this->assertArrayHasKey($route, $routes);
            $this->assertSame('receive_source_webhook', $routes[$route]['callback'][1]);
            $this->assertArrayHasKey('/peanut/v1/webhooks/receive', $routes, 'Legacy route kept for existing senders.');
        }

        public function test_a_body_naming_another_source_is_refused_on_a_source_route(): void
        {
            // Unsigned route "custom"; the body (signed for formflow-lite)
            // says formflow-lite. Re-posting it under another source is refused.
            Webhooks_Signature::set_secret('formflow-lite', self::SECRET);
            $body = json_encode(['source' => 'formflow-lite', 'event' => 'enrollment.completed', 'timestamp' => date('c')]);
            $_SERVER['HTTP_X_FFFL_SIGNATURE'] = hash_hmac('sha256', $body, self::SECRET);

            $this->assertRejected($this->receiveFor('custom', $body), 400, 'source_mismatch');
            $this->assertSame([], $this->stored());
        }

        public function test_a_header_naming_another_source_is_refused_on_a_source_route(): void
        {
            $_SERVER['HTTP_X_WEBHOOK_SOURCE'] = 'formflow';

            $this->assertRejected($this->receiveFor('custom', json_encode(['event' => 'x'])), 400, 'source_mismatch');
        }

        public function test_a_signature_for_one_source_does_not_verify_on_another_sources_route(): void
        {
            Webhooks_Signature::set_secret('alpha', self::SECRET);
            Webhooks_Signature::set_secret('beta', 'beta-secret-0123456789abcdef0000');
            $body = json_encode(['event' => 'x']);
            $this->signTimestamped($body, time()); // signed with alpha's secret

            $this->assertRejected($this->receiveFor('beta', $body), 401, 'invalid_signature');
            $this->assertAccepted($this->receiveFor('alpha', $body));
        }

        public function test_an_invalid_route_source_is_refused(): void
        {
            $this->assertRejected($this->receiveFor('../evil', json_encode(['event' => 'x'])), 400, 'invalid_source');
        }

        public function test_the_legacy_route_refuses_a_header_that_relabels_a_signed_body(): void
        {
            $_SERVER['HTTP_X_WEBHOOK_SOURCE'] = 'formflow';
            $body = json_encode(['source' => 'formflow-lite', 'event' => 'x', 'timestamp' => time()]);

            $this->assertRejected($this->receiveLegacy($body), 400, 'source_mismatch');
        }

        public function test_signing_status_advertises_the_per_source_endpoint(): void
        {
            $data = (new \Webhooks_Controller())->get_signing_status(new \WP_REST_Request([]))->data['data'];

            $this->assertStringEndsWith('/webhooks/receive/{source}', $data['source_endpoint_url']);
            $this->assertSame(300, $data['timestamp_tolerance']);
        }

        // -- FormFlow /formflow/event ---------------------------------------------

        public function test_formflow_event_rejects_stale_and_future_and_missing_timestamps(): void
        {
            update_option('fffl_peanut_webhook_secret', self::SECRET);

            foreach ([
                'stale_timestamp' => ['event' => 'x', 'timestamp' => gmdate('c', time() - 301)],
                'future_timestamp' => ['event' => 'x', 'timestamp' => gmdate('c', time() + 301)],
                'missing_timestamp' => ['event' => 'x'],
            ] as $code => $payload) {
                $body = json_encode($payload);
                $result = (new \FormFlow_Controller())->verify_request($this->formflowRequest($body, hash_hmac('sha256', $body, self::SECRET)));

                $this->assertInstanceOf(\WP_Error::class, $result, $code);
                $this->assertSame($code, $result->get_error_code());
                $this->assertSame(401, $result->get_error_data()['status'] ?? null);
            }
        }

        public function test_formflow_event_accepts_a_delivery_once(): void
        {
            update_option('fffl_peanut_webhook_secret', self::SECRET);
            $body = json_encode(['event' => 'enrollment.completed', 'timestamp' => date('c')]);
            $request = $this->formflowRequest($body, hash_hmac('sha256', $body, self::SECRET));

            $this->assertTrue((new \FormFlow_Controller())->verify_request($request));

            $replay = (new \FormFlow_Controller())->verify_request($request);
            $this->assertInstanceOf(\WP_Error::class, $replay);
            $this->assertSame('duplicate_delivery', $replay->get_error_code());
            $this->assertSame(409, $replay->get_error_data()['status'] ?? null);
        }

        // -- Stripe /webhooks/stripe ----------------------------------------------

        public function test_stripe_event_is_handled_once_and_a_replay_is_acknowledged(): void
        {
            update_option('peanut_settings', ['stripe_webhook_secret' => 'whsec_test_0123456789']);
            $body = json_encode(['id' => 'evt_1', 'type' => 'invoice.sent', 'data' => ['object' => ['id' => 'in_1']]]);
            $t = time();
            $request = $this->stripeRequest($body, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, 'whsec_test_0123456789'));

            $module = new \Invoicing_Module();
            $first = $module->handle_stripe_webhook($request);
            $second = $module->handle_stripe_webhook($request);

            $this->assertSame(200, $first->status);
            $this->assertArrayNotHasKey('duplicate', $first->data);
            $this->assertSame(200, $second->status);
            $this->assertTrue($second->data['duplicate']);
            $this->assertCount(1, $this->wpdb->updates, 'The replayed event must not be applied twice.');
        }

        public function test_stripe_stale_timestamp_is_still_rejected(): void
        {
            update_option('peanut_settings', ['stripe_webhook_secret' => 'whsec_test_0123456789']);
            $body = json_encode(['id' => 'evt_2', 'type' => 'invoice.sent', 'data' => ['object' => ['id' => 'in_1']]]);
            $t = time() - 301;
            $request = $this->stripeRequest($body, 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, 'whsec_test_0123456789'));

            $this->assertSame(400, (new \Invoicing_Module())->handle_stripe_webhook($request)->status);
            $this->assertSame([], $this->wpdb->claims);
        }

        public function test_stripe_missing_signature_header_is_a_400_not_a_fatal(): void
        {
            update_option('peanut_settings', ['stripe_webhook_secret' => 'whsec_test_0123456789']);

            $this->assertSame(400, (new \Invoicing_Module())->handle_stripe_webhook($this->stripeRequest('{}', null))->status);
        }

        // -- guard -----------------------------------------------------------------

        public function test_purge_removes_only_expired_claims(): void
        {
            $this->assertTrue(Peanut_Webhook_Replay_Guard::claim('s', 'old', 10, 1000));
            $this->assertTrue(Peanut_Webhook_Replay_Guard::claim('s', 'new', 10, 5000));

            // claim() also purges opportunistically (1 in 50), so assert the
            // resulting state, not the count from this call.
            Peanut_Webhook_Replay_Guard::purge_expired(2000);
            $this->assertArrayNotHasKey(Peanut_Webhook_Replay_Guard::key('s', 'old'), $this->wpdb->claims);
            $this->assertArrayHasKey(Peanut_Webhook_Replay_Guard::key('s', 'new'), $this->wpdb->claims);
        }

        public function test_claims_are_scoped(): void
        {
            $this->assertTrue(Peanut_Webhook_Replay_Guard::claim('receive:a', 'id'));
            $this->assertTrue(Peanut_Webhook_Replay_Guard::claim('receive:b', 'id'));
            $this->assertFalse(Peanut_Webhook_Replay_Guard::claim('receive:a', 'id'));
        }

        // -- helpers ---------------------------------------------------------------

        private function signTimestamped(string $body, int $timestamp): void
        {
            $_SERVER['HTTP_X_PEANUT_TIMESTAMP'] = (string) $timestamp;
            $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, self::SECRET);
        }

        private function receiveFor(string $source, string $body)
        {
            return (new \Webhooks_Controller())->receive_source_webhook($this->request($body, ['source' => $source]));
        }

        private function receiveLegacy(string $body)
        {
            return (new \Webhooks_Controller())->receive_webhook($this->request($body, []));
        }

        private function request(string $body, array $params): \WP_REST_Request
        {
            return new class($body, $params) extends \WP_REST_Request {
                public function __construct(private string $body, private array $params) { parent::__construct([]); }
                public function get_body() { return $this->body; }
                public function get_json_params() { return json_decode($this->body, true); }
                public function get_param($key) { return $this->params[$key] ?? null; }
            };
        }

        private function formflowRequest(string $body, string $signature): \WP_REST_Request
        {
            return new class($body, $signature) extends \WP_REST_Request {
                public function __construct(private string $body, string $signature) { parent::__construct(['X-FFFL-Signature' => $signature]); }
                public function get_body() { return $this->body; }
            };
        }

        private function stripeRequest(string $body, ?string $signature): \WP_REST_Request
        {
            return new class($body, $signature) extends \WP_REST_Request {
                public function __construct(private string $body, ?string $signature) { parent::__construct($signature === null ? [] : ['Stripe-Signature' => $signature]); }
                public function get_body() { return $this->body; }
            };
        }

        private function stored(): array
        {
            return array_values(array_filter($this->wpdb->inserts, static fn($i) => str_ends_with($i['table'], 'webhooks_received')));
        }

        private function assertAccepted($result): void
        {
            $this->assertInstanceOf(\WP_REST_Response::class, $result, $result instanceof \WP_Error ? $result->get_error_code() : '');
            $this->assertSame(202, $result->status);
        }

        private function assertRejected($result, int $status, string $code): void
        {
            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame($code, $result->get_error_code());
            $this->assertSame($status, $result->get_error_data()['status'] ?? null);
        }
    }
}
