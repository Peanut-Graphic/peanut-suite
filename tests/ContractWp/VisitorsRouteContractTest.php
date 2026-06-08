<?php
/**
 * Real-WordPress REST contract test (net 7) for the admin visitors list route.
 *
 * Pins the REAL `GET /peanut/v1/visitors` route registered by
 * \PeanutSuite\Visitors\Visitors_Controller::register_routes(). The route's
 * permission_callback is `check_admin_permission()` => `current_user_can('manage_options')`,
 * so this is an auth-gated surface. Per the wp-harness "auth-only APIs still have a
 * contract" guidance, we pin the REAL auth surface on a real WordPress:
 *
 *   - Unauthenticated  GET /peanut/v1/visitors => 401 (rest_authorization_required)
 *   - Authenticated    GET /peanut/v1/visitors => 200 + documented shape
 *
 * Documented 200 response shape (see Visitors_Database::get_all):
 *   [ 'data' => array, 'total' => int, 'total_pages' => int|float,
 *     'page' => int, 'per_page' => int ]
 *
 * This boots a real WordPress and dispatches through the real REST server —
 * NO mocks. If the route, its auth gate, or its shape regresses, this fails.
 */

namespace PeanutSuite\Tests\ContractWp;

use WP_UnitTestCase;
use WP_REST_Request;
use PeanutSuite\Visitors\Visitors_Controller;
use PeanutSuite\Visitors\Visitors_Database;

class VisitorsRouteContractTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();

        // The visitors module is loaded by the module manager in production. Here we
        // wire its real REST controller + tables explicitly, then rebuild the REST
        // server so the just-registered routes are live. (The plugin's global
        // `Peanut_` autoloader, registered during boot, resolves Peanut_Security et al.
        // referenced inside the controller's callbacks.)
        $base = PEANUT_PLUGIN_DIR . 'modules/visitors/';
        require_once $base . 'class-visitors-database.php';
        require_once $base . 'class-visitors-tracker.php';
        require_once $base . 'api/class-visitors-controller.php';

        Visitors_Database::create_tables();

        add_action('rest_api_init', static function () {
            (new Visitors_Controller())->register_routes();
        });

        global $wp_rest_server;
        $wp_rest_server = null;
        do_action('rest_api_init');
    }

    public function test_visitors_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/peanut/v1/visitors',
            $routes,
            'Admin visitors list route must be registered on a real WordPress.'
        );
    }

    public function test_unauthenticated_request_is_rejected(): void {
        wp_set_current_user(0);

        $request  = new WP_REST_Request('GET', '/peanut/v1/visitors');
        $response = rest_get_server()->dispatch($request);

        // Real status from the real permission_callback (manage_options denied).
        $this->assertContains(
            $response->get_status(),
            [401, 403],
            'Unauthenticated visitors request must be rejected with 401/403.'
        );
    }

    public function test_authenticated_admin_gets_documented_contract(): void {
        $admin_id = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($admin_id);

        $request  = new WP_REST_Request('GET', '/peanut/v1/visitors');
        $response = rest_get_server()->dispatch($request);

        $this->assertSame(
            200,
            $response->get_status(),
            'Authenticated admin must receive HTTP 200 from the visitors list route.'
        );

        $data = $response->get_data();

        // Documented response-shape keys.
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data);
        $this->assertArrayHasKey('total_pages', $data);
        $this->assertArrayHasKey('page', $data);
        $this->assertArrayHasKey('per_page', $data);
        $this->assertIsArray($data['data']);
    }
}
