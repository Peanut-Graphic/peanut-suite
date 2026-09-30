<?php
/**
 * The GA4 Reports integration (core/services/integrations/
 * class-peanut-integration-ga4-reports.php) protected its Google OAuth
 * callback with a weak `state`:
 *  - one SITE-WIDE transient (`peanut_ga4_reports_oauth_state`), so a state
 *    minted in one user's session was accepted in any other user's session,
 *    and each new consent link overwrote everyone else's;
 *  - the state was `wp_create_nonce()` output, not random bytes;
 *  - it was compared with `!==` (not constant time) and stored in the clear;
 *  - exchange_code() had no capability check.
 *
 * Contract pinned here (same standard as the GA Integration module):
 *  - the auth URL carries 32 random bytes of state, bound to the current
 *    user, stored hashed in a per-user transient that expires within
 *    10 minutes, and single-use;
 *  - the callback exchanges the code only for a `manage_options` user with a
 *    matching (`hash_equals`), unexpired, unused state; a missing, wrong,
 *    other-user, expired or reused state is rejected with nothing sent to
 *    Google;
 *  - a valid state exchanges the code exactly once;
 *  - the old site-wide transient is no longer honoured.
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
}

namespace PeanutSuite\Tests\Regression {

    use PHPUnit\Framework\TestCase;
    use Peanut_Encryption;
    use Peanut_Integration_GA4_Reports;

    final class Ga4ReportsOAuthStateRegressionTest extends TestCase
    {
        private const ADMIN_ID = 7;
        private const SECRET = 'GOCSPX-ga4-reports-secret';

        public static function setUpBeforeClass(): void
        {
            $root = dirname(__DIR__, 2);
            require_once $root . '/core/services/class-peanut-encryption.php';
            require_once $root . '/core/services/class-peanut-settings-secrets.php';
            require_once $root . '/core/services/integrations/class-peanut-integration-ga4-reports.php';
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
                'body' => json_encode(['access_token' => 'ya29.linked', 'refresh_token' => '1//r', 'expires_in' => 3600]),
                'response' => ['code' => 200],
            ];
            update_option('peanut_settings', [
                'ga4_reports_client_id' => 'cid',
                'ga4_reports_client_secret' => (new Peanut_Encryption())->encrypt(self::SECRET),
            ]);
        }

        protected function tearDown(): void
        {
            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_transients'] = [];
            $GLOBALS['mock_http_calls'] = [];
            unset($GLOBALS['mock_http_response'], $GLOBALS['__peanut_test_caps'], $GLOBALS['__peanut_test_user_id']);
            parent::tearDown();
        }

        // -- auth URL ------------------------------------------------------------

        public function test_auth_url_carries_a_random_state(): void
        {
            $first = $this->stateFrom($this->ga4()->get_auth_url());
            $second = $this->stateFrom($this->ga4()->get_auth_url());

            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first, 'state must be 32 random bytes, hex encoded.');
            $this->assertNotSame($first, $second, 'Each consent link must mint a fresh state.');
            $this->assertNotSame(wp_create_nonce('peanut_ga4_reports_oauth'), $first, 'state must not be a WP nonce.');
        }

        public function test_state_is_stored_hashed_and_bound_to_the_user(): void
        {
            $state = $this->stateFrom($this->ga4()->get_auth_url());

            $this->assertStringNotContainsString($state, serialize($GLOBALS['mock_transients']), 'The raw state must not be stored.');
            $this->assertArrayHasKey($this->stateKey(self::ADMIN_ID), $GLOBALS['mock_transients']);
            $this->assertArrayNotHasKey('peanut_ga4_reports_oauth_state', $GLOBALS['mock_transients'], 'No site-wide state transient.');
        }

        public function test_state_expires_within_ten_minutes(): void
        {
            $this->ga4()->get_auth_url();
            $expiration = $GLOBALS['mock_transients'][$this->stateKey(self::ADMIN_ID)]['expiration'];

            $this->assertLessThanOrEqual(time() + 600, $expiration);
            $this->assertGreaterThan(time(), $expiration);
        }

        public function test_no_auth_url_without_a_signed_in_user(): void
        {
            $GLOBALS['__peanut_test_user_id'] = 0;

            $this->assertSame('', $this->ga4()->get_auth_url());
        }

        // -- callback rejection --------------------------------------------------

        public function test_missing_state_is_rejected_without_calling_google(): void
        {
            $this->ga4()->get_auth_url();

            $this->assertSame('invalid_state', $this->ga4()->process_oauth_callback(['code' => 'attacker-code']));
            $this->assertNothingSentToGoogle();
        }

        public function test_wrong_state_is_rejected_without_calling_google(): void
        {
            $this->ga4()->get_auth_url();

            $status = $this->ga4()->process_oauth_callback(['code' => 'attacker-code', 'state' => str_repeat('a', 64)]);

            $this->assertSame('invalid_state', $status);
            $this->assertNothingSentToGoogle();
        }

        public function test_state_minted_for_another_user_is_rejected(): void
        {
            $GLOBALS['__peanut_test_user_id'] = 99; // the attacker's own session on the site
            $state = $this->stateFrom($this->ga4()->get_auth_url());

            $GLOBALS['__peanut_test_user_id'] = self::ADMIN_ID;
            $status = $this->ga4()->process_oauth_callback(['code' => 'attacker-code', 'state' => $state]);

            $this->assertSame('invalid_state', $status);
            $this->assertNothingSentToGoogle();
        }

        public function test_expired_state_is_rejected(): void
        {
            $state = $this->stateFrom($this->ga4()->get_auth_url());
            $GLOBALS['mock_transients'][$this->stateKey(self::ADMIN_ID)]['expiration'] = time() - 1;

            $this->assertSame('invalid_state', $this->ga4()->process_oauth_callback(['code' => 'code', 'state' => $state]));
            $this->assertNothingSentToGoogle();
        }

        public function test_legacy_site_wide_transient_is_not_honoured(): void
        {
            set_transient('peanut_ga4_reports_oauth_state', 'st', 600);

            $this->assertFalse($this->ga4()->exchange_code('attacker-code', 'st')['success']);
            $this->assertSame('invalid_state', $this->ga4()->process_oauth_callback(['code' => 'attacker-code', 'state' => 'st']));
            $this->assertNothingSentToGoogle();
        }

        public function test_non_admin_is_rejected_even_with_a_valid_state(): void
        {
            $state = $this->stateFrom($this->ga4()->get_auth_url());
            $GLOBALS['__peanut_test_caps'] = ['read'];

            $this->assertSame('forbidden', $this->ga4()->process_oauth_callback(['code' => 'code', 'state' => $state]));
            $this->assertNothingSentToGoogle();
        }

        public function test_exchange_code_itself_enforces_state_and_capability(): void
        {
            $this->ga4()->get_auth_url();
            $this->assertFalse($this->ga4()->exchange_code('code', 'bogus')['success']);

            $state = $this->stateFrom($this->ga4()->get_auth_url());
            $GLOBALS['__peanut_test_caps'] = [];
            $this->assertFalse($this->ga4()->exchange_code('code', $state)['success']);

            $this->assertNothingSentToGoogle();
        }

        public function test_provider_error_is_reported_and_consumes_the_state(): void
        {
            $state = $this->stateFrom($this->ga4()->get_auth_url());

            $this->assertSame('failed', $this->ga4()->process_oauth_callback(['error' => 'access_denied', 'state' => $state]));
            $this->assertNothingSentToGoogle();
            $this->assertSame('invalid_state', $this->ga4()->process_oauth_callback(['code' => 'code', 'state' => $state]));
            $this->assertNothingSentToGoogle();
        }

        // -- happy path ----------------------------------------------------------

        public function test_valid_state_exchanges_the_code_exactly_once(): void
        {
            $state = $this->stateFrom($this->ga4()->get_auth_url());

            $this->assertSame('connected', $this->ga4()->process_oauth_callback(['code' => 'real-code', 'state' => $state]));
            $this->assertCount(1, $GLOBALS['mock_http_calls']);
            $this->assertSame('real-code', $GLOBALS['mock_http_calls'][0]['args']['body']['code']);
            $this->assertSame(self::SECRET, $GLOBALS['mock_http_calls'][0]['args']['body']['client_secret']);
            $this->assertSame('ya29.linked', $this->ga4()->get_access_token());

            // Replay of the same callback URL.
            $this->assertSame('invalid_state', $this->ga4()->process_oauth_callback(['code' => 'real-code', 'state' => $state]));
            $this->assertFalse($this->ga4()->exchange_code('real-code', $state)['success']);
            $this->assertCount(1, $GLOBALS['mock_http_calls'], 'A replayed callback must not reach Google.');
        }

        public function test_exchange_code_with_a_valid_state_succeeds_once(): void
        {
            $state = $this->stateFrom($this->ga4()->get_auth_url());

            $this->assertTrue($this->ga4()->exchange_code('real-code', $state)['success']);
            $this->assertFalse($this->ga4()->exchange_code('real-code', $state)['success']);
            $this->assertCount(1, $GLOBALS['mock_http_calls']);
        }

        public function test_failed_exchange_still_burns_the_state(): void
        {
            $state = $this->stateFrom($this->ga4()->get_auth_url());
            $GLOBALS['mock_http_response'] = ['body' => json_encode(['error' => 'invalid_grant']), 'response' => ['code' => 400]];

            $this->assertSame('failed', $this->ga4()->process_oauth_callback(['code' => 'bad', 'state' => $state]));
            $this->assertSame('invalid_state', $this->ga4()->process_oauth_callback(['code' => 'bad', 'state' => $state]));
            $this->assertCount(1, $GLOBALS['mock_http_calls']);
        }

        // -- source-level guarantees ---------------------------------------------

        public function test_state_is_compared_in_constant_time(): void
        {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/core/services/integrations/class-peanut-integration-ga4-reports.php');

            $this->assertStringContainsString('hash_equals(', $src);
            $this->assertStringContainsString('random_bytes(32)', $src);
            $this->assertStringNotContainsString("wp_create_nonce('peanut_ga4_reports_oauth')", $src);
        }

        public function test_admin_init_handler_redirects_without_the_code(): void
        {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/core/services/integrations/class-peanut-integration-ga4-reports.php');

            $this->assertMatchesRegularExpression('/function maybe_handle_oauth_callback\(\): void/', $src);
            $this->assertStringContainsString('wp_safe_redirect(', $src);
        }

        // -- helpers -------------------------------------------------------------

        private function ga4(): Peanut_Integration_GA4_Reports
        {
            return new Peanut_Integration_GA4_Reports();
        }

        private function stateKey(int $userId): string
        {
            return Peanut_Integration_GA4_Reports::OAUTH_STATE_TRANSIENT_PREFIX . $userId;
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
    }
}
