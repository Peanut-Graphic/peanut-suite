<?php
/**
 * Regression guard: migrations run on upgrade, not only on activation.
 *
 * Structural root cause (item D): table migrations ran ONLY in the plugin
 * activation hook. The GitHub self-updater swaps the plugin files in place and
 * never re-activates, so any schema change shipped in an update never reached
 * existing installs — needs_upgrade() existed but was never called. New columns
 * (e.g. contacts.source_detail, sequences.from_email) would therefore be absent
 * on every auto-updated site, re-creating the exact silent-write-failure class
 * of bug this PR fixes.
 *
 * Peanut_Database::maybe_upgrade() closes that gap: a cheap get_option-gated
 * check that, when peanut_db_version is stale, re-runs the table creators
 * (dbDelta is idempotent/additive) and bumps the version. It is wired on
 * plugins_loaded in peanut-suite.php so it runs in admin, cron and WP-CLI.
 *
 * This test exercises the GATE logic with an injected migrator spy (no DB
 * required) and statically asserts the plugins_loaded wiring exists. Mirrors
 * the connect check_db_version() self-heal pattern.
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class MigrationOnUpgradeTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Ensure the WordPress function/option mocks are loaded standalone.
        require_once $this->repoRoot() . '/tests/mocks/wordpress-mocks.php';
        if (!defined('PEANUT_TABLE_PREFIX')) {
            define('PEANUT_TABLE_PREFIX', 'peanut_');
        }
        require_once $this->repoRoot() . '/core/database/class-peanut-database.php';

        // Reset mock option/transient store between tests.
        global $mock_options, $mock_transients;
        $mock_options = [];
        $mock_transients = [];
    }

    public function test_maybe_upgrade_method_exists(): void
    {
        $this->assertTrue(
            method_exists(\Peanut_Database::class, 'maybe_upgrade'),
            'Peanut_Database::maybe_upgrade() must exist to self-heal schema on upgrade.'
        );
    }

    public function test_runs_migrator_and_bumps_version_when_stale(): void
    {
        update_option('peanut_db_version', '2.1.0'); // older than current

        $ran = false;
        $result = \Peanut_Database::maybe_upgrade(function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran, 'Stale install must run the migrator.');
        $this->assertTrue($result, 'maybe_upgrade() returns true when it migrated.');
        $this->assertSame(
            \Peanut_Database::current_db_version(),
            get_option('peanut_db_version'),
            'Version option must be bumped to DB_VERSION after migrating.'
        );
    }

    public function test_runs_migrator_when_version_option_absent(): void
    {
        // Fresh-but-unactivated / corrupted-option case: option missing entirely.
        $ran = false;
        $result = \Peanut_Database::maybe_upgrade(function () use (&$ran): void {
            $ran = true;
        });

        $this->assertTrue($ran, 'Missing version option must trigger a migration.');
        $this->assertTrue($result);
        $this->assertSame(
            \Peanut_Database::current_db_version(),
            get_option('peanut_db_version')
        );
    }

    public function test_noop_when_version_current(): void
    {
        update_option('peanut_db_version', \Peanut_Database::current_db_version());
        // Steady state: the schema-drift check has already passed recently, so
        // its result is cached in the transient and maybe_upgrade() takes the
        // fast path without touching $wpdb. (The drift self-heal is covered by
        // SchemaSelfHealTest.)
        set_transient('peanut_suite_schema_ok', \Peanut_Database::current_db_version(), 3600);

        $ran = false;
        $result = \Peanut_Database::maybe_upgrade(function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran, 'Up-to-date install must NOT re-run migrations (cheap gate).');
        $this->assertFalse($result, 'maybe_upgrade() returns false when nothing to do.');
    }

    public function test_wired_on_plugins_loaded(): void
    {
        $src = file_get_contents($this->repoRoot() . '/peanut-suite.php');
        $this->assertNotFalse($src);
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*'plugins_loaded'.*maybe_upgrade/s",
            $src,
            'maybe_upgrade must be wired on the plugins_loaded hook so auto-updated '
            . 'sites self-heal their schema without re-activation.'
        );
    }
}
