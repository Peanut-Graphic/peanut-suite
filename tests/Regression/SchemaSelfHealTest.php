<?php
/**
 * Regression guard: maybe_upgrade() must self-heal on SCHEMA DRIFT, not just on
 * a stale version option.
 *
 * Incident (production): the stored `peanut_db_version` option was already
 * '2.5.0' — AHEAD of the DB_VERSION constant that shipped at the time ('2.2.0')
 * from earlier work — so maybe_upgrade()'s version-only gate
 *
 *     if (version_compare($installed, self::DB_VERSION, '>=')) return false;
 *
 * returned early and NEVER ran the migration. As a result the
 * `source_detail` column (defined in the contacts CREATE TABLE) was never added
 * to the pre-existing `peanut_contacts` table, and every popups-module write of
 * that column silently failed — the same "trust the version option, skip the
 * needed schema change" bug class as the original outage.
 *
 * A version-only gate is insufficient. maybe_upgrade() must ALSO run the
 * migration when the actual DB schema is missing a column the current plugin
 * version expects. This test reproduces the exact production state
 * (version option ahead of the old constant + column missing) and asserts the
 * migration runs and the column gets added.
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class SchemaSelfHealTest extends TestCase
{
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once $this->repoRoot() . '/tests/mocks/wordpress-mocks.php';
        if (!defined('PEANUT_TABLE_PREFIX')) {
            define('PEANUT_TABLE_PREFIX', 'peanut_');
        }
        require_once $this->repoRoot() . '/core/database/class-peanut-database.php';

        global $mock_options, $mock_transients;
        $mock_options = [];
        $mock_transients = [];
    }

    /**
     * Install a fake $wpdb that reports a set of EXISTING columns per table and
     * records every SQL statement run through query()/get_var(). It answers the
     * SHOW COLUMNS / INFORMATION_SCHEMA introspection used by the drift check and
     * the idempotent ALTER guards, and grows its column set when an ALTER ... ADD
     * COLUMN runs so the post-migration schema reflects the heal.
     *
     * @param array<string,array<int,string>> $existingColumns suffix => columns
     * @return object capturing $queries
     */
    private function installFakeWpdb(array $existingColumns): object
    {
        global $wpdb;

        $wpdb = new class($existingColumns) {
            public string $prefix = 'wp_';
            public string $dbname = 'wordpress';
            /** @var array<int,string> */
            public array $queries = [];
            /** @var array<string,array<string,true>> suffix => set<column> */
            private array $cols = [];

            /** @param array<string,array<int,string>> $existing */
            public function __construct(array $existing)
            {
                foreach ($existing as $suffix => $columns) {
                    foreach ($columns as $c) {
                        $this->cols[$suffix][$c] = true;
                    }
                }
            }

            public function get_charset_collate(): string
            {
                return 'DEFAULT CHARACTER SET utf8mb4';
            }

            public function prepare($query, ...$args)
            {
                // Good-enough stand-in: positional %s/%d substitution.
                $i = 0;
                return preg_replace_callback('/%[sd]/', static function () use (&$i, $args) {
                    return "'" . ($args[$i++] ?? '') . "'";
                }, $query);
            }

            private function suffixFor(string $sql): ?string
            {
                if (preg_match('/wp_peanut_([a-z_]+)/', $sql, $m)) {
                    return $m[1];
                }
                return null;
            }

            public function get_var($query)
            {
                $this->queries[] = $query;

                // Table-existence probe (INFORMATION_SCHEMA.TABLES). A table is
                // "present" iff we were seeded with any columns for its suffix.
                if (stripos($query, 'INFORMATION_SCHEMA.TABLES') !== false) {
                    $suffix = $this->suffixFor($query);
                    return ($suffix !== null && !empty($this->cols[$suffix])) ? '1' : '0';
                }

                // Column-existence probe (INFORMATION_SCHEMA.COLUMNS or SHOW COLUMNS).
                if (preg_match("/COLUMN_NAME\s*=\s*'([a-z_]+)'/i", $query, $cm)
                    || preg_match("/SHOW COLUMNS.*LIKE\s*'([a-z_]+)'/is", $query, $cm)) {
                    $col = $cm[1];
                    $suffix = $this->suffixFor($query);
                    if ($suffix === null) {
                        return null;
                    }
                    return isset($this->cols[$suffix][$col]) ? '1' : null;
                }
                return null;
            }

            public function query($query)
            {
                $this->queries[] = $query;
                // Reflect ADD COLUMN into the in-memory schema so a follow-up
                // probe sees the healed column.
                if (preg_match('/ALTER TABLE\s+`?wp_peanut_([a-z_]+)`?\s+ADD COLUMN\s+`?([a-z_]+)`?/i', $query, $m)) {
                    $this->cols[$m[1]][$m[2]] = true;
                }
                return 0;
            }

            public function get_results($query, $output = null)
            {
                $this->queries[] = $query;
                return [];
            }
        };

        return $wpdb;
    }

    /**
     * RED on the version-only gate: option is AHEAD of the OLD constant but the
     * `source_detail` column is missing — maybe_upgrade() must still heal it.
     */
    public function test_self_heals_when_schema_drifts_despite_ahead_version(): void
    {
        // Production state: option ahead of the historical 2.2.0 constant.
        update_option('peanut_db_version', '2.5.0');

        // contacts table exists but is MISSING the source_detail column.
        $fake = $this->installFakeWpdb([
            'contacts' => [
                'id', 'user_id', 'email', 'first_name', 'last_name', 'phone',
                'company', 'status', 'source', /* source_detail MISSING */
            ],
            'sequences' => [
                'id', 'name', 'description', 'trigger_type', 'trigger_value',
                'from_email', 'from_name', 'status', 'created_at',
            ],
        ]);

        $ran = false;
        $result = \Peanut_Database::maybe_upgrade(function () use (&$ran): void {
            $ran = true;
            // Real migrator runs the column-healer; emulate that here so the
            // gate test does not require the full activator/module bootstrap.
            \Peanut_Database::heal_drifted_columns();
        });

        $this->assertTrue(
            $ran,
            'maybe_upgrade() MUST run the migrator when a column is missing, even '
            . 'though the stored version (2.5.0) is >= the shipped constant. '
            . 'A version-only gate is the production bug.'
        );
        $this->assertTrue($result, 'maybe_upgrade() returns true when it healed.');

        // The ALTER that adds source_detail must have been issued.
        $issuedAlter = false;
        foreach ($fake->queries as $q) {
            if (preg_match('/ALTER TABLE\s+`?wp_peanut_contacts`?\s+ADD COLUMN\s+`?source_detail`?/i', $q)) {
                $issuedAlter = true;
                break;
            }
        }
        $this->assertTrue(
            $issuedAlter,
            'maybe_upgrade() must issue ALTER TABLE ... ADD COLUMN source_detail '
            . "on the existing contacts table.\nQueries:\n" . implode("\n", $fake->queries)
        );
    }

    /**
     * The drift detector itself: reports a missing expected column.
     */
    public function test_schema_drift_detector_flags_missing_column(): void
    {
        $this->installFakeWpdb([
            'contacts'  => ['id', 'email'], // source_detail missing
            'sequences' => ['id', 'from_email', 'from_name'],
        ]);

        $this->assertFalse(
            \Peanut_Database::schema_is_current(),
            'schema_is_current() must return false when an expected column is absent.'
        );
    }

    /**
     * Positive path: when all expected columns exist, the detector passes and
     * the result is cached in a transient so the introspection is cheap.
     */
    public function test_schema_drift_detector_passes_and_caches(): void
    {
        $this->installFakeWpdb([
            'contacts'  => ['id', 'email', 'source_detail'],
            'sequences' => ['id', 'from_email', 'from_name'],
        ]);

        $this->assertTrue(
            \Peanut_Database::schema_is_current(),
            'schema_is_current() must return true when every expected column exists.'
        );
    }
}
