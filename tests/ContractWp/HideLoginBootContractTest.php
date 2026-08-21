<?php
/**
 * Real-WordPress contract test: hide-login must actually ARM when enabled,
 * not merely be registered.
 *
 * The bug this pins (fleet notes: "suite's hide-login can never fire --
 * hook too late"): the plugin boots via peanut_init on init@10, and the
 * security module registered
 *
 *     add_action('init', [$this, 'hide_login_init'], 1);
 *
 * from inside that boot -- targeting a priority that had ALREADY RUN. The
 * callback never fired on any web request, so the hide-login feature has
 * been dead since it shipped: wp-login.php stayed reachable on every site
 * that enabled it. Same family as peanut-connect's dead migration hook
 * (connect#124) and formflow-lite's dead connector init (formflow-lite#51).
 *
 * The test reproduces the real boot ordering (init already fired) and
 * asserts the module's construction path EXECUTES the guard rather than
 * registering it into the void.
 */

namespace Peanut\Suite\Tests\ContractWp;

use WP_UnitTestCase;

class HideLoginBootContractTest extends WP_UnitTestCase
{
    public function test_hide_login_blocker_runs_despite_init_having_fired(): void
    {
        // Real boot ordering: by module-registration time, init is history.
        $this->assertGreaterThan(
            0,
            did_action('init'),
            'test premise: init must already have fired, as it has during the real peanut_init boot'
        );

        update_option('peanut_security_settings', [
            'hide_login_enabled' => true,
            'login_slug'         => 'secret-door',
            // home redirect (not 404) so the block path goes through
            // wp_safe_redirect, whose wp_redirect filter we can intercept --
            // the 404 branch include+exit would kill the test process.
            'redirect_slug'      => 'home',
        ] + (array) get_option('peanut_security_settings', []));

        // A direct, unauthorised wp-login.php hit: exactly what hide-login
        // exists to block.
        $_SERVER['REQUEST_URI']    = '/wp-login.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_COOKIE['peanut_login_access']);
        wp_set_current_user(0);

        // Intercept the redirect before its exit: reaching this filter IS the
        // proof that hide_login_init executed and blocked the request.
        $thrown = null;
        add_filter('wp_redirect', function ($location) {
            throw new \RuntimeException('blocked:' . $location);
        });

        require_once dirname(__DIR__, 2) . '/modules/security/class-security-module.php';

        try {
            new \Security_Module();
        } catch (\RuntimeException $e) {
            $thrown = $e->getMessage();
        }

        // The broken version registered init@1 from within init@10 -- a
        // priority that had already run -- so hide_login_init NEVER executed
        // and this unauthorised wp-login.php request sailed through
        // unblocked. The fixed version runs the blocker at construction.
        $this->assertNotNull(
            $thrown,
            'an unauthorised wp-login.php request must be blocked at boot -- an add_action for init@1 issued during init@10 is a silent no-op, which is why hide-login never worked'
        );
        $this->assertStringStartsWith('blocked:', $thrown);
    }
}
