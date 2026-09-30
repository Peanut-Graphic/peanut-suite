<?php
/**
 * The GA4 Reports OAuth client secret (peanut_settings['ga4_reports_client_secret'])
 * was stored in plaintext next to the OAuth tokens it guards, which are
 * encrypted, and GET /peanut/v1/settings echoed the whole option (secret
 * included) to the browser.
 *
 * Contract pinned here:
 *  - stored value is V2 ciphertext, never the plaintext;
 *  - token exchange and refresh still send Google the real secret;
 *  - REST settings output never contains the plaintext or the ciphertext,
 *    only an "is set" flag;
 *  - an empty submitted value keeps the existing secret;
 *  - a legacy plaintext value still works, and is encrypted by the next save
 *    and by the one-time upgrade;
 *  - a value that fails authentication means "not configured": nothing is
 *    sent to Google.
 *
 * SELF-CONTAINED: pure PHP against the standalone WordPress mocks.
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
    if (!function_exists('admin_url')) {
        function admin_url($path = '') {
            return 'https://suite.example/wp-admin/' . ltrim((string) $path, '/');
        }
    }
    if (!function_exists('wp_create_nonce')) {
        function wp_create_nonce($action = -1) {
            return 'nonce-' . md5((string) $action);
        }
    }
    if (!function_exists('current_user_can')) {
        function current_user_can($cap, ...$args): bool {
            return in_array($cap, $GLOBALS['__peanut_test_caps'] ?? [], true);
        }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int { return (int) ($GLOBALS['__peanut_test_user_id'] ?? 0); }
    }
    if (!function_exists('wp_parse_args')) {
        function wp_parse_args($args, $defaults = []) {
            return array_merge((array) $defaults, (array) $args);
        }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw($url, $protocols = null) {
            return filter_var($url, FILTER_SANITIZE_URL);
        }
    }
    if (!function_exists('peanut_get_active_modules')) {
        function peanut_get_active_modules() {
            return [];
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
            public $data;
            public int $status;
            public function __construct($data = null, int $status = 200) {
                $this->data = $data;
                $this->status = $status;
            }
            public function get_data() { return $this->data; }
        }
    }
}

namespace PeanutSuite\Tests\Regression {

    use PHPUnit\Framework\TestCase;
    use Peanut_Encryption;
    use Peanut_Integration_GA4_Reports;
    use Peanut_Settings_Controller;
    use Peanut_Settings_Secrets;

    final class Ga4ClientSecretAtRestRegressionTest extends TestCase
    {
        private const SECRET = 'GOCSPX-real-client-secret-9f8e7d';
        private const KEY = 'ga4_reports_client_secret';

        public static function setUpBeforeClass(): void
        {
            $root = dirname(__DIR__, 2);
            require_once $root . '/core/services/class-peanut-encryption.php';
            require_once $root . '/core/services/class-peanut-security.php';
            require_once $root . '/core/services/class-peanut-settings-secrets.php';
            require_once $root . '/core/services/integrations/class-peanut-integration-ga4-reports.php';
            require_once $root . '/core/api/class-peanut-rest-controller.php';
            require_once $root . '/core/api/class-peanut-settings-controller.php';
        }

        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_transients'] = [];
            $GLOBALS['mock_http_calls'] = [];
            unset($GLOBALS['mock_http_response']);
            // Token exchange needs an admin with a valid per-user OAuth state.
            $GLOBALS['__peanut_test_caps'] = ['manage_options'];
            $GLOBALS['__peanut_test_user_id'] = 1;
        }

        protected function tearDown(): void
        {
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_transients'] = [];
            $GLOBALS['mock_http_calls'] = [];
            unset($GLOBALS['mock_http_response'], $GLOBALS['__peanut_test_caps'], $GLOBALS['__peanut_test_user_id']);
            parent::tearDown();
        }

        // -- storage -------------------------------------------------------------

        public function test_saved_secret_is_stored_as_v2_ciphertext_not_plaintext(): void
        {
            $this->saveViaRest(['ga4_reports_client_id' => 'cid-123', self::KEY => self::SECRET]);

            $stored = get_option('peanut_settings')[self::KEY];
            $this->assertTrue(Peanut_Encryption::is_v2($stored), 'The client secret must be stored in the V2 authenticated format.');
            $this->assertStringNotContainsString(self::SECRET, serialize(get_option('peanut_settings')));
            $this->assertSame(self::SECRET, (new Peanut_Encryption())->decrypt($stored));
            $this->assertSame('cid-123', get_option('peanut_settings')['ga4_reports_client_id']);
        }

        public function test_empty_submitted_secret_keeps_the_existing_secret(): void
        {
            $this->saveViaRest([self::KEY => self::SECRET]);
            $before = get_option('peanut_settings')[self::KEY];

            // Round-trip what GET returns (secret blank + "is set" flag) plus an edit.
            $general = $this->getViaRest()['general'];
            $general['site_name'] = 'Acme';
            $this->saveViaRest($general);

            $after = get_option('peanut_settings');
            $this->assertSame($before, $after[self::KEY], 'A blank secret field must not wipe or re-encrypt the stored secret.');
            $this->assertSame('Acme', $after['site_name']);
            $this->assertArrayNotHasKey(self::KEY . '_set', $after, 'The display-only flag must not be persisted.');
        }

        public function test_a_new_secret_replaces_the_old_one(): void
        {
            $this->saveViaRest([self::KEY => self::SECRET]);
            $this->saveViaRest([self::KEY => 'GOCSPX-rotated']);

            $this->assertSame('GOCSPX-rotated', Peanut_Settings_Secrets::get(get_option('peanut_settings'), self::KEY));
        }

        // -- REST output ---------------------------------------------------------

        public function test_rest_output_never_contains_the_secret_or_its_ciphertext(): void
        {
            $saved = $this->saveViaRest(['ga4_reports_client_id' => 'cid', self::KEY => self::SECRET]);
            $stored = get_option('peanut_settings')[self::KEY];

            $got = $this->getViaRest();

            foreach (['POST' => $saved, 'GET' => $got] as $verb => $payload) {
                $json = json_encode($payload);
                $this->assertStringNotContainsString(self::SECRET, $json, "$verb /settings leaked the plaintext secret");
                $this->assertStringNotContainsString($stored, $json, "$verb /settings leaked the ciphertext");
            }
            $this->assertSame('', $got['general'][self::KEY]);
            $this->assertTrue($got['general'][self::KEY . '_set']);
            $this->assertSame('cid', $got['general']['ga4_reports_client_id']);
        }

        public function test_rest_output_reports_unset_secret(): void
        {
            $got = $this->getViaRest();
            $this->assertFalse($got['general'][self::KEY . '_set']);
        }

        // -- use with Google -----------------------------------------------------

        public function test_token_exchange_sends_the_decrypted_secret(): void
        {
            $this->saveViaRest(['ga4_reports_client_id' => 'cid', self::KEY => self::SECRET]);
            $GLOBALS['mock_http_response'] = [
                'body' => json_encode(['access_token' => 'ya29.a', 'refresh_token' => '1//r', 'expires_in' => 3600]),
                'response' => ['code' => 200],
            ];
            $ga4 = new Peanut_Integration_GA4_Reports();
            $state = $this->mintState($ga4);

            $result = $ga4->exchange_code('auth-code', $state);

            $this->assertTrue($result['success']);
            $this->assertSame(self::SECRET, $GLOBALS['mock_http_calls'][0]['args']['body']['client_secret']);
        }

        public function test_token_refresh_sends_the_decrypted_secret(): void
        {
            $this->saveViaRest(['ga4_reports_client_id' => 'cid', self::KEY => self::SECRET]);
            $enc = new Peanut_Encryption();
            update_option('peanut_ga4_reports_tokens', [
                'access_token' => $enc->encrypt('ya29.old'),
                'refresh_token' => $enc->encrypt('1//refresh'),
                'expires_at' => time() - 1,
            ]);
            $GLOBALS['mock_http_response'] = [
                'body' => json_encode(['access_token' => 'ya29.new', 'expires_in' => 3600]),
                'response' => ['code' => 200],
            ];

            $this->assertSame('ya29.new', (new Peanut_Integration_GA4_Reports())->get_access_token());
            $this->assertSame(self::SECRET, $GLOBALS['mock_http_calls'][0]['args']['body']['client_secret']);
        }

        // -- legacy plaintext ----------------------------------------------------

        public function test_legacy_plaintext_secret_still_works(): void
        {
            update_option('peanut_settings', ['ga4_reports_client_id' => 'cid', self::KEY => self::SECRET]);
            $GLOBALS['mock_http_response'] = [
                'body' => json_encode(['access_token' => 'ya29.a', 'expires_in' => 3600]),
                'response' => ['code' => 200],
            ];

            $ga4 = new Peanut_Integration_GA4_Reports();
            $this->assertTrue($ga4->has_credentials());
            $ga4->exchange_code('auth-code', $this->mintState($ga4));
            $this->assertSame(self::SECRET, $GLOBALS['mock_http_calls'][0]['args']['body']['client_secret']);
        }

        public function test_legacy_plaintext_is_encrypted_by_the_next_save(): void
        {
            update_option('peanut_settings', ['ga4_reports_client_id' => 'cid', self::KEY => self::SECRET]);

            // Saving an unrelated field (secret not submitted) still upgrades it.
            $this->saveViaRest(['site_name' => 'Acme']);

            $stored = get_option('peanut_settings')[self::KEY];
            $this->assertTrue(Peanut_Encryption::is_v2($stored));
            $this->assertSame(self::SECRET, Peanut_Settings_Secrets::get(get_option('peanut_settings'), self::KEY));
        }

        public function test_one_time_upgrade_encrypts_legacy_plaintext_and_is_flag_gated(): void
        {
            update_option('peanut_settings', ['ga4_reports_client_id' => 'cid', self::KEY => self::SECRET, 'link_prefix' => 'go']);

            $this->assertTrue(Peanut_Settings_Secrets::maybe_upgrade());

            $settings = get_option('peanut_settings');
            $this->assertTrue(Peanut_Encryption::is_v2($settings[self::KEY]));
            $this->assertStringNotContainsString(self::SECRET, serialize($settings));
            $this->assertSame('go', $settings['link_prefix']);
            $this->assertSame(Peanut_Settings_Secrets::UPGRADE_VERSION, get_option(Peanut_Settings_Secrets::UPGRADE_FLAG));

            // Flag set: a second run is a no-op, even if a plaintext value reappears.
            $settings[self::KEY] = 'plain-again';
            update_option('peanut_settings', $settings);
            $this->assertFalse(Peanut_Settings_Secrets::maybe_upgrade());
            $this->assertSame('plain-again', get_option('peanut_settings')[self::KEY]);
        }

        public function test_upgrade_on_a_site_without_the_secret_just_sets_the_flag(): void
        {
            update_option('peanut_settings', ['link_prefix' => 'go']);

            Peanut_Settings_Secrets::maybe_upgrade();

            $this->assertSame(['link_prefix' => 'go'], get_option('peanut_settings'));
            $this->assertSame(Peanut_Settings_Secrets::UPGRADE_VERSION, get_option(Peanut_Settings_Secrets::UPGRADE_FLAG));
        }

        public function test_upgrade_is_wired_into_plugin_boot(): void
        {
            $main = (string) file_get_contents(dirname(__DIR__, 2) . '/peanut-suite.php');
            $this->assertMatchesRegularExpression('/Peanut_Settings_Secrets::maybe_upgrade\(\);/', $main);
        }

        // -- tampering -----------------------------------------------------------

        public function test_tampered_secret_is_not_configured_and_nothing_goes_to_google(): void
        {
            $this->saveViaRest(['ga4_reports_client_id' => 'cid', self::KEY => self::SECRET]);
            $settings = get_option('peanut_settings');
            $raw = base64_decode(substr($settings[self::KEY], strlen(Peanut_Encryption::V2_PREFIX)), true);
            $raw[30] = chr(ord($raw[30]) ^ 0x01);
            $settings[self::KEY] = Peanut_Encryption::V2_PREFIX . base64_encode($raw);
            update_option('peanut_settings', $settings);

            $ga4 = new Peanut_Integration_GA4_Reports();
            $this->assertFalse($ga4->has_credentials());
            $this->assertSame('', $ga4->get_auth_url());

            // Even with a valid per-user state, an undecryptable secret stops the exchange.
            set_transient(Peanut_Integration_GA4_Reports::OAUTH_STATE_TRANSIENT_PREFIX . 1, hash('sha256', 'st'), 600);
            $this->assertFalse($ga4->exchange_code('auth-code', 'st')['success']);

            $enc = new Peanut_Encryption();
            update_option('peanut_ga4_reports_tokens', [
                'access_token' => $enc->encrypt('ya29.old'),
                'refresh_token' => $enc->encrypt('1//refresh'),
                'expires_at' => time() - 1,
            ]);
            $this->assertNull($ga4->get_access_token());

            $this->assertSame([], $GLOBALS['mock_http_calls'], 'No request (and so no ciphertext) may reach Google.');
        }

        // -- helpers -------------------------------------------------------------

        private function mintState(Peanut_Integration_GA4_Reports $ga4): string
        {
            parse_str((string) parse_url($ga4->get_auth_url(), PHP_URL_QUERY), $query);
            $this->assertNotEmpty($query['state'] ?? '', 'Expected a consent URL with a state.');
            return (string) $query['state'];
        }

        private function saveViaRest(array $settings): array
        {
            $request = new class($settings) extends \WP_REST_Request {
                private array $settings;
                public function __construct(array $settings) { parent::__construct([]); $this->settings = $settings; }
                public function get_param($key) { return $key === 'settings' ? $this->settings : null; }
            };
            $response = (new Peanut_Settings_Controller())->update_settings($request);
            $this->assertInstanceOf(\WP_REST_Response::class, $response);
            return $response->get_data()['data'];
        }

        private function getViaRest(): array
        {
            $response = (new Peanut_Settings_Controller())->get_settings(new \WP_REST_Request([]));
            return $response->get_data()['data'];
        }
    }
}
