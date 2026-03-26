<?php
/**
 * Email Digest Reports View
 *
 * Configure scheduled email reports with marketing analytics summaries.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get settings
$settings = get_option('peanut_reports_settings', [
    'enabled' => false,
    'frequency' => 'weekly',
    'day_of_week' => 1,
    'day_of_month' => 1,
    'time' => '08:00',
    'recipients' => [get_option('admin_email')],
    'include_sections' => [
        'overview' => true,
        'utm_campaigns' => true,
        'links' => true,
        'contacts' => true,
        'visitors' => true,
        'top_performers' => true,
    ],
    'attach_pdf' => true,
    'custom_logo' => '',
    'custom_footer' => '',
]);

// Get next scheduled time
$next_scheduled = wp_next_scheduled('peanut_send_digest_report');

// Get report log
$report_log = get_option('peanut_reports_log', []);
$report_log = array_reverse($report_log);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['peanut_reports_nonce'])) {
    if ( ! wp_verify_nonce( sanitize_key( $_POST['peanut_reports_nonce'] ), 'peanut_save_reports' ) ) {
        wp_die( __( 'Security check failed.', 'peanut-suite' ), 403 );
    }
    $settings['enabled'] = isset($_POST['enabled']);
    $settings['frequency'] = sanitize_key($_POST['frequency'] ?? 'weekly');
    $settings['day_of_week'] = absint($_POST['day_of_week'] ?? 1) % 7;
    $settings['day_of_month'] = min(28, max(1, absint($_POST['day_of_month'] ?? 1)));
    $settings['time'] = sanitize_text_field($_POST['time'] ?? '08:00');
    $settings['attach_pdf'] = isset($_POST['attach_pdf']);

    // Recipients
    $recipients_raw = sanitize_textarea_field($_POST['recipients'] ?? '');
    $settings['recipients'] = array_filter(array_map('trim', explode("\n", $recipients_raw)));

    // Sections
    $sections = ['overview', 'utm_campaigns', 'links', 'contacts', 'visitors', 'top_performers'];
    foreach ($sections as $section) {
        $settings['include_sections'][$section] = isset($_POST['include_' . $section]);
    }

    // Branding
    $settings['custom_logo'] = esc_url_raw($_POST['custom_logo'] ?? '');
    $settings['custom_footer'] = sanitize_text_field($_POST['custom_footer'] ?? '');

    update_option('peanut_reports_settings', $settings);
    echo '<div class="notice notice-success"><p>' . esc_html__('Report settings saved.', 'peanut-suite') . '</p></div>';

    // Refresh next scheduled
    $next_scheduled = wp_next_scheduled('peanut_send_digest_report');
}

// Days of week
$days_of_week = [
    0 => __('Sunday', 'peanut-suite'),
    1 => __('Monday', 'peanut-suite'),
    2 => __('Tuesday', 'peanut-suite'),
    3 => __('Wednesday', 'peanut-suite'),
    4 => __('Thursday', 'peanut-suite'),
    5 => __('Friday', 'peanut-suite'),
    6 => __('Saturday', 'peanut-suite'),
];
?>

<div class="peanut-page-header">
    <div>
        <h1><?php esc_html_e('Email Digest Reports', 'peanut-suite'); ?></h1>
        <p class="peanut-page-description"><?php esc_html_e('Schedule automated email reports with your marketing analytics delivered to your inbox.', 'peanut-suite'); ?></p>
    </div>
    <div class="peanut-page-actions">
        <button type="button" class="button" id="peanut-preview-report">
            <span class="dashicons dashicons-visibility"></span>
            <?php esc_html_e('Preview Report', 'peanut-suite'); ?>
        </button>
        <button type="button" class="button button-primary" id="peanut-send-now" <?php echo !$settings['enabled'] ? 'disabled' : ''; ?>>
            <span class="dashicons dashicons-email-alt"></span>
            <?php esc_html_e('Send Now', 'peanut-suite'); ?>
        </button>
    </div>
</div>

<!-- Status Banner -->
<?php if ($settings['enabled'] && $next_scheduled): ?>
<div class="peanut-info-banner" style="margin-bottom: 24px;">
    <span class="dashicons dashicons-calendar-alt"></span>
    <div>
        <strong><?php esc_html_e('Next report scheduled:', 'peanut-suite'); ?></strong>
        <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next_scheduled)); ?>
    </div>
</div>
<?php endif; ?>

<div class="peanut-two-column">
    <div class="peanut-main-column">
        <form method="post" class="peanut-form">
            <?php wp_nonce_field('peanut_save_reports', 'peanut_reports_nonce'); ?>

            <!-- Enable Reports -->
            <div class="peanut-card">
                <div class="peanut-card-header">
                    <h3 class="peanut-card-title"><?php esc_html_e('Report Schedule', 'peanut-suite'); ?></h3>
                </div>
                <div class="peanut-card-body">
                    <div class="peanut-toggle-row" style="margin-bottom: 24px;">
                        <label class="peanut-toggle">
                            <input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="toggle-label">
                            <strong><?php esc_html_e('Enable Scheduled Reports', 'peanut-suite'); ?></strong>
                            <p><?php esc_html_e('Automatically send email reports on your chosen schedule.', 'peanut-suite'); ?></p>
                        </div>
                    </div>

                    <div class="peanut-form-row">
                        <label class="peanut-form-label"><?php esc_html_e('Frequency', 'peanut-suite'); ?></label>
                        <div class="peanut-button-group">
                            <label class="peanut-button-option <?php echo $settings['frequency'] === 'daily' ? 'active' : ''; ?>">
                                <input type="radio" name="frequency" value="daily" <?php checked($settings['frequency'], 'daily'); ?>>
                                <?php esc_html_e('Daily', 'peanut-suite'); ?>
                            </label>
                            <label class="peanut-button-option <?php echo $settings['frequency'] === 'weekly' ? 'active' : ''; ?>">
                                <input type="radio" name="frequency" value="weekly" <?php checked($settings['frequency'], 'weekly'); ?>>
                                <?php esc_html_e('Weekly', 'peanut-suite'); ?>
                            </label>
                            <label class="peanut-button-option <?php echo $settings['frequency'] === 'monthly' ? 'active' : ''; ?>">
                                <input type="radio" name="frequency" value="monthly" <?php checked($settings['frequency'], 'monthly'); ?>>
                                <?php esc_html_e('Monthly', 'peanut-suite'); ?>
                            </label>
                        </div>
                    </div>

                    <div class="peanut-form-row peanut-weekly-options" style="<?php echo $settings['frequency'] !== 'weekly' ? 'display: none;' : ''; ?>">
                        <label class="peanut-form-label" for="day_of_week"><?php esc_html_e('Send On', 'peanut-suite'); ?></label>
                        <select id="day_of_week" name="day_of_week" class="peanut-form-select">
                            <?php foreach ($days_of_week as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php selected($settings['day_of_week'], $value); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="peanut-form-row peanut-monthly-options" style="<?php echo $settings['frequency'] !== 'monthly' ? 'display: none;' : ''; ?>">
                        <label class="peanut-form-label" for="day_of_month"><?php esc_html_e('Day of Month', 'peanut-suite'); ?></label>
                        <select id="day_of_month" name="day_of_month" class="peanut-form-select">
                            <?php for ($i = 1; $i <= 28; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php selected($settings['day_of_month'], $i); ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Reports are sent for the previous period (e.g., monthly report on the 1st covers the previous month).', 'peanut-suite'); ?></p>
                    </div>

                    <div class="peanut-form-row">
                        <label class="peanut-form-label" for="time"><?php esc_html_e('Time (24-hour)', 'peanut-suite'); ?></label>
                        <input type="time" id="time" name="time" class="peanut-form-input" style="width: 150px;"
                               value="<?php echo esc_attr($settings['time']); ?>">
                        <p class="description"><?php esc_html_e('Reports will be sent at this time in your site\'s timezone.', 'peanut-suite'); ?></p>
                    </div>
                </div>
            </div>

            <!-- Recipients -->
            <div class="peanut-card" style="margin-top: 24px;">
                <div class="peanut-card-header">
                    <h3 class="peanut-card-title"><?php esc_html_e('Recipients', 'peanut-suite'); ?></h3>
                </div>
                <div class="peanut-card-body">
                    <div class="peanut-form-row">
                        <label class="peanut-form-label" for="recipients"><?php esc_html_e('Email Addresses', 'peanut-suite'); ?></label>
                        <textarea id="recipients" name="recipients" class="peanut-form-textarea" rows="4"
                                  placeholder="<?php esc_attr_e('One email address per line', 'peanut-suite'); ?>"><?php echo esc_textarea(implode("\n", $settings['recipients'] ?? [])); ?></textarea>
                        <?php echo peanut_field_help(__('Enter one email address per line. All recipients will receive the same report.', 'peanut-suite')); ?>
                    </div>
                </div>
            </div>

            <!-- Report Content -->
            <div class="peanut-card" style="margin-top: 24px;">
                <div class="peanut-card-header">
                    <h3 class="peanut-card-title"><?php esc_html_e('Report Sections', 'peanut-suite'); ?></h3>
                </div>
                <div class="peanut-card-body">
                    <p class="description" style="margin-bottom: 16px;"><?php esc_html_e('Choose which sections to include in your email reports.', 'peanut-suite'); ?></p>

                    <div class="peanut-checkbox-grid">
                        <label class="peanut-checkbox-card">
                            <input type="checkbox" name="include_overview" value="1" <?php checked($settings['include_sections']['overview'] ?? true); ?>>
                            <span class="dashicons dashicons-chart-bar"></span>
                            <span class="checkbox-label"><?php esc_html_e('Overview Stats', 'peanut-suite'); ?></span>
                            <span class="checkbox-description"><?php esc_html_e('Key metrics with period comparison', 'peanut-suite'); ?></span>
                        </label>

                        <label class="peanut-checkbox-card">
                            <input type="checkbox" name="include_top_performers" value="1" <?php checked($settings['include_sections']['top_performers'] ?? true); ?>>
                            <span class="dashicons dashicons-star-filled"></span>
                            <span class="checkbox-label"><?php esc_html_e('Top Performers', 'peanut-suite'); ?></span>
                            <span class="checkbox-description"><?php esc_html_e('Best sources, campaigns, links', 'peanut-suite'); ?></span>
                        </label>

                        <label class="peanut-checkbox-card">
                            <input type="checkbox" name="include_utm_campaigns" value="1" <?php checked($settings['include_sections']['utm_campaigns'] ?? true); ?>>
                            <span class="dashicons dashicons-chart-line"></span>
                            <span class="checkbox-label"><?php esc_html_e('UTM Campaigns', 'peanut-suite'); ?></span>
                            <span class="checkbox-description"><?php esc_html_e('Campaign performance breakdown', 'peanut-suite'); ?></span>
                        </label>

                        <label class="peanut-checkbox-card">
                            <input type="checkbox" name="include_links" value="1" <?php checked($settings['include_sections']['links'] ?? true); ?>>
                            <span class="dashicons dashicons-admin-links"></span>
                            <span class="checkbox-label"><?php esc_html_e('Link Clicks', 'peanut-suite'); ?></span>
                            <span class="checkbox-description"><?php esc_html_e('Top performing links', 'peanut-suite'); ?></span>
                        </label>

                        <label class="peanut-checkbox-card">
                            <input type="checkbox" name="include_contacts" value="1" <?php checked($settings['include_sections']['contacts'] ?? true); ?>>
                            <span class="dashicons dashicons-groups"></span>
                            <span class="checkbox-label"><?php esc_html_e('Contacts', 'peanut-suite'); ?></span>
                            <span class="checkbox-description"><?php esc_html_e('New leads and sources', 'peanut-suite'); ?></span>
                        </label>

                        <label class="peanut-checkbox-card">
                            <input type="checkbox" name="include_visitors" value="1" <?php checked($settings['include_sections']['visitors'] ?? true); ?>>
                            <span class="dashicons dashicons-visibility"></span>
                            <span class="checkbox-label"><?php esc_html_e('Visitors', 'peanut-suite'); ?></span>
                            <span class="checkbox-description"><?php esc_html_e('Visitor tracking summary', 'peanut-suite'); ?></span>
                        </label>
                    </div>

                    <div class="peanut-toggle-row" style="margin-top: 24px;">
                        <label class="peanut-toggle">
                            <input type="checkbox" name="attach_pdf" value="1" <?php checked($settings['attach_pdf']); ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="toggle-label">
                            <strong><?php esc_html_e('Attach Report as File', 'peanut-suite'); ?></strong>
                            <p><?php esc_html_e('Include an HTML file attachment for offline viewing or archiving.', 'peanut-suite'); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Branding -->
            <div class="peanut-card" style="margin-top: 24px;">
                <div class="peanut-card-header">
                    <h3 class="peanut-card-title"><?php esc_html_e('Branding', 'peanut-suite'); ?></h3>
                </div>
                <div class="peanut-card-body">
                    <div class="peanut-form-row">
                        <label class="peanut-form-label" for="custom_logo"><?php esc_html_e('Logo URL', 'peanut-suite'); ?></label>
                        <div style="display: flex; gap: 8px;">
                            <input type="url" id="custom_logo" name="custom_logo" class="peanut-form-input"
                                   value="<?php echo esc_url($settings['custom_logo']); ?>"
                                   placeholder="https://example.com/logo.png">
                            <button type="button" class="button" id="peanut-select-logo"><?php esc_html_e('Select', 'peanut-suite'); ?></button>
                        </div>
                        <?php echo peanut_field_help(__('Optional logo to display at the top of email reports.', 'peanut-suite')); ?>
                    </div>

                    <div class="peanut-form-row">
                        <label class="peanut-form-label" for="custom_footer"><?php esc_html_e('Custom Footer', 'peanut-suite'); ?></label>
                        <input type="text" id="custom_footer" name="custom_footer" class="peanut-form-input"
                               value="<?php echo esc_attr($settings['custom_footer']); ?>"
                               placeholder="<?php echo esc_attr(sprintf(__('Sent from %s via Peanut Suite', 'peanut-suite'), get_bloginfo('name'))); ?>">
                    </div>
                </div>
            </div>

            <div class="peanut-form-actions" style="margin-top: 24px;">
                <button type="submit" class="button button-primary button-large"><?php esc_html_e('Save Report Settings', 'peanut-suite'); ?></button>
            </div>
        </form>
    </div>

    <!-- Sidebar -->
    <div class="peanut-sidebar-column">
        <!-- Report History -->
        <div class="peanut-card">
            <div class="peanut-card-header">
                <h3 class="peanut-card-title"><?php esc_html_e('Report History', 'peanut-suite'); ?></h3>
            </div>
            <div class="peanut-card-body" style="padding: 0;">
                <?php if (empty($report_log)): ?>
                    <div class="peanut-empty-state" style="padding: 24px;">
                        <span class="dashicons dashicons-email-alt" style="font-size: 32px; width: 32px; height: 32px; color: #94a3b8;"></span>
                        <p><?php esc_html_e('No reports have been sent yet.', 'peanut-suite'); ?></p>
                    </div>
                <?php else: ?>
                    <ul class="peanut-activity-list">
                        <?php foreach (array_slice($report_log, 0, 10) as $log): ?>
                        <li>
                            <span class="activity-icon" style="background: #f0fdf4;">
                                <span class="dashicons dashicons-yes" style="color: #10b981;"></span>
                            </span>
                            <div class="activity-content">
                                <strong><?php echo esc_html(ucfirst($log['frequency'])); ?> Report</strong>
                                <span class="activity-meta">
                                    <?php echo esc_html($log['period']); ?> &bull;
                                    <?php printf(_n('%d recipient', '%d recipients', $log['recipients'], 'peanut-suite'), $log['recipients']); ?>
                                </span>
                            </div>
                            <span class="activity-time"><?php echo esc_html(human_time_diff(strtotime($log['sent_at']))); ?> ago</span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Help -->
        <div class="peanut-card" style="margin-top: 24px;">
            <div class="peanut-card-header">
                <h3 class="peanut-card-title"><?php esc_html_e('About Email Reports', 'peanut-suite'); ?></h3>
            </div>
            <div class="peanut-card-body">
                <div class="peanut-help-content">
                    <p><?php esc_html_e('Email digest reports help you stay informed about your marketing performance without needing to log in.', 'peanut-suite'); ?></p>

                    <h4><?php esc_html_e('Report Timing', 'peanut-suite'); ?></h4>
                    <ul>
                        <li><strong><?php esc_html_e('Daily:', 'peanut-suite'); ?></strong> <?php esc_html_e('Covers the previous day', 'peanut-suite'); ?></li>
                        <li><strong><?php esc_html_e('Weekly:', 'peanut-suite'); ?></strong> <?php esc_html_e('Covers the previous 7 days', 'peanut-suite'); ?></li>
                        <li><strong><?php esc_html_e('Monthly:', 'peanut-suite'); ?></strong> <?php esc_html_e('Covers the previous calendar month', 'peanut-suite'); ?></li>
                    </ul>

                    <h4><?php esc_html_e('Tips', 'peanut-suite'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Weekly reports work best for most businesses', 'peanut-suite'); ?></li>
                        <li><?php esc_html_e('Add multiple recipients to keep your team informed', 'peanut-suite'); ?></li>
                        <li><?php esc_html_e('Use the Preview button to see exactly what recipients will receive', 'peanut-suite'); ?></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div id="peanut-preview-modal" class="peanut-modal-backdrop">
    <div class="peanut-modal" style="max-width: 700px; max-height: 90vh; overflow: hidden; display: flex; flex-direction: column;">
        <div class="peanut-modal-header">
            <h3><?php esc_html_e('Report Preview', 'peanut-suite'); ?></h3>
            <button type="button" class="peanut-modal-close">&times;</button>
        </div>
        <div class="peanut-modal-body" style="padding: 0; flex: 1; overflow: auto;">
            <iframe id="peanut-preview-frame" style="width: 100%; height: 600px; border: 0;"></iframe>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Frequency toggle
    $('input[name="frequency"]').on('change', function() {
        var freq = $(this).val();
        $('.peanut-weekly-options').toggle(freq === 'weekly');
        $('.peanut-monthly-options').toggle(freq === 'monthly');

        // Update button group active state
        $('.peanut-button-option').removeClass('active');
        $(this).closest('.peanut-button-option').addClass('active');
    });

    // Preview report
    $('#peanut-preview-report').on('click', function() {
        var $btn = $(this);
        $btn.prop('disabled', true).text('<?php esc_html_e('Loading...', 'peanut-suite'); ?>');

        $.ajax({
            url: '<?php echo esc_url(rest_url(PEANUT_API_NAMESPACE . '/reports/preview')); ?>',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
            },
            success: function(response) {
                var $iframe = $('#peanut-preview-frame');
                var doc = $iframe[0].contentWindow.document;
                doc.open();
                doc.write(response.html);
                doc.close();
                PeanutAdmin.openModal('peanut-preview-modal');
            },
            error: function() {
                alert('<?php esc_html_e('Failed to load preview', 'peanut-suite'); ?>');
            },
            complete: function() {
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-visibility"></span> <?php esc_html_e('Preview Report', 'peanut-suite'); ?>');
            }
        });
    });

    // Send now
    $('#peanut-send-now').on('click', function() {
        var $btn = $(this);

        if (!confirm('<?php esc_html_e('Send the report now to all recipients?', 'peanut-suite'); ?>')) {
            return;
        }

        $btn.prop('disabled', true).text('<?php esc_html_e('Sending...', 'peanut-suite'); ?>');

        $.ajax({
            url: '<?php echo esc_url(rest_url(PEANUT_API_NAMESPACE . '/reports/send-now')); ?>',
            method: 'POST',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
            },
            success: function() {
                PeanutAdmin.showNotice('success', '<?php esc_html_e('Report sent successfully!', 'peanut-suite'); ?>');
                setTimeout(function() {
                    location.reload();
                }, 2000);
            },
            error: function() {
                PeanutAdmin.showNotice('error', '<?php esc_html_e('Failed to send report', 'peanut-suite'); ?>');
                $btn.prop('disabled', false).html('<span class="dashicons dashicons-email-alt"></span> <?php esc_html_e('Send Now', 'peanut-suite'); ?>');
            }
        });
    });

    // Media uploader for logo
    $('#peanut-select-logo').on('click', function(e) {
        e.preventDefault();

        var frame = wp.media({
            title: '<?php esc_html_e('Select Logo', 'peanut-suite'); ?>',
            button: { text: '<?php esc_html_e('Use this image', 'peanut-suite'); ?>' },
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            $('#custom_logo').val(attachment.url);
        });

        frame.open();
    });
});
</script>

<style>
.peanut-two-column {
    display: grid;
    grid-template-columns: 1fr 350px;
    gap: 24px;
}
.peanut-main-column {
    min-width: 0;
}
.peanut-sidebar-column {
    min-width: 0;
}
.peanut-info-banner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    background: #eff6ff;
    border-radius: 8px;
    color: #1e40af;
}
.peanut-info-banner .dashicons {
    font-size: 20px;
    width: 20px;
    height: 20px;
}
.peanut-button-group {
    display: flex;
    gap: 0;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
}
.peanut-button-option {
    flex: 1;
    padding: 10px 16px;
    text-align: center;
    cursor: pointer;
    border-right: 1px solid #e2e8f0;
    background: #fff;
    transition: all 0.15s;
}
.peanut-button-option:last-child {
    border-right: 0;
}
.peanut-button-option input {
    display: none;
}
.peanut-button-option:hover {
    background: #f8fafc;
}
.peanut-button-option.active {
    background: #f97316;
    color: #fff;
}
.peanut-checkbox-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}
.peanut-checkbox-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 20px;
    border: 2px solid #e2e8f0;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s;
    text-align: center;
}
.peanut-checkbox-card:hover {
    border-color: #cbd5e1;
}
.peanut-checkbox-card:has(input:checked) {
    border-color: #f97316;
    background: #fff7ed;
}
.peanut-checkbox-card input {
    display: none;
}
.peanut-checkbox-card .dashicons {
    font-size: 28px;
    width: 28px;
    height: 28px;
    color: #64748b;
}
.peanut-checkbox-card:has(input:checked) .dashicons {
    color: #f97316;
}
.peanut-checkbox-card .checkbox-label {
    font-weight: 600;
    color: #1f2937;
}
.peanut-checkbox-card .checkbox-description {
    font-size: 12px;
    color: #64748b;
}
.peanut-activity-list {
    list-style: none;
    margin: 0;
    padding: 0;
}
.peanut-activity-list li {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid #e2e8f0;
}
.peanut-activity-list li:last-child {
    border-bottom: 0;
}
.peanut-activity-list .activity-icon {
    flex-shrink: 0;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.peanut-activity-list .activity-icon .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
}
.peanut-activity-list .activity-content {
    flex: 1;
    min-width: 0;
}
.peanut-activity-list .activity-content strong {
    display: block;
    font-size: 13px;
    color: #1f2937;
}
.peanut-activity-list .activity-meta {
    font-size: 12px;
    color: #64748b;
}
.peanut-activity-list .activity-time {
    font-size: 12px;
    color: #94a3b8;
    white-space: nowrap;
}
.peanut-help-content h4 {
    margin: 16px 0 8px;
    font-size: 13px;
    font-weight: 600;
    color: #374151;
}
.peanut-help-content h4:first-child {
    margin-top: 0;
}
.peanut-help-content ul {
    margin: 0;
    padding-left: 20px;
}
.peanut-help-content li {
    margin-bottom: 4px;
    font-size: 13px;
    color: #4b5563;
}

@media (max-width: 1024px) {
    .peanut-two-column {
        grid-template-columns: 1fr;
    }
    .peanut-checkbox-grid {
        grid-template-columns: 1fr;
    }
}
</style>
