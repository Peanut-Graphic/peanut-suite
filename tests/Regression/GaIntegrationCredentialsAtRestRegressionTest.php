<?php
/**
 * The GA Integration module (modules/ga-integration) kept its own Google
 * OAuth client secret and access/refresh tokens in PLAINTEXT in the
 * peanut_ga_credentials option, and its admin page printed the client secret
 * into the password input's value attribute (so it was in page source).
 *
 * Contract pinned here:
 *  - client_secret, access_token and refresh_token are stored as V2
 *    ciphertext, never plaintext; non-secret fields stay readable;
 *  - Google still receives the real secret / refresh token / bearer token;
 *  - the admin page never renders the secret (or its ciphertext); it shows an
 *    empty password field plus a "secret is set" hint;
 *  - a blank submitted secret keeps the stored one;
 *  - legacy plaintext still works and is encrypted by the next save and by a
 *    flag-gated one-time upgrade wired into plugin boot;
 *  - a secret that fails authentication means "not configured": nothing (and
 *    so no ciphertext) is sent to Google.
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
    if (!class_exists('Peanut_Test_Json_Halt')) {
        class Peanut_Test_Json_Halt extends \RuntimeException {
            public bool $success;
            public $payload;
            public ?int $status;
            public function __construct(bool $success, $payload, ?int $status = null) {
                parent::__construct('json halt');
                $this->success = $success;
                $this->payload = $payload;
                $this->status = $status;
            }
        }
    }
    if (!function_exists('wp_send_json_success')) {
        function wp_send_json_success($data = null, $status = null) {
            throw new \Peanut_Test_Json_Halt(true, $data, $status);
        }
    }
    if (!function_exists('wp_send_json_error')) {
        function wp_send_json_error($data = null, $status = null) {
            throw new \Peanut_Test_Json_Halt(false, $data, $status);
        }
    }
    if (!function_exists('peanut_test_mint_nonce')) {
        function peanut_test_mint_nonce(string $action): string {
            return 'nonce-for:' . $action;
        }
    }
    if (!function_exists('check_ajax_referer')) {
        function check_ajax_referer($action = -1, $query_arg = false, $stop = true) {
            $token = $query_arg !== false ? ($_POST[$query_arg] ?? '') : '';
            if ($token !== peanut_test_mint_nonce((string) $action)) {
                throw new \Peanut_Test_Json_Halt(false, 'invalid nonce', 403);
            }
            return 1;
        }
    }
    if (!function_exists('current_user_can')) {
        function current_user_can($cap, ...$args): bool {
            return in_array($cap, $GLOBALS['__peanut_test_caps'] ?? [], true);
        }
    }
    if (!function_exists('wp_next_scheduled')) {
        function wp_next_scheduled($hook, $args = []) { return 1; }
    }
    if (!function_exists('wp_schedule_event')) {
        function wp_schedule_event($timestamp, $recurrence, $hook, $args = []) { return true; }
    }
    if (!function_exists('admin_url')) {
        function admin_url($path = '') {
            return 'https://suite.example/wp-admin/' . ltrim((string) $path, '/');
        }
    }
    if (!function_exists('rest_url')) {
        function rest_url($path = '') {
            return 'https://suite.example/wp-json/' . ltrim((string) $path, '/');
        }
    }
    if (!function_exists('wp_create_nonce')) {
        function wp_create_nonce($action = -1) {
            return 'nonce-' . md5((string) $action);
        }
    }
    if (!function_exists('wp_nonce_field')) {
        function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true) {
            $field = '<input type="hidden" name="' . $name . '" value="nonce">';
            if ($display) {
                echo $field;
            }
            return $field;
        }
    }
    if (!function_exists('esc_attr_e')) {
        function esc_attr_e($text, $domain = 'default') { echo esc_attr($text); }
    }
    if (!function_exists('selected')) {
        function selected($selected, $current = true, $display = true) {
            $out = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
            if ($display) {
                echo $out;
            }
            return $out;
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
    if (!function_exists('wp_parse_args')) {
        function wp_parse_args($args, $defaults = []) {
            return array_merge((array) $defaults, (array) $args);
        }
    }
}

namespace PeanutSuite\Tests\Regression {

    use PHPUnit\Framework\TestCase;
    use Peanut_Encryption;
    use Peanut_GA_Credentials;
    use PeanutSuite\GAIntegration\GA_Integration_Module;

    final class GaIntegrationCredentialsAtRestRegressionTest extends TestCase
    {
        private const SECRET = 'GOCSPX-ga-module-secret-4c3b2a';
        private const ACCESS = 'ya29.module-access-token';
        private const REFRESH = '1//module-refresh-token';

        public static function setUpBeforeClass(): void
        {
            $root = dirname(__DIR__, 2);
            require_once $root . '/core/services/class-peanut-encryption.php';
            require_once $root . '/modules/ga-integration/class-ga-integration-module.php';
        }

        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_transients'] = [];
            $GLOBALS['mock_http_calls'] = [];
            $GLOBALS['__peanut_test_caps'] = ['manage_options'];
            unset($GLOBALS['mock_http_response']);
            $_POST = [];
            $_GET = [];
        }

        protected function tearDown(): void
        {
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_transients'] = [];
            $GLOBALS['mock_http_calls'] = [];
            unset($GLOBALS['mock_http_response'], $GLOBALS['__peanut_test_caps']);
            $_POST = [];
            $_GET = [];
            parent::tearDown();
        }

        // -- storage -------------------------------------------------------------

        public function test_saved_client_secret_is_stored_as_v2_ciphertext(): void
        {
            $this->save(['client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => self::SECRET]);

            $stored = get_option('peanut_ga_credentials');
            $this->assertTrue(Peanut_Encryption::is_v2($stored['client_secret']), 'client_secret must be stored in the V2 format.');
            $this->assertStringNotContainsString(self::SECRET, serialize($stored));
            $this->assertSame(self::SECRET, (new Peanut_Encryption())->decrypt($stored['client_secret']));
            $this->assertSame('cid.apps.googleusercontent.com', $stored['client_id']);
        }

        public function test_oauth_callback_stores_tokens_encrypted_and_sends_the_real_secret(): void
        {
            $this->save(['client_id' => 'cid', 'client_secret' => self::SECRET]);
            $GLOBALS['mock_http_response'] = [
                'body' => json_encode(['access_token' => self::ACCESS, 'refresh_token' => self::REFRESH, 'expires_in' => 3600]),
                'response' => ['code' => 200],
            ];

            $this->assertTrue($this->module()->handle_oauth_callback('auth-code'));

            $this->assertSame(self::SECRET, $GLOBALS['mock_http_calls'][0]['args']['body']['client_secret']);
            $stored = get_option('peanut_ga_credentials');
            $this->assertTrue(Peanut_Encryption::is_v2($stored['access_token']));
            $this->assertTrue(Peanut_Encryption::is_v2($stored['refresh_token']));
            $this->assertTrue(Peanut_Encryption::is_v2($stored['client_secret']));
            $blob = serialize($stored);
            foreach ([self::SECRET, self::ACCESS, self::REFRESH] as $plain) {
                $this->assertStringNotContainsString($plain, $blob);
            }
        }

        public function test_api_calls_use_the_decrypted_bearer_token(): void
        {
            $this->storeConnected(time() + 3600);

            $this->module()->get_top_pages(new \WP_REST_Request([]));

            $this->assertSame('Bearer ' . self::ACCESS, $GLOBALS['mock_http_calls'][0]['args']['headers']['Authorization']);
        }

        public function test_refresh_sends_decrypted_secrets_and_stores_the_new_token_encrypted(): void
        {
            $this->storeConnected(time() - 1);
            $GLOBALS['mock_http_response'] = [
                'body' => json_encode(['access_token' => 'ya29.refreshed', 'expires_in' => 3600]),
                'response' => ['code' => 200],
            ];

            $this->assertSame('ya29.refreshed', $this->accessToken());

            $body = $GLOBALS['mock_http_calls'][0]['args']['body'];
            $this->assertSame(self::SECRET, $body['client_secret']);
            $this->assertSame(self::REFRESH, $body['refresh_token']);
            $stored = get_option('peanut_ga_credentials');
            $this->assertTrue(Peanut_Encryption::is_v2($stored['access_token']));
            $this->assertStringNotContainsString('ya29.refreshed', serialize($stored));
            $this->assertTrue(Peanut_Encryption::is_v2($stored['refresh_token']), 'refresh must not decrypt the refresh token into storage');
        }

        // -- save semantics ------------------------------------------------------

        public function test_blank_submitted_secret_keeps_the_stored_secret(): void
        {
            $this->save(['client_id' => 'cid', 'client_secret' => self::SECRET]);
            $before = get_option('peanut_ga_credentials')['client_secret'];

            $this->save(['client_id' => 'cid-2', 'client_secret' => '']);

            $after = get_option('peanut_ga_credentials');
            $this->assertSame($before, $after['client_secret'], 'A blank secret field must not wipe the stored secret.');
            $this->assertSame('cid-2', $after['client_id']);
        }

        public function test_a_new_secret_replaces_the_old_one(): void
        {
            $this->save(['client_id' => 'cid', 'client_secret' => self::SECRET]);
            $this->save(['client_id' => 'cid', 'client_secret' => 'GOCSPX-rotated']);

            $this->assertSame('GOCSPX-rotated', Peanut_GA_Credentials::load()['client_secret']);
        }

        public function test_save_keeps_existing_tokens(): void
        {
            $this->storeConnected(time() + 3600);

            $this->save(['client_id' => 'cid', 'client_secret' => '', 'property_id' => '42']);

            $loaded = Peanut_GA_Credentials::load();
            $this->assertSame(self::ACCESS, $loaded['access_token']);
            $this->assertSame(self::REFRESH, $loaded['refresh_token']);
            $this->assertSame('42', $loaded['property_id']);
        }

        public function test_save_requires_admin_and_nonce(): void
        {
            $GLOBALS['__peanut_test_caps'] = ['read'];
            $halt = $this->save(['client_id' => 'cid', 'client_secret' => self::SECRET]);
            $this->assertFalse($halt->success);
            $this->assertFalse(get_option('peanut_ga_credentials'));

            $GLOBALS['__peanut_test_caps'] = ['manage_options'];
            $_POST = ['nonce' => 'forged', 'client_secret' => self::SECRET];
            try {
                $this->module()->ajax_save_credentials();
                $this->fail('A forged nonce must halt.');
            } catch (\Peanut_Test_Json_Halt $halt) {
                $this->assertSame(403, $halt->status);
            }
            $this->assertFalse(get_option('peanut_ga_credentials'));
        }

        // -- admin page ----------------------------------------------------------

        public function test_admin_page_never_renders_the_secret_or_its_ciphertext(): void
        {
            $this->save(['client_id' => 'cid.apps.googleusercontent.com', 'client_secret' => self::SECRET]);
            $stored = get_option('peanut_ga_credentials')['client_secret'];

            $html = $this->renderView();

            $this->assertStringNotContainsString(self::SECRET, $html);
            $this->assertStringNotContainsString($stored, $html);
            $this->assertMatchesRegularExpression('/<input[^>]*id="client-secret"[^>]*value=""/s', $html, 'The secret field must render empty.');
            $this->assertStringContainsString('A client secret is saved', $html, 'The page must say a secret is stored.');
            $this->assertStringContainsString('cid.apps.googleusercontent.com', $html);
        }

        public function test_admin_page_has_no_saved_hint_when_no_secret_is_stored(): void
        {
            $html = $this->renderView();

            $this->assertStringNotContainsString('A client secret is saved', $html);
        }

        public function test_legacy_plaintext_secret_is_not_rendered_either(): void
        {
            update_option('peanut_ga_credentials', ['client_id' => 'cid', 'client_secret' => self::SECRET]);

            $this->assertStringNotContainsString(self::SECRET, $this->renderView());
        }

        // -- legacy plaintext ----------------------------------------------------

        public function test_legacy_plaintext_credentials_still_work(): void
        {
            update_option('peanut_ga_credentials', [
                'client_id' => 'cid',
                'client_secret' => self::SECRET,
                'access_token' => self::ACCESS,
                'refresh_token' => self::REFRESH,
                'token_expires' => time() - 1,
                'property_id' => '42',
            ]);
            $GLOBALS['mock_http_response'] = [
                'body' => json_encode(['access_token' => 'ya29.refreshed', 'expires_in' => 3600]),
                'response' => ['code' => 200],
            ];

            $this->assertTrue($this->module()->is_connected());
            $this->assertSame('ya29.refreshed', $this->accessToken());
            $this->assertSame(self::SECRET, $GLOBALS['mock_http_calls'][0]['args']['body']['client_secret']);
            $this->assertSame(self::REFRESH, $GLOBALS['mock_http_calls'][0]['args']['body']['refresh_token']);
        }

        public function test_legacy_plaintext_is_encrypted_by_the_next_save(): void
        {
            update_option('peanut_ga_credentials', [
                'client_id' => 'cid', 'client_secret' => self::SECRET,
                'access_token' => self::ACCESS, 'refresh_token' => self::REFRESH,
            ]);

            $this->save(['client_id' => 'cid', 'client_secret' => '']);

            $stored = get_option('peanut_ga_credentials');
            foreach (Peanut_GA_Credentials::SECRET_FIELDS as $field) {
                $this->assertTrue(Peanut_Encryption::is_v2($stored[$field]), "$field must be upgraded on save");
            }
            $this->assertSame(self::SECRET, Peanut_GA_Credentials::load()['client_secret']);
        }

        public function test_one_time_upgrade_encrypts_legacy_plaintext_and_is_flag_gated(): void
        {
            update_option('peanut_ga_credentials', [
                'client_id' => 'cid', 'client_secret' => self::SECRET,
                'access_token' => self::ACCESS, 'refresh_token' => self::REFRESH,
                'token_expires' => 123, 'property_id' => '42',
            ]);

            $this->assertTrue(Peanut_GA_Credentials::maybe_upgrade());

            $stored = get_option('peanut_ga_credentials');
            $blob = serialize($stored);
            foreach ([self::SECRET, self::ACCESS, self::REFRESH] as $plain) {
                $this->assertStringNotContainsString($plain, $blob);
            }
            $this->assertSame('42', $stored['property_id']);
            $this->assertSame(123, $stored['token_expires']);
            $this->assertSame(self::ACCESS, Peanut_GA_Credentials::load()['access_token']);
            $this->assertSame(Peanut_GA_Credentials::UPGRADE_VERSION, get_option(Peanut_GA_Credentials::UPGRADE_FLAG));

            $stored['client_secret'] = 'plain-again';
            update_option('peanut_ga_credentials', $stored);
            $this->assertFalse(Peanut_GA_Credentials::maybe_upgrade());
            $this->assertSame('plain-again', get_option('peanut_ga_credentials')['client_secret']);
        }

        public function test_upgrade_on_a_site_without_credentials_just_sets_the_flag(): void
        {
            Peanut_GA_Credentials::maybe_upgrade();

            $this->assertFalse(get_option('peanut_ga_credentials'));
            $this->assertSame(Peanut_GA_Credentials::UPGRADE_VERSION, get_option(Peanut_GA_Credentials::UPGRADE_FLAG));
        }

        public function test_upgrade_is_wired_into_plugin_boot(): void
        {
            $main = (string) file_get_contents(dirname(__DIR__, 2) . '/peanut-suite.php');
            $this->assertMatchesRegularExpression('/Peanut_GA_Credentials::maybe_upgrade\(\);/', $main);
        }

        // -- tampering -----------------------------------------------------------

        public function test_tampered_client_secret_is_not_configured_and_nothing_goes_to_google(): void
        {
            $this->storeConnected(time() - 1);
            $stored = get_option('peanut_ga_credentials');
            $stored['client_secret'] = $this->tamper($stored['client_secret']);
            update_option('peanut_ga_credentials', $stored);

            $module = $this->module();
            $this->assertSame('', $module->get_oauth_url());
            $this->assertFalse($module->handle_oauth_callback('auth-code'));
            $this->assertNull($this->accessToken(), 'An expired token must not be refreshed with an unreadable secret.');

            $this->assertSame([], $GLOBALS['mock_http_calls'], 'No request (and so no ciphertext) may reach Google.');
        }

        public function test_tampered_access_token_means_not_connected(): void
        {
            $this->storeConnected(time() + 3600);
            $stored = get_option('peanut_ga_credentials');
            $stored['access_token'] = $this->tamper($stored['access_token']);
            update_option('peanut_ga_credentials', $stored);

            $module = $this->module();
            $this->assertFalse($module->is_connected());
            $module->get_top_pages(new \WP_REST_Request([]));
            $this->assertSame([], $GLOBALS['mock_http_calls']);
        }

        // -- helpers -------------------------------------------------------------

        private function module(): GA_Integration_Module
        {
            return new GA_Integration_Module();
        }

        private function save(array $fields): \Peanut_Test_Json_Halt
        {
            $_POST = array_merge(['nonce' => peanut_test_mint_nonce('peanut_ga')], $fields);
            try {
                $this->module()->ajax_save_credentials();
            } catch (\Peanut_Test_Json_Halt $halt) {
                return $halt;
            }
            $this->fail('ajax_save_credentials must answer with JSON.');
        }

        private function storeConnected(int $expires): void
        {
            Peanut_GA_Credentials::save([
                'client_id' => 'cid',
                'client_secret' => self::SECRET,
                'access_token' => self::ACCESS,
                'refresh_token' => self::REFRESH,
                'token_expires' => $expires,
                'property_id' => '42',
            ]);
        }

        private function accessToken(): ?string
        {
            $method = new \ReflectionMethod(GA_Integration_Module::class, 'get_access_token');
            $method->setAccessible(true);
            return $method->invoke($this->module());
        }

        private function tamper(string $v2): string
        {
            $raw = base64_decode(substr($v2, strlen(Peanut_Encryption::V2_PREFIX)), true);
            $raw[30] = chr(ord($raw[30]) ^ 0x01);
            return Peanut_Encryption::V2_PREFIX . base64_encode($raw);
        }

        private function renderView(): string
        {
            ob_start();
            try {
                include dirname(__DIR__, 2) . '/core/admin/views/ga-integration.php';
            } finally {
                $html = (string) ob_get_clean();
            }
            return $html;
        }
    }
}
