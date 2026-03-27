<?php
/**
 * Attribution REST API Controller
 *
 * @package PeanutSuite\Attribution
 */

namespace PeanutSuite\Attribution;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API endpoints for attribution.
 */
class Attribution_Controller {

    /**
     * REST namespace.
     *
     * @var string
     */
    protected string $namespace = 'peanut/v1';

    /**
     * Register REST routes.
     */
    public function register_routes(): void {
        // Conversions
        register_rest_route($this->namespace, '/attribution/conversions', [
            'methods' => 'GET',
            'callback' => [$this, 'get_conversions'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'page' => [
                    'default' => 1,
                    'type' => 'integer',
                ],
                'per_page' => [
                    'default' => 20,
                    'type' => 'integer',
                ],
                'conversion_type' => [
                    'type' => 'string',
                ],
                'date_from' => [
                    'type' => 'string',
                ],
                'date_to' => [
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/attribution/conversions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_conversion'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
                'model' => [
                    'type' => 'string',
                ],
            ],
        ]);

        // Touches
        register_rest_route($this->namespace, '/attribution/touches', [
            'methods' => 'GET',
            'callback' => [$this, 'get_touches'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'visitor_id' => [
                    'type' => 'string',
                ],
                'conversion_id' => [
                    'type' => 'integer',
                ],
            ],
        ]);

        // Reports
        register_rest_route($this->namespace, '/attribution/report', [
            'methods' => 'GET',
            'callback' => [$this, 'get_report'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'model' => [
                    'default' => 'last_touch',
                    'type' => 'string',
                ],
                'date_from' => [
                    'type' => 'string',
                ],
                'date_to' => [
                    'type' => 'string',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/attribution/compare', [
            'methods' => 'GET',
            'callback' => [$this, 'compare_models'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'date_from' => [
                    'type' => 'string',
                ],
                'date_to' => [
                    'type' => 'string',
                ],
            ],
        ]);

        // Channel performance
        register_rest_route($this->namespace, '/attribution/channels', [
            'methods' => 'GET',
            'callback' => [$this, 'get_channels'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'model' => [
                    'default' => 'last_touch',
                    'type' => 'string',
                ],
                'date_from' => [
                    'type' => 'string',
                ],
                'date_to' => [
                    'type' => 'string',
                ],
            ],
        ]);

        // Stats
        register_rest_route($this->namespace, '/attribution/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Models info
        register_rest_route($this->namespace, '/attribution/models', [
            'methods' => 'GET',
            'callback' => [$this, 'get_models'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        // Markov attribution
        register_rest_route($this->namespace, '/attribution/markov', [
            'methods' => 'GET',
            'callback' => [$this, 'get_markov_report'],
            'permission_callback' => [$this, 'check_permission'],
            'args' => [
                'date_from' => [
                    'type' => 'string',
                    'required' => false,
                ],
                'date_to' => [
                    'type' => 'string',
                    'required' => false,
                ],
            ],
        ]);
    }

    /**
     * Check admin permission.
     *
     * @return bool
     */
    public function check_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get conversions list.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_conversions(\WP_REST_Request $request): \WP_REST_Response {
        $args = [
            'page' => $request->get_param('page'),
            'per_page' => min($request->get_param('per_page'), 100),
            'conversion_type' => $request->get_param('conversion_type'),
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
        ];

        $result = Attribution_Database::get_conversions($args);

        return new \WP_REST_Response($result);
    }

    /**
     * Get single conversion with attribution.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_conversion(\WP_REST_Request $request): \WP_REST_Response {
        $id = (int) $request->get_param('id');
        $model = $request->get_param('model');

        $conversion = Attribution_Database::get_conversion($id);

        if (!$conversion) {
            return new \WP_REST_Response(['error' => 'Conversion not found'], 404);
        }

        // Get touches linked to this conversion
        $touches = Attribution_Database::get_visitor_touches($conversion['visitor_id'], [
            'conversion_id' => $id,
        ]);

        // Get attribution results
        $attribution = Attribution_Database::get_attribution_results($id, $model);

        // If no attribution calculated yet, calculate now
        if (empty($attribution)) {
            Attribution_Calculator::calculate_for_conversion($id);
            $attribution = Attribution_Database::get_attribution_results($id, $model);
        }

        $conversion['touches'] = $touches;
        $conversion['attribution'] = $attribution;

        return new \WP_REST_Response($conversion);
    }

    /**
     * Get touches.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_touches(\WP_REST_Request $request): \WP_REST_Response {
        $visitor_id = $request->get_param('visitor_id');
        $conversion_id = $request->get_param('conversion_id');

        if (!$visitor_id && !$conversion_id) {
            return new \WP_REST_Response(['error' => 'visitor_id or conversion_id required'], 400);
        }

        if ($conversion_id) {
            $conversion = Attribution_Database::get_conversion($conversion_id);
            if (!$conversion) {
                return new \WP_REST_Response(['error' => 'Conversion not found'], 404);
            }
            $visitor_id = $conversion['visitor_id'];
        }

        $touches = Attribution_Database::get_visitor_touches($visitor_id, [
            'conversion_id' => $conversion_id,
        ]);

        return new \WP_REST_Response([
            'visitor_id' => $visitor_id,
            'touches' => $touches,
            'total' => count($touches),
        ]);
    }

    /**
     * Get attribution report.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_report(\WP_REST_Request $request): \WP_REST_Response {
        $model = $request->get_param('model');
        $args = [
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
        ];

        // Validate model
        if (!array_key_exists($model, Attribution_Models::MODELS)) {
            return new \WP_REST_Response(['error' => 'Invalid attribution model'], 400);
        }

        $report = Attribution_Calculator::get_report($model, $args);

        return new \WP_REST_Response($report);
    }

    /**
     * Compare all models.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function compare_models(\WP_REST_Request $request): \WP_REST_Response {
        $args = [
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
        ];

        $comparison = Attribution_Calculator::compare_models($args);

        return new \WP_REST_Response($comparison);
    }

    /**
     * Get channel performance.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_channels(\WP_REST_Request $request): \WP_REST_Response {
        $model = $request->get_param('model');
        $args = [
            'date_from' => $request->get_param('date_from'),
            'date_to' => $request->get_param('date_to'),
        ];

        $channels = Attribution_Database::get_channel_performance($model, $args);

        return new \WP_REST_Response([
            'model' => $model,
            'channels' => $channels,
        ]);
    }

    /**
     * Get stats.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_stats(\WP_REST_Request $request): \WP_REST_Response {
        $stats = Attribution_Database::get_stats();
        return new \WP_REST_Response($stats);
    }

    /**
     * Get available models.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_models(\WP_REST_Request $request): \WP_REST_Response {
        $models = [];

        foreach (Attribution_Models::MODELS as $key => $name) {
            $models[] = [
                'id' => $key,
                'name' => $name,
                'description' => Attribution_Models::get_description($key),
            ];
        }

        return new \WP_REST_Response($models);
    }

    /**
     * Get Markov chain attribution report.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_markov_report(\WP_REST_Request $request): \WP_REST_Response {
        $date_from = $request->get_param('date_from') ?? gmdate('Y-m-d', strtotime('-30 days'));
        $date_to = $request->get_param('date_to') ?? gmdate('Y-m-d');

        if (!class_exists('Peanut_Markov_Attribution')) {
            return new \WP_REST_Response(
                ['error' => 'Markov attribution service unavailable'],
                503
            );
        }

        $report = Peanut_Markov_Attribution::get_report($date_from, $date_to);
        if (!$report) {
            return new \WP_REST_Response(
                ['error' => 'Markov attribution service is unavailable'],
                503
            );
        }

        return new \WP_REST_Response($report);
    }
}
