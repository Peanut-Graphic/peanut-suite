<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Suite refuses update packages that are not cryptographically ours.
 *
 * Suite shipped with no signature gate at all: whatever package a site was
 * handed for this plugin, it installed, on transport trust alone. Transport
 * trust is not authenticity.
 *
 * These tests pin the wiring rather than the crypto (the verifier itself is
 * covered in formflow-core). The wiring is what was missing, and it is the part
 * a future refactor can silently drop.
 */
final class SignedUpdateGateTest extends TestCase {

    private string $source;

    protected function setUp(): void {
        parent::setUp();
        $this->source = file_get_contents(dirname(__DIR__, 2) . '/peanut-suite.php');
    }

    public function test_the_gate_is_registered_on_plugins_loaded(): void {
        $this->assertStringContainsString(
            "add_action('plugins_loaded', 'peanut_suite_register_update_gate', 1)",
            $this->source,
            'The update gate is never registered, so nothing verifies an update package.'
        );
    }

    public function test_the_gate_is_constructed_with_this_plugins_identity(): void {
        $this->assertStringContainsString('PEANUT_PLUGIN_BASENAME', $this->source);
        $this->assertStringContainsString('PEANUT_SUITE_SIGNING_PUBKEY', $this->source);
        $this->assertStringContainsString("'peanut-suite'", $this->source);
    }

    public function test_it_pins_the_fleet_signing_key(): void {
        // The key the central publisher signs manifests against. A different key
        // here means every legitimate release is refused, and — worse — a key an
        // attacker chose would mean none of them are.
        $this->assertStringContainsString(
            "define('PEANUT_SUITE_SIGNING_PUBKEY', 'NtHnWTBLVzCBKMAq9CO8LHDSD9ZfpGV0UloQdgToIwM=')",
            $this->source
        );
    }

    public function test_only_peanut_and_github_hosts_are_trusted(): void {
        $this->assertStringContainsString("['peanutgraphic.com', 'github.com']", $this->source);
    }

    public function test_a_missing_verifier_warns_loudly_instead_of_failing_open(): void {
        $this->assertStringContainsString('Updates are NOT being verified', $this->source);
        $this->assertStringContainsString('admin_notices', $this->source);
    }

    public function test_formflow_core_is_a_declared_dependency(): void {
        $composer = json_decode(file_get_contents(dirname(__DIR__, 2) . '/composer.json'), true);

        $this->assertArrayHasKey(
            'peanut/formflow-core',
            $composer['require'] ?? [],
            'Without formflow-core in require, vendor/ will not carry the verifier and the gate degrades to a notice.'
        );
    }

    public function test_the_publisher_must_ship_vendor_for_this_to_work(): void {
        // The gate lives in vendor/. If the central publisher packages Suite
        // without it (BUILD=assets, no vendor in INCLUDE — which is how Suite was
        // configured before this change), every install degrades to the notice
        // path and updates go unverified. This test is the reminder.
        $this->assertFileExists(dirname(__DIR__, 2) . '/composer.lock');
    }
}
