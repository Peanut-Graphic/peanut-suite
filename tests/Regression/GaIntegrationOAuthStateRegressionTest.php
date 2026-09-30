<?php
/**
 * The GA Integration module (modules/ga-integration) completed its Google
 * OAuth connect flow with NO `state` parameter: get_oauth_url() never sent
 * one, and the admin view (core/admin/views/ga-integration.php) exchanged any
 * `?action=oauth_callback&code=...` it was rendered with. An attacker could
 * make a signed-in admin's browser complete the callback with the ATTACKER's
 * authorization code (login / account-linking CSRF), linking the attacker's
 * Google account to the site, and a code could be replayed.
 *
 * Contract pinned here:
 *  - the auth URL carries a cryptographically random `state` bound to the
 *    current user, stored short-lived (hashed) and single-use;
 *  - the callback exchanges the code only for a `manage_options` user with a
 *    matching, unexpired, unused state; everything else is rejected without
 *    any request to Google;
 *  - a valid state exchanges the code exactly once;
 *  - the callback is handled on admin_init (then redirected so the code
 *    leaves the URL), not while rendering the admin view.
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
    if (!function_exists('current_user_can')) {
        function current_user_can($cap, ...$args): bool {
            return in_array($cap, $GLOBALS['__peanut_test_caps'] ?? [], true);
        }
    }
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int { return (int) ($GLOBALS['__peanut_test_user_id'] ?? 0); }
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
    if (!function_exists('wp_parse_args')) {
        function wp_parse_args($args, $defaults = []) {
            return array_merge((array) $defaults, (array) $args);
        }
    }
}

namespace PeanutSuite\Tests\Regression {

    use PHPUnit\Framework\TestCase;
    use Peanut_GA_Credentials;
    use PeanutSuite\GAIntegration\GA_Integration_Module;

    final class GaIntegrationOAuthStateRegressionTest extends TestCase
    {
        private const SECRET = 'GOCSPX-ga-state-secret-9f8e7d';
        private const ADMIN_ID = 7;

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
            $GLOBALS['__peanut_test_user_id'] = self::ADMIN_ID;
            $GLOBALS['mock_http_response'] = [
                'body' => json_encode(['access_token' => 'ya29.attacker-or-admin', 'refresh_token' => '1//r', 'expires_in' => 3600]),
                'response' => ['code' => 200],
            ];
            $_GET = [];
            $_POST = [];
            Peanut_GA_Credentials::save(['client_id' => 'cid', 'client_secret' => self::SECRET]);
        }

        protected function tearDown(): void
        {
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_transients'] = [];
            $GLOBALS['mock_http_calls'] = [];
            unset($GLOBALS['mock_http_response'], $GLOBALS['__peanut_test_caps'], $GLOBALS['__peanut_test_user_id']);
            $_GET = [];
            $_POST = [];
            parent::tearDown();
        }

        // -- auth URL ------------------------------------------------------------

        public function test_auth_url_carries_a_random_state(): void
        {
            $first = $this->stateFrom($this->module()->get_oauth_url());
            $second = $this->stateFrom($this->module()->get_oauth_url());

            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first, 'state must be 32 random bytes, hex encoded.');
            $this->assertNotSame($first, $second, 'Each consent link must mint a fresh state.');
        }

        public function test_state_is_stored_hashed_and_bound_to_the_user(): void
        {
            $state = $this->stateFrom($this->module()->get_oauth_url());

            $blob = serialize($GLOBALS['mock_transients']);
            $this->assertStringNotContainsString($state, $blob, 'The raw state must not be stored.');
            $this->assertArrayHasKey(GA_Integration_Module::OAUTH_STATE_TRANSIENT_PREFIX . self::ADMIN_ID, $GLOBALS['mock_transients']);
        }

        public function test_no_auth_url_without_a_signed_in_user(): void
        {
            $GLOBALS['__peanut_test_user_id'] = 0;

            $this->assertSame('', $this->module()->get_oauth_url());
        }

        // -- callback rejection --------------------------------------------------

        public function test_missing_state_is_rejected_without_calling_google(): void
        {
            $this->module()->get_oauth_url();

            $status = $this->module()->process_oauth_callback(['code' => 'attacker-code']);

            $this->assertSame('invalid_state', $status);
            $this->assertNothingSentToGoogle();
        }

        public function test_wrong_state_is_rejected_without_calling_google(): void
        {
            $this->module()->get_oauth_url();

            $status = $this->module()->process_oauth_callback(['code' => 'attacker-code', 'state' => str_repeat('a', 64)]);

            $this->assertSame('invalid_state', $status);
            $this->assertNothingSentToGoogle();
        }

        public function test_state_minted_for_another_user_is_rejected(): void
        {
            $GLOBALS['__peanut_test_user_id'] = 99; // the attacker's own session on the site
            $state = $this->stateFrom($this->module()->get_oauth_url());

            $GLOBALS['__peanut_test_user_id'] = self::ADMIN_ID;
            $status = $this->module()->process_oauth_callback(['code' => 'attacker-code', 'state' => $state]);

            $this->assertSame('invalid_state', $status);
            $this->assertNothingSentToGoogle();
        }

        public function test_expired_state_is_rejected(): void
        {
            $state = $this->stateFrom($this->module()->get_oauth_url());
            $key = GA_Integration_Module::OAUTH_STATE_TRANSIENT_PREFIX . self::ADMIN_ID;
            $GLOBALS['mock_transients'][$key]['expiration'] = time() - 1;

            $status = $this->module()->process_oauth_callback(['code' => 'code', 'state' => $state]);

            $this->assertSame('invalid_state', $status);
            $this->assertNothingSentToGoogle();
        }

        public function test_state_expires_within_ten_minutes(): void
        {
            $this->module()->get_oauth_url();
            $key = GA_Integration_Module::OAUTH_STATE_TRANSIENT_PREFIX . self::ADMIN_ID;

            $this->assertLessThanOrEqual(time() + 600, $GLOBALS['mock_transients'][$key]['expiration']);
            $this->assertGreaterThan(time(), $GLOBALS['mock_transients'][$key]['expiration']);
        }

        public function test_non_admin_is_rejected_even_with_a_valid_state(): void
        {
            $state = $this->stateFrom($this->module()->get_oauth_url());
            $GLOBALS['__peanut_test_caps'] = ['read'];

            $status = $this->module()->process_oauth_callback(['code' => 'code', 'state' => $state]);

            $this->assertSame('forbidden', $status);
            $this->assertNothingSentToGoogle();
        }

        public function test_handle_oauth_callback_itself_enforces_state_and_capability(): void
        {
            $this->module()->get_oauth_url();
            $this->assertFalse($this->module()->handle_oauth_callback('code', 'bogus'));

            $state = $this->stateFrom($this->module()->get_oauth_url());
            $GLOBALS['__peanut_test_caps'] = [];
            $this->assertFalse($this->module()->handle_oauth_callback('code', $state));

            $this->assertNothingSentToGoogle();
        }

        public function test_provider_error_is_reported_and_consumes_the_state(): void
        {
            $state = $this->stateFrom($this->module()->get_oauth_url());

            $status = $this->module()->process_oauth_callback(['error' => 'access_denied', 'state' => $state]);

            $this->assertSame('failed', $status);
            $this->assertNothingSentToGoogle();
            $this->assertSame('invalid_state', $this->module()->process_oauth_callback(['code' => 'code', 'state' => $state]));
        }

        // -- happy path ----------------------------------------------------------

        public function test_valid_state_exchanges_the_code_exactly_once(): void
        {
            $state = $this->stateFrom($this->module()->get_oauth_url());

            $this->assertSame('connected', $this->module()->process_oauth_callback(['code' => 'real-code', 'state' => $state]));
            $this->assertCount(1, $GLOBALS['mock_http_calls']);
            $this->assertSame('real-code', $GLOBALS['mock_http_calls'][0]['args']['body']['code']);
            $this->assertSame('ya29.attacker-or-admin', Peanut_GA_Credentials::load()['access_token']);

            // Replay of the same callback URL.
            $this->assertSame('invalid_state', $this->module()->process_oauth_callback(['code' => 'real-code', 'state' => $state]));
            $this->assertCount(1, $GLOBALS['mock_http_calls'], 'A replayed callback must not reach Google.');
        }

        public function test_failed_exchange_still_burns_the_state(): void
        {
            $state = $this->stateFrom($this->module()->get_oauth_url());
            $GLOBALS['mock_http_response'] = ['body' => json_encode(['error' => 'invalid_grant']), 'response' => ['code' => 400]];

            $this->assertSame('failed', $this->module()->process_oauth_callback(['code' => 'bad', 'state' => $state]));
            $this->assertSame('invalid_state', $this->module()->process_oauth_callback(['code' => 'bad', 'state' => $state]));
            $this->assertCount(1, $GLOBALS['mock_http_calls']);
        }

        // -- wiring --------------------------------------------------------------

        public function test_callback_is_handled_on_admin_init_and_redirected(): void
        {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/ga-integration/class-ga-integration-module.php');

            $this->assertMatchesRegularExpression("/add_action\\('admin_init',\\s*\\[\\\$this,\\s*'maybe_handle_oauth_callback'\\]\\)/", $src);
            $this->assertStringContainsString('wp_safe_redirect(', $src);
        }

        public function test_admin_view_no_longer_exchanges_codes(): void
        {
            $view = (string) file_get_contents(dirname(__DIR__, 2) . '/core/admin/views/ga-integration.php');

            $this->assertStringNotContainsString('->handle_oauth_callback(', $view);
            $this->assertStringNotContainsString('->process_oauth_callback(', $view);
            $this->assertStringNotContainsString("\$_GET['code']", $view);
        }

        public function test_admin_view_renders_invalid_state_error_and_never_calls_google(): void
        {
            $_GET = ['page' => 'peanut-ga-integration', 'action' => 'oauth_callback', 'code' => 'attacker-code', 'ga_oauth' => 'invalid_state'];

            $html = $this->renderView();

            $this->assertStringContainsString('notice-error', $html);
            $this->assertNothingSentToGoogle();
        }

        // -- helpers -------------------------------------------------------------

        private function module(): GA_Integration_Module
        {
            return new GA_Integration_Module();
        }

        private function stateFrom(string $url): string
        {
            $this->assertNotSame('', $url, 'Expected a consent URL.');
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $this->assertArrayHasKey('state', $query, 'The consent URL must carry a state parameter.');
            return (string) $query['state'];
        }

        private function assertNothingSentToGoogle(): void
        {
            $this->assertSame([], $GLOBALS['mock_http_calls'], 'No code exchange may reach Google.');
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
