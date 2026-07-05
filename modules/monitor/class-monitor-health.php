<?php
/**
 * Monitor Health Checker
 *
 * Performs health checks and uptime monitoring for connected sites.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-monitor-database.php';

class Monitor_Health {

    /**
     * @var Monitor_Sites
     */
    private Monitor_Sites $sites;

    /**
     * Health check weights for scoring
     */
    private const CHECK_WEIGHTS = [
        'wp_version' => 15,
        'php_version' => 15,
        'ssl_status' => 20,
        'plugins_updates' => 10,
        'themes_updates' => 5,
        'disk_space' => 10,
        'debug_mode' => 5,
        'backup_status' => 15,
        'file_permissions' => 5,
    ];

    public function __construct() {
        $this->sites = new Monitor_Sites();
    }

    /**
     * Perform full health check on a site
     */
    public function check_site(object $site): array {
        $health_data = $this->sites->remote_request($site, 'health');

        if (is_wp_error($health_data)) {
            $result = [
                'status' => 'offline',
                'score' => 0,
                'error' => $health_data->get_error_message(),
                'checked_at' => current_time('mysql'),
            ];

            $this->log_health($site->id, $result);
            $this->sites->update_health($site->id, $result);

            return $result;
        }

        // Calculate health score
        $score = $this->calculate_score($health_data);
        $status = $this->determine_status($score, $health_data);

        $result = [
            'status' => $status,
            'score' => $score,
            'checks' => $health_data,
            'checked_at' => current_time('mysql'),
        ];

        $this->log_health($site->id, $result);
        $this->sites->update_health($site->id, $result);

        // Send alerts if needed
        $this->maybe_send_alerts($site, $result);

        return $result;
    }

    /**
     * Perform uptime check on a site
     */
    public function check_uptime(object $site): array {
        $start_time = microtime(true);

        // SSRF guard: never probe internal/reserved addresses. Admin-gated, but
        // cheap defense-in-depth against pivoting the manager into a private net.
        if (!Monitor_Sites::is_safe_remote_url($site->site_url)) {
            return [
                'status' => 'down',
                'response_time' => null,
                'status_code' => null,
                'error_message' => __('Blocked: internal or reserved network address.', 'peanut-suite'),
            ];
        }

        $response = wp_remote_head($site->site_url, [
            'timeout' => 10,
            // TLS verification stays ON: silently skipping it let a MITM/tampered
            // response report a compromised child site as "up". Allow opting out
            // per-site only via an explicit, documented filter.
            'sslverify' => (bool) apply_filters('peanut_monitor_uptime_sslverify', true, $site),
        ]);

        $response_time = round((microtime(true) - $start_time) * 1000);

        if (is_wp_error($response)) {
            $result = [
                'status' => 'down',
                'response_time' => null,
                'status_code' => null,
                'error_message' => $response->get_error_message(),
            ];
        } else {
            $status_code = wp_remote_retrieve_response_code($response);
            $result = [
                'status' => ($status_code >= 200 && $status_code < 400) ? 'up' : 'down',
                'response_time' => $response_time,
                'status_code' => $status_code,
                'error_message' => null,
            ];
        }

        $this->log_uptime($site->id, $result);

        // Send alert on status change
        $this->maybe_send_uptime_alert($site, $result);

        return $result;
    }

    /**
     * Calculate health score from check data
     */
    private function calculate_score(array $data): int {
        $score = 100;
        $deductions = [];

        // WordPress version check
        if (!empty($data['wp_version'])) {
            if ($data['wp_version']['needs_update'] ?? false) {
                $deductions['wp_version'] = self::CHECK_WEIGHTS['wp_version'];
            }
        }

        // PHP version check
        if (!empty($data['php_version'])) {
            $php = $data['php_version']['version'] ?? '0';
            if (version_compare($php, '8.0', '<')) {
                $deductions['php_version'] = self::CHECK_WEIGHTS['php_version'];
            } elseif (version_compare($php, '8.1', '<')) {
                $deductions['php_version'] = (int) (self::CHECK_WEIGHTS['php_version'] / 2);
            }
        }

        // SSL check
        if (!empty($data['ssl'])) {
            if (!($data['ssl']['valid'] ?? false)) {
                $deductions['ssl_status'] = self::CHECK_WEIGHTS['ssl_status'];
            } elseif (($data['ssl']['days_until_expiry'] ?? 999) < 14) {
                $deductions['ssl_status'] = (int) (self::CHECK_WEIGHTS['ssl_status'] / 2);
            }
        }

        // Plugin updates
        if (!empty($data['plugins'])) {
            $updates_count = $data['plugins']['updates_available'] ?? 0;
            if ($updates_count > 5) {
                $deductions['plugins_updates'] = self::CHECK_WEIGHTS['plugins_updates'];
            } elseif ($updates_count > 0) {
                $deductions['plugins_updates'] = (int) (self::CHECK_WEIGHTS['plugins_updates'] * ($updates_count / 10));
            }
        }

        // Theme updates
        if (!empty($data['themes'])) {
            $updates_count = $data['themes']['updates_available'] ?? 0;
            if ($updates_count > 0) {
                $deductions['themes_updates'] = self::CHECK_WEIGHTS['themes_updates'];
            }
        }

        // Disk space
        if (!empty($data['disk_space'])) {
            $used_percent = $data['disk_space']['used_percent'] ?? 0;
            if ($used_percent > 90) {
                $deductions['disk_space'] = self::CHECK_WEIGHTS['disk_space'];
            } elseif ($used_percent > 80) {
                $deductions['disk_space'] = (int) (self::CHECK_WEIGHTS['disk_space'] / 2);
            }
        }

        // Debug mode
        if ($data['debug_mode'] ?? false) {
            $deductions['debug_mode'] = self::CHECK_WEIGHTS['debug_mode'];
        }

        // Backup status
        if (!empty($data['backup'])) {
            $days_since = $data['backup']['days_since_last'] ?? 999;
            if ($days_since > 7) {
                $deductions['backup_status'] = self::CHECK_WEIGHTS['backup_status'];
            } elseif ($days_since > 3) {
                $deductions['backup_status'] = (int) (self::CHECK_WEIGHTS['backup_status'] / 2);
            }
        } else {
            // No backup info available
            $deductions['backup_status'] = self::CHECK_WEIGHTS['backup_status'];
        }

        // File permissions
        if (!empty($data['file_permissions'])) {
            if (!($data['file_permissions']['secure'] ?? true)) {
                $deductions['file_permissions'] = self::CHECK_WEIGHTS['file_permissions'];
            }
        }

        $total_deductions = array_sum($deductions);
        return max(0, $score - $total_deductions);
    }

    /**
     * Determine overall status from score and data
     */
    private function determine_status(int $score, array $data): string {
        // Critical issues override score
        if (!($data['ssl']['valid'] ?? true)) {
            return 'critical';
        }

        if ($score >= 80) {
            return 'healthy';
        } elseif ($score >= 50) {
            return 'warning';
        } else {
            return 'critical';
        }
    }

    /**
     * Log health check result
     */
    private function log_health(int $site_id, array $result): void {
        global $wpdb;
        $table = Monitor_Database::health_log_table();

        $wpdb->insert($table, [
            'site_id' => $site_id,
            'status' => $result['status'],
            'score' => $result['score'] ?? 0,
            'checks' => wp_json_encode($result['checks'] ?? $result),
            'checked_at' => current_time('mysql'),
        ]);
    }

    /**
     * Log uptime check result
     */
    private function log_uptime(int $site_id, array $result): void {
        global $wpdb;
        $table = Monitor_Database::uptime_table();

        $wpdb->insert($table, [
            'site_id' => $site_id,
            'status' => $result['status'],
            'response_time' => $result['response_time'],
            'status_code' => $result['status_code'],
            'error_message' => $result['error_message'],
            'checked_at' => current_time('mysql'),
        ]);
    }

    /**
     * Get health history for a site
     */
    public function get_health_history(int $site_id, int $days = 30): array {
        global $wpdb;
        $table = Monitor_Database::health_log_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE site_id = %d AND checked_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY checked_at DESC",
            $site_id,
            $days
        ), ARRAY_A);
    }

    /**
     * Get uptime statistics for a site
     */
    public function get_uptime_stats(int $site_id, int $days = 30): array {
        global $wpdb;
        $table = Monitor_Database::uptime_table();

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_checks,
                SUM(CASE WHEN status = 'up' THEN 1 ELSE 0 END) as up_count,
                AVG(CASE WHEN status = 'up' THEN response_time ELSE NULL END) as avg_response_time,
                MIN(CASE WHEN status = 'up' THEN response_time ELSE NULL END) as min_response_time,
                MAX(CASE WHEN status = 'up' THEN response_time ELSE NULL END) as max_response_time
             FROM $table
             WHERE site_id = %d AND checked_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $site_id,
            $days
        ), ARRAY_A);

        $uptime_percent = 0;
        if ($stats['total_checks'] > 0) {
            $uptime_percent = round(($stats['up_count'] / $stats['total_checks']) * 100, 2);
        }

        // Get recent incidents
        $incidents = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE site_id = %d AND status = 'down' AND checked_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY checked_at DESC
             LIMIT 10",
            $site_id,
            $days
        ), ARRAY_A);

        return [
            'uptime_percent' => $uptime_percent,
            'total_checks' => (int) $stats['total_checks'],
            'avg_response_time' => (int) ($stats['avg_response_time'] ?? 0),
            'min_response_time' => (int) ($stats['min_response_time'] ?? 0),
            'max_response_time' => (int) ($stats['max_response_time'] ?? 0),
            'incidents' => $incidents,
            'incidents_count' => count($incidents),
        ];
    }

    /**
     * Get pending updates for a site
     */
    public function get_pending_updates(object $site): array|WP_Error {
        return $this->sites->remote_request($site, 'updates');
    }

    /**
     * Perform update on child site
     */
    public function perform_update(object $site, string $type, string $slug): array|WP_Error {
        return $this->sites->remote_request($site, 'update', 'POST', [
            'type' => $type,
            'slug' => $slug,
        ]);
    }

    /**
     * Get all pending updates across all sites for user
     */
    public function get_all_pending_updates(): array {
        $sites = $this->sites->get_all_active();
        $all_updates = [];

        foreach ($sites as $site) {
            $updates = $this->get_pending_updates($site);

            if (is_wp_error($updates)) {
                continue;
            }

            if (!empty($updates['plugins'])) {
                foreach ($updates['plugins'] as $plugin) {
                    $slug = $plugin['slug'];
                    if (!isset($all_updates['plugins'][$slug])) {
                        $all_updates['plugins'][$slug] = [
                            'name' => $plugin['name'],
                            'current_version' => $plugin['version'],
                            'new_version' => $plugin['new_version'],
                            'sites' => [],
                        ];
                    }
                    $all_updates['plugins'][$slug]['sites'][] = [
                        'id' => $site->id,
                        'name' => $site->site_name,
                        'url' => $site->site_url,
                    ];
                }
            }

            if (!empty($updates['themes'])) {
                foreach ($updates['themes'] as $theme) {
                    $slug = $theme['slug'];
                    if (!isset($all_updates['themes'][$slug])) {
                        $all_updates['themes'][$slug] = [
                            'name' => $theme['name'],
                            'current_version' => $theme['version'],
                            'new_version' => $theme['new_version'],
                            'sites' => [],
                        ];
                    }
                    $all_updates['themes'][$slug]['sites'][] = [
                        'id' => $site->id,
                        'name' => $site->site_name,
                        'url' => $site->site_url,
                    ];
                }
            }
        }

        return $all_updates;
    }

    /**
     * Send alerts if health is concerning
     */
    private function maybe_send_alerts(object $site, array $result): void {
        // Only alert on critical or offline
        if (!in_array($result['status'], ['critical', 'offline'])) {
            return;
        }

        // Check if we've already alerted recently (within 1 hour)
        $last_alert = get_transient("peanut_monitor_alert_{$site->id}");
        if ($last_alert) {
            return;
        }

        // Send email alert
        $user = get_user_by('ID', $site->user_id ?? get_current_user_id());
        if ($user) {
            $subject = sprintf(
                __('[Peanut Monitor] %s: %s requires attention', 'peanut-suite'),
                ucfirst($result['status']),
                $site->site_name
            );

            $message = sprintf(
                __("Site: %s\nStatus: %s\nScore: %d/100\n\nView details: %s", 'peanut-suite'),
                $site->site_url,
                ucfirst($result['status']),
                $result['score'],
                admin_url('admin.php?page=peanut-monitor&site=' . $site->id)
            );

            wp_mail($user->user_email, $subject, $message);
        }

        // Set transient to prevent spam
        set_transient("peanut_monitor_alert_{$site->id}", true, HOUR_IN_SECONDS);

        do_action('peanut_monitor_alert_sent', $site, $result);
    }

    /**
     * Send uptime alert on status change
     */
    private function maybe_send_uptime_alert(object $site, array $result): void {
        $previous_status = get_transient("peanut_monitor_uptime_status_{$site->id}");

        // Only alert on status change
        if ($previous_status === $result['status']) {
            return;
        }

        // Store new status
        set_transient("peanut_monitor_uptime_status_{$site->id}", $result['status'], DAY_IN_SECONDS);

        // Don't alert on first check
        if ($previous_status === false) {
            return;
        }

        $user = get_user_by('ID', $site->user_id ?? get_current_user_id());
        if (!$user) {
            return;
        }

        if ($result['status'] === 'down') {
            $subject = sprintf(
                __('[Peanut Monitor] Site Down: %s', 'peanut-suite'),
                $site->site_name
            );
            $message = sprintf(
                __("Site %s is currently unreachable.\n\nURL: %s\nError: %s\n\nWe'll notify you when it's back online.", 'peanut-suite'),
                $site->site_name,
                $site->site_url,
                $result['error_message'] ?? 'Unknown error'
            );
        } else {
            $subject = sprintf(
                __('[Peanut Monitor] Site Recovered: %s', 'peanut-suite'),
                $site->site_name
            );
            $message = sprintf(
                __("Site %s is back online.\n\nURL: %s\nResponse time: %dms", 'peanut-suite'),
                $site->site_name,
                $site->site_url,
                $result['response_time']
            );
        }

        wp_mail($user->user_email, $subject, $message);

        do_action('peanut_monitor_uptime_alert_sent', $site, $result, $previous_status);
    }

    /**
     * Sync Peanut Suite analytics from child site
     */
    public function sync_analytics(object $site): array|WP_Error {
        if (!$site->peanut_suite_active) {
            return new WP_Error('no_peanut_suite', __('Peanut Suite is not active on this site.', 'peanut-suite'));
        }

        $analytics = $this->sites->remote_request($site, 'analytics');

        if (is_wp_error($analytics)) {
            return $analytics;
        }

        // Store analytics data
        global $wpdb;
        $table = Monitor_Database::analytics_table();

        // Store monthly metrics
        $wpdb->replace($table, [
            'site_id' => $site->id,
            'period' => 'month',
            'period_start' => date('Y-m-01'),
            'metrics' => wp_json_encode($analytics),
            'synced_at' => current_time('mysql'),
        ]);

        return $analytics;
    }

    /**
     * Get aggregated analytics across all sites
     */
    public function get_aggregated_analytics(?int $user_id = null): array {
        global $wpdb;
        $sites_table = Monitor_Database::sites_table();
        $analytics_table = Monitor_Database::analytics_table();
        $user_id = $user_id ?? get_current_user_id();

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.site_name, s.site_url, a.metrics
             FROM $sites_table s
             LEFT JOIN $analytics_table a ON s.id = a.site_id AND a.period = 'month' AND a.period_start = DATE_FORMAT(NOW(), '%%Y-%%m-01')
             WHERE s.user_id = %d AND s.status = 'active' AND s.peanut_suite_active = 1",
            $user_id
        ), ARRAY_A);

        $aggregated = [
            'contacts' => 0,
            'utm_clicks' => 0,
            'link_clicks' => 0,
            'forms_submitted' => 0,
            'sites' => [],
        ];

        foreach ($results as $row) {
            $metrics = json_decode($row['metrics'] ?? '{}', true);

            $site_data = [
                'id' => $row['id'],
                'name' => $row['site_name'],
                'url' => $row['site_url'],
                'contacts' => $metrics['contacts'] ?? 0,
                'utm_clicks' => $metrics['utm_clicks'] ?? 0,
                'link_clicks' => $metrics['link_clicks'] ?? 0,
            ];

            $aggregated['contacts'] += $site_data['contacts'];
            $aggregated['utm_clicks'] += $site_data['utm_clicks'];
            $aggregated['link_clicks'] += $site_data['link_clicks'];
            $aggregated['forms_submitted'] += $metrics['forms_submitted'] ?? 0;
            $aggregated['sites'][] = $site_data;
        }

        // Sort sites by contacts descending
        usort($aggregated['sites'], fn($a, $b) => $b['contacts'] - $a['contacts']);

        return $aggregated;
    }
}
