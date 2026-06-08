<?php
/**
 * Contract tests (Testing Protocol v2, Net 7) for Peanut Suite.
 *
 * STATUS: BLOCKED — pinning the shape of a `/wp-json/peanut/v1/*` endpoint requires
 * a booting WordPress test harness (the WP test library + a registered REST server).
 * This repo has NO WP test lib wired (`tests/bootstrap.php` falls back to function
 * mocks when `WP_TESTS_DIR` is absent), so the REST routes are never registered and
 * a real request/response contract cannot be exercised here without fabricating it.
 *
 * Per the protocol: report BLOCKED with the reason rather than fake a green contract.
 * Unblocks when the WP test suite is installed in CI (install-wp-tests.sh + a MySQL
 * service) so `rest_do_request('/peanut/v1/...')` returns a real response to pin.
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Contract;

use PHPUnit\Framework\TestCase;

final class RestApiContractTest extends TestCase
{
    public function test_rest_contract_requires_wp_harness(): void
    {
        if (!function_exists('rest_do_request') || !defined('WP_TESTS_DIR') && !getenv('WP_TESTS_DIR')) {
            $this->markTestSkipped(
                'Net 7 contract BLOCKED: no booting WP test harness in this repo — ' .
                'REST routes (/wp-json/peanut/v1/*) are not registered under mock bootstrap. ' .
                'Tracked in known-gaps.md.'
            );
        }

        // When a real WP harness is available, pin one endpoint's shape here, e.g.:
        //   $res = rest_do_request(new \WP_REST_Request('GET', '/peanut/v1/links'));
        //   $this->assertSame(200, $res->get_status());
        $this->fail('WP harness present but contract not yet implemented.');
    }
}
