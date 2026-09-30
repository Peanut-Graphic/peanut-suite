<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The two places Suite stores secrets through Peanut_Encryption.
 *
 * 1. GA4 Reports OAuth tokens (option peanut_ga4_reports_tokens). Values
 *    written as legacy AES-256-CBC must still work, and the next token
 *    refresh (a write) must re-store BOTH tokens in the authenticated V2
 *    format — that is the lazy migration.
 *
 * 2. Monitor site keys (option peanut_monitor_site_key_{id}), the bearer
 *    credential Suite sends to a child site's Peanut Connect. Through 4.2.9
 *    Monitor_Sites called Peanut_Encryption::encrypt()/decrypt() statically
 *    on instance methods, which is a fatal Error on PHP 8, so adding a site
 *    500'd after the row was inserted and no key was ever stored.
 */
final class EncryptionCallersRegressionTest extends TestCase {

    /** 4.2.9 CBC ciphertexts under the mock default key sha256('test_salt_auth'). */
    private const LEGACY_ACCESS = '4jZX9fd5hN3ny4J/W03yJycBZw0nmozlGe65Q183KspkznqG4mrqFOm6B7LVVgM8';
    private const LEGACY_ACCESS_PLAIN = 'ya29.legacy-ga4-access-token';

    public static function setUpBeforeClass(): void {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        if (!function_exists('admin_url')) {
            function admin_url($path = '') {
                return 'https://suite.example/wp-admin/' . ltrim((string) $path, '/');
            }
        }
        $root = dirname(__DIR__, 2);
        require_once $root . '/core/services/class-peanut-encryption.php';
        require_once $root . '/core/services/class-peanut-settings-secrets.php';
        require_once $root . '/core/services/integrations/class-peanut-integration-ga4-reports.php';
        require_once $root . '/modules/monitor/class-monitor-sites.php';
    }

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['mock_options'] = [];
        $GLOBALS['mock_http_calls'] = [];
        unset($GLOBALS['mock_http_response']);
    }

    protected function tearDown(): void {
        $GLOBALS['mock_options'] = [];
        $GLOBALS['mock_http_calls'] = [];
        unset($GLOBALS['mock_http_response']);
        parent::tearDown();
    }

    public function test_legacy_ga4_access_token_is_still_readable(): void {
        update_option('peanut_ga4_reports_tokens', [
            'access_token' => self::LEGACY_ACCESS,
            'refresh_token' => '',
            'expires_at' => time() + 600,
        ]);

        $this->assertSame(self::LEGACY_ACCESS_PLAIN, (new Peanut_Integration_GA4_Reports())->get_access_token());
    }

    public function test_ga4_token_refresh_rewrites_legacy_tokens_as_v2(): void {
        $enc = new Peanut_Encryption();
        // A legacy CBC refresh token, as 4.2.9 would have stored it.
        $legacy_refresh = self::legacy_cbc($enc, '1//legacy-refresh-token');
        $this->assertTrue($enc->needs_reencrypt($legacy_refresh));

        // A refresh needs configured OAuth client credentials; without them
        // nothing is sent to Google (see Ga4ClientSecretAtRestRegressionTest).
        update_option('peanut_settings', [
            'ga4_reports_client_id' => 'cid',
            'ga4_reports_client_secret' => (new Peanut_Encryption())->encrypt('GOCSPX-test'),
        ]);
        update_option('peanut_ga4_reports_tokens', [
            'access_token' => self::LEGACY_ACCESS,
            'refresh_token' => $legacy_refresh,
            'expires_at' => time() - 1,
        ]);
        $GLOBALS['mock_http_response'] = [
            'body' => json_encode(['access_token' => 'ya29.new', 'expires_in' => 3600]),
            'response' => ['code' => 200],
        ];

        $this->assertSame('ya29.new', (new Peanut_Integration_GA4_Reports())->get_access_token());

        // The legacy refresh token was decrypted and sent to Google.
        $this->assertSame('1//legacy-refresh-token', $GLOBALS['mock_http_calls'][0]['args']['body']['refresh_token']);

        $stored = get_option('peanut_ga4_reports_tokens');
        $this->assertTrue(Peanut_Encryption::is_v2($stored['access_token']));
        $this->assertTrue(Peanut_Encryption::is_v2($stored['refresh_token']));
        $this->assertSame('ya29.new', $enc->decrypt($stored['access_token']));
        $this->assertSame('1//legacy-refresh-token', $enc->decrypt($stored['refresh_token']));
    }

    public function test_monitor_site_key_is_stored_encrypted_and_read_back(): void {
        $sites = new Monitor_Sites();

        $this->assertTrue($sites->store_site_key(7, 'connect-site-key-abc'));

        $stored = get_option('peanut_monitor_site_key_7');
        $this->assertTrue(Peanut_Encryption::is_v2($stored));
        $this->assertStringNotContainsString('connect-site-key-abc', $stored);

        $read = new ReflectionMethod(Monitor_Sites::class, 'get_site_key');
        $read->setAccessible(true);
        $this->assertSame('connect-site-key-abc', $read->invoke($sites, 7));
    }

    public function test_tampered_monitor_site_key_yields_no_credential(): void {
        $sites = new Monitor_Sites();
        $sites->store_site_key(8, 'connect-site-key-abc');

        $stored = get_option('peanut_monitor_site_key_8');
        $raw = base64_decode(substr($stored, strlen(Peanut_Encryption::V2_PREFIX)), true);
        $raw[30] = chr(ord($raw[30]) ^ 0x01);
        update_option('peanut_monitor_site_key_8', Peanut_Encryption::V2_PREFIX . base64_encode($raw));

        $read = new ReflectionMethod(Monitor_Sites::class, 'get_site_key');
        $read->setAccessible(true);
        $this->assertNull($read->invoke($sites, 8), 'A value that fails authentication must not become a bearer token.');
    }

    /** Reproduce the 4.2.9 write format exactly (bare base64(iv . AES-256-CBC)). */
    private static function legacy_cbc(Peanut_Encryption $enc, string $plain): string {
        $key = hash('sha256', wp_salt('auth'), true);
        $iv = random_bytes(16);
        return base64_encode($iv . openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv));
    }
}
