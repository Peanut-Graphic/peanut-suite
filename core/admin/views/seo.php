<?php
/**
 * SEO & Keywords Admin View
 */

if (!defined('ABSPATH')) {
    exit;
}

$active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'keywords';
?>

<div class="peanut-tabs">
    <a href="?page=peanut-seo&tab=keywords" class="peanut-tab <?php echo $active_tab === 'keywords' ? 'active' : ''; ?>">
        <span class="dashicons dashicons-search"></span> Keyword Tracking
    </a>
    <a href="?page=peanut-seo&tab=audit" class="peanut-tab <?php echo $active_tab === 'audit' ? 'active' : ''; ?>">
        <span class="dashicons dashicons-welcome-view-site"></span> SEO Audit
    </a>
    <a href="?page=peanut-seo&tab=settings" class="peanut-tab <?php echo $active_tab === 'settings' ? 'active' : ''; ?>">
        <span class="dashicons dashicons-admin-settings"></span> Settings
    </a>
</div>

<?php if ($active_tab === 'keywords'): ?>
<!-- Keyword Tracking Tab -->
<div class="peanut-content">
    <div class="peanut-stats-row" id="keyword-stats">
        <div class="peanut-stat-card">
            <div class="stat-icon"><span class="dashicons dashicons-search"></span></div>
            <div class="stat-content">
                <span class="stat-value" id="stat-total">0</span>
                <span class="stat-label">Keywords Tracked</span>
            </div>
        </div>
        <div class="peanut-stat-card">
            <div class="stat-icon green"><span class="dashicons dashicons-arrow-up-alt"></span></div>
            <div class="stat-content">
                <span class="stat-value" id="stat-improved">0</span>
                <span class="stat-label">Improved</span>
            </div>
        </div>
        <div class="peanut-stat-card">
            <div class="stat-icon red"><span class="dashicons dashicons-arrow-down-alt"></span></div>
            <div class="stat-content">
                <span class="stat-value" id="stat-declined">0</span>
                <span class="stat-label">Declined</span>
            </div>
        </div>
        <div class="peanut-stat-card">
            <div class="stat-icon blue"><span class="dashicons dashicons-awards"></span></div>
            <div class="stat-content">
                <span class="stat-value" id="stat-top10">0</span>
                <span class="stat-label">Top 10 Rankings</span>
            </div>
        </div>
    </div>

    <!-- Add Keyword Form -->
    <div class="peanut-card">
        <h3>Add Keyword to Track</h3>
        <form id="add-keyword-form" class="peanut-form-inline">
            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label for="keyword">Keyword</label>
                    <input type="text" id="keyword" name="keyword" placeholder="e.g., wordpress seo plugin" required>
                </div>
                <div class="form-group" style="flex: 2;">
                    <label for="target_url">Target URL (optional)</label>
                    <input type="url" id="target_url" name="target_url" placeholder="<?php echo esc_attr(home_url()); ?>">
                </div>
                <div class="form-group">
                    <label for="search_engine">Engine</label>
                    <select id="search_engine" name="search_engine">
                        <option value="google">Google</option>
                        <option value="bing">Bing</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="location">Location</label>
                    <select id="location" name="location">
                        <option value="us">United States</option>
                        <option value="uk">United Kingdom</option>
                        <option value="ca">Canada</option>
                        <option value="au">Australia</option>
                    </select>
                </div>
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="button button-primary">
                        <span class="dashicons dashicons-plus-alt"></span> Add Keyword
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Keywords Table -->
    <div class="peanut-card">
        <div class="peanut-card-header">
            <h3>Tracked Keywords</h3>
            <button type="button" class="button" id="check-all-rankings">
                <span class="dashicons dashicons-update"></span> Check All Rankings
            </button>
        </div>

        <table class="wp-list-table widefat fixed striped" id="keywords-table">
            <thead>
                <tr>
                    <th style="width: 30%;">Keyword</th>
                    <th style="width: 20%;">Target URL</th>
                    <th style="width: 10%;">Position</th>
                    <th style="width: 10%;">Change</th>
                    <th style="width: 15%;">Last Checked</th>
                    <th style="width: 15%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr class="loading-row">
                    <td colspan="6" style="text-align: center;">
                        <span class="spinner is-active" style="float: none;"></span> Loading keywords...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Ranking History Chart -->
    <div class="peanut-card" id="history-card" style="display: none;">
        <h3>Ranking History: <span id="history-keyword"></span></h3>
        <canvas id="ranking-chart" height="100"></canvas>
    </div>
</div>

