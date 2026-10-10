<?php
/**
 * The Security module's login 2FA was unsafe and did not work:
 *  - codes came from mt_rand() (not a CSPRNG);
 *  - a challenge accepted unlimited guesses for its 10 minutes, so the
 *    1,000,000-code space could be brute-forced;
 *  - a correct code re-fired wp_login, which started a NEW challenge, so the
 *    user was sent back to the code form forever;
 *  - "totp" was offered but never implemented: with no secret it skipped 2FA
 *    entirely, with one it locked the user out;
 *  - the "Resend code" link had no handler.
 *
 * Runs the real Security_Module in a separate offline PHP process.
 *
 * @package Peanut_Suite
 */
declare(strict_types=1);
namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class TwoFactorEmailCodeRegressionTest extends TestCase {
    public function test_email_2fa_is_csprng_attempt_limited_expiring_and_completes(): void {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/security/two-factor-boundary.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $output);
        $this->assertStringContainsString('two-factor flow checks passed', $output);
    }
}
