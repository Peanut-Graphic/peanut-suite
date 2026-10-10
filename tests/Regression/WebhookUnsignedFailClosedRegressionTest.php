<?php
/**
 * POST /webhooks/receive/{source} (and the legacy /webhooks/receive) is a
 * public route, and checked a signature only when the source had a secret.
 * A source with no secret was accepted unsigned, so anyone who knew the URL
 * could post
 *   POST /peanut/v1/webhooks/receive/formflow-lite
 *   {"event":"enrollment.completed","customer":{"email":"victim@example.com"},...}
 * and Webhooks_Processor fired peanut_conversion and peanut_visitor_identify:
 * forged conversions, enrollments, attribution and visitor identities, around
 * the hardened POST /formflow/event (which already fails closed).
 *
 * Contract pinned here:
 *  - a source with no secret is refused (401 signature_required): nothing is
 *    stored and nothing is dispatched, on both routes;
 *  - the refusal is recorded (bounded, throttled) so an admin notice and the
 *    signing status name the sender that needs a secret; configuring the
 *    secret clears the warning;
 *  - formflow / formflow-lite can never be opted back in to unsigned; another
 *    source can, only through the peanut_webhook_allow_unsigned filter;
 *  - the admin "Send test" (source "test") still works for a signed-in
 *    administrator, and is refused for anyone else.
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
    if (!function_exists('admin_url')) {
        function admin_url($path = '') {
            return 'https://suite.example/wp-admin/' . ltrim((string) $path, '/');
        }
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!function_exists('wp_parse_args')) {
        function wp_parse_args($args, $defaults = []) {
            return array_merge((array) $defaults, (array) $args);
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
    use Webhooks_Signature;

    /** $wpdb double: records webhook inserts; replay claims via INSERT IGNORE. */
    final class UnsignedFailClosedFakeWpdb
    {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public int $insert_id = 0;
        public array $inserts = [];
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
            return 0;
        }
    }

    final class WebhookUnsignedFailClosedRegressionTest extends TestCase
    {
        private const SECRET = 'formflow-shared-secret-abcdef';

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
            require_once $root . '/modules/webhooks/class-webhooks-module.php';
        }

        private array $serverBackup = [];
        private UnsignedFailClosedFakeWpdb $wpdb;
        private $wpdbBackup = null;

        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_transients'] = [];
            $GLOBALS['mock_filter_overrides'] = [];
            $this->serverBackup = $_SERVER;
            unset($_SERVER['HTTP_X_WEBHOOK_SOURCE']);
            $this->wpdbBackup = $GLOBALS['wpdb'] ?? null;
            $this->wpdb = $GLOBALS['wpdb'] = new UnsignedFailClosedFakeWpdb();
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->serverBackup;
            $GLOBALS['wpdb'] = $this->wpdbBackup;
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_filter_overrides'] = [];
            unset($GLOBALS['__peanut_test_logged_in'], $GLOBALS['__peanut_test_caps']);
            parent::tearDown();
        }

        /** The exact forgery from the audit: an unsigned FormFlow enrollment. */
        private function forgedEnrollment(): array
        {
            return [
                'event' => 'enrollment.completed',
                'submission' => ['id' => 9, 'session_id' => 'visitor-abc'],
                'customer' => ['email' => 'victim@example.com', 'name' => 'Victim'],
                'attribution' => ['utm_source' => 'attacker'],
            ];
        }

        /** @return array<string, array{0: string}> */
        public static function formflowSources(): array
        {
            return ['formflow-lite' => ['formflow-lite'], 'formflow' => ['formflow']];
        }

        /** @dataProvider formflowSources */
        public function test_unsigned_formflow_enrollment_is_refused_and_never_stored(string $source): void
        {
            $result = $this->receiveFor($source, $this->forgedEnrollment());

            $this->assertRefusedUnsigned($result);
            $this->assertSame([], $this->wpdb->inserts, 'Nothing stored, so nothing dispatched.');
        }

        public function test_unsigned_formflow_on_the_legacy_route_is_refused(): void
        {
            $payload = ['source' => 'formflow-lite'] + $this->forgedEnrollment();

            $this->assertRefusedUnsigned($this->receiveLegacy($payload));
            $this->assertSame([], $this->wpdb->inserts);
        }

        public function test_any_source_without_a_secret_is_refused_by_default(): void
        {
            $this->assertRefusedUnsigned($this->receiveFor('zapier', ['event' => 'x']));
            $this->assertRefusedUnsigned($this->receiveLegacy(['event' => 'x']), 'unknown (FormFlow Pro) unsigned');
            $this->assertSame([], $this->wpdb->inserts);
        }

        public function test_verify_fails_closed_without_a_secret(): void
        {
            $this->assertFalse(Webhooks_Signature::verify('{}', '', 'formflow-lite'));
            $this->assertFalse(Webhooks_Signature::verify('{}', '', 'zapier'));
        }

        public function test_a_correctly_signed_formflow_enrollment_is_still_accepted(): void
        {
            Webhooks_Signature::set_secret('formflow-lite', self::SECRET);
            $payload = $this->forgedEnrollment() + ['timestamp' => gmdate('c')];
            $_SERVER['HTTP_X_FFFL_SIGNATURE'] = hash_hmac('sha256', json_encode($payload), self::SECRET);

            $result = $this->receiveFor('formflow-lite', $payload);

            $this->assertInstanceOf(\WP_REST_Response::class, $result);
            $this->assertSame(202, $result->status);
            $this->assertCount(1, $this->wpdb->inserts);
        }

        // -- opt-in filter ----------------------------------------------------------

        public function test_the_filter_can_opt_a_non_formflow_source_back_in(): void
        {
            $GLOBALS['mock_filter_overrides']['peanut_webhook_allow_unsigned'] = true;

            $result = $this->receiveFor('nocode-tool', ['event' => 'x']);

            $this->assertInstanceOf(\WP_REST_Response::class, $result);
            $this->assertSame(202, $result->status);
        }

        /** @dataProvider formflowSources */
        public function test_the_filter_can_never_opt_formflow_back_in(string $source): void
        {
            $GLOBALS['mock_filter_overrides']['peanut_webhook_allow_unsigned'] = true;

            $this->assertRefusedUnsigned($this->receiveFor($source, $this->forgedEnrollment()));
            $this->assertFalse(Webhooks_Signature::allows_unsigned($source));
            $this->assertSame([], $this->wpdb->inserts);
        }

        public function test_only_a_strict_true_from_the_filter_opts_in(): void
        {
            $GLOBALS['mock_filter_overrides']['peanut_webhook_allow_unsigned'] = 'yes';

            $this->assertFalse(Webhooks_Signature::allows_unsigned('nocode-tool'));
        }

        // -- admin "Send test" ------------------------------------------------------

        public function test_admin_send_test_is_accepted_for_a_signed_in_administrator(): void
        {
            $GLOBALS['__peanut_test_logged_in'] = true;
            $GLOBALS['__peanut_test_caps'] = ['read', 'manage_options'];

            $result = $this->receiveFor('test', ['event' => 'form_submission']);

            $this->assertInstanceOf(\WP_REST_Response::class, $result);
            $this->assertSame(202, $result->status);
        }

        public function test_send_test_source_is_refused_for_anonymous_and_non_admin_callers(): void
        {
            $this->assertRefusedUnsigned($this->receiveFor('test', ['event' => 'x']), 'anonymous');

            $GLOBALS['__peanut_test_logged_in'] = true;
            $GLOBALS['__peanut_test_caps'] = ['read'];
            $this->assertRefusedUnsigned($this->receiveFor('test', ['event' => 'x']), 'subscriber');

            $GLOBALS['__peanut_test_caps'] = ['read', 'manage_options'];
            $this->assertRefusedUnsigned($this->receiveFor('zapier', ['event' => 'x']), 'admin, but not the test source');
        }

        public function test_legacy_send_test_button_sends_a_rest_nonce(): void
        {
            $view = (string) file_get_contents(dirname(__DIR__, 2) . '/core/admin/views/webhooks.php');
            $this->assertStringContainsString("'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'", $view);
        }

        // -- operator visibility ----------------------------------------------------

        public function test_a_refusal_is_recorded_and_named_in_the_signing_status(): void
        {
            $this->receiveFor('legacy-crm', ['event' => 'x']);

            $this->assertSame(['legacy-crm'], Webhooks_Signature::rejected_unsigned_sources());

            $data = (new \Webhooks_Controller())->get_signing_status($this->request())->get_data()['data'];
            $this->assertSame(['legacy-crm'], $data['rejected_unsigned_sources']);
            $bySource = array_column($data['sources'], null, 'source');
            $this->assertTrue($bySource['legacy-crm']['rejected_unsigned']);
            $this->assertFalse($bySource['legacy-crm']['allows_unsigned']);
        }

        public function test_setting_a_secret_clears_the_warning(): void
        {
            $this->receiveFor('legacy-crm', ['event' => 'x']);
            Webhooks_Signature::set_secret('legacy-crm', self::SECRET);

            $this->assertSame([], Webhooks_Signature::rejected_unsigned_sources());
        }

        public function test_refusals_are_throttled_bounded_and_expire(): void
        {
            $now = 1_800_000_000;
            Webhooks_Signature::record_unsigned_rejection('a', $now);
            Webhooks_Signature::record_unsigned_rejection('a', $now + 10);
            $this->assertSame(1, get_option(Webhooks_Signature::REJECTIONS_OPTION)['a']['count'], 'One write per source per hour.');

            Webhooks_Signature::record_unsigned_rejection('a', $now + 3601);
            $this->assertSame(2, get_option(Webhooks_Signature::REJECTIONS_OPTION)['a']['count']);

            for ($i = 0; $i < 40; $i++) {
                Webhooks_Signature::record_unsigned_rejection('flood' . $i, $now + 4000 + $i);
            }
            $this->assertLessThanOrEqual(25, count(get_option(Webhooks_Signature::REJECTIONS_OPTION)));

            Webhooks_Signature::record_unsigned_rejection('../evil', $now);
            $this->assertArrayNotHasKey('../evil', get_option(Webhooks_Signature::REJECTIONS_OPTION));

            $this->assertSame([], Webhooks_Signature::rejected_unsigned_sources($now + 4100 + Webhooks_Signature::REJECTIONS_WINDOW));
        }

        public function test_admin_notice_names_refused_sources_for_administrators_only(): void
        {
            $this->receiveFor('legacy-crm', ['event' => 'x']);
            $module = new \Webhooks_Module();

            $GLOBALS['__peanut_test_caps'] = ['read'];
            ob_start();
            $module->render_unsigned_rejections_notice();
            $this->assertSame('', ob_get_clean());

            $GLOBALS['__peanut_test_caps'] = ['read', 'manage_options'];
            ob_start();
            $module->render_unsigned_rejections_notice();
            $html = (string) ob_get_clean();
            $this->assertStringContainsString('notice-warning', $html);
            $this->assertStringContainsString('<code>legacy-crm</code>', $html);
            $this->assertStringContainsString('page=peanut-app#/webhooks', $html);
        }

        public function test_admin_notice_is_hooked(): void
        {
            $module = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/webhooks/class-webhooks-module.php');
            $this->assertStringContainsString("add_action('admin_notices', [\$this, 'render_unsigned_rejections_notice']);", $module);
        }

        // -- helpers ---------------------------------------------------------------

        private function request(array $params = []): \WP_REST_Request
        {
            return new class($params) extends \WP_REST_Request {
                private array $params;
                public function __construct(array $params) { parent::__construct(['X-WP-Nonce' => 'valid-nonce']); $this->params = $params; }
                public function get_param($key) { return $this->params[$key] ?? null; }
            };
        }

        private function payloadRequest(array $payload, array $params = []): \WP_REST_Request
        {
            return new class($payload, $params) extends \WP_REST_Request {
                private array $payload; private array $params;
                public function __construct(array $payload, array $params) { parent::__construct([]); $this->payload = $payload; $this->params = $params; }
                public function get_body() { return json_encode($this->payload); }
                public function get_json_params() { return $this->payload; }
                public function get_param($key) { return $this->params[$key] ?? null; }
            };
        }

        private function receiveFor(string $source, array $payload)
        {
            return (new \Webhooks_Controller())->receive_source_webhook($this->payloadRequest($payload, ['source' => $source]));
        }

        private function receiveLegacy(array $payload)
        {
            return (new \Webhooks_Controller())->receive_webhook($this->payloadRequest($payload));
        }

        private function assertRefusedUnsigned($result, string $message = ''): void
        {
            $this->assertInstanceOf(\WP_Error::class, $result, $message);
            $this->assertSame(401, $result->get_error_data()['status'] ?? null, $message);
            $this->assertSame('signature_required', $result->get_error_code(), $message);
        }
    }
}
