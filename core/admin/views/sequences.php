<?php
/**
 * Email Sequences View
 */

if (!defined('ABSPATH')) {
    exit;
}

global $wpdb;
$sequences_table = $wpdb->prefix . 'peanut_sequences';
$emails_table = $wpdb->prefix . 'peanut_sequence_emails';
$subscribers_table = $wpdb->prefix . 'peanut_sequence_subscribers';

// Get sequences
$sequences = [];
if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sequences_table)) === $sequences_table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name uses $wpdb->prefix which is not user-controlled
    $sequences = $wpdb->get_results("SELECT * FROM $sequences_table ORDER BY created_at DESC", ARRAY_A) ?: [];

    // Get stats for each sequence
    foreach ($sequences as &$seq) {
        $seq['email_count'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $emails_table WHERE sequence_id = %d",
            $seq['id']
        ));
        $seq['subscriber_count'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $subscribers_table WHERE sequence_id = %d",
            $seq['id']
        ));
        $seq['active_count'] = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $subscribers_table WHERE sequence_id = %d AND status = 'active'",
            $seq['id']
        ));
    }
}

// Get stats
$total_sequences = count($sequences);
$active_sequences = count(array_filter($sequences, fn($s) => $s['status'] === 'active'));
$total_subscribers = array_sum(array_column($sequences, 'subscriber_count'));
$active_subscribers = array_sum(array_column($sequences, 'active_count'));
?>

