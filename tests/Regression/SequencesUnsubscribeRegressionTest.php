<?php
/**
 * Every sequence e-mail carried an "Unsubscribe" link
 * (?peanut_unsubscribe=1&sid=&token=), but nothing handled it: clicking it
 * just loaded the home page and the recipient kept getting mail (a CAN-SPAM /
 * GDPR compliance failure). The link is now signed (HMAC-SHA256 under the
 * auth salt), handled on template_redirect (GET confirms, POST or RFC 8058
 * one-click unsubscribes), honors links already sent, unsubscribes the
 * address from every sequence, and blocks re-enrollment.
 *
 * Runs the real Sequences_Module in a separate offline PHP process.
 *
 * @package Peanut_Suite
 */
declare(strict_types=1);
namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class SequencesUnsubscribeRegressionTest extends TestCase {
    public function test_unsubscribe_links_are_signed_handled_and_honored(): void {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/security/sequences-unsubscribe-boundary.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('sequence unsubscribe checks passed', $output);
    }
}
