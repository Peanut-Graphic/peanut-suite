<?php
/**
 * ADA Compliance / Accessibility Module
 *
 * Frontend accessibility widget, scanner, and compliance tools.
 */

namespace PeanutSuite\Accessibility;

if (!defined('ABSPATH')) {
    exit;
}

class Accessibility_Module {

    private static ?self $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // init() is called by Peanut_Module_Manager after instantiation.
    }

    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);

        // Add accessibility widget to frontend
        if ($this->is_widget_enabled()) {
            add_action('wp_footer', [$this, 'render_widget']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_widget_assets']);
        }

        // Admin
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handlers
        add_action('wp_ajax_peanut_save_accessibility_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_peanut_run_accessibility_scan', [$this, 'ajax_run_scan']);
    }

    /**
     * AJAX: Save accessibility settings
     */
    public function ajax_save_settings(): void {
        check_ajax_referer('peanut_accessibility', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $settings = [
            'widget_enabled' => !empty($_POST['widget_enabled']),
            'widget_position' => sanitize_text_field($_POST['widget_position'] ?? 'bottom-right'),
            'widget_color' => sanitize_hex_color($_POST['widget_color'] ?? '#2271b1'),
            'skip_link' => !empty($_POST['skip_link']),
        ];

        update_option('peanut_accessibility_settings', $settings);
        wp_send_json_success(['message' => 'Settings saved']);
    }

    /**
     * AJAX: Run accessibility scan
     */
    public function ajax_run_scan(): void {
        check_ajax_referer('peanut_accessibility', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $scanner = new Accessibility_Scanner();
        $results = $scanner->scan(home_url('/'));

        update_option('peanut_accessibility_scan', $results);
        update_option('peanut_accessibility_last_scan', current_time('mysql'));

        wp_send_json_success($results);
    }

    /**
     * Register REST routes
     */
    public function register_routes(): void {
        register_rest_route(PEANUT_API_NAMESPACE, '/accessibility/settings', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_settings'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'save_settings'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/accessibility/scan', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'run_scan'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/accessibility/scan/results', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_scan_results'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/accessibility/alt-text', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get_alt_text_report'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/accessibility/contrast', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'check_contrast'],
            'permission_callback' => [$this, 'admin_permission'],
        ]);

        register_rest_route(PEANUT_API_NAMESPACE, '/accessibility/statement', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'get_statement'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'generate_statement'],
                'permission_callback' => [$this, 'admin_permission'],
            ],
        ]);
    }

    public function admin_permission(): bool {
        return current_user_can('manage_options');
    }

    /**
     * Check if widget is enabled
     */
    public function is_widget_enabled(): bool {
        $settings = $this->get_accessibility_settings();
        return !empty($settings['widget_enabled']);
    }

    /**
     * Get accessibility settings
     */
    public function get_accessibility_settings(): array {
        return get_option('peanut_accessibility_settings', [
            'widget_enabled' => true,
            'widget_position' => 'bottom-right',
            'widget_color' => '#2271b1',
            'features' => [
                'font_size' => true,
                'contrast' => true,
                'highlight_links' => true,
                'readable_font' => true,
                'focus_mode' => true,
                'animations' => true,
                'cursor_size' => true,
                'text_spacing' => true,
            ],
            'skip_link' => true,
            'auto_alt_detection' => true,
        ]);
    }

    /**
     * Get settings via API
     */
    public function get_settings(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response($this->get_accessibility_settings(), 200);
    }

    /**
     * Save settings via API
     */
    public function save_settings(\WP_REST_Request $request): \WP_REST_Response {
        $settings = [
            'widget_enabled' => (bool) $request->get_param('widget_enabled'),
            'widget_position' => sanitize_text_field($request->get_param('widget_position') ?: 'bottom-right'),
            'widget_color' => sanitize_hex_color($request->get_param('widget_color') ?: '#2271b1'),
            'features' => [
                'font_size' => (bool) $request->get_param('feature_font_size'),
                'contrast' => (bool) $request->get_param('feature_contrast'),
                'highlight_links' => (bool) $request->get_param('feature_highlight_links'),
                'readable_font' => (bool) $request->get_param('feature_readable_font'),
                'focus_mode' => (bool) $request->get_param('feature_focus_mode'),
                'animations' => (bool) $request->get_param('feature_animations'),
                'cursor_size' => (bool) $request->get_param('feature_cursor_size'),
                'text_spacing' => (bool) $request->get_param('feature_text_spacing'),
            ],
            'skip_link' => (bool) $request->get_param('skip_link'),
            'auto_alt_detection' => (bool) $request->get_param('auto_alt_detection'),
        ];

        update_option('peanut_accessibility_settings', $settings);

        return new \WP_REST_Response(['message' => 'Settings saved'], 200);
    }

    /**
     * Enqueue widget assets
     */
    public function enqueue_widget_assets(): void {
        wp_enqueue_style(
            'peanut-accessibility-widget',
            PEANUT_PLUGIN_URL . 'modules/accessibility/assets/accessibility-widget.css',
            [],
            PEANUT_VERSION
        );

        wp_enqueue_script(
            'peanut-accessibility-widget',
            PEANUT_PLUGIN_URL . 'modules/accessibility/assets/accessibility-widget.js',
            [],
            PEANUT_VERSION,
            true
        );

        wp_localize_script('peanut-accessibility-widget', 'peanutA11y', [
            'settings' => $this->get_accessibility_settings(),
        ]);
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets(string $hook): void {
        if (strpos($hook, 'peanut-accessibility') === false) {
            return;
        }

        wp_enqueue_style(
            'peanut-accessibility-admin',
            PEANUT_PLUGIN_URL . 'modules/accessibility/assets/accessibility-admin.css',
            [],
            PEANUT_VERSION
        );
    }

    /**
     * Render accessibility widget
     */
    public function render_widget(): void {
        $settings = $this->get_accessibility_settings();
        $position = $settings['widget_position'];
        $color = $settings['widget_color'];
        $features = $settings['features'];

        // Add skip to content link if enabled
        if ($settings['skip_link']) {
            echo '<a href="#main-content" class="peanut-skip-link">Skip to main content</a>';
        }
        ?>
        <div id="peanut-a11y-widget" class="peanut-a11y-widget <?php echo esc_attr($position); ?>" style="--widget-color: <?php echo esc_attr($color); ?>">
            <button class="peanut-a11y-toggle" aria-label="Accessibility Options" aria-expanded="false">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="4.5" r="2.5"/>
                    <path d="m12 7-5 5.5 1.5 1.5L12 11l3.5 3L17 12.5z"/>
                    <path d="M8.5 14.5 7 21l2-1 3-1 3 1 2 1-1.5-6.5"/>
                </svg>
            </button>

            <div class="peanut-a11y-panel" role="dialog" aria-label="Accessibility Settings">
                <div class="peanut-a11y-header">
                    <h2>Accessibility</h2>
                    <button class="peanut-a11y-close" aria-label="Close">&times;</button>
                </div>

                <div class="peanut-a11y-options">
                    <?php if ($features['font_size']): ?>
                    <div class="peanut-a11y-option">
                        <span class="option-label">Text Size</span>
                        <div class="option-controls">
                            <button data-action="font-decrease" aria-label="Decrease text size">A-</button>
                            <button data-action="font-reset" aria-label="Reset text size">A</button>
                            <button data-action="font-increase" aria-label="Increase text size">A+</button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($features['contrast']): ?>
                    <div class="peanut-a11y-option">
                        <span class="option-label">Contrast</span>
                        <div class="option-controls">
                            <button data-action="contrast-normal" aria-label="Normal contrast">Normal</button>
                            <button data-action="contrast-high" aria-label="High contrast">High</button>
                            <button data-action="contrast-invert" aria-label="Inverted colors">Invert</button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($features['highlight_links']): ?>
                    <div class="peanut-a11y-option">
                        <label class="option-toggle">
                            <input type="checkbox" data-action="highlight-links">
                            <span class="option-label">Highlight Links</span>
                        </label>
                    </div>
                    <?php endif; ?>

                    <?php if ($features['readable_font']): ?>
                    <div class="peanut-a11y-option">
                        <label class="option-toggle">
                            <input type="checkbox" data-action="readable-font">
                            <span class="option-label">Readable Font</span>
                        </label>
                    </div>
                    <?php endif; ?>

                    <?php if ($features['focus_mode']): ?>
                    <div class="peanut-a11y-option">
                        <label class="option-toggle">
                            <input type="checkbox" data-action="focus-mode">
                            <span class="option-label">Focus Mode</span>
                        </label>
                    </div>
                    <?php endif; ?>

                    <?php if ($features['animations']): ?>
                    <div class="peanut-a11y-option">
                        <label class="option-toggle">
                            <input type="checkbox" data-action="pause-animations">
                            <span class="option-label">Pause Animations</span>
                        </label>
                    </div>
                    <?php endif; ?>

                    <?php if ($features['cursor_size']): ?>
                    <div class="peanut-a11y-option">
                        <label class="option-toggle">
                            <input type="checkbox" data-action="big-cursor">
                            <span class="option-label">Large Cursor</span>
                        </label>
                    </div>
                    <?php endif; ?>

                    <?php if ($features['text_spacing']): ?>
                    <div class="peanut-a11y-option">
                        <label class="option-toggle">
                            <input type="checkbox" data-action="text-spacing">
                            <span class="option-label">Text Spacing</span>
                        </label>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="peanut-a11y-footer">
                    <button data-action="reset-all" class="reset-button">Reset All</button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Run accessibility scan on URL
     */
    public function run_scan(\WP_REST_Request $request): \WP_REST_Response {
        $url = esc_url_raw($request->get_param('url') ?: home_url());

        $scanner = new Accessibility_Scanner();
        $results = $scanner->scan($url);

        // Store results
        update_option('peanut_accessibility_last_scan', [
            'url' => $url,
            'results' => $results,
            'timestamp' => time(),
        ]);

        return new \WP_REST_Response($results, 200);
    }

    /**
     * Get scan results
     */
    public function get_scan_results(\WP_REST_Request $request): \WP_REST_Response {
        $results = get_option('peanut_accessibility_last_scan', null);
        return new \WP_REST_Response($results ?: ['error' => 'No scan data'], 200);
    }

    /**
     * Get alt text report for all images
     */
    public function get_alt_text_report(\WP_REST_Request $request): \WP_REST_Response {
        global $wpdb;

        $page = (int) ($request->get_param('page') ?: 1);
        $per_page = (int) ($request->get_param('per_page') ?: 50);
        $offset = ($page - 1) * $per_page;

        // Get all image attachments
        $total = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image/%'
        ");

        $images = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title, p.guid,
                   pm.meta_value as alt_text
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
            WHERE p.post_type = 'attachment'
            AND p.post_mime_type LIKE 'image/%%'
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d
        ", $per_page, $offset), ARRAY_A);

        $missing_alt = 0;
        $empty_alt = 0;
        $has_alt = 0;

        foreach ($images as &$image) {
            $image['thumbnail'] = wp_get_attachment_image_url($image['ID'], 'thumbnail');
            $image['edit_url'] = admin_url('post.php?post=' . $image['ID'] . '&action=edit');

            if (!isset($image['alt_text']) || $image['alt_text'] === null) {
                $image['status'] = 'missing';
                $missing_alt++;
            } elseif (trim($image['alt_text']) === '') {
                $image['status'] = 'empty';
                $empty_alt++;
            } else {
                $image['status'] = 'ok';
                $has_alt++;
            }
        }

        return new \WP_REST_Response([
            'images' => $images,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'summary' => [
                'total' => $total,
                'has_alt' => $has_alt,
                'missing' => $missing_alt,
                'empty' => $empty_alt,
                'compliance_rate' => $total > 0 ? round(($has_alt / $total) * 100, 1) : 100,
            ],
        ], 200);
    }

    /**
     * Check color contrast
     */
    public function check_contrast(\WP_REST_Request $request): \WP_REST_Response {
        $foreground = $request->get_param('foreground');
        $background = $request->get_param('background');

        if (!$foreground || !$background) {
            return new \WP_REST_Response(['error' => 'Both colors required'], 400);
        }

        $ratio = $this->calculate_contrast_ratio($foreground, $background);

        $results = [
            'foreground' => $foreground,
            'background' => $background,
            'ratio' => round($ratio, 2),
            'wcag_aa_normal' => $ratio >= 4.5,
            'wcag_aa_large' => $ratio >= 3.0,
            'wcag_aaa_normal' => $ratio >= 7.0,
            'wcag_aaa_large' => $ratio >= 4.5,
        ];

        $results['recommendation'] = $this->get_contrast_recommendation($ratio);

        return new \WP_REST_Response($results, 200);
    }

    /**
     * Calculate contrast ratio between two colors
     */
    private function calculate_contrast_ratio(string $color1, string $color2): float {
        $l1 = $this->get_relative_luminance($color1);
        $l2 = $this->get_relative_luminance($color2);

        $lighter = max($l1, $l2);
        $darker = min($l1, $l2);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Get relative luminance of a color
     */
    private function get_relative_luminance(string $hex): float {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $r = $r <= 0.03928 ? $r / 12.92 : pow(($r + 0.055) / 1.055, 2.4);
        $g = $g <= 0.03928 ? $g / 12.92 : pow(($g + 0.055) / 1.055, 2.4);
        $b = $b <= 0.03928 ? $b / 12.92 : pow(($b + 0.055) / 1.055, 2.4);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /**
     * Get contrast recommendation
     */
    private function get_contrast_recommendation(float $ratio): string {
        if ($ratio >= 7) {
            return 'Excellent! Passes WCAG AAA for all text sizes.';
        } elseif ($ratio >= 4.5) {
            return 'Good. Passes WCAG AA for normal text and AAA for large text.';
        } elseif ($ratio >= 3) {
            return 'Acceptable for large text only. Fails for normal text.';
        } else {
            return 'Poor contrast. Does not meet WCAG requirements. Consider using different colors.';
        }
    }

    /**
     * Get accessibility statement
     */
    public function get_statement(\WP_REST_Request $request): \WP_REST_Response {
        $statement = get_option('peanut_accessibility_statement', '');
        return new \WP_REST_Response(['statement' => $statement], 200);
    }

    /**
     * Generate accessibility statement
     */
    public function generate_statement(\WP_REST_Request $request): \WP_REST_Response {
        $data = [
            'company_name' => sanitize_text_field($request->get_param('company_name') ?: get_bloginfo('name')),
            'website_url' => esc_url_raw($request->get_param('website_url') ?: home_url()),
            'contact_email' => sanitize_email($request->get_param('contact_email') ?: get_option('admin_email')),
            'contact_phone' => sanitize_text_field($request->get_param('contact_phone') ?: ''),
            'conformance_level' => sanitize_text_field($request->get_param('conformance_level') ?: 'AA'),
            'last_updated' => date('F j, Y'),
        ];

        $statement = $this->generate_statement_html($data);

        // Optionally create a page
        if ($request->get_param('create_page')) {
            $page_id = wp_insert_post([
                'post_title' => 'Accessibility Statement',
                'post_content' => $statement,
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);

            if (!is_wp_error($page_id)) {
                $data['page_url'] = get_permalink($page_id);
            }
        }

        update_option('peanut_accessibility_statement', $statement);
        update_option('peanut_accessibility_statement_data', $data);

        return new \WP_REST_Response([
            'statement' => $statement,
            'data' => $data,
        ], 200);
    }

    /**
     * Generate statement HTML
     */
    private function generate_statement_html(array $data): string {
        ob_start();
        ?>
<h2>Accessibility Statement for <?php echo esc_html($data['company_name']); ?></h2>

<p>This is an accessibility statement from <?php echo esc_html($data['company_name']); ?>.</p>

<h3>Conformance Status</h3>
<p>The Web Content Accessibility Guidelines (WCAG) defines requirements for designers and developers to improve accessibility for people with disabilities. It defines three levels of conformance: Level A, Level AA, and Level AAA. <?php echo esc_html($data['website_url']); ?> is partially conformant with WCAG 2.1 level <?php echo esc_html($data['conformance_level']); ?>. Partially conformant means that some parts of the content do not fully conform to the accessibility standard.</p>

<h3>Feedback</h3>
<p>We welcome your feedback on the accessibility of <?php echo esc_html($data['company_name']); ?>. Please let us know if you encounter accessibility barriers:</p>
<ul>
    <?php if ($data['contact_email']): ?>
    <li>E-mail: <a href="mailto:<?php echo esc_attr($data['contact_email']); ?>"><?php echo esc_html($data['contact_email']); ?></a></li>
    <?php endif; ?>
    <?php if ($data['contact_phone']): ?>
    <li>Phone: <?php echo esc_html($data['contact_phone']); ?></li>
    <?php endif; ?>
</ul>
<p>We try to respond to feedback within 2 business days.</p>

<h3>Technical Specifications</h3>
<p>Accessibility of this website relies on the following technologies to work with the particular combination of web browser and any assistive technologies or plugins installed on your computer:</p>
<ul>
    <li>HTML</li>
    <li>WAI-ARIA</li>
    <li>CSS</li>
    <li>JavaScript</li>
</ul>
<p>These technologies are relied upon for conformance with the accessibility standards used.</p>

<h3>Assessment Approach</h3>
<p><?php echo esc_html($data['company_name']); ?> assessed the accessibility of this website by the following approaches:</p>
<ul>
    <li>Self-evaluation</li>
    <li>Automated testing tools</li>
</ul>

<h3>Date</h3>
<p>This statement was created on <?php echo esc_html($data['last_updated']); ?>.</p>
        <?php
        return ob_get_clean();
    }
}

/**
 * Accessibility Scanner class
 */
class Accessibility_Scanner {

    private array $issues = [];

    /**
     * Scan URL for accessibility issues
     */
    public function scan(string $url): array {
        $this->issues = [];

        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $html = wp_remote_retrieve_body($response);

        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR);
        $xpath = new \DOMXPath($dom);

        // Run all checks
        $this->check_images($xpath);
        $this->check_headings($xpath);
        $this->check_links($xpath);
        $this->check_forms($xpath);
        $this->check_tables($xpath);
        $this->check_language($xpath);
        $this->check_landmarks($xpath);
        $this->check_skip_link($xpath);
        $this->check_focus_indicators($html);
        $this->check_color_references($html);

        $summary = [
            'critical' => count(array_filter($this->issues, fn($i) => $i['severity'] === 'critical')),
            'warning' => count(array_filter($this->issues, fn($i) => $i['severity'] === 'warning')),
            'info' => count(array_filter($this->issues, fn($i) => $i['severity'] === 'info')),
        ];

        return [
            'success' => true,
            'url' => $url,
            'issues' => $this->issues,
            'summary' => $summary,
            'score' => $this->calculate_score($summary),
        ];
    }

    private function add_issue(string $rule, string $severity, string $description, ?string $element = null): void {
        $this->issues[] = [
            'rule' => $rule,
            'severity' => $severity,
            'description' => $description,
            'element' => $element,
            'wcag' => $this->get_wcag_reference($rule),
        ];
    }

    private function check_images(\DOMXPath $xpath): void {
        $images = $xpath->query('//img');

        foreach ($images as $img) {
            $alt = $img->getAttribute('alt');
            $src = $img->getAttribute('src');

            if (!$img->hasAttribute('alt')) {
                $this->add_issue('img-alt', 'critical',
                    'Image missing alt attribute',
                    "<img src=\"" . substr($src, 0, 50) . "...\">"
                );
            } elseif (trim($alt) === '' && !$img->hasAttribute('role')) {
                // Empty alt without role="presentation" might be intentional for decorative images
                // but should be flagged for review
                $this->add_issue('img-alt-empty', 'info',
                    'Image has empty alt. Verify it is decorative.',
                    "<img src=\"" . substr($src, 0, 50) . "...\" alt=\"\">"
                );
            }
        }

        // Check for images used as links
        $linked_images = $xpath->query('//a/img');
        foreach ($linked_images as $img) {
            $alt = trim($img->getAttribute('alt'));
            if (empty($alt)) {
                $parent = $img->parentNode;
                $link_text = trim($parent->textContent);
                if (empty($link_text)) {
                    $this->add_issue('img-link-alt', 'critical',
                        'Linked image has no alt text and link has no text content'
                    );
                }
            }
        }
    }

    private function check_headings(\DOMXPath $xpath): void {
        $h1s = $xpath->query('//h1');

        if ($h1s->length === 0) {
            $this->add_issue('heading-h1', 'warning', 'Page missing H1 heading');
        } elseif ($h1s->length > 1) {
            $this->add_issue('heading-h1-multiple', 'warning',
                "Multiple H1 headings found ({$h1s->length})"
            );
        }

        // Check heading hierarchy
        $headings = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
        $prev_level = 0;

        foreach ($headings as $heading) {
            $level = (int) substr($heading->nodeName, 1);

            if ($prev_level > 0 && $level > $prev_level + 1) {
                $this->add_issue('heading-order', 'warning',
                    "Heading level skipped from H{$prev_level} to H{$level}",
                    "<{$heading->nodeName}>" . substr($heading->textContent, 0, 50)
                );
            }

            if (trim($heading->textContent) === '') {
                $this->add_issue('heading-empty', 'warning', "Empty heading found", "<{$heading->nodeName}>");
            }

            $prev_level = $level;
        }
    }

    private function check_links(\DOMXPath $xpath): void {
        $links = $xpath->query('//a[@href]');

        foreach ($links as $link) {
            $text = trim($link->textContent);
            $aria_label = $link->getAttribute('aria-label');
            $title = $link->getAttribute('title');
            $href = $link->getAttribute('href');

            // Check for accessible name
            if (empty($text) && empty($aria_label)) {
                $imgs = $xpath->query('.//img[@alt]', $link);
                if ($imgs->length === 0) {
                    $this->add_issue('link-name', 'critical',
                        'Link has no accessible name',
                        "<a href=\"" . substr($href, 0, 50) . "\">"
                    );
                }
            }

            // Check for generic link text
            $generic_texts = ['click here', 'read more', 'learn more', 'more', 'here', 'link'];
            if (in_array(strtolower($text), $generic_texts)) {
                $this->add_issue('link-text-generic', 'warning',
                    "Generic link text: \"$text\"",
                    "<a>$text</a>"
                );
            }

            // Check for new window without warning
            if ($link->getAttribute('target') === '_blank') {
                if (strpos($aria_label . $title . $text, 'new window') === false &&
                    strpos($aria_label . $title . $text, 'new tab') === false) {
                    $this->add_issue('link-new-window', 'info',
                        'Link opens in new window without warning',
                        "<a>$text</a>"
                    );
                }
            }
        }
    }

    private function check_forms(\DOMXPath $xpath): void {
        // Check inputs have labels
        $inputs = $xpath->query('//input[@type!="hidden" and @type!="submit" and @type!="button" and @type!="image"]|//textarea|//select');

        foreach ($inputs as $input) {
            $id = $input->getAttribute('id');
            $aria_label = $input->getAttribute('aria-label');
            $aria_labelledby = $input->getAttribute('aria-labelledby');

            if (empty($aria_label) && empty($aria_labelledby)) {
                if (empty($id)) {
                    $this->add_issue('form-label', 'critical',
                        'Form input has no label',
                        "<{$input->nodeName}>"
                    );
                } else {
                    $label = $xpath->query("//label[@for='$id']");
                    if ($label->length === 0) {
                        $this->add_issue('form-label', 'critical',
                            "Form input has no associated label",
                            "<{$input->nodeName} id=\"$id\">"
                        );
                    }
                }
            }
        }

        // Check buttons have accessible names
        $buttons = $xpath->query('//button|//input[@type="submit"]|//input[@type="button"]');

        foreach ($buttons as $button) {
            $text = trim($button->textContent);
            $value = $button->getAttribute('value');
            $aria_label = $button->getAttribute('aria-label');

            if (empty($text) && empty($value) && empty($aria_label)) {
                $this->add_issue('button-name', 'critical', 'Button has no accessible name');
            }
        }
    }

    private function check_tables(\DOMXPath $xpath): void {
        $tables = $xpath->query('//table');

        foreach ($tables as $table) {
            $headers = $xpath->query('.//th', $table);
            $caption = $xpath->query('.//caption', $table);

            if ($headers->length === 0) {
                $this->add_issue('table-headers', 'warning', 'Table has no header cells');
            }

            if ($caption->length === 0 && !$table->getAttribute('aria-label')) {
                $this->add_issue('table-caption', 'info', 'Table has no caption or aria-label');
            }
        }
    }

    private function check_language(\DOMXPath $xpath): void {
        $html = $xpath->query('//html[@lang]');

        if ($html->length === 0) {
            $this->add_issue('html-lang', 'critical', 'Page missing lang attribute on html element');
        } else {
            $lang = $html->item(0)->getAttribute('lang');
            if (strlen($lang) < 2) {
                $this->add_issue('html-lang-valid', 'warning', 'Invalid lang attribute value');
            }
        }
    }

    private function check_landmarks(\DOMXPath $xpath): void {
        $main = $xpath->query('//main|//*[@role="main"]');
        $nav = $xpath->query('//nav|//*[@role="navigation"]');

        if ($main->length === 0) {
            $this->add_issue('landmark-main', 'warning', 'Page missing main landmark');
        }

        if ($nav->length === 0) {
            $this->add_issue('landmark-nav', 'info', 'Page has no navigation landmark');
        }

        // Check for multiple mains
        if ($main->length > 1) {
            $this->add_issue('landmark-main-multiple', 'warning', 'Page has multiple main landmarks');
        }
    }

    private function check_skip_link(\DOMXPath $xpath): void {
        $skip = $xpath->query('//a[contains(@href, "#main") or contains(@href, "#content")]');

        if ($skip->length === 0) {
            $this->add_issue('skip-link', 'warning', 'Page missing skip to content link');
        }
    }

    private function check_focus_indicators(string $html): void {
        if (preg_match('/outline\s*:\s*(?:none|0)/i', $html)) {
            $this->add_issue('focus-visible', 'warning',
                'CSS may be removing focus indicators (outline: none found)'
            );
        }
    }

    private function check_color_references(string $html): void {
        $color_words = ['red', 'green', 'blue', 'yellow', 'orange', 'purple', 'pink'];
        $pattern = '/\b(?:click|select|choose)\s+(?:the\s+)?(' . implode('|', $color_words) . ')\b/i';

        if (preg_match($pattern, strip_tags($html))) {
            $this->add_issue('color-alone', 'warning',
                'Content may rely on color alone to convey information'
            );
        }
    }

    private function get_wcag_reference(string $rule): string {
        $references = [
            'img-alt' => '1.1.1 Non-text Content (A)',
            'img-alt-empty' => '1.1.1 Non-text Content (A)',
            'img-link-alt' => '2.4.4 Link Purpose (A)',
            'heading-h1' => '1.3.1 Info and Relationships (A)',
            'heading-h1-multiple' => '1.3.1 Info and Relationships (A)',
            'heading-order' => '1.3.1 Info and Relationships (A)',
            'heading-empty' => '1.3.1 Info and Relationships (A)',
            'link-name' => '2.4.4 Link Purpose (A)',
            'link-text-generic' => '2.4.4 Link Purpose (A)',
            'link-new-window' => '3.2.5 Change on Request (AAA)',
            'form-label' => '1.3.1 Info and Relationships (A)',
            'button-name' => '4.1.2 Name, Role, Value (A)',
            'table-headers' => '1.3.1 Info and Relationships (A)',
            'table-caption' => '1.3.1 Info and Relationships (A)',
            'html-lang' => '3.1.1 Language of Page (A)',
            'html-lang-valid' => '3.1.1 Language of Page (A)',
            'landmark-main' => '1.3.1 Info and Relationships (A)',
            'landmark-nav' => '1.3.1 Info and Relationships (A)',
            'skip-link' => '2.4.1 Bypass Blocks (A)',
            'focus-visible' => '2.4.7 Focus Visible (AA)',
            'color-alone' => '1.4.1 Use of Color (A)',
        ];

        return $references[$rule] ?? '';
    }

    private function calculate_score(array $summary): int {
        $score = 100;
        $score -= $summary['critical'] * 15;
        $score -= $summary['warning'] * 5;
        $score -= $summary['info'] * 1;
        return max(0, $score);
    }
}
