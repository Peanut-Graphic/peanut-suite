<?php
/**
 * Email Sequences Module
 *
 * Automated drip campaigns and email sequences.
 */

namespace PeanutSuite\Sequences;

if (!defined('ABSPATH')) {
    exit;
}

class Sequences_Module {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->init();
    }

    private function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);

        // Schedule sequence processing
        if (!wp_next_scheduled('peanut_process_sequences')) {
            wp_schedule_event(time(), 'hourly', 'peanut_process_sequences');
        }
        add_action('peanut_process_sequences', [$this, 'process_sequences']);

        // Trigger sequences on contact events
        add_action('peanut_contact_created', [$this, 'trigger_on_contact_created']);
        add_action('peanut_contact_tagged', [$this, 'trigger_on_contact_tagged'], 10, 2);

        // AJAX handlers
        add_action('wp_ajax_peanut_save_sequence', [$this, 'ajax_save_sequence']);
    }

    /**
     * AJAX: Save sequence
     */
    public function ajax_save_sequence(): void {
        check_ajax_referer('peanut_sequences', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'peanut_sequences';

        $data = [
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'trigger_type' => sanitize_text_field($_POST['trigger_type'] ?? 'manual'),
            'trigger_value' => sanitize_text_field($_POST['trigger_value'] ?? ''),
            'from_email' => sanitize_email($_POST['from_email'] ?? ''),
            'from_name' => sanitize_text_field($_POST['from_name'] ?? ''),
            'status' => sanitize_text_field($_POST['status'] ?? 'draft'),
        ];

        $id = isset($_POST['id']) && $_POST['id'] !== '' ? intval($_POST['id']) : 0;

        if ($id > 0) {
            $wpdb->update($table, $data, ['id' => $id]);
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            $id = $wpdb->insert_id;
        }

        wp_send_json_success(['id' => $id]);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route(PEANUT_API_NAMESPACE, '/sequences', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_sequences'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'create_sequence'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/sequences/(?P<id>\d+)', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_sequence'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_sequence'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_sequence'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/sequences/(?P<id>\d+)/emails', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_sequence_emails'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'add_sequence_email'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/sequences/emails/(?P<id>\d+)', [
            [
                'methods' => \WP_REST_Server::EDITABLE,
                'callback' => [$this, 'update_sequence_email'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => [$this, 'delete_sequence_email'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/sequences/(?P<id>\d+)/subscribers', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_subscribers'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/sequences/(?P<id>\d+)/enroll', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'enroll_contact'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);
    }

    public function admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get all sequences
     */
    public function get_sequences(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_sequences';
        $subs_table = $wpdb->prefix . 'peanut_sequence_subscribers';

        $sequences = $wpdb->get_results("
            SELECT s.*,
                   (SELECT COUNT(*) FROM $subs_table WHERE sequence_id = s.id AND status = 'active') as active_subscribers,
                   (SELECT COUNT(*) FROM $subs_table WHERE sequence_id = s.id AND status = 'completed') as completed_subscribers
            FROM $table s
            ORDER BY s.created_at DESC
        ", ARRAY_A);

        return new \WP_REST_Response(['sequences' => $sequences], 200);
    }

    /**
     * Get single sequence
     */
    public function get_sequence(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'peanut_sequences';

        $sequence = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $id
        ), ARRAY_A);

        if (!$sequence) {
            return new \WP_REST_Response(['error' => 'Sequence not found'], 404);
        }

        // Get emails
        $emails = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}peanut_sequence_emails WHERE sequence_id = %d ORDER BY delay_days, delay_hours",
            $id
        ), ARRAY_A);

        $sequence['emails'] = $emails;

        // Get stats
        $subs_table = $wpdb->prefix . 'peanut_sequence_subscribers';
        $sequence['stats'] = [
            'active' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $subs_table WHERE sequence_id = %d AND status = 'active'", $id
            )),
            'completed' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $subs_table WHERE sequence_id = %d AND status = 'completed'", $id
            )),
            'paused' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $subs_table WHERE sequence_id = %d AND status = 'paused'", $id
            )),
        ];

        return new \WP_REST_Response($sequence, 200);
    }

    /**
     * Create sequence
     */
    public function create_sequence(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_sequences';

        $wpdb->insert($table, [
            'name' => sanitize_text_field($request->get_param('name')),
            'description' => sanitize_textarea_field($request->get_param('description') ?: ''),
            'trigger_type' => sanitize_text_field($request->get_param('trigger_type') ?: 'manual'),
            'trigger_value' => sanitize_text_field($request->get_param('trigger_value') ?: ''),
            'status' => 'draft',
            'created_at' => current_time('mysql'),
        ]);

        return new \WP_REST_Response(['id' => $wpdb->insert_id], 201);
    }

    /**
     * Update sequence
     */
    public function update_sequence(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'peanut_sequences';

        $data = [];
        if ($request->has_param('name')) {
            $data['name'] = sanitize_text_field($request->get_param('name'));
        }
        if ($request->has_param('description')) {
            $data['description'] = sanitize_textarea_field($request->get_param('description'));
        }
        if ($request->has_param('status')) {
            $data['status'] = sanitize_text_field($request->get_param('status'));
        }
        if ($request->has_param('trigger_type')) {
            $data['trigger_type'] = sanitize_text_field($request->get_param('trigger_type'));
        }
        if ($request->has_param('trigger_value')) {
            $data['trigger_value'] = sanitize_text_field($request->get_param('trigger_value'));
        }

        if (!empty($data)) {
            $wpdb->update($table, $data, ['id' => $id]);
        }

        return new \WP_REST_Response(['message' => 'Updated'], 200);
    }

    /**
     * Delete sequence
     */
    public function delete_sequence(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');

        $wpdb->delete($wpdb->prefix . 'peanut_sequences', ['id' => $id]);
        $wpdb->delete($wpdb->prefix . 'peanut_sequence_emails', ['sequence_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'peanut_sequence_subscribers', ['sequence_id' => $id]);

        return new \WP_REST_Response(['message' => 'Deleted'], 200);
    }

    /**
     * Get sequence emails
     */
    public function get_sequence_emails(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');

        $emails = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}peanut_sequence_emails WHERE sequence_id = %d ORDER BY delay_days, delay_hours",
            $id
        ), ARRAY_A);

        return new \WP_REST_Response(['emails' => $emails], 200);
    }

    /**
     * Add email to sequence
     */
    public function add_sequence_email(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $sequence_id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'peanut_sequence_emails';

        $wpdb->insert($table, [
            'sequence_id' => $sequence_id,
            'subject' => sanitize_text_field($request->get_param('subject')),
            'body' => wp_kses_post($request->get_param('body')),
            'delay_days' => (int) $request->get_param('delay_days'),
            'delay_hours' => (int) $request->get_param('delay_hours'),
            'status' => 'active',
            'created_at' => current_time('mysql'),
        ]);

        return new \WP_REST_Response(['id' => $wpdb->insert_id], 201);
    }

    /**
     * Update sequence email
     */
    public function update_sequence_email(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'peanut_sequence_emails';

        $data = [];
        if ($request->has_param('subject')) {
            $data['subject'] = sanitize_text_field($request->get_param('subject'));
        }
        if ($request->has_param('body')) {
            $data['body'] = wp_kses_post($request->get_param('body'));
        }
        if ($request->has_param('delay_days')) {
            $data['delay_days'] = (int) $request->get_param('delay_days');
        }
        if ($request->has_param('delay_hours')) {
            $data['delay_hours'] = (int) $request->get_param('delay_hours');
        }
        if ($request->has_param('status')) {
            $data['status'] = sanitize_text_field($request->get_param('status'));
        }

        if (!empty($data)) {
            $wpdb->update($table, $data, ['id' => $id]);
        }

        return new \WP_REST_Response(['message' => 'Updated'], 200);
    }

    /**
     * Delete sequence email
     */
    public function delete_sequence_email(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');

        $wpdb->delete($wpdb->prefix . 'peanut_sequence_emails', ['id' => $id]);

        return new \WP_REST_Response(['message' => 'Deleted'], 200);
    }

    /**
     * Get subscribers for a sequence
     */
    public function get_subscribers(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');
        $table = $wpdb->prefix . 'peanut_sequence_subscribers';

        $subscribers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE sequence_id = %d ORDER BY enrolled_at DESC",
            $id
        ), ARRAY_A);

        return new \WP_REST_Response(['subscribers' => $subscribers], 200);
    }

    /**
     * Enroll contact in sequence
     */
    public function enroll_contact(\WP_REST_Request $request): \WP_REST_Response {
        $sequence_id = (int) $request->get_param('id');
        $contact_id = (int) $request->get_param('contact_id');
        $email = sanitize_email($request->get_param('email'));

        $result = $this->enroll($sequence_id, $contact_id, $email);

        if ($result) {
            return new \WP_REST_Response(['message' => 'Enrolled'], 200);
        }

        return new \WP_REST_Response(['error' => 'Already enrolled or invalid'], 400);
    }

    /**
     * Enroll a contact in a sequence
     */
    public function enroll(int $sequence_id, int $contact_id = 0, string $email = ''): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_sequence_subscribers';

        // Check if already enrolled
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE sequence_id = %d AND (contact_id = %d OR email = %s)",
            $sequence_id,
            $contact_id,
            $email
        ));

        if ($existing) {
            return false;
        }

        // Get first email in sequence
        $first_email = $wpdb->get_row($wpdb->prepare(
            "SELECT id, delay_days, delay_hours FROM {$wpdb->prefix}peanut_sequence_emails
             WHERE sequence_id = %d AND status = 'active'
             ORDER BY delay_days, delay_hours LIMIT 1",
            $sequence_id
        ));

        $next_email_id = $first_email ? $first_email->id : null;
        $next_send_at = null;

        if ($first_email) {
            $delay_seconds = ($first_email->delay_days * 86400) + ($first_email->delay_hours * 3600);
            $next_send_at = date('Y-m-d H:i:s', time() + $delay_seconds);
        }

        $wpdb->insert($table, [
            'sequence_id' => $sequence_id,
            'contact_id' => $contact_id,
            'email' => $email,
            'current_email_id' => $next_email_id,
            'next_send_at' => $next_send_at,
            'status' => 'active',
            'enrolled_at' => current_time('mysql'),
        ]);

        return true;
    }

    /**
     * Process sequences - send due emails
     */
    public function process_sequences(): void {
        global $wpdb;
        $subs_table = $wpdb->prefix . 'peanut_sequence_subscribers';
        $emails_table = $wpdb->prefix . 'peanut_sequence_emails';
        $sequences_table = $wpdb->prefix . 'peanut_sequences';

        // Get due subscribers
        $due = $wpdb->get_results("
            SELECT sub.*, seq.name as sequence_name
            FROM $subs_table sub
            JOIN $sequences_table seq ON sub.sequence_id = seq.id
            WHERE sub.status = 'active'
            AND sub.next_send_at IS NOT NULL
            AND sub.next_send_at <= NOW()
            AND seq.status = 'active'
            LIMIT 50
        ");

        foreach ($due as $subscriber) {
            // Get the email to send
            $email = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $emails_table WHERE id = %d AND status = 'active'",
                $subscriber->current_email_id
            ));

            if (!$email) {
                // No more emails - mark as completed
                $wpdb->update($subs_table, [
                    'status' => 'completed',
                    'completed_at' => current_time('mysql'),
                ], ['id' => $subscriber->id]);
                continue;
            }

            // Get recipient email
            $recipient = $subscriber->email;
            if (!$recipient && $subscriber->contact_id) {
                $contact = $wpdb->get_row($wpdb->prepare(
                    "SELECT email FROM {$wpdb->prefix}peanut_contacts WHERE id = %d",
                    $subscriber->contact_id
                ));
                $recipient = $contact->email ?? '';
            }

            if (!$recipient) {
                continue;
            }

            // Send the email
            $sent = $this->send_sequence_email($recipient, $email, $subscriber);

            if ($sent) {
                // Update subscriber
                $this->advance_subscriber($subscriber->id, $email->sequence_id, $email->id);
            }
        }
    }

    /**
     * Send a sequence email
     */
    private function send_sequence_email(string $to, object $email, object $subscriber): bool {
        // Get contact data for personalization
        $contact = null;
        if ($subscriber->contact_id) {
            global $wpdb;
            $contact = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}peanut_contacts WHERE id = %d",
                $subscriber->contact_id
            ));
        }

        // Personalize content
        $subject = $this->personalize($email->subject, $contact, $to);
        $body = $this->personalize($email->body, $contact, $to);

        // Add unsubscribe link
        $unsubscribe_url = add_query_arg([
            'peanut_unsubscribe' => 1,
            'sid' => $subscriber->id,
            'token' => wp_hash($subscriber->id . $subscriber->email),
        ], home_url());

        $body .= "\n\n<p style='font-size: 12px; color: #666;'><a href='{$unsubscribe_url}'>Unsubscribe</a></p>";

        // Apply branding
        $branding = apply_filters('peanut_report_branding', []);

        $html = "
        <!DOCTYPE html>
        <html>
        <head><meta charset='utf-8'></head>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                {$body}
            </div>
        </body>
        </html>
        ";

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        return wp_mail($to, $subject, $html, $headers);
    }

    /**
     * Personalize content with contact data
     */
    private function personalize(string $content, ?object $contact, string $email): string {
        $replacements = [
            '{{email}}' => $email,
            '{{first_name}}' => $contact->first_name ?? '',
            '{{last_name}}' => $contact->last_name ?? '',
            '{{name}}' => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')) ?: 'there',
            '{{company}}' => $contact->company ?? '',
            '{{site_name}}' => get_bloginfo('name'),
            '{{site_url}}' => home_url(),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * Advance subscriber to next email
     */
    private function advance_subscriber(int $subscriber_id, int $sequence_id, int $current_email_id): void {
        global $wpdb;
        $subs_table = $wpdb->prefix . 'peanut_sequence_subscribers';
        $emails_table = $wpdb->prefix . 'peanut_sequence_emails';

        // Get current email position
        $current = $wpdb->get_row($wpdb->prepare(
            "SELECT delay_days, delay_hours FROM $emails_table WHERE id = %d",
            $current_email_id
        ));

        // Find next email
        $next = $wpdb->get_row($wpdb->prepare(
            "SELECT id, delay_days, delay_hours FROM $emails_table
             WHERE sequence_id = %d AND status = 'active'
             AND (delay_days > %d OR (delay_days = %d AND delay_hours > %d))
             ORDER BY delay_days, delay_hours LIMIT 1",
            $sequence_id,
            $current->delay_days,
            $current->delay_days,
            $current->delay_hours
        ));

        if ($next) {
            // Calculate next send time based on enrollment
            $enrolled = $wpdb->get_var($wpdb->prepare(
                "SELECT enrolled_at FROM $subs_table WHERE id = %d",
                $subscriber_id
            ));

            $delay_seconds = ($next->delay_days * 86400) + ($next->delay_hours * 3600);
            $next_send_at = date('Y-m-d H:i:s', strtotime($enrolled) + $delay_seconds);

            $wpdb->update($subs_table, [
                'current_email_id' => $next->id,
                'next_send_at' => $next_send_at,
                'emails_sent' => $wpdb->get_var($wpdb->prepare(
                    "SELECT emails_sent FROM $subs_table WHERE id = %d", $subscriber_id
                )) + 1,
            ], ['id' => $subscriber_id]);
        } else {
            // No more emails - mark completed
            $wpdb->update($subs_table, [
                'status' => 'completed',
                'current_email_id' => null,
                'next_send_at' => null,
                'completed_at' => current_time('mysql'),
                'emails_sent' => $wpdb->get_var($wpdb->prepare(
                    "SELECT emails_sent FROM $subs_table WHERE id = %d", $subscriber_id
                )) + 1,
            ], ['id' => $subscriber_id]);
        }
    }

    /**
     * Trigger on contact created
     */
    public function trigger_on_contact_created(int $contact_id): void {
        global $wpdb;

        // Find sequences with 'contact_created' trigger
        $sequences = $wpdb->get_results(
            "SELECT id FROM {$wpdb->prefix}peanut_sequences
             WHERE trigger_type = 'contact_created' AND status = 'active'"
        );

        foreach ($sequences as $seq) {
            $this->enroll($seq->id, $contact_id);
        }
    }

    /**
     * Trigger on contact tagged
     */
    public function trigger_on_contact_tagged(int $contact_id, string $tag): void {
        global $wpdb;

        // Find sequences triggered by this tag
        $sequences = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}peanut_sequences
             WHERE trigger_type = 'tag_added' AND trigger_value = %s AND status = 'active'",
            $tag
        ));

        foreach ($sequences as $seq) {
            $this->enroll($seq->id, $contact_id);
        }
    }

    /**
     * Create database tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sequences_table = $wpdb->prefix . 'peanut_sequences';
        $emails_table = $wpdb->prefix . 'peanut_sequence_emails';
        $subs_table = $wpdb->prefix . 'peanut_sequence_subscribers';

        $sql = "
        CREATE TABLE $sequences_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            description text,
            trigger_type varchar(50) DEFAULT 'manual',
            trigger_value varchar(200) DEFAULT '',
            from_email varchar(255) DEFAULT '',
            from_name varchar(255) DEFAULT '',
            status varchar(20) DEFAULT 'draft',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset;

        CREATE TABLE $emails_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sequence_id bigint(20) UNSIGNED NOT NULL,
            subject varchar(255) NOT NULL,
            body longtext,
            delay_days int(11) DEFAULT 0,
            delay_hours int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sequence_id (sequence_id)
        ) $charset;

        CREATE TABLE $subs_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sequence_id bigint(20) UNSIGNED NOT NULL,
            contact_id bigint(20) UNSIGNED DEFAULT 0,
            email varchar(200) DEFAULT '',
            current_email_id bigint(20) UNSIGNED DEFAULT NULL,
            next_send_at datetime DEFAULT NULL,
            emails_sent int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'active',
            enrolled_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY sequence_id (sequence_id),
            KEY contact_id (contact_id),
            KEY status (status),
            KEY next_send_at (next_send_at)
        ) $charset;
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
