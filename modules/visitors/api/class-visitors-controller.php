<?php
/**
 * Visitors REST API Controller
 *
 * @package PeanutSuite\Visitors
 */

namespace PeanutSuite\Visitors;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * REST API endpoints for visitor tracking.
 */
class Visitors_Controller {

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
        // Public tracking endpoints
        register_rest_route($this->namespace, '/track', [
            'methods' => 'POST',
            'callback' => [$this, 'track_event'],
            'permission_callback' => '__return_true',
            'args' => [
                'visitor_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'event_type' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/track/identify', [
            'methods' => 'POST',
            'callback' => [$this, 'identify_visitor'],
            'permission_callback' => '__return_true',
            'args' => [
                'visitor_id' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'email' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_email',
                ],
            ],
        ]);

        // Admin endpoints
        register_rest_route($this->namespace, '/visitors', [
            'methods' => 'GET',
            'callback' => [$this, 'get_visitors'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'page' => [
                    'default' => 1,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default' => 20,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'search' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'identified_only' => [
                    'type' => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/visitors/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_admin_permission'],
        ]);

        register_rest_route($this->namespace, '/visitors/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_visitor'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/visitors/(?P<id>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'delete_visitor'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/visitors/(?P<id>\d+)/events', [
            'methods' => 'GET',
            'callback' => [$this, 'get_visitor_events'],
            'permission_callback' => [$this, 'check_admin_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'limit' => [
                    'default' => 100,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'event_type' => [
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Check admin permission.
     *
     * @return bool
     */
    public function check_admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Track an event.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function track_event(\WP_REST_Request $request): \WP_REST_Response {
        if (!Peanut_Security::check_rate_limit('track_event', 120, 60)) {
            return new \WP_REST_Response(['message' => 'Rate limit exceeded'], 429);
        }

        $visitor_id = $request->get_param('visitor_id');

        // Validate visitor ID format
        if (!Visitors_Tracker::validate_visitor_id($visitor_id)) {
            return new \WP_REST_Response(['error' => 'Invalid visitor ID'], 400);
        }

        // Check rate limit
        if (!Visitors_Tracker::check_rate_limit($visitor_id)) {
            return new \WP_REST_Response(['error' => 'Rate limit exceeded'], 429);
        }

        $event_type = Visitors_Tracker::sanitize_event_type($request->get_param('event_type'));
        $session_id = $request->get_param('session_id');

        // Get or create visitor
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $ua_data = Visitors_Tracker::parse_user_agent($user_agent);
        $settings = get_option('peanut_settings', []);
        $anonymize = !empty($settings['anonymize_ip']);

        $ip = Visitors_Tracker::get_ip_address($anonymize);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }

        $visitor_data = [
            'device_type' => $ua_data['device_type'],
            'browser' => $ua_data['browser'],
            'os' => $ua_data['os'],
        ];

        $visitor = Visitors_Database::get_or_create_visitor($visitor_id, $visitor_data);

        if (!$visitor) {
            return new \WP_REST_Response(['error' => 'Failed to track visitor'], 500);
        }

        // Prepare event data
        $event_data = [
            'visitor_id' => $visitor_id,
            'session_id' => $session_id && Visitors_Tracker::validate_session_id($session_id) ? $session_id : null,
            'event_type' => $event_type,
            'page_url' => Visitors_Tracker::sanitize_url($request->get_param('page_url') ?? ''),
            'page_title' => sanitize_text_field($request->get_param('page_title') ?? ''),
            'referrer' => Visitors_Tracker::sanitize_url($request->get_param('referrer') ?? ''),
            'utm_source' => sanitize_text_field($request->get_param('utm_source') ?? ''),
            'utm_medium' => sanitize_text_field($request->get_param('utm_medium') ?? ''),
            'utm_campaign' => sanitize_text_field($request->get_param('utm_campaign') ?? ''),
            'utm_term' => sanitize_text_field($request->get_param('utm_term') ?? ''),
            'utm_content' => sanitize_text_field($request->get_param('utm_content') ?? ''),
        ];

        // Custom data
        $custom_data = $request->get_param('custom_data');
        if ($custom_data) {
            $event_data['custom_data'] = $custom_data;
        }

        // Record event
        $event_id = Visitors_Database::record_event($event_data);

        if (!$event_id) {
            return new \WP_REST_Response(['error' => 'Failed to record event'], 500);
        }

        // Fire action for other modules to hook into
        do_action('peanut_visitor_event', $visitor_id, $event_type, $event_data);

        return new \WP_REST_Response([
            'success' => true,
            'event_id' => $event_id,
        ]);
    }

    /**
     * Identify a visitor with email.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function identify_visitor(\WP_REST_Request $request): \WP_REST_Response {
        if (!Peanut_Security::check_rate_limit('identify_visitor', 30, 60)) {
            return new \WP_REST_Response(['message' => 'Rate limit exceeded'], 429);
        }

        $visitor_id = $request->get_param('visitor_id');
        $email = $request->get_param('email');

        // Validate visitor ID
        if (!Visitors_Tracker::validate_visitor_id($visitor_id)) {
            return new \WP_REST_Response(['error' => 'Invalid visitor ID'], 400);
        }

        // Validate email
        if (!is_email($email)) {
            return new \WP_REST_Response(['error' => 'Invalid email address'], 400);
        }

        // Check rate limit
        if (!Visitors_Tracker::check_rate_limit($visitor_id)) {
            return new \WP_REST_Response(['error' => 'Rate limit exceeded'], 429);
        }

        // Ensure visitor exists
        $visitor = Visitors_Database::get_by_visitor_id($visitor_id);
        if (!$visitor) {
            // Create visitor if doesn't exist
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $ua_data = Visitors_Tracker::parse_user_agent($user_agent);
            $visitor = Visitors_Database::get_or_create_visitor($visitor_id, $ua_data);
        }

        if (!$visitor) {
            return new \WP_REST_Response(['error' => 'Visitor not found'], 404);
        }

        // Additional traits
        $traits = $request->get_param('traits') ?? [];

        // Check if we need to merge with existing identified visitor
        $existing = Visitors_Database::get_by_visitor_id($visitor_id);
        if ($existing && !empty($existing['email']) && $existing['email'] !== $email) {
            // Visitor already has different email - might be shared device
            // Just update to new email
        }

        // Update visitor with email
        $extra_data = [];
        if (!empty($traits['contact_id'])) {
            $extra_data['contact_id'] = absint($traits['contact_id']);
        }

        $result = Visitors_Database::identify($visitor_id, $email, $extra_data);

        if (!$result) {
            return new \WP_REST_Response(['error' => 'Failed to identify visitor'], 500);
        }

        // Fire action
        do_action('peanut_visitor_identified', $visitor_id, $email, $traits);

        return new \WP_REST_Response([
            'success' => true,
            'visitor_id' => $visitor_id,
            'email' => $email,
        ]);
    }

    /**
     * Get visitors list.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_visitors(\WP_REST_Request $request): \WP_REST_Response {
        $args = [
            'page' => $request->get_param('page'),
            'per_page' => min($request->get_param('per_page'), 100),
            'search' => $request->get_param('search'),
            'order_by' => $request->get_param('order_by') ?? 'last_seen',
            'order' => $request->get_param('order') ?? 'DESC',
            'identified_only' => $request->get_param('identified_only'),
        ];

        $result = Visitors_Database::get_all($args);

        return new \WP_REST_Response($result);
    }

    /**
     * Get visitor statistics.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_stats(\WP_REST_Request $request): \WP_REST_Response {
        $stats = Visitors_Database::get_stats();
        return new \WP_REST_Response($stats);
    }

    /**
     * Get a single visitor.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_visitor(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');
        $visitor = Visitors_Database::get_by_id($id);

        if (!$visitor) {
            return new \WP_REST_Response(['error' => 'Visitor not found'], 404);
        }

        // Get recent events
        $events = Visitors_Database::get_visitor_events($visitor['visitor_id'], [
            'limit' => 10,
        ]);

        $visitor['recent_events'] = $events;

        return new \WP_REST_Response($visitor);
    }

    /**
     * Delete a visitor.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function delete_visitor(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');

        $result = Visitors_Database::delete($id);

        if (!$result) {
            return new \WP_REST_Response(['error' => 'Failed to delete visitor'], 500);
        }

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Visitor deleted',
        ]);
    }

    /**
     * Get visitor events.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public function get_visitor_events(\WP_REST_Request $request): \WP_REST_Response {
        $id = $request->get_param('id');
        $visitor = Visitors_Database::get_by_id($id);

        if (!$visitor) {
            return new \WP_REST_Response(['error' => 'Visitor not found'], 404);
        }

        $args = [
            'limit' => min($request->get_param('limit'), 500),
            'event_type' => $request->get_param('event_type'),
        ];

        $events = Visitors_Database::get_visitor_events($visitor['visitor_id'], $args);

        return new \WP_REST_Response([
            'visitor_id' => $visitor['visitor_id'],
            'events' => $events,
            'total' => count($events),
        ]);
    }
}
