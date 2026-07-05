<?php
/**
 * Regression guards for the "broken / mis-gated endpoint" cluster (W4).
 *
 * Three independent runtime bugs are pinned here, each red-then-green:
 *
 *  (a) Monitor REST mis-gate — every Monitor route registered
 *      permission_callback => [this, check_permission], but the method did NOT
 *      exist, so the whole admin Monitor surface mis-gated / erred. The restored
 *      method must exist AND enforce administrator access (manage_options). We
 *      prove enforcement through the SAME base gate (admin_permission_callback)
 *      it delegates to.
 *
 *  (b) Public /track + /track/identify fatal — the visitors controller lives in
 *      namespace PeanutSuite\Visitors and called the security class UNqualified.
 *      That class is GLOBAL-namespace and PHP does NOT fall back to the global
 *      namespace for class names, so the bare call fataled (Class not found) and
 *      took the public tracking endpoints down — the rate limit never ran. The
 *      sibling NamespacedClassCallSiteTest does NOT catch this (it treats
 *      globally-declared names as resolving), so this is the dedicated guard:
 *      every reference to the security class in that file must be fully
 *      qualified (leading backslash).
 *
 *  (c) SSRF — Monitor fetches admin-supplied URLs. The restored guard
 *      Monitor_Sites::is_safe_remote_url() must reject loopback / private /
 *      link-local / metadata / IPv6-loopback targets and allow public ones.
 *
 * SELF-CONTAINED: pure PHP. No WordPress boot, no DB, no network (only IP-literal
 * hosts are tested, so no DNS lookups). Mirrors the sibling Regression-guard
 * pattern (NamespacedClassCallSiteTest).
 *
 * @package Peanut_Suite
 */

// --- Global-namespace shims (only if a real WP harness is not present). -------
// The Regression suite runs without a WordPress boot; the plugin mock file
// provides WP_Error but not the auth helpers or WP_REST_Request, so define
// controllable stubs in the GLOBAL namespace. Guards keep this inert under a
// real WP test lib.
namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    if (!defined('PEANUT_API_NAMESPACE')) {
        define('PEANUT_API_NAMESPACE', 'peanut/v1');
    }
    if (!function_exists('__')) {
        function __($text, $domain = 'default') { return $text; }
    }
    if (!class_exists('WP_Error')) {
        class WP_Error {
            public array $errors = [];
            public array $error_data = [];
            public function __construct($code = '', $message = '', $data = '') {
                if ($code !== '') {
                    $this->errors[$code][] = $message;
                    if ($data !== '') { $this->error_data[$code] = $data; }
                }
            }
            public function get_error_code() { return array_key_first($this->errors) ?? ''; }
            public function get_error_data($code = '') {
                $code = $code !== '' ? $code : $this->get_error_code();
                return $this->error_data[$code] ?? null;
            }
        }
    }
    if (!function_exists('is_user_logged_in')) {
        function is_user_logged_in(): bool { return (bool) ($GLOBALS['__peanut_test_logged_in'] ?? false); }
    }
    if (!function_exists('wp_verify_nonce')) {
        function wp_verify_nonce($nonce, $action = -1) { return $nonce === 'valid-nonce' ? 1 : false; }
    }
    if (!function_exists('current_user_can')) {
        function current_user_can($cap, ...$args): bool {
            return in_array($cap, $GLOBALS['__peanut_test_caps'] ?? [], true);
        }
    }
    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request {
            private array $headers;
            public function __construct(array $headers = []) { $this->headers = $headers; }
            public function get_header($key) { return $this->headers[$key] ?? null; }
            public function get_param($key) { return null; }
        }
    }
}

namespace PeanutSuite\Tests\Regression {

    use PHPUnit\Framework\TestCase;

    final class RestoredEndpointGuardsTest extends TestCase
    {
        private function repoRoot(): string
        {
            return dirname(__DIR__, 2);
        }

        // -- (a) Monitor REST denies non-admin ----------------------------------

        public function testMonitorControllerDefinesPermissionCallback(): void
        {
            require_once $this->repoRoot() . '/core/api/class-peanut-rest-controller.php';
            require_once $this->repoRoot() . '/modules/monitor/api/class-monitor-controller.php';

            $ref = new \ReflectionClass(\Monitor_Controller::class);

            $this->assertTrue(
                $ref->hasMethod('check_permission'),
                'Monitor_Controller::check_permission() must exist — every route registers it '
                . 'as its permission_callback; a missing method mis-gates the whole surface.'
            );
            $this->assertTrue(
                $ref->getMethod('check_permission')->isPublic(),
                'check_permission() must be public so WordPress can invoke it as a permission_callback.'
            );
        }