<?php elseif ($active_tab === 'audit'): ?>
<!-- SEO Audit Tab -->
<div class="peanut-content">
    <div class="peanut-card">
        <h3>Run SEO Audit</h3>
        <p class="description">Analyze any page for SEO issues and get recommendations.</p>

        <form id="audit-form" class="peanut-form-inline">
            <div class="form-row">
                <div class="form-group" style="flex: 3;">
                    <label for="audit_url">URL to Audit</label>
                    <input type="url" id="audit_url" name="url" value="<?php echo esc_attr(home_url()); ?>" required>
                </div>
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="button button-primary" id="run-audit-btn">
                        <span class="dashicons dashicons-search"></span> Run Audit
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Audit Results -->
    <div id="audit-results" style="display: none;">
        <div class="peanut-stats-row">
            <div class="peanut-stat-card large">
                <div class="stat-content" style="text-align: center;">
                    <span class="stat-value" id="audit-score" style="font-size: 48px;">--</span>
                    <span class="stat-label">SEO Score</span>
                    <span class="stat-grade" id="audit-grade"></span>
                </div>
            </div>
            <div class="peanut-stat-card">
                <div class="stat-icon red"><span class="dashicons dashicons-warning"></span></div>
                <div class="stat-content">
                    <span class="stat-value" id="audit-critical">0</span>
                    <span class="stat-label">Critical Issues</span>
                </div>
            </div>
            <div class="peanut-stat-card">
                <div class="stat-icon orange"><span class="dashicons dashicons-flag"></span></div>
                <div class="stat-content">
                    <span class="stat-value" id="audit-warnings">0</span>
                    <span class="stat-label">Warnings</span>
                </div>
            </div>
            <div class="peanut-stat-card">
                <div class="stat-icon green"><span class="dashicons dashicons-yes-alt"></span></div>
                <div class="stat-content">
                    <span class="stat-value" id="audit-passed">0</span>
                    <span class="stat-label">Passed</span>
                </div>
            </div>
        </div>

        <!-- Issues List -->
        <div class="peanut-card">
            <h3>Audit Results</h3>
            <div id="audit-issues"></div>
        </div>
    </div>

    <!-- Loading State -->
    <div id="audit-loading" style="display: none; text-align: center; padding: 40px;">
        <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
        <p>Running SEO audit... This may take a minute.</p>
    </div>
</div>

