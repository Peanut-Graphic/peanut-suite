<?php
/**
 * Real-WordPress regression tests for the popup unauth lead-capture hardening
 * (security cluster W2).
 *
 * Boots a REAL WordPress (via the shared Peanut wp-harness) — NO mocks — and pins
 * the four security guarantees the W2 fix introduces:
 *
 *   (a) An UNAUTHENTICATED popup convert must NOT overwrite an existing CRM
 *       contact's identity fields (name) — it may only make the non-destructive
 *       interaction touch. (P0 contact-overwrite abuse.)
 *   (b) The nopriv convert AJAX handler is rate limited (429 once the per-IP
 *       budget is exceeded). (P0 flood abuse.)
 *   (c) Captured form_data is sanitized before it is persisted to the interaction
 *       log (no stored markup / XSS). (P3.)
 *   (d) The popups REST management routes deny a non-administrator — proving the
 *       previously-missing `check_permission()` gate now exists and is wired.
 *       (P2 availability / mis-gating.)
 *
 * Mirrors tests/ContractWp/VisitorsRouteContractTest.php. The popups module
 * classes are global (unprefixed) rather than namespaced, so they are referenced
 * without a namespace here.
 *
 * NOTE: this suite requires the real-WP harness (phpunit.contract-wp.xml +
 * wp-contract.yml). It was NOT executed in the authoring sandbox (no WordPress
 * test library available there); it runs in CI on a real WordPress + MySQL.
 */

namespace PeanutSuite\Tests\ContractWp;

use WP_UnitTestCase;
use WP_Ajax_UnitTestCase;
use WP_REST_Request;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;
use ReflectionMethod;
use Popups_Module;
use Popups_Controller;
use Popups_Database;

/**
 * REST-gate + contact-overwrite + sanitize guarantees (non-AJAX surface).
 */
class PopupsHardeningContractTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();

        $base = PEANUT_PLUGIN_DIR . 'modules/popups/';
        require_once $base . 'class-popups-database.php';
        require_once $base . 'class-popups-renderer.php';
        require_once $base . 'class-popups-triggers.php';
        require_once $base . 'class-popups-module.php';
        require_once $base . 'api/class-popups-controller.php';

        Popups_Database::create_tables();

        add_action('rest_api_init', static function () {
            (new Popups_Controller())->register_routes();
        });

        global $wp_rest_server;
        $wp_rest_server = null;
        do_action('rest_api_init');
    }

    /** (d) The management route is registered on a real WordPress. */
    public function test_popups_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/peanut/v1/popups',
            $routes,
            'Popups management route must be registered.'
        );
    }

    /** (d) A non-administrator (and the anonymous public) is denied. */
    public function test_popups_route_denies_non_admin(): void {
        // Anonymous.
        wp_set_current_user(0);
        $anon = rest_get_server()->dispatch(new WP_REST_Request('GET', '/peanut/v1/popups'));
        $this->assertContains(
            $anon->get_status(),
            [401, 403],
            'Anonymous popups request must be rejected with 401/403.'
        );

        // Logged-in subscriber (lacks manage_options).
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriber);
        $sub = rest_get_server()->dispatch(new WP_REST_Request('GET', '/peanut/v1/popups'));
        $this->assertContains(
            $sub->get_status(),
            [401, 403],
            'Non-admin popups request must be rejected with 401/403 (check_permission gate).'
        );
    }

    /**
     * (a) An unauthenticated convert must never overwrite an existing contact's
     * name. Exercises the real Popups_Module::create_contact_from_popup path.
     */
    public function test_unauth_convert_does_not_overwrite_existing_contact_name(): void {
        global $wpdb;
        $contacts = $wpdb->prefix . 'peanut_contacts';
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$contacts} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED DEFAULT 0,
            email VARCHAR(191) NOT NULL,
            first_name VARCHAR(191) DEFAULT '',
            last_name VARCHAR(191) DEFAULT '',
            status VARCHAR(32) DEFAULT '',
            source VARCHAR(64) DEFAULT '',
            source_detail VARCHAR(191) DEFAULT '',
            score INT DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id)
        )");
        $activities = $wpdb->prefix . 'peanut_contact_activities';
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$activities} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contact_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(64) DEFAULT '',
            description LONGTEXT NULL,
            created_at DATETIME NULL,
            PRIMARY KEY (id)
        )");

        // Seed an existing contact whose identity we own.
        $wpdb->insert($contacts, [
            'email'      => 'victim@example.com',
            'first_name' => 'Alice',
            'last_name'  => 'Owner',
            'updated_at' => current_time('mysql'),
        ]);
        $contact_id = (int) $wpdb->insert_id;

        // Seed a popup so the source-attribution lookup succeeds.
        $popups = Popups_Database::popups_table();
        $wpdb->insert($popups, [
            'user_id' => 1,
            'name'    => 'Newsletter',
            'status'  => 'active',
        ]);
        $popup_id = (int) $wpdb->insert_id;

        // Attacker POST: known email, hostile name.
        $method = new ReflectionMethod(Popups_Module::class, 'create_contact_from_popup');
        $method->setAccessible(true);
        $method->invoke(new Popups_Module(), $popup_id, [
            'email'      => 'victim@example.com',
            'first_name' => 'Attacker',
            'last_name'  => 'Pwned',
        ]);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT first_name, last_name FROM {$contacts} WHERE id = %d",
            $contact_id
        ));

        $this->assertSame('Alice', $row->first_name, 'Existing contact first_name must NOT be overwritten.');
        $this->assertSame('Owner', $row->last_name, 'Existing contact last_name must NOT be overwritten.');
    }

    /**
     * (c) form_data is sanitized before being written to the interaction log.
     */
    public function test_form_data_is_sanitized_before_storage(): void {
        global $wpdb;
        $method = new ReflectionMethod(Popups_Module::class, 'log_interaction');
        $method->setAccessible(true);
        $method->invoke(new Popups_Module(), 123, 'convert', [
            'first_name' => '<script>alert(1)</script>Bob',
            'nested'     => ['note' => '<img src=x onerror=alert(1)>'],
        ]);

        $interactions = Popups_Database::interactions_table();
        $data = $wpdb->get_var($wpdb->prepare(
            "SELECT data FROM {$interactions} WHERE popup_id = %d ORDER BY id DESC LIMIT 1",
            123
        ));

        $this->assertIsString($data);
        $this->assertStringNotContainsString('<script', $data, 'Stored form_data must not contain raw <script>.');
        $this->assertStringNotContainsString('onerror=', $data, 'Nested form_data must be recursively sanitized.');
    }
}

