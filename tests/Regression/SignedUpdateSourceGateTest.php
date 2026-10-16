<?php
declare(strict_types=1);
namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class SignedUpdateSourceGateTest extends TestCase {
    public function test_identified_suite_updates_cannot_bypass_signature_verification(): void {
        $root = dirname(__DIR__, 2);
        $process = proc_open([PHP_BINARY, $root . '/tests/security/suite-update-gate.php', $root . '/core/services/class-peanut-updater.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('named Suite packages fail closed', $output);
    }
}
