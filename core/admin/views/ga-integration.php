<?php
/**
 * Google Analytics & Search Console Integration View
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 3) . '/core/services/class-peanut-encryption.php';
require_once dirname(__DIR__, 3) . '/core/services/class-peanut-ga-credentials.php';

// Secrets (client secret, tokens) are decrypted only to learn whether they are
// set; they are removed before anything is rendered and never reach the page.
$credentials = Peanut_GA_Credentials::load();
$is_connected = $credentials['access_token'] !== '' && !empty($credentials['property_id']);
$client_secret_set = $credentials['client_secret'] !== '';
foreach (Peanut_GA_Credentials::SECRET_FIELDS as $secret_field) {
    unset($credentials[$secret_field]);
}

// Handle OAuth callback
if (isset($_GET['action']) && $_GET['action'] === 'oauth_callback' && isset($_GET['code'])) {
    if (class_exists('PeanutSuite\GAIntegration\GA_Integration_Module')) {
        $module = \PeanutSuite\GAIntegration\GA_Integration_Module::instance();
        if ($module->handle_oauth_callback(sanitize_text_field($_GET['code']))) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Successfully connected to Google!', 'peanut-suite') . '</p></div>';
            $is_connected = true;
        } else {
            echo '<div class="notice notice-error"><p>' . esc_html__('Failed to connect. Please try again.', 'peanut-suite') . '</p></div>';
        }
    }
}

// Get period
$period = isset($_GET['period']) ? intval($_GET['period']) : 30;
?>

<div class="peanut-content">
    <div class="peanut-tabs">
        <nav class="peanut-tab-nav">
            <a href="#overview" class="active"><?php esc_html_e('Overview', 'peanut-suite'); ?></a>
            <a href="#traffic"><?php esc_html_e('Traffic', 'peanut-suite'); ?></a>
            <a href="#search"><?php esc_html_e('Search Queries', 'peanut-suite'); ?></a>
            <a href="#pages"><?php esc_html_e('Top Pages', 'peanut-suite'); ?></a>
            <a href="#settings"><?php esc_html_e('Settings', 'peanut-suite'); ?></a>
        </nav>

        <?php if (!$is_connected): ?>
            <!-- Not Connected State -->
            <div class="peanut-tab-content active" id="overview">
                <div class="peanut-card">
                    <div class="peanut-card-body">
                        <div class="peanut-empty-state">
                            <span class="dashicons dashicons-chart-area"></span>
                            <h3><?php esc_html_e('Connect Google Analytics', 'peanut-suite'); ?></h3>
                            <p><?php esc_html_e('Link your Google Analytics and Search Console accounts to view traffic, search queries, and more.', 'peanut-suite'); ?></p>
                            <a href="#settings" class="button button-primary peanut-tab-link">
                                <?php esc_html_e('Configure Connection', 'peanut-suite'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Overview Tab -->
            <div id="overview" class="peanut-tab-content active">
                <!-- Period Filter -->
                <div class="peanut-toolbar">
                    <div class="peanut-period-filter">
                        <label><?php esc_html_e('Period:', 'peanut-suite'); ?></label>
                        <select id="period-filter">
                            <option value="7" <?php selected($period, 7); ?>><?php esc_html_e('Last 7 days', 'peanut-suite'); ?></option>
                            <option value="30" <?php selected($period, 30); ?>><?php esc_html_e('Last 30 days', 'peanut-suite'); ?></option>
                            <option value="90" <?php selected($period, 90); ?>><?php esc_html_e('Last 90 days', 'peanut-suite'); ?></option>
                        </select>
                    </div>
                    <button type="button" class="button" id="refresh-data">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Refresh', 'peanut-suite'); ?>
                    </button>
                </div>

                <!-- Stats Cards -->
                <div class="peanut-stats-grid" id="ga-stats">
                    <div class="peanut-stat-card">
                        <div class="stat-icon"><span class="dashicons dashicons-admin-users"></span></div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-users">-</div>
                            <div class="stat-label"><?php esc_html_e('Users', 'peanut-suite'); ?></div>
                        </div>
                    </div>
                    <div class="peanut-stat-card">
                        <div class="stat-icon"><span class="dashicons dashicons-visibility"></span></div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-sessions">-</div>
                            <div class="stat-label"><?php esc_html_e('Sessions', 'peanut-suite'); ?></div>
                        </div>
                    </div>
                    <div class="peanut-stat-card">
                        <div class="stat-icon"><span class="dashicons dashicons-admin-page"></span></div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-pageviews">-</div>
                            <div class="stat-label"><?php esc_html_e('Pageviews', 'peanut-suite'); ?></div>
                        </div>
                    </div>
                    <div class="peanut-stat-card">
                        <div class="stat-icon"><span class="dashicons dashicons-clock"></span></div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-duration">-</div>
                            <div class="stat-label"><?php esc_html_e('Avg. Duration', 'peanut-suite'); ?></div>
                        </div>
                    </div>
                    <div class="peanut-stat-card">
                        <div class="stat-icon"><span class="dashicons dashicons-migrate"></span></div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-bounce">-</div>
                            <div class="stat-label"><?php esc_html_e('Bounce Rate', 'peanut-suite'); ?></div>
                        </div>
                    </div>
                    <div class="peanut-stat-card">
                        <div class="stat-icon"><span class="dashicons dashicons-flag"></span></div>
                        <div class="stat-content">
                            <div class="stat-value" id="stat-conversions">-</div>
                            <div class="stat-label"><?php esc_html_e('Conversions', 'peanut-suite'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Traffic Tab -->
            <div id="traffic" class="peanut-tab-content">
                <div class="peanut-card">
                    <div class="peanut-card-header">
                        <h3><?php esc_html_e('Traffic Over Time', 'peanut-suite'); ?></h3>
                    </div>
                    <div class="peanut-card-body">
                        <canvas id="traffic-chart" height="300"></canvas>
                    </div>
                </div>
            </div>

            <!-- Search Queries Tab -->
            <div id="search" class="peanut-tab-content">
                <div class="peanut-card">
                    <div class="peanut-card-header">
                        <h3><?php esc_html_e('Search Console Queries', 'peanut-suite'); ?></h3>
                    </div>
                    <div class="peanut-card-body">
                        <table class="peanut-table" id="search-queries-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Query', 'peanut-suite'); ?></th>
                                    <th><?php esc_html_e('Clicks', 'peanut-suite'); ?></th>
                                    <th><?php esc_html_e('Impressions', 'peanut-suite'); ?></th>
                                    <th><?php esc_html_e('CTR', 'peanut-suite'); ?></th>
                                    <th><?php esc_html_e('Position', 'peanut-suite'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="5" class="text-center"><?php esc_html_e('Loading...', 'peanut-suite'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Top Pages Tab -->
            <div id="pages" class="peanut-tab-content">
                <div class="peanut-card">
                    <div class="peanut-card-header">
                        <h3><?php esc_html_e('Top Pages', 'peanut-suite'); ?></h3>
                    </div>
                    <div class="peanut-card-body">
                        <table class="peanut-table" id="top-pages-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Page', 'peanut-suite'); ?></th>
                                    <th><?php esc_html_e('Pageviews', 'peanut-suite'); ?></th>
                                    <th><?php esc_html_e('Users', 'peanut-suite'); ?></th>
                                    <th><?php esc_html_e('Avg. Time', 'peanut-suite'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="4" class="text-center"><?php esc_html_e('Loading...', 'peanut-suite'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Settings Tab -->
        <div id="settings" class="peanut-tab-content">
            <form id="ga-settings-form">
                <?php wp_nonce_field('peanut_ga', 'peanut_nonce'); ?>

                <div class="peanut-card">
                    <div class="peanut-card-header">
                        <h3><?php esc_html_e('Google API Credentials', 'peanut-suite'); ?></h3>
                    </div>
                    <div class="peanut-card-body">
                        <div class="peanut-notice peanut-notice-info">
                            <span class="dashicons dashicons-info"></span>
                            <p>
                                <?php printf(
                                    __('Create credentials in the %sGoogle Cloud Console%s. Enable the Analytics Data API and Search Console API.', 'peanut-suite'),
                                    '<a href="https://console.cloud.google.com/apis/credentials" target="_blank">',
                                    '</a>'
                                ); ?>
                            </p>
                        </div>

                        <div class="peanut-form-row">
                            <label for="client-id"><?php esc_html_e('OAuth Client ID', 'peanut-suite'); ?></label>
                            <input type="text" id="client-id" name="client_id"
                                   value="<?php echo esc_attr($credentials['client_id'] ?? ''); ?>"
                                   placeholder="xxxxx.apps.googleusercontent.com">
                        </div>

                        <div class="peanut-form-row">
                            <label for="client-secret"><?php esc_html_e('OAuth Client Secret', 'peanut-suite'); ?></label>
                            <input type="password" id="client-secret" name="client_secret"
                                   value="" autocomplete="new-password"
                                   <?php if ($client_secret_set): ?>placeholder="<?php esc_attr_e('Saved (hidden)', 'peanut-suite'); ?>" aria-describedby="client-secret-help"<?php endif; ?>>
                            <?php if ($client_secret_set): ?>
                                <p class="description" id="client-secret-help"><?php esc_html_e('A client secret is saved and stored encrypted. It is never shown again. Leave this field blank to keep it, or enter a new secret to replace it.', 'peanut-suite'); ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="peanut-form-row">
                            <label><?php esc_html_e('Redirect URI', 'peanut-suite'); ?></label>
                            <input type="text" readonly
                                   value="<?php echo esc_url(admin_url('admin.php?page=peanut-ga-integration&action=oauth_callback')); ?>">
                            <p class="description"><?php esc_html_e('Add this URI to your Google OAuth client settings.', 'peanut-suite'); ?></p>
                        </div>
                    </div>
                </div>

                <?php if (!empty($credentials['client_id'])): ?>
                <div class="peanut-card">
                    <div class="peanut-card-header">
                        <h3><?php esc_html_e('Connection Status', 'peanut-suite'); ?></h3>
                    </div>
                    <div class="peanut-card-body">
                        <?php if ($is_connected): ?>
                            <div class="peanut-connection-status connected">
                                <span class="dashicons dashicons-yes-alt"></span>
                                <strong><?php esc_html_e('Connected to Google', 'peanut-suite'); ?></strong>
                            </div>
                        <?php else: ?>
                            <?php
                            $module = class_exists('PeanutSuite\GAIntegration\GA_Integration_Module')
                                ? \PeanutSuite\GAIntegration\GA_Integration_Module::instance()
                                : null;
                            $oauth_url = $module ? $module->get_oauth_url() : '';
                            ?>
                            <?php if ($oauth_url): ?>
                                <a href="<?php echo esc_url($oauth_url); ?>" class="button button-primary button-hero">
                                    <span class="dashicons dashicons-google"></span>
                                    <?php esc_html_e('Connect with Google', 'peanut-suite'); ?>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($is_connected): ?>
                <div class="peanut-card">
                    <div class="peanut-card-header">
                        <h3><?php esc_html_e('Property Settings', 'peanut-suite'); ?></h3>
                    </div>
                    <div class="peanut-card-body">
                        <div class="peanut-form-row">
                            <label for="property-id"><?php esc_html_e('GA4 Property ID', 'peanut-suite'); ?></label>
                            <input type="text" id="property-id" name="property_id"
                                   value="<?php echo esc_attr($credentials['property_id'] ?? ''); ?>"
                                   placeholder="123456789">
                            <button type="button" class="button" id="load-properties">
                                <?php esc_html_e('Load Properties', 'peanut-suite'); ?>
                            </button>
                        </div>

                        <div class="peanut-form-row">
                            <label for="gsc-property"><?php esc_html_e('Search Console Property', 'peanut-suite'); ?></label>
                            <input type="url" id="gsc-property" name="gsc_property"
                                   value="<?php echo esc_attr($credentials['gsc_property'] ?? ''); ?>"
                                   placeholder="https://example.com/">
                            <p class="description"><?php esc_html_e('Your verified site URL in Search Console.', 'peanut-suite'); ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <div class="peanut-form-actions">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e('Save Settings', 'peanut-suite'); ?>
                    </button>
                    <?php if ($is_connected): ?>
                        <button type="button" class="button" id="disconnect-ga">
                            <?php esc_html_e('Disconnect', 'peanut-suite'); ?>
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.peanut-connection-status {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px;
    border-radius: 4px;
}
.peanut-connection-status.connected {
    background: #d4edda;
    color: #155724;
}
.peanut-connection-status .dashicons {
    color: #28a745;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
jQuery(document).ready(function($) {
    let trafficChart = null;
    let currentPeriod = <?php echo $period; ?>;

    // Tab switching
    $('.peanut-tab-nav a, .peanut-tab-link').on('click', function(e) {
        e.preventDefault();
        const target = $(this).attr('href');
        $('.peanut-tab-nav a').removeClass('active');
        $('.peanut-tab-nav a[href="' + target + '"]').addClass('active');
        $('.peanut-tab-content').removeClass('active');
        $(target).addClass('active');

        // Load data for tab
        if (target === '#traffic') loadTrafficData();
        if (target === '#search') loadSearchQueries();
        if (target === '#pages') loadTopPages();
    });

    // Period change
    $('#period-filter').on('change', function() {
        currentPeriod = $(this).val();
        loadOverview();
    });

    // Refresh button
    $('#refresh-data').on('click', loadOverview);

    // Load overview data
    function loadOverview() {
        $.get('<?php echo rest_url('peanut/v1/ga/overview'); ?>', { days: currentPeriod }, function(data) {
            $('#stat-users').text(data.users.toLocaleString());
            $('#stat-sessions').text(data.sessions.toLocaleString());
            $('#stat-pageviews').text(data.pageviews.toLocaleString());
            $('#stat-duration').text(formatDuration(data.avg_duration));
            $('#stat-bounce').text(data.bounce_rate + '%');
            $('#stat-conversions').text(data.conversions.toLocaleString());
        });
    }

    function formatDuration(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return mins + 'm ' + secs + 's';
    }

    // Load traffic chart
    function loadTrafficData() {
        $.get('<?php echo rest_url('peanut/v1/ga/traffic'); ?>', { days: currentPeriod }, function(data) {
            const ctx = document.getElementById('traffic-chart').getContext('2d');

            if (trafficChart) trafficChart.destroy();

            trafficChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Users',
                            data: data.users,
                            borderColor: '#2271b1',
                            tension: 0.3,
                            fill: false
                        },
                        {
                            label: 'Sessions',
                            data: data.sessions,
                            borderColor: '#28a745',
                            tension: 0.3,
                            fill: false
                        },
                        {
                            label: 'Pageviews',
                            data: data.pageviews,
                            borderColor: '#ffc107',
                            tension: 0.3,
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'top' } }
                }
            });
        });
    }

    // Load search queries
    function loadSearchQueries() {
        $.get('<?php echo rest_url('peanut/v1/ga/search-queries'); ?>', { days: currentPeriod }, function(data) {
            const tbody = $('#search-queries-table tbody');
            tbody.empty();

            if (data.length === 0) {
                tbody.html('<tr><td colspan="5" class="text-center">No data available</td></tr>');
                return;
            }

            data.forEach(function(row) {
                tbody.append(`
                    <tr>
                        <td><strong>${row.query}</strong></td>
                        <td>${row.clicks.toLocaleString()}</td>
                        <td>${row.impressions.toLocaleString()}</td>
                        <td>${row.ctr}%</td>
                        <td>${row.position}</td>
                    </tr>
                `);
            });
        });
    }

    // Load top pages
    function loadTopPages() {
        $.get('<?php echo rest_url('peanut/v1/ga/pages'); ?>', { days: currentPeriod }, function(data) {
            const tbody = $('#top-pages-table tbody');
            tbody.empty();

            if (data.length === 0) {
                tbody.html('<tr><td colspan="4" class="text-center">No data available</td></tr>');
                return;
            }

            data.forEach(function(row) {
                tbody.append(`
                    <tr>
                        <td><code>${row.path}</code></td>
                        <td>${row.pageviews.toLocaleString()}</td>
                        <td>${row.users.toLocaleString()}</td>
                        <td>${formatDuration(row.avg_time)}</td>
                    </tr>
                `);
            });
        });
    }

    // Save settings
    $('#ga-settings-form').on('submit', function(e) {
        e.preventDefault();

        $.post(ajaxurl, {
            action: 'peanut_save_ga_credentials',
            nonce: $('[name="peanut_nonce"]').val(),
            client_id: $('#client-id').val(),
            client_secret: $('#client-secret').val(),
            property_id: $('#property-id').val(),
            gsc_property: $('#gsc-property').val()
        }, function(response) {
            if (response.success) {
                alert('Settings saved!');
                location.reload();
            } else {
                alert(response.data || 'Error saving settings');
            }
        });
    });

    // Disconnect
    $('#disconnect-ga').on('click', function() {
        if (!confirm('Disconnect from Google Analytics?')) return;

        $.post(ajaxurl, {
            action: 'peanut_disconnect_ga',
            nonce: '<?php echo wp_create_nonce('peanut_ga'); ?>'
        }, function(response) {
            if (response.success) {
                location.reload();
            }
        });
    });

    // Load properties
    $('#load-properties').on('click', function() {
        const $btn = $(this);
        $btn.prop('disabled', true).text('Loading...');

        $.post(ajaxurl, {
            action: 'peanut_get_ga_properties',
            nonce: '<?php echo wp_create_nonce('peanut_ga'); ?>'
        }, function(response) {
            $btn.prop('disabled', false).text('Load Properties');

            if (response.success && response.data.properties.length > 0) {
                let options = '<option value="">Select a property</option>';
                response.data.properties.forEach(function(prop) {
                    options += `<option value="${prop.id}">${prop.name} (${prop.id})</option>`;
                });

                const select = $('<select>').html(options).on('change', function() {
                    $('#property-id').val($(this).val());
                });

                $('#property-id').after(select);
            } else {
                alert('No properties found or not authenticated');
            }
        });
    });

    // Initial load
    <?php if ($is_connected): ?>
    loadOverview();
    <?php endif; ?>
});
</script>
