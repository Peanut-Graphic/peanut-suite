<?php
/**
 * Base REST Controller
 *
 * Shared functionality for all API endpoints.
 */

if (!defined('ABSPATH')) {
    exit;
}

abstract class Peanut_REST_Controller {

    protected string $namespace = PEANUT_API_NAMESPACE;
    protected string $rest_base = '';

    /**
     * Register routes (implemented by child classes)
     */
    abstract public function register_routes(): void;

    /**
     * Standard permission callback
     */
    public function permission_callback(WP_REST_Request $request): bool|WP_Error {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_not_logged_in',
                __('Authentication required.', 'peanut-suite'),
                ['status' => 401]
            );
        }

        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return new WP_Error(
                'rest_invalid_nonce',
                __('Invalid security token.', 'peanut-suite'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Admin permission callback
     */
    public function admin_permission_callback(WP_REST_Request $request): bool|WP_Error {
        $base = $this->permission_callback($request);
        if (is_wp_error($base)) {
            return $base;
        }

        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('Administrator access required.', 'peanut-suite'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Peanut Suite access permission callback
     *
     * Login + nonce, plus the `peanut_access` capability: WordPress
     * administrators, or users a team admin added to a Peanut account (see
     * Peanut_Admin::grant_peanut_access_to_team()). Use it for writes a
     * non-admin team member legitimately performs in the Suite app but a
     * plain Subscriber must not.
     */
    public function peanut_access_permission_callback(WP_REST_Request $request): bool|WP_Error {
        $base = $this->permission_callback($request);
        if (is_wp_error($base)) {
            return $base;
        }

        if (!current_user_can('peanut_access')) {
            return new WP_Error(
                'rest_forbidden',
                __('Peanut Suite access required.', 'peanut-suite'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Success response
     */
    protected function success(array $data = [], int $status = 200): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    /**
     * Paginated response
     */
    protected function paginated(array $items, int $total, int $page, int $per_page): WP_REST_Response {
        return new WP_REST_Response([
            'success' => true,
            'data' => $items,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $per_page,
                'total_pages' => (int) ceil($total / $per_page),
            ],
        ], 200);
    }

    /**
     * Error response
     */
    protected function error(string $message, string $code = 'error', int $status = 400): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }

    /**
     * Not found response
     */
    protected function not_found(string $message = 'Resource not found'): WP_Error {
        return $this->error($message, 'not_found', 404);
    }

    /**
     * Get pagination params
     */
    protected function get_pagination(WP_REST_Request $request): array {
        return [
            'page' => max(1, (int) $request->get_param('page') ?: 1),
            'per_page' => min(100, max(1, (int) $request->get_param('per_page') ?: 20)),
        ];
    }

    /**
     * Get sort params
     */
    protected function get_sort(WP_REST_Request $request, array $allowed = []): array {
        $orderby = $request->get_param('sort_by') ?: 'created_at';
        $order = strtoupper($request->get_param('sort_order') ?: 'DESC');

        if (!empty($allowed) && !in_array($orderby, $allowed, true)) {
            $orderby = 'created_at';
        }

        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }

        return ['orderby' => $orderby, 'order' => $order];
    }

    /**
     * Check feature limit
     */
    protected function check_limit(string $feature, int $current_count): bool {
        $license = new Peanut_License();
        $limit = $license->get_limit($feature);

        // -1 means unlimited
        if ($limit === -1) {
            return true;
        }

        return $current_count < $limit;
    }
}
