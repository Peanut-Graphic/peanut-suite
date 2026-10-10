<?php
/**
 * Security Settings View
 *
 * Configure security features including login protection, 2FA, and IP blocking.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get settings
$settings = get_option('peanut_security_settings', [
    'hide_login_enabled' => false,
    'login_slug' => 'secure-login',
    'redirect_slug' => '404',
    'limit_login_enabled' => true,
    'max_attempts' => 5,
    'lockout_duration' => 30,
    'lockout_increment' => true,
    'ip_whitelist' => [],
    'ip_blacklist' => [],
    'notify_login_success' => false,
    'notify_login_failed' => true,
    'notify_lockout' => true,
    'notify_email' => '',
    '2fa_enabled' => false,
    '2fa_method' => 'email',
    '2fa_roles' => ['administrator'],
]);

// Get current tab
$current_tab = isset($_GET['section']) ? sanitize_key($_GET['section']) : 'login';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['peanut_security_nonce'])) {
    if (wp_verify_nonce($_POST['peanut_security_nonce'], 'peanut_save_security')) {
        $section = sanitize_key($_POST['security_section'] ?? '');

        if ($section === 'login') {
            $settings['hide_login_enabled'] = isset($_POST['hide_login_enabled']);
            $settings['login_slug'] = sanitize_title($_POST['login_slug'] ?? 'secure-login');
            $settings['redirect_slug'] = sanitize_text_field($_POST['redirect_slug'] ?? '404');
            $settings['limit_login_enabled'] = isset($_POST['limit_login_enabled']);
            $settings['max_attempts'] = absint($_POST['max_attempts'] ?? 5);
            $settings['lockout_duration'] = absint($_POST['lockout_duration'] ?? 30);
            $settings['lockout_increment'] = isset($_POST['lockout_increment']);
        }

        if ($section === 'notifications') {
            $settings['notify_login_success'] = isset($_POST['notify_login_success']);
            $settings['notify_login_failed'] = isset($_POST['notify_login_failed']);
            $settings['notify_lockout'] = isset($_POST['notify_lockout']);
            $settings['notify_email'] = sanitize_email($_POST['notify_email'] ?? '');
        }

        if ($section === '2fa') {
            $settings['2fa_enabled'] = isset($_POST['2fa_enabled']);
            // Only e-mail codes are implemented; TOTP never had a verifier.
            $settings['2fa_method'] = 'email';
            $settings['2fa_roles'] = isset($_POST['2fa_roles']) ? array_map('sanitize_key', $_POST['2fa_roles']) : ['administrator'];
        }

        if ($section === 'ip') {
            $whitelist = sanitize_textarea_field($_POST['ip_whitelist'] ?? '');
            $settings['ip_whitelist'] = array_filter(array_map('trim', explode("\n", $whitelist)));

            $blacklist = sanitize_textarea_field($_POST['ip_blacklist'] ?? '');
            $settings['ip_blacklist'] = array_filter(array_map('trim', explode("\n", $blacklist)));
        }

        update_option('peanut_security_settings', $settings);
        echo '<div class="notice notice-success"><p>' . esc_html__('Security settings saved.', 'peanut-suite') . '</p></div>';
    }
}

// Get stats
global $wpdb;
$stats = [
    'failed_24h' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}peanut_login_attempts
        WHERE status = 'failed' AND attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    ),
    'blocked_ips' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}peanut_lockouts WHERE lockout_until > NOW()"
    ),
    'successful_24h' => (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->prefix}peanut_login_attempts
        WHERE status = 'success' AND attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    ),
];

// Get recent attempts
$recent_attempts = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}peanut_login_attempts
    ORDER BY attempt_time DESC LIMIT 10"
);

// Get active lockouts
$active_lockouts = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}peanut_lockouts
    WHERE lockout_until > NOW()
    ORDER BY created_at DESC"
);

// Tabs
$tabs = [
    'login' => ['label' => __('Login Protection', 'peanut-suite'), 'icon' => 'lock'],
    'notifications' => ['label' => __('Notifications', 'peanut-suite'), 'icon' => 'email'],
    '2fa' => ['label' => __('Two-Factor Auth', 'peanut-suite'), 'icon' => 'shield'],
    'ip' => ['label' => __('IP Management', 'peanut-suite'), 'icon' => 'admin-site'],
    'logs' => ['label' => __('Activity Logs', 'peanut-suite'), 'icon' => 'list-view'],
];
?>

<div class="peanut-page-header">
    <div>
        <h1><?php esc_html_e('Security', 'peanut-suite'); ?></h1>
        <p class="peanut-page-description"><?php esc_html_e('Protect your WordPress site with login security, IP blocking, and two-factor authentication.', 'peanut-suite'); ?></p>
    </div>
</div>

<!-- Stats -->
<div class="peanut-stats-row">
    <div class="peanut-stat-card">
        <div class="peanut-stat-icon" style="background: #fee2e2;">
            <span class="dashicons dashicons-warning" style="color: #ef4444;"></span>
        </div>
        <div class="peanut-stat-content">
            <span class="peanut-stat-value"><?php echo number_format_i18n($stats['failed_24h']); ?></span>
            <span class="peanut-stat-label"><?php esc_html_e('Failed Logins (24h)', 'peanut-suite'); ?></span>
        </div>
    </div>

    <div class="peanut-stat-card">
        <div class="peanut-stat-icon" style="background: #fef3c7;">
            <span class="dashicons dashicons-shield" style="color: #f59e0b;"></span>
        </div>
        <div class="peanut-stat-content">
            <span class="peanut-stat-value"><?php echo number_format_i18n($stats['blocked_ips']); ?></span>
            <span class="peanut-stat-label"><?php esc_html_e('Blocked IPs', 'peanut-suite'); ?></span>
        </div>
    </div>

    <div class="peanut-stat-card">
        <div class="peanut-stat-icon" style="background: #d1fae5;">
            <span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span>
        </div>
        <div class="peanut-stat-content">
            <span class="peanut-stat-value"><?php echo number_format_i18n($stats['successful_24h']); ?></span>
            <span class="peanut-stat-label"><?php esc_html_e('Successful Logins (24h)', 'peanut-suite'); ?></span>
        </div>
    </div>

    <div class="peanut-stat-card">
        <div class="peanut-stat-icon" style="background: <?php echo $settings['hide_login_enabled'] ? '#d1fae5' : '#fee2e2'; ?>;">
            <span class="dashicons dashicons-hidden" style="color: <?php echo $settings['hide_login_enabled'] ? '#10b981' : '#ef4444'; ?>;"></span>
        </div>
        <div class="peanut-stat-content">
            <span class="peanut-stat-value"><?php echo $settings['hide_login_enabled'] ? __('Hidden', 'peanut-suite') : __('Visible', 'peanut-suite'); ?></span>
            <span class="peanut-stat-label"><?php esc_html_e('Login URL', 'peanut-suite'); ?></span>
        </div>
    </div>
</div>

<!-- Tabs -->
<div class="peanut-tabs" style="margin-top: 24px;">
    <?php foreach ($tabs as $tab_id => $tab): ?>
    <a href="<?php echo esc_url(add_query_arg('section', $tab_id)); ?>"
       class="peanut-tab <?php echo $current_tab === $tab_id ? 'active' : ''; ?>">
        <span class="dashicons dashicons-<?php echo esc_attr($tab['icon']); ?>"></span>
        <?php echo esc_html($tab['label']); ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Tab Content -->
<?php if ($current_tab === 'login'): ?>
<div class="peanut-card" style="margin-top: 16px;">
    <div class="peanut-card-header">
        <h3 class="peanut-card-title"><?php esc_html_e('Login Protection', 'peanut-suite'); ?></h3>
    </div>
    <div class="peanut-card-body">
        <form method="post" class="peanut-form">
            <?php wp_nonce_field('peanut_save_security', 'peanut_security_nonce'); ?>
            <input type="hidden" name="security_section" value="login">

            <!-- Hide Login URL -->
            <div class="peanut-settings-section">
                <h4><?php esc_html_e('Hide Login URL', 'peanut-suite'); ?></h4>
                <p class="description"><?php esc_html_e('Change your login URL to prevent brute force attacks on the default wp-login.php.', 'peanut-suite'); ?></p>

                <div class="peanut-toggle-row" style="margin: 16px 0;">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="hide_login_enabled" value="1" <?php checked($settings['hide_login_enabled']); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('Enable Custom Login URL', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Block access to wp-login.php and use a custom slug instead.', 'peanut-suite'); ?></p>
                    </div>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="login_slug"><?php esc_html_e('Login Slug', 'peanut-suite'); ?></label>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span style="color: #64748b;"><?php echo esc_html(home_url('/')); ?></span>
                        <input type="text" id="login_slug" name="login_slug" class="peanut-form-input" style="width: 200px;"
                               value="<?php echo esc_attr($settings['login_slug']); ?>" placeholder="secure-login">
                    </div>
                    <?php echo peanut_field_help(__('Choose a unique, hard-to-guess slug. Avoid common words like "login" or "admin".', 'peanut-suite')); ?>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="redirect_slug"><?php esc_html_e('Redirect Unauthorized Access To', 'peanut-suite'); ?></label>
                    <select id="redirect_slug" name="redirect_slug" class="peanut-form-select">
                        <option value="404" <?php selected($settings['redirect_slug'], '404'); ?>><?php esc_html_e('404 Page (Recommended)', 'peanut-suite'); ?></option>
                        <option value="" <?php selected($settings['redirect_slug'], ''); ?>><?php esc_html_e('Homepage', 'peanut-suite'); ?></option>
                    </select>
                </div>
            </div>

            <!-- Login Attempt Limiting -->
            <div class="peanut-settings-section" style="margin-top: 32px; padding-top: 32px; border-top: 1px solid #e2e8f0;">
                <h4><?php esc_html_e('Login Attempt Limiting', 'peanut-suite'); ?></h4>
                <p class="description"><?php esc_html_e('Automatically lock out IP addresses after too many failed login attempts.', 'peanut-suite'); ?></p>

                <div class="peanut-toggle-row" style="margin: 16px 0;">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="limit_login_enabled" value="1" <?php checked($settings['limit_login_enabled']); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('Enable Login Limiting', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Block IPs after repeated failed login attempts.', 'peanut-suite'); ?></p>
                    </div>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="max_attempts"><?php esc_html_e('Max Failed Attempts', 'peanut-suite'); ?></label>
                    <select id="max_attempts" name="max_attempts" class="peanut-form-select">
                        <?php for ($i = 3; $i <= 10; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php selected($settings['max_attempts'], $i); ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="peanut-form-row">
                    <label class="peanut-form-label" for="lockout_duration"><?php esc_html_e('Lockout Duration', 'peanut-suite'); ?></label>
                    <select id="lockout_duration" name="lockout_duration" class="peanut-form-select">
                        <option value="15" <?php selected($settings['lockout_duration'], 15); ?>><?php esc_html_e('15 minutes', 'peanut-suite'); ?></option>
                        <option value="30" <?php selected($settings['lockout_duration'], 30); ?>><?php esc_html_e('30 minutes', 'peanut-suite'); ?></option>
                        <option value="60" <?php selected($settings['lockout_duration'], 60); ?>><?php esc_html_e('1 hour', 'peanut-suite'); ?></option>
                        <option value="120" <?php selected($settings['lockout_duration'], 120); ?>><?php esc_html_e('2 hours', 'peanut-suite'); ?></option>
                        <option value="1440" <?php selected($settings['lockout_duration'], 1440); ?>><?php esc_html_e('24 hours', 'peanut-suite'); ?></option>
                    </select>
                </div>

                <div class="peanut-toggle-row" style="margin-top: 16px;">
                    <label class="peanut-toggle">
                        <input type="checkbox" name="lockout_increment" value="1" <?php checked($settings['lockout_increment']); ?>>
                        <span class="toggle-slider"></span>
                    </label>
                    <div class="toggle-label">
                        <strong><?php esc_html_e('Progressive Lockout', 'peanut-suite'); ?></strong>
                        <p><?php esc_html_e('Increase lockout duration for repeat offenders (2x, 3x, etc.).', 'peanut-suite'); ?></p>
                    </div>
                </div>
            </div>

            <div class="peanut-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save Login Settings', 'peanut-suite'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($current_tab === 'notifications'): ?>
<div class="peanut-card" style="margin-top: 16px;">
    <div class="peanut-card-header">
        <h3 class="peanut-card-title"><?php esc_html_e('Security Notifications', 'peanut-suite'); ?></h3>
    </div>
    <div class="peanut-card-body">
        <form method="post" class="peanut-form">
            <?php wp_nonce_field('peanut_save_security', 'peanut_security_nonce'); ?>
            <input type="hidden" name="security_section" value="notifications">

            <div class="peanut-form-row">
                <label class="peanut-form-label" for="notify_email"><?php esc_html_e('Notification Email', 'peanut-suite'); ?></label>
                <input type="email" id="notify_email" name="notify_email" class="peanut-form-input"
                       value="<?php echo esc_attr($settings['notify_email']); ?>"
                       placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                <?php echo peanut_field_help(__('Leave blank to use the admin email.', 'peanut-suite')); ?>
            </div>

            <div class="peanut-toggle-row" style="margin-top: 16px;">
                <label class="peanut-toggle">
                    <input type="checkbox" name="notify_login_success" value="1" <?php checked($settings['notify_login_success']); ?>>
                    <span class="toggle-slider"></span>
                </label>
                <div class="toggle-label">
                    <strong><?php esc_html_e('Successful Admin Logins', 'peanut-suite'); ?></strong>
                    <p><?php esc_html_e('Get notified when an admin or editor logs in.', 'peanut-suite'); ?></p>
                </div>
            </div>

            <div class="peanut-toggle-row">
                <label class="peanut-toggle">
                    <input type="checkbox" name="notify_login_failed" value="1" <?php checked($settings['notify_login_failed']); ?>>
                    <span class="toggle-slider"></span>
                </label>
                <div class="toggle-label">
                    <strong><?php esc_html_e('Multiple Failed Attempts', 'peanut-suite'); ?></strong>
                    <p><?php esc_html_e('Alert after 3+ failed login attempts from the same IP.', 'peanut-suite'); ?></p>
                </div>
            </div>

            <div class="peanut-toggle-row">
                <label class="peanut-toggle">
                    <input type="checkbox" name="notify_lockout" value="1" <?php checked($settings['notify_lockout']); ?>>
                    <span class="toggle-slider"></span>
                </label>
                <div class="toggle-label">
                    <strong><?php esc_html_e('IP Lockouts', 'peanut-suite'); ?></strong>
                    <p><?php esc_html_e('Get notified when an IP is locked out.', 'peanut-suite'); ?></p>
                </div>
            </div>

            <div class="peanut-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save Notification Settings', 'peanut-suite'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($current_tab === '2fa'): ?>
<div class="peanut-card" style="margin-top: 16px;">
    <div class="peanut-card-header">
        <h3 class="peanut-card-title"><?php esc_html_e('Two-Factor Authentication', 'peanut-suite'); ?></h3>
    </div>
    <div class="peanut-card-body">
        <form method="post" class="peanut-form">
            <?php wp_nonce_field('peanut_save_security', 'peanut_security_nonce'); ?>
            <input type="hidden" name="security_section" value="2fa">

            <div class="peanut-toggle-row" style="margin-bottom: 16px;">
                <label class="peanut-toggle">
                    <input type="checkbox" name="2fa_enabled" value="1" <?php checked($settings['2fa_enabled']); ?>>
                    <span class="toggle-slider"></span>
                </label>
                <div class="toggle-label">
                    <strong><?php esc_html_e('Enable Two-Factor Authentication', 'peanut-suite'); ?></strong>
                    <p><?php esc_html_e('Require a second verification step after password entry.', 'peanut-suite'); ?></p>
                </div>
            </div>

            <div class="peanut-form-row">
                <label class="peanut-form-label"><?php esc_html_e('2FA Method', 'peanut-suite'); ?></label>
                <div class="peanut-radio-group">
                    <label class="peanut-radio">
                        <input type="radio" name="2fa_method" value="email" checked>
                        <span class="radio-label">
                            <strong><?php esc_html_e('Email Code', 'peanut-suite'); ?></strong>
                            <span><?php esc_html_e('Send a one-time code to the user\'s email.', 'peanut-suite'); ?></span>
                        </span>
                    </label>
                    <p class="description" style="margin-top: 8px;">
                        <?php esc_html_e('Authenticator apps (TOTP) are not supported yet. Sites that had TOTP selected now use e-mail codes.', 'peanut-suite'); ?>
                    </p>
                </div>
            </div>

            <div class="peanut-form-row">
                <label class="peanut-form-label"><?php esc_html_e('Require 2FA for Roles', 'peanut-suite'); ?></label>
                <?php
                $roles = wp_roles()->roles;
                $selected_roles = $settings['2fa_roles'] ?? ['administrator'];
                ?>
                <div class="peanut-checkbox-group">
                    <?php foreach ($roles as $role_key => $role): ?>
                    <label class="peanut-checkbox">
                        <input type="checkbox" name="2fa_roles[]" value="<?php echo esc_attr($role_key); ?>"
                               <?php checked(in_array($role_key, $selected_roles)); ?>>
                        <span><?php echo esc_html($role['name']); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="peanut-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save 2FA Settings', 'peanut-suite'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php elseif ($current_tab === 'ip'): ?>
<div class="peanut-card" style="margin-top: 16px;">
    <div class="peanut-card-header">
        <h3 class="peanut-card-title"><?php esc_html_e('IP Management', 'peanut-suite'); ?></h3>
    </div>
    <div class="peanut-card-body">
        <form method="post" class="peanut-form">
            <?php wp_nonce_field('peanut_save_security', 'peanut_security_nonce'); ?>
            <input type="hidden" name="security_section" value="ip">

            <div class="peanut-form-row">
                <label class="peanut-form-label" for="ip_whitelist">
                    <?php esc_html_e('IP Whitelist', 'peanut-suite'); ?>
                    <span class="peanut-badge peanut-badge-success" style="margin-left: 8px;"><?php esc_html_e('Always Allowed', 'peanut-suite'); ?></span>
                </label>
                <textarea id="ip_whitelist" name="ip_whitelist" class="peanut-form-textarea" rows="4"
                          placeholder="<?php esc_attr_e('One IP address per line', 'peanut-suite'); ?>"><?php echo esc_textarea(implode("\n", $settings['ip_whitelist'] ?? [])); ?></textarea>
                <?php echo peanut_field_help(__('These IPs will never be blocked. Include your own IP to prevent accidental lockout.', 'peanut-suite')); ?>
                <p style="margin-top: 8px;">
                    <span class="description"><?php esc_html_e('Your current IP:', 'peanut-suite'); ?></span>
                    <code><?php echo esc_html($_SERVER['REMOTE_ADDR']); ?></code>
                </p>
            </div>

            <div class="peanut-form-row">
                <label class="peanut-form-label" for="ip_blacklist">
                    <?php esc_html_e('IP Blacklist', 'peanut-suite'); ?>
                    <span class="peanut-badge peanut-badge-danger" style="margin-left: 8px;"><?php esc_html_e('Permanently Blocked', 'peanut-suite'); ?></span>
                </label>
                <textarea id="ip_blacklist" name="ip_blacklist" class="peanut-form-textarea" rows="4"
                          placeholder="<?php esc_attr_e('One IP address per line', 'peanut-suite'); ?>"><?php echo esc_textarea(implode("\n", $settings['ip_blacklist'] ?? [])); ?></textarea>
                <?php echo peanut_field_help(__('These IPs will always be blocked from logging in.', 'peanut-suite')); ?>
            </div>

            <div class="peanut-form-actions">
                <button type="submit" class="button button-primary"><?php esc_html_e('Save IP Settings', 'peanut-suite'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Active Lockouts -->
<?php if (!empty($active_lockouts)): ?>
<div class="peanut-card" style="margin-top: 24px;">
    <div class="peanut-card-header">
        <h3 class="peanut-card-title"><?php esc_html_e('Currently Locked Out IPs', 'peanut-suite'); ?></h3>
    </div>
    <div class="peanut-card-body" style="padding: 0;">
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('IP Address', 'peanut-suite'); ?></th>
                    <th><?php esc_html_e('Failed Attempts', 'peanut-suite'); ?></th>
                    <th><?php esc_html_e('Locked Until', 'peanut-suite'); ?></th>
                    <th style="width: 100px;"><?php esc_html_e('Actions', 'peanut-suite'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_lockouts as $lockout): ?>
                <tr>
                    <td><code><?php echo esc_html($lockout->ip_address); ?></code></td>
                    <td><?php echo esc_html($lockout->attempts); ?></td>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($lockout->lockout_until))); ?></td>
                    <td>
                        <button type="button" class="button button-small peanut-unlock-ip" data-ip="<?php echo esc_attr($lockout->ip_address); ?>">
                            <?php esc_html_e('Unlock', 'peanut-suite'); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php elseif ($current_tab === 'logs'): ?>
<div class="peanut-card" style="margin-top: 16px;">
    <div class="peanut-card-header">
        <h3 class="peanut-card-title"><?php esc_html_e('Recent Login Activity', 'peanut-suite'); ?></h3>
    </div>
    <div class="peanut-card-body" style="padding: 0;">
        <?php if (empty($recent_attempts)): ?>
            <div class="peanut-empty-state" style="padding: 40px;">
                <span class="dashicons dashicons-yes-alt" style="font-size: 48px; width: 48px; height: 48px; color: #10b981;"></span>
                <h3><?php esc_html_e('No login attempts recorded', 'peanut-suite'); ?></h3>
                <p><?php esc_html_e('Login attempts will appear here once the security module starts tracking.', 'peanut-suite'); ?></p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'peanut-suite'); ?></th>
                        <th><?php esc_html_e('Username', 'peanut-suite'); ?></th>
                        <th><?php esc_html_e('IP Address', 'peanut-suite'); ?></th>
                        <th><?php esc_html_e('Status', 'peanut-suite'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_attempts as $attempt): ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($attempt->attempt_time))); ?></td>
                        <td><?php echo esc_html($attempt->username); ?></td>
                        <td><code><?php echo esc_html($attempt->ip_address); ?></code></td>
                        <td>
                            <?php if ($attempt->status === 'success'): ?>
                                <span class="peanut-badge peanut-badge-success"><?php esc_html_e('Success', 'peanut-suite'); ?></span>
                            <?php else: ?>
                                <span class="peanut-badge peanut-badge-danger"><?php esc_html_e('Failed', 'peanut-suite'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
jQuery(document).ready(function($) {
    // Unlock IP
    $('.peanut-unlock-ip').on('click', function() {
        var $btn = $(this);
        var ip = $btn.data('ip');

        if (!confirm('<?php esc_html_e('Unlock this IP address?', 'peanut-suite'); ?>')) {
            return;
        }

        $btn.prop('disabled', true).text('<?php esc_html_e('Unlocking...', 'peanut-suite'); ?>');

        $.ajax({
            url: '<?php echo esc_url(rest_url(PEANUT_API_NAMESPACE . '/security/unlock/')); ?>' + encodeURIComponent(ip),
            method: 'DELETE',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', '<?php echo wp_create_nonce('wp_rest'); ?>');
            },
            success: function() {
                $btn.closest('tr').fadeOut(function() {
                    $(this).remove();
                });
            },
            error: function() {
                alert('<?php esc_html_e('Failed to unlock IP', 'peanut-suite'); ?>');
                $btn.prop('disabled', false).text('<?php esc_html_e('Unlock', 'peanut-suite'); ?>');
            }
        });
    });
});
</script>

<style>
.peanut-settings-section h4 {
    margin: 0 0 8px;
    font-size: 15px;
    font-weight: 600;
}
.peanut-settings-section > .description {
    color: #64748b;
    margin-bottom: 16px;
}
.peanut-radio-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.peanut-radio {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    cursor: pointer;
}
.peanut-radio input {
    margin-top: 4px;
}
.peanut-radio .radio-label {
    display: flex;
    flex-direction: column;
}
.peanut-radio .radio-label strong {
    font-weight: 500;
}
.peanut-radio .radio-label span {
    color: #64748b;
    font-size: 13px;
}
.peanut-checkbox-group {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
}
.peanut-checkbox {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
}
</style>
