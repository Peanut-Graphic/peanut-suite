<?php
/**
 * GET /peanut/v1/settings returned the whole peanut_settings option -
 * Stripe secret key, Stripe webhook secret, Mailchimp / ConvertKit keys,
 * GA4 API secret - to ANY logged-in user with a REST nonce (Subscriber,
 * WooCommerce customer): the read route used permission_callback (login +
 * nonce only) while the write route used admin_permission_callback.
 *
 * Pins: (1) the settings read route requires manage_options; (2) third-party
 * secrets are write-only over REST (blank + "<key>_set" flag), and a blank
 * submitted value keeps the stored one so a GET->POST round trip cannot wipe
 * a live Stripe key.
 *
 * SELF-CONTAINED: pure PHP against the standalone WordPress mocks.
 */

namespace {
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
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public array $errors = []; public array $error_data = [];
            public function __construct($code = '', $message = '', $data = '') {
                if ($code !== '') { $this->errors[$code][] = $message; if ($data !== '') { $this->error_data[$code] = $data; } }
            }
            public function get_error_code() { return array_key_first($this->errors) ?? ''; }
            public function get_error_data($code = '') { $code = $code !== '' ? $code : $this->get_error_code(); return $this->error_data[$code] ?? null; }
        }
    }
    if (!function_exists('peanut_get_active_modules')) {
        function peanut_get_active_modules() { return []; }
    }
}

namespace PeanutSuite\Tests\Regression {

    use PHPUnit\Framework\TestCase;
    use Peanut_Settings_Controller;

    final class SettingsReadAuthorizationRegressionTest extends TestCase
    {
        private const THIRD_PARTY_SECRETS = [
            'stripe_secret_key' => 'sk_live_51Hreal',
            'stripe_webhook_secret' => 'whsec_realwebhook',
            'ga4_api_secret' => 'ga4-mp-secret',
            'mailchimp_api_key' => 'abc123-us21',
            'convertkit_api_key' => 'ck_key_real',
            'convertkit_api_secret' => 'ck_secret_real',
        ];

        public static function setUpBeforeClass(): void
        {
            $root = dirname(__DIR__, 2);
            require_once $root . '/core/services/class-peanut-encryption.php';
            require_once $root . '/core/services/class-peanut-security.php';
            require_once $root . '/core/services/class-peanut-settings-secrets.php';
            require_once $root . '/core/api/class-peanut-rest-controller.php';
            require_once $root . '/core/api/class-peanut-settings-controller.php';
        }

        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['mock_options'] = [];
            $GLOBALS['__peanut_test_routes'] = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['mock_options'] = [];
            unset($GLOBALS['__peanut_test_logged_in'], $GLOBALS['__peanut_test_caps'], $GLOBALS['__peanut_test_routes']);
            parent::tearDown();
        }

        private function settingsReadPermission(): callable
        {
            (new Peanut_Settings_Controller())->register_routes();
            foreach ($GLOBALS['__peanut_test_routes'] as $r) {
                $methods = (array) ($r['args']['methods'] ?? []);
                if (str_ends_with($r['route'], '/settings') && in_array('GET', array_map('strtoupper', array_map('strval', $methods)), true)) {
                    return $r['args']['permission_callback'];
                }
            }
            $this->fail('GET /settings route not registered');
        }

        public function test_a_logged_in_non_admin_cannot_read_settings(): void
        {
            $GLOBALS['__peanut_test_logged_in'] = true;
            $GLOBALS['__peanut_test_caps'] = ['read']; // Subscriber / customer

            $result = ($this->settingsReadPermission())(new \WP_REST_Request(['X-WP-Nonce' => 'valid-nonce']));

            $this->assertInstanceOf(\WP_Error::class, $result, 'A Subscriber must not read settings (they contain the Stripe secret key).');
            $this->assertSame(403, $result->get_error_data()['status'] ?? null);
        }

        public function test_an_administrator_can_read_settings(): void
        {
            $GLOBALS['__peanut_test_logged_in'] = true;
            $GLOBALS['__peanut_test_caps'] = ['read', 'manage_options'];

            $result = ($this->settingsReadPermission())(new \WP_REST_Request(['X-WP-Nonce' => 'valid-nonce']));

            $this->assertTrue($result);
        }

        public function test_third_party_secrets_never_leave_over_rest_even_to_admins(): void
        {
            update_option('peanut_settings', self::THIRD_PARTY_SECRETS + ['site_name' => 'Acme']);

            $general = $this->getViaRest()['general'];
            $json = json_encode($general);

            foreach (self::THIRD_PARTY_SECRETS as $key => $secret) {
                $this->assertStringNotContainsString($secret, $json, "GET /settings leaked $key");
                $this->assertSame('', $general[$key]);
                $this->assertTrue($general[$key . '_set'], "$key must report that it is set");
            }
            $this->assertSame('Acme', $general['site_name']);
        }

        public function test_get_then_post_round_trip_keeps_every_stored_secret(): void
        {
            update_option('peanut_settings', self::THIRD_PARTY_SECRETS + ['site_name' => 'Acme']);

            $general = $this->getViaRest()['general'];
            $general['site_name'] = 'Acme 2';
            $saved = $this->saveViaRest($general);

            $stored = get_option('peanut_settings');
            foreach (self::THIRD_PARTY_SECRETS as $key => $secret) {
                $this->assertSame($secret, $stored[$key], "a blank $key from the round trip must keep the stored secret");
                $this->assertArrayNotHasKey($key . '_set', $stored);
                $this->assertStringNotContainsString($secret, json_encode($saved), "POST /settings leaked $key");
            }
            $this->assertSame('Acme 2', $stored['site_name']);
        }

        public function test_a_submitted_third_party_secret_still_replaces_the_stored_one(): void
        {
            update_option('peanut_settings', ['stripe_secret_key' => 'sk_live_old']);

            $this->saveViaRest(['stripe_secret_key' => 'sk_live_new']);

            $this->assertSame('sk_live_new', get_option('peanut_settings')['stripe_secret_key']);
        }

        private function saveViaRest(array $settings): array
        {
            $request = new class($settings) extends \WP_REST_Request {
                private array $settings;
                public function __construct(array $settings) { parent::__construct([]); $this->settings = $settings; }
                public function get_param($key) { return $key === 'settings' ? $this->settings : null; }
            };
            return (new Peanut_Settings_Controller())->update_settings($request)->get_data()['data'];
        }

        private function getViaRest(): array
        {
            return (new Peanut_Settings_Controller())->get_settings(new \WP_REST_Request([]))->get_data()['data'];
        }
    }
}
