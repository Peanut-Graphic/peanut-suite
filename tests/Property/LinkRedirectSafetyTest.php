<?php
/**
 * Regression guard (P1 open-redirect): short-link redirect-target validation.
 *
 * Incident 2026-07-05: the public /go/{slug} handler in the links module did
 *   wp_redirect($link->destination_url, 301)
 * on an attacker-controllable destination. Any logged-in user (incl. a
 * Subscriber, via the create endpoint) can set destination_url, so the site's
 * own trusted domain could 301 a visitor to javascript:/data: or an arbitrary
 * external host — an open-redirect / credential-phishing primitive.
 *
 * The fix keeps legitimate EXTERNAL short-links working (so plain
 * wp_safe_redirect, which forces a same-host fallback, was not an option) but
 * hardens the egress: Links_Module::is_safe_redirect_target() only accepts a
 * well-formed absolute http(s) URL with a host and rejects everything else.
 *
 * This test is SELF-CONTAINED: pure PHP, no WordPress, no $wpdb, no network. It
 * defines ABSPATH and requires the module file directly (mirroring the sibling
 * PureFunctionsPropertyTest seam) and asserts the invariant over concrete
 * attack vectors plus a randomized fuzz sweep.
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Property;

use PHPUnit\Framework\TestCase;
use Links_Module;

final class LinkRedirectSafetyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Satisfy the `if (!defined('ABSPATH')) exit;` guard without WordPress.
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }

        require_once dirname(__DIR__, 2) . '/modules/links/class-links-module.php';
    }

    /**
     * Malicious / malformed destinations that MUST NOT be redirected to.
     *
     * @return array<string,array{0:mixed}>
     */
    public static function unsafeTargets(): array
    {
        return [
            'javascript scheme'            => ['javascript:alert(1)'],
            'javascript with host-like'    => ['javascript:alert(document.domain)//evil.example'],
            'obfuscated javascript (tab)'  => ["java\tscript:alert(1)"],
            'obfuscated javascript (nl)'   => ["java\nscript:alert(1)"],
            'data uri'                     => ['data:text/html,<script>alert(1)</script>'],
            'vbscript scheme'              => ['vbscript:msgbox(1)'],
            'file scheme'                  => ['file:///etc/passwd'],
            'protocol-relative'            => ['//evil.example/phish'],
            'scheme without host'          => ['https:///path'],
            'http no host'                 => ['http://'],
            'relative path'               => ['/wp-admin/'],
            'bare host no scheme'          => ['evil.example/login'],
            'empty string'                 => [''],
            'whitespace only'              => ['   '],
            'leading-space javascript'     => [' javascript:alert(1)'],
            'null'                         => [null],
            'integer'                      => [12345],
            'array'                        => [['https://ok.example']],
        ];
    }

    /**
     * @dataProvider unsafeTargets
     * @param mixed $url
     */
    public function test_unsafe_targets_are_rejected($url): void
    {
        $this->assertFalse(
            Links_Module::is_safe_redirect_target($url),
            'Expected unsafe redirect target to be rejected: ' . var_export($url, true)
        );
    }

    /**
     * Legitimate external short-links that MUST keep working (301).
     *
     * @return array<string,array{0:string}>
     */
    public static function safeTargets(): array
    {
        return [
            'https external'        => ['https://example.com/landing'],
            'http external'         => ['http://example.com/page?a=1&b=2'],
            'https with port'       => ['https://example.com:8443/x'],
            'uppercase scheme'      => ['HTTPS://Example.com/'],
            'deep path + fragment'  => ['https://sub.example.com/a/b/c#frag'],
            'query with encoded'    => ['https://example.com/s?q=hello%20world'],
        ];
    }

    /**
     * @dataProvider safeTargets
     */
    public function test_safe_external_targets_are_allowed(string $url): void
    {
        $this->assertTrue(
            Links_Module::is_safe_redirect_target($url),
            'Expected legitimate external https/http link to be allowed: ' . $url
        );
    }

    /**
     * Fuzz: no javascript:/data:/vbscript: string is ever accepted, regardless
     * of casing or surrounding whitespace.
     */
    public function test_fuzz_dangerous_schemes_never_accepted(): void
    {
        mt_srand(1337);
        $bad = ['javascript', 'data', 'vbscript', 'JavaScript', 'DATA', 'file'];
        for ($i = 0; $i < 300; $i++) {
            $scheme  = $bad[mt_rand(0, count($bad) - 1)];
            $pad     = str_repeat(' ', mt_rand(0, 3));
            $payload = $pad . $scheme . ':' . 'alert(' . mt_rand(0, 9999) . ')';
            $this->assertFalse(
                Links_Module::is_safe_redirect_target($payload),
                'Dangerous scheme leaked through: ' . var_export($payload, true)
            );
        }
    }
}
