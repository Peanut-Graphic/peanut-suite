<?php
/**
 * Regression guard: write-vs-schema column drift on peanut_* custom tables.
 *
 * Incident 2026-06-16: several modules wrote columns via $wpdb->insert()/->update()
 * that did NOT exist in the table's dbDelta CREATE TABLE definition:
 *   - popups module wrote contacts.source_detail (column never existed) -> the
 *     INSERT silently failed and the converting contact was dropped on the floor.
 *   - calendar AJAX wrote `type`/`scheduled_date` while the schema defined
 *     `event_type`/`event_date` -> every AJAX save silently failed.
 *   - sequences AJAX wrote `from_email`/`from_name`, absent from the schema ->
 *     those settings silently failed to persist.
 *
 * MySQL rejects an INSERT/UPDATE naming an unknown column ("Unknown column"),
 * but $wpdb->insert returns false and the calling code never checked it, so the
 * failures were invisible in production.
 *
 * This test is SELF-CONTAINED: pure PHP, no WordPress, no plugin bootstrap. It
 * statically scans the repo, extracting (a) every literal-keyed array passed to
 * $wpdb->insert()/->update() targeting a `peanut_*` custom table, and (b) every
 * column declared in a dbDelta CREATE TABLE block. It asserts that every written
 * column exists in that table's schema. Mirrors the sibling regression-guard
 * pattern (NamespacedClassCallSiteTest).
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class SchemaDriftGuardTest extends TestCase
{
    /**
     * Tables this guard explicitly covers. The drift incident touched these
     * three; the scanner is generic but we assert at least these are seen so
     * a refactor that hides a call site does not silently weaken the guard.
     *
     * Keys are the table suffix after the `peanut_` prefix.
     */
    private const COVERED_TABLES = ['contacts', 'calendar_events', 'sequences'];

    /** Repo root (this file is tests/Regression/<name>.php). */
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** All PHP files in the repo, excluding vendor/, node_modules/, tests/. */
    private function phpFiles(): array
    {
        $root  = $this->repoRoot();
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            $path = $f->getPathname();
            if (str_contains($path, '/vendor/')
                || str_contains($path, '/node_modules/')
                || str_contains($path, '/tests/')) {
                continue;
            }
            if ($f->isFile() && str_ends_with($path, '.php')) {
                $files[] = $path;
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Build table-suffix => set<column> from every dbDelta CREATE TABLE block.
     *
     * Table names are built dynamically (e.g. $wpdb->prefix . 'peanut_contacts'
     * or self::contacts_table()). We key on the `peanut_<suffix>` literal that
     * appears in the CREATE TABLE statement's variable assignment OR in the
     * shorthand accessor, so we resolve the variable used after `CREATE TABLE`.
     *
     * @return array<string,array<string,true>>
     */
    private function schemaColumns(): array
    {
        $schema = [];

        foreach ($this->phpFiles() as $file) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }

            // Map local variable => peanut_<suffix> from assignments like:
            //   $events_table = $wpdb->prefix . 'peanut_calendar_events';
            //   $sequences_table = $wpdb->prefix . 'peanut_sequences';
            $varTable = [];
            if (preg_match_all(
                "/\\\$(\w+)\s*=\s*\\\$wpdb->prefix\s*\.\s*'peanut_([a-z_]+)'/",
                $src,
                $vm,
                PREG_SET_ORDER
            )) {
                foreach ($vm as $m) {
                    $varTable['$' . $m[1]] = $m[2];
                }
            }

            // Map self::<name>_table() accessors -> peanut_<name>.
            // Database accessors are self::table('<name>') wrapped; the suffix
            // equals the method name minus the trailing _table.
            if (preg_match_all(
                "/public static function (\w+)_table\(\): string \{ return self::table\('(\w+)'\)/",
                $src,
                $am,
                PREG_SET_ORDER
            )) {
                foreach ($am as $m) {
                    // accessor returns peanut_<arg>
                    // referenced later as self::<m1>_table()
                    $varTable['self::' . $m[1] . '_table()'] = $m[2];
                }
            }

            // Find each CREATE TABLE <ref> ( ... ) $charset; block. <ref> may be:
            //   CREATE TABLE $events_table (          -- inline interpolated var
            //   CREATE TABLE " . self::contacts_table() . " (   -- concatenated accessor
            if (!preg_match_all(
                '/CREATE TABLE\s+(?:"\s*\.\s*)?(\$\w+|self::\w+_table\(\))(?:\s*\.\s*")?\s*\((.*?)\)\s*\$charset;/s',
                $src,
                $cm,
                PREG_SET_ORDER
            )) {
                continue;
            }

            foreach ($cm as $block) {
                $ref  = $block[1];
                $body = $block[2];
                $suffix = $varTable[$ref] ?? null;
                if ($suffix === null) {
                    continue;
                }

                $schema[$suffix] ??= [];
                foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    // Skip index/key/constraint lines.
                    if (preg_match('/^(PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX|CONSTRAINT|FOREIGN\s+KEY)\b/i', $line)) {
                        continue;
                    }
                    // Column lines start with a bare identifier (optionally backticked).
                    if (preg_match('/^`?([a-z_][a-z0-9_]*)`?\s+/i', $line, $colm)) {
                        $schema[$suffix][$colm[1]] = true;
                    }
                }
            }
        }

        return $schema;
    }

    /**
     * Extract every literal-keyed write: $wpdb->insert(<table>, [ 'col' => ... ]).
     * Also handles ->update(<table>, [ ... ], ...). Resolves <table> to a
     * peanut_<suffix> via the same local-variable map as the schema scan.
     *
     * @return array<int,array{file:string,line:int,table:string,column:string}>
     */
    private function writeCallSites(): array
    {
        $root  = $this->repoRoot();
        $sites = [];

        foreach ($this->phpFiles() as $file) {
            $src = file_get_contents($file);
            if ($src === false) {
                continue;
            }

            // Local variable assignments => list of [offset, peanut_<suffix>],
            // so we can resolve each call site against the NEAREST PRECEDING
            // assignment (not a file-global last-wins map — files reuse generic
            // names like $table for several different tables).
            $assignments = []; // '$var' => [ [offset, suffix], ... ]
            $addAssign = static function (string $var, int $offset, string $suffix) use (&$assignments): void {
                $assignments[$var][] = [$offset, $suffix];
            };
            if (preg_match_all(
                "/\\\$(\w+)\s*=\s*\\\$wpdb->prefix\s*\.\s*'peanut_([a-z_]+)'/",
                $src,
                $vm,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )) {
                foreach ($vm as $m) {
                    $addAssign('$' . $m[1][0], $m[0][1], $m[2][0]);
                }
            }
            // Database accessor variables: $contacts_table = Peanut_Database::contacts_table();
            if (preg_match_all(
                "/\\\$(\w+)\s*=\s*(?:Peanut_Database|self)::(\w+)_table\(\)/",
                $src,
                $dm,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )) {
                foreach ($dm as $m) {
                    $addAssign('$' . $m[1][0], $m[0][1], $m[2][0]);
                }
            }
            // Resolve a variable to the suffix assigned nearest-before $at.
            $resolveVar = static function (string $var, int $at) use ($assignments): ?string {
                if (!isset($assignments[$var])) {
                    return null;
                }
                $best = null;
                $bestOff = -1;
                foreach ($assignments[$var] as [$off, $suffix]) {
                    if ($off <= $at && $off > $bestOff) {
                        $bestOff = $off;
                        $best = $suffix;
                    }
                }
                return $best;
            };

            // Index data-array variable assignments: $data = [ ... ];
            // Stored as '$var' => [ [offset, arrayBody], ... ] for nearest-before
            // resolution of indirect writes ($wpdb->insert($table, $data)).
            $arrAssigns = []; // '$var' => [ [offset, body], ... ]
            $assignRe = '/\$(\w+)\s*=\s*\[/';
            if (preg_match_all($assignRe, $src, $am2, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($am2 as $m) {
                    $open = $m[0][1] + strlen($m[0][0]) - 1; // position of '['
                    $body = $this->balancedBracketBody($src, $open);
                    if ($body !== null) {
                        $arrAssigns['$' . $m[1][0]][] = [$m[0][1], $body];
                    }
                }
            }
            $resolveArr = static function (string $var, int $at) use ($arrAssigns): ?string {
                if (!isset($arrAssigns[$var])) {
                    return null;
                }
                $best = null;
                $bestOff = -1;
                foreach ($arrAssigns[$var] as [$off, $body]) {
                    if ($off <= $at && $off > $bestOff) {
                        $bestOff = $off;
                        $best = $body;
                    }
                }
                return $best;
            };

            // Find every $wpdb->insert( / ->update( call opener.
            if (!preg_match_all(
                '/\$wpdb->(insert|update)\s*\(/',
                $src,
                $calls,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE
            )) {
                continue;
            }

            foreach ($calls as $c) {
                $callOffset = $c[0][1];
                $parenOpen  = $callOffset + strlen($c[0][0]) - 1; // position of '('
                $argsBody   = $this->balancedBracketBody($src, $parenOpen, '(', ')');
                if ($argsBody === null) {
                    continue;
                }
                $args = $this->splitTopLevelArgs($argsBody);
                if (count($args) < 2) {
                    continue;
                }
                $tableArg = trim($args[0]);
                $dataArg  = trim($args[1]);
                $line     = substr_count(substr($src, 0, $callOffset), "\n") + 1;

                // Resolve table suffix.
                $suffix = null;
                if (preg_match('/^\$\w+$/', $tableArg)) {
                    $suffix = $resolveVar($tableArg, $callOffset);
                } elseif (preg_match("/\\\$wpdb->prefix\s*\.\s*'peanut_([a-z_]+)'/", $tableArg, $tm)) {
                    $suffix = $tm[1];
                } elseif (preg_match('/(?:Peanut_Database|self)::(\w+)_table\(\)/', $tableArg, $tm)) {
                    $suffix = $tm[1];
                }
                if ($suffix === null) {
                    continue; // non-peanut or unresolvable table; out of scope
                }

                // Resolve the data array body (inline literal or $var).
                $arrBody = null;
                if (str_starts_with($dataArg, '[')) {
                    $arrBody = $this->balancedBracketBody($dataArg, 0);
                } elseif (preg_match('/^\$\w+$/', $dataArg)) {
                    $arrBody = $resolveArr($dataArg, $callOffset);
                }
                if ($arrBody === null) {
                    continue;
                }

                // Extract TOP-LEVEL literal string keys only (ignore keys nested
                // inside value arrays). Walk the body splitting at depth 0.
                foreach ($this->topLevelArrayKeys($arrBody) as $col) {
                    $sites[] = [
                        'file'   => ltrim(str_replace($root, '', $file), '/'),
                        'line'   => $line,
                        'table'  => $suffix,
                        'column' => $col,
                    ];
                }
            }
        }

        return $sites;
    }

    /**
     * Given source and the position of an opening bracket, return the substring
     * BETWEEN the matching brackets (exclusive), respecting nesting and skipping
     * over single/double-quoted string literals. Returns null on imbalance.
     */
    private function balancedBracketBody(
        string $src,
        int $openPos,
        string $open = '[',
        string $close = ']'
    ): ?string {
        $len = strlen($src);
        $depth = 0;
        $start = $openPos + 1;
        for ($i = $openPos; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === "'" || $ch === '"') {
                // skip string literal
                $q = $ch;
                $i++;
                while ($i < $len) {
                    if ($src[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($src[$i] === $q) {
                        break;
                    }
                    $i++;
                }
                continue;
            }
            if ($ch === $open) {
                $depth++;
            } elseif ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
        }
        return null;
    }

    /**
     * Split a function-argument body on top-level commas (depth 0), respecting
     * brackets/parens/braces and string literals.
     *
     * @return array<int,string>
     */
    private function splitTopLevelArgs(string $body): array
    {
        $args = [];
        $depth = 0;
        $cur = '';
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($ch === "'" || $ch === '"') {
                $q = $ch;
                $cur .= $ch;
                $i++;
                while ($i < $len) {
                    $cur .= $body[$i];
                    if ($body[$i] === '\\') {
                        $i++;
                        if ($i < $len) {
                            $cur .= $body[$i];
                        }
                        $i++;
                        continue;
                    }
                    if ($body[$i] === $q) {
                        break;
                    }
                    $i++;
                }
                continue;
            }
            if (str_contains('([{', $ch)) {
                $depth++;
            } elseif (str_contains(')]}', $ch)) {
                $depth--;
            }
            if ($ch === ',' && $depth === 0) {
                $args[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $ch;
        }
        if (trim($cur) !== '') {
            $args[] = $cur;
        }
        return $args;
    }

    /**
     * Extract top-level array keys ('key' => ...) from an array body, ignoring
     * keys nested inside value sub-arrays. Splits on top-level commas first.
     *
     * @return array<int,string>
     */
    private function topLevelArrayKeys(string $body): array
    {
        $keys = [];
        foreach ($this->splitTopLevelArgs($body) as $pair) {
            if (preg_match("/^\s*'([a-z_][a-z0-9_]*)'\s*=>/i", $pair, $m)) {
                $keys[] = $m[1];
            }
        }
        return $keys;
    }

    public function testNoWrittenColumnIsMissingFromSchema(): void
    {
        $schema = $this->schemaColumns();
        $sites  = $this->writeCallSites();

        $drift = [];
        $seenTables = [];

        foreach ($sites as $site) {
            $table = $site['table'];
            // Only assert against tables we actually have a schema for.
            if (!isset($schema[$table])) {
                continue;
            }
            $seenTables[$table] = true;
            if (!isset($schema[$table][$site['column']])) {
                $drift[] = sprintf(
                    "%s:%d writes peanut_%s.%s which is NOT in the dbDelta schema",
                    $site['file'],
                    $site['line'],
                    $table,
                    $site['column']
                );
            }
        }

        $this->assertSame(
            [],
            $drift,
            "Schema drift detected (write to a column absent from the table's CREATE TABLE):\n"
            . implode("\n", $drift)
        );

        // Guard the guard: confirm the covered tables were actually exercised,
        // so a refactor that hides a call site cannot silently neuter this test.
        foreach (self::COVERED_TABLES as $table) {
            $this->assertArrayHasKey(
                $table,
                $schema,
                "Expected a dbDelta schema for peanut_{$table}; scanner found none."
            );
            $this->assertArrayHasKey(
                $table,
                $seenTables,
                "Expected at least one \$wpdb->insert/->update write to peanut_{$table}; "
                . "scanner saw none (call site moved or pattern changed?)."
            );
        }
    }
}
