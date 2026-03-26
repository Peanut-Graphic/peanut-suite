<?php
/**
 * Settings View
 *
 * Configure Peanut Suite settings, license, and integrations.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get current tab
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

// Get license data
$license = peanut_get_license();

// Get settings
$settings = get_option('peanut_settings', []);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['peanut_settings_nonce'])) {
    if ( ! wp_verify_nonce( sanitize_key( $_POST['peanut_settings_nonce'] ), 'peanut_save_settings' ) ) {
        wp_die( __( 'Security check failed.', 'peanut-suite' ), 403 );
    }
    // Process based on tab
    switch ($current_tab) {
        case 'general':
            $settings['site_name'] = sanitize_text_field($_POST['site_name'] ?? '');
            $settings['short_domain'] = sanitize_text_field($_POST['short_domain'] ?? '');
            $settings['timezone'] = sanitize_text_field($_POST['timezone'] ?? 'UTC');
            update_option('peanut_settings', $settings);
            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'peanut-suite') . '</p></div>';
            break;

        case 'license':
            $license_key = sanitize_text_field($_POST['license_key'] ?? '');
            if ($license_key) {
                // Activate license
                require_once PEANUT_PLUGIN_DIR . 'core/services/class-peanut-license.php';
                $license_service = new Peanut_License();
                $result = $license_service->activate($license_key);
                if (($result['status'] ?? '') === 'active') {
                    $license = peanut_get_license(); // Refresh
                    // Redirect to refresh the page and show updated menu
                    wp_redirect(admin_url('admin.php?page=peanut-settings&tab=license&activated=1'));
                    exit;
                } else {
                    echo '<div class="notice notice-error"><p>' . esc_html($result['message'] ?? __('Failed to activate license.', 'peanut-suite')) . '</p></div>';
                }
            }
            break;

        case 'integrations':
            // Check which section is being saved
            $section = sanitize_key($_POST['settings_section'] ?? '');

            if ($section === 'stripe') {
                $settings['stripe_secret_key'] = sanitize_text_field($_POST['stripe_secret_key'] ?? '');
                $settings['stripe_webhook_secret'] = sanitize_text_field($_POST['stripe_webhook_secret'] ?? '');
                $settings['invoice_currency'] = sanitize_text_field($_POST['invoice_currency'] ?? 'USD');
                $settings['invoice_due_days'] = absint($_POST['invoice_due_days'] ?? 30);
                $settings['invoice_footer'] = sanitize_textarea_field($_POST['invoice_footer'] ?? '');
                update_option('peanut_settings', $settings);
                echo '<div class="notice notice-success"><p>' . esc_html__('Stripe settings saved.', 'peanut-suite') . '</p></div>';
            }

            if ($section === 'ga4') {
                $settings['ga4_enabled'] = isset($_POST['ga4_enabled']) ? 1 : 0;
                $settings['ga4_measurement_id'] = sanitize_text_field($_POST['ga4_measurement_id'] ?? '');
                $settings['ga4_api_secret'] = sanitize_text_field($_POST['ga4_api_secret'] ?? '');
                update_option('peanut_settings', $settings);
                echo '<div class="notice notice-success"><p>' . esc_html__('Google Analytics 4 settings saved.', 'peanut-suite') . '</p></div>';
            }

            if ($section === 'gtm') {
                $settings['gtm_enabled'] = isset($_POST['gtm_enabled']) ? 1 : 0;
                $settings['gtm_container_id'] = strtoupper(sanitize_text_field($_POST['gtm_container_id'] ?? ''));
                $settings['gtm_track_contacts'] = isset($_POST['gtm_track_contacts']) ? 1 : 0;
                $settings['gtm_track_links'] = isset($_POST['gtm_track_links']) ? 1 : 0;
                $settings['gtm_track_popups'] = isset($_POST['gtm_track_popups']) ? 1 : 0;
                $settings['gtm_track_utm'] = isset($_POST['gtm_track_utm']) ? 1 : 0;
                update_option('peanut_settings', $settings);
                echo '<div class="notice notice-success"><p>' . esc_html__('Google Tag Manager settings saved.', 'peanut-suite') . '</p></div>';
            }

            if ($section === 'mailchimp') {
                $settings['mailchimp_enabled'] = isset($_POST['mailchimp_enabled']) ? 1 : 0;
                $settings['mailchimp_api_key'] = sanitize_text_field($_POST['mailchimp_api_key'] ?? '');
                $settings['mailchimp_list_id'] = sanitize_text_field($_POST['mailchimp_list_id'] ?? '');
                $settings['mailchimp_double_optin'] = isset($_POST['mailchimp_double_optin']) ? 1 : 0;
                $settings['mailchimp_tags'] = sanitize_text_field($_POST['mailchimp_tags'] ?? '');
                update_option('peanut_settings', $settings);
                echo '<div class="notice notice-success"><p>' . esc_html__('Mailchimp settings saved.', 'peanut-suite') . '</p></div>';
            }

            if ($section === 'convertkit') {
                $settings['convertkit_enabled'] = isset($_POST['convertkit_enabled']) ? 1 : 0;
                $settings['convertkit_api_key'] = sanitize_text_field($_POST['convertkit_api_key'] ?? '');
                $settings['convertkit_api_secret'] = sanitize_text_field($_POST['convertkit_api_secret'] ?? '');
                $settings['convertkit_form_id'] = sanitize_text_field($_POST['convertkit_form_id'] ?? '');
                $settings['convertkit_tags'] = sanitize_text_field($_POST['convertkit_tags'] ?? '');
                update_option('peanut_settings', $settings);
                echo '<div class="notice notice-success"><p>' . esc_html__('ConvertKit settings saved.', 'peanut-suite') . '</p></div>';
            }
            break;

        case 'notifications':
            $settings['email_notifications'] = isset($_POST['email_notifications']) ? 1 : 0;
            $settings['notification_email'] = sanitize_email($_POST['notification_email'] ?? '');
            update_option('peanut_settings', $settings);
            echo '<div class="notice notice-success"><p>' . esc_html__('Notification settings saved.', 'peanut-suite') . '</p></div>';
            break;

        case 'advanced':
            $settings['delete_data'] = isset($_POST['delete_data']) ? 1 : 0;
            $settings['debug_mode'] = isset($_POST['debug_mode']) ? 1 : 0;
            update_option('peanut_settings', $settings);
            echo '<div class="notice notice-success"><p>' . esc_html__('Advanced settings saved.', 'peanut-suite') . '</p></div>';
            break;
    }
}

// Show success message if just activated
if (isset($_GET['activated']) && $_GET['activated'] === '1') {
    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('License activated successfully! The page has been refreshed to show your new features.', 'peanut-suite') . '</p></div>';
}

// Tabs configuration
$tabs = [
    'general' => [
        'label' => __('General', 'peanut-suite'),
        'icon' => 'admin-settings',
    ],
    'license' => [
        'label' => __('License', 'peanut-suite'),
        'icon' => 'admin-network',
    ],
    'api' => [
        'label' => __('API', 'peanut-suite'),
        'icon' => 'rest-api',
    ],
    'integrations' => [
        'label' => __('Integrations', 'peanut-suite'),
        'icon' => 'admin-plugins',
    ],
    'notifications' => [
        'label' => __('Notifications', 'peanut-suite'),
        'icon' => 'email-alt',
    ],
    'advanced' => [
        'label' => __('Advanced', 'peanut-suite'),
        'icon' => 'admin-tools',
    ],
    'diagnostics' => [
        'label' => __('Diagnostics', 'peanut-suite'),
        'icon' => 'heart',
    ],
];
?>

<!-- Tabs Navigation -->
<div class="peanut-tabs">
    <?php foreach ($tabs as $tab_id => $tab): ?>
    <a href="<?php echo esc_url(admin_url('admin.php?page=peanut-settings&tab=' . $tab_id)); ?>"
       class="peanut-tab <?php echo $current_tab === $tab_id ? 'active' : ''; ?>">
        <span class="dashicons dashicons-<?php echo esc_attr($tab['icon']); ?>"></span>
        <?php echo esc_html($tab['label']); ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Tab Content -->
<div class="peanut-tab-content">
    <?php
    switch ($current_tab):
        case 'general':
    ?>
    <!-- General Settings -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title"><?php esc_html_e('General Settings', 'peanut-suite'); ?></h3>
        </div>
        <div class="peanut-card-body">
            <form method="post" class="peanut-form">
                <?php wp_nonce_field('peanut_save_settings', 'peanut_settings_nonce'); ?>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="site_name">
                        <?php esc_html_e('Site Name', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Display name for your site in reports and exports', 'peanut-suite')); ?>
                    </label>
                    <input type="text" id="site_name" name="site_name" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['site_name'] ?? get_bloginfo('name')); ?>"
                           placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="short_domain">
                        <?php esc_html_e('Short Link Domain', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Custom domain for short links (advanced)', 'peanut-suite')); ?>
                    </label>
                    <input type="text" id="short_domain" name="short_domain" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['short_domain'] ?? ''); ?>"
                           placeholder="<?php echo esc_attr(parse_url(home_url(), PHP_URL_HOST)); ?>">
                    <?php echo peanut_field_help(__('Leave blank to use your site domain. Custom domains require DNS configuration.', 'peanut-suite')); ?>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="timezone">
                        <?php esc_html_e('Timezone', 'peanut-suite'); ?>
                    </label>
                    <select id="timezone" name="timezone" class="peanut-form-select">
                        <option value="UTC" <?php selected($settings['timezone'] ?? '', 'UTC'); ?>>UTC</option>
                        <option value="America/New_York" <?php selected($settings['timezone'] ?? '', 'America/New_York'); ?>>Eastern Time (ET)</option>
                        <option value="America/Chicago" <?php selected($settings['timezone'] ?? '', 'America/Chicago'); ?>>Central Time (CT)</option>
                        <option value="America/Denver" <?php selected($settings['timezone'] ?? '', 'America/Denver'); ?>>Mountain Time (MT)</option>
                        <option value="America/Los_Angeles" <?php selected($settings['timezone'] ?? '', 'America/Los_Angeles'); ?>>Pacific Time (PT)</option>
                    </select>
                </div>

                <div class="peanut-form-actions">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Save Settings', 'peanut-suite'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
            break;

        case 'license':
    ?>
    <!-- License Settings -->
    <div class="peanut-grid peanut-grid-2">
        <div class="peanut-card">
            <div class="peanut-card-header">
                <h3 class="peanut-card-title"><?php esc_html_e('License Key', 'peanut-suite'); ?></h3>
            </div>
            <div class="peanut-card-body">
                <form method="post" class="peanut-form">
                    <?php wp_nonce_field('peanut_save_settings', 'peanut_settings_nonce'); ?>

                    <div class="peanut-license-status">
                        <div class="peanut-license-badge <?php echo esc_attr($license['status'] ?? 'inactive'); ?>">
                            <span class="dashicons dashicons-<?php echo ($license['status'] ?? '') === 'active' ? 'yes-alt' : 'warning'; ?>"></span>
                            <span class="status-text">
                                <?php
                                if (($license['status'] ?? '') === 'active') {
                                    echo esc_html(ucfirst($license['tier'] ?? 'Free'));
                                } else {
                                    esc_html_e('Not Activated', 'peanut-suite');
                                }
                                ?>
                            </span>
                        </div>
                    </div>

                    <div class="peanut-form-row">
                        <label class="peanut-form-label" for="license_key">
                            <?php esc_html_e('License Key', 'peanut-suite'); ?>
                        </label>
                        <input type="text" id="license_key" name="license_key" class="peanut-form-input"
                               value="<?php echo esc_attr($license['key'] ?? ''); ?>"
                               placeholder="PEANUT-XXXX-XXXX-XXXX">
                        <?php echo peanut_field_help(__('Enter your license key to unlock Pro or Agency features.', 'peanut-suite')); ?>
                    </div>

                    <div class="peanut-form-actions">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e('Activate License', 'peanut-suite'); ?>
                        </button>
                        <?php if (($license['status'] ?? '') === 'active'): ?>
                        <button type="button" class="button" id="peanut-deactivate-license">
                            <?php esc_html_e('Deactivate', 'peanut-suite'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="peanut-card">
            <div class="peanut-card-header">
                <h3 class="peanut-card-title"><?php esc_html_e('Feature Access', 'peanut-suite'); ?></h3>
            </div>
            <div class="peanut-card-body">
                <table class="peanut-features-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Feature', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Free', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Pro', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Agency', 'peanut-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?php esc_html_e('UTM Builder', 'peanut-suite'); ?></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Short Links', 'peanut-suite'); ?></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Contacts CRM', 'peanut-suite'); ?></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Webhooks', 'peanut-suite'); ?></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Visitor Tracking', 'peanut-suite'); ?></td>
                            <td><span class="dashicons dashicons-no"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Attribution', 'peanut-suite'); ?></td>
                            <td><span class="dashicons dashicons-no"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Analytics Dashboard', 'peanut-suite'); ?></td>
                            <td><span class="dashicons dashicons-no"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Conversion Popups', 'peanut-suite'); ?></td>
                            <td><span class="dashicons dashicons-no"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Multi-Site Monitor', 'peanut-suite'); ?></td>
                            <td><span class="dashicons dashicons-no"></span></td>
                            <td><span class="dashicons dashicons-no"></span></td>
                            <td><span class="dashicons dashicons-yes"></span></td>
                        </tr>
                    </tbody>
                </table>

                <?php if (($license['tier'] ?? 'free') === 'free'): ?>
                <div class="peanut-upgrade-cta">
                    <p><?php esc_html_e('Unlock all features with a Pro or Agency license.', 'peanut-suite'); ?></p>
                    <a href="https://peanutgraphic.com/peanut-suite/pricing" target="_blank" class="button button-primary">
                        <?php esc_html_e('View Pricing', 'peanut-suite'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
            break;

        case 'api':
            // Get current site key
            $site_key = get_option('peanut_connect_site_key', '');
    ?>
    <!-- API Settings -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title"><?php esc_html_e('Site Connection Key', 'peanut-suite'); ?></h3>
            <p class="peanut-card-description"><?php esc_html_e('Allow this site to be monitored from a central Peanut Suite manager installation.', 'peanut-suite'); ?></p>
        </div>
        <div class="peanut-card-body">
            <div class="peanut-form">
                <div class="peanut-form-row">
                    <label class="peanut-form-label">
                        <?php esc_html_e('API Key', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Use this key to connect this site to a Peanut Suite manager for remote monitoring.', 'peanut-suite')); ?>
                    </label>
                    <?php if ($site_key): ?>
                        <div class="peanut-api-key-display">
                            <input type="text" id="peanut-site-key" class="peanut-form-input"
                                   value="<?php echo esc_attr($site_key); ?>" readonly
                                   style="font-family: monospace; background: #f5f5f5;">
                            <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('peanut-site-key').value); PeanutAdmin.notify('<?php esc_attr_e('Key copied to clipboard', 'peanut-suite'); ?>', 'success');">
                                <span class="dashicons dashicons-clipboard" style="vertical-align: middle;"></span>
                                <?php esc_html_e('Copy', 'peanut-suite'); ?>
                            </button>
                        </div>
                        <?php echo peanut_field_help(__('Copy this key and paste it into the manager site when adding this site to monitor.', 'peanut-suite')); ?>
                    <?php else: ?>
                        <p class="peanut-text-muted"><?php esc_html_e('No API key generated yet. Click the button below to generate one.', 'peanut-suite'); ?></p>
                    <?php endif; ?>
                </div>

                <div class="peanut-form-actions" style="margin-top: 16px;">
                    <button type="button" id="peanut-generate-key" class="button <?php echo $site_key ? '' : 'button-primary'; ?>">
                        <span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
                        <?php echo $site_key ? esc_html__('Regenerate Key', 'peanut-suite') : esc_html__('Generate API Key', 'peanut-suite'); ?>
                    </button>
                    <?php if ($site_key): ?>
                        <span class="peanut-text-muted" style="margin-left: 12px;">
                            <?php esc_html_e('Warning: Regenerating will disconnect any sites currently using this key.', 'peanut-suite'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- API Endpoints Info -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title"><?php esc_html_e('API Endpoints', 'peanut-suite'); ?></h3>
        </div>
        <div class="peanut-card-body">
            <p><?php esc_html_e('This site exposes the following API endpoints for remote monitoring:', 'peanut-suite'); ?></p>
            <table class="peanut-table" style="margin-top: 16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Endpoint', 'peanut-suite'); ?></th>
                        <th><?php esc_html_e('Description', 'peanut-suite'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code><?php echo esc_html(rest_url('peanut-connect/v1/verify')); ?></code></td>
                        <td><?php esc_html_e('Verify connection and get site info', 'peanut-suite'); ?></td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html(rest_url('peanut-connect/v1/health')); ?></code></td>
                        <td><?php esc_html_e('Get detailed health data', 'peanut-suite'); ?></td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html(rest_url('peanut-connect/v1/stats')); ?></code></td>
                        <td><?php esc_html_e('Get Peanut Suite statistics', 'peanut-suite'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#peanut-generate-key').on('click', function() {
            var $btn = $(this);
            var originalText = $btn.html();

            $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spinning" style="vertical-align: middle;"></span> <?php esc_html_e('Generating...', 'peanut-suite'); ?>');

            $.ajax({
                url: '<?php echo esc_url(rest_url('peanut-connect/v1/generate-key')); ?>',
                type: 'POST',
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
                },
                success: function(response) {
                    if (response.success) {
                        PeanutAdmin.notify(response.message, 'success');
                        location.reload();
                    } else {
                        PeanutAdmin.notify('<?php esc_html_e('Failed to generate key', 'peanut-suite'); ?>', 'error');
                        $btn.prop('disabled', false).html(originalText);
                    }
                },
                error: function() {
                    PeanutAdmin.notify('<?php esc_html_e('Failed to generate key', 'peanut-suite'); ?>', 'error');
                    $btn.prop('disabled', false).html(originalText);
                }
            });
        });
    });
    </script>
    <?php
            break;

        case 'integrations':
    ?>
    <!-- Stripe Integration (Agency) -->
    <?php if (peanut_is_agency()): ?>
    <div class="peanut-card" style="margin-bottom: 24px;">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title">
                <span style="color: #635bff;">
                    <svg width="20" height="20" viewBox="0 0 32 32" fill="currentColor" style="vertical-align: middle; margin-right: 8px;">
                        <path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-6.99-2.109l-.9 5.555C5.175 22.99 8.385 24 11.714 24c2.641 0 4.843-.624 6.328-1.813 1.664-1.305 2.525-3.236 2.525-5.732 0-4.128-2.524-5.851-6.594-7.305h.003z"/>
                    </svg>
                </span>
                <?php esc_html_e('Stripe Integration', 'peanut-suite'); ?>
                <span class="peanut-badge peanut-badge-info" style="margin-left: 8px;"><?php esc_html_e('Agency', 'peanut-suite'); ?></span>
            </h3>
            <p class="peanut-card-description"><?php esc_html_e('Connect Stripe to create and send professional invoices to clients.', 'peanut-suite'); ?></p>
        </div>
        <div class="peanut-card-body">
            <form method="post" class="peanut-form">
                <?php wp_nonce_field('peanut_save_settings', 'peanut_settings_nonce'); ?>
                <input type="hidden" name="settings_section" value="stripe">

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="stripe_secret_key">
                        <?php esc_html_e('Secret Key', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Your Stripe secret key (starts with sk_live_ or sk_test_)', 'peanut-suite')); ?>
                    </label>
                    <input type="password" id="stripe_secret_key" name="stripe_secret_key" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['stripe_secret_key'] ?? ''); ?>"
                           placeholder="sk_live_...">
                    <?php echo peanut_field_help(__('Find this in your Stripe Dashboard > Developers > API keys', 'peanut-suite')); ?>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="stripe_webhook_secret">
                        <?php esc_html_e('Webhook Secret', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Webhook signing secret for payment notifications (starts with whsec_)', 'peanut-suite')); ?>
                    </label>
                    <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['stripe_webhook_secret'] ?? ''); ?>"
                           placeholder="whsec_...">
                    <div class="peanut-field-help">
                        <?php esc_html_e('Create a webhook in Stripe pointing to:', 'peanut-suite'); ?>
                        <code style="display: block; margin-top: 4px; padding: 8px; background: #f1f5f9; border-radius: 4px; font-size: 12px;">
                            <?php echo esc_html(rest_url(PEANUT_API_NAMESPACE . '/webhooks/stripe')); ?>
                        </code>
                    </div>
                </div>

                <div class="peanut-form-row" style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                    <label class="peanut-form-label" for="invoice_currency">
                        <?php esc_html_e('Default Currency', 'peanut-suite'); ?>
                    </label>
                    <select id="invoice_currency" name="invoice_currency" class="peanut-form-select">
                        <option value="USD" <?php selected($settings['invoice_currency'] ?? '', 'USD'); ?>>USD - US Dollar</option>
                        <option value="EUR" <?php selected($settings['invoice_currency'] ?? '', 'EUR'); ?>>EUR - Euro</option>
                        <option value="GBP" <?php selected($settings['invoice_currency'] ?? '', 'GBP'); ?>>GBP - British Pound</option>
                        <option value="CAD" <?php selected($settings['invoice_currency'] ?? '', 'CAD'); ?>>CAD - Canadian Dollar</option>
                        <option value="AUD" <?php selected($settings['invoice_currency'] ?? '', 'AUD'); ?>>AUD - Australian Dollar</option>
                    </select>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="invoice_due_days">
                        <?php esc_html_e('Default Payment Terms', 'peanut-suite'); ?>
                    </label>
                    <select id="invoice_due_days" name="invoice_due_days" class="peanut-form-select">
                        <option value="7" <?php selected($settings['invoice_due_days'] ?? 30, 7); ?>><?php esc_html_e('Net 7 (7 days)', 'peanut-suite'); ?></option>
                        <option value="14" <?php selected($settings['invoice_due_days'] ?? 30, 14); ?>><?php esc_html_e('Net 14 (14 days)', 'peanut-suite'); ?></option>
                        <option value="30" <?php selected($settings['invoice_due_days'] ?? 30, 30); ?>><?php esc_html_e('Net 30 (30 days)', 'peanut-suite'); ?></option>
                        <option value="45" <?php selected($settings['invoice_due_days'] ?? 30, 45); ?>><?php esc_html_e('Net 45 (45 days)', 'peanut-suite'); ?></option>
                        <option value="60" <?php selected($settings['invoice_due_days'] ?? 30, 60); ?>><?php esc_html_e('Net 60 (60 days)', 'peanut-suite'); ?></option>
                    </select>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="invoice_footer">
                        <?php esc_html_e('Default Invoice Footer', 'peanut-suite'); ?>
                    </label>
                    <textarea id="invoice_footer" name="invoice_footer" class="peanut-form-textarea" rows="2"
                              placeholder="<?php esc_attr_e('Thank you for your business!', 'peanut-suite'); ?>"><?php echo esc_textarea($settings['invoice_footer'] ?? ''); ?></textarea>
                </div>

                <div class="peanut-form-actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save Stripe Settings', 'peanut-suite'); ?></button>
                    <?php if (!empty($settings['stripe_secret_key'])): ?>
                        <button type="button" class="button" id="test-stripe-connection"><?php esc_html_e('Test Connection', 'peanut-suite'); ?></button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Google Analytics 4 Integration -->
    <div class="peanut-card" style="margin-bottom: 24px;">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title">
                <span style="color: #F9AB00;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="vertical-align: middle; margin-right: 8px;">
                        <path d="M22.84 2.998v17.999a3.003 3.003 0 01-3.003 3.003H4.163a3.003 3.003 0 01-3.003-3.003V2.998A2.998 2.998 0 014.158 0h15.68a2.998 2.998 0 012.998 2.998zM8.56 20.207a2.063 2.063 0 100-4.125 2.063 2.063 0 000 4.125zm0-6.048c1.116 0 2.063-.947 2.063-2.063V5.855a2.063 2.063 0 00-4.125 0v6.241c0 1.116.947 2.063 2.063 2.063zm6.88 6.048a2.063 2.063 0 100-4.125 2.063 2.063 0 000 4.125zm0-6.048c1.116 0 2.063-.947 2.063-2.063V5.855a2.063 2.063 0 00-4.125 0v6.241c0 1.116.947 2.063 2.063 2.063z"/>
                    </svg>
                </span>
                <?php esc_html_e('Google Analytics 4', 'peanut-suite'); ?>
                <?php if (!empty($settings['ga4_enabled'])): ?>
                    <span class="peanut-badge peanut-badge-success" style="margin-left: 8px;"><?php esc_html_e('Active', 'peanut-suite'); ?></span>
                <?php endif; ?>
            </h3>
            <p class="peanut-card-description"><?php esc_html_e('Send events to GA4 via Measurement Protocol for server-side tracking.', 'peanut-suite'); ?></p>
        </div>
        <div class="peanut-card-body">
            <form method="post" class="peanut-form">
                <?php wp_nonce_field('peanut_save_settings', 'peanut_settings_nonce'); ?>
                <input type="hidden" name="settings_section" value="ga4">

                <div class="peanut-toggle-row" style="margin-bottom: 16px;">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="ga4_enabled" value="1" <?php checked($settings['ga4_enabled'] ?? false); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('Enable GA4 Integration', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Send events when contacts are created, links are clicked, etc.', 'peanut-suite'); ?></p>
                    </div>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="ga4_measurement_id">
                        <?php esc_html_e('Measurement ID', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Your GA4 Measurement ID (starts with G-)', 'peanut-suite')); ?>
                    </label>
                    <input type="text" id="ga4_measurement_id" name="ga4_measurement_id" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['ga4_measurement_id'] ?? ''); ?>"
                           placeholder="G-XXXXXXXXXX">
                    <?php echo peanut_field_help(__('Find this in GA4 > Admin > Data Streams > Your Stream', 'peanut-suite')); ?>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="ga4_api_secret">
                        <?php esc_html_e('API Secret', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Measurement Protocol API secret for server-side events', 'peanut-suite')); ?>
                    </label>
                    <input type="password" id="ga4_api_secret" name="ga4_api_secret" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['ga4_api_secret'] ?? ''); ?>"
                           placeholder="••••••••">
                    <?php echo peanut_field_help(__('Create in GA4 > Admin > Data Streams > Measurement Protocol API secrets', 'peanut-suite')); ?>
                </div>

                <div class="peanut-form-actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save GA4 Settings', 'peanut-suite'); ?></button>
                    <?php if (!empty($settings['ga4_measurement_id']) && !empty($settings['ga4_api_secret'])): ?>
                        <button type="button" class="button peanut-test-integration" data-integration="ga4"><?php esc_html_e('Test Connection', 'peanut-suite'); ?></button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Google Tag Manager Integration -->
    <div class="peanut-card" style="margin-bottom: 24px;">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title">
                <span style="color: #4285F4;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="vertical-align: middle; margin-right: 8px;">
                        <path d="M12 0L1.5 6v12L12 24l10.5-6V6L12 0zm0 2.25L20.25 7.5v9L12 21.75 3.75 16.5v-9L12 2.25z"/>
                        <path d="M12 6.75L6.75 9.75v4.5L12 17.25l5.25-3v-4.5L12 6.75zm0 2.25l3 1.5v3l-3 1.5-3-1.5v-3l3-1.5z"/>
                    </svg>
                </span>
                <?php esc_html_e('Google Tag Manager', 'peanut-suite'); ?>
                <?php if (!empty($settings['gtm_enabled'])): ?>
                    <span class="peanut-badge peanut-badge-success" style="margin-left: 8px;"><?php esc_html_e('Active', 'peanut-suite'); ?></span>
                <?php endif; ?>
            </h3>
            <p class="peanut-card-description"><?php esc_html_e('Add GTM container and push dataLayer events for all Peanut Suite actions.', 'peanut-suite'); ?></p>
        </div>
        <div class="peanut-card-body">
            <form method="post" class="peanut-form">
                <?php wp_nonce_field('peanut_save_settings', 'peanut_settings_nonce'); ?>
                <input type="hidden" name="settings_section" value="gtm">

                <div class="peanut-toggle-row" style="margin-bottom: 16px;">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="gtm_enabled" value="1" <?php checked($settings['gtm_enabled'] ?? false); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('Enable GTM Integration', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Inject GTM container code and push events to dataLayer.', 'peanut-suite'); ?></p>
                    </div>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="gtm_container_id">
                        <?php esc_html_e('Container ID', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Your GTM Container ID (starts with GTM-)', 'peanut-suite')); ?>
                    </label>
                    <input type="text" id="gtm_container_id" name="gtm_container_id" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['gtm_container_id'] ?? ''); ?>"
                           placeholder="GTM-XXXXXXX">
                </div>

                <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid #e2e8f0;">
                    <label class="peanut-form-label" style="margin-bottom: 12px;"><?php esc_html_e('Events to Track', 'peanut-suite'); ?></label>

                    <div class="peanut-toggle-row" style="margin-bottom: 8px;">
                        <label class="peanut-toggle">
                            <input type="checkbox" name="gtm_track_contacts" value="1" <?php checked($settings['gtm_track_contacts'] ?? true); ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="toggle-label">
                            <strong><?php esc_html_e('Contact Events', 'peanut-suite'); ?></strong>
                            <p><?php esc_html_e('peanut_lead_captured when contacts are created', 'peanut-suite'); ?></p>
                        </div>
                    </div>

                    <div class="peanut-toggle-row" style="margin-bottom: 8px;">
                        <label class="peanut-toggle">
                            <input type="checkbox" name="gtm_track_links" value="1" <?php checked($settings['gtm_track_links'] ?? true); ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="toggle-label">
                            <strong><?php esc_html_e('Link Click Events', 'peanut-suite'); ?></strong>
                            <p><?php esc_html_e('peanut_link_click when short links are clicked', 'peanut-suite'); ?></p>
                        </div>
                    </div>

                    <div class="peanut-toggle-row" style="margin-bottom: 8px;">
                        <label class="peanut-toggle">
                            <input type="checkbox" name="gtm_track_popups" value="1" <?php checked($settings['gtm_track_popups'] ?? true); ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="toggle-label">
                            <strong><?php esc_html_e('Popup Events', 'peanut-suite'); ?></strong>
                            <p><?php esc_html_e('peanut_popup_view and peanut_popup_conversion', 'peanut-suite'); ?></p>
                        </div>
                    </div>

                    <div class="peanut-toggle-row">
                        <label class="peanut-toggle">
                            <input type="checkbox" name="gtm_track_utm" value="1" <?php checked($settings['gtm_track_utm'] ?? true); ?>>
                            <span class="toggle-slider"></span>
                        </label>
                        <div class="toggle-label">
                            <strong><?php esc_html_e('UTM Events', 'peanut-suite'); ?></strong>
                            <p><?php esc_html_e('peanut_utm_click when UTM links are used', 'peanut-suite'); ?></p>
                        </div>
                    </div>
                </div>

                <div class="peanut-form-actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save GTM Settings', 'peanut-suite'); ?></button>
                    <?php if (!empty($settings['gtm_container_id'])): ?>
                        <button type="button" class="button peanut-test-integration" data-integration="gtm"><?php esc_html_e('Verify Container', 'peanut-suite'); ?></button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Mailchimp Integration -->
    <div class="peanut-card" style="margin-bottom: 24px;">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title">
                <span style="color: #FFE01B;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="vertical-align: middle; margin-right: 8px;">
                        <path d="M18.89 12.71c-.17-.17-.41-.18-.59-.02-.23.21-.31.6-.55.81-.45.38-1.07.29-1.64-.02-.42-.22-.63-.49-.63-.96 0-.84.37-1.37 1.11-1.79.41-.23.87-.35 1.39-.39.14-.01.31-.02.45 0 .38.05.67.27.76.64.05.21 0 .44-.11.63-.16.28-.45.42-.75.44-.21.02-.41-.06-.54-.24-.07-.1-.12-.2-.11-.33.01-.22.18-.4.38-.45.15-.04.29.02.39.14.06.08.04.18-.04.24-.07.05-.13.11-.14.2-.01.16.11.28.27.27.24-.01.44-.21.44-.51-.01-.35-.25-.6-.57-.67-.21-.05-.42-.03-.62.05-.45.17-.74.47-.87.93-.07.24-.07.48.01.71.13.36.38.61.73.76.43.19.87.21 1.31.06.4-.13.7-.4.89-.77.14-.27.17-.55.07-.84-.07-.22-.2-.4-.39-.54-.4-.3-.87-.39-1.36-.32-.73.1-1.37.4-1.93.87-.81.68-1.22 1.53-1.23 2.58-.01.69.19 1.31.62 1.85.52.66 1.22 1.04 2.03 1.18.79.14 1.54.01 2.25-.37.56-.3 1.01-.72 1.38-1.22.03-.04.06-.08.1-.11.21-.17.47-.14.64.07.17.22.14.47-.08.68-.85.87-1.87 1.41-3.09 1.58-.91.13-1.78-.01-2.58-.46-.91-.52-1.51-1.27-1.79-2.28-.28-1.01-.17-1.98.34-2.88.42-.74 1.02-1.31 1.78-1.7.72-.37 1.49-.54 2.31-.48.61.05 1.16.25 1.62.65.47.41.7.92.68 1.55-.02.64-.31 1.15-.82 1.51-.3.21-.64.32-1.01.32-.63 0-1.08-.32-1.3-.89-.06-.16-.09-.32-.08-.49.03-.37.24-.62.56-.78.2-.1.37-.06.5.12.03.04.04.08.06.12.04.11-.02.21-.12.27-.13.09-.13.14-.03.26.18.22.49.25.72.08.28-.2.38-.57.24-.88-.2-.44-.69-.64-1.18-.48-.54.17-.88.55-.97 1.11-.07.42.04.8.32 1.12.43.5 1.08.67 1.72.44.52-.19.91-.54 1.15-1.04.17-.35.22-.72.14-1.1-.12-.54-.43-.95-.9-1.24-.36-.23-.75-.34-1.18-.34-.53 0-1.01.15-1.45.46-.09.07-.18.14-.26.22z"/>
                    </svg>
                </span>
                <?php esc_html_e('Mailchimp', 'peanut-suite'); ?>
                <?php if (!empty($settings['mailchimp_enabled'])): ?>
                    <span class="peanut-badge peanut-badge-success" style="margin-left: 8px;"><?php esc_html_e('Active', 'peanut-suite'); ?></span>
                <?php endif; ?>
            </h3>
            <p class="peanut-card-description"><?php esc_html_e('Automatically sync new contacts to your Mailchimp audience.', 'peanut-suite'); ?></p>
        </div>
        <div class="peanut-card-body">
            <form method="post" class="peanut-form">
                <?php wp_nonce_field('peanut_save_settings', 'peanut_settings_nonce'); ?>
                <input type="hidden" name="settings_section" value="mailchimp">

                <div class="peanut-toggle-row" style="margin-bottom: 16px;">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="mailchimp_enabled" value="1" <?php checked($settings['mailchimp_enabled'] ?? false); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('Enable Mailchimp Sync', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('New contacts will be added to your selected Mailchimp audience.', 'peanut-suite'); ?></p>
                    </div>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="mailchimp_api_key">
                        <?php esc_html_e('API Key', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Your Mailchimp API key (ends with -usX)', 'peanut-suite')); ?>
                    </label>
                    <input type="password" id="mailchimp_api_key" name="mailchimp_api_key" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['mailchimp_api_key'] ?? ''); ?>"
                           placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us19">
                    <?php echo peanut_field_help(__('Find in Mailchimp > Account > Extras > API keys', 'peanut-suite')); ?>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="mailchimp_list_id">
                        <?php esc_html_e('Audience ID', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('The ID of the Mailchimp audience/list to add contacts to', 'peanut-suite')); ?>
                    </label>
                    <input type="text" id="mailchimp_list_id" name="mailchimp_list_id" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['mailchimp_list_id'] ?? ''); ?>"
                           placeholder="abc1234def">
                    <?php echo peanut_field_help(__('Find in Mailchimp > Audience > Settings > Audience name and defaults', 'peanut-suite')); ?>
                </div>

                <div class="peanut-toggle-row" style="margin-top: 16px;">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="mailchimp_double_optin" value="1" <?php checked($settings['mailchimp_double_optin'] ?? false); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('Double Opt-in', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Require email confirmation before adding to audience.', 'peanut-suite'); ?></p>
                    </div>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="mailchimp_tags">
                        <?php esc_html_e('Default Tags', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Comma-separated tags to apply to new subscribers', 'peanut-suite')); ?>
                    </label>
                    <input type="text" id="mailchimp_tags" name="mailchimp_tags" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['mailchimp_tags'] ?? ''); ?>"
                           placeholder="peanut-suite, website-lead">
                </div>

                <div class="peanut-form-actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save Mailchimp Settings', 'peanut-suite'); ?></button>
                    <?php if (!empty($settings['mailchimp_api_key'])): ?>
                        <button type="button" class="button peanut-test-integration" data-integration="mailchimp"><?php esc_html_e('Test Connection', 'peanut-suite'); ?></button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- ConvertKit Integration -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title">
                <span style="color: #FB6970;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" style="vertical-align: middle; margin-right: 8px;">
                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                    </svg>
                </span>
                <?php esc_html_e('ConvertKit', 'peanut-suite'); ?>
                <?php if (!empty($settings['convertkit_enabled'])): ?>
                    <span class="peanut-badge peanut-badge-success" style="margin-left: 8px;"><?php esc_html_e('Active', 'peanut-suite'); ?></span>
                <?php endif; ?>
            </h3>
            <p class="peanut-card-description"><?php esc_html_e('Send new contacts to ConvertKit forms automatically.', 'peanut-suite'); ?></p>
        </div>
        <div class="peanut-card-body">
            <form method="post" class="peanut-form">
                <?php wp_nonce_field('peanut_save_settings', 'peanut_settings_nonce'); ?>
                <input type="hidden" name="settings_section" value="convertkit">

                <div class="peanut-toggle-row" style="margin-bottom: 16px;">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="convertkit_enabled" value="1" <?php checked($settings['convertkit_enabled'] ?? false); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('Enable ConvertKit Sync', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('New contacts will be subscribed via your selected form.', 'peanut-suite'); ?></p>
                    </div>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="convertkit_api_key">
                        <?php esc_html_e('API Key', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Your ConvertKit API key', 'peanut-suite')); ?>
                    </label>
                    <input type="password" id="convertkit_api_key" name="convertkit_api_key" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['convertkit_api_key'] ?? ''); ?>"
                           placeholder="••••••••">
                    <?php echo peanut_field_help(__('Find in ConvertKit > Settings > Advanced > API', 'peanut-suite')); ?>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="convertkit_api_secret">
                        <?php esc_html_e('API Secret', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Your ConvertKit API secret (optional, for some features)', 'peanut-suite')); ?>
                    </label>
                    <input type="password" id="convertkit_api_secret" name="convertkit_api_secret" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['convertkit_api_secret'] ?? ''); ?>"
                           placeholder="••••••••">
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="convertkit_form_id">
                        <?php esc_html_e('Form ID', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('The ID of the ConvertKit form to subscribe contacts to', 'peanut-suite')); ?>
                    </label>
                    <input type="text" id="convertkit_form_id" name="convertkit_form_id" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['convertkit_form_id'] ?? ''); ?>"
                           placeholder="1234567">
                    <?php echo peanut_field_help(__('Find the form ID in the form embed code or API', 'peanut-suite')); ?>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="convertkit_tags">
                        <?php esc_html_e('Tag IDs', 'peanut-suite'); ?>
                        <?php echo peanut_tooltip(__('Comma-separated tag IDs to apply to new subscribers', 'peanut-suite')); ?>
                    </label>
                    <input type="text" id="convertkit_tags" name="convertkit_tags" class="peanut-form-input"
                           value="<?php echo esc_attr($settings['convertkit_tags'] ?? ''); ?>"
                           placeholder="123456, 789012">
                    <?php echo peanut_field_help(__('Find tag IDs in ConvertKit > Grow > Subscribers > Tags', 'peanut-suite')); ?>
                </div>

                <div class="peanut-form-actions">
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save ConvertKit Settings', 'peanut-suite'); ?></button>
                    <?php if (!empty($settings['convertkit_api_key'])): ?>
                        <button type="button" class="button peanut-test-integration" data-integration="convertkit"><?php esc_html_e('Test Connection', 'peanut-suite'); ?></button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
    <?php
            break;

        case 'notifications':
    ?>
    <!-- Notifications -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title"><?php esc_html_e('Email Notifications', 'peanut-suite'); ?></h3>
        </div>
        <div class="peanut-card-body">
            <form method="post" class="peanut-form">
                <?php wp_nonce_field('peanut_save_settings', 'peanut_settings_nonce'); ?>

                <div class="peanut-toggle-row">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="notify_new_contact" value="1"
                               <?php checked($settings['notify_new_contact'] ?? false); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('New Contact Captured', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Receive an email when a new contact is added.', 'peanut-suite'); ?></p>
                    </div>
                </div>

                <div class="peanut-toggle-row">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="notify_popup_milestone" value="1"
                               <?php checked($settings['notify_popup_milestone'] ?? false); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('Popup Milestones', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Get notified when popups reach conversion milestones.', 'peanut-suite'); ?></p>
                    </div>
                </div>

                <div class="peanut-toggle-row">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="notify_weekly_report" value="1"
                               <?php checked($settings['notify_weekly_report'] ?? false); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('Weekly Report', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Receive a weekly summary of your marketing performance.', 'peanut-suite'); ?></p>
                    </div>
                </div>

                <div class="peanut-form-actions">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Save Notifications', 'peanut-suite'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php
            break;

        case 'advanced':
    ?>
    <!-- Advanced Settings -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title"><?php esc_html_e('Data Management', 'peanut-suite'); ?></h3>
        </div>
        <div class="peanut-card-body">
            <div class="peanut-data-actions">
                <div class="peanut-data-action">
                    <div class="action-info">
                        <strong><?php esc_html_e('Export All Data', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Download all your data as a CSV file.', 'peanut-suite'); ?></p>
                    </div>
                    <button type="button" class="button" id="peanut-export-data">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e('Export', 'peanut-suite'); ?>
                    </button>
                </div>

                <div class="peanut-data-action">
                    <div class="action-info">
                        <strong><?php esc_html_e('Clear Cache', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Clear cached data and statistics.', 'peanut-suite'); ?></p>
                    </div>
                    <button type="button" class="button" id="peanut-clear-cache">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Clear Cache', 'peanut-suite'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="peanut-card peanut-danger-zone">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title">
                <span class="dashicons dashicons-warning"></span>
                <?php esc_html_e('Danger Zone', 'peanut-suite'); ?>
            </h3>
        </div>
        <div class="peanut-card-body">
            <div class="peanut-data-action danger">
                <div class="action-info">
                    <strong><?php esc_html_e('Delete All Data', 'peanut-suite'); ?></strong>
                    <p><?php esc_html_e('Permanently delete all Peanut Suite data. This cannot be undone.', 'peanut-suite'); ?></p>
                </div>
                <button type="button" class="button button-link-delete" id="peanut-delete-data"
                        data-confirm="<?php esc_attr_e('Are you absolutely sure? This will permanently delete all UTM codes, links, contacts, and analytics data. This action CANNOT be undone.', 'peanut-suite'); ?>">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e('Delete All Data', 'peanut-suite'); ?>
                </button>
            </div>
        </div>
    </div>

    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title"><?php esc_html_e('System Information', 'peanut-suite'); ?></h3>
        </div>
        <div class="peanut-card-body">
            <table class="peanut-system-info">
                <tr>
                    <td><?php esc_html_e('Plugin Version', 'peanut-suite'); ?></td>
                    <td><code><?php echo esc_html(PEANUT_VERSION); ?></code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('WordPress Version', 'peanut-suite'); ?></td>
                    <td><code><?php echo esc_html(get_bloginfo('version')); ?></code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('PHP Version', 'peanut-suite'); ?></td>
                    <td><code><?php echo esc_html(PHP_VERSION); ?></code></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('License Tier', 'peanut-suite'); ?></td>
                    <td><code><?php echo esc_html(ucfirst($license['tier'] ?? 'free')); ?></code></td>
                </tr>
            </table>
        </div>
    </div>
    <?php
            break;

        case 'diagnostics':
            // Run diagnostics
            $diagnostics = peanut_run_diagnostics();
    ?>
    <!-- Diagnostics -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title">
                <span class="dashicons dashicons-heart"></span>
                <?php esc_html_e('System Health Check', 'peanut-suite'); ?>
            </h3>
        </div>
        <div class="peanut-card-body">
            <div class="peanut-diagnostics-grid">
                <?php foreach ($diagnostics['checks'] as $check): ?>
                <div class="peanut-diagnostic-item <?php echo esc_attr($check['status']); ?>">
                    <div class="diagnostic-icon">
                        <?php if ($check['status'] === 'pass'): ?>
                            <span class="dashicons dashicons-yes-alt"></span>
                        <?php elseif ($check['status'] === 'warning'): ?>
                            <span class="dashicons dashicons-warning"></span>
                        <?php else: ?>
                            <span class="dashicons dashicons-dismiss"></span>
                        <?php endif; ?>
                    </div>
                    <div class="diagnostic-info">
                        <strong><?php echo esc_html($check['name']); ?></strong>
                        <p><?php echo esc_html($check['message']); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="peanut-diagnostic-actions">
                <button type="button" class="button" id="peanut-run-diagnostics">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Re-run Diagnostics', 'peanut-suite'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Error Log -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title">
                <span class="dashicons dashicons-warning"></span>
                <?php esc_html_e('Error Log', 'peanut-suite'); ?>
            </h3>
        </div>
        <div class="peanut-card-body">
            <?php
            $error_log = get_option('peanut_error_log', []);
            if (empty($error_log)):
            ?>
            <div class="peanut-empty-state" style="padding: 30px;">
                <span class="dashicons dashicons-yes-alt" style="color: #10b981; font-size: 32px; width: 32px; height: 32px;"></span>
                <h4><?php esc_html_e('No Errors Logged', 'peanut-suite'); ?></h4>
                <p><?php esc_html_e('Everything is running smoothly. No errors have been recorded.', 'peanut-suite'); ?></p>
            </div>
            <?php else: ?>
            <div class="peanut-error-log">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Type', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Message', 'peanut-suite'); ?></th>
                            <th><?php esc_html_e('Context', 'peanut-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse(array_slice($error_log, -50)) as $error): ?>
                        <tr>
                            <td><code><?php echo esc_html(date('Y-m-d H:i:s', $error['time'])); ?></code></td>
                            <td>
                                <span class="peanut-badge peanut-badge-<?php echo $error['type'] === 'error' ? 'error' : 'warning'; ?>">
                                    <?php echo esc_html(ucfirst($error['type'])); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($error['message']); ?></td>
                            <td><code><?php echo esc_html($error['context'] ?? '-'); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="peanut-diagnostic-actions" style="margin-top: 15px;">
                <button type="button" class="button" id="peanut-clear-error-log">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e('Clear Error Log', 'peanut-suite'); ?>
                </button>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- JavaScript Console -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3 class="peanut-card-title">
                <span class="dashicons dashicons-editor-code"></span>
                <?php esc_html_e('JavaScript Status', 'peanut-suite'); ?>
            </h3>
        </div>
        <div class="peanut-card-body">
            <div id="peanut-js-diagnostics">
                <p><?php esc_html_e('Checking JavaScript components...', 'peanut-suite'); ?></p>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Check JavaScript components
        var jsChecks = [];

        // Check jQuery
        jsChecks.push({
            name: 'jQuery',
            status: typeof jQuery !== 'undefined' ? 'pass' : 'fail',
            version: typeof jQuery !== 'undefined' ? jQuery.fn.jquery : 'Not loaded'
        });

        // Check Chart.js
        jsChecks.push({
            name: 'Chart.js',
            status: typeof Chart !== 'undefined' ? 'pass' : 'fail',
            version: typeof Chart !== 'undefined' ? Chart.version : 'Not loaded'
        });

        // Check PeanutCharts
        jsChecks.push({
            name: 'PeanutCharts',
            status: typeof PeanutCharts !== 'undefined' ? 'pass' : 'fail',
            version: typeof PeanutCharts !== 'undefined' ? 'Loaded' : 'Not loaded'
        });

        // Check PeanutAdmin
        jsChecks.push({
            name: 'PeanutAdmin',
            status: typeof PeanutAdmin !== 'undefined' ? 'pass' : 'fail',
            version: typeof PeanutAdmin !== 'undefined' ? 'Loaded' : 'Not loaded'
        });

        // Check peanutAdmin config
        jsChecks.push({
            name: 'peanutAdmin Config',
            status: typeof peanutAdmin !== 'undefined' ? 'pass' : 'fail',
            version: typeof peanutAdmin !== 'undefined' ? 'v' + peanutAdmin.version : 'Not loaded'
        });

        var html = '<div class="peanut-diagnostics-grid">';
        jsChecks.forEach(function(check) {
            html += '<div class="peanut-diagnostic-item ' + check.status + '">';
            html += '<div class="diagnostic-icon">';
            html += check.status === 'pass' ?
                '<span class="dashicons dashicons-yes-alt"></span>' :
                '<span class="dashicons dashicons-dismiss"></span>';
            html += '</div>';
            html += '<div class="diagnostic-info">';
            html += '<strong>' + check.name + '</strong>';
            html += '<p>' + check.version + '</p>';
            html += '</div>';
            html += '</div>';
        });
        html += '</div>';

        $('#peanut-js-diagnostics').html(html);

        // Re-run diagnostics
        $('#peanut-run-diagnostics').on('click', function() {
            location.reload();
        });

        // Clear error log
        $('#peanut-clear-error-log').on('click', function() {
            if (confirm('<?php esc_html_e('Are you sure you want to clear the error log?', 'peanut-suite'); ?>')) {
                $.post(ajaxurl, {
                    action: 'peanut_clear_error_log',
                    nonce: '<?php echo wp_create_nonce('peanut_clear_log'); ?>'
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    }
                });
            }
        });
    });
    </script>
    <?php
            break;
    endswitch;
    ?>
</div>

