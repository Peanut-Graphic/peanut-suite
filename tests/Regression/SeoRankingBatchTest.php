<?php
/**
 * Regression guard: SEO keyword-ranking cron is bounded per run.
 *
 * Item E: SEO_Module::check_rankings() did `SELECT * FROM <keywords>` with NO
 * LIMIT, then performed an external lookup plus sleep(2) PER ROW. As the
 * keyword table grows the daily cron approaches (and on large installs exceeds)
 * max_execution_time, so it never finishes — and the work is unbounded.
 *
 * The fix mirrors the backlinks module's batching: a per-run cap (LIMIT) with
 * least-recently-checked ordering (ORDER BY last_checked ASC, NULLs first) so
 * every keyword is eventually refreshed across runs without any single run
 * running away.
 *
 * Self-contained static guard on the source of check_rankings().
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;

final class SeoRankingBatchTest extends TestCase
{
    private function checkRankingsBody(): string
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/modules/seo/class-seo-module.php'
        );
        $this->assertNotFalse($src, 'SEO module source must be readable.');

        // Extract the check_rankings() method body.
        $pos = strpos($src, 'function check_rankings(');
        $this->assertNotFalse($pos, 'check_rankings() must exist.');
        $brace = strpos($src, '{', $pos);
        $depth = 0;
        $end = $brace;
        for ($i = $brace, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }
        return substr($src, $brace, $end - $brace + 1);
    }

    public function test_query_is_bounded_with_a_limit(): void
    {
        $body = $this->checkRankingsBody();
        $this->assertMatchesRegularExpression(
            '/\bLIMIT\b/i',
            $body,
            'check_rankings() must cap the per-run batch with a LIMIT.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/SELECT\s+\*\s+FROM\s+\$table\s*"/i',
            $body,
            'check_rankings() must not run an unbounded SELECT * FROM keywords.'
        );
    }

    public function test_orders_by_least_recently_checked(): void
    {
        $body = $this->checkRankingsBody();
        $this->assertMatchesRegularExpression(
            '/ORDER BY\s+last_checked\s+ASC/i',
            $body,
            'check_rankings() must process least-recently-checked keywords first '
            . 'so every keyword is eventually refreshed across runs.'
        );
    }
}
