<?php
/**
 * Regression guard: Hub-migration export PII-exposure lockdown (cluster W1).
 *
 * Three P0/P2 holes this locks shut, so they can't silently regress:
 *
 *  1. (P0) cli/export-to-hub.php required wp-load.php and dumped the ENTIRE
 *     database with no WP-CLI guard. A direct web GET of the file bootstrapped
 *     WordPress and dumped contacts/user-emails/invoices — with the output path
 *     read from $argv[1], which register_argc_argv populates from the query
 *     string. The fix refuses unless (defined('WP_CLI') && WP_CLI) BEFORE the
 *     wp-load require. Verified here by static source scan (the file can't be
 *     executed without a WordPress install).
 *
 *  2. (P0) The exporter wrote full PII dumps to a predictable, web-readable dir
 *     (wp-content/peanut-hub-export/*.json) with no protection. The fix drops a
 *     web-denied `.htaccess` + an empty `index.php` into the dir on creation.
 *     Verified behaviourally against Peanut_Hub_Exporter::harden_export_dir().
 *
 *  3. (P2) Peanut_Security::get_client_ip() trusted client-supplied forwarding
 *     headers (X-Forwarded-For / CF-Connecting-IP) unconditionally, so anyone
 *     could spoof their IP to evade rate limiting / poison logs. The fix trusts
 *     forwarding headers ONLY when REMOTE_ADDR is a configured trusted proxy.
 *
 * Self-contained: pure PHP, no WordPress boot, no live export. Mirrors the
 * sibling Regression guards (MigrationOnUpgradeTest / NamespacedClassCallSiteTest).
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class ExportPiiLockdownRegressionTest extends TestCase
{
    /** Repo root (this file is tests/Regression/<name>.php). */
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Load the standalone WordPress function/constant mocks (defines ABSPATH,
        // WP_CONTENT_DIR, get_option, etc.).
        require_once $this->repoRoot() . '/tests/mocks/wordpress-mocks.php';

        // Ensure the exporter's file-scope auto-run stays disarmed when we load it.
        if (!defined('PEANUT_SUITE_TESTING')) {
            define('PEANUT_SUITE_TESTING', true);
        }

        // Reset option store between tests.
        global $mock_options;
        $mock_options = [];
    }

    // ---- Finding 1: cli/export-to-hub.php denied over the web -------------------

    public function test_cli_exporter_refuses_web_requests_before_bootstrapping_wp(): void
    {
        $src = file_get_contents($this->repoRoot() . '/cli/export-to-hub.php');
        $this->assertIsString($src);

        $guardPos   = strpos($src, "defined('WP_CLI')");
        $forbidPos  = strpos($src, 'http_response_code(403)');
        $wpLoadPos  = strpos($src, 'wp-load.php');

        $this->assertNotFalse($guardPos, 'cli/export-to-hub.php must gate on WP_CLI.');
        $this->assertNotFalse($forbidPos, 'cli/export-to-hub.php must send a 403 when not under WP-CLI.');
        $this->assertNotFalse($wpLoadPos, 'cli/export-to-hub.php still requires wp-load.php under WP-CLI.');

        // The refusal MUST come before wp-load is required, or WP is already booted
        // and the DB dump has effectively started.
        $this->assertLessThan(
            $wpLoadPos,
            $guardPos,
            'The WP_CLI guard must precede the wp-load require.'
        );
        $this->assertLessThan(
            $wpLoadPos,
            $forbidPos,
            'The 403 refusal must precede the wp-load require.'
        );
        $this->assertStringContainsString('exit(', $src, 'The web-request path must exit.');
    }

    // ---- Finding 2: export dir hardened on creation ----------------------------

    public function test_export_dir_gets_htaccess_and_index_on_creation(): void
    {
        require_once $this->repoRoot() . '/export-to-hub.php';
        $this->assertTrue(
            method_exists(\Peanut_Hub_Exporter::class, 'harden_export_dir'),
            'Peanut_Hub_Exporter::harden_export_dir() must exist.'
        );

        $dir = sys_get_temp_dir() . '/peanut-export-test-' . uniqid();
        mkdir($dir, 0700, true);

        try {
            \Peanut_Hub_Exporter::harden_export_dir($dir);

            $this->assertFileExists($dir . '/.htaccess', 'A deny-all .htaccess must be written.');
            $this->assertFileExists($dir . '/index.php', 'A silence-is-golden index.php must be written.');

            $htaccess = file_get_contents($dir . '/.htaccess');
            $this->assertStringContainsString('Require all denied', $htaccess, 'Apache 2.4 deny directive missing.');
            $this->assertStringContainsString('Deny from all', $htaccess, 'Apache 2.2 deny directive missing.');

            $this->assertStringContainsString(
                'Silence is golden',
                file_get_contents($dir . '/index.php'),
                'index.php must not leak directory contents.'
            );
        } finally {
            @unlink($dir . '/.htaccess');
            @unlink($dir . '/index.php');
            @rmdir($dir);
        }
    }

    // ---- Finding 3: IP resolver ignores spoofed XFF from an untrusted peer ------

    public function test_ip_resolver_ignores_spoofed_xff_when_remote_untrusted(): void
    {
        $this->loadSecurity();

        // No trusted proxies configured (default): forwarding headers are attacker-controlled.
        $_SERVER['REMOTE_ADDR']          = '203.0.113.9';   // real connecting peer
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';       // spoofed
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '5.6.7.8';      // spoofed

        $this->assertSame(
            '203.0.113.9',
            \Peanut_Security::get_client_ip(),
            'With no trusted proxy configured, only REMOTE_ADDR may be returned.'
        );

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    public function test_ip_resolver_honors_xff_only_from_a_trusted_proxy(): void
    {
        $this->loadSecurity();

        update_option('peanut_trusted_proxies', '203.0.113.9');

        $_SERVER['REMOTE_ADDR']          = '203.0.113.9';   // configured trusted proxy
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.9';

        $this->assertSame(
            '1.2.3.4',
            \Peanut_Security::get_client_ip(),
            'Behind a configured trusted proxy, the left-most XFF client IP is used.'
        );

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    private function loadSecurity(): void
    {
        require_once $this->repoRoot() . '/core/services/class-peanut-security.php';
    }

    // ---- uninstall purges the PII dump dir -------------------------------------

    public function test_uninstall_purges_export_artifacts(): void
    {
        $src = file_get_contents($this->repoRoot() . '/uninstall.php');
        $this->assertIsString($src);

        $this->assertStringContainsString('peanut-hub-export', $src, 'uninstall must remove the export dir.');
        $this->assertStringContainsString('peanut-hub-export.zip', $src, 'uninstall must remove the export zip.');
        $this->assertStringContainsString('@unlink', $src, 'uninstall must delete the dumped files.');
    }
}
