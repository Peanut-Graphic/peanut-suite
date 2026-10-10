<?php
/**
 * Webhooks Log Page
 *
 * Monitor incoming webhook events from form services and integrations.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load the list table class
require_once PEANUT_PLUGIN_DIR . 'core/admin/tables/class-webhooks-list-table.php';

// Initialize and prepare list table
$list_table = new Peanut_Webhooks_List_Table();
$list_table->prepare_items();

// Get stats
global $wpdb;
$table_name = $wpdb->prefix . 'peanut_webhooks';
$stats = [
    'total' => 0,
    'processed' => 0,
    'pending' => 0,
    'failed' => 0,
];

if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name) {
    $stats['total'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $stats['processed'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'processed'");
    $stats['pending'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'pending'");
    $stats['failed'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'failed'");
}

// Webhook URL: one per source (POST /webhooks/receive/{source}). The source
// is bound by the URL; the request cannot choose it.
$webhook_url = trailingslashit(rest_url(PEANUT_API_NAMESPACE . '/webhooks/receive'));

// Signing status (never includes a secret): which sources post unsigned.
$unsigned_sources = [];
if (class_exists('Webhooks_Signature') && class_exists('Webhooks_Database')) {
    $seen_sources = Webhooks_Database::get_sources();
    foreach (Webhooks_Signature::sources_status(is_array($seen_sources) ? $seen_sources : []) as $signing_row) {
        if (($signing_row['seen'] || !empty($signing_row['rejected_unsigned'])) && !$signing_row['signed'] && empty($signing_row['allows_unsigned'])) {
            $unsigned_sources[] = $signing_row['source'];
        }
    }
}
?>

<div class="peanut-webhooks-page">

    <!-- Stats Cards -->
    <div class="peanut-stats-row">
        <div class="peanut-stat-card">
            <div class="peanut-stat-icon">
                <span class="dashicons dashicons-rest-api"></span>
            </div>
            <div class="peanut-stat-content">
                <span class="peanut-stat-value"><?php echo number_format_i18n($stats['total']); ?></span>
                <span class="peanut-stat-label"><?php esc_html_e('Total Webhooks', 'peanut-suite'); ?></span>
            </div>
        </div>

        <div class="peanut-stat-card">
            <div class="peanut-stat-icon" style="background: rgba(70, 180, 80, 0.1);">
                <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
            </div>
            <div class="peanut-stat-content">
                <span class="peanut-stat-value"><?php echo number_format_i18n($stats['processed']); ?></span>
                <span class="peanut-stat-label"><?php esc_html_e('Processed', 'peanut-suite'); ?></span>
            </div>
        </div>

        <div class="peanut-stat-card">
            <div class="peanut-stat-icon" style="background: rgba(255, 186, 0, 0.1);">
                <span class="dashicons dashicons-clock" style="color: #ffba00;"></span>
            </div>
            <div class="peanut-stat-content">
                <span class="peanut-stat-value"><?php echo number_format_i18n($stats['pending']); ?></span>
                <span class="peanut-stat-label"><?php esc_html_e('Pending', 'peanut-suite'); ?></span>
            </div>
        </div>

        <div class="peanut-stat-card">
            <div class="peanut-stat-icon" style="background: rgba(220, 50, 50, 0.1);">
                <span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
            </div>
            <div class="peanut-stat-content">
                <span class="peanut-stat-value"><?php echo number_format_i18n($stats['failed']); ?></span>
                <span class="peanut-stat-label"><?php esc_html_e('Failed', 'peanut-suite'); ?></span>
            </div>
        </div>
    </div>

    <!-- Webhook URL Card -->
    <div class="peanut-webhook-url-card">
        <div class="peanut-webhook-url-header">
            <h3>
                <span class="dashicons dashicons-admin-links"></span>
                <?php esc_html_e('Your Webhook URL', 'peanut-suite'); ?>
            </h3>
            <span class="peanut-badge peanut-badge-success"><?php esc_html_e('Ready', 'peanut-suite'); ?></span>
        </div>
        <div class="peanut-webhook-url-body">
            <p><?php esc_html_e('Use this URL in your form services and integrations to send data to Peanut Suite. Add the sender\'s source name to the end, for example formflow-lite:', 'peanut-suite'); ?></p>
            <div class="peanut-url-copy-box">
                <code id="peanut-webhook-url"><?php echo esc_html($webhook_url); ?>{source}</code>
                <button type="button" class="button peanut-copy-btn" data-copy="<?php echo esc_attr($webhook_url); ?>">
                    <span class="dashicons dashicons-clipboard"></span>
                    <?php esc_html_e('Copy', 'peanut-suite'); ?>
                </button>
            </div>
            <?php if (!empty($unsigned_sources)): ?>
                <div class="notice notice-warning inline" role="status">
                    <p>
                        <?php
                        printf(
                            /* translators: %s: comma-separated webhook source names */
                            esc_html__('Sources without a signing secret: %s. Their webhooks are refused until you set a signing secret for each one on the Webhooks page of the Peanut Suite app and add it to the sender.', 'peanut-suite'),
                            '<strong>' . esc_html(implode(', ', $unsigned_sources)) . '</strong>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Info Banners -->
    <div class="peanut-info-grid">
        <div class="peanut-info-card">
            <span class="dashicons dashicons-editor-help"></span>
            <h4><?php esc_html_e('What are Webhooks?', 'peanut-suite'); ?></h4>
            <p><?php esc_html_e('Webhooks are automated messages sent from apps when something happens. When someone fills out your form, the form service sends the data here automatically.', 'peanut-suite'); ?></p>
        </div>
        <div class="peanut-info-card">
            <span class="dashicons dashicons-admin-settings"></span>
            <h4><?php esc_html_e('How to Set Up', 'peanut-suite'); ?></h4>
            <ol>
                <li><?php esc_html_e('Copy the webhook URL above', 'peanut-suite'); ?></li>
                <li><?php esc_html_e('Go to your form builder settings', 'peanut-suite'); ?></li>
                <li><?php esc_html_e('Find "Webhooks" or "Integrations"', 'peanut-suite'); ?></li>
                <li><?php esc_html_e('Paste the URL and save', 'peanut-suite'); ?></li>
            </ol>
        </div>
        <div class="peanut-info-card">
            <span class="dashicons dashicons-yes-alt"></span>
            <h4><?php esc_html_e('Supported Services', 'peanut-suite'); ?></h4>
            <ul class="peanut-services-list">
                <li><span class="dashicons dashicons-feedback"></span> FormFlow</li>
                <li><span class="dashicons dashicons-forms"></span> Gravity Forms</li>
                <li><span class="dashicons dashicons-feedback"></span> WPForms</li>
                <li><span class="dashicons dashicons-email-alt"></span> Contact Form 7</li>
                <li><span class="dashicons dashicons-randomize"></span> Zapier / Make</li>
            </ul>
        </div>
    </div>

    <!-- Webhooks Table -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3><?php esc_html_e('Incoming Webhooks', 'peanut-suite'); ?></h3>
            <button type="button" class="button" id="peanut-test-webhook">
                <span class="dashicons dashicons-superhero"></span>
                <?php esc_html_e('Send Test', 'peanut-suite'); ?>
            </button>
        </div>
        <form method="get">
            <input type="hidden" name="page" value="peanut-webhooks" />
            <?php
            $list_table->search_box(__('Search Webhooks', 'peanut-suite'), 'webhook');
            $list_table->display();
            ?>
        </form>
    </div>

    <!-- Troubleshooting -->
    <div class="peanut-card peanut-troubleshooting">
        <h3><?php esc_html_e('Troubleshooting', 'peanut-suite'); ?></h3>
        <div class="peanut-troubleshooting-grid">
            <div class="peanut-trouble-item">
                <h4><?php esc_html_e('Webhooks not arriving?', 'peanut-suite'); ?></h4>
                <ul>
                    <li><?php esc_html_e('Check that your form service has the correct webhook URL', 'peanut-suite'); ?></li>
                    <li><?php esc_html_e('Verify your site is publicly accessible (not localhost)', 'peanut-suite'); ?></li>
                    <li><?php esc_html_e('Check if your hosting firewall is blocking requests', 'peanut-suite'); ?></li>
                    <li><?php esc_html_e('Use the "Send Test" button to verify the endpoint works', 'peanut-suite'); ?></li>
                </ul>
            </div>
            <div class="peanut-trouble-item">
                <h4><?php esc_html_e('Webhooks showing as "Failed"?', 'peanut-suite'); ?></h4>
                <ul>
                    <li><?php esc_html_e('Click the view icon to inspect the payload', 'peanut-suite'); ?></li>
                    <li><?php esc_html_e('Hover over the status to see the error message', 'peanut-suite'); ?></li>
                    <li><?php esc_html_e('Make sure the payload includes an "email" field', 'peanut-suite'); ?></li>
                    <li><?php esc_html_e('Try reprocessing after fixing the issue', 'peanut-suite'); ?></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Side-by-side panels for Payload and Test -->
