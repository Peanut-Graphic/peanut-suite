<?php
/**
 * Regression guard: license validation must not hammer the license server.
 *
 * Incident 2026-07-21. peanutgraphic.com dogfoods every Peanut plugin, so its
 * licence calls resolve to *itself*. Each validate took a PHP-FPM worker, that
 * worker issued an HTTP request back into the same pool, and the pool starved
 * until the request 504'd after 15s. Because validate_license() cached ONLY a
 * successful ("active") result, every failure was retried on the very next
 * trigger — a self-sustaining loop running ~24 req/min since 2026-07-01. The
 * licence server was intermittently unable to serve /updates/check, so no site
 * in the fleet could reliably update (FormFlow Lite 3.2.22-3.2.24 never
 * installed anywhere).
 *
 * Two independent defects, so two independent guards:
 *
 *   1. Failures must be cached with a backoff. Without it ANY licence-server
 *      outage becomes permanent: every client retries on every trigger with no
 *      pause, so the server can never climb out from under the load.
 *   2. The licence server must never HTTP itself. A host that is its own API
 *      deadlocks one worker against another in the same pool.
 *
 * Static source scan, matching this suite's convention (no WP bootstrap, no DB).
 */

declare(strict_types=1);

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class LicenseValidationBackoffRegressionTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/services/class-peanut-license.php'
        );
    }

    /**
     * Isolate validate_license() so a set_transient() elsewhere in the class
     * cannot satisfy these assertions.
     */
    private function validateLicenseBody(): string
    {
        $found = preg_match(
            '/function validate_license\s*\([^)]*\)\s*:\s*array\s*\{(.*?)\n    \}/s',
            self::$src,
            $m
        );

        $this->assertSame(1, $found, 'Could not isolate validate_license().');

        return $m[1];
    }

    /**
     * The core defect. `if ($result['status'] === 'active') { set_transient(...) }`
     * leaves every failure uncached and therefore instantly retried.
     */
    public function test_failed_validation_is_cached_so_it_is_not_retried_immediately(): void
    {
        $body = $this->validateLicenseBody();

        $this->assertDoesNotMatchRegularExpression(
            "/if\s*\(\s*\\\$result\['status'\]\s*===\s*'active'\s*\)\s*\{\s*set_transient\([^}]*\}\s*return \\\$result;/s",
            $body,
            'validate_license() still caches ONLY the active result, so every failure is '
            . 'retried on the next trigger. That is the loop that took the licence server down.'
        );

        $this->assertMatchesRegularExpression(
            '/FAILURE_CACHE_DURATION/',
            $body,
            'validate_license() must cache failures under a distinct, shorter backoff TTL.'
        );
    }

    /**
     * The backoff has to be short enough that a real fix propagates quickly,
     * and long enough that it is not a hammer. Anything under a minute is not
     * a backoff.
     */
    public function test_failure_backoff_is_a_sane_duration(): void
    {
        $found = preg_match(
            '/const\s+FAILURE_CACHE_DURATION\s*=\s*([^;]+);/',
            self::$src,
            $m
        );

        $this->assertSame(1, $found, 'FAILURE_CACHE_DURATION must be declared as a constant.');
        $this->assertMatchesRegularExpression(
            '/MINUTE_IN_SECONDS|HOUR_IN_SECONDS/',
            $m[1],
            'Express the backoff in WordPress time constants so it is readable.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*[0-9]+\s*$/',
            $m[1],
            'A bare number here is almost always seconds-vs-minutes confusion.'
        );
    }

    /**
     * The failure cache must be shorter than the success cache — a bad result
     * should not be pinned for as long as a good one.
     */
    public function test_failure_cache_is_shorter_than_success_cache(): void
    {
        preg_match('/const\s+CACHE_DURATION\s*=\s*([^;]+);/', self::$src, $ok);
        preg_match('/const\s+FAILURE_CACHE_DURATION\s*=\s*([^;]+);/', self::$src, $bad);

        $this->assertNotEmpty($ok[1] ?? '', 'CACHE_DURATION missing.');
        $this->assertNotEmpty($bad[1] ?? '', 'FAILURE_CACHE_DURATION missing.');

        $eval = static function (string $expr): int {
            $expr = str_replace(
                ['MINUTE_IN_SECONDS', 'HOUR_IN_SECONDS', 'DAY_IN_SECONDS'],
                ['60', '3600', '86400'],
                trim($expr)
            );
            $this_val = 0;
            // Only digits, operators and whitespace remain — safe to evaluate.
            if (preg_match('/^[0-9\s*+\/()-]+$/', $expr)) {
                $this_val = (int) eval("return {$expr};");
            }
            return $this_val;
        };

        $this->assertGreaterThan(
            0,
            $eval($bad[1]),
            'FAILURE_CACHE_DURATION did not evaluate to a positive duration.'
        );
        $this->assertLessThan(
            $eval($ok[1]),
            $eval($bad[1]),
            'A failed validation must not be cached as long as a successful one.'
        );
    }

    /**
     * Defect 2: the licence server calling its own API deadlocks the pool.
     */
    public function test_self_hosted_licence_server_does_not_call_itself_over_http(): void
    {
        $this->assertMatchesRegularExpression(
            '/function\s+is_self_hosted_licence_server\b/',
            self::$src,
            'There is no self-host check, so a site that IS the licence server will '
            . 'still issue an HTTP request to itself and deadlock its own PHP pool.'
        );
    }

    public function test_self_host_check_compares_hosts_not_full_urls(): void
    {
        $found = preg_match(
            '/function\s+is_self_hosted_licence_server\b.*?\n    \}/s',
            self::$src,
            $m
        );
        $this->assertSame(1, $found, 'Could not isolate is_self_hosted_licence_server().');

        $this->assertMatchesRegularExpression(
            '/PHP_URL_HOST/',
            $m[0],
            'Compare hosts, not raw URLs — scheme, port, trailing slash and www all '
            . 'differ harmlessly and a string compare would miss the self-call.'
        );
    }

    /**
     * The check is worthless if remote_validate() never consults it.
     */
    public function test_remote_validate_short_circuits_on_the_self_host(): void
    {
        $found = preg_match(
            '/function remote_validate\s*\([^)]*\)\s*:\s*array\s*\{(.*?)\n    \}/s',
            self::$src,
            $m
        );
        $this->assertSame(1, $found, 'Could not isolate remote_validate().');

        $this->assertMatchesRegularExpression(
            '/is_self_hosted_licence_server\s*\(\s*\)/',
            $m[1],
            'remote_validate() never consults the self-host check, so the guard exists '
            . 'but the deadlock still happens.'
        );

        $this->assertStringNotContainsString(
            'wp_remote_post',
            substr($m[1], 0, (int) strpos($m[1], 'is_self_hosted_licence_server')),
            'The self-host check must come BEFORE the outbound request, not after it.'
        );
    }
}