<?php else: ?>
<!-- Settings Tab -->
<div class="peanut-content">
    <div class="peanut-card">
        <h3>SEO API Configuration</h3>
        <p class="description">Configure API keys for keyword tracking and PageSpeed analysis.</p>

        <form id="seo-settings-form">
            <?php wp_nonce_field('peanut_seo', 'peanut_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="dataforseo_key">DataForSEO API Key</label>
                    </th>
                    <td>
                        <input type="password" id="dataforseo_key" name="dataforseo_key" class="regular-text"
                               value="<?php echo esc_attr(get_option('peanut_dataforseo_api_key', '')); ?>">
                        <p class="description">
                            Get API credentials from <a href="https://dataforseo.com" target="_blank">DataForSEO.com</a>.
                            Format: login:password
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="pagespeed_key">Google PageSpeed API Key</label>
                    </th>
                    <td>
                        <input type="password" id="pagespeed_key" name="pagespeed_key" class="regular-text"
                               value="<?php echo esc_attr(get_option('peanut_pagespeed_api_key', '')); ?>">
                        <p class="description">
                            Get a free API key from <a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank">Google Cloud Console</a>.
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary" id="seo-settings-save">Save Settings</button>
            </p>

            <div id="seo-settings-notice" class="notice" style="display: none;" role="status" aria-live="polite">
                <p></p>
            </div>
        </form>
    </div>

    <div class="peanut-card">
        <h3>Without API Keys</h3>
        <p>Without API keys, the following features have limited functionality:</p>
        <ul style="list-style: disc; margin-left: 20px;">
            <li><strong>Keyword Tracking:</strong> Rankings cannot be checked automatically. You can still add keywords and manually track positions.</li>
            <li><strong>SEO Audit:</strong> PageSpeed scores won't be included in audits. All other checks work without API keys.</li>
        </ul>
    </div>
</div>
<?php endif; ?>

<style>
.peanut-tabs {
    display: flex;
    gap: 0;
    margin-bottom: 20px;
    border-bottom: 1px solid #c3c4c7;
}
.peanut-tab {
    padding: 12px 20px;
    text-decoration: none;
    color: #50575e;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.peanut-tab:hover {
    color: #2271b1;
}
.peanut-tab.active {
    color: #2271b1;
    border-bottom-color: #2271b1;
    font-weight: 500;
}
.peanut-form-inline .form-row {
    display: flex;
    gap: 15px;
    align-items: flex-start;
}
.peanut-form-inline .form-group {
    display: flex;
    flex-direction: column;
    flex: 1;
}
.peanut-form-inline label {
    font-weight: 500;
    margin-bottom: 5px;
}
.peanut-form-inline input,
.peanut-form-inline select {
    padding: 8px 12px;
}
.position-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 14px;
}
.position-badge.top-3 { background: #d4edda; color: #155724; }
.position-badge.top-10 { background: #cce5ff; color: #004085; }
.position-badge.top-20 { background: #fff3cd; color: #856404; }
.position-badge.top-50 { background: #f8d7da; color: #721c24; }
.position-badge.not-found { background: #e9ecef; color: #6c757d; }
.change-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-weight: 500;
}
.change-badge.positive { color: #28a745; }
.change-badge.negative { color: #dc3545; }
.change-badge.neutral { color: #6c757d; }
.audit-issue {
    padding: 15px;
    margin-bottom: 10px;
    border-radius: 6px;
    border-left: 4px solid;
}
.audit-issue.critical {
    background: #fdf2f2;
    border-color: #dc3545;
}
.audit-issue.warning {
    background: #fffbeb;
    border-color: #f59e0b;
}
.audit-issue.info {
    background: #f0f9ff;
    border-color: #3b82f6;
}
.audit-issue.passed {
    background: #f0fdf4;
    border-color: #22c55e;
}
.audit-issue h4 {
    margin: 0 0 5px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.audit-issue p {
    margin: 0;
    color: #6b7280;
}
.audit-issue .recommendation {
    margin-top: 10px;
    padding: 10px;
    background: rgba(255,255,255,0.5);
    border-radius: 4px;
    font-size: 13px;
}
.stat-grade {
    display: inline-block;
    font-size: 24px;
    font-weight: bold;
    padding: 5px 15px;
    border-radius: 6px;
    margin-top: 5px;
}
.stat-grade.A { background: #d4edda; color: #155724; }
.stat-grade.B { background: #cce5ff; color: #004085; }
.stat-grade.C { background: #fff3cd; color: #856404; }
.stat-grade.D { background: #f8d7da; color: #721c24; }
.stat-grade.F { background: #f5c6cb; color: #721c24; }
.stat-icon.orange { color: #f59e0b; }
</style>

<script>
jQuery(document).ready(function($) {
    const apiBase = '<?php echo esc_url(rest_url(PEANUT_API_NAMESPACE)); ?>';
    const nonce = '<?php echo wp_create_nonce('wp_rest'); ?>';

    // Load keywords
    function loadKeywords() {
        $.ajax({
            url: apiBase + '/seo/keywords',
            headers: { 'X-WP-Nonce': nonce },
            success: function(response) {
                renderKeywords(response.keywords || []);
                updateStats(response.keywords || []);
            }
        });
    }

    function renderKeywords(keywords) {
        const tbody = $('#keywords-table tbody');
        tbody.empty();

        if (keywords.length === 0) {
            tbody.append(`
                <tr>
                    <td colspan="6" style="text-align: center; padding: 40px;">
                        <p style="color: #6b7280;">No keywords tracked yet. Add your first keyword above.</p>
                    </td>
                </tr>
            `);
            return;
        }

        keywords.forEach(function(kw) {
            const position = kw.current_position || kw.last_position;
            const positionBadge = getPositionBadge(position);
            const changeBadge = getChangeBadge(kw.change);
            const lastChecked = kw.last_checked ? new Date(kw.last_checked).toLocaleDateString() : 'Never';

            tbody.append(`
                <tr data-id="${kw.id}">
                    <td>
                        <strong>${escapeHtml(kw.keyword)}</strong>
                        <br><small style="color: #6b7280;">${kw.search_engine} / ${kw.location}</small>
                    </td>
                    <td>
                        <a href="${escapeHtml(kw.target_url)}" target="_blank" style="word-break: break-all;">
                            ${escapeHtml(kw.target_url ? new URL(kw.target_url).pathname : '/')}
                        </a>
                    </td>
                    <td>${positionBadge}</td>
                    <td>${changeBadge}</td>
                    <td>${lastChecked}</td>
                    <td>
                        <button type="button" class="button button-small view-history" data-id="${kw.id}" data-keyword="${escapeHtml(kw.keyword)}">
                            <span class="dashicons dashicons-chart-line"></span>
                        </button>
                        <button type="button" class="button button-small delete-keyword" data-id="${kw.id}">
                            <span class="dashicons dashicons-trash"></span>
                        </button>
                    </td>
                </tr>
            `);
        });
    }

    function getPositionBadge(position) {
        if (!position) return '<span class="position-badge not-found">Not found</span>';
        if (position <= 3) return `<span class="position-badge top-3">#${position}</span>`;
        if (position <= 10) return `<span class="position-badge top-10">#${position}</span>`;
        if (position <= 20) return `<span class="position-badge top-20">#${position}</span>`;
        return `<span class="position-badge top-50">#${position}</span>`;
    }

    function getChangeBadge(change) {
        if (!change || change === 0) return '<span class="change-badge neutral">—</span>';
        if (change > 0) return `<span class="change-badge positive"><span class="dashicons dashicons-arrow-up-alt"></span> ${change}</span>`;
        return `<span class="change-badge negative"><span class="dashicons dashicons-arrow-down-alt"></span> ${Math.abs(change)}</span>`;
    }

    function updateStats(keywords) {
        const total = keywords.length;
        const improved = keywords.filter(k => k.change > 0).length;
        const declined = keywords.filter(k => k.change < 0).length;
        const top10 = keywords.filter(k => k.current_position && k.current_position <= 10).length;

        $('#stat-total').text(total);
        $('#stat-improved').text(improved);
        $('#stat-declined').text(declined);
        $('#stat-top10').text(top10);
    }

    // Add keyword
    $('#add-keyword-form').on('submit', function(e) {
        e.preventDefault();

        const btn = $(this).find('button[type="submit"]');
        btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0;"></span>');

        $.ajax({
            url: apiBase + '/seo/keywords',
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
            contentType: 'application/json',
            data: JSON.stringify({
                keyword: $('#keyword').val(),
                target_url: $('#target_url').val() || '<?php echo esc_url(home_url()); ?>',
                search_engine: $('#search_engine').val(),
                location: $('#location').val()
            }),
            success: function() {
                $('#keyword').val('');
                loadKeywords();
            },
            error: function(xhr) {
                alert(xhr.responseJSON?.error || 'Failed to add keyword');
            },
            complete: function() {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span> Add Keyword');
            }
        });
    });

    // Delete keyword
    $(document).on('click', '.delete-keyword', function() {
        if (!confirm('Delete this keyword?')) return;

        const id = $(this).data('id');
        $.ajax({
            url: apiBase + '/seo/keywords/' + id,
            method: 'DELETE',
            headers: { 'X-WP-Nonce': nonce },
            success: function() {
                loadKeywords();
            }
        });
    });

    // View history
    $(document).on('click', '.view-history', function() {
        const id = $(this).data('id');
        const keyword = $(this).data('keyword');

        $('#history-keyword').text(keyword);
        $('#history-card').show();

        $.ajax({
            url: apiBase + '/seo/keywords/' + id + '/history',
            headers: { 'X-WP-Nonce': nonce },
            success: function(response) {
                renderHistoryChart(response.history || []);
            }
        });
    });

    function renderHistoryChart(history) {
        const ctx = document.getElementById('ranking-chart').getContext('2d');

        if (window.rankingChart) {
            window.rankingChart.destroy();
        }

        window.rankingChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: history.map(h => new Date(h.checked_at).toLocaleDateString()),
                datasets: [{
                    label: 'Position',
                    data: history.map(h => h.position),
                    borderColor: '#2271b1',
                    backgroundColor: 'rgba(34, 113, 177, 0.1)',
                    fill: true,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        reverse: true,
                        min: 1,
                        title: { display: true, text: 'Position' }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    // Check all rankings
    $('#check-all-rankings').on('click', function() {
        const btn = $(this);
        btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0;"></span> Checking...');

        $.ajax({
            url: apiBase + '/seo/keywords/check',
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
            success: function(response) {
                loadKeywords();
                alert(`Checked ${response.checked} keywords`);
            },
            complete: function() {
                btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Check All Rankings');
            }
        });
    });

    // Run audit
    $('#audit-form').on('submit', function(e) {
        e.preventDefault();

        $('#audit-results').hide();
        $('#audit-loading').show();

        $.ajax({
            url: apiBase + '/seo/audit',
            method: 'POST',
            headers: { 'X-WP-Nonce': nonce },
            contentType: 'application/json',
            data: JSON.stringify({ url: $('#audit_url').val() }),
            success: function(response) {
                renderAuditResults(response);
            },
            error: function(xhr) {
                alert('Audit failed: ' + (xhr.responseJSON?.error || 'Unknown error'));
            },
            complete: function() {
                $('#audit-loading').hide();
            }
        });
    });

    function renderAuditResults(data) {
        $('#audit-score').text(data.score);
        $('#audit-grade').text(data.grade).removeClass().addClass('stat-grade ' + data.grade);
        $('#audit-critical').text(data.summary.critical);
        $('#audit-warnings').text(data.summary.warning);
        $('#audit-passed').text(data.summary.passed);

        const container = $('#audit-issues');
        container.empty();

        // Group by category
        const categories = {};
        data.issues.forEach(issue => {
            if (!categories[issue.category]) {
                categories[issue.category] = [];
            }
            categories[issue.category].push(issue);
        });

        const categoryNames = {
            meta: 'Meta Tags',
            content: 'Content',
            images: 'Images',
            links: 'Links',
            schema: 'Structured Data',
            social: 'Social Media',
            mobile: 'Mobile',
            performance: 'Performance',
            security: 'Security'
        };

        Object.keys(categories).forEach(cat => {
            container.append(`<h4 style="margin: 20px 0 10px; text-transform: capitalize;">${categoryNames[cat] || cat}</h4>`);

            categories[cat].forEach(issue => {
                const icon = {
                    critical: 'warning',
                    warning: 'flag',
                    info: 'info',
                    passed: 'yes-alt'
                }[issue.severity] || 'info';

                container.append(`
                    <div class="audit-issue ${issue.severity}">
                        <h4>
                            <span class="dashicons dashicons-${icon}"></span>
                            ${escapeHtml(issue.title)}
                        </h4>
                        <p>${escapeHtml(issue.description)}</p>
                        ${issue.recommendation ? `<div class="recommendation"><strong>Recommendation:</strong> ${escapeHtml(issue.recommendation)}</div>` : ''}
                    </div>
                `);
            });
        });

        $('#audit-results').show();
    }

    // Save settings
    function showSettingsNotice(type, message) {
        const $notice = $('#seo-settings-notice');
        $notice
            .removeClass('notice-success notice-error')
            .addClass(type === 'success' ? 'notice-success' : 'notice-error')
            .show()
            .find('p').text(message);
    }

    $('#seo-settings-form').on('submit', function(e) {
        e.preventDefault();

        const $button = $('#seo-settings-save');
        $button.prop('disabled', true).text('Saving...');

        $.ajax({
            url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'peanut_save_seo_settings',
                nonce: $('[name="peanut_nonce"]').val(),
                dataforseo_key: $('#dataforseo_key').val(),
                pagespeed_key: $('#pagespeed_key').val()
            }
        }).done(function(response) {
            // wp_send_json_success() sets success:true. Anything else is a
            // failure the server chose to report — show what it said.
            if (response && response.success) {
                showSettingsNotice('success', 'API keys saved. Keyword rank checks and PageSpeed scores will use them from the next run.');
                return;
            }

            let detail = '';
            if (response && response.data) {
                detail = typeof response.data === 'string' ? response.data : (response.data.message || '');
            }
            showSettingsNotice('error', detail
                ? 'Not saved: ' + detail
                : 'Not saved. The server rejected the request without a reason. Reload this page and try again; if it repeats, check the PHP error log for peanut_save_seo_settings.');
        }).fail(function(xhr) {
            const status = xhr.status;
            let message;

            if (status === 0) {
                message = 'Not saved: the browser could not reach admin-ajax.php. Check your connection, and whether a security plugin or firewall is blocking admin-ajax.php.';
            } else if (status === 403) {
                message = 'Not saved: your security token expired (HTTP 403). Reload this page and save again.';
            } else if (status === 404) {
                message = 'Not saved: admin-ajax.php returned 404. The Peanut Suite SEO module is not loaded — reactivate the plugin.';
            } else {
                message = 'Not saved: the server returned HTTP ' + status + ' (' + (xhr.statusText || 'error') + '). Check the PHP error log for the peanut_save_seo_settings handler.';
            }

            showSettingsNotice('error', message);
        }).always(function() {
            $button.prop('disabled', false).text('Save Settings');
        });
    });

    function escapeHtml(text) {
        if (!text) return '';
        return $('<div>').text(text).html();
    }

    // Initial load
    <?php if ($active_tab === 'keywords'): ?>
    loadKeywords();
    <?php endif; ?>
});
</script>
