<?php
/**
 * Property-based tests (Testing Protocol v2, Net 6) for Peanut Suite.
 *
 * These exercise PURE functions — ones that depend only on their arguments and
 * PHP's standard library, with NO WordPress runtime, no $wpdb, no network. Each
 * test asserts a REAL invariant over many randomized inputs (seeded for
 * determinism, per conventions.md). A failing property is a real bug: stop and
 * report the counter-example, never weaken the assertion.
 *
 * Seam: each subject class file guards on ABSPATH (`if (!defined('ABSPATH')) exit;`)
 * and otherwise pulls in NO WordPress symbols on the code paths under test, so we
 * define ABSPATH and require the file directly — no plugin bootstrap, no DB.
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Property;

use PHPUnit\Framework\TestCase;
use UTM_Module;
use Links_Module;
use Peanut_Encryption;
use Peanut_Api_Keys_Service;

final class PureFunctionsPropertyTest extends TestCase
{
    /** Deterministic PRNG seed so randomized cases are reproducible. */
    private const SEED = 1337;

    /** Number of randomized cases per property. */
    private const CASES = 300;

    public static function setUpBeforeClass(): void
    {
        // Satisfy the `if (!defined('ABSPATH')) exit;` guard without WordPress.
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }

        $root = dirname(__DIR__, 2);
        require_once $root . '/modules/utm/class-utm-module.php';
        require_once $root . '/modules/links/class-links-module.php';
        require_once $root . '/core/services/class-peanut-encryption.php';
        require_once $root . '/core/services/class-peanut-api-keys-service.php';
    }

    protected function setUp(): void
    {
        mt_srand(self::SEED);
    }

    /** Random printable-ish token (may include URL-significant chars). */
    private function randToken(int $maxLen = 12): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 &?=#%+/:';
        $len = mt_rand(0, $maxLen);
        $s = '';
        for ($i = 0; $i < $len; $i++) {
            $s .= $alphabet[mt_rand(0, strlen($alphabet) - 1)];
        }
        return $s;
    }

    // ---------------------------------------------------------------------
    // UTM_Module::build_url — pure UTM URL builder
    // ---------------------------------------------------------------------

    /**
     * Property: every non-empty utm param supplied is encoded into the result
     * with the correct key=value pair, and the base URL is always a prefix.
     */
    public function test_build_url_round_trips_supplied_params(): void
    {
        for ($n = 0; $n < self::CASES; $n++) {
            $base = 'https://example.com/' . rawurlencode($this->randToken(6));
            $params = ['base_url' => $base];
            $keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
            $expected = [];
            foreach ($keys as $k) {
                $v = $this->randToken();
                $params[$k] = $v;
                if ($v !== '') {
                    $expected[$k] = $v;
                }
            }

            $url = UTM_Module::build_url($params);

            // Base (sans trailing ?&) is always a prefix of the result.
            $trimmedBase = rtrim($base, '?&');
            $this->assertStringStartsWith($trimmedBase, $url, "base must prefix result for params: " . json_encode($params));

            // Parse the query that build_url appended and confirm it round-trips
            // exactly the non-empty utm params (and nothing else).
            $query = (string) parse_url($url, PHP_URL_QUERY);
            parse_str($query, $decoded);
            $this->assertSame($expected, $decoded, "query must encode exactly the non-empty utm params for: " . json_encode($params));
        }
    }

    /**
     * Property: the separator is `?` when the base has no query, `&` when it does —
     * and the result never produces a malformed `??` or `?&`/`&&` joint.
     */
    public function test_build_url_picks_correct_separator(): void
    {
        for ($n = 0; $n < self::CASES; $n++) {
            $hasQuery = (bool) mt_rand(0, 1);
            $base = 'https://example.com/page' . ($hasQuery ? '?x=1' : '');
            $url = UTM_Module::build_url([
                'base_url' => $base,
                'utm_source' => 'news' . mt_rand(0, 99),
            ]);

            $sep = $hasQuery ? '&' : '?';
            $this->assertSame($base . $sep . 'utm_source=' . substr($url, strrpos($url, '=') + 1), $url);
            $this->assertStringNotContainsString('??', $url);
            $this->assertStringNotContainsString('?&', $url);
            $this->assertStringNotContainsString('&&', $url);
        }
    }

    // ---------------------------------------------------------------------
    // Links_Module::get_qr_code_url — pure QR endpoint builder
    // ---------------------------------------------------------------------

    /**
     * Property: the QR url always points at the QR Server API, embeds the size as
     * NxN, and round-trips the target url through the `data` query param verbatim.
     */
    public function test_get_qr_code_url_embeds_size_and_data(): void
    {
        for ($n = 0; $n < self::CASES; $n++) {
            $target = 'https://example.com/' . $this->randToken();
            $size = mt_rand(1, 1000);

            $qr = Links_Module::get_qr_code_url($target, $size);

            $this->assertStringStartsWith('https://api.qrserver.com/v1/create-qr-code/?', $qr);

            $query = (string) parse_url($qr, PHP_URL_QUERY);
            parse_str($query, $decoded);
            $this->assertSame("{$size}x{$size}", $decoded['size'] ?? null, "size must be NxN");
            $this->assertSame($target, $decoded['data'] ?? null, "data must round-trip the target url");
        }
    }

    // ---------------------------------------------------------------------
    // Peanut_Encryption::mask — pure display-masking formatter
    // ---------------------------------------------------------------------

    /**
     * Property: mask preserves length, never reveals more than start+end chars,
     * leaks no character it shouldn't, and keeps the visible prefix/suffix when
     * the data is long enough to expose them.
     */
    public function test_mask_preserves_length_and_hides_middle(): void
    {
        for ($n = 0; $n < self::CASES; $n++) {
            $data = $this->randToken(20);
            $start = mt_rand(0, 6);
            $end = mt_rand(0, 6);

            $masked = Peanut_Encryption::mask($data, $start, $end);

            // Length is always preserved.
            $this->assertSame(strlen($data), strlen($masked), "length preserved for '" . $data . "'");

            $len = strlen($data);
            if ($len <= $start + $end) {
                // Fully masked.
                $this->assertSame(str_repeat('*', $len), $masked, "short data fully masked: '" . $data . "'");
            } else {
                // Visible prefix and suffix are intact; middle is all stars.
                if ($start > 0) {
                    $this->assertSame(substr($data, 0, $start), substr($masked, 0, $start));
                }
                if ($end > 0) {
                    $this->assertSame(substr($data, -$end), substr($masked, -$end));
                }
                $middle = substr($masked, $start, $len - $start - $end);
                $this->assertSame(str_repeat('*', $len - $start - $end), $middle, "middle all-stars for '" . $data . "'");
            }
        }
    }

    // ---------------------------------------------------------------------
    // Peanut_Api_Keys_Service::has_scope — pure membership predicate
    // ---------------------------------------------------------------------

    /**
     * Property: has_scope is exactly strict membership of $scope in the scopes
     * array — true iff present (type-strict), false otherwise.
     */
    public function test_has_scope_is_strict_membership(): void
    {
        $pool = ['read', 'write', 'admin', 'links:create', 'utm:view', 'reports:export'];
        for ($n = 0; $n < self::CASES; $n++) {
            // Build a random subset of scopes.
            $scopes = [];
            foreach ($pool as $s) {
                if (mt_rand(0, 1)) {
                    $scopes[] = $s;
                }
            }
            $keyData = ['scopes' => $scopes];

            // A scope drawn from the pool: result must match in_array strict.
            $probe = $pool[mt_rand(0, count($pool) - 1)];
            $this->assertSame(
                in_array($probe, $scopes, true),
                Peanut_Api_Keys_Service::has_scope($keyData, $probe),
                "membership must match for probe '$probe' against " . json_encode($scopes)
            );

            // A guaranteed-absent scope is always false.
            $absent = 'definitely-absent-' . mt_rand(1000, 9999);
            $this->assertFalse(
                Peanut_Api_Keys_Service::has_scope($keyData, $absent),
                "absent scope must be false"
            );
        }
    }
}
