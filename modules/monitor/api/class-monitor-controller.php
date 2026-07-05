<?php
/**
 * Monitor REST API Controller
 *
 * Handles all Monitor-related API endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Monitor_Controller extends Peanut_REST_Controller {

    /**
     * @var Monitor_Sites
     */
    private Monitor_Sites $sites;

    /**
     * @var Monitor_Health
     */
    private Monitor_Health $health;

    /**
     * Constructor
     */
    public function __construct(Monitor_Sites $sites, Monitor_Health $health) {
        $this->sites = $sites;
        $this->health = $health;
        $this->rest_base = 'monitor';
    }

    /**
     * Register routes
     */
    public function register_routes(): void {
        // Sites endpoints
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/sites', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_sites'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => $this->get_collection_params(),
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'add_site'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'site_url' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'esc_url_raw',
                    ],
                    'site_key' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'site_name' => [
                        'required' => false,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/sites/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_site'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
            [
                'methods' => WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_site'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                    'site_name' => [
                        'required' => false,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'disconnect_site'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'type' => 'integer',
                    ],
                ],
            ],
        ]);

        // Health check endpoint
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/sites/(?P<id>\d+)/check', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'check_site_health'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ],
        ]);

        // Site updates endpoint
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/sites/(?P<id>\d+)/updates', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_site_updates'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'perform_site_update'],
                'permission_callback' => [$this, 'check_permission'],
                'args' => [
                    'type' => [
                        'required' => true,
                        'type' => 'string',
                        'enum' => ['plugin', 'theme', 'core'],
                    ],
                    'slug' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
        ]);

        // Site health history
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/sites/(?P<id>\d+)/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_site_health_history'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'days' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 30,
                ],
            ],
        ]);

        // Site uptime stats
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/sites/(?P<id>\d+)/uptime', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_site_uptime'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'days' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 30,
                ],
            ],
        ]);

        // Site analytics sync
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/sites/(?P<id>\d+)/analytics', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_site_analytics'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'sync_site_analytics'],
                'permission_callback' => [$this, 'check_permission'],
            ],
        ]);

        // All updates across sites
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/updates', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_all_updates'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Bulk update
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/updates/bulk', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulk_update'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'updates' => [
                    'required' => true,
                    'type' => 'array',
                ],
            ],
        ]);

        // Aggregated analytics
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/analytics', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_aggregated_analytics'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Overview dashboard
        register_rest_route(PEANUT_API_NAMESPACE, '/' . $this->rest_base . '/overview', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_overview'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    /**
     * Permission callback for every Monitor route.
     *
     * All ~20 routes above register this as their `permission_callback`, but the
     * method did not exist — WordPress treats a non-callable permission callback
     * as failing, so the entire Monitor REST surface mis-gated / erred. Monitor
     * is admin-only tooling (it connects, updates and reads managed child sites),
     * so this requires an authenticated administrator. Mirrors the base
     * controller's `admin_permission_callback` (logged-in + valid nonce +
     * `manage_options`). Do NOT relax this to a public callback.
     */
    public function check_permission(WP_REST_Request $request): bool|WP_Error {
        return $this->admin_permission_callback($request);
    }

    /**
     * Get sites list
     */
    public function get_sites(WP_REST_Request $request): WP_REST_Response {
        $args = [
            'status' => $request->get_param('status'),
            'search' => $request->get_param('search'),
            'page' => $request->get_param('page') ?? 1,
            'per_page' => $request->get_param('per_page') ?? 20,
            'order_by' => $request->get_param('order_by') ?? 'site_name',
            'order' => $request->get_param('order') ?? 'ASC',
        ];

        $result = $this->sites->get_all(array_filter($args));

        // Parse health data for each site
        foreach ($result['items'] as &$site) {
            $site->last_health = json_decode($site->last_health, true);
            $site->permissions = json_decode($site->permissions, true);
        }

        return $this->success($result);
    }

    /**
     * Add new site
     */
    public function add_site(WP_REST_Request $request): WP_REST_Response {
        // Check site limit
        if (!$this->sites->can_add_site()) {
            return $this->error(
                'site_limit_reached',
                __('You have reached your site limit. Please upgrade your plan.', 'peanut-suite'),
                403
            );
        }

        $result = $this->sites->add([
            'site_url' => $request->get_param('site_url'),
            'site_key' => $request->get_param('site_key'),
            'site_name' => $request->get_param('site_name'),
        ]);

        if (is_wp_error($result)) {
            return $this->error($result->get_error_code(), $result->get_error_message());
        }

        // Store encrypted site key
        $this->sites->store_site_key($result, $request->get_param('site_key'));

        $site = $this->sites->get($result);

        return $this->success([
            'id' => $result,
            'site' => $site,
            'message' => __('Site connected successfully.', 'peanut-suite'),
        ], 201);
    }

    /**
     * Get single site
     */
    public function get_site(WP_REST_Request $request): WP_REST_Response {
        $site = $this->sites->get($request->get_param('id'));

        if (!$site) {
            return $this->error('not_found', __('Site not found.', 'peanut-suite'), 404);
        }

        $site->last_health = json_decode($site->last_health, true);
        $site->permissions = json_decode($site->permissions, true);

        // Get uptime stats
        $site->uptime = $this->health->get_uptime_stats($site->id, 30);

        return $this->success($site);
    }

    /**
     * Update site
     */
    public function update_site(WP_REST_Request $request): WP_REST_Response {
        $result = $this->sites->update($request->get_param('id'), [
            'site_name' => $request->get_param('site_name'),
        ]);

        if (is_wp_error($result)) {
            return $this->error($result->get_error_code(), $result->get_error_message());
        }

        return $this->success(['message' => __('Site updated.', 'peanut-suite')]);
    }

    /**
     * Disconnect site
     */
    public function disconnect_site(WP_REST_Request $request): WP_REST_Response {
        $id = $request->get_param('id');

        $result = $this->sites->disconnect($id);

        if (is_wp_error($result)) {
            return $this->error($result->get_error_code(), $result->get_error_message());
        }

        // Delete stored site key
        $this->sites->delete_site_key($id);

        return $this->success(['message' => __('Site disconnected.', 'peanut-suite')]);
    }

    /**
     * Force health check on site
     */
    public function check_site_health(WP_REST_Request $request): WP_REST_Response {
        $site = $this->sites->get($request->get_param('id'));

        if (!$site) {
            return $this->error('not_found', __('Site not found.', 'peanut-suite'), 404);
        }

        $result = $this->health->check_site($site);

        return $this->success($result);
    }

    /**
     * Get pending updates for site
     */
    public function get_site_updates(WP_REST_Request $request): WP_REST_Response {
        $site = $this->sites->get($request->get_param('id'));

        if (!$site) {
            return $this->error('not_found', __('Site not found.', 'peanut-suite'), 404);
        }

        $result = $this->health->get_pending_updates($site);

        if (is_wp_error($result)) {
            return $this->error($result->get_error_code(), $result->get_error_message());
        }

        return $this->success($result);
    }

    /**
     * Perform update on site
     */
    public function perform_site_update(WP_REST_Request $request): WP_REST_Response {
        $site = $this->sites->get($request->get_param('id'));

        if (!$site) {
            return $this->error('not_found', __('Site not found.', 'peanut-suite'), 404);
        }

        $result = $this->health->perform_update(
            $site,
            $request->get_param('type'),
            $request->get_param('slug')
        );

        if (is_wp_error($result)) {
            return $this->error($result->get_error_code(), $result->get_error_message());
        }

        return $this->success([
            'message' => __('Update completed.', 'peanut-suite'),
            'result' => $result,
        ]);
    }

    /**
     * Get site health history
     */
    public function get_site_health_history(WP_REST_Request $request): WP_REST_Response {
        $site = $this->sites->get($request->get_param('id'));

        if (!$site) {
            return $this->error('not_found', __('Site not found.', 'peanut-suite'), 404);
        }

        $history = $this->health->get_health_history(
            $site->id,
            $request->get_param('days') ?? 30
        );

        return $this->success($history);
    }

    /**
     * Get site uptime stats
     */
    public function get_site_uptime(WP_REST_Request $request): WP_REST_Response {
        $site = $this->sites->get($request->get_param('id'));

        if (!$site) {
            return $this->error('not_found', __('Site not found.', 'peanut-suite'), 404);
        }

        $stats = $this->health->get_uptime_stats(
            $site->id,
            $request->get_param('days') ?? 30
        );

        return $this->success($stats);
    }

    /**
     * Get site analytics
     */
    public function get_site_analytics(WP_REST_Request $request): WP_REST_Response {
        $site = $this->sites->get($request->get_param('id'));

        if (!$site) {
            return $this->error('not_found', __('Site not found.', 'peanut-suite'), 404);
        }

        if (!$site->peanut_suite_active) {
            return $this->error(
                'no_peanut_suite',
                __('Peanut Suite is not active on this site.', 'peanut-suite')
            );
        }

        global $wpdb;
        $table = Monitor_Database::analytics_table();

        $analytics = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE site_id = %d AND period = 'month' AND period_start = DATE_FORMAT(NOW(), '%%Y-%%m-01')",
            $site->id
        ));

        if ($analytics) {
            $analytics->metrics = json_decode($analytics->metrics, true);
        }

        return $this->success($analytics);
    }

    /**
     * Sync analytics from site
     */
    public function sync_site_analytics(WP_REST_Request $request): WP_REST_Response {
        $site = $this->sites->get($request->get_param('id'));

        if (!$site) {
            return $this->error('not_found', __('Site not found.', 'peanut-suite'), 404);
        }

        $result = $this->health->sync_analytics($site);

        if (is_wp_error($result)) {
            return $this->error($result->get_error_code(), $result->get_error_message());
        }

        return $this->success([
            'message' => __('Analytics synced.', 'peanut-suite'),
            'data' => $result,
        ]);
    }

    /**
     * Get all pending updates across all sites
     */
    public function get_all_updates(WP_REST_Request $request): WP_REST_Response {
        $updates = $this->health->get_all_pending_updates();
        return $this->success($updates);
    }

    /**
     * Perform bulk updates
     */
    public function bulk_update(WP_REST_Request $request): WP_REST_Response {
        $updates = $request->get_param('updates');
        $results = [];

        foreach ($updates as $update) {
            $site = $this->sites->get($update['site_id']);

            if (!$site) {
                $results[] = [
                    'site_id' => $update['site_id'],
                    'success' => false,
                    'error' => __('Site not found.', 'peanut-suite'),
                ];
                continue;
            }

            $result = $this->health->perform_update($site, $update['type'], $update['slug']);

            $results[] = [
                'site_id' => $update['site_id'],
                'site_name' => $site->site_name,
                'type' => $update['type'],
                'slug' => $update['slug'],
                'success' => !is_wp_error($result),
                'error' => is_wp_error($result) ? $result->get_error_message() : null,
            ];
        }

        $success_count = count(array_filter($results, fn($r) => $r['success']));

        return $this->success([
            'message' => sprintf(
                __('%d of %d updates completed successfully.', 'peanut-suite'),
                $success_count,
                count($results)
            ),
            'results' => $results,
        ]);
    }

    /**
     * Get aggregated analytics
     */
    public function get_aggregated_analytics(WP_REST_Request $request): WP_REST_Response {
        $analytics = $this->health->get_aggregated_analytics();
        return $this->success($analytics);
    }

    /**
     * Get monitor overview dashboard data
     */
    public function get_overview(WP_REST_Request $request): WP_REST_Response {
        $sites_data = $this->sites->get_all(['status' => 'active']);
        $sites = $sites_data['items'];

        $overview = [
            'total_sites' => count($sites),
            'healthy_sites' => 0,
            'warning_sites' => 0,
            'critical_sites' => 0,
            'offline_sites' => 0,
            'total_updates' => 0,
            'sites_with_peanut' => 0,
            'sites' => [],
        ];

        foreach ($sites as $site) {
            $health = json_decode($site->last_health, true);
            $status = $health['status'] ?? 'unknown';

            switch ($status) {
                case 'healthy':
                    $overview['healthy_sites']++;
                    break;
                case 'warning':
                    $overview['warning_sites']++;
                    break;
                case 'critical':
                    $overview['critical_sites']++;
                    break;
                case 'offline':
                    $overview['offline_sites']++;
                    break;
            }

            if ($site->peanut_suite_active) {
                $overview['sites_with_peanut']++;
            }

            // Count updates if available in health data
            if (isset($health['checks']['plugins']['updates_available'])) {
                $overview['total_updates'] += $health['checks']['plugins']['updates_available'];
            }
            if (isset($health['checks']['themes']['updates_available'])) {
                $overview['total_updates'] += $health['checks']['themes']['updates_available'];
            }

            $overview['sites'][] = [
                'id' => $site->id,
                'name' => $site->site_name,
                'url' => $site->site_url,
                'status' => $status,
                'score' => $health['score'] ?? 0,
                'last_check' => $site->last_check,
                'peanut_suite' => (bool) $site->peanut_suite_active,
            ];
        }

        // Get aggregated analytics if any sites have Peanut Suite
        if ($overview['sites_with_peanut'] > 0) {
            $overview['analytics'] = $this->health->get_aggregated_analytics();
        }

        return $this->success($overview);
    }

    /**
     * Get collection params for list endpoints
     */
    protected function get_collection_params(): array {
        return [
            'page' => [
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
            ],
            'per_page' => [
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
            ],
            'search' => [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['active', 'disconnected', 'error'],
            ],
            'order_by' => [
                'type' => 'string',
                'default' => 'site_name',
                'enum' => ['site_name', 'site_url', 'created_at', 'last_check'],
            ],
            'order' => [
                'type' => 'string',
                'default' => 'ASC',
                'enum' => ['ASC', 'DESC'],
            ],
        ];
    }
}
