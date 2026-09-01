<?php
/**
 * Regression guard: the SEO settings page can actually save, and what it saves
 * is what the consumers read.
 *
 * The SEO settings screen shipped broken three independent ways, any one of
 * which alone made the "Save Settings" button a no-op:
 *
 *  (1) NONCE MISMATCH — the form minted wp_create_nonce('peanut_seo_settings')
 *      while the handler ran check_ajax_referer('peanut_seo', 'nonce'), so every
 *      save died 403 before reaching any code.
 *
 *  (2) FIELD-NAME MISMATCH — the form posted dataforseo_key / pagespeed_key; the
 *      handler read dataforseo_login / dataforseo_password / default_location /
 *      default_language. Disjoint sets: even past the nonce it stored empty
 *      strings.
 *
 *  (3) OPTION-KEY MISMATCH — the handler wrote update_option('peanut_seo_settings'),
 *      an option nothing reads. The three consumers read the flat options
 *      peanut_dataforseo_api_key / peanut_pagespeed_api_key, which nothing wrote.
 *      Both consumers guard with `if (!empty($api_key))`, so the feature never
 *      errored — it silently degraded forever.
 *
 * The property that was broken is the ROUND TRIP, so that is what is asserted
 * here: post exactly what the real form posts, with exactly the nonce action the
 * real form mints, through the real handler — then prove each consumer reads the
 * saved secret back.
 *
 * SELF-CONTAINED: pure PHP, no WordPress boot, no DB, no network (HTTP is
 * shimmed and recorded). Mirrors the sibling Regression-guard pattern
 * (RestoredEndpointGuardsTest).
 *
 * @package Peanut_Suite
 */

// --- Global-namespace shims (only if a real WP harness is not present). -------
namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    if (!defined('PEANUT_API_NAMESPACE')) {
        define('PEANUT_API_NAMESPACE', 'peanut/v1');
    }

    /**
     * Stands in for the request termination wp_send_json_success/error perform,
     * so a test can inspect what the handler decided instead of exiting.
     */
    if (!class_exists('Peanut_Test_Json_Halt')) {
        class Peanut_Test_Json_Halt extends \RuntimeException {
            public bool $success;
            public $payload;
            public ?int $status;
            public function __construct(bool $success, $payload, ?int $status = null) {
                parent::__construct('json halt');
                $this->success = $success;
                $this->payload = $payload;
                $this->status = $status;
            }
        }
    }

    if (!function_exists('wp_send_json_success')) {
        function wp_send_json_success($data = null, $status = null) {
            throw new \Peanut_Test_Json_Halt(true, $data, $status);
        }
    }
    if (!function_exists('wp_send_json_error')) {
        function wp_send_json_error($data = null, $status = null) {
            throw new \Peanut_Test_Json_Halt(false, $data, $status);
        }
    }

    /**
     * Nonce shim. A token is valid only for the action it was minted for, which
     * is the whole point of defect (1): a token minted for one action must NOT
     * pass a handler checking a different action.
     */
    // Deliberately NOT routed through wp_verify_nonce(): a sibling Regression
    // guard defines its own single-token wp_verify_nonce() shim, and these two
    // tests share one process.
    if (!function_exists('peanut_test_mint_nonce')) {
        function peanut_test_mint_nonce(string $action): string {
            return 'nonce-for:' . $action;
        }
    }
    if (!function_exists('check_ajax_referer')) {
        function check_ajax_referer($action = -1, $query_arg = false, $stop = true) {
            $token = $query_arg !== false ? ($_POST[$query_arg] ?? '') : '';
            if ($token !== peanut_test_mint_nonce((string) $action)) {
                // WordPress dies 403 here.
                throw new \Peanut_Test_Json_Halt(false, 'invalid nonce', 403);
            }
            return 1;
        }
    }

    if (!function_exists('current_user_can')) {
        function current_user_can($cap, ...$args): bool {
            return in_array($cap, $GLOBALS['__peanut_test_caps'] ?? [], true);
        }
    }
    if (!function_exists('add_query_arg')) {
        function add_query_arg($args, $url = '') {
            $sep = strpos($url, '?') === false ? '?' : '&';
            return $url . $sep . http_build_query($args);
        }
    }
    if (!function_exists('wp_remote_retrieve_headers')) {
        function wp_remote_retrieve_headers($response) {
            return is_array($response) ? ($response['headers'] ?? []) : [];
        }
    }
    if (!function_exists('esc_url_raw')) {
        function esc_url_raw($url) { return $url; }
    }
}

namespace PeanutSuite\Tests\Regression {

    use PeanutSuite\SEO\SEO_Auditor;
    use PeanutSuite\SEO\SEO_Module;
    use PHPUnit\Framework\TestCase;

    final class SeoSettingsRoundTripTest extends TestCase
    {
        private const DATAFORSEO_SECRET = 'roundtrip-login:roundtrip-password';
        private const PAGESPEED_SECRET = 'roundtrip-pagespeed-key';