<div class="peanut-content">
    <!-- Stats Cards -->
    <div class="peanut-stats-grid">
        <div class="peanut-stat-card">
            <div class="stat-icon"><span class="dashicons dashicons-email-alt2"></span></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo esc_html($total_sequences); ?></div>
                <div class="stat-label"><?php esc_html_e('Total Sequences', 'peanut-suite'); ?></div>
            </div>
        </div>
        <div class="peanut-stat-card">
            <div class="stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo esc_html($active_sequences); ?></div>
                <div class="stat-label"><?php esc_html_e('Active Sequences', 'peanut-suite'); ?></div>
            </div>
        </div>
        <div class="peanut-stat-card">
            <div class="stat-icon"><span class="dashicons dashicons-groups"></span></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo esc_html($total_subscribers); ?></div>
                <div class="stat-label"><?php esc_html_e('Total Subscribers', 'peanut-suite'); ?></div>
            </div>
        </div>
        <div class="peanut-stat-card">
            <div class="stat-icon"><span class="dashicons dashicons-clock"></span></div>
            <div class="stat-content">
                <div class="stat-value"><?php echo esc_html($active_subscribers); ?></div>
                <div class="stat-label"><?php esc_html_e('In Progress', 'peanut-suite'); ?></div>
            </div>
        </div>
    </div>

    <!-- Actions Bar -->
    <div class="peanut-toolbar">
        <button type="button" class="button button-primary" id="create-sequence">
            <span class="dashicons dashicons-plus-alt"></span>
            <?php esc_html_e('Create Sequence', 'peanut-suite'); ?>
        </button>
    </div>

    <!-- Sequences List -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3><?php esc_html_e('Email Sequences', 'peanut-suite'); ?></h3>
        </div>
        <div class="peanut-card-body">
            <?php if (!empty($sequences)): ?>
                <table class="peanut-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Sequence', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Trigger', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Emails', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Subscribers', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Status', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Actions', 'peanut-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sequences as $sequence): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($sequence['name']); ?></strong>
                                    <?php if (!empty($sequence['description'])): ?>
                                        <br><small class="text-muted"><?php echo esc_html($sequence['description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $triggers = [
                                        'contact_created' => __('Contact Created', 'peanut-suite'),
                                        'tag_added' => __('Tag Added', 'peanut-suite'),
                                        'manual' => __('Manual', 'peanut-suite'),
                                    ];
                                    echo esc_html($triggers[$sequence['trigger_type']] ?? $sequence['trigger_type']);
                                    if (!empty($sequence['trigger_value'])) {
                                        echo '<br><small class="text-muted">' . esc_html($sequence['trigger_value']) . '</small>';
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($sequence['email_count']); ?></td>
                                <td>
                                    <?php echo esc_html($sequence['subscriber_count']); ?>
                                    <?php if ($sequence['active_count'] > 0): ?>
                                        <br><small class="text-muted"><?php printf(__('%d active', 'peanut-suite'), $sequence['active_count']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($sequence['status'] === 'active'): ?>
                                        <span class="peanut-badge peanut-badge-success"><?php esc_html_e('Active', 'peanut-suite'); ?></span>
                                    <?php else: ?>
                                        <span class="peanut-badge peanut-badge-secondary"><?php esc_html_e('Draft', 'peanut-suite'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="peanut-actions">
                                        <button type="button" class="button button-small edit-sequence" data-id="<?php echo esc_attr($sequence['id']); ?>">
                                            <?php esc_html_e('Edit', 'peanut-suite'); ?>
                                        </button>
                                        <button type="button" class="button button-small manage-emails" data-id="<?php echo esc_attr($sequence['id']); ?>">
                                            <?php esc_html_e('Emails', 'peanut-suite'); ?>
                                        </button>
                                        <button type="button" class="button button-small view-subscribers" data-id="<?php echo esc_attr($sequence['id']); ?>">
                                            <?php esc_html_e('Subscribers', 'peanut-suite'); ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="peanut-empty-state">
                    <span class="dashicons dashicons-email-alt2"></span>
                    <h3><?php esc_html_e('No Email Sequences Yet', 'peanut-suite'); ?></h3>
                    <p><?php esc_html_e('Create automated drip campaigns to nurture your leads.', 'peanut-suite'); ?></p>
                    <button type="button" class="button button-primary" id="create-first-sequence">
                        <?php esc_html_e('Create Your First Sequence', 'peanut-suite'); ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- How It Works -->
    <div class="peanut-card peanut-help-card">
        <div class="peanut-card-header">
            <h3><span class="dashicons dashicons-info-outline"></span> <?php esc_html_e('How Email Sequences Work', 'peanut-suite'); ?></h3>
        </div>
        <div class="peanut-card-body">
            <div class="peanut-help-grid">
                <div class="help-item">
                    <span class="help-number">1</span>
                    <div>
                        <strong><?php esc_html_e('Create Sequence', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Set up a sequence with a trigger (new contact, tag added, etc.).', 'peanut-suite'); ?></p>
                    </div>
                </div>
                <div class="help-item">
                    <span class="help-number">2</span>
                    <div>
                        <strong><?php esc_html_e('Add Emails', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Create emails with delays (e.g., Day 1, Day 3, Day 7).', 'peanut-suite'); ?></p>
                    </div>
                </div>
                <div class="help-item">
                    <span class="help-number">3</span>
                    <div>
                        <strong><?php esc_html_e('Activate', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Enable the sequence and contacts will automatically receive emails.', 'peanut-suite'); ?></p>
                    </div>
                </div>
            </div>
            <div class="peanut-personalization-info">
                <h4><?php esc_html_e('Personalization Tags', 'peanut-suite'); ?></h4>
                <p><?php esc_html_e('Use these tags in your emails:', 'peanut-suite'); ?></p>
                <code>{first_name}</code> <code>{last_name}</code> <code>{email}</code> <code>{company}</code>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Sequence Modal -->
<div id="sequence-modal" class="peanut-modal" style="display:none;">
    <div class="peanut-modal-content">
        <div class="peanut-modal-header">
            <h2 id="sequence-modal-title"><?php esc_html_e('Create Sequence', 'peanut-suite'); ?></h2>
            <button type="button" class="peanut-modal-close">&times;</button>
        </div>
        <div class="peanut-modal-body">
            <form id="sequence-form">
                <input type="hidden" name="id" id="sequence-id">
                <div class="peanut-form-row">
                    <label for="sequence-name"><?php esc_html_e('Sequence Name', 'peanut-suite'); ?></label>
                    <input type="text" id="sequence-name" name="name" required>
                </div>
                <div class="peanut-form-row">
                    <label for="sequence-description"><?php esc_html_e('Description', 'peanut-suite'); ?></label>
                    <textarea id="sequence-description" name="description" rows="2"></textarea>
                </div>
                <div class="peanut-form-row">
                    <label for="sequence-trigger"><?php esc_html_e('Trigger', 'peanut-suite'); ?></label>
                    <select id="sequence-trigger" name="trigger_type">
                        <option value="manual"><?php esc_html_e('Manual Enrollment', 'peanut-suite'); ?></option>
                        <option value="contact_created"><?php esc_html_e('Contact Created', 'peanut-suite'); ?></option>
                        <option value="tag_added"><?php esc_html_e('Tag Added', 'peanut-suite'); ?></option>
                    </select>
                </div>
                <div class="peanut-form-row" id="trigger-value-row" style="display:none;">
                    <label for="sequence-trigger-value"><?php esc_html_e('Tag Name', 'peanut-suite'); ?></label>
                    <input type="text" id="sequence-trigger-value" name="trigger_value" placeholder="e.g., newsletter-signup">
                </div>
                <div class="peanut-form-row">
                    <label for="sequence-from-email"><?php esc_html_e('From Email', 'peanut-suite'); ?></label>
                    <input type="email" id="sequence-from-email" name="from_email" value="<?php echo esc_attr(get_option('admin_email')); ?>">
                </div>
                <div class="peanut-form-row">
                    <label for="sequence-from-name"><?php esc_html_e('From Name', 'peanut-suite'); ?></label>
                    <input type="text" id="sequence-from-name" name="from_name" value="<?php echo esc_attr(get_bloginfo('name')); ?>">
                </div>
                <div class="peanut-form-row">
                    <label>
                        <input type="checkbox" name="status" value="active">
                        <?php esc_html_e('Activate sequence', 'peanut-suite'); ?>
                    </label>
                </div>
            </form>
        </div>
        <div class="peanut-modal-footer">
            <button type="button" class="button" data-dismiss="modal"><?php esc_html_e('Cancel', 'peanut-suite'); ?></button>
            <button type="button" class="button button-primary" id="save-sequence"><?php esc_html_e('Save Sequence', 'peanut-suite'); ?></button>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Toggle trigger value field
    $('#sequence-trigger').on('change', function() {
        $('#trigger-value-row').toggle($(this).val() === 'tag_added');
    });

    // Open create modal
    $('#create-sequence, #create-first-sequence').on('click', function() {
        $('#sequence-modal-title').text('<?php esc_html_e('Create Sequence', 'peanut-suite'); ?>');
        $('#sequence-form')[0].reset();
        $('#sequence-id').val('');
        $('#sequence-modal').show();
    });

    // Close modal
    $('.peanut-modal-close, [data-dismiss="modal"]').on('click', function() {
        $(this).closest('.peanut-modal').hide();
    });

    // Save sequence
    $('#save-sequence').on('click', function() {
        const form = $('#sequence-form');
        const data = {
            action: 'peanut_save_sequence',
            nonce: '<?php echo wp_create_nonce('peanut_sequences'); ?>',
            id: $('#sequence-id').val(),
            name: $('#sequence-name').val(),
            description: $('#sequence-description').val(),
            trigger_type: $('#sequence-trigger').val(),
            trigger_value: $('#sequence-trigger-value').val(),
            from_email: $('#sequence-from-email').val(),
            from_name: $('#sequence-from-name').val(),
            status: $('input[name="status"]').is(':checked') ? 'active' : 'draft'
        };

        $.post(ajaxurl, data, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data || 'Error saving sequence');
            }
        });
    });
});
</script>
