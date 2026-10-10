<?php
/**
 * POST /track/identify is public (__return_true) and copied
 * traits.contact_id from the request onto the visitor row, so any caller
 * could link any visitor (and its page journey) to any CRM contact by id, and
 * passed the caller's contact_id on to peanut_visitor_identified listeners.
 * The route now ignores contact_id from request input; only server-side code
 * that already knows the contact may link it.
 *
 * Runs the real namespaced callback in a separate offline PHP process (same
 * pattern as FormsViewSecurityNamespaceTest).
 *
 * @package Peanut_Suite
 */
declare(strict_types=1);
namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class VisitorsIdentifyContactIdRegressionTest extends TestCase {
    public function test_public_identify_ignores_a_caller_supplied_contact_id(): void {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/security/visitors-identify-contact-boundary.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('identify ignores caller contact_id checks passed', $output);
    }
}