        public function testAdminGateDeniesNonAdminAndAllowsAdmin(): void
        {
            require_once $this->repoRoot() . '/core/api/class-peanut-rest-controller.php';

            // Concrete stand-in for the abstract base — check_permission()
            // delegates to admin_permission_callback(), so proving the base gate
            // proves the Monitor route gate.
            $ctrl = new class extends \Peanut_REST_Controller {
                public function register_routes(): void {}
            };

            $request = new \WP_REST_Request(['X-WP-Nonce' => 'valid-nonce']);
            $GLOBALS['__peanut_test_logged_in'] = true;

            // Logged-in non-admin => forbidden.
            $GLOBALS['__peanut_test_caps'] = [];
            $denied = $ctrl->admin_permission_callback($request);
            $this->assertInstanceOf(\WP_Error::class, $denied, 'Non-admin must be denied.');
            $data = $denied->get_error_data();
            $this->assertSame(403, $data['status'] ?? null, 'Non-admin denial must be HTTP 403.');

            // Administrator => allowed.
            $GLOBALS['__peanut_test_caps'] = ['manage_options'];
            $this->assertTrue(
                $ctrl->admin_permission_callback($request),
                'Administrator with a valid nonce must be allowed.'
            );
        }

        // -- (b) /track no longer fatals; rate limit can run --------------------

        public function testVisitorsControllerFullyQualifiesGlobalSecurityClass(): void
        {
            $file = $this->repoRoot() . '/modules/visitors/api/class-visitors-controller.php';
            // Strip comments/whitespace so only real code is scanned (a doc
            // comment mentioning the class must not trip the guard).
            $code = php_strip_whitespace($file);
            $this->assertNotSame('', $code);

            // Any bare (unqualified) Peanut_Security:: — not preceded by a
            // backslash or word char — would fatal from inside the namespaced file.
            $this->assertSame(
                0,
                preg_match_all('/(?<![\\\\\w])Peanut_Security::/', $code),
                'Unqualified Peanut_Security:: reference found — from namespace '
                . 'PeanutSuite\\Visitors this fatals at runtime and breaks the public '
                . '/track endpoint. Use \\Peanut_Security:: instead.'
            );

            // And the fix must actually be present: the two rate-limit call sites,
            // fully qualified, so the limiter runs before any work.
            $this->assertSame(
                2,
                preg_match_all('/\\\\Peanut_Security::check_rate_limit\(/', $code),
                'Expected the two fully-qualified \\Peanut_Security::check_rate_limit() '
                . 'call sites (track_event + identify_visitor).'
            );
        }

        // -- (c) SSRF guard blocks internal targets, allows public --------------

        public function testSsrfGuardBlocksInternalRangesAndAllowsPublic(): void
        {
            require_once $this->repoRoot() . '/modules/monitor/class-monitor-sites.php';

            $blocked = [
                'http://127.0.0.1/wp-json',        // IPv4 loopback
                'https://10.0.0.5/',               // RFC1918 10/8
                'https://172.16.4.4/',             // RFC1918 172.16/12
                'https://192.168.1.10/',           // RFC1918 192.168/16
                'http://169.254.169.254/latest/',  // link-local + cloud metadata
                'http://[::1]/',                   // IPv6 loopback
            ];
            foreach ($blocked as $url) {
                $this->assertFalse(
                    \Monitor_Sites::is_safe_remote_url($url),
                    "SSRF guard must BLOCK internal/reserved target: {$url}"
                );
            }

            // Empty / unparseable host fails closed.
            $this->assertFalse(\Monitor_Sites::is_safe_remote_url(''));
            $this->assertFalse(\Monitor_Sites::is_safe_remote_url('not a url'));

            // Public routable addresses are allowed (IP literals => no DNS).
            $this->assertTrue(\Monitor_Sites::is_safe_remote_url('https://93.184.216.34/'));
            $this->assertTrue(\Monitor_Sites::is_safe_remote_url('https://8.8.8.8/'));
        }
    }
}