<div id="peanut-webhook-panels" class="peanut-panels-row" style="display: none;">
    <!-- Payload Panel -->
    <div id="peanut-payload-panel" class="peanut-panel">
        <div class="peanut-panel-header">
            <h3><?php esc_html_e('Webhook Payload', 'peanut-suite'); ?></h3>
            <button type="button" class="button button-small peanut-close-panel" data-panel="payload">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="peanut-panel-body">
            <div class="peanut-payload-meta">
                <div class="peanut-meta-item">
                    <span class="peanut-meta-label"><?php esc_html_e('Source:', 'peanut-suite'); ?></span>
                    <span class="peanut-meta-value" id="payload-source">-</span>
                </div>
                <div class="peanut-meta-item">
                    <span class="peanut-meta-label"><?php esc_html_e('Event:', 'peanut-suite'); ?></span>
                    <span class="peanut-meta-value" id="payload-event">-</span>
                </div>
                <div class="peanut-meta-item">
                    <span class="peanut-meta-label"><?php esc_html_e('Status:', 'peanut-suite'); ?></span>
                    <span class="peanut-meta-value" id="payload-status">-</span>
                </div>
                <div class="peanut-meta-item">
                    <span class="peanut-meta-label"><?php esc_html_e('Received:', 'peanut-suite'); ?></span>
                    <span class="peanut-meta-value" id="payload-received">-</span>
                </div>
            </div>
            <div class="peanut-payload-content">
                <h4><?php esc_html_e('Raw Payload', 'peanut-suite'); ?></h4>
                <pre id="payload-json"><code></code></pre>
            </div>
        </div>
        <div class="peanut-panel-footer">
            <button type="button" class="button" id="copy-payload">
                <span class="dashicons dashicons-clipboard"></span>
                <?php esc_html_e('Copy Payload', 'peanut-suite'); ?>
            </button>
            <button type="button" class="button button-primary" id="reprocess-payload" style="display: none;">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e('Reprocess', 'peanut-suite'); ?>
            </button>
        </div>
    </div>

    <!-- Test Webhook Panel -->
    <div id="peanut-test-panel" class="peanut-panel">
        <div class="peanut-panel-header">
            <h3><?php esc_html_e('Send Test Webhook', 'peanut-suite'); ?></h3>
            <button type="button" class="button button-small peanut-close-panel" data-panel="test">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="peanut-panel-body">
            <p style="margin-top: 0;"><?php esc_html_e('Send a test webhook to verify your endpoint is working.', 'peanut-suite'); ?></p>

            <div class="peanut-form-group">
                <label for="test-email"><?php esc_html_e('Test Email', 'peanut-suite'); ?></label>
                <input type="email" id="test-email" value="test@example.com" class="regular-text" style="width: 100%;">
                <?php echo peanut_field_help(__('This email will be used in the test payload.', 'peanut-suite')); ?>
            </div>

            <div class="peanut-test-payload-preview">
                <h4><?php esc_html_e('Test Payload:', 'peanut-suite'); ?></h4>
                <pre id="test-payload-preview">{
    "source": "test",
    "event_type": "form_submission",
    "email": "test@example.com",
    "first_name": "Test",
    "last_name": "User",
    "timestamp": "<?php echo current_time('c'); ?>"
}</pre>
            </div>

            <div id="test-result" style="display: none;">
                <h4><?php esc_html_e('Result:', 'peanut-suite'); ?></h4>
                <div class="peanut-test-result-content"></div>
            </div>
        </div>
        <div class="peanut-panel-footer">
            <button type="button" class="button button-primary" id="send-test-webhook">
                <span class="dashicons dashicons-superhero"></span>
                <?php esc_html_e('Send Test', 'peanut-suite'); ?>
            </button>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var currentWebhookId = null;
    var currentPayload = null;

    // Show/hide panels container
    function showPanels() {
        $('#peanut-webhook-panels').slideDown(200);
    }

    function hidePanels() {
        $('#peanut-webhook-panels').slideUp(200);
    }

    function showPayloadPanel() {
        $('#peanut-payload-panel').show();
        showPanels();
    }

    function showTestPanel() {
        $('#peanut-test-panel').show();
        showPanels();
    }

    // Close panel buttons
    $('.peanut-close-panel').on('click', function() {
        var panel = $(this).data('panel');
        if (panel === 'payload') {
            $('#peanut-payload-panel').hide();
        } else if (panel === 'test') {
            $('#peanut-test-panel').hide();
        }
        // Hide container if both panels are hidden
        if (!$('#peanut-payload-panel').is(':visible') && !$('#peanut-test-panel').is(':visible')) {
            hidePanels();
        }
    });

    // Copy webhook URL
    $('.peanut-copy-btn').on('click', function() {
        var url = $(this).data('copy');
        PeanutAdmin.copyToClipboard(url);
    });

    // View payload - show in panel
    $(document).on('click', '.peanut-view-payload', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        currentWebhookId = id;

        $.ajax({
            url: peanutAdmin.apiUrl + '/webhooks/' + id,
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', peanutAdmin.nonce);
            },
            success: function(webhook) {
                currentPayload = webhook.payload;

                $('#payload-source').text(webhook.source || '-');
                $('#payload-event').text(webhook.event_type || '-');
                $('#payload-status').html(getStatusBadge(webhook.status));
                $('#payload-received').text(webhook.received_at || webhook.created_at || '-');

                // Format JSON
                try {
                    var parsed = typeof webhook.payload === 'string' ? JSON.parse(webhook.payload) : webhook.payload;
                    $('#payload-json code').text(JSON.stringify(parsed, null, 2));
                } catch (e) {
                    $('#payload-json code').text(webhook.payload || '{}');
                }

                // Show reprocess button if needed
                if (webhook.status === 'failed' || webhook.status === 'pending') {
                    $('#reprocess-payload').show();
                } else {
                    $('#reprocess-payload').hide();
                }

                showPayloadPanel();
                // Scroll to panels
                $('html, body').animate({
                    scrollTop: $('#peanut-webhook-panels').offset().top - 50
                }, 300);
            },
            error: function() {
                PeanutAdmin.notify('<?php esc_html_e('Failed to load webhook', 'peanut-suite'); ?>', 'error');
            }
        });
    });

    function getStatusBadge(status) {
        var classes = {
            'processed': 'peanut-badge-success',
            'pending': 'peanut-badge-warning',
            'failed': 'peanut-badge-error',
            'ignored': 'peanut-badge-neutral'
        };
        var labels = {
            'processed': '<?php esc_html_e('Processed', 'peanut-suite'); ?>',
            'pending': '<?php esc_html_e('Pending', 'peanut-suite'); ?>',
            'failed': '<?php esc_html_e('Failed', 'peanut-suite'); ?>',
            'ignored': '<?php esc_html_e('Ignored', 'peanut-suite'); ?>'
        };
        return '<span class="peanut-badge ' + (classes[status] || 'peanut-badge-neutral') + '">' + (labels[status] || status) + '</span>';
    }

    // Copy payload
    $('#copy-payload').on('click', function() {
        var payload = $('#payload-json code').text();
        PeanutAdmin.copyToClipboard(payload);
    });

    // Reprocess from panel
    $('#reprocess-payload').on('click', function() {
        if (!currentWebhookId) return;
        reprocessWebhook(currentWebhookId, function() {
            location.reload();
        });
    });

    // Reprocess from table
    $(document).on('click', '.peanut-reprocess-webhook', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        reprocessWebhook(id);
    });

    function reprocessWebhook(id, callback) {
        $.ajax({
            url: peanutAdmin.apiUrl + '/webhooks/' + id + '/reprocess',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', peanutAdmin.nonce);
            },
            success: function() {
                PeanutAdmin.notify('<?php esc_html_e('Webhook reprocessed', 'peanut-suite'); ?>', 'success');
                if (callback) callback();
                else location.reload();
            },
            error: function(xhr) {
                var message = xhr.responseJSON?.message || '<?php esc_html_e('Failed to reprocess webhook', 'peanut-suite'); ?>';
                PeanutAdmin.notify(message, 'error');
            }
        });
    }

    // Delete webhook
    $(document).on('click', '.peanut-delete-webhook', function(e) {
        e.preventDefault();

        var $link = $(this);
        var id = $link.data('id');
        var confirmMsg = $link.data('confirm');

        if (!confirm(confirmMsg)) return;

        $.ajax({
            url: peanutAdmin.apiUrl + '/webhooks/' + id,
            method: 'DELETE',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', peanutAdmin.nonce);
            },
            success: function() {
                PeanutAdmin.notify('<?php esc_html_e('Webhook deleted', 'peanut-suite'); ?>', 'success');
                $link.closest('tr').fadeOut(function() {
                    $(this).remove();
                });
            },
            error: function() {
                PeanutAdmin.notify('<?php esc_html_e('Failed to delete webhook', 'peanut-suite'); ?>', 'error');
            }
        });
    });

    // Open test webhook panel
    $('#peanut-test-webhook, #peanut-show-webhook-url').on('click', function() {
        $('#test-result').hide();
        showTestPanel();
        // Scroll to panels
        $('html, body').animate({
            scrollTop: $('#peanut-webhook-panels').offset().top - 50
        }, 300);
    });

    // Update test payload preview
    $('#test-email').on('input', function() {
        var email = $(this).val();
        var preview = {
            source: 'test',
            event_type: 'form_submission',
            email: email,
            first_name: 'Test',
            last_name: 'User',
            timestamp: new Date().toISOString()
        };
        $('#test-payload-preview').text(JSON.stringify(preview, null, 4));
    });

    // Send test webhook
    $('#send-test-webhook').on('click', function() {
        var $btn = $(this);
        var email = $('#test-email').val();

        $btn.prop('disabled', true).addClass('updating-message');

        var payload = {
            source: 'test',
            event_type: 'form_submission',
            email: email,
            first_name: 'Test',
            last_name: 'User'
        };

        $.ajax({
            url: '<?php echo esc_url($webhook_url . 'test'); ?>',
            method: 'POST',
            contentType: 'application/json',
            // Unsigned webhooks are refused; the test is accepted because it
            // comes from a signed-in administrator (REST cookie auth + nonce).
            headers: { 'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>' },
            data: JSON.stringify(payload),
            success: function(response) {
                $('#test-result .peanut-test-result-content').html(
                    '<div class="peanut-notice peanut-notice-success">' +
                    '<strong><?php esc_html_e('Success!', 'peanut-suite'); ?></strong> ' +
                    '<?php esc_html_e('The webhook was received and processed. Refresh the page to see it in the list.', 'peanut-suite'); ?>' +
                    '</div>'
                );
                $('#test-result').show();
            },
            error: function(xhr) {
                var message = xhr.responseJSON?.message || '<?php esc_html_e('Unknown error', 'peanut-suite'); ?>';
                $('#test-result .peanut-test-result-content').html(
                    '<div class="peanut-notice peanut-notice-error">' +
                    '<strong><?php esc_html_e('Failed:', 'peanut-suite'); ?></strong> ' + message +
                    '</div>'
                );
                $('#test-result').show();
            },
            complete: function() {
                $btn.prop('disabled', false).removeClass('updating-message');
            }
        });
    });
});
</script>

