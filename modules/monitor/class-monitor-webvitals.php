<?php
/**
 * Monitor Core Web Vitals
 *
 * Tracks Core Web Vitals (LCP, FID/INP, CLS) and PageSpeed scores.
 * Uses Google PageSpeed Insights API when available.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-monitor-database.php';

class Monitor_WebVitals {

    /**
     * PageSpeed API endpoint
     */
    private const PAGESPEED_API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * Thresholds for Core Web Vitals (Good / Needs Improvement / Poor)
     */
    private const THRESHOLDS = [
        'lcp' => [
            'good' => 2500,      // < 2.5s
            'poor' => 4000,      // > 4s
        ],
        'fid' => [
            'good' => 100,       // < 100ms
            'poor' => 300,       // > 300ms
        ],
        'inp' => [
            'good' => 200,       // < 200ms
            'poor' => 500,       // > 500ms
        ],
        'cls' => [
            'good' => 0.1,       // < 0.1
            'poor' => 0.25,      // > 0.25
        ],
        'ttfb' => [
            'good' => 800,       // < 800ms
            'poor' => 1800,      // > 1800ms
        ],
        'fcp' => [
            'good' => 1800,      // < 1.8s
            'poor' => 3000,      // > 3s
        ],
    ];

    /**
     * Check Core Web Vitals for a site
     */
    public function check_site(object $site): array {
        // Written by the SEO settings screen
        // (PeanutSuite\SEO\SEO_Module::OPTION_PAGESPEED_KEY). Kept as a literal
        // so Monitor does not depend on the SEO module being loaded; the two are
        // pinned together by tests/Regression/SeoSettingsRoundTripTest.php.
        $api_key = get_option('peanut_pagespeed_api_key', '');

        // Try PageSpeed API if key is available
        if (!empty($api_key)) {
            $result = $this->check_via_pagespeed($site->site_url, $api_key);

            if (!is_wp_error($result)) {
                $this->store_result($site->id, $result);
                $this->maybe_send_alert($site, $result);
                return $result;
            }
        }

        // Fallback to basic timing check
        $result = $this->check_basic_performance($site->site_url);
        $this->store_result($site->id, $result);
        $this->maybe_send_alert($site, $result);

        return $result;
    }

    /**
     * Check performance via PageSpeed Insights API
     */
    private function check_via_pagespeed(string $url, string $api_key): array|WP_Error {
        $results = [];

        // Check both mobile and desktop
        foreach (['mobile', 'desktop'] as $strategy) {
            $api_url = add_query_arg([
                'url' => $url,
                'key' => $api_key,
                'strategy' => $strategy,
                'category' => 'performance',
            ], self::PAGESPEED_API);

            $response = wp_remote_get($api_url, [
                'timeout' => 60, // PageSpeed can be slow
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($body['error'])) {
                return new WP_Error(
                    'pagespeed_error',
                    $body['error']['message'] ?? 'PageSpeed API error'
                );
            }

            $results[$strategy] = $this->parse_pagespeed_response($body);
        }

        return [
            'source' => 'pagespeed',
            'checked_at' => current_time('mysql'),
            'mobile' => $results['mobile'],
            'desktop' => $results['desktop'],
            'overall_score' => round(($results['mobile']['score'] + $results['desktop']['score']) / 2),
            'status' => $this->calculate_status($results),
        ];
    }

    /**
     * Parse PageSpeed API response
     */
    private function parse_pagespeed_response(array $data): array {
        $lighthouse = $data['lighthouseResult'] ?? [];
        $audits = $lighthouse['audits'] ?? [];
        $categories = $lighthouse['categories'] ?? [];

        // Performance score (0-100)
        $score = isset($categories['performance']['score'])
            ? round($categories['performance']['score'] * 100)
            : 0;

        // Core Web Vitals
        $metrics = [
            'score' => $score,
            'lcp' => $this->extract_metric($audits, 'largest-contentful-paint', 'numericValue'),
            'fid' => $this->extract_metric($audits, 'max-potential-fid', 'numericValue'),
            'inp' => $this->extract_metric($audits, 'experimental-interaction-to-next-paint', 'numericValue'),
            'cls' => $this->extract_metric($audits, 'cumulative-layout-shift', 'numericValue'),
            'ttfb' => $this->extract_metric($audits, 'server-response-time', 'numericValue'),
            'fcp' => $this->extract_metric($audits, 'first-contentful-paint', 'numericValue'),
            'si' => $this->extract_metric($audits, 'speed-index', 'numericValue'),
            'tbt' => $this->extract_metric($audits, 'total-blocking-time', 'numericValue'),
        ];

        // Add ratings for each metric
        foreach (['lcp', 'fid', 'inp', 'cls', 'ttfb', 'fcp'] as $metric) {
            if ($metrics[$metric] !== null) {
                $metrics[$metric . '_rating'] = $this->rate_metric($metric, $metrics[$metric]);
            }
        }

        // Opportunities for improvement
        $opportunities = [];
        $opportunity_audits = [
            'render-blocking-resources' => 'Eliminate render-blocking resources',
            'unused-css-rules' => 'Remove unused CSS',
            'unused-javascript' => 'Remove unused JavaScript',
            'modern-image-formats' => 'Serve images in modern formats',
            'offscreen-images' => 'Defer offscreen images',
            'uses-optimized-images' => 'Efficiently encode images',
            'uses-responsive-images' => 'Properly size images',
            'efficient-animated-content' => 'Use video formats for animated content',
            'uses-text-compression' => 'Enable text compression',
            'uses-rel-preconnect' => 'Preconnect to required origins',
            'uses-rel-preload' => 'Preload key requests',
        ];

        foreach ($opportunity_audits as $audit_id => $label) {
            if (isset($audits[$audit_id]) && ($audits[$audit_id]['score'] ?? 1) < 1) {
                $savings = $audits[$audit_id]['numericValue'] ?? 0;
                if ($savings > 100) { // Only include significant opportunities (>100ms)
                    $opportunities[] = [
                        'id' => $audit_id,
                        'label' => $label,
                        'savings_ms' => round($savings),
                        'description' => $audits[$audit_id]['description'] ?? '',
                    ];
                }
            }
        }

        // Sort by potential savings
        usort($opportunities, fn($a, $b) => $b['savings_ms'] - $a['savings_ms']);

        $metrics['opportunities'] = array_slice($opportunities, 0, 5);

        return $metrics;
    }

    /**
     * Extract metric from audits
     */
    private function extract_metric(array $audits, string $audit_id, string $key): ?float {
        if (!isset($audits[$audit_id][$key])) {
            return null;
        }

        return round($audits[$audit_id][$key], 3);
    }

    /**
     * Rate a metric based on thresholds
     */
    private function rate_metric(string $metric, float $value): string {
        $thresholds = self::THRESHOLDS[$metric] ?? null;

        if (!$thresholds) {
            return 'unknown';
        }

        if ($value <= $thresholds['good']) {
            return 'good';
        } elseif ($value <= $thresholds['poor']) {
            return 'needs-improvement';
        } else {
            return 'poor';
        }
    }

    /**
     * Basic performance check (fallback when no API key)
     */
    private function check_basic_performance(string $url): array {
        $start_time = microtime(true);

        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
        ]);

        $ttfb = round((microtime(true) - $start_time) * 1000);

        if (is_wp_error($response)) {
            return [
                'source' => 'basic',
                'checked_at' => current_time('mysql'),
                'error' => $response->get_error_message(),
                'status' => 'error',
            ];
        }

        $body = wp_remote_retrieve_body($response);

        // Estimate FCP based on HTML size
        $html_size = strlen($body);
        $estimated_fcp = $ttfb + ($html_size / 1000); // Very rough estimate

        return [
            'source' => 'basic',
            'checked_at' => current_time('mysql'),
            'mobile' => [
                'score' => null,
                'ttfb' => $ttfb,
                'ttfb_rating' => $this->rate_metric('ttfb', $ttfb),
                'html_size' => $html_size,
                'estimated_fcp' => round($estimated_fcp),
            ],
            'desktop' => [
                'score' => null,
                'ttfb' => $ttfb,
                'ttfb_rating' => $this->rate_metric('ttfb', $ttfb),
                'html_size' => $html_size,
                'estimated_fcp' => round($estimated_fcp),
            ],
            'overall_score' => null,
            'status' => $ttfb < 1000 ? 'healthy' : ($ttfb < 2000 ? 'warning' : 'poor'),
        ];
    }

    /**
     * Calculate overall status from results
     */
    private function calculate_status(array $results): string {
        $mobile_score = $results['mobile']['score'] ?? 0;
        $desktop_score = $results['desktop']['score'] ?? 0;
        $avg_score = ($mobile_score + $desktop_score) / 2;

        // Check for poor Core Web Vitals
        $poor_metrics = 0;

        foreach (['lcp', 'fid', 'inp', 'cls'] as $metric) {
            $mobile_rating = $results['mobile'][$metric . '_rating'] ?? 'unknown';
            if ($mobile_rating === 'poor') {
                $poor_metrics++;
            }
        }

        if ($poor_metrics >= 2 || $avg_score < 50) {
            return 'poor';
        } elseif ($poor_metrics >= 1 || $avg_score < 75) {
            return 'needs-improvement';
        } else {
            return 'good';
        }
    }

    /**
     * Store performance result
     */
    private function store_result(int $site_id, array $result): void {
        global $wpdb;
        $table = Monitor_Database::webvitals_table();

        $wpdb->insert($table, [
            'site_id' => $site_id,
            'source' => $result['source'],
            'status' => $result['status'],
            'score_mobile' => $result['mobile']['score'] ?? null,
            'score_desktop' => $result['desktop']['score'] ?? null,
            'lcp_mobile' => $result['mobile']['lcp'] ?? null,
            'lcp_desktop' => $result['desktop']['lcp'] ?? null,
            'fid_mobile' => $result['mobile']['fid'] ?? null,
            'fid_desktop' => $result['desktop']['fid'] ?? null,
            'cls_mobile' => $result['mobile']['cls'] ?? null,
            'cls_desktop' => $result['desktop']['cls'] ?? null,
            'ttfb_mobile' => $result['mobile']['ttfb'] ?? null,
            'ttfb_desktop' => $result['desktop']['ttfb'] ?? null,
            'full_data' => wp_json_encode($result),
            'checked_at' => $result['checked_at'],
        ]);

        // Also update the site's last webvitals data
        $sites_table = Monitor_Database::sites_table();
        $wpdb->update(
            $sites_table,
            ['last_webvitals' => wp_json_encode($result)],
            ['id' => $site_id]
        );
    }

    /**
     * Get performance history for a site
     */
    public function get_history(int $site_id, int $days = 30): array {
        global $wpdb;
        $table = Monitor_Database::webvitals_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table
             WHERE site_id = %d AND checked_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY checked_at DESC",
            $site_id,
            $days
        ), ARRAY_A);
    }

    /**
     * Get latest performance data for a site
     */
    public function get_latest(int $site_id): ?array {
        global $wpdb;
        $table = Monitor_Database::webvitals_table();

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE site_id = %d ORDER BY checked_at DESC LIMIT 1",
            $site_id
        ), ARRAY_A);

        if ($result && isset($result['full_data'])) {
            return json_decode($result['full_data'], true);
        }

        return null;
    }

    /**
     * Get aggregated performance stats for a site
     */
    public function get_stats(int $site_id, int $days = 30): array {
        global $wpdb;
        $table = Monitor_Database::webvitals_table();

        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT
                AVG(score_mobile) as avg_score_mobile,
                AVG(score_desktop) as avg_score_desktop,
                AVG(lcp_mobile) as avg_lcp_mobile,
                AVG(lcp_desktop) as avg_lcp_desktop,
                AVG(cls_mobile) as avg_cls_mobile,
                AVG(cls_desktop) as avg_cls_desktop,
                AVG(ttfb_mobile) as avg_ttfb_mobile,
                AVG(ttfb_desktop) as avg_ttfb_desktop,
                MIN(score_mobile) as min_score_mobile,
                MAX(score_mobile) as max_score_mobile,
                COUNT(*) as total_checks
            FROM $table
            WHERE site_id = %d AND checked_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
            $site_id,
            $days
        ), ARRAY_A);

        // Calculate trends
        $recent = $wpdb->get_row($wpdb->prepare(
            "SELECT AVG(score_mobile) as avg_score
             FROM $table
             WHERE site_id = %d AND checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $site_id
        ), ARRAY_A);

        $previous = $wpdb->get_row($wpdb->prepare(
            "SELECT AVG(score_mobile) as avg_score
             FROM $table
             WHERE site_id = %d
             AND checked_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
             AND checked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            $site_id
        ), ARRAY_A);

        $trend = 0;
        if ($previous['avg_score'] && $recent['avg_score']) {
            $trend = round($recent['avg_score'] - $previous['avg_score'], 1);
        }

        return [
            'avg_score_mobile' => round($stats['avg_score_mobile'] ?? 0),
            'avg_score_desktop' => round($stats['avg_score_desktop'] ?? 0),
            'avg_lcp_mobile' => round($stats['avg_lcp_mobile'] ?? 0),
            'avg_lcp_desktop' => round($stats['avg_lcp_desktop'] ?? 0),
            'avg_cls_mobile' => round($stats['avg_cls_mobile'] ?? 0, 3),
            'avg_cls_desktop' => round($stats['avg_cls_desktop'] ?? 0, 3),
            'avg_ttfb_mobile' => round($stats['avg_ttfb_mobile'] ?? 0),
            'avg_ttfb_desktop' => round($stats['avg_ttfb_desktop'] ?? 0),
            'score_range' => [
                'min' => round($stats['min_score_mobile'] ?? 0),
                'max' => round($stats['max_score_mobile'] ?? 0),
            ],
            'total_checks' => (int) ($stats['total_checks'] ?? 0),
            'trend' => $trend,
            'trend_direction' => $trend > 0 ? 'improving' : ($trend < 0 ? 'declining' : 'stable'),
        ];
    }

    /**
     * Get all sites with performance issues
     */
    public function get_sites_with_issues(?int $user_id = null): array {
        global $wpdb;
        $sites_table = Monitor_Database::sites_table();
        $user_id = $user_id ?? get_current_user_id();

        $sites = $wpdb->get_results($wpdb->prepare(
            "SELECT id, site_name, site_url, last_webvitals
             FROM $sites_table
             WHERE user_id = %d AND status = 'active'
             AND JSON_EXTRACT(last_webvitals, '$.status') IN ('poor', 'needs-improvement')",
            $user_id
        ), ARRAY_A);

        foreach ($sites as &$site) {
            $site['webvitals'] = json_decode($site['last_webvitals'] ?? '{}', true);
            unset($site['last_webvitals']);
        }

        return $sites;
    }

    /**
     * Send alert for poor performance
     */
    private function maybe_send_alert(object $site, array $result): void {
        if ($result['status'] !== 'poor') {
            return;
        }

        // Check if we've already alerted recently (within 24 hours)
        $last_alert = get_transient("peanut_webvitals_alert_{$site->id}");
        if ($last_alert) {
            return;
        }

        $user = get_user_by('ID', $site->user_id ?? get_current_user_id());
        if (!$user) {
            return;
        }

        $mobile_score = $result['mobile']['score'] ?? 0;
        $desktop_score = $result['desktop']['score'] ?? 0;

        $subject = sprintf(
            __('[Peanut Monitor] Performance Alert: %s', 'peanut-suite'),
            $site->site_name
        );

        $issues = [];
        if (($result['mobile']['lcp_rating'] ?? '') === 'poor') {
            $issues[] = sprintf('LCP: %dms (poor)', $result['mobile']['lcp']);
        }
        if (($result['mobile']['cls_rating'] ?? '') === 'poor') {
            $issues[] = sprintf('CLS: %.3f (poor)', $result['mobile']['cls']);
        }
        if (($result['mobile']['fid_rating'] ?? '') === 'poor') {
            $issues[] = sprintf('FID: %dms (poor)', $result['mobile']['fid']);
        }

        $message = sprintf(
            __("Performance issues detected on %s\n\nURL: %s\nMobile Score: %d/100\nDesktop Score: %d/100\n\n%s\n\nView details: %s", 'peanut-suite'),
            $site->site_name,
            $site->site_url,
            $mobile_score,
            $desktop_score,
            !empty($issues) ? "Issues:\n- " . implode("\n- ", $issues) : '',
            admin_url('admin.php?page=peanut-site-detail&id=' . $site->id . '&tab=performance')
        );

        wp_mail($user->user_email, $subject, $message);

        set_transient("peanut_webvitals_alert_{$site->id}", true, DAY_IN_SECONDS);

        do_action('peanut_webvitals_alert_sent', $site, $result);
    }

    /**
     * Get threshold definitions
     */
    public static function get_thresholds(): array {
        return self::THRESHOLDS;
    }

    /**
     * Format metric for display
     */
    public static function format_metric(string $metric, float $value): string {
        switch ($metric) {
            case 'lcp':
            case 'fcp':
            case 'si':
                return number_format($value / 1000, 1) . 's';

            case 'fid':
            case 'inp':
            case 'ttfb':
            case 'tbt':
                return number_format($value) . 'ms';

            case 'cls':
                return number_format($value, 3);

            default:
                return (string) $value;
        }
    }
}
