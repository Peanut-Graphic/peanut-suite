<?php
/**
 * REST authorization hardening (Subscriber-reachable routes + unsigned
 * public receivers).
 *
 * The base Peanut_REST_Controller::permission_callback() is login + wp_rest
 * nonce ONLY, so any Subscriber / WooCommerce customer passes it. Several
 * admin surfaces were registered on it (or on `read` / `edit_posts`), and two
 * public receivers accepted unsigned requests even when a secret existed:
 *
 *  1. Webhooks admin routes (list / get / reprocess / stats / filters) were on
 *     the weak callback -> every stored inbound webhook payload site-wide.
 *  2. /webhooks/receive only verified a signature when BOTH a secret was
 *     configured AND a signature header was present: omit the header, skip it.
 *  3. /formflow/event trusted a same-host Referer / REMOTE_ADDR==SERVER_ADDR
 *     and a spoofable X-FFFL-Source header instead of the configured HMAC.
 *  4. ML lead-scoring / segmentation data routes required only `read`.
 *  5. Calendar routes required only `edit_posts` (no ownership scoping).
 *  6. get_or_create_for_user() auto-created an ACTIVE owned account for any
 *     logged-in user, which grant_peanut_access_to_team() then honoured ->
 *     any Subscriber self-granted `peanut_access`.
 *  7. POST /links (+ from-utm, update) let a Subscriber mint site-domain
 *     redirects to arbitrary URLs.
 *
 * SELF-CONTAINED: pure PHP against the standalone WordPress mocks plus the
 * shims below (guarded, so the first Regression test to load them wins; every
 * shim here has the same semantics as its siblings).
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    if (!defined('PEANUT_API_NAMESPACE')) {
        define('PEANUT_API_NAMESPACE', 'peanut/v1');
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
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
    if (!function_exists('user_can')) {
        function user_can($user, $cap, ...$args): bool {
            $id = is_object($user) ? (int) $user->ID : (int) $user;
            return in_array($cap, $GLOBALS['__peanut_test_user_caps'][$id] ?? [], true);
        }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int { return (int) ($GLOBALS['__peanut_test_user_id'] ?? 0); }
    }
    if (!function_exists('get_userdata')) {
        function get_userdata($user_id) {
            return (object) ['ID' => (int) $user_id, 'display_name' => 'User ' . $user_id, 'user_login' => 'user' . $user_id];
        }
    }
    if (!function_exists('sanitize_title')) {
        function sanitize_title($title) { return strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $title)); }
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
    // Collaborators of the webhooks receiver: recorded, never touch a DB.
    if (!class_exists('Webhooks_Database')) {
        class Webhooks_Database {
            public static array $inserted = [];
            public static function insert(array $data) { self::$inserted[] = $data; return count(self::$inserted); }
        }
    }
    if (!class_exists('Webhooks_Processor')) {
        class Webhooks_Processor {
            public static array $processed = [];
            public static function process(int $id): bool { self::$processed[] = $id; return true; }
        }
    }
    if (!class_exists('Peanut_Database')) {
        class Peanut_Database {
            public static function accounts_table(): string { return 'wp_peanut_accounts'; }
            public static function account_members_table(): string { return 'wp_peanut_account_members'; }
        }
    }
}

namespace PeanutSuite\Tests\Regression {

    use PHPUnit\Framework\TestCase;

    /** Minimal $wpdb double: answers read queries from a closure, records writes. */
    final class RestAuthzFakeWpdb
    {
        public string $prefix = 'wp_';
        public int $insert_id = 0;
        public array $inserts = [];
        /** @var callable(string $method, string $sql): mixed */
        public $answer;

        public function __construct(callable $answer) { $this->answer = $answer; }
        public function prepare($query, ...$args) {
            $args = isset($args[0]) && is_array($args[0]) ? $args[0] : $args;
            return vsprintf(str_replace(['%d', '%s'], ['%s', "'%s'"], $query), $args);
        }
        public function get_var($sql) { return ($this->answer)('get_var', $sql); }
        public function get_row($sql, $output = null) { return ($this->answer)('get_row', $sql); }
        public function get_results($sql, $output = null) { return ($this->answer)('get_results', $sql) ?? []; }
        public function insert($table, $data) {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            $this->insert_id = count($this->inserts);
            return 1;
        }
    }

    final class RestAuthorizationHardeningRegressionTest extends TestCase
    {
        private const SUBSCRIBER = ['read'];
        /** Contributor: logged in, has edit_posts (the old calendar gate), not an admin. */
        private const NON_ADMIN = ['read', 'edit_posts'];
        private const ADMIN = ['read', 'manage_options', 'edit_posts'];

        public static function setUpBeforeClass(): void
        {
            $root = dirname(__DIR__, 2);
            require_once $root . '/core/services/class-peanut-security.php';
            require_once $root . '/core/api/class-peanut-rest-controller.php';
            require_once $root . '/core/services/class-peanut-account-service.php';
            require_once $root . '/core/api/class-peanut-accounts-controller.php';
            require_once $root . '/core/admin/class-peanut-admin.php';
            require_once $root . '/modules/webhooks/class-webhooks-signature.php';
            require_once $root . '/modules/webhooks/api/class-webhooks-controller.php';
            require_once $root . '/modules/formflow/class-formflow-module.php';
            require_once $root . '/modules/formflow/api/class-formflow-controller.php';
            require_once $root . '/modules/contacts/api/class-ml-lead-scoring-controller.php';
            require_once $root . '/modules/analytics/api/class-ml-segmentation-controller.php';
            require_once $root . '/modules/calendar/class-calendar-module.php';
            require_once $root . '/modules/links/api/class-links-controller.php';
        }

        private array $serverBackup = [];
        private $wpdbBackup = null;

        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_transients'] = [];
            $GLOBALS['__peanut_test_routes'] = [];
            $this->serverBackup = $_SERVER;
            $this->wpdbBackup = $GLOBALS['wpdb'] ?? null;
            \Webhooks_Database::$inserted = [];
            \Webhooks_Processor::$processed = [];
        }

        protected function tearDown(): void
        {
            $_SERVER = $this->serverBackup;
            $GLOBALS['wpdb'] = $this->wpdbBackup;
            $GLOBALS['mock_options'] = [];
            unset(
                $GLOBALS['__peanut_test_logged_in'],
                $GLOBALS['__peanut_test_caps'],
                $GLOBALS['__peanut_test_user_caps'],
                $GLOBALS['__peanut_test_user_id'],
                $GLOBALS['__peanut_test_routes']
            );
            parent::tearDown();
        }

        // ------------------------------------------------------------------
        // Route-permission matrix (findings 1, 4, 5, 7)
        // ------------------------------------------------------------------

        /** @return array<string, array{0: callable(): object, 1: string, 2: string}> */
        public static function adminOnlyRoutes(): array
        {
            $noCtor = static fn(string $class) => static fn() => (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $webhooks = static fn() => new \Webhooks_Controller();
            $lead = $noCtor(\PeanutSuite\Contacts\ML_Lead_Scoring_Controller::class);
            $seg = $noCtor(\PeanutSuite\Analytics\ML_Segmentation_Controller::class);
            $cal = $noCtor(\PeanutSuite\Calendar\Calendar_Module::class);

            return [
                'GET webhooks' => [$webhooks, '/peanut/v1/webhooks', 'GET'],
                'GET webhooks/{id}' => [$webhooks, '/peanut/v1/webhooks/(?P<id>\d+)', 'GET'],
                'POST webhooks/{id}/reprocess' => [$webhooks, '/peanut/v1/webhooks/(?P<id>\d+)/reprocess', 'POST'],
                'GET webhooks/stats' => [$webhooks, '/peanut/v1/webhooks/stats', 'GET'],
                'GET webhooks/filters' => [$webhooks, '/peanut/v1/webhooks/filters', 'GET'],
                'POST webhooks/bulk-delete' => [$webhooks, '/peanut/v1/webhooks/bulk-delete', 'POST'],
                'POST contacts/lead-score' => [$lead, '/peanut/v1/contacts/lead-score', 'POST'],
                'POST contacts/lead-scores' => [$lead, '/peanut/v1/contacts/lead-scores', 'POST'],
                'GET contacts/top-leads' => [$lead, '/peanut/v1/contacts/top-leads', 'GET'],
                'GET analytics/segments' => [$seg, '/peanut/v1/analytics/segments', 'GET'],
                'GET analytics/visitor-profile' => [$seg, '/peanut/v1/analytics/visitor-profile/(?P<visitor_id>[^/]+)', 'GET'],
                'GET analytics/segmentation-stats' => [$seg, '/peanut/v1/analytics/segmentation-stats', 'GET'],
                'GET calendar/events' => [$cal, '/peanut/v1/calendar/events', 'GET'],
                'POST calendar/events' => [$cal, '/peanut/v1/calendar/events', 'POST'],
                'PUT calendar/events/{id}' => [$cal, '/peanut/v1/calendar/events/(?P<id>\d+)', 'PUT'],
                'DELETE calendar/events/{id}' => [$cal, '/peanut/v1/calendar/events/(?P<id>\d+)', 'DELETE'],
                'GET calendar/posts' => [$cal, '/peanut/v1/calendar/posts', 'GET'],
                'GET calendar/ideas' => [$cal, '/peanut/v1/calendar/ideas', 'GET'],
                'POST calendar/ideas' => [$cal, '/peanut/v1/calendar/ideas', 'POST'],
                'PUT calendar/ideas/{id}' => [$cal, '/peanut/v1/calendar/ideas/(?P<id>\d+)', 'PUT'],
                'DELETE calendar/ideas/{id}' => [$cal, '/peanut/v1/calendar/ideas/(?P<id>\d+)', 'DELETE'],
            ];
        }

        /** @dataProvider adminOnlyRoutes */
        public function test_a_non_admin_is_denied_admin_only_routes(callable $make, string $route, string $method): void
        {
            $permission = $this->permissionFor($make(), $route, $method);

            $this->loginWith(self::NON_ADMIN);
            $this->assertDenied($permission(new \WP_REST_Request(['X-WP-Nonce' => 'valid-nonce'])), "$method $route");
        }

        /** @dataProvider adminOnlyRoutes */
        public function test_an_administrator_is_allowed_admin_only_routes(callable $make, string $route, string $method): void
        {
            $permission = $this->permissionFor($make(), $route, $method);

            $this->loginWith(self::ADMIN);
            $this->assertTrue($permission(new \WP_REST_Request(['X-WP-Nonce' => 'valid-nonce'])), "$method $route");
        }

        public function test_ml_health_routes_stay_on_the_read_gate(): void
        {
            $noCtor = static fn(string $c) => (new \ReflectionClass($c))->newInstanceWithoutConstructor();
            $this->loginWith(self::SUBSCRIBER);
            $req = new \WP_REST_Request(['X-WP-Nonce' => 'valid-nonce']);

            $lead = $this->permissionFor($noCtor(\PeanutSuite\Contacts\ML_Lead_Scoring_Controller::class), '/peanut/v1/contacts/lead-scores/health', 'GET');
            $seg = $this->permissionFor($noCtor(\PeanutSuite\Analytics\ML_Segmentation_Controller::class), '/peanut/v1/analytics/segments/health', 'GET');

            $this->assertTrue($lead($req));
            $this->assertTrue($seg($req));
        }

        /** @return array<string, array{0: string, 1: string}> */
        public static function linkWriteRoutes(): array
        {
            return [
                'POST links' => ['/peanut/v1/links', 'POST'],
                'POST links/from-utm' => ['/peanut/v1/links/from-utm', 'POST'],
                'PUT links/{id}' => ['/peanut/v1/links/(?P<id>\d+)', 'PUT'],
            ];
        }

        /** @dataProvider linkWriteRoutes */
        public function test_a_subscriber_cannot_mint_or_retarget_short_links(string $route, string $method): void
        {
            $permission = $this->permissionFor(new \Links_Controller(), $route, $method);
            $this->loginWith(self::SUBSCRIBER);

            $this->assertDenied($permission(new \WP_REST_Request(['X-WP-Nonce' => 'valid-nonce'])), "$method $route");
        }

        /** @dataProvider linkWriteRoutes */
        public function test_a_peanut_team_member_can_still_write_short_links(string $route, string $method): void
        {
            $permission = $this->permissionFor(new \Links_Controller(), $route, $method);
            $this->loginWith(['read', 'peanut_access']);

            $this->assertTrue($permission(new \WP_REST_Request(['X-WP-Nonce' => 'valid-nonce'])), "$method $route");
        }

        // ------------------------------------------------------------------
        // Finding 2: /webhooks/receive signature enforcement
        // ------------------------------------------------------------------

        public function test_receive_rejects_a_missing_signature_when_the_source_has_a_secret(): void
        {
            update_option('peanut_webhook_secrets', ['stripe' => 'whsec_configured']);

            $result = (new \Webhooks_Controller())->receive_webhook($this->webhookRequest(['source' => 'stripe', 'event' => 'charge.succeeded']));

            $this->assertInstanceOf(\WP_Error::class, $result, 'An unsigned webhook for a source with a secret must be rejected.');
            $this->assertSame(401, $result->get_error_data()['status'] ?? null);
            $this->assertSame([], \Webhooks_Database::$inserted, 'A rejected webhook must not be stored.');
            $this->assertSame([], \Webhooks_Processor::$processed);
        }

        public function test_receive_rejects_an_invalid_signature_and_stores_nothing(): void
        {
            update_option('peanut_webhook_secrets', ['stripe' => 'whsec_configured']);
            $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] = 'sha256=' . str_repeat('0', 64);

            $result = (new \Webhooks_Controller())->receive_webhook($this->webhookRequest(['source' => 'stripe', 'event' => 'x']));

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame(401, $result->get_error_data()['status'] ?? null);
            $this->assertSame([], \Webhooks_Database::$inserted);
        }

        public function test_receive_accepts_a_valid_signature(): void
        {
            update_option('peanut_webhook_secrets', ['stripe' => 'whsec_configured']);
            $request = $this->webhookRequest(['source' => 'stripe', 'event' => 'x']);
            $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $request->get_body(), 'whsec_configured');

            $result = (new \Webhooks_Controller())->receive_webhook($request);

            $this->assertInstanceOf(\WP_REST_Response::class, $result);
            $this->assertSame(202, $result->status);
            $this->assertCount(1, \Webhooks_Database::$inserted);
        }

        public function test_receive_keeps_accepting_unsigned_webhooks_for_a_source_with_no_secret(): void
        {
            // Documented compatibility behavior: a site that never configured
            // a secret for a source keeps receiving that source's webhooks.
            $result = (new \Webhooks_Controller())->receive_webhook($this->webhookRequest(['source' => 'custom', 'event' => 'x']));

            $this->assertInstanceOf(\WP_REST_Response::class, $result);
            $this->assertSame(202, $result->status);
            $this->assertCount(1, \Webhooks_Database::$inserted);
        }

        // ------------------------------------------------------------------
        // Finding 3: /formflow/event verification
        // ------------------------------------------------------------------

        public function test_formflow_event_requires_the_hmac_when_a_secret_exists_even_from_the_same_host(): void
        {
            update_option('fffl_peanut_webhook_secret', 'ff-secret');
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['SERVER_ADDR'] = '127.0.0.1';
            $_SERVER['HTTP_REFERER'] = home_url('/anything');

            $result = (new \FormFlow_Controller())->verify_request($this->formflowRequest('{"event":"enrollment.completed"}', ['X-FFFL-Source' => 'formflow-lite']));

            $this->assertInstanceOf(\WP_Error::class, $result, 'A same-host / loopback request without the HMAC must not pass when a secret is configured.');
            $this->assertSame(401, $result->get_error_data()['status'] ?? null);
        }

        public function test_formflow_event_rejects_a_bad_hmac(): void
        {
            update_option('fffl_peanut_webhook_secret', 'ff-secret');

            $result = (new \FormFlow_Controller())->verify_request($this->formflowRequest('{"event":"x"}', ['X-FFFL-Signature' => str_repeat('a', 64)]));

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame(403, $result->get_error_data()['status'] ?? null);
        }

        public function test_formflow_event_accepts_the_formflow_lite_signature_format(): void
        {
            update_option('fffl_peanut_webhook_secret', 'ff-secret');
            $body = '{"event":"enrollment.completed"}';

            // FormFlow Lite (includes/class-webhook-handler.php::send) sends a bare
            // hex hash_hmac('sha256', $json_payload, $secret) in X-FFFL-Signature.
            $result = (new \FormFlow_Controller())->verify_request($this->formflowRequest($body, [
                'X-FFFL-Signature' => hash_hmac('sha256', $body, 'ff-secret'),
                'X-FFFL-Source' => 'formflow-lite',
            ]));

            $this->assertTrue($result);
        }

        public function test_formflow_event_never_trusts_a_forged_referer(): void
        {
            // No secret configured, opt-in off: an external request with a
            // same-host Referer used to pass.
            $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
            $_SERVER['SERVER_ADDR'] = '198.51.100.1';
            $_SERVER['HTTP_REFERER'] = home_url('/');

            $result = (new \FormFlow_Controller())->verify_request($this->formflowRequest('{"event":"x"}', ['X-FFFL-Source' => 'formflow-lite']));

            $this->assertInstanceOf(\WP_Error::class, $result);
            $this->assertSame(401, $result->get_error_data()['status'] ?? null);
        }

        public function test_formflow_event_unsigned_loopback_is_denied_unless_explicitly_opted_in(): void
        {
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
            $_SERVER['SERVER_ADDR'] = '127.0.0.1';
            $request = $this->formflowRequest('{"event":"x"}', ['X-FFFL-Source' => 'formflow-lite']);

            $this->assertInstanceOf(\WP_Error::class, (new \FormFlow_Controller())->verify_request($request), 'Default is off.');

            update_option('peanut_formflow_allow_unsigned_loopback', true);
            $this->assertTrue((new \FormFlow_Controller())->verify_request($request), 'Explicit opt-in keeps the legacy loopback path for unconfigured sites.');

            $_SERVER['HTTP_REFERER'] = home_url('/');
            $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
            $this->assertInstanceOf(\WP_Error::class, (new \FormFlow_Controller())->verify_request($request), 'Even opted in, a Referer never substitutes for loopback.');
        }

        // ------------------------------------------------------------------
        // Finding 6: account auto-creation + peanut_access self-grant
        // ------------------------------------------------------------------

        public function test_a_subscriber_without_a_team_gets_no_auto_created_account(): void
        {
            $wpdb = $this->useWpdb(static fn() => null);
            $GLOBALS['__peanut_test_user_caps'][7] = self::SUBSCRIBER;

            $this->assertNull(\Peanut_Account_Service::get_or_create_for_user(7));
            $this->assertSame([], $wpdb->inserts, 'No account may be created for a non-admin.');
        }

        public function test_an_administrator_still_gets_a_default_account(): void
        {
            $wpdb = $this->useWpdb(static fn(string $m, string $sql) => $m === 'get_row' && str_contains($sql, 'WHERE id =')
                ? ['id' => 1, 'name' => "User 1's Account", 'slug' => 'u', 'status' => 'active', 'tier' => 'free', 'max_users' => 3, 'owner_user_id' => 1, 'settings' => null, 'created_at' => null, 'updated_at' => null]
                : null);
            $GLOBALS['__peanut_test_user_caps'][1] = self::ADMIN;

            $this->assertNotNull(\Peanut_Account_Service::get_or_create_for_user(1));
            $this->assertSame('wp_peanut_accounts', $wpdb->inserts[0]['table'] ?? null);
        }

        public function test_accounts_current_returns_404_for_a_subscriber_with_no_team(): void
        {
            $this->useWpdb(static fn() => null);
            $GLOBALS['__peanut_test_user_id'] = 7;
            $GLOBALS['__peanut_test_user_caps'][7] = self::SUBSCRIBER;

            $controller = new \Peanut_Accounts_Controller();
            $current = $controller->get_current_account(new \WP_REST_Request([]));
            $features = $controller->get_available_features(new \WP_REST_Request([]));

            $this->assertInstanceOf(\WP_Error::class, $current);
            $this->assertSame(404, $current->get_error_data()['status'] ?? null);
            $this->assertInstanceOf(\WP_Error::class, $features);
            $this->assertSame(404, $features->get_error_data()['status'] ?? null);
        }

        /** @return array<string, array{0: array, 1: bool}> */
        public static function membershipRows(): array
        {
            // user 7 is the user being checked; user 1 is a WP administrator.
            return [
                'self-created owner (the Subscriber exploit)' => [['user_id' => 7, 'role' => 'owner', 'invited_by' => null, 'owner_user_id' => 7, 'owner_invited_by' => null], false],
                'invited by an admin-owned team' => [['user_id' => 7, 'role' => 'member', 'invited_by' => 1, 'owner_user_id' => 1, 'owner_invited_by' => null], true],
                'viewer invited by an admin-owned team' => [['user_id' => 7, 'role' => 'viewer', 'invited_by' => 1, 'owner_user_id' => 1, 'owner_invited_by' => null], true],
                'sock puppet invited into a self-created account' => [['user_id' => 7, 'role' => 'admin', 'invited_by' => 8, 'owner_user_id' => 8, 'owner_invited_by' => null], false],
                'owner by ownership transfer (was invited)' => [['user_id' => 7, 'role' => 'owner', 'invited_by' => 1, 'owner_user_id' => 7, 'owner_invited_by' => 1], true],
                'member of a team whose owner got it by transfer' => [['user_id' => 7, 'role' => 'member', 'invited_by' => 9, 'owner_user_id' => 9, 'owner_invited_by' => 1], true],
                'self-invited row' => [['user_id' => 7, 'role' => 'member', 'invited_by' => 7, 'owner_user_id' => 1, 'owner_invited_by' => null], false],
            ];
        }

        /** @dataProvider membershipRows */
        public function test_peanut_access_requires_a_membership_granted_by_someone_else(array $row, bool $expected): void
        {
            // The membership exists in the DB: answer both the list query and a
            // single-row "first active account for this user" lookup with it.
            $account = $row + ['id' => 5, 'name' => 'Team', 'slug' => 'team', 'status' => 'active', 'tier' => 'free', 'max_users' => 3, 'settings' => null, 'created_at' => null, 'updated_at' => null];
            $this->useWpdb(static fn(string $m) => match ($m) {
                'get_results' => [$account],
                'get_row' => $account,
                default => null,
            });
            $GLOBALS['__peanut_test_user_caps'][1] = self::ADMIN;

            $admin = (new \ReflectionClass(\Peanut_Admin::class))->newInstanceWithoutConstructor();
            $caps = $admin->grant_peanut_access_to_team(['read' => true], ['peanut_access'], ['peanut_access', 7], (object) ['ID' => 7]);

            $this->assertSame($expected, !empty($caps['peanut_access']));
        }

        public function test_administrators_always_get_peanut_access(): void
        {
            $this->useWpdb(static fn() => null);
            $admin = (new \ReflectionClass(\Peanut_Admin::class))->newInstanceWithoutConstructor();
            $caps = $admin->grant_peanut_access_to_team(['read' => true, 'manage_options' => true], ['peanut_access'], ['peanut_access', 1], (object) ['ID' => 1]);

            $this->assertTrue($caps['peanut_access']);
        }

        // ------------------------------------------------------------------
        // Helpers
        // ------------------------------------------------------------------

        private function loginWith(array $caps): void
        {
            $GLOBALS['__peanut_test_logged_in'] = true;
            $GLOBALS['__peanut_test_caps'] = $caps;
        }

        private function assertDenied($result, string $label): void
        {
            $this->assertNotTrue($result, "$label must deny a non-admin");
            if ($result instanceof \WP_Error) {
                $this->assertSame(403, $result->get_error_data()['status'] ?? null, $label);
            } else {
                $this->assertFalse($result, $label);
            }
        }

        private function permissionFor(object $controller, string $route, string $method): callable
        {
            $GLOBALS['__peanut_test_routes'] = [];
            $controller->register_routes();

            foreach ($GLOBALS['__peanut_test_routes'] as $r) {
                if ($r['route'] !== $route) {
                    continue;
                }
                $handlers = isset($r['args']['methods']) ? [$r['args']] : array_values(array_filter($r['args'], 'is_array'));
                foreach ($handlers as $h) {
                    $methods = array_map('trim', explode(',', strtoupper((string) $h['methods'])));
                    if (in_array($method, $methods, true)) {
                        $cb = $h['permission_callback'];
                        if (is_string($cb) && !is_callable($cb)) {
                            $this->fail("$method $route permission_callback '$cb' is not callable");
                        }
                        return static fn($request) => is_array($cb) ? $cb[0]->{$cb[1]}($request) : $cb($request);
                    }
                }
            }
            $this->fail("$method $route not registered");
        }

        private function webhookRequest(array $payload): \WP_REST_Request
        {
            return new class($payload) extends \WP_REST_Request {
                private array $payload;
                public function __construct(array $payload) { parent::__construct([]); $this->payload = $payload; }
                public function get_body() { return json_encode($this->payload); }
                public function get_json_params() { return $this->payload; }
            };
        }

        private function formflowRequest(string $body, array $headers): \WP_REST_Request
        {
            return new class($body, $headers) extends \WP_REST_Request {
                private string $body;
                public function __construct(string $body, array $headers) { parent::__construct($headers); $this->body = $body; }
                public function get_body() { return $this->body; }
            };
        }

        private function useWpdb(callable $answer): RestAuthzFakeWpdb
        {
            return $GLOBALS['wpdb'] = new RestAuthzFakeWpdb($answer);
        }
    }
}
