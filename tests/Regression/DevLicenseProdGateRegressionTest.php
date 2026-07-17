<?php
/**
 * Regression guard (audit 2026-07): the dev/demo/test license shortcut must stay
 * gated to non-production. validate_license() previously honored any PEANUT-DEV-*
 * key unconditionally, granting the Agency tier for free on live sites.
 *
 * Verified by static source scan (no WP bootstrap / no DB) — mirrors the style of
 * ExportPiiLockdownRegressionTest. Fails if the gate is removed or neutered.
 */

declare(strict_types=1);

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class DevLicenseProdGateRegressionTest extends TestCase
{
    private static string $src = '';

    public static function setUpBeforeClass(): void
    {
        self::$src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/services/class-peanut-license.php'
        );
    }

    public function test_validate_gates_dev_license_behind_allowed_check(): void
    {
        $this->assertMatchesRegularExpression(
            '/is_dev_license\([^)]*\)\s*&&\s*\$this->dev_license_allowed\(\)/',
            self::$src,
            'validate_license() must gate the dev-license shortcut behind dev_license_allowed().'
        );
    }

    public function test_gate_consults_the_environment(): void
    {
        $this->assertMatchesRegularExpression(
            '/function\s+dev_license_allowed\b.*wp_get_environment_type\s*\(/s',
            self::$src,
            'dev_license_allowed() must consult wp_get_environment_type().'
        );
    }

    public function test_gate_is_not_unconditionally_true(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+dev_license_allowed\s*\(\s*\)\s*:\s*bool\s*\{\s*return\s+true\s*;/s',
            self::$src,
            'dev_license_allowed() must not unconditionally return true (that reopens the backdoor).'
        );
    }
}
