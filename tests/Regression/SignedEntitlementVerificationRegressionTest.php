<?php
/**
 * Regression guard (audit 2026-07, C1b client half): the client must verify the
 * server's Ed25519-signed entitlement before trusting the granted tier, and must
 * reject a present-but-invalid signature. Static source scan (no WP/DB) — fails if
 * the verification call or its checks are removed.
 */

declare(strict_types=1);

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class SignedEntitlementVerificationRegressionTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/services/class-peanut-license.php'
        );
    }

    public function test_remote_validate_verifies_before_trusting_tier(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$verified\s*=\s*\$this->verify_entitlement\(/',
            self::$src,
            'remote_validate() must call verify_entitlement() before trusting the tier.'
        );
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$verified\s*===\s*false/',
            self::$src,
            'A present-but-invalid signature ($verified === false) must be handled (rejected).'
        );
    }

    public function test_signature_is_cryptographically_verified_and_bound(): void
    {
        $this->assertMatchesRegularExpression(
            '/function\s+verify_entitlement\b.*sodium_crypto_sign_verify_detached\s*\(/s',
            self::$src,
            'verify_entitlement() must call sodium_crypto_sign_verify_detached().'
        );
        $this->assertMatchesRegularExpression(
            '/function\s+verify_entitlement\b.*hash_equals\s*\(/s',
            self::$src,
            'verify_entitlement() must bind the signature to the license key via hash_equals().'
        );
    }
}