        protected function setUp(): void
        {
            parent::setUp();

            require_once $this->repoRoot() . '/modules/seo/class-seo-module.php';

            $GLOBALS['mock_options'] = [];
            $GLOBALS['mock_http_calls'] = [];
            $GLOBALS['__peanut_test_caps'] = ['manage_options'];
            $_POST = [];
        }

        protected function tearDown(): void
        {
            $_POST = [];
            $GLOBALS['__peanut_test_caps'] = [];
            parent::tearDown();
        }

        private function repoRoot(): string
        {
            return dirname(__DIR__, 2);
        }

        private function viewSource(): string
        {
            $src = file_get_contents($this->repoRoot() . '/core/admin/views/seo.php');
            $this->assertNotFalse($src, 'SEO admin view must be readable.');
            return $src;
        }

        /**
         * The nonce action the settings FORM actually mints.
         */
        private function formNonceAction(): string
        {
            $src = $this->viewSource();
            $this->assertSame(
                1,
                preg_match("/wp_nonce_field\(\s*'([^']+)'\s*,\s*'peanut_nonce'\s*\)/", $src, $m),
                'The SEO settings form must mint a nonce with wp_nonce_field(<action>, \'peanut_nonce\') '
                . 'like the sibling settings pages.'
            );
            return $m[1];
        }

        /**
         * The field names the settings form actually POSTs, read out of the real
         * AJAX payload in the view. Defect (2) lived precisely in the gap between
         * this list and what the handler reads.
         *
         * @return string[]
         */
        private function formPostedFields(): array
        {
            $src = $this->viewSource();
            $this->assertSame(
                1,
                preg_match(
                    "/action:\s*'peanut_save_seo_settings',(.*?)\n\s*\}/s",
                    $src,
                    $m
                ),
                'The view must POST action=peanut_save_seo_settings with a data object.'
            );

            preg_match_all("/^\s*([a-z_]+):\s*\\\$\('#/mi", $m[1], $fields);
            $names = $fields[1];
            sort($names);

            $this->assertNotEmpty($names, 'The save payload must carry the API-key fields.');
            return $names;
        }

        /**
         * Drive the real handler exactly as the browser would.
         */
        private function saveThroughHandler(string $nonceAction, array $fields): \Peanut_Test_Json_Halt
        {
            $module = (new \ReflectionClass(SEO_Module::class))->newInstanceWithoutConstructor();

            $_POST = array_merge(['nonce' => \peanut_test_mint_nonce($nonceAction)], $fields);

            try {
                $module->ajax_save_settings();
            } catch (\Peanut_Test_Json_Halt $halt) {
                return $halt;
            }

            $this->fail('ajax_save_settings() must terminate through wp_send_json_success/error.');
        }

        private function saveBothKeys(): void
        {
            $posted = $this->formPostedFields();
            $this->assertSame(
                ['dataforseo_key', 'pagespeed_key'],
                $posted,
                'The form must post exactly the two API-key fields the handler reads.'
            );

            $halt = $this->saveThroughHandler($this->formNonceAction(), [
                'dataforseo_key' => self::DATAFORSEO_SECRET,
                'pagespeed_key' => self::PAGESPEED_SECRET,
            ]);

            $this->assertTrue(
                $halt->success,
                'Saving with the form\'s own nonce action and the form\'s own field names must succeed. '
                . 'A failure here is the original 403: the form nonce action and the '
                . 'check_ajax_referer() action have drifted apart again.'
            );
        }

        // -- (1) the form's nonce action must satisfy the handler ---------------

        public function testFormNonceActionIsAcceptedByTheHandler(): void
        {
            $this->saveBothKeys();
        }

        public function testANonceMintedForADifferentActionIsRejected(): void
        {
            $halt = $this->saveThroughHandler('peanut_some_other_action', [
                'dataforseo_key' => 'should-not-be-stored',
                'pagespeed_key' => 'should-not-be-stored',
            ]);

            $this->assertFalse($halt->success, 'A nonce for another action must not save.');
            $this->assertSame(403, $halt->status);
            $this->assertSame(
                [],
                $GLOBALS['mock_options'],
                'A rejected save must not write any option.'
            );
        }

        public function testSaveRequiresManageOptions(): void
        {
            $GLOBALS['__peanut_test_caps'] = ['edit_posts'];

            $halt = $this->saveThroughHandler($this->formNonceAction(), [
                'dataforseo_key' => 'should-not-be-stored',
                'pagespeed_key' => 'should-not-be-stored',
            ]);

            $this->assertFalse($halt->success, 'A non-administrator must not be able to save API keys.');
            $this->assertSame(
                [],
                $GLOBALS['mock_options'],
                'A denied save must not write any option.'
            );
        }

        // -- (2)+(3) the round trip: saved values reach every consumer ----------

