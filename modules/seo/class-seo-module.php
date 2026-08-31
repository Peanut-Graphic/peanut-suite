<?php
/**
 * SEO Module
 *
 * Keyword rank tracking and SEO audit tools.
 */

namespace PeanutSuite\SEO;

if (!defined('ABSPATH')) {
    exit;
}

class SEO_Module {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        $this->init();
    }

    private function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('peanut_daily_maintenance_tasks', [$this, 'check_rankings']);

        // Schedule ranking checks
        if (!wp_next_scheduled('peanut_check_keyword_rankings')) {
            wp_schedule_event(time(), 'daily', 'peanut_check_keyword_rankings');
        }
        add_action('peanut_check_keyword_rankings', [$this, 'check_rankings']);

        // AJAX handlers
        add_action('wp_ajax_peanut_save_seo_settings', [$this, 'ajax_save_settings']);
    }

    /**
     * Option name holding the DataForSEO API key.
     *
     * This is the option every consumer reads (SEO_Module::fetch_ranking()), so
     * it is the option the settings form must write. Do not introduce a second
     * storage shape for the same secret.
     */
    public const OPTION_DATAFORSEO_KEY = 'peanut_dataforseo_api_key';

    /**
     * Option name holding the Google PageSpeed API key.
     *
     * Read by SEO_Auditor::check_page_speed() and by the Monitor module's
     * Core Web Vitals check (Monitor_WebVitals::check_site()).
     */
    public const OPTION_PAGESPEED_KEY = 'peanut_pagespeed_api_key';

    /**
     * AJAX: Save SEO settings
     *
     * Writes the two API keys to the flat options the consumers read. Posted
     * field names (dataforseo_key / pagespeed_key) and the nonce action
     * ('peanut_seo') must stay in step with core/admin/views/seo.php.
     */
    public function ajax_save_settings(): void {
        check_ajax_referer('peanut_seo', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You need the manage_options capability to change SEO API keys.'], 403);
        }

        $dataforseo_key = sanitize_text_field(wp_unslash($_POST['dataforseo_key'] ?? ''));
        $pagespeed_key = sanitize_text_field(wp_unslash($_POST['pagespeed_key'] ?? ''));

        update_option(self::OPTION_DATAFORSEO_KEY, $dataforseo_key);
        update_option(self::OPTION_PAGESPEED_KEY, $pagespeed_key);

        wp_send_json_success(['message' => 'Settings saved']);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route(PEANUT_API_NAMESPACE, '/seo/keywords', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_keywords'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'add_keyword'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/seo/keywords/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::DELETABLE,
            'callback' => [$this, 'delete_keyword'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/seo/keywords/(?P<id>\d+)/history', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_keyword_history'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/seo/keywords/check', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'manual_check'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/seo/audit', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'run_audit'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/seo/audit/results', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_audit_results'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);
    }

    public function admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Get all tracked keywords
     */
    public function get_keywords(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_seo_keywords';

        $keywords = $wpdb->get_results("
            SELECT k.*,
                   (SELECT position FROM {$wpdb->prefix}peanut_seo_rankings
                    WHERE keyword_id = k.id ORDER BY checked_at DESC LIMIT 1) as current_position,
                   (SELECT position FROM {$wpdb->prefix}peanut_seo_rankings
                    WHERE keyword_id = k.id ORDER BY checked_at DESC LIMIT 1 OFFSET 1) as previous_position
            FROM $table k
            ORDER BY k.created_at DESC
        ", ARRAY_A);

        // Calculate position change
        foreach ($keywords as &$kw) {
            $current = $kw['current_position'] ? (int)$kw['current_position'] : null;
            $previous = $kw['previous_position'] ? (int)$kw['previous_position'] : null;

            if ($current !== null && $previous !== null) {
                $kw['change'] = $previous - $current; // Positive = improved
            } else {
                $kw['change'] = 0;
            }
        }

        return new \WP_REST_Response(['keywords' => $keywords], 200);
    }

    /**
     * Add a keyword to track
     */
    public function add_keyword(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_seo_keywords';

        $keyword = sanitize_text_field($request->get_param('keyword'));
        $target_url = esc_url_raw($request->get_param('target_url') ?: home_url());
        $search_engine = sanitize_text_field($request->get_param('search_engine') ?: 'google');
        $location = sanitize_text_field($request->get_param('location') ?: 'us');

        if (empty($keyword)) {
            return new \WP_REST_Response(['error' => 'Keyword is required'], 400);
        }

        // Check limit based on license
        $license = peanut_get_license();
        $tier = $license['tier'] ?? 'free';
        $limit = $tier === 'agency' ? 999 : ($tier === 'pro' ? 50 : 10);

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count >= $limit) {
            return new \WP_REST_Response([
                'error' => "Keyword limit reached ($limit). Upgrade for more."
            ], 400);
        }

        $wpdb->insert($table, [
            'keyword' => $keyword,
            'target_url' => $target_url,
            'search_engine' => $search_engine,
            'location' => $location,
            'created_at' => current_time('mysql'),
        ]);

        $id = $wpdb->insert_id;

        // Check ranking immediately
        $this->check_single_keyword($id);

        return new \WP_REST_Response(['id' => $id, 'message' => 'Keyword added'], 201);
    }

    /**
     * Delete a keyword
     */
    public function delete_keyword(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');

        $wpdb->delete($wpdb->prefix . 'peanut_seo_keywords', ['id' => $id]);
        $wpdb->delete($wpdb->prefix . 'peanut_seo_rankings', ['keyword_id' => $id]);

        return new \WP_REST_Response(['message' => 'Keyword deleted'], 200);
    }

    /**
     * Get ranking history for a keyword
     */
    public function get_keyword_history(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;
        $id = (int) $request->get_param('id');
        $days = (int) ($request->get_param('days') ?: 30);

        $table = $wpdb->prefix . 'peanut_seo_rankings';
        $history = $wpdb->get_results($wpdb->prepare("
            SELECT position, ranking_url, checked_at
            FROM $table
            WHERE keyword_id = %d
            AND checked_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            ORDER BY checked_at ASC
        ", $id, $days), ARRAY_A);

        return new \WP_REST_Response(['history' => $history], 200);
    }

    /**
     * Manual check all keywords
     */
    public function manual_check(\WP_REST_Request $request): \WP_REST_Response {
        $results = $this->check_rankings();
        return new \WP_REST_Response(['checked' => $results], 200);
    }

    /**
     * Check rankings for all keywords
     */
    public function check_rankings(): int {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_seo_keywords';

        // Cap the per-run batch. An unbounded SELECT + external lookup + sleep(2)
        // per row would approach (and on large installs exceed) max_execution_time
        // and never finish. We process the least-recently-checked keywords first
        // (NULL last_checked sorts first in ASC), so every keyword is eventually
        // refreshed across runs. Mirrors the backlinks module's LIMIT 50 batching.
        $batch_size = (int) apply_filters('peanut_seo_rankings_batch_size', 50);
        if ($batch_size < 1) {
            $batch_size = 50;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table uses $wpdb->prefix (not user-controlled); LIMIT is a prepared int
        $keywords = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table ORDER BY last_checked ASC LIMIT %d",
            $batch_size
        ), ARRAY_A);

        $total = count($keywords);
        $checked = 0;

        foreach ($keywords as $keyword) {
            $this->check_single_keyword($keyword['id']);
            $checked++;

            // Rate limit - don't hammer search engines
            if ($checked < $total) {
                sleep(2);
            }
        }

        return $checked;
    }

    /**
     * Check ranking for a single keyword
     */
    private function check_single_keyword(int $keyword_id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_seo_keywords';
        $rankings_table = $wpdb->prefix . 'peanut_seo_rankings';

        $keyword = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d",
            $keyword_id
        ), ARRAY_A);

        if (!$keyword) {
            return null;
        }

        // Use a rank checking service or scraper
        // For now, simulate with a placeholder
        $result = $this->fetch_ranking($keyword['keyword'], $keyword['target_url'], $keyword['search_engine']);

        // Store the result
        $wpdb->insert($rankings_table, [
            'keyword_id' => $keyword_id,
            'position' => $result['position'],
            'ranking_url' => $result['url'] ?? '',
            'checked_at' => current_time('mysql'),
        ]);

        // Update last checked
        $wpdb->update($table, [
            'last_position' => $result['position'],
            'last_checked' => current_time('mysql'),
        ], ['id' => $keyword_id]);

        return $result;
    }

    /**
     * Fetch ranking from search engine
     * This is a placeholder - in production, use an API like DataForSEO, SEMrush, etc.
     */
    private function fetch_ranking(string $keyword, string $target_url, string $engine = 'google'): array {
        // Option 1: Use Google Custom Search API (limited)
        // Option 2: Use DataForSEO API
        // Option 3: Use SEMrush API
        // Option 4: Custom scraper (not recommended - ToS issues)

        $api_key = get_option(self::OPTION_DATAFORSEO_KEY, '');

        if (!empty($api_key)) {
            return $this->fetch_from_dataforseo($keyword, $target_url, $api_key);
        }

        // Fallback: Return not found
        return [
            'position' => null,
            'url' => null,
            'error' => 'No ranking API configured',
        ];
    }

    /**
     * Fetch ranking from DataForSEO
     */
    private function fetch_from_dataforseo(string $keyword, string $target_url, string $api_key): array {
        $response = wp_remote_post('https://api.dataforseo.com/v3/serp/google/organic/live/regular', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($api_key),
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([[
                'keyword' => $keyword,
                'location_code' => 2840, // US
                'language_code' => 'en',
                'depth' => 100,
            ]]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return ['position' => null, 'url' => null, 'error' => $response->get_error_message()];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $domain = wp_parse_url($target_url, PHP_URL_HOST);

        if (!empty($body['tasks'][0]['result'][0]['items'])) {
            foreach ($body['tasks'][0]['result'][0]['items'] as $item) {
                if (isset($item['domain']) && strpos($item['domain'], $domain) !== false) {
                    return [
                        'position' => $item['rank_absolute'] ?? $item['rank_group'],
                        'url' => $item['url'] ?? '',
                    ];
                }
            }
        }

        return ['position' => null, 'url' => null];
    }

    /**
     * Run SEO audit on a URL
     */
    public function run_audit(\WP_REST_Request $request): \WP_REST_Response {
        $url = esc_url_raw($request->get_param('url') ?: home_url());

        $audit = new SEO_Auditor();
        $results = $audit->audit_url($url);

        // Store results
        update_option('peanut_seo_last_audit', [
            'url' => $url,
            'results' => $results,
            'timestamp' => time(),
        ]);

        return new \WP_REST_Response($results, 200);
    }

    /**
     * Get last audit results
     */
    public function get_audit_results(\WP_REST_Request $request): \WP_REST_Response {
        $audit = get_option('peanut_seo_last_audit', null);
        return new \WP_REST_Response($audit ?: ['error' => 'No audit data'], 200);
    }

    /**
     * Create database tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $keywords_table = $wpdb->prefix . 'peanut_seo_keywords';
        $rankings_table = $wpdb->prefix . 'peanut_seo_rankings';
        $audit_table = $wpdb->prefix . 'peanut_seo_audits';

        $sql = "
        CREATE TABLE $keywords_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword varchar(255) NOT NULL,
            target_url varchar(500) DEFAULT '',
            search_engine varchar(20) DEFAULT 'google',
            location varchar(10) DEFAULT 'us',
            last_position int(11) DEFAULT NULL,
            last_checked datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY keyword (keyword(100))
        ) $charset;

        CREATE TABLE $rankings_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword_id bigint(20) UNSIGNED NOT NULL,
            position int(11) DEFAULT NULL,
            ranking_url varchar(500) DEFAULT '',
            checked_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY keyword_id (keyword_id),
            KEY checked_at (checked_at)
        ) $charset;

        CREATE TABLE $audit_table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            url varchar(500) NOT NULL,
            score int(11) DEFAULT 0,
            issues_critical int(11) DEFAULT 0,
            issues_warning int(11) DEFAULT 0,
            issues_info int(11) DEFAULT 0,
            results longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY url (url(100))
        ) $charset;
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}

/**
 * SEO Auditor class
 */
class SEO_Auditor {

    private array $issues = [];
    private int $score = 100;

    /**
     * Run full SEO audit on a URL
     */
    public function audit_url(string $url): array {
        $this->issues = [];
        $this->score = 100;

        // Fetch the page
        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => $response->get_error_message(),
            ];
        }

        $html = wp_remote_retrieve_body($response);
        $headers = wp_remote_retrieve_headers($response);

        // Parse HTML
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);

        // Run all checks
        $this->check_title($xpath);
        $this->check_meta_description($xpath);
        $this->check_headings($xpath);
        $this->check_images($xpath);
        $this->check_links($xpath, $url);
        $this->check_canonical($xpath);
        $this->check_robots($xpath);
        $this->check_schema($html);
        $this->check_open_graph($xpath);
        $this->check_mobile($xpath);
        $this->check_page_speed($url);
        $this->check_ssl($url);
        $this->check_headers($headers);

        return [
            'success' => true,
            'url' => $url,
            'score' => max(0, $this->score),
            'grade' => $this->get_grade($this->score),
            'issues' => $this->issues,
            'summary' => [
                'critical' => count(array_filter($this->issues, fn($i) => $i['severity'] === 'critical')),
                'warning' => count(array_filter($this->issues, fn($i) => $i['severity'] === 'warning')),
                'info' => count(array_filter($this->issues, fn($i) => $i['severity'] === 'info')),
                'passed' => count(array_filter($this->issues, fn($i) => $i['severity'] === 'passed')),
            ],
        ];
    }

    private function add_issue(string $category, string $severity, string $title, string $description, ?string $recommendation = null): void {
        $points = match($severity) {
            'critical' => 15,
            'warning' => 5,
            'info' => 1,
            default => 0,
        };

        $this->score -= $points;

        $this->issues[] = [
            'category' => $category,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'recommendation' => $recommendation,
        ];
    }

    private function add_passed(string $category, string $title, string $description): void {
        $this->issues[] = [
            'category' => $category,
            'severity' => 'passed',
            'title' => $title,
            'description' => $description,
        ];
    }

    private function check_title(\DOMXPath $xpath): void {
        $titles = $xpath->query('//title');

        if ($titles->length === 0) {
            $this->add_issue('meta', 'critical', 'Missing Title Tag',
                'The page does not have a title tag.',
                'Add a unique, descriptive title tag between 50-60 characters.');
            return;
        }

        $title = trim($titles->item(0)->textContent);
        $length = strlen($title);

        if ($length === 0) {
            $this->add_issue('meta', 'critical', 'Empty Title Tag',
                'The title tag exists but is empty.',
                'Add a descriptive title between 50-60 characters.');
        } elseif ($length < 30) {
            $this->add_issue('meta', 'warning', 'Title Too Short',
                "Title is only $length characters. Recommended: 50-60.",
                'Expand your title to better describe the page content.');
        } elseif ($length > 60) {
            $this->add_issue('meta', 'warning', 'Title Too Long',
                "Title is $length characters. May be truncated in search results.",
                'Shorten to 60 characters or less.');
        } else {
            $this->add_passed('meta', 'Title Tag', "Good title length ($length characters).");
        }
    }

    private function check_meta_description(\DOMXPath $xpath): void {
        $metas = $xpath->query('//meta[@name="description"]');

        if ($metas->length === 0) {
            $this->add_issue('meta', 'warning', 'Missing Meta Description',
                'No meta description found.',
                'Add a compelling meta description between 150-160 characters.');
            return;
        }

        $desc = $metas->item(0)->getAttribute('content');
        $length = strlen($desc);

        if ($length === 0) {
            $this->add_issue('meta', 'warning', 'Empty Meta Description',
                'Meta description tag exists but is empty.',
                'Add a compelling description between 150-160 characters.');
        } elseif ($length < 120) {
            $this->add_issue('meta', 'info', 'Meta Description Short',
                "Description is $length characters. Could be longer.",
                'Expand to 150-160 characters for better visibility.');
        } elseif ($length > 160) {
            $this->add_issue('meta', 'info', 'Meta Description Long',
                "Description is $length characters. May be truncated.",
                'Shorten to 160 characters or less.');
        } else {
            $this->add_passed('meta', 'Meta Description', "Good length ($length characters).");
        }
    }

    private function check_headings(\DOMXPath $xpath): void {
        $h1s = $xpath->query('//h1');

        if ($h1s->length === 0) {
            $this->add_issue('content', 'critical', 'Missing H1 Tag',
                'No H1 heading found on the page.',
                'Add a single H1 tag with your main page topic.');
        } elseif ($h1s->length > 1) {
            $this->add_issue('content', 'warning', 'Multiple H1 Tags',
                "Found {$h1s->length} H1 tags. Should have exactly one.",
                'Use only one H1 per page for the main heading.');
        } else {
            $h1_text = trim($h1s->item(0)->textContent);
            if (strlen($h1_text) < 10) {
                $this->add_issue('content', 'info', 'H1 Too Short',
                    'H1 heading is very short.',
                    'Make your H1 more descriptive.');
            } else {
                $this->add_passed('content', 'H1 Tag', 'Page has exactly one H1 tag.');
            }
        }

        // Check heading hierarchy
        $headings = [];
        for ($i = 1; $i <= 6; $i++) {
            $headings[$i] = $xpath->query("//h$i")->length;
        }

        // Check for skipped levels
        $prev_level = 0;
        foreach ($headings as $level => $count) {
            if ($count > 0) {
                if ($prev_level > 0 && $level > $prev_level + 1) {
                    $this->add_issue('content', 'info', 'Heading Hierarchy Skip',
                        "Heading level skipped from H$prev_level to H$level.",
                        'Maintain proper heading hierarchy (H1 → H2 → H3).');
                    break;
                }
                $prev_level = $level;
            }
        }
    }

    private function check_images(\DOMXPath $xpath): void {
        $images = $xpath->query('//img');
        $missing_alt = 0;
        $empty_alt = 0;

        foreach ($images as $img) {
            if (!$img->hasAttribute('alt')) {
                $missing_alt++;
            } elseif (trim($img->getAttribute('alt')) === '') {
                $empty_alt++;
            }
        }

        $total = $images->length;

        if ($total === 0) {
            $this->add_passed('images', 'No Images', 'No images found to audit.');
            return;
        }

        if ($missing_alt > 0) {
            $this->add_issue('images', 'warning', 'Images Missing Alt Text',
                "$missing_alt of $total images have no alt attribute.",
                'Add descriptive alt text to all images for accessibility and SEO.');
        }

        if ($empty_alt > 0) {
            $this->add_issue('images', 'info', 'Images with Empty Alt',
                "$empty_alt images have empty alt attributes.",
                'Add descriptive alt text unless image is decorative.');
        }

        if ($missing_alt === 0 && $empty_alt === 0) {
            $this->add_passed('images', 'Image Alt Text', "All $total images have alt text.");
        }
    }

    private function check_links(\DOMXPath $xpath, string $page_url): void {
        $links = $xpath->query('//a[@href]');
        $broken = [];
        $nofollow = 0;
        $external = 0;
        $internal = 0;

        $page_domain = wp_parse_url($page_url, PHP_URL_HOST);

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $rel = $link->getAttribute('rel');

            if (strpos($rel, 'nofollow') !== false) {
                $nofollow++;
            }

            // Skip anchors, javascript, mailto
            if (empty($href) || $href[0] === '#' || strpos($href, 'javascript:') === 0 || strpos($href, 'mailto:') === 0) {
                continue;
            }

            $link_domain = wp_parse_url($href, PHP_URL_HOST);

            if ($link_domain && $link_domain !== $page_domain) {
                $external++;
            } else {
                $internal++;
            }
        }

        $total = $links->length;

        if ($internal < 3) {
            $this->add_issue('links', 'info', 'Few Internal Links',
                "Only $internal internal links found.",
                'Add more internal links to help users and search engines navigate.');
        } else {
            $this->add_passed('links', 'Internal Links', "$internal internal links found.");
        }

        $this->add_passed('links', 'Link Summary',
            "$total total links: $internal internal, $external external, $nofollow nofollow.");
    }

    private function check_canonical(\DOMXPath $xpath): void {
        $canonical = $xpath->query('//link[@rel="canonical"]');

        if ($canonical->length === 0) {
            $this->add_issue('meta', 'warning', 'Missing Canonical Tag',
                'No canonical URL specified.',
                'Add a canonical tag to prevent duplicate content issues.');
        } else {
            $href = $canonical->item(0)->getAttribute('href');
            $this->add_passed('meta', 'Canonical Tag', "Canonical set to: $href");
        }
    }

    private function check_robots(\DOMXPath $xpath): void {
        $robots = $xpath->query('//meta[@name="robots"]');

        if ($robots->length > 0) {
            $content = $robots->item(0)->getAttribute('content');

            if (strpos($content, 'noindex') !== false) {
                $this->add_issue('meta', 'critical', 'Page Set to Noindex',
                    'This page will not be indexed by search engines.',
                    'Remove noindex if this page should appear in search results.');
            }

            if (strpos($content, 'nofollow') !== false) {
                $this->add_issue('meta', 'warning', 'Page Set to Nofollow',
                    'Links on this page will not pass PageRank.',
                    'Remove nofollow if you want link equity to flow.');
            }
        }
    }

    private function check_schema(string $html): void {
        $has_schema = strpos($html, 'application/ld+json') !== false
                   || strpos($html, 'itemtype="http://schema.org') !== false
                   || strpos($html, 'itemtype="https://schema.org') !== false;

        if (!$has_schema) {
            $this->add_issue('schema', 'info', 'No Schema Markup',
                'No structured data found.',
                'Add Schema.org markup for rich search results.');
        } else {
            $this->add_passed('schema', 'Schema Markup', 'Structured data found on page.');
        }
    }

    private function check_open_graph(\DOMXPath $xpath): void {
        $og_title = $xpath->query('//meta[@property="og:title"]');
        $og_desc = $xpath->query('//meta[@property="og:description"]');
        $og_image = $xpath->query('//meta[@property="og:image"]');

        $missing = [];
        if ($og_title->length === 0) $missing[] = 'og:title';
        if ($og_desc->length === 0) $missing[] = 'og:description';
        if ($og_image->length === 0) $missing[] = 'og:image';

        if (count($missing) > 0) {
            $this->add_issue('social', 'info', 'Missing Open Graph Tags',
                'Missing: ' . implode(', ', $missing),
                'Add Open Graph meta tags for better social sharing.');
        } else {
            $this->add_passed('social', 'Open Graph', 'All essential OG tags present.');
        }
    }

    private function check_mobile(\DOMXPath $xpath): void {
        $viewport = $xpath->query('//meta[@name="viewport"]');

        if ($viewport->length === 0) {
            $this->add_issue('mobile', 'critical', 'Missing Viewport Meta',
                'No viewport meta tag found.',
                'Add viewport meta for mobile responsiveness.');
        } else {
            $content = $viewport->item(0)->getAttribute('content');
            if (strpos($content, 'width=device-width') !== false) {
                $this->add_passed('mobile', 'Viewport Tag', 'Mobile viewport properly configured.');
            } else {
                $this->add_issue('mobile', 'warning', 'Viewport Not Responsive',
                    'Viewport does not use device-width.',
                    'Use width=device-width for proper mobile display.');
            }
        }
    }

    private function check_page_speed(string $url): void {
        // Use PageSpeed API if available
        $api_key = get_option(SEO_Module::OPTION_PAGESPEED_KEY, '');

        if (empty($api_key)) {
            $this->add_issue('performance', 'info', 'PageSpeed Not Checked',
                'No PageSpeed API key configured.',
                'Add API key in settings for performance metrics.');
            return;
        }

        $response = wp_remote_get(add_query_arg([
            'url' => urlencode($url),
            'key' => $api_key,
            'strategy' => 'mobile',
        ], 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed'), [
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $score = ($data['lighthouseResult']['categories']['performance']['score'] ?? 0) * 100;

        if ($score < 50) {
            $this->add_issue('performance', 'critical', 'Poor PageSpeed Score',
                "Mobile score: $score/100",
                'Optimize images, reduce JavaScript, and improve server response time.');
        } elseif ($score < 90) {
            $this->add_issue('performance', 'warning', 'PageSpeed Needs Work',
                "Mobile score: $score/100",
                'Review Core Web Vitals and optimize as needed.');
        } else {
            $this->add_passed('performance', 'PageSpeed Score', "Excellent score: $score/100");
        }
    }

    private function check_ssl(string $url): void {
        if (strpos($url, 'https://') === 0) {
            $this->add_passed('security', 'HTTPS', 'Page is served over HTTPS.');
        } else {
            $this->add_issue('security', 'critical', 'Not Using HTTPS',
                'Page is not served over a secure connection.',
                'Enable SSL certificate and redirect HTTP to HTTPS.');
        }
    }

    private function check_headers($headers): void {
        $security_headers = [
            'x-content-type-options' => 'X-Content-Type-Options',
            'x-frame-options' => 'X-Frame-Options',
            'x-xss-protection' => 'X-XSS-Protection',
        ];

        $missing = [];
        foreach ($security_headers as $header => $name) {
            if (!isset($headers[$header])) {
                $missing[] = $name;
            }
        }

        if (count($missing) > 0) {
            $this->add_issue('security', 'info', 'Missing Security Headers',
                'Missing: ' . implode(', ', $missing),
                'Add security headers to protect against common attacks.');
        }
    }

    private function get_grade(int $score): string {
        return match(true) {
            $score >= 90 => 'A',
            $score >= 80 => 'B',
            $score >= 70 => 'C',
            $score >= 60 => 'D',
            default => 'F',
        };
    }
}
