<?php
/**
 * FormFlow REST Controller
 *
 * Provides REST API endpoints for FormFlow integration.
 * Receives webhook events from FormFlow Lite.
 *
 * @package Peanut_Suite
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 3) . '/core/services/class-peanut-webhook-replay-guard.php';

class FormFlow_Controller {

    /**
     * REST namespace
     */
    private const NAMESPACE = 'peanut/v1';

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        // Receive webhook events from FormFlow
        register_rest_route(self::NAMESPACE, '/formflow/event', [
            'methods' => 'POST',
            'callback' => [$this, 'receive_event'],
            'permission_callback' => [$this, 'verify_request'],
        ]);

        // Get FormFlow stats
        register_rest_route(self::NAMESPACE, '/formflow/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'verify_admin'],
            'args' => [
                'start_date' => [
                    'type' => 'string',
                    'format' => 'date',
                    'default' => date('Y-m-d', strtotime('-30 days')),
                ],
                'end_date' => [
                    'type' => 'string',
                    'format' => 'date',
                    'default' => date('Y-m-d'),
                ],
            ],
        ]);

        // Get attribution data
        register_rest_route(self::NAMESPACE, '/formflow/attribution', [
            'methods' => 'GET',
            'callback' => [$this, 'get_attribution'],
            'permission_callback' => [$this, 'verify_admin'],
            'args' => [
                'start_date' => [
                    'type' => 'string',
                    'format' => 'date',
                    'default' => date('Y-m-d', strtotime('-30 days')),
                ],
                'end_date' => [
                    'type' => 'string',
                    'format' => 'date',
                    'default' => date('Y-m-d'),
                ],
            ],
        ]);
    }

    /**
     * Option that re-enables the legacy unsigned loopback path.
     *
     * Only consulted when NO webhook secret is configured. Off by default.
     */
    public const ALLOW_UNSIGNED_LOOPBACK_OPTION = 'peanut_formflow_allow_unsigned_loopback';

    /**
     * Verify webhook request from FormFlow
     *
     * FormFlow Lite's Peanut Suite preset (PeanutWebhookPreset::create())
     * generates a secret, stores it in `fffl_peanut_webhook_secret` for us, and
     * signs every delivery with a bare-hex HMAC-SHA256 of the raw JSON body in
     * `X-FFFL-Signature`. So:
     *
     *  - Secret configured: a valid X-FFFL-Signature is REQUIRED. Missing -> 401,
     *    wrong -> 403. Nothing else (source header, Referer, IP) is trusted.
     *    The signed body's "timestamp" (FormFlow Lite sends current_time('c'))
     *    must be within the replay tolerance window (401 otherwise), and a
     *    signed delivery is accepted once (a replay gets 409).
     *  - No secret configured: rejected (401), unless the site explicitly opts
     *    in via the `peanut_formflow_allow_unsigned_loopback` option, in which
     *    case only a loopback / same-address request is accepted. The Referer
     *    header is never trusted: any client can send it.
     *
     * @param WP_REST_Request $request Request object
     * @return bool|WP_Error True if valid, error otherwise
     */
    public function verify_request(WP_REST_Request $request): bool|WP_Error {
        $secret = (string) FormFlow_Module::get_webhook_secret();

        if ($secret !== '') {
            $signature = (string) $request->get_header('X-FFFL-Signature');

            if ($signature === '') {
                return new WP_Error(
                    'missing_signature',
                    'Webhook signature required',
                    ['status' => 401]
                );
            }

            $expected = hash_hmac('sha256', $request->get_body(), $secret);

            if (!hash_equals($expected, $signature)) {
                return new WP_Error(
                    'invalid_signature',
                    'Invalid webhook signature',
                    ['status' => 403]
                );
            }

            return $this->verify_freshness($request->get_body());
        }

        if (get_option(self::ALLOW_UNSIGNED_LOOPBACK_OPTION, false) && $this->is_loopback_request()) {
            return true;
        }

        return new WP_Error(
            'unauthorized',
            'Webhook authentication required',
            ['status' => 401]
        );
    }

    /**
     * Replay protection for a signed FormFlow delivery: the signed body's
     * "timestamp" must be within the tolerance window, and the delivery must
     * not have been accepted before. The X-FFFL-Timestamp header is not
     * signed, so it is not used.
     *
     * @param string $body Raw (signed) request body.
     * @return true|WP_Error
     */
    private function verify_freshness(string $body): bool|WP_Error {
        $decoded = json_decode($body, true);
        $timestamp = Peanut_Webhook_Replay_Guard::parse_timestamp(is_array($decoded) ? ($decoded['timestamp'] ?? null) : null);

        $reason = Peanut_Webhook_Replay_Guard::check_timestamp($timestamp);
        if ($reason !== Peanut_Webhook_Replay_Guard::OK) {
            return new WP_Error(
                $reason,
                'Webhook timestamp missing or outside the allowed window',
                ['status' => 401]
            );
        }

        try {
            $first = Peanut_Webhook_Replay_Guard::claim('formflow-event', $timestamp . '.' . hash('sha256', $body));
        } catch (RuntimeException $e) {
            return new WP_Error(
                'replay_check_unavailable',
                'Webhook could not be checked for replay',
                ['status' => 503]
            );
        }

        if (!$first) {
            return new WP_Error(
                'duplicate_delivery',
                'Webhook delivery already received',
                ['status' => 409]
            );
        }

        return true;
    }

    /**
     * Whether the TCP peer is this server (loopback or the server's own
     * address). Only used behind the explicit unsigned opt-in: behind a
     * reverse proxy every request can look local, which is why it is opt-in.
     *
     * @return bool
     */
    private function is_loopback_request(): bool {
        $remote_ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $server_ip = (string) ($_SERVER['SERVER_ADDR'] ?? '');

        if ($remote_ip === '') {
            return false;
        }

        if (in_array($remote_ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        return $server_ip !== '' && $remote_ip === $server_ip;
    }

    /**
     * Verify admin access
     *
     * @return bool
     */
    public function verify_admin(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Receive and process webhook event from FormFlow
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public function receive_event(WP_REST_Request $request): WP_REST_Response {
        $body = $request->get_json_params();

        if (empty($body) || empty($body['event'])) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'Invalid payload',
            ], 400);
        }

        $event = sanitize_text_field($body['event']);
        $data = $body;

        // Process based on event type
        switch ($event) {
            case 'form.viewed':
                $this->process_form_view($data);
                break;

            case 'form.step_completed':
                $this->process_step_completed($data);
                break;

            case 'account.validated':
                $this->process_account_validated($data);
                break;

            case 'enrollment.submitted':
            case 'enrollment.completed':
                $this->process_enrollment($data);
                break;

            case 'enrollment.failed':
                $this->process_enrollment_failed($data);
                break;

            case 'appointment.scheduled':
                $this->process_appointment($data);
                break;

            default:
                // Store generic event
                $this->process_generic_event($data);
        }

        // Log the received event
        do_action('peanut_log', 'debug', 'FormFlow event received', [
            'event' => $event,
            'source' => $body['source'] ?? 'unknown',
        ]);

        return new WP_REST_Response([
            'success' => true,
            'event' => $event,
            'received_at' => current_time('c'),
        ], 200);
    }

    /**
     * Process form view event
     *
     * @param array $data Event data
     */
    private function process_form_view(array $data): void {
        $db = new FormFlow_Database();

        $instance_id = $data['instance']['id'] ?? 0;
        $visitor_id = $data['visitor_id'] ?? '';

        if ($instance_id && $visitor_id) {
            $db->record_view((int)$instance_id, $visitor_id);
        }

        // Fire WordPress action for other handlers
        do_action('peanut_formflow_view', $data);
    }

    /**
     * Process step completed event
     *
     * @param array $data Event data
     */
    private function process_step_completed(array $data): void {
        // Update visitor journey if tracking module available
        if (class_exists('Visitors_Database')) {
            $visitors_db = new \PeanutSuite\Visitors\Visitors_Database();
            $visitors_db->record_touchpoint(
                $data['visitor_id'] ?? '',
                'form_step',
                [
                    'step' => $data['step']['number'] ?? 0,
                    'step_name' => $data['step']['name'] ?? '',
                    'instance_id' => $data['instance']['id'] ?? 0,
                ]
            );
        }

        do_action('peanut_formflow_step', $data);
    }

    /**
     * Process account validated event
     *
     * @param array $data Event data
     */
    private function process_account_validated(array $data): void {
        // Track validation as funnel step
        do_action('peanut_formflow_validated', $data);
    }

    /**
     * Process enrollment event
     *
     * @param array $data Event data
     */
    private function process_enrollment(array $data): void {
        $db = new FormFlow_Database();

        $submission_data = [
            'visitor_id' => $data['visitor_id'] ?? null,
            'instance_id' => $data['instance']['id'] ?? null,
            'submission_id' => $data['submission']['id'] ?? null,
            'email' => $data['customer']['email'] ?? null,
            'status' => $data['event'] === 'enrollment.completed' ? 'completed' : 'submitted',
            'conversion_type' => 'enrollment',
        ];

        // Add attribution data
        if (!empty($data['attribution'])) {
            $submission_data['utm_source'] = $data['attribution']['utm_source'] ?? null;
            $submission_data['utm_medium'] = $data['attribution']['utm_medium'] ?? null;
            $submission_data['utm_campaign'] = $data['attribution']['utm_campaign'] ?? null;
            $submission_data['utm_term'] = $data['attribution']['utm_term'] ?? null;
            $submission_data['utm_content'] = $data['attribution']['utm_content'] ?? null;
        }

        // Store metadata
        $submission_data['metadata'] = array_filter([
            'instance_slug' => $data['instance']['slug'] ?? null,
            'utility' => $data['instance']['utility'] ?? null,
            'device_type' => $data['customer']['device_type'] ?? null,
            'confirmation_number' => $data['submission']['confirmation_number'] ?? null,
        ]);

        $db->record_submission($submission_data);

        // Fire conversion action
        if ($data['event'] === 'enrollment.completed') {
            do_action('peanut_conversion', [
                'source' => 'formflow-lite',
                'form_id' => $data['instance']['id'] ?? 0,
                'submission_id' => $data['submission']['id'] ?? 0,
                'visitor_id' => $data['visitor_id'] ?? '',
                'email' => $data['customer']['email'] ?? '',
                'conversion_type' => 'enrollment',
                'utm_source' => $data['attribution']['utm_source'] ?? '',
                'utm_medium' => $data['attribution']['utm_medium'] ?? '',
                'utm_campaign' => $data['attribution']['utm_campaign'] ?? '',
                'metadata' => $submission_data['metadata'],
            ]);
        }

        do_action('peanut_formflow_enrollment', $data);
    }

    /**
     * Process enrollment failed event
     *
     * @param array $data Event data
     */
    private function process_enrollment_failed(array $data): void {
        $db = new FormFlow_Database();

        $db->record_submission([
            'visitor_id' => $data['visitor_id'] ?? null,
            'instance_id' => $data['instance']['id'] ?? null,
            'submission_id' => $data['submission']['id'] ?? null,
            'status' => 'failed',
            'metadata' => [
                'error' => $data['error'] ?? null,
                'error_code' => $data['error_code'] ?? null,
            ],
        ]);

        do_action('peanut_formflow_failed', $data);
    }

    /**
     * Process appointment event
     *
     * @param array $data Event data
     */
    private function process_appointment(array $data): void {
        // Store scheduling data as part of the submission
        $db = new FormFlow_Database();

        // Update existing submission with scheduling data
        if (!empty($data['submission']['id'])) {
            global $wpdb;
            $table = $wpdb->prefix . 'peanut_formflow_submissions';

            $wpdb->update(
                $table,
                [
                    'metadata' => json_encode([
                        'appointment_date' => $data['scheduling']['date'] ?? null,
                        'appointment_time' => $data['scheduling']['time_slot'] ?? null,
                        'fsr_number' => $data['scheduling']['fsr_number'] ?? null,
                    ]),
                ],
                ['submission_id' => $data['submission']['id']]
            );
        }

        do_action('peanut_formflow_appointment', $data);
    }

    /**
     * Process generic event
     *
     * @param array $data Event data
     */
    private function process_generic_event(array $data): void {
        // Store in webhooks log for debugging
        do_action('peanut_log', 'debug', 'FormFlow generic event', $data);
    }

    /**
     * Get FormFlow statistics
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public function get_stats(WP_REST_Request $request): WP_REST_Response {
        $db = new FormFlow_Database();

        $stats = $db->get_stats(
            $request->get_param('start_date'),
            $request->get_param('end_date')
        );

        return new WP_REST_Response([
            'success' => true,
            'data' => $stats,
        ], 200);
    }

    /**
     * Get attribution data
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public function get_attribution(WP_REST_Request $request): WP_REST_Response {
        $db = new FormFlow_Database();

        $attribution = $db->get_attribution_data(
            $request->get_param('start_date'),
            $request->get_param('end_date')
        );

        return new WP_REST_Response([
            'success' => true,
            'data' => $attribution,
        ], 200);
    }
}
