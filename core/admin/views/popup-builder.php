<?php
/**
 * Popup Builder View
 *
 * Create and edit popups with tabbed interface.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load database class
require_once PEANUT_PLUGIN_DIR . 'modules/popups/class-popups-database.php';

// Get popup ID if editing
$popup_id = isset($_GET['id']) ? absint($_GET['id']) : 0;
$popup = null;

// Load existing popup or use defaults
if ($popup_id) {
    global $wpdb;
    $table = Popups_Database::popups_table();
    $popup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $popup_id), ARRAY_A);
}

if (!$popup) {
    $popup = Popups_Database::get_default_popup();
    $popup['id'] = 0;
}

// Decode JSON fields
$form_fields = is_string($popup['form_fields'] ?? '') ? json_decode($popup['form_fields'], true) : ($popup['form_fields'] ?? []);
$triggers = is_string($popup['triggers'] ?? '') ? json_decode($popup['triggers'], true) : ($popup['triggers'] ?? []);
$display_rules = is_string($popup['display_rules'] ?? '') ? json_decode($popup['display_rules'], true) : ($popup['display_rules'] ?? []);
$styles = is_string($popup['styles'] ?? '') ? json_decode($popup['styles'], true) : ($popup['styles'] ?? []);
$settings = is_string($popup['settings'] ?? '') ? json_decode($popup['settings'], true) : ($popup['settings'] ?? []);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['peanut_popup_nonce'])) {
    if ( ! wp_verify_nonce( sanitize_key( $_POST['peanut_popup_nonce'] ), 'peanut_save_popup' ) ) {
        wp_die( __( 'Security check failed.', 'peanut-suite' ), 403 );
    }
    global $wpdb;
    $table = Popups_Database::popups_table();

    $data = [
        'user_id' => get_current_user_id(),
        'name' => sanitize_text_field($_POST['name'] ?? ''),
        'type' => sanitize_text_field($_POST['type'] ?? 'modal'),
        'position' => sanitize_text_field($_POST['position'] ?? 'center'),
        'status' => sanitize_text_field($_POST['status'] ?? 'draft'),
        'title' => sanitize_text_field($_POST['title'] ?? ''),
        'content' => wp_kses_post($_POST['content'] ?? ''),
        'image_url' => esc_url_raw($_POST['image_url'] ?? ''),
        'button_text' => sanitize_text_field($_POST['button_text'] ?? 'Subscribe'),
        'success_message' => sanitize_text_field($_POST['success_message'] ?? ''),
        'form_fields' => wp_json_encode($_POST['form_fields'] ?? []),
        'triggers' => wp_json_encode($_POST['triggers'] ?? []),
        'display_rules' => wp_json_encode($_POST['display_rules'] ?? []),
        'styles' => wp_json_encode($_POST['styles'] ?? []),
        'settings' => wp_json_encode($_POST['settings'] ?? []),
    ];

    if ($popup_id) {
        $wpdb->update($table, $data, ['id' => $popup_id]);
        echo '<div class="notice notice-success"><p>' . esc_html__('Popup updated successfully.', 'peanut-suite') . '</p></div>';
    } else {
        $wpdb->insert($table, $data);
        $popup_id = $wpdb->insert_id;
        echo '<div class="notice notice-success"><p>' . esc_html__('Popup created successfully.', 'peanut-suite') . '</p></div>';
        // Redirect to edit URL
        echo '<script>window.history.replaceState({}, "", "' . admin_url('admin.php?page=peanut-popup-builder&id=' . $popup_id) . '");</script>';
    }

    // Reload popup data
    $popup = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $popup_id), ARRAY_A);
    $form_fields = json_decode($popup['form_fields'], true) ?: [];
    $triggers = json_decode($popup['triggers'], true) ?: [];
    $display_rules = json_decode($popup['display_rules'], true) ?: [];
    $styles = json_decode($popup['styles'], true) ?: [];
    $settings = json_decode($popup['settings'], true) ?: [];
}

$current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'content';
?>

<form method="post" id="peanut-popup-form">
    <?php wp_nonce_field('peanut_save_popup', 'peanut_popup_nonce'); ?>

    <div class="peanut-builder-layout">
        <!-- Main Form -->
        <div class="peanut-card" style="padding: 20px;">
            <!-- Tabs -->
            <div class="peanut-builder-tabs">
                <a href="#content" class="peanut-builder-tab active" data-tab="content">
                    <span class="dashicons dashicons-edit"></span> <?php esc_html_e('Content', 'peanut-suite'); ?>
                </a>
                <a href="#triggers" class="peanut-builder-tab" data-tab="triggers">
                    <span class="dashicons dashicons-clock"></span> <?php esc_html_e('Triggers', 'peanut-suite'); ?>
                </a>
                <a href="#targeting" class="peanut-builder-tab" data-tab="targeting">
                    <span class="dashicons dashicons-filter"></span> <?php esc_html_e('Targeting', 'peanut-suite'); ?>
                </a>
                <a href="#styling" class="peanut-builder-tab" data-tab="styling">
                    <span class="dashicons dashicons-art"></span> <?php esc_html_e('Styling', 'peanut-suite'); ?>
                </a>
            </div>

            <!-- Content Panel -->
            <div class="peanut-builder-panel active" data-panel="content">
                <div class="peanut-field-group">
                    <label><?php esc_html_e('Popup Name', 'peanut-suite'); ?></label>
                    <input type="text" name="name" value="<?php echo esc_attr($popup['name'] ?? ''); ?>" placeholder="<?php esc_attr_e('e.g., Newsletter Signup', 'peanut-suite'); ?>" required>
                    <p class="peanut-field-hint"><?php esc_html_e('Internal name for your reference', 'peanut-suite'); ?></p>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Headline', 'peanut-suite'); ?></label>
                    <input type="text" name="title" value="<?php echo esc_attr($popup['title'] ?? ''); ?>" placeholder="<?php esc_attr_e('e.g., Get 10% Off Your First Order!', 'peanut-suite'); ?>">
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Message', 'peanut-suite'); ?></label>
                    <textarea name="content" placeholder="<?php esc_attr_e('Enter your popup message...', 'peanut-suite'); ?>"><?php echo esc_textarea($popup['content'] ?? ''); ?></textarea>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Image URL', 'peanut-suite'); ?></label>
                    <input type="url" name="image_url" value="<?php echo esc_url($popup['image_url'] ?? ''); ?>" placeholder="https://">
                    <p class="peanut-field-hint"><?php esc_html_e('Optional header image', 'peanut-suite'); ?></p>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Button Text', 'peanut-suite'); ?></label>
                    <input type="text" name="button_text" value="<?php echo esc_attr($popup['button_text'] ?? 'Subscribe'); ?>">
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Success Message', 'peanut-suite'); ?></label>
                    <input type="text" name="success_message" value="<?php echo esc_attr($popup['success_message'] ?? ''); ?>" placeholder="<?php esc_attr_e('Thank you for subscribing!', 'peanut-suite'); ?>">
                </div>
            </div>

            <!-- Triggers Panel -->
            <div class="peanut-builder-panel" data-panel="triggers">
                <div class="peanut-field-group">
                    <label><?php esc_html_e('Trigger Type', 'peanut-suite'); ?></label>
                    <select name="triggers[type]">
                        <option value="time_delay" <?php selected($triggers['type'] ?? '', 'time_delay'); ?>><?php esc_html_e('Time Delay', 'peanut-suite'); ?></option>
                        <option value="exit_intent" <?php selected($triggers['type'] ?? '', 'exit_intent'); ?>><?php esc_html_e('Exit Intent', 'peanut-suite'); ?></option>
                        <option value="scroll" <?php selected($triggers['type'] ?? '', 'scroll'); ?>><?php esc_html_e('Scroll Percentage', 'peanut-suite'); ?></option>
                        <option value="click" <?php selected($triggers['type'] ?? '', 'click'); ?>><?php esc_html_e('Click Trigger', 'peanut-suite'); ?></option>
                        <option value="immediate" <?php selected($triggers['type'] ?? '', 'immediate'); ?>><?php esc_html_e('Immediately', 'peanut-suite'); ?></option>
                    </select>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Delay (seconds)', 'peanut-suite'); ?></label>
                    <input type="number" name="triggers[delay]" value="<?php echo esc_attr($triggers['delay'] ?? 5); ?>" min="0" max="120">
                    <p class="peanut-field-hint"><?php esc_html_e('For time delay trigger', 'peanut-suite'); ?></p>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Scroll Percentage', 'peanut-suite'); ?></label>
                    <input type="number" name="triggers[scroll_percent]" value="<?php echo esc_attr($triggers['scroll_percent'] ?? 50); ?>" min="0" max="100">
                    <p class="peanut-field-hint"><?php esc_html_e('For scroll trigger', 'peanut-suite'); ?></p>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Click Selector', 'peanut-suite'); ?></label>
                    <input type="text" name="triggers[click_selector]" value="<?php echo esc_attr($triggers['click_selector'] ?? ''); ?>" placeholder=".my-button, #open-popup">
                    <p class="peanut-field-hint"><?php esc_html_e('CSS selector for click trigger', 'peanut-suite'); ?></p>
                </div>
            </div>

            <!-- Targeting Panel -->
            <div class="peanut-builder-panel" data-panel="targeting">
                <div class="peanut-field-group">
                    <label><?php esc_html_e('Show On Pages', 'peanut-suite'); ?></label>
                    <select name="display_rules[pages]">
                        <option value="all" <?php selected($display_rules['pages'] ?? '', 'all'); ?>><?php esc_html_e('All Pages', 'peanut-suite'); ?></option>
                        <option value="homepage" <?php selected($display_rules['pages'] ?? '', 'homepage'); ?>><?php esc_html_e('Homepage Only', 'peanut-suite'); ?></option>
                        <option value="posts" <?php selected($display_rules['pages'] ?? '', 'posts'); ?>><?php esc_html_e('Blog Posts Only', 'peanut-suite'); ?></option>
                        <option value="specific" <?php selected($display_rules['pages'] ?? '', 'specific'); ?>><?php esc_html_e('Specific Pages', 'peanut-suite'); ?></option>
                    </select>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Page URLs (one per line)', 'peanut-suite'); ?></label>
                    <textarea name="display_rules[page_urls]" rows="4" placeholder="/pricing&#10;/features&#10;/about"><?php echo esc_textarea($display_rules['page_urls'] ?? ''); ?></textarea>
                    <p class="peanut-field-hint"><?php esc_html_e('For specific pages targeting', 'peanut-suite'); ?></p>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Devices', 'peanut-suite'); ?></label>
                    <div class="peanut-checkbox-group">
                        <?php $devices = $display_rules['devices'] ?? ['desktop', 'tablet', 'mobile']; ?>
                        <label><input type="checkbox" name="display_rules[devices][]" value="desktop" <?php checked(in_array('desktop', $devices)); ?>> <?php esc_html_e('Desktop', 'peanut-suite'); ?></label>
                        <label><input type="checkbox" name="display_rules[devices][]" value="tablet" <?php checked(in_array('tablet', $devices)); ?>> <?php esc_html_e('Tablet', 'peanut-suite'); ?></label>
                        <label><input type="checkbox" name="display_rules[devices][]" value="mobile" <?php checked(in_array('mobile', $devices)); ?>> <?php esc_html_e('Mobile', 'peanut-suite'); ?></label>
                    </div>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Visitor Type', 'peanut-suite'); ?></label>
                    <select name="display_rules[user_status]">
                        <option value="all" <?php selected($display_rules['user_status'] ?? '', 'all'); ?>><?php esc_html_e('All Visitors', 'peanut-suite'); ?></option>
                        <option value="logged_out" <?php selected($display_rules['user_status'] ?? '', 'logged_out'); ?>><?php esc_html_e('Logged Out Only', 'peanut-suite'); ?></option>
                        <option value="logged_in" <?php selected($display_rules['user_status'] ?? '', 'logged_in'); ?>><?php esc_html_e('Logged In Only', 'peanut-suite'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Styling Panel -->
            <div class="peanut-builder-panel" data-panel="styling">
                <div class="peanut-field-group">
                    <label><?php esc_html_e('Popup Type', 'peanut-suite'); ?></label>
                    <select name="type">
                        <option value="modal" <?php selected($popup['type'] ?? '', 'modal'); ?>><?php esc_html_e('Modal (Center)', 'peanut-suite'); ?></option>
                        <option value="slide-in" <?php selected($popup['type'] ?? '', 'slide-in'); ?>><?php esc_html_e('Slide-in (Corner)', 'peanut-suite'); ?></option>
                        <option value="bar" <?php selected($popup['type'] ?? '', 'bar'); ?>><?php esc_html_e('Bar (Top/Bottom)', 'peanut-suite'); ?></option>
                        <option value="fullscreen" <?php selected($popup['type'] ?? '', 'fullscreen'); ?>><?php esc_html_e('Fullscreen', 'peanut-suite'); ?></option>
                    </select>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Position', 'peanut-suite'); ?></label>
                    <select name="position">
                        <option value="center" <?php selected($popup['position'] ?? '', 'center'); ?>><?php esc_html_e('Center', 'peanut-suite'); ?></option>
                        <option value="top" <?php selected($popup['position'] ?? '', 'top'); ?>><?php esc_html_e('Top', 'peanut-suite'); ?></option>
                        <option value="bottom" <?php selected($popup['position'] ?? '', 'bottom'); ?>><?php esc_html_e('Bottom', 'peanut-suite'); ?></option>
                        <option value="bottom-right" <?php selected($popup['position'] ?? '', 'bottom-right'); ?>><?php esc_html_e('Bottom Right', 'peanut-suite'); ?></option>
                        <option value="bottom-left" <?php selected($popup['position'] ?? '', 'bottom-left'); ?>><?php esc_html_e('Bottom Left', 'peanut-suite'); ?></option>
                    </select>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Background Color', 'peanut-suite'); ?></label>
                    <div class="peanut-color-field">
                        <input type="color" name="styles[background_color]" value="<?php echo esc_attr($styles['background_color'] ?? '#ffffff'); ?>">
                        <input type="text" value="<?php echo esc_attr($styles['background_color'] ?? '#ffffff'); ?>" readonly>
                    </div>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Text Color', 'peanut-suite'); ?></label>
                    <div class="peanut-color-field">
                        <input type="color" name="styles[text_color]" value="<?php echo esc_attr($styles['text_color'] ?? '#333333'); ?>">
                        <input type="text" value="<?php echo esc_attr($styles['text_color'] ?? '#333333'); ?>" readonly>
                    </div>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Button Color', 'peanut-suite'); ?></label>
                    <div class="peanut-color-field">
                        <input type="color" name="styles[button_color]" value="<?php echo esc_attr($styles['button_color'] ?? '#0073aa'); ?>">
                        <input type="text" value="<?php echo esc_attr($styles['button_color'] ?? '#0073aa'); ?>" readonly>
                    </div>
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Border Radius (px)', 'peanut-suite'); ?></label>
                    <input type="number" name="styles[border_radius]" value="<?php echo esc_attr($styles['border_radius'] ?? 8); ?>" min="0" max="50">
                </div>

                <div class="peanut-field-group">
                    <label><?php esc_html_e('Max Width (px)', 'peanut-suite'); ?></label>
                    <input type="number" name="styles[max_width]" value="<?php echo esc_attr($styles['max_width'] ?? 500); ?>" min="200" max="800">
                </div>
            </div>

            <!-- Actions -->
            <div class="peanut-builder-actions">
                <div class="peanut-field-group" style="margin: 0; flex: 1;">
                    <select name="status">
                        <option value="draft" <?php selected($popup['status'] ?? '', 'draft'); ?>><?php esc_html_e('Draft', 'peanut-suite'); ?></option>
                        <option value="active" <?php selected($popup['status'] ?? '', 'active'); ?>><?php esc_html_e('Active', 'peanut-suite'); ?></option>
                        <option value="paused" <?php selected($popup['status'] ?? '', 'paused'); ?>><?php esc_html_e('Paused', 'peanut-suite'); ?></option>
                    </select>
                </div>
                <button type="submit" class="button button-primary"><?php echo $popup_id ? esc_html__('Update Popup', 'peanut-suite') : esc_html__('Create Popup', 'peanut-suite'); ?></button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=peanut-popups')); ?>" class="button"><?php esc_html_e('Cancel', 'peanut-suite'); ?></a>
            </div>
        </div>

        <!-- Preview Panel -->
        <div class="peanut-preview-panel">
            <h3 style="margin: 0 0 16px; font-size: 14px; font-weight: 600;"><?php esc_html_e('Preview', 'peanut-suite'); ?></h3>
            <div class="peanut-preview-frame" id="popup-preview">
                <h4 style="margin: 0 0 12px; font-size: 18px;" id="preview-title"><?php echo esc_html($popup['title'] ?: 'Your Headline Here'); ?></h4>
                <p style="margin: 0 0 16px; color: #64748b; font-size: 14px;" id="preview-content"><?php echo esc_html($popup['content'] ?: 'Your message will appear here...'); ?></p>
                <input type="email" placeholder="Enter your email" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 12px;">
                <button type="button" style="width: 100%; padding: 12px; background: <?php echo esc_attr($styles['button_color'] ?? '#0073aa'); ?>; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-weight: 500;" id="preview-button">
                    <?php echo esc_html($popup['button_text'] ?: 'Subscribe'); ?>
                </button>
            </div>

            <div style="margin-top: 16px; padding: 12px; background: #fff; border-radius: 6px; font-size: 12px; color: #64748b;">
                <strong><?php esc_html_e('Tip:', 'peanut-suite'); ?></strong>
                <?php esc_html_e('The preview updates as you type. Test your popup on the frontend after saving.', 'peanut-suite'); ?>
            </div>
        </div>
    </div>
</form>

<script>
jQuery(document).ready(function($) {
    // Tab switching
    $('.peanut-builder-tab').on('click', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');

        $('.peanut-builder-tab').removeClass('active');
        $(this).addClass('active');

        $('.peanut-builder-panel').removeClass('active');
        $('[data-panel="' + tab + '"]').addClass('active');
    });

    // Color picker sync
    $('input[type="color"]').on('input', function() {
        $(this).next('input[type="text"]').val($(this).val());
    });

    // Live preview updates
    $('input[name="title"]').on('input', function() {
        $('#preview-title').text($(this).val() || 'Your Headline Here');
    });

    $('textarea[name="content"]').on('input', function() {
        $('#preview-content').text($(this).val() || 'Your message will appear here...');
    });

    $('input[name="button_text"]').on('input', function() {
        $('#preview-button').text($(this).val() || 'Subscribe');
    });

    $('input[name="styles[button_color]"]').on('input', function() {
        $('#preview-button').css('background', $(this).val());
    });
});
</script>
