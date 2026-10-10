<?php
/**
 * POST /forms/track/interaction and /forms/track/abandon are public
 * (__return_true) and wrote to the form stats tables on every request with no
 * rate limit, unlike /forms/track/view, so one client could inflate or
 * pollute form analytics at will and grow peanut_form_field_stats without
 * bound (any form_id x field_name). Both now go through
 * Peanut_Security::check_rate_limit() before any query, refuse empty keys,
 * cut keys to the column width and clamp time_spent.
 *
 * Runs the real namespaced callbacks in a separate offline PHP process (same
 * pattern as FormsViewSecurityNamespaceTest).
 *
 * @package Peanut_Suite
 */
declare(strict_types=1);
namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class FormsTrackingThrottleRegressionTest extends TestCase {
    public function test_interaction_and_abandon_are_throttled_and_validated_before_writes(): void {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/security/forms-tracking-throttle-boundary.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('form tracking throttle and validation checks passed', $output);
    }
}
