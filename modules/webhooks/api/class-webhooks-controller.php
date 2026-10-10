<?php
/**
 * Webhooks REST Controller
 *
 * Handles REST API endpoints for webhooks.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Webhooks_Controller extends Peanut_REST_Controller {

    protected string $rest_base = 'webhooks';

    /**
     * Register routes
     */
    public function register_routes(): void {
        // Public endpoint: receive webhooks for one source. The source is
        // bound by the route; the request body/headers cannot choose it.
        // (No WordPress auth: the sender proves itself with an HMAC signature.
        // A source with no secret is refused; see handle_delivery().)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/receive/(?P<source>[A-Za-z0-9._-]{1,64})', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'receive_source_webhook'],
            'permission_callback' => '__return_true', // Public endpoint
        ]);

        // Deprecated public endpoint: the sender names its source in an
        // X-Webhook-Source header or a "source" body field. Kept so existing
        // senders keep working; see receive_webhook().
        register_rest_route($this->namespace, '/' . $this->rest_base . '/receive', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'receive_webhook'],
            'permission_callback' => '__return_true', // Public endpoint
        ]);

        // Admin endpoints below expose every stored inbound webhook site-wide
        // (payload, sender IP, signature) and can re-fire peanut_webhook_*
        // actions, so they require manage_options, not just login + nonce.

        // Admin endpoint: List webhooks
        register_rest_route($this->namespace, '/' . $this->rest_base, [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_items'],
            'permission_callback' => [$this, 'admin_permission_callback'],
            'args' => $this->get_collection_params(),
        ]);

        // Admin endpoint: Get single webhook
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_item'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);

        // Admin endpoint: Reprocess webhook
        register_rest_route($this->namespace, '/' . $this->rest_base . '/(?P<id>\d+)/reprocess', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reprocess_webhook'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);

        // Admin endpoint: Get statistics
        register_rest_route($this->namespace, '/' . $this->rest_base . '/stats', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_stats'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);

        // Admin endpoint: Get filter options (sources, events)
        register_rest_route($this->namespace, '/' . $this->rest_base . '/filters', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_filters'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);

        // Admin endpoint: Bulk delete
        register_rest_route($this->namespace, '/' . $this->rest_base . '/bulk-delete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'bulk_delete'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);

        // Admin endpoints: per-source signing secrets. Status never includes
        // a secret; a generated secret is returned once, by the POST only.
        register_rest_route($this->namespace, '/' . $this->rest_base . '/signing', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'get_signing_status'],
                'permission_callback' => [$this, 'admin_permission_callback'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'set_signing_secret'],
                'permission_callback' => [$this, 'admin_permission_callback'],
            ],
        ]);

        register_rest_route($this->namespace, '/' . $this->rest_base . '/signing/(?P<source>[A-Za-z0-9._-]{1,64})', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_signing_secret'],
            'permission_callback' => [$this, 'admin_permission_callback'],
        ]);
    }

    /**
     * Receive a webhook on the per-source route (public endpoint).
     *
     * The source comes from the route only. A request that names a different
     * source in its X-Webhook-Source header or body "source" field is refused
     * (400): a payload signed for one source cannot be re-posted under
     * another source's route.
     */
    public function receive_source_webhook(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $source = (string) $request->get_param('source');
        if (!Webhooks_Signature::is_valid_source($source)) {
            return $this->error(__('Invalid source.', 'peanut-suite'), 'invalid_source', 400);
        }

        $payload = $request->get_json_params();
        if (is_array($payload) && !empty($payload)) {
            $named = Webhooks_Signature::get_source_from_request($payload);
            if ($named !== 'unknown' && $named !== $source) {
                return $this->error(
                    __('The request names a different source than this endpoint.', 'peanut-suite'),
                    'source_mismatch',
                    400
                );
            }
        }

        return $this->handle_delivery($request, $source);
    }

    /**
     * Receive a webhook on the deprecated legacy route (public endpoint).
     *
     * The sender names its source (X-Webhook-Source header, else the body
     * "source" field, else "unknown"). Kept for senders configured before
     * per-source routes existed. A request whose header and body name
     * different sources is refused: the header is not covered by a body
     * signature, so it must not relabel a signed body. A named source that has
     * a secret is only accepted with a valid signature under that secret, so
     * the label of a signed delivery is proven, not chosen. New senders should
     * use POST /webhooks/receive/{source}.
     */
    public function receive_webhook(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $payload = $request->get_json_params();
        $payload = is_array($payload) ? $payload : [];

        $header = isset($_SERVER['HTTP_X_WEBHOOK_SOURCE']) && is_string($_SERVER['HTTP_X_WEBHOOK_SOURCE'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WEBHOOK_SOURCE']))
            : '';
        $body = isset($payload['source']) && is_string($payload['source'])
            ? sanitize_text_field($payload['source'])
            : '';
        if ($header !== '' && $body !== '' && $header !== $body) {
            return $this->error(
                __('The X-Webhook-Source header and the body source disagree.', 'peanut-suite'),
                'source_mismatch',
                400
            );
        }

        return $this->handle_delivery($request, Webhooks_Signature::get_source_from_request($payload));
    }

    /**
     * Verify, dedupe, store and dispatch one delivery for a bound source.
     *
     * Signed sources (a secret is configured): the signature must cover a
     * timestamp within the tolerance window (401 otherwise), and each signed
     * delivery is accepted once; a replay gets 200 {duplicate: true} and is
     * neither stored nor dispatched again.
     */
    private function handle_delivery(WP_REST_Request $request, string $source): WP_REST_Response|WP_Error {
        // Rate limiting
        if (!Peanut_Security::check_rate_limit('webhook_receive', 100, 60)) {
            return $this->error(
                __('Rate limit exceeded. Please try again later.', 'peanut-suite'),
                'rate_limited',
                429
            );
        }

        // Get raw body for signature verification
        $raw_body = (string) $request->get_body();
        $payload = $request->get_json_params();

        if (empty($payload) || !is_array($payload)) {
            return $this->error(
                __('Invalid or empty payload.', 'peanut-suite'),
                'invalid_payload',
                400
            );
        }

        $event = sanitize_text_field($payload['event'] ?? 'unknown');

        // Every delivery must be signed with the source's secret. A missing
        // signature header is a failure, not a skip. A source with NO secret
        // is refused (fail closed): accepting it let anyone who knew the URL
        // forge FormFlow conversions, enrollments and visitor identities. The
        // only unsigned deliveries accepted are an administrator's own "Send
        // test" (REST cookie auth + nonce, source "test") and a non-FormFlow
        // source a site explicitly opted in (peanut_webhook_allow_unsigned).
        // A configured secret that cannot be decrypted fails closed.
        $signature = Webhooks_Signature::get_signature_from_headers();
        $claim = null;

        if (!Webhooks_Signature::has_secret($source)) {
            if (!$this->is_admin_test_delivery($source) && !Webhooks_Signature::allows_unsigned($source)) {
                Webhooks_Signature::record_unsigned_rejection($source);
                return $this->error(
                    __('This webhook source has no signing secret configured, so unsigned webhooks are refused. An administrator must set a signing secret for it on the Peanut Suite Webhooks page.', 'peanut-suite'),
                    'signature_required',
                    401
                );
            }
        } else {
            $result = Webhooks_Signature::verify_delivery(
                $raw_body,
                (string) $signature,
                $source,
                Webhooks_Signature::get_timestamp_from_headers(),
                $payload
            );

            if (!$result['ok']) {
                $messages = [
                    Peanut_Webhook_Replay_Guard::MISSING => __('The webhook signature does not cover a timestamp.', 'peanut-suite'),
                    Peanut_Webhook_Replay_Guard::STALE => __('The webhook timestamp is too old.', 'peanut-suite'),
                    Peanut_Webhook_Replay_Guard::FUTURE => __('The webhook timestamp is in the future.', 'peanut-suite'),
                ];
                return $this->error(
                    $messages[$result['error']] ?? __('Invalid or missing webhook signature.', 'peanut-suite'),
                    $result['error'],
                    401
                );
            }

            // Dedupe on signed material only: the signed timestamp and body.
            $claim = ['receive:' . $source, $result['timestamp'] . '.' . hash('sha256', $raw_body)];
            try {
                $first = Peanut_Webhook_Replay_Guard::claim($claim[0], $claim[1]);
            } catch (RuntimeException $e) {
                return $this->error(
                    __('The webhook could not be checked for replay. Please retry.', 'peanut-suite'),
                    'replay_check_unavailable',
                    503
                );
            }
            if (!$first) {
                return $this->success([
                    'received' => true,
                    'duplicate' => true,
                ], 200);
            }
        }

        // Get client IP
        $ip_address = Peanut_Security::get_client_ip();

        // Store webhook
        $webhook_id = Webhooks_Database::insert([
            'source' => $source,
            'event' => $event,
            'payload' => $payload,
            'signature' => $signature,
            'ip_address' => $ip_address,
            'status' => 'pending',
        ]);

        if (!$webhook_id) {
            if ($claim !== null) {
                // Let the sender's retry through.
                Peanut_Webhook_Replay_Guard::release($claim[0], $claim[1]);
            }
            return $this->error(
                __('Failed to store webhook.', 'peanut-suite'),
                'storage_failed',
                500
            );
        }

        // Process immediately if configured, otherwise queue for cron
        $process_immediately = apply_filters('peanut_webhook_process_immediately', true, $source, $event);

        if ($process_immediately) {
            Webhooks_Processor::process($webhook_id);
        }

        return $this->success([
            'received' => true,
            'webhook_id' => $webhook_id,
        ], 202);
    }

    /**
     * An administrator's own test delivery from the Webhooks page: source
     * "test", sent with WordPress cookie auth and a wp_rest nonce (so the REST
     * server has authenticated the user). Never matches an anonymous caller.
     */
    private function is_admin_test_delivery(string $source): bool {
        return $source === 'test' && is_user_logged_in() && current_user_can('manage_options');
    }

    /**
     * Signing status: which sources require a signature, which are unsigned.
     */
    public function get_signing_status(WP_REST_Request $request): WP_REST_Response {
        $seen = Webhooks_Database::get_sources();
        $sources = Webhooks_Signature::sources_status(is_array($seen) ? $seen : []);

        $unsigned_seen = [];
        foreach ($sources as $row) {
            if ($row['seen'] && !$row['signed']) {
                $unsigned_seen[] = $row['source'];
            }
        }

        return $this->success([
            'endpoint_url' => rest_url(PEANUT_API_NAMESPACE . '/webhooks/receive'),
            // Preferred: one URL per source; replace {source} with the name.
            'source_endpoint_url' => rest_url(PEANUT_API_NAMESPACE . '/webhooks/receive/{source}'),
            'timestamp_tolerance' => Peanut_Webhook_Replay_Guard::tolerance(),
            'sources' => $sources,
            'unsigned_seen_sources' => $unsigned_seen,
            // Unsigned deliveries refused recently because the source has no
            // secret: the senders an operator still needs to configure.
            'rejected_unsigned_sources' => Webhooks_Signature::rejected_unsigned_sources(),
        ]);
    }

    /**
     * Set or rotate a source's signing secret.
     *
     * generate=true creates a random secret and returns it in this response
     * only (it is never shown again). Otherwise `secret` is a secret issued
     * by the sender; it is stored but not echoed back.
     */
    public function set_signing_secret(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $source = (string) $request->get_param('source');
        if (!Webhooks_Signature::is_valid_source($source)) {
            return $this->error(
                __('Source names may use letters, numbers, dots, dashes and underscores (up to 64 characters).', 'peanut-suite'),
                'invalid_source',
                400
            );
        }

        $generate = filter_var($request->get_param('generate'), FILTER_VALIDATE_BOOLEAN);
        $secret = $generate ? Webhooks_Signature::generate_secret() : trim((string) $request->get_param('secret'));

        if (!$generate && strlen($secret) < Webhooks_Signature::MIN_SECRET_LENGTH) {
            return $this->error(
                sprintf(
                    /* translators: %d: minimum secret length */
                    __('A signing secret must be at least %d characters. Use Generate for a strong one.', 'peanut-suite'),
                    Webhooks_Signature::MIN_SECRET_LENGTH
                ),
                'invalid_secret',
                400
            );
        }

        if (!Webhooks_Signature::set_secret($source, $secret)) {
            return $this->error(
                __('The signing secret could not be stored encrypted on this server.', 'peanut-suite'),
                'secret_not_stored',
                500
            );
        }

        $data = ['source' => $source, 'signed' => true];
        if ($generate) {
            $data['secret'] = $secret;
        }
        return $this->success($data);
    }

    /**
     * Clear a source's signing secret (its webhooks are then refused until a
     * new secret is set).
     */
    public function delete_signing_secret(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $source = (string) $request->get_param('source');
        if (!Webhooks_Signature::is_valid_source($source)) {
            return $this->error(__('Invalid source.', 'peanut-suite'), 'invalid_source', 400);
        }

        Webhooks_Signature::delete_secret($source);

        return $this->success(['source' => $source, 'signed' => false]);
    }

    /**
     * Get webhooks list
     */
    public function get_items(WP_REST_Request $request): WP_REST_Response {
        $pagination = $this->get_pagination($request);

        $result = Webhooks_Database::get_all([
            'page' => $pagination['page'],
            'per_page' => $pagination['per_page'],
            'source' => $request->get_param('source'),
            'event' => $request->get_param('event'),
            'status' => $request->get_param('status'),
            'search' => $request->get_param('search'),
            'orderby' => $request->get_param('orderby') ?: 'created_at',
            'order' => $request->get_param('order') ?: 'DESC',
        ]);

        $items = array_map([$this, 'prepare_item'], $result['items']);

        return $this->paginated(
            $items,
            $result['total'],
            $result['page'],
            $result['per_page']
        );
    }

    /**
     * Get single webhook
     */
    public function get_item(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        $webhook = Webhooks_Database::get($id);

        if (!$webhook) {
            return $this->not_found(__('Webhook not found.', 'peanut-suite'));
        }

        return $this->success($this->prepare_item($webhook));
    }

    /**
     * Reprocess a webhook
     */
    public function reprocess_webhook(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request->get_param('id');
        $webhook = Webhooks_Database::get($id);

        if (!$webhook) {
            return $this->not_found(__('Webhook not found.', 'peanut-suite'));
        }

        $success = Webhooks_Processor::reprocess($id);

        if (!$success) {
            return $this->error(
                __('Failed to reprocess webhook.', 'peanut-suite'),
                'reprocess_failed',
                500
            );
        }

        // Get updated webhook
        $webhook = Webhooks_Database::get($id);

        return $this->success([
            'message' => __('Webhook reprocessed successfully.', 'peanut-suite'),
            'webhook' => $this->prepare_item($webhook),
        ]);
    }

    /**
     * Get webhook statistics
     */
    public function get_stats(WP_REST_Request $request): WP_REST_Response {
        $stats = Webhooks_Database::get_stats();

        return $this->success($stats);
    }

    /**
     * Get filter options
     */
    public function get_filters(WP_REST_Request $request): WP_REST_Response {
        return $this->success([
            'sources' => Webhooks_Database::get_sources(),
            'events' => Webhooks_Database::get_events(),
            'statuses' => ['pending', 'processing', 'processed', 'failed'],
        ]);
    }

    /**
     * Bulk delete webhooks
     */
    public function bulk_delete(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $ids = $request->get_param('ids');

        if (empty($ids) || !is_array($ids)) {
            return $this->error(__('No webhook IDs provided.', 'peanut-suite'));
        }

        global $wpdb;
        $ids = array_map('absint', $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM " . Webhooks_Database::webhooks_table() . " WHERE id IN ($placeholders)",
            ...$ids
        ));

        return $this->success([
            'message' => sprintf(__('%d webhooks deleted.', 'peanut-suite'), $deleted),
            'deleted' => $deleted,
        ]);
    }

    /**
     * Prepare webhook item for response
     */
    private function prepare_item(array $item): array {
        // Decode payload
        $payload = json_decode($item['payload'], true);

        return [
            'id' => (int) $item['id'],
            'source' => $item['source'],
            'event' => $item['event'],
            'payload' => $payload,
            'signature' => $item['signature'],
            'ip_address' => $item['ip_address'],
            'processed_at' => $item['processed_at'],
            'status' => $item['status'],
            'error_message' => $item['error_message'],
            'retry_count' => (int) $item['retry_count'],
            'created_at' => $item['created_at'],
        ];
    }

    /**
     * Get collection parameters
     */
    private function get_collection_params(): array {
        return [
            'page' => [
                'default' => 1,
                'sanitize_callback' => 'absint',
            ],
            'per_page' => [
                'default' => 20,
                'sanitize_callback' => 'absint',
            ],
            'source' => [
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'event' => [
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'status' => [
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'search' => [
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'orderby' => [
                'default' => 'created_at',
                'sanitize_callback' => 'sanitize_text_field',
            ],
            'order' => [
                'default' => 'DESC',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];
    }
}
