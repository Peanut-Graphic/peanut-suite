<?php
/**
 * Regression guard: namespaced-class-referenced-unqualified mismatches.
 *
 * Incident 2026-05-15: classes (e.g. Visitors_Database, ML_Lead_Scoring_Controller)
 * were moved into a non-global `namespace`, but global-namespace callers still
 * referenced them UNqualified (no leading `\`, no matching `use`, caller not in
 * the declaring namespace). PHP throws a fatal "Class not found" at runtime.
 * On peanutgraphic.com this fired on rest_api_init and took down ALL REST + WP-CLI.
 *
 * This test is SELF-CONTAINED: pure PHP, no WordPress, no plugin bootstrap, no
 * external scripts. It re-implements the audit logic and scans the whole repo,
 * asserting ZERO such mismatches. It mirrors the sibling regression-guard pattern
 * used in formflow / formflow-lite (those guard a DIFFERENT bug — ABSPATH).
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class NamespacedClassCallSiteTest extends TestCase
{
    /** Repo root (this file is tests/Regression/<name>.php). */
    private function repoRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /** All PHP files in the repo, excluding vendor/ and node_modules/. */
    private function phpFiles(string $root): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            $path = $f->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
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
     * Reproduces ns_mismatch_audit.py in pure PHP.
     *
     * @return array<int,array{file:string,line:int,name:string,where:string,snippet:string}>
     */
    private function findMismatches(): array
    {
        $root  = $this->repoRoot();
        $files = $this->phpFiles($root);

        $nsRe   = '/^\s*namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m';
        $declRe = '/^\s*(?:abstract\s+|final\s+)*(?:class|interface|trait)\s+([A-Za-z0-9_]+)/m';
        $useRe  = '/^\s*use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m';
        $refRes = [
            '/(?<![\\\\\w])new\s+([A-Z][A-Za-z0-9_]*)\s*\(/',
            '/(?<![\\\\\w])([A-Z][A-Za-z0-9_]*)::/',
            '/(?<![\\\\\w])extends\s+([A-Z][A-Za-z0-9_]*)/',
            '/(?<![\\\\\w])implements\s+([A-Z][A-Za-z0-9_]*)/',
            '/(?<![\\\\\w])instanceof\s+([A-Z][A-Za-z0-9_]*)/',
        ];

        // Pass 1: where is each short name declared (set of namespaces; '' = global).
        $declared = []; // name => [ns => true]
        foreach ($files as $f) {
            $src = @file_get_contents($f);
            if ($src === false) {
                continue;
            }
            $ns = preg_match($nsRe, $src, $m) ? $m[1] : '';
            if (preg_match_all($declRe, $src, $dm)) {
                foreach ($dm[1] as $name) {
                    $declared[$name][$ns] = true;
                }
            }
        }

        // Pass 2: flag unqualified references that can only resolve to a
        // non-global namespace the caller is not in / has not imported.
        $flags = [];
        foreach ($files as $f) {
            $src = @file_get_contents($f);
            if ($src === false) {
                continue;
            }
            $fns = preg_match($nsRe, $src, $m) ? $m[1] : '';

            $uses = [];
            if (preg_match_all($useRe, $src, $um, PREG_SET_ORDER)) {
                foreach ($um as $u) {
                    $alias = $u[2] ?? '';
                    if ($alias === '') {
                        $parts = explode('\\', $u[1]);
                        $alias = end($parts);
                    }
                    $uses[$alias] = true;
                }
            }

            $lines = preg_split('/\r\n|\n|\r/', $src);
            foreach ($lines as $idx => $line) {
                $s = trim($line);
                if ($s === '' || $s[0] === '#'
                    || str_starts_with($s, '//') || str_starts_with($s, '*')) {
                    continue;
                }
                foreach ($refRes as $rx) {
                    if (!preg_match_all($rx, $line, $rm)) {
                        continue;
                    }
                    foreach ($rm[1] as $name) {
                        if (in_array($name, ['self', 'static', 'parent', 'class'], true)) {
                            continue;
                        }
                        $where = $declared[$name] ?? null;
                        if ($where === null) {
                            continue; // external/builtin
                        }
                        if (isset($where[''])) {
                            continue; // also defined globally -> resolves
                        }
                        if (isset($where[$fns])) {
                            continue; // same namespace -> resolves
                        }
                        if (isset($uses[$name])) {
                            continue; // imported -> resolves
                        }
                        $ns = array_keys($where);
                        sort($ns);
                        $rel = ltrim(str_replace($root, '', $f), '/');
                        $flags[] = [
                            'file'    => $rel,
                            'line'    => $idx + 1,
                            'name'    => $name,
                            'where'   => implode(', ', $ns),
                            'snippet' => substr($s, 0, 90),
                        ];
                    }
                }
            }
        }

        return $flags;
    }

    public function testNoNamespacedClassReferencedUnqualified(): void
    {
        $flags = $this->findMismatches();

        $report = '';
        foreach ($flags as $fl) {
            $report .= sprintf(
                "  %s:%d  %s  (declared in: %s)  | %s\n",
                $fl['file'],
                $fl['line'],
                $fl['name'],
                $fl['where'],
                $fl['snippet']
            );
        }

        $this->assertSame(
            [],
            $flags,
            sprintf(
                "Found %d namespaced-class-referenced-unqualified mismatch(es) "
                . "(fatal 'Class not found' at runtime):\n%s",
                count($flags),
                $report
            )
        );
    }
}
