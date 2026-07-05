<?php
/**
 * Property/unit tests (Testing Protocol v2, Net 6) for the update-package
 * signature verifier.
 *
 * Regression guard for security/verify-update-signature: the self-updater now
 * refuses to install its own GitHub-release package unless the ZIP's sha256 +
 * detached Ed25519 signature verify against our embedded public key. The
 * cryptographic core is extracted into the pure, side-effect-free helper
 * Peanut_Updater::verify_bytes() so it can be exercised without a WordPress
 * boot, a network fetch, or the filesystem.
 *
 * Seam: class-peanut-updater.php guards on `if (!defined('ABSPATH')) exit;` and
 * pulls in NO WordPress symbols on the verify_bytes() code path, so we define
 * ABSPATH and require the file directly — no plugin bootstrap, no DB.
 *
 * The helper hard-codes OUR public key for production, but accepts a public key
 * override purely so the round-trip can be signed with a throwaway test keypair
 * (we do not — and must not — hold the real private signing key here).
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Property;

use PHPUnit\Framework\TestCase;
use Peanut_Updater;

final class UpdateSignatureVerificationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Satisfy the `if (!defined('ABSPATH')) exit;` guard without WordPress.
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }

        require_once dirname(__DIR__, 2) . '/core/services/class-peanut-updater.php';
    }

    protected function setUp(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('libsodium not available in this PHP build.');
        }
    }

    /**
     * Build a valid manifest (correct sha256 + detached Ed25519 signature) for
     * the given bytes, signed by the given secret key.
     *
     * @return array{sha256:string,signature:string}
     */
    private function signManifest(string $bytes, string $secretKey): array
    {
        return [
            'sha256'    => hash('sha256', $bytes),
            'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $secretKey)),
        ];
    }

    public function test_correctly_signed_payload_verifies_true(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $pub     = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret  = sodium_crypto_sign_secretkey($keypair);

        $bytes    = random_bytes(2048); // stand-in for the release ZIP
        $manifest = $this->signManifest($bytes, $secret);

        $this->assertTrue(
            Peanut_Updater::verify_bytes($bytes, $manifest, $pub),
            'A correctly signed payload with a matching sha256 must verify.'
        );
    }

    public function test_tampered_payload_fails(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $pub     = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret  = sodium_crypto_sign_secretkey($keypair);

        $bytes    = random_bytes(2048);
        $manifest = $this->signManifest($bytes, $secret); // manifest signs the ORIGINAL

        $tampered = $bytes . 'x'; // flip the payload after signing

        $this->assertFalse(
            Peanut_Updater::verify_bytes($tampered, $manifest, $pub),
            'A payload that no longer matches the signed sha256 must be rejected.'
        );
    }

    public function test_wrong_sha_fails(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $pub     = base64_encode(sodium_crypto_sign_publickey($keypair));
        $secret  = sodium_crypto_sign_secretkey($keypair);

        $bytes            = random_bytes(2048);
        $manifest         = $this->signManifest($bytes, $secret);
        $manifest['sha256'] = hash('sha256', 'a completely different payload');

        $this->assertFalse(
            Peanut_Updater::verify_bytes($bytes, $manifest, $pub),
            'A manifest whose sha256 does not match the bytes must be rejected.'
        );
    }

    public function test_missing_signature_fails(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $pub     = base64_encode(sodium_crypto_sign_publickey($keypair));

        $bytes = random_bytes(2048);

        $this->assertFalse(
            Peanut_Updater::verify_bytes($bytes, ['sha256' => hash('sha256', $bytes)], $pub),
            'A manifest with no signature must be rejected (fail-closed).'
        );
        $this->assertFalse(
            Peanut_Updater::verify_bytes($bytes, [], $pub),
            'An empty manifest must be rejected (fail-closed).'
        );
    }

    public function test_signature_from_wrong_key_fails(): void
    {
        // Sign with one key, verify against a different (unrelated) key.
        $signer   = sodium_crypto_sign_keypair();
        $attacker = sodium_crypto_sign_keypair();

        $bytes    = random_bytes(2048);
        $manifest = $this->signManifest($bytes, sodium_crypto_sign_secretkey($signer));

        $wrongPub = base64_encode(sodium_crypto_sign_publickey($attacker));

        $this->assertFalse(
            Peanut_Updater::verify_bytes($bytes, $manifest, $wrongPub),
            'A signature made by a different key must not verify against our key.'
        );
    }
}