/**
 * (b) The nopriv convert AJAX handler is rate limited. Uses the AJAX test harness
 * so wp_send_json's terminating die is catchable.
 */
class PopupsConvertRateLimitTest extends WP_Ajax_UnitTestCase {

    private Popups_Module $module;

    public function set_up(): void {
        parent::set_up();

        $base = PEANUT_PLUGIN_DIR . 'modules/popups/';
        require_once $base . 'class-popups-database.php';
        require_once $base . 'class-popups-renderer.php';
        require_once $base . 'class-popups-triggers.php';
        require_once $base . 'class-popups-module.php';

        Popups_Database::create_tables();

        $this->module = new Popups_Module();
        add_action('wp_ajax_nopriv_peanut_popup_convert', [$this->module, 'handle_popup_convert']);

        // Deterministic trusted IP so the per-IP rate-limit key is stable.
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
    }

    public function test_convert_is_rate_limited(): void {
        $_POST['popup_id'] = 1;
        // Intentionally no email so the (unauthenticated) contact-create branch is
        // skipped — this test isolates the rate limiter.
        $_POST['form_data'] = ['first_name' => 'x'];

        $rate_limited = false;

        // Budget is 30/min; 40 attempts must trip the limiter at least once.
        for ($i = 0; $i < 40; $i++) {
            $_POST['nonce'] = wp_create_nonce('peanut_popup_action');
            $this->_last_response = '';
            try {
                $this->_handleAjax('nopriv_peanut_popup_convert');
            } catch (WPAjaxDieContinueException | WPAjaxDieStopException $e) {
                // wp_send_json terminates via die() — expected.
            }

            $decoded = json_decode($this->_last_response, true);
            if (is_array($decoded)
                && ($decoded['success'] ?? true) === false
                && str_contains(wp_json_encode($decoded), 'Rate limit')) {
                $rate_limited = true;
                break;
            }
        }

        $this->assertTrue($rate_limited, 'Convert handler must return a rate-limit error once the per-IP budget is exceeded.');
    }
}
