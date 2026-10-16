<?php
declare(strict_types=1);
namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class LicenseRequestTransportTest extends TestCase {
    public function test_exact_identity_and_key_free_update_urls(): void {
        $root = dirname(__DIR__, 2);
        $process = proc_open([PHP_BINARY, $root . '/scripts/tests/pr98-client-requests.php', $root . '/core/services/class-peanut-license.php', 'suite'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('update-check and plugin-info credentials remain headers', $output);
    }
}
