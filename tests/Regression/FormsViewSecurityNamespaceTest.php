<?php
declare(strict_types=1);
namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class FormsViewSecurityNamespaceTest extends TestCase {
    public function test_actual_view_callback_resolves_global_security_class(): void {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/security/forms-view-boundary.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('deny-before-write and allowed-view checks passed', $output);
    }
}
