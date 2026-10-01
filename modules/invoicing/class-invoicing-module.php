<?php
/**
 * Invoicing Module
 *
 * Stripe-powered invoicing for agency clients.
 * Agency feature - requires Agency license.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Invoicing_Module {

    /**
     * Initialize the module
     */
    public function __construct() {
        $this->load_dependencies();
        $this->register_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies(): void {
        require_once __DIR__ . '/class-invoicing-database.php';
        require_once __DIR__ . '/class-invoicing-stripe.php';
        require_once dirname(__DIR__, 2) . '/core/services/class-peanut-webhook-replay-guard.php';
    }

    /**
     * Register hooks
     */
    private function register_hooks(): void {
        // Create tables on activation
        register_activation_hook(PEANUT_PLUGIN_BASENAME, [Invoicing_Database::class, 'create_tables']);

        // Register REST API routes
        add_action('rest_api_init', [$this, 'register_routes']);

        // Handle Stripe webhooks
        add_action('rest_api_init', [$this, 'register_webhook_endpoint']);

        // Add admin menu
        add_action('admin_menu', [$this, 'add_submenu'], 30);

        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        require_once __DIR__ . '/api/class-invoicing-controller.php';
        $controller = new Invoicing_Controller();
        $controller->register_routes();
    }

    /**
     * Register webhook endpoint
     */
    public function register_webhook_endpoint(): void {
        register_rest_route(PEANUT_API_NAMESPACE, '/webhooks/stripe', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_stripe_webhook'],
            'permission_callback' => '__return_true', // Stripe validates via signature
        ]);
    }

    /**
     * Handle Stripe webhook
     */
    public function handle_stripe_webhook(\WP_REST_Request $request): \WP_REST_Response {
        $payload = $request->get_body();
        $sig_header = $request->get_header('Stripe-Signature');

        $settings = get_option('peanut_settings', []);
        $webhook_secret = $settings['stripe_webhook_secret'] ?? '';

        if (empty($webhook_secret)) {
            return new \WP_REST_Response(['error' => 'Webhook secret not configured'], 400);
        }

        $claim_id = null;

        try {
            $stripe = new Invoicing_Stripe();
            $event = $stripe->construct_webhook_event((string) $payload, (string) $sig_header, $webhook_secret);

            // construct_webhook_event() enforces Stripe's signed t= within
            // 300 s; the signed event id makes each event count once. A
            // replay (or a Stripe retry of an event already handled) is
            // acknowledged with 200 so Stripe stops retrying.
            $event_id = isset($event->id) && is_string($event->id) ? $event->id : '';
            if ($event_id === '') {
                throw new \Exception('Missing event id');
            }
            if (!Peanut_Webhook_Replay_Guard::claim('stripe-invoicing', $event_id, DAY_IN_SECONDS)) {
                return new \WP_REST_Response(['received' => true, 'duplicate' => true], 200);
            }
            $claim_id = $event_id;

            // Handle the event
            switch ($event->type) {
                case 'invoice.paid':
                    $this->handle_invoice_paid($event->data->object);
                    break;

                case 'invoice.payment_failed':
                    $this->handle_invoice_payment_failed($event->data->object);
                    break;

                case 'invoice.sent':
                    $this->handle_invoice_sent($event->data->object);
                    break;

                case 'invoice.voided':
                    $this->handle_invoice_voided($event->data->object);
                    break;
            }

            return new \WP_REST_Response(['received' => true], 200);

        } catch (\Exception $e) {
            if ($claim_id !== null) {
                // Handler failed: let Stripe's retry through.
                Peanut_Webhook_Replay_Guard::release('stripe-invoicing', $claim_id);
            }
            peanut_log_error('Stripe webhook error: ' . $e->getMessage(), 'error', 'invoicing');
            return new \WP_REST_Response(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Handle invoice paid event
     */
    private function handle_invoice_paid(object $invoice): void {
        Invoicing_Database::update_by_stripe_id($invoice->id, [
            'status' => 'paid',
            'paid_at' => date('Y-m-d H:i:s', $invoice->status_transitions->paid_at),
        ]);

        // Log the payment
        peanut_log_error("Invoice {$invoice->number} paid", 'info', 'invoicing');
    }

    /**
     * Handle invoice payment failed
     */
    private function handle_invoice_payment_failed(object $invoice): void {
        Invoicing_Database::update_by_stripe_id($invoice->id, [
            'status' => 'payment_failed',
        ]);

        peanut_log_error("Invoice {$invoice->number} payment failed", 'warning', 'invoicing');
    }

    /**
     * Handle invoice sent
     */
    private function handle_invoice_sent(object $invoice): void {
        Invoicing_Database::update_by_stripe_id($invoice->id, [
            'status' => 'sent',
            'sent_at' => current_time('mysql'),
        ]);
    }

    /**
     * Handle invoice voided
     */
    private function handle_invoice_voided(object $invoice): void {
        Invoicing_Database::update_by_stripe_id($invoice->id, [
            'status' => 'voided',
        ]);
    }

    /**
     * Add submenu page
     */
    public function add_submenu(): void {
        // Only show for agency tier
        if (!peanut_is_agency()) {
            return;
        }

        add_submenu_page(
            'peanut-dashboard',
            __('Invoicing', 'peanut-suite'),
            __('Invoicing', 'peanut-suite'),
            'manage_options',
            'peanut-invoicing',
            [$this, 'render_page']
        );
    }

    /**
     * Render the page
     */
    public function render_page(): void {
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'list';

        echo '<div class="wrap peanut-wrap">';

        switch ($view) {
            case 'create':
                require_once PEANUT_PLUGIN_DIR . 'core/admin/views/invoice-create.php';
                break;

            case 'detail':
                require_once PEANUT_PLUGIN_DIR . 'core/admin/views/invoice-detail.php';
                break;

            default:
                require_once PEANUT_PLUGIN_DIR . 'core/admin/views/invoices.php';
                break;
        }

        echo '</div>';
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts(string $hook): void {
        if (strpos($hook, 'peanut-invoicing') === false) {
            return;
        }

        wp_enqueue_style('peanut-admin');
        wp_enqueue_script('peanut-admin');
    }
}
