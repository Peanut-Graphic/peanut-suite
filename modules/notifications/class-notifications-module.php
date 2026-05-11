<?php
/**
 * Notifications Module
 *
 * Slack, Discord, and other notification integrations.
 */

namespace PeanutSuite\Notifications;

if (!defined('ABSPATH')) {
    exit;
}

class Notifications_Module {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // init() is called by Peanut_Module_Manager after instantiation.
    }

    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);

        // Hook into various events
        add_action('peanut_contact_created', [$this, 'notify_contact_created']);
        add_action('peanut_link_clicked', [$this, 'notify_link_clicked'], 10, 2);
        add_action('peanut_popup_conversion', [$this, 'notify_popup_conversion'], 10, 2);
        add_action('peanut_webhook_received', [$this, 'notify_webhook_received'], 10, 2);

        // WooCommerce
        add_action('woocommerce_order_status_completed', [$this, 'notify_woo_order']);

        // AJAX handlers
        add_action('wp_ajax_peanut_save_notification_channels', [$this, 'ajax_save_channels']);
        add_action('wp_ajax_peanut_save_notification_events', [$this, 'ajax_save_events']);
        add_action('wp_ajax_peanut_test_notification', [$this, 'ajax_test_notification']);
    }

    /**
     * AJAX: Save notification channels
     */
    public function ajax_save_channels(): void {
        check_ajax_referer('peanut_notifications', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $settings = [
            'slack_webhook' => esc_url_raw($_POST['slack_webhook'] ?? ''),
            'discord_webhook' => esc_url_raw($_POST['discord_webhook'] ?? ''),
            'telegram_bot_token' => sanitize_text_field($_POST['telegram_bot_token'] ?? ''),
            'telegram_chat_id' => sanitize_text_field($_POST['telegram_chat_id'] ?? ''),
        ];

        update_option('peanut_notification_settings', $settings);
        wp_send_json_success(['message' => 'Channels saved']);
    }

    /**
     * AJAX: Save notification events
     */
    public function ajax_save_events(): void {
        check_ajax_referer('peanut_notifications', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $events = [];
        if (!empty($_POST['events']) && is_array($_POST['events'])) {
            foreach ($_POST['events'] as $event => $channels) {
                $events[sanitize_key($event)] = array_map('sanitize_text_field', (array) $channels);
            }
        }

        update_option('peanut_notification_events', $events);
        wp_send_json_success(['message' => 'Events saved']);
    }

    /**
     * AJAX: Test notification
     */
    public function ajax_test_notification(): void {
        check_ajax_referer('peanut_notifications', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $channel = sanitize_text_field($_POST['channel'] ?? '');
        $message_text = __('This is a test notification from Peanut Suite!', 'peanut-suite');

        $result = false;
        switch ($channel) {
            case 'slack':
                $result = $this->send_slack([
                    'text' => $message_text,
                    'username' => 'Peanut Suite',
                ]);
                break;
            case 'discord':
                $result = $this->send_discord([
                    'content' => $message_text,
                    'username' => 'Peanut Suite',
                ]);
                break;
            case 'telegram':
                $result = $this->send_telegram($message_text);
                break;
        }

        if ($result) {
            wp_send_json_success(['message' => 'Test sent successfully']);
        } else {
            wp_send_json_error('Failed to send test notification');
        }
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route(PEANUT_API_NAMESPACE, '/notifications/settings', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_settings'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_settings'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/notifications/test', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'test_notification'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);
    }

    public function admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get notification settings
     */
    public function get_notification_settings(): array {
        return get_option('peanut_notification_settings', [
            'slack' => [
                'enabled' => false,
                'webhook_url' => '',
                'channel' => '',
                'events' => ['contact_created', 'order_completed'],
            ],
            'discord' => [
                'enabled' => false,
                'webhook_url' => '',
                'events' => ['contact_created', 'order_completed'],
            ],
            'telegram' => [
                'enabled' => false,
                'bot_token' => '',
                'chat_id' => '',
                'events' => [],
            ],
            'email' => [
                'enabled' => true,
                'recipients' => get_option('admin_email'),
                'events' => ['order_completed'],
            ],
        ]);
    }

    /**
     * Get settings via API
     */
    public function get_settings(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response($this->get_notification_settings(), 200);
    }

    /**
     * Save settings via API
     */
    public function save_settings(\WP_REST_Request $request): \WP_REST_Response {
        $settings = [
            'slack' => [
                'enabled' => (bool) $request->get_param('slack_enabled'),
                'webhook_url' => esc_url_raw($request->get_param('slack_webhook_url') ?: ''),
                'channel' => sanitize_text_field($request->get_param('slack_channel') ?: ''),
                'events' => array_map('sanitize_text_field', $request->get_param('slack_events') ?: []),
            ],
            'discord' => [
                'enabled' => (bool) $request->get_param('discord_enabled'),
                'webhook_url' => esc_url_raw($request->get_param('discord_webhook_url') ?: ''),
                'events' => array_map('sanitize_text_field', $request->get_param('discord_events') ?: []),
            ],
            'telegram' => [
                'enabled' => (bool) $request->get_param('telegram_enabled'),
                'bot_token' => sanitize_text_field($request->get_param('telegram_bot_token') ?: ''),
                'chat_id' => sanitize_text_field($request->get_param('telegram_chat_id') ?: ''),
                'events' => array_map('sanitize_text_field', $request->get_param('telegram_events') ?: []),
            ],
            'email' => [
                'enabled' => (bool) $request->get_param('email_enabled'),
                'recipients' => sanitize_textarea_field($request->get_param('email_recipients') ?: ''),
                'events' => array_map('sanitize_text_field', $request->get_param('email_events') ?: []),
            ],
        ];

        update_option('peanut_notification_settings', $settings);

        return new \WP_REST_Response(['message' => 'Settings saved'], 200);
    }

    /**
     * Test notification
     */
    public function test_notification(\WP_REST_Request $request): \WP_REST_Response {
        $channel = $request->get_param('channel');

        $result = match($channel) {
            'slack' => $this->send_slack([
                'text' => 'Test notification from Peanut Suite',
                'attachments' => [[
                    'color' => '#22c55e',
                    'title' => 'Test Successful',
                    'text' => 'Your Slack integration is working correctly!',
                    'footer' => 'Peanut Suite',
                    'ts' => time(),
                ]],
            ]),
            'discord' => $this->send_discord([
                'content' => 'Test notification from Peanut Suite',
                'embeds' => [[
                    'title' => 'Test Successful',
                    'description' => 'Your Discord integration is working correctly!',
                    'color' => 2278869, // Green
                    'footer' => ['text' => 'Peanut Suite'],
                ]],
            ]),
            'telegram' => $this->send_telegram('*Test Notification*\n\nYour Telegram integration is working correctly!'),
            default => false,
        };

        if ($result) {
            return new \WP_REST_Response(['message' => 'Test notification sent'], 200);
        }

        return new \WP_REST_Response(['error' => 'Failed to send notification'], 400);
    }

    /**
     * Send notification for an event
     */
    public function notify(string $event, array $data): void {
        $settings = $this->get_notification_settings();

        // Slack
        if ($settings['slack']['enabled'] && in_array($event, $settings['slack']['events'])) {
            $this->send_slack($this->format_slack_message($event, $data));
        }

        // Discord
        if ($settings['discord']['enabled'] && in_array($event, $settings['discord']['events'])) {
            $this->send_discord($this->format_discord_message($event, $data));
        }

        // Telegram
        if ($settings['telegram']['enabled'] && in_array($event, $settings['telegram']['events'])) {
            $this->send_telegram($this->format_telegram_message($event, $data));
        }

        // Email
        if ($settings['email']['enabled'] && in_array($event, $settings['email']['events'])) {
            $this->send_email_notification($event, $data, $settings['email']['recipients']);
        }
    }

    /**
     * Send Slack notification
     */
    private function send_slack(array $message): bool {
        $settings = $this->get_notification_settings();
        $webhook_url = $settings['slack']['webhook_url'];

        if (empty($webhook_url)) {
            return false;
        }

        if (!empty($settings['slack']['channel'])) {
            $message['channel'] = $settings['slack']['channel'];
        }

        $response = wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($message),
            'timeout' => 15,
        ]);

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Send Discord notification
     */
    private function send_discord(array $message): bool {
        $settings = $this->get_notification_settings();
        $webhook_url = $settings['discord']['webhook_url'];

        if (empty($webhook_url)) {
            return false;
        }

        $response = wp_remote_post($webhook_url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($message),
            'timeout' => 15,
        ]);

        return !is_wp_error($response) && in_array(wp_remote_retrieve_response_code($response), [200, 204]);
    }

    /**
     * Send Telegram notification
     */
    private function send_telegram(string $message): bool {
        $settings = $this->get_notification_settings();
        $bot_token = $settings['telegram']['bot_token'];
        $chat_id = $settings['telegram']['chat_id'];

        if (empty($bot_token) || empty($chat_id)) {
            return false;
        }

        $url = "https://api.telegram.org/bot{$bot_token}/sendMessage";

        $response = wp_remote_post($url, [
            'body' => [
                'chat_id' => $chat_id,
                'text' => $message,
                'parse_mode' => 'Markdown',
            ],
            'timeout' => 15,
        ]);

        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    /**
     * Send email notification
     */
    private function send_email_notification(string $event, array $data, string $recipients): void {
        $recipients = array_filter(array_map('trim', explode("\n", $recipients)));

        if (empty($recipients)) {
            return;
        }

        $subject = $this->get_email_subject($event, $data);
        $body = $this->get_email_body($event, $data);

        foreach ($recipients as $email) {
            if (is_email($email)) {
                wp_mail($email, $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
            }
        }
    }

    /**
     * Format Slack message
     */
    private function format_slack_message(string $event, array $data): array {
        $site_name = get_bloginfo('name');

        return match($event) {
            'contact_created' => [
                'text' => "New contact on {$site_name}",
                'attachments' => [[
                    'color' => '#3b82f6',
                    'title' => 'New Contact Created',
                    'fields' => [
                        ['title' => 'Email', 'value' => $data['email'] ?? 'N/A', 'short' => true],
                        ['title' => 'Source', 'value' => $data['source'] ?? 'Direct', 'short' => true],
                    ],
                    'footer' => $site_name,
                    'ts' => time(),
                ]],
            ],
            'order_completed' => [
                'text' => "New order on {$site_name}",
                'attachments' => [[
                    'color' => '#22c55e',
                    'title' => 'Order Completed',
                    'fields' => [
                        ['title' => 'Order', 'value' => '#' . ($data['order_id'] ?? ''), 'short' => true],
                        ['title' => 'Total', 'value' => $data['total'] ?? '$0', 'short' => true],
                        ['title' => 'Customer', 'value' => $data['customer'] ?? 'N/A', 'short' => true],
                        ['title' => 'Source', 'value' => $data['utm_source'] ?? 'Direct', 'short' => true],
                    ],
                    'footer' => $site_name,
                    'ts' => time(),
                ]],
            ],
            'popup_conversion' => [
                'text' => "Popup conversion on {$site_name}",
                'attachments' => [[
                    'color' => '#8b5cf6',
                    'title' => 'Popup Conversion',
                    'fields' => [
                        ['title' => 'Popup', 'value' => $data['popup_name'] ?? 'N/A', 'short' => true],
                        ['title' => 'Email', 'value' => $data['email'] ?? 'N/A', 'short' => true],
                    ],
                    'footer' => $site_name,
                    'ts' => time(),
                ]],
            ],
            default => [
                'text' => "Event: {$event} on {$site_name}",
                'attachments' => [[
                    'color' => '#6b7280',
                    'text' => json_encode($data),
                    'footer' => $site_name,
                    'ts' => time(),
                ]],
            ],
        };
    }

    /**
     * Format Discord message
     */
    private function format_discord_message(string $event, array $data): array {
        $site_name = get_bloginfo('name');

        $colors = [
            'contact_created' => 3447003, // Blue
            'order_completed' => 2278869, // Green
            'popup_conversion' => 9109831, // Purple
        ];

        return [
            'embeds' => [[
                'title' => $this->get_event_title($event),
                'color' => $colors[$event] ?? 7506394,
                'fields' => $this->get_embed_fields($event, $data),
                'footer' => ['text' => $site_name],
                'timestamp' => date('c'),
            ]],
        ];
    }

    /**
     * Format Telegram message
     */
    private function format_telegram_message(string $event, array $data): string {
        $site_name = get_bloginfo('name');
        $title = $this->get_event_title($event);

        $lines = ["*{$title}*", ""];

        foreach ($data as $key => $value) {
            if (!empty($value)) {
                $label = ucwords(str_replace('_', ' ', $key));
                $lines[] = "*{$label}:* {$value}";
            }
        }

        $lines[] = "";
        $lines[] = "_{$site_name}_";

        return implode("\n", $lines);
    }

    /**
     * Get event title
     */
    private function get_event_title(string $event): string {
        return match($event) {
            'contact_created' => 'New Contact Created',
            'order_completed' => 'Order Completed',
            'popup_conversion' => 'Popup Conversion',
            'link_clicked' => 'Link Clicked',
            'webhook_received' => 'Webhook Received',
            default => ucwords(str_replace('_', ' ', $event)),
        };
    }

    /**
     * Get embed fields for Discord
     */
    private function get_embed_fields(string $event, array $data): array {
        $fields = [];

        foreach ($data as $key => $value) {
            if (!empty($value)) {
                $fields[] = [
                    'name' => ucwords(str_replace('_', ' ', $key)),
                    'value' => (string) $value,
                    'inline' => true,
                ];
            }
        }

        return $fields;
    }

    /**
     * Get email subject
     */
    private function get_email_subject(string $event, array $data): string {
        $site_name = get_bloginfo('name');

        return match($event) {
            'contact_created' => "[{$site_name}] New Contact: " . ($data['email'] ?? ''),
            'order_completed' => "[{$site_name}] Order Completed: #" . ($data['order_id'] ?? ''),
            'popup_conversion' => "[{$site_name}] Popup Conversion",
            default => "[{$site_name}] " . $this->get_event_title($event),
        };
    }

    /**
     * Get email body
     */
    private function get_email_body(string $event, array $data): string {
        $title = $this->get_event_title($event);

        $html = "<h2>{$title}</h2><table style='border-collapse: collapse;'>";

        foreach ($data as $key => $value) {
            if (!empty($value)) {
                $label = ucwords(str_replace('_', ' ', $key));
                $html .= "<tr><td style='padding: 8px; border: 1px solid #ddd;'><strong>{$label}</strong></td>";
                $html .= "<td style='padding: 8px; border: 1px solid #ddd;'>" . esc_html($value) . "</td></tr>";
            }
        }

        $html .= "</table>";

        return $html;
    }

    // Event handlers

    public function notify_contact_created(int $contact_id): void {
        global $wpdb;
        $contact = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}peanut_contacts WHERE id = %d",
            $contact_id
        ));

        if ($contact) {
            $this->notify('contact_created', [
                'email' => $contact->email,
                'name' => trim($contact->first_name . ' ' . $contact->last_name),
                'source' => $contact->utm_source ?: 'Direct',
            ]);
        }
    }

    public function notify_link_clicked(int $link_id, array $click_data): void {
        global $wpdb;
        $link = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}peanut_links WHERE id = %d",
            $link_id
        ));

        if ($link) {
            $this->notify('link_clicked', [
                'link' => $link->title ?: $link->slug,
                'destination' => $link->destination_url,
                'clicks' => $link->click_count,
            ]);
        }
    }

    public function notify_popup_conversion(int $popup_id, array $data): void {
        global $wpdb;
        $popup = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}peanut_popups WHERE id = %d",
            $popup_id
        ));

        $this->notify('popup_conversion', [
            'popup_name' => $popup->name ?? 'Unknown',
            'email' => $data['email'] ?? '',
            'page' => $data['page_url'] ?? '',
        ]);
    }

    public function notify_webhook_received(string $source, array $data): void {
        $this->notify('webhook_received', [
            'source' => $source,
            'event' => $data['event'] ?? 'Unknown',
        ]);
    }

    public function notify_woo_order(int $order_id): void {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $this->notify('order_completed', [
            'order_id' => $order_id,
            'total' => $order->get_formatted_order_total(),
            'customer' => $order->get_billing_email(),
            'utm_source' => $order->get_meta('_peanut_utm_source') ?: 'Direct',
            'utm_campaign' => $order->get_meta('_peanut_utm_campaign') ?: '',
        ]);
    }
}
