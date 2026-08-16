<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Suite refuses update packages that are not cryptographically ours.
 *
 * Suite has verified its own updates since 4.2.3 — Peanut_Updater hooks
 * `upgrader_pre_download` and refuses anything whose `.manifest.json` sha256 or
 * detached Ed25519 signature does not check out.
 *
 * Nothing pinned that wiring, which is how it gets removed by accident: the
 * verifier is one `add_filter` and one `new Peanut_Updater()` away from being
 * silently inert, and an inert verifier looks exactly like a working one until
 * someone ships an unsigned package.
 *
 * These tests pin the wiring, not the crypto (the signature maths has its own
 * property test in tests/Property/UpdateSignatureVerificationTest.php).
 */
final class UpdateVerifierWiringTest extends TestCase {

    private string $updater;

    private string $main;

    protected function setUp(): void {
        parent::setUp();
        $this->updater = file_get_contents(dirname(__DIR__, 2) . '/core/services/class-peanut-updater.php');
        $this->main = file_get_contents(dirname(__DIR__, 2) . '/peanut-suite.php');
    }

    public function test_the_verifier_is_hooked_before_wordpress_downloads(): void {
        $this->assertStringContainsString(
            "add_filter('upgrader_pre_download', [\$this, 'verify_package_signature']",
            $this->updater,
            'Nothing intercepts the download, so WordPress installs whatever it is handed.'
        );
    }

    public function test_the_updater_is_actually_instantiated(): void {
        // A verifier that is never constructed registers nothing. This is the
        // cheapest way for the whole gate to become decorative.
        $this->assertStringContainsString('new Peanut_Updater()', $this->main);
    }

    public function test_it_pins_the_fleet_signing_key(): void {
        // The key the central publisher signs manifests against. A different key
        // means every legitimate release is refused, and a key an attacker chose
        // means none of them are.
        $this->assertStringContainsString(
            "PEANUT_SIGNING_PUBKEY = 'NtHnWTBLVzCBKMAq9CO8LHDSD9ZfpGV0UloQdgToIwM='",
            $this->updater
        );
    }

    public function test_a_missing_manifest_refuses_the_install(): void {
        $this->assertStringContainsString('refusing to install an unsigned package', $this->updater);
    }

    public function test_the_package_hash_is_compared_in_constant_time(): void {
        // hash_equals, not ===: a timing-variable comparison on a digest is the
        // kind of subtlety that survives review precisely because it works.
        $this->assertStringContainsString('hash_equals(', $this->updater);
    }

    public function test_suite_does_not_also_bundle_the_formflow_core_gate(): void {
        // Deliberate: Suite verifies via its own Peanut_Updater. Adding
        // formflow-core's SignedUpdateGate would hook upgrader_pre_download a
        // SECOND time, so two filters would each download and verify the same
        // package. Redundant at best, and it obscures which one is authoritative.
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertArrayNotHasKey('peanut/formflow-core', $composer['require'] ?? []);
        $this->assertStringNotContainsString('SignedUpdateGate', $this->main);
    }
}