        /**
         * Consumer A: SEO_Module::fetch_ranking() — without a key it short-circuits
         * to 'No ranking API configured'; with one it calls DataForSEO carrying it.
         */
        public function testSavedDataForSeoKeyReachesTheRankingLookup(): void
        {
            $module = (new \ReflectionClass(SEO_Module::class))->newInstanceWithoutConstructor();
            $fetch = new \ReflectionMethod(SEO_Module::class, 'fetch_ranking');

            // Before saving: the documented degraded behaviour.
            $before = $fetch->invoke($module, 'peanut butter', 'https://example.com/');
            $this->assertSame(
                'No ranking API configured',
                $before['error'] ?? null,
                'With no key stored, rank tracking must report that it is unconfigured.'
            );

            $this->saveBothKeys();

            $GLOBALS['mock_http_calls'] = [];
            $after = $fetch->invoke($module, 'peanut butter', 'https://example.com/');

            $this->assertArrayNotHasKey(
                'error',
                $after,
                'After saving a DataForSEO key, rank tracking must stop reporting itself unconfigured. '
                . 'If it still does, the key the form saved is not the key fetch_ranking() reads.'
            );

            $call = $GLOBALS['mock_http_calls'][0] ?? null;
            $this->assertNotNull($call, 'fetch_ranking() must call DataForSEO once a key is stored.');
            $this->assertStringContainsString('api.dataforseo.com', $call['url']);
            $this->assertSame(
                'Basic ' . base64_encode(self::DATAFORSEO_SECRET),
                $call['args']['headers']['Authorization'] ?? null,
                'The DataForSEO request must authenticate with the key that was just saved.'
            );
        }

        /**
         * Consumer B: SEO_Auditor::check_page_speed().
         */
        public function testSavedPageSpeedKeyReachesTheAuditor(): void
        {
            $auditor = new SEO_Auditor();
            $check = new \ReflectionMethod(SEO_Auditor::class, 'check_page_speed');
            $issues = new \ReflectionProperty(SEO_Auditor::class, 'issues');

            // Before saving: the audit records "not checked".
            $check->invoke($auditor, 'https://example.com/');
            $titles = array_column($issues->getValue($auditor), 'title');
            $this->assertContains(
                'PageSpeed Not Checked',
                $titles,
                'With no key stored, the audit must record that PageSpeed was skipped.'
            );

            $this->saveBothKeys();

            $auditor = new SEO_Auditor();
            $GLOBALS['mock_http_calls'] = [];
            $check->invoke($auditor, 'https://example.com/');

            $titles = array_column($issues->getValue($auditor), 'title');
            $this->assertNotContains(
                'PageSpeed Not Checked',
                $titles,
                'After saving a PageSpeed key the audit must stop skipping PageSpeed. '
                . 'If it still skips, the key the form saved is not the key check_page_speed() reads.'
            );

            $call = $GLOBALS['mock_http_calls'][0] ?? null;
            $this->assertNotNull($call, 'The audit must call the PageSpeed API once a key is stored.');
            $this->assertStringContainsString('pagespeedonline', $call['url']);
            $this->assertStringContainsString(
                'key=' . rawurlencode(self::PAGESPEED_SECRET),
                str_replace('+', '%20', $call['url']),
                'The PageSpeed request must carry the key that was just saved.'
            );
        }

        /**
         * Consumer C: Monitor_WebVitals::check_site().
         *
         * The Monitor module is a separate, globally-namespaced module that must
         * not depend on the SEO module being loaded, so it reads the option name
         * as a literal. Pin that literal to the canonical constant and prove the
         * saved value is readable through it.
         */
        public function testSavedPageSpeedKeyIsReadableByMonitorWebVitals(): void
        {
            $src = file_get_contents($this->repoRoot() . '/modules/monitor/class-monitor-webvitals.php');
            $this->assertNotFalse($src);

            $this->assertSame(
                1,
                preg_match("/\\\$api_key\s*=\s*get_option\(\s*'([^']+)'/", $src, $m),
                'Monitor_WebVitals::check_site() must read its PageSpeed key from a literal option name.'
            );

            $this->assertSame(
                SEO_Module::OPTION_PAGESPEED_KEY,
                $m[1],
                'Monitor Core Web Vitals reads a different option than the SEO settings page writes. '
                . 'That is exactly the silent-degradation bug: PageSpeed is skipped forever with no error.'
            );

            $this->saveBothKeys();

            $this->assertSame(
                self::PAGESPEED_SECRET,
                \get_option($m[1], ''),
                'The value the settings form saved must be readable at the option name '
                . 'Monitor_WebVitals reads.'
            );
        }

        /**
         * The dead option is gone: nothing may write a shape no consumer reads.
         */
        public function testHandlerDoesNotWriteAnOptionNobodyReads(): void
        {
            $this->saveBothKeys();

            $this->assertArrayNotHasKey(
                'peanut_seo_settings',
                $GLOBALS['mock_options'],
                'peanut_seo_settings is read by nothing in this plugin — writing it is how the '
                . 'original bug hid.'
            );
            $this->assertSame(
                [
                    SEO_Module::OPTION_DATAFORSEO_KEY => self::DATAFORSEO_SECRET,
                    SEO_Module::OPTION_PAGESPEED_KEY => self::PAGESPEED_SECRET,
                ],
                $GLOBALS['mock_options'],
                'A save must write exactly the two options the consumers read.'
            );
        }
    }
}
