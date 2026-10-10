<?php
/**
 * Help System
 *
 * Provides contextual help, tooltips, and documentation for admin pages.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Help {

    /**
     * Help content for each page
     */
    private array $help_content = [];

    /**
     * Initialize help system
     */
    public function __construct() {
        $this->register_help_content();
        add_action('current_screen', [$this, 'add_help_tabs']);
    }

    /**
     * Register help content for all pages
     */
    private function register_help_content(): void {
        $this->help_content = [
            'dashboard' => [
                'overview' => [
                    'title' => __('Dashboard Overview', 'peanut-suite'),
                    'content' => $this->get_dashboard_help(),
                ],
                'metrics' => [
                    'title' => __('Understanding Metrics', 'peanut-suite'),
                    'content' => $this->get_metrics_help(),
                ],
            ],
            'utm-builder' => [
                'overview' => [
                    'title' => __('What are UTM Parameters?', 'peanut-suite'),
                    'content' => $this->get_utm_overview_help(),
                ],
                'best-practices' => [
                    'title' => __('Best Practices', 'peanut-suite'),
                    'content' => $this->get_utm_best_practices_help(),
                ],
                'examples' => [
                    'title' => __('Examples', 'peanut-suite'),
                    'content' => $this->get_utm_examples_help(),
                ],
            ],
            'links' => [
                'overview' => [
                    'title' => __('Short Links Overview', 'peanut-suite'),
                    'content' => $this->get_links_help(),
                ],
                'qr-codes' => [
                    'title' => __('QR Codes', 'peanut-suite'),
                    'content' => $this->get_qr_help(),
                ],
            ],
            'contacts' => [
                'overview' => [
                    'title' => __('Contact Management', 'peanut-suite'),
                    'content' => $this->get_contacts_help(),
                ],
                'lifecycle' => [
                    'title' => __('Contact Lifecycle', 'peanut-suite'),
                    'content' => $this->get_lifecycle_help(),
                ],
            ],
            'webhooks' => [
                'overview' => [
                    'title' => __('What are Webhooks?', 'peanut-suite'),
                    'content' => $this->get_webhooks_help(),
                ],
                'troubleshooting' => [
                    'title' => __('Troubleshooting', 'peanut-suite'),
                    'content' => $this->get_webhooks_troubleshooting_help(),
                ],
            ],
            'visitors' => [
                'overview' => [
                    'title' => __('Visitor Tracking', 'peanut-suite'),
                    'content' => $this->get_visitors_help(),
                ],
                'setup' => [
                    'title' => __('Setup Guide', 'peanut-suite'),
                    'content' => $this->get_visitors_setup_help(),
                ],
                'privacy' => [
                    'title' => __('Privacy & Compliance', 'peanut-suite'),
                    'content' => $this->get_visitors_privacy_help(),
                ],
            ],
            'attribution' => [
                'overview' => [
                    'title' => __('Attribution Overview', 'peanut-suite'),
                    'content' => $this->get_attribution_help(),
                ],
                'models' => [
                    'title' => __('Attribution Models', 'peanut-suite'),
                    'content' => $this->get_attribution_models_help(),
                ],
            ],
            'analytics' => [
                'overview' => [
                    'title' => __('Analytics Overview', 'peanut-suite'),
                    'content' => $this->get_analytics_help(),
                ],
            ],
            'popups' => [
                'overview' => [
                    'title' => __('Popup Overview', 'peanut-suite'),
                    'content' => $this->get_popups_help(),
                ],
                'triggers' => [
                    'title' => __('Trigger Types', 'peanut-suite'),
                    'content' => $this->get_popup_triggers_help(),
                ],
            ],
        ];
    }

    /**
     * Add help tabs to current screen
     */
    public function add_help_tabs(): void {
        $screen = get_current_screen();

        if (!$screen || strpos($screen->id, 'peanut') === false) {
            return;
        }

        // Determine the page slug from screen ID
        $page_slug = $this->get_page_slug_from_screen($screen->id);

        if (!isset($this->help_content[$page_slug])) {
            return;
        }

        foreach ($this->help_content[$page_slug] as $tab_id => $tab) {
            $screen->add_help_tab([
                'id' => 'peanut-' . $page_slug . '-' . $tab_id,
                'title' => $tab['title'],
                'content' => $tab['content'],
            ]);
        }

        // Add sidebar with links
        $screen->set_help_sidebar($this->get_help_sidebar());
    }

    /**
     * Get page slug from screen ID
     */
    private function get_page_slug_from_screen(string $screen_id): string {
        // Screen IDs look like: toplevel_page_peanut-suite or peanut-suite_page_peanut-utm-builder
        if (preg_match('/peanut-([a-z-]+)$/', $screen_id, $matches)) {
            return $matches[1] === 'suite' ? 'dashboard' : $matches[1];
        }
        return 'dashboard';
    }

    /**
     * Get help sidebar content
     */
    private function get_help_sidebar(): string {
        return sprintf(
            '<p><strong>%s</strong></p>
            <p><a href="%s" target="_blank">%s</a></p>
            <p><a href="%s" target="_blank">%s</a></p>',
            __('For more information:', 'peanut-suite'),
            'https://peanutgraphic.com/docs/peanut-suite/',
            __('Documentation', 'peanut-suite'),
            'https://peanutgraphic.com/support/',
            __('Support', 'peanut-suite')
        );
    }

    // ========================================
    // Help Content Methods
    // ========================================

    private function get_dashboard_help(): string {
        return '
            <h3>' . __('Welcome to Peanut Suite', 'peanut-suite') . '</h3>
            <p>' . __('The dashboard gives you a quick overview of your marketing performance. Here you can see:', 'peanut-suite') . '</p>
            <ul>
                <li><strong>' . __('Stats Cards', 'peanut-suite') . '</strong> - ' . __('Key metrics at a glance including total UTM campaigns, links, contacts, and popups.', 'peanut-suite') . '</li>
                <li><strong>' . __('Quick Actions', 'peanut-suite') . '</strong> - ' . __('Shortcuts to create new UTM codes, links, or view contacts.', 'peanut-suite') . '</li>
                <li><strong>' . __('Recent Activity', 'peanut-suite') . '</strong> - ' . __('Your most recent UTM campaigns and their performance.', 'peanut-suite') . '</li>
                <li><strong>' . __('Performance Charts', 'peanut-suite') . '</strong> - ' . __('Visual representation of clicks and traffic over time.', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_metrics_help(): string {
        return '
            <h3>' . __('Understanding Your Metrics', 'peanut-suite') . '</h3>
            <p><strong>' . __('UTM Campaigns', 'peanut-suite') . '</strong></p>
            <p>' . __('The total number of UTM tracking URLs you have created. Each campaign helps you track where your traffic comes from.', 'peanut-suite') . '</p>

            <p><strong>' . __('Short Links', 'peanut-suite') . '</strong></p>
            <p>' . __('Branded short URLs that redirect to your destination pages while tracking clicks.', 'peanut-suite') . '</p>

            <p><strong>' . __('Contacts', 'peanut-suite') . '</strong></p>
            <p>' . __('Leads and customers captured through forms, webhooks, or manual entry.', 'peanut-suite') . '</p>

            <p><strong>' . __('Click Count', 'peanut-suite') . '</strong></p>
            <p>' . __('Total number of times your tracked links have been clicked.', 'peanut-suite') . '</p>
        ';
    }

    private function get_utm_overview_help(): string {
        return '
            <h3>' . __('What are UTM Parameters?', 'peanut-suite') . '</h3>
            <p>' . __('UTM (Urchin Tracking Module) parameters are tags added to URLs to track the effectiveness of marketing campaigns.', 'peanut-suite') . '</p>

            <p>' . __('When someone clicks a link with UTM parameters, the data is captured by analytics tools like Google Analytics, allowing you to see:', 'peanut-suite') . '</p>
            <ul>
                <li>' . __('Which campaigns drive the most traffic', 'peanut-suite') . '</li>
                <li>' . __('Which sources send the best quality visitors', 'peanut-suite') . '</li>
                <li>' . __('Which content resonates with your audience', 'peanut-suite') . '</li>
            </ul>

            <h4>' . __('The 5 UTM Parameters', 'peanut-suite') . '</h4>
            <ul>
                <li><strong>utm_source</strong> - ' . __('Where the traffic comes from (e.g., google, facebook, newsletter)', 'peanut-suite') . '</li>
                <li><strong>utm_medium</strong> - ' . __('The marketing medium (e.g., cpc, email, social)', 'peanut-suite') . '</li>
                <li><strong>utm_campaign</strong> - ' . __('The specific campaign name (e.g., spring_sale, product_launch)', 'peanut-suite') . '</li>
                <li><strong>utm_term</strong> - ' . __('(Optional) Paid search keywords', 'peanut-suite') . '</li>
                <li><strong>utm_content</strong> - ' . __('(Optional) Differentiate similar content or links', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_utm_best_practices_help(): string {
        return '
            <h3>' . __('UTM Best Practices', 'peanut-suite') . '</h3>

            <p><strong>' . __('Use Consistent Naming', 'peanut-suite') . '</strong></p>
            <p>' . __('Always use lowercase letters and underscores instead of spaces. This ensures your data is grouped correctly in analytics.', 'peanut-suite') . '</p>

            <p><strong>' . __('Be Descriptive', 'peanut-suite') . '</strong></p>
            <p>' . __('Use clear, meaningful names that your team will understand months later.', 'peanut-suite') . '</p>

            <p><strong>' . __('Document Your Conventions', 'peanut-suite') . '</strong></p>
            <p>' . __('Create a naming guide so everyone on your team uses the same format.', 'peanut-suite') . '</p>

            <h4>' . __('Common Mistakes to Avoid', 'peanut-suite') . '</h4>
            <ul>
                <li>' . __('Using different cases (Google vs google vs GOOGLE)', 'peanut-suite') . '</li>
                <li>' . __('Spaces in parameter values', 'peanut-suite') . '</li>
                <li>' . __('Vague campaign names like "test" or "new"', 'peanut-suite') . '</li>
                <li>' . __('Adding UTM parameters to internal links', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_utm_examples_help(): string {
        return '
            <h3>' . __('UTM Examples by Channel', 'peanut-suite') . '</h3>

            <p><strong>' . __('Google Ads Campaign', 'peanut-suite') . '</strong></p>
            <code>?utm_source=google&utm_medium=cpc&utm_campaign=spring_sale&utm_term=running+shoes</code>

            <p><strong>' . __('Facebook Organic Post', 'peanut-suite') . '</strong></p>
            <code>?utm_source=facebook&utm_medium=social&utm_campaign=product_launch&utm_content=carousel_post</code>

            <p><strong>' . __('Email Newsletter', 'peanut-suite') . '</strong></p>
            <code>?utm_source=newsletter&utm_medium=email&utm_campaign=weekly_digest&utm_content=header_link</code>

            <p><strong>' . __('Instagram Bio Link', 'peanut-suite') . '</strong></p>
            <code>?utm_source=instagram&utm_medium=social&utm_campaign=bio_link</code>

            <p><strong>' . __('Affiliate Partner', 'peanut-suite') . '</strong></p>
            <code>?utm_source=partner_name&utm_medium=affiliate&utm_campaign=q4_promo</code>
        ';
    }

    private function get_links_help(): string {
        return '
            <h3>' . __('Short Links', 'peanut-suite') . '</h3>
            <p>' . __('Short links are condensed URLs that redirect to your destination pages. They are useful for:', 'peanut-suite') . '</p>
            <ul>
                <li>' . __('Social media posts with character limits', 'peanut-suite') . '</li>
                <li>' . __('Print materials and business cards', 'peanut-suite') . '</li>
                <li>' . __('Tracking clicks across channels', 'peanut-suite') . '</li>
                <li>' . __('Creating memorable, branded URLs', 'peanut-suite') . '</li>
            </ul>

            <h4>' . __('Features', 'peanut-suite') . '</h4>
            <ul>
                <li><strong>' . __('Custom Slugs', 'peanut-suite') . '</strong> - ' . __('Create meaningful short URLs like /spring-sale instead of random characters', 'peanut-suite') . '</li>
                <li><strong>' . __('Click Tracking', 'peanut-suite') . '</strong> - ' . __('See how many times each link was clicked', 'peanut-suite') . '</li>
                <li><strong>' . __('QR Codes', 'peanut-suite') . '</strong> - ' . __('Generate QR codes for print materials', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_qr_help(): string {
        return '
            <h3>' . __('QR Codes', 'peanut-suite') . '</h3>
            <p>' . __('QR codes are scannable barcodes that open URLs when scanned with a smartphone camera.', 'peanut-suite') . '</p>

            <h4>' . __('Best Uses for QR Codes', 'peanut-suite') . '</h4>
            <ul>
                <li>' . __('Business cards and brochures', 'peanut-suite') . '</li>
                <li>' . __('Product packaging', 'peanut-suite') . '</li>
                <li>' . __('Event signage and posters', 'peanut-suite') . '</li>
                <li>' . __('Restaurant menus', 'peanut-suite') . '</li>
            </ul>

            <h4>' . __('Tips', 'peanut-suite') . '</h4>
            <ul>
                <li>' . __('Ensure adequate size - at least 1 inch (2.5cm) for reliable scanning', 'peanut-suite') . '</li>
                <li>' . __('Maintain contrast - dark code on light background works best', 'peanut-suite') . '</li>
                <li>' . __('Test before printing', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_contacts_help(): string {
        return '
            <h3>' . __('Contact Management', 'peanut-suite') . '</h3>
            <p>' . __('Contacts are leads and customers captured from your marketing efforts.', 'peanut-suite') . '</p>

            <h4>' . __('Contact Sources', 'peanut-suite') . '</h4>
            <ul>
                <li><strong>' . __('Popup', 'peanut-suite') . '</strong> - ' . __('Captured via popup forms', 'peanut-suite') . '</li>
                <li><strong>' . __('Form', 'peanut-suite') . '</strong> - ' . __('Submitted through FormFlow or other forms', 'peanut-suite') . '</li>
                <li><strong>' . __('Import', 'peanut-suite') . '</strong> - ' . __('Imported from CSV file', 'peanut-suite') . '</li>
                <li><strong>' . __('Manual', 'peanut-suite') . '</strong> - ' . __('Added manually', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_lifecycle_help(): string {
        return '
            <h3>' . __('Contact Lifecycle Stages', 'peanut-suite') . '</h3>

            <p><strong>' . __('Lead', 'peanut-suite') . '</strong></p>
            <p>' . __('A new contact who has shown interest but has not been qualified yet.', 'peanut-suite') . '</p>

            <p><strong>' . __('Contacted', 'peanut-suite') . '</strong></p>
            <p>' . __('You have reached out to this contact.', 'peanut-suite') . '</p>

            <p><strong>' . __('Qualified', 'peanut-suite') . '</strong></p>
            <p>' . __('This contact meets your criteria and is a good fit for your product/service.', 'peanut-suite') . '</p>

            <p><strong>' . __('Customer', 'peanut-suite') . '</strong></p>
            <p>' . __('This contact has made a purchase or signed up.', 'peanut-suite') . '</p>

            <p><strong>' . __('Churned', 'peanut-suite') . '</strong></p>
            <p>' . __('A former customer who is no longer active.', 'peanut-suite') . '</p>
        ';
    }

    private function get_webhooks_help(): string {
        return '
            <h3>' . __('Understanding Webhooks', 'peanut-suite') . '</h3>
            <p>' . __('Webhooks are automated messages sent from one application to another when an event occurs.', 'peanut-suite') . '</p>

            <p>' . __('Peanut Suite receives webhooks from FormFlow and other sources to:', 'peanut-suite') . '</p>
            <ul>
                <li>' . __('Create contacts from form submissions', 'peanut-suite') . '</li>
                <li>' . __('Track conversions for attribution', 'peanut-suite') . '</li>
                <li>' . __('Update visitor information', 'peanut-suite') . '</li>
            </ul>

            <h4>' . __('Webhook Statuses', 'peanut-suite') . '</h4>
            <ul>
                <li><strong style="color: #10b981;">' . __('Processed', 'peanut-suite') . '</strong> - ' . __('Successfully handled', 'peanut-suite') . '</li>
                <li><strong style="color: #f59e0b;">' . __('Pending', 'peanut-suite') . '</strong> - ' . __('Waiting to be processed', 'peanut-suite') . '</li>
                <li><strong style="color: #ef4444;">' . __('Failed', 'peanut-suite') . '</strong> - ' . __('Error occurred during processing', 'peanut-suite') . '</li>
            </ul>

            <h4>' . __('Signing Secrets', 'peanut-suite') . '</h4>
            <p>' . __('Give a source a signing secret (Webhooks page, Signing secrets) and every webhook from it must carry an HMAC-SHA256 signature of the raw request body, or it is rejected. A source with no secret is refused: every webhook must be signed, so set a secret for each sender before pointing it here. Secrets are stored encrypted; a generated secret is shown once.', 'peanut-suite') . '</p>
            <ul>
                <li><strong>FormFlow Lite</strong> - ' . __('source "formflow-lite"; signs with the X-FFFL-Signature header when its webhook has a Secret.', 'peanut-suite') . '</li>
                <li><strong>FormFlow Pro</strong> - ' . __('sends no source, so it arrives as "unknown"; signs with X-ISF-Signature. Set its secret on "unknown".', 'peanut-suite') . '</li>
                <li>' . __('Other senders: post to /wp-json/peanut/v1/webhooks/receive/{source} (the URL names the source), send the Unix time as X-Peanut-Timestamp, and send X-Webhook-Signature: sha256=&lt;hex HMAC-SHA256 of "timestamp.body"&gt;.', 'peanut-suite') . '</li>
                <li>' . __('Replay protection: a signed delivery more than 5 minutes old (or dated in the future) is rejected, and each signed delivery is accepted once. FormFlow\'s signed "timestamp" body field counts.', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_webhooks_troubleshooting_help(): string {
        return '
            <h3>' . __('Troubleshooting Webhooks', 'peanut-suite') . '</h3>

            <p><strong>' . __('Webhook Not Arriving', 'peanut-suite') . '</strong></p>
            <ul>
                <li>' . __('Verify the webhook URL is correct in your sending application', 'peanut-suite') . '</li>
                <li>' . __('Check that your site is publicly accessible', 'peanut-suite') . '</li>
                <li>' . __('Ensure REST API is not blocked by security plugins', 'peanut-suite') . '</li>
            </ul>

            <p><strong>' . __('Webhook Failed', 'peanut-suite') . '</strong></p>
            <ul>
                <li>' . __('Check the error message in the webhook detail view', 'peanut-suite') . '</li>
                <li>' . __('Verify the payload format matches expected structure', 'peanut-suite') . '</li>
                <li>' . __('Try reprocessing the webhook', 'peanut-suite') . '</li>
            </ul>

            <p><strong>' . __('Signature Verification Failed', 'peanut-suite') . '</strong></p>
            <ul>
                <li>' . __('Ensure the webhook secret matches in both applications', 'peanut-suite') . '</li>
                <li>' . __('If the Webhooks page shows "Secret unreadable", the stored secret can no longer be decrypted (for example after PEANUT_ENCRYPTION_KEY or the WordPress salts changed); every webhook from that source is rejected until you set a new secret', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_visitors_help(): string {
        return '
            <h3>' . __('Visitor Tracking', 'peanut-suite') . '</h3>
            <p>' . __('Track anonymous and identified visitors on your website to understand their journey.', 'peanut-suite') . '</p>

            <h4>' . __('What is Tracked', 'peanut-suite') . '</h4>
            <ul>
                <li>' . __('Page views and navigation paths', 'peanut-suite') . '</li>
                <li>' . __('Referral sources and UTM parameters', 'peanut-suite') . '</li>
                <li>' . __('Device, browser, and location data', 'peanut-suite') . '</li>
                <li>' . __('Form interactions and conversions', 'peanut-suite') . '</li>
            </ul>

            <h4>' . __('Anonymous vs Identified Visitors', 'peanut-suite') . '</h4>
            <p><strong>' . __('Anonymous', 'peanut-suite') . '</strong> - ' . __('Visitors tracked by a cookie ID. You can see their journey but not who they are.', 'peanut-suite') . '</p>
            <p><strong>' . __('Identified', 'peanut-suite') . '</strong> - ' . __('Visitors who have provided their email through a form or identification call.', 'peanut-suite') . '</p>
        ';
    }

    private function get_visitors_setup_help(): string {
        return '
            <h3>' . __('Setting Up Visitor Tracking', 'peanut-suite') . '</h3>

            <p><strong>' . __('Step 1: Get Your Tracking Code', 'peanut-suite') . '</strong></p>
            <p>' . __('Click the "Get Tracking Code" button to view your unique tracking snippet.', 'peanut-suite') . '</p>

            <p><strong>' . __('Step 2: Add to Your Website', 'peanut-suite') . '</strong></p>
            <p>' . __('Paste the tracking code in your website\'s &lt;head&gt; section, before the closing &lt;/head&gt; tag.', 'peanut-suite') . '</p>

            <p><strong>' . __('Step 3: Verify Installation', 'peanut-suite') . '</strong></p>
            <p>' . __('Visit your website and check the Visitors page. You should see yourself appear within a few minutes.', 'peanut-suite') . '</p>

            <p><strong>' . __('Step 4: Identify Visitors (Optional)', 'peanut-suite') . '</strong></p>
            <p>' . __('When a visitor submits a form with their email, call the identify function:', 'peanut-suite') . '</p>
            <code>peanut.identify("visitor@example.com");</code>
        ';
    }

    private function get_visitors_privacy_help(): string {
        return '
            <h3>' . __('Privacy & Compliance', 'peanut-suite') . '</h3>

            <p><strong>' . __('Cookie Notice', 'peanut-suite') . '</strong></p>
            <p>' . __('Visitor tracking uses cookies. Ensure your website has a cookie consent banner if required in your jurisdiction (GDPR, CCPA, etc.).', 'peanut-suite') . '</p>

            <p><strong>' . __('Data Collected', 'peanut-suite') . '</strong></p>
            <ul>
                <li>' . __('Browser and device information', 'peanut-suite') . '</li>
                <li>' . __('Approximate location (country/region)', 'peanut-suite') . '</li>
                <li>' . __('Pages visited and referral source', 'peanut-suite') . '</li>
                <li>' . __('Email (only when voluntarily provided)', 'peanut-suite') . '</li>
            </ul>

            <p><strong>' . __('Data Retention', 'peanut-suite') . '</strong></p>
            <p>' . __('Visitor data is retained for 90 days by default. You can adjust this in Settings.', 'peanut-suite') . '</p>
        ';
    }

    private function get_attribution_help(): string {
        return '
            <h3>' . __('Understanding Attribution', 'peanut-suite') . '</h3>
            <p>' . __('Attribution helps you understand which marketing channels and touchpoints contribute to conversions.', 'peanut-suite') . '</p>

            <p>' . __('When a visitor converts (submits a form, makes a purchase), attribution assigns credit to the channels they interacted with along their journey.', 'peanut-suite') . '</p>

            <h4>' . __('Key Concepts', 'peanut-suite') . '</h4>
            <ul>
                <li><strong>' . __('Touchpoint', 'peanut-suite') . '</strong> - ' . __('An interaction with your brand (ad click, email open, website visit)', 'peanut-suite') . '</li>
                <li><strong>' . __('Conversion', 'peanut-suite') . '</strong> - ' . __('A completed goal (form submission, purchase)', 'peanut-suite') . '</li>
                <li><strong>' . __('Attribution Model', 'peanut-suite') . '</strong> - ' . __('The rule for assigning credit to touchpoints', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_attribution_models_help(): string {
        return '
            <h3>' . __('Attribution Models Explained', 'peanut-suite') . '</h3>

            <p><strong>' . __('First Touch', 'peanut-suite') . '</strong></p>
            <p>' . __('100% credit to the first interaction. Best for understanding what channels introduce new visitors.', 'peanut-suite') . '</p>

            <p><strong>' . __('Last Touch', 'peanut-suite') . '</strong></p>
            <p>' . __('100% credit to the final interaction before conversion. Best for understanding what closes deals.', 'peanut-suite') . '</p>

            <p><strong>' . __('Linear', 'peanut-suite') . '</strong></p>
            <p>' . __('Equal credit to all touchpoints. Best when all interactions are equally important.', 'peanut-suite') . '</p>

            <p><strong>' . __('Time Decay', 'peanut-suite') . '</strong></p>
            <p>' . __('More credit to recent touchpoints, using a 7-day half-life. Best for short sales cycles.', 'peanut-suite') . '</p>

            <p><strong>' . __('Position Based (U-Shaped)', 'peanut-suite') . '</strong></p>
            <p>' . __('40% to first, 40% to last, 20% split among middle touchpoints. Best for valuing both discovery and conversion.', 'peanut-suite') . '</p>
        ';
    }

    private function get_analytics_help(): string {
        return '
            <h3>' . __('Analytics Overview', 'peanut-suite') . '</h3>
            <p>' . __('The Analytics dashboard provides a comprehensive view of your website traffic and conversions.', 'peanut-suite') . '</p>

            <h4>' . __('Key Metrics', 'peanut-suite') . '</h4>
            <ul>
                <li><strong>' . __('Visitors', 'peanut-suite') . '</strong> - ' . __('Unique visitors to your site', 'peanut-suite') . '</li>
                <li><strong>' . __('Pageviews', 'peanut-suite') . '</strong> - ' . __('Total pages viewed', 'peanut-suite') . '</li>
                <li><strong>' . __('Conversions', 'peanut-suite') . '</strong> - ' . __('Completed goals (form submissions, etc.)', 'peanut-suite') . '</li>
                <li><strong>' . __('Conversion Rate', 'peanut-suite') . '</strong> - ' . __('Percentage of visitors who convert', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_popups_help(): string {
        return '
            <h3>' . __('Creating Effective Popups', 'peanut-suite') . '</h3>

            <h4>' . __('Popup Types', 'peanut-suite') . '</h4>
            <ul>
                <li><strong>' . __('Modal', 'peanut-suite') . '</strong> - ' . __('Center-screen overlay that demands attention', 'peanut-suite') . '</li>
                <li><strong>' . __('Slide-in', 'peanut-suite') . '</strong> - ' . __('Slides from corner, less intrusive', 'peanut-suite') . '</li>
                <li><strong>' . __('Bar', 'peanut-suite') . '</strong> - ' . __('Top or bottom banner, persistent', 'peanut-suite') . '</li>
                <li><strong>' . __('Fullscreen', 'peanut-suite') . '</strong> - ' . __('Takes over entire screen, maximum impact', 'peanut-suite') . '</li>
            </ul>

            <h4>' . __('Best Practices', 'peanut-suite') . '</h4>
            <ul>
                <li>' . __('Use clear, compelling headlines', 'peanut-suite') . '</li>
                <li>' . __('Offer real value (discount, free resource)', 'peanut-suite') . '</li>
                <li>' . __('Time popups appropriately (not immediately)', 'peanut-suite') . '</li>
                <li>' . __('Make it easy to close', 'peanut-suite') . '</li>
                <li>' . __('Test different variations', 'peanut-suite') . '</li>
            </ul>
        ';
    }

    private function get_popup_triggers_help(): string {
        return '
            <h3>' . __('Popup Triggers', 'peanut-suite') . '</h3>

            <p><strong>' . __('Time Delay', 'peanut-suite') . '</strong></p>
            <p>' . __('Show popup after X seconds on page. Good for engaged visitors.', 'peanut-suite') . '</p>

            <p><strong>' . __('Scroll Percentage', 'peanut-suite') . '</strong></p>
            <p>' . __('Trigger when visitor scrolls X% down the page. Shows intent.', 'peanut-suite') . '</p>

            <p><strong>' . __('Exit Intent', 'peanut-suite') . '</strong></p>
            <p>' . __('Detect when mouse moves toward browser close/back button. Last chance to convert.', 'peanut-suite') . '</p>

            <p><strong>' . __('Click Element', 'peanut-suite') . '</strong></p>
            <p>' . __('Show when specific element is clicked. Good for manual triggers.', 'peanut-suite') . '</p>

            <p><strong>' . __('Page Views', 'peanut-suite') . '</strong></p>
            <p>' . __('Show after visitor views X pages. Indicates engagement.', 'peanut-suite') . '</p>

            <p><strong>' . __('User Inactivity', 'peanut-suite') . '</strong></p>
            <p>' . __('Trigger after no mouse/scroll activity for X seconds.', 'peanut-suite') . '</p>
        ';
    }

    // ========================================
    // Tooltip Helper Methods
    // ========================================

    /**
     * Get tooltip HTML
     */
    public static function tooltip(string $text, string $icon = 'dashicons-info'): string {
        return sprintf(
            '<span class="peanut-tooltip" data-tip="%s"><span class="dashicons %s"></span></span>',
            esc_attr($text),
            esc_attr($icon)
        );
    }

    /**
     * Get field help text HTML
     */
    public static function field_help(string $text): string {
        return sprintf(
            '<p class="peanut-field-help">%s</p>',
            esc_html($text)
        );
    }

    /**
     * Get field examples HTML
     */
    public static function field_examples(array $examples): string {
        return sprintf(
            '<p class="peanut-field-examples"><span class="label">%s:</span> %s</p>',
            esc_html__('Examples', 'peanut-suite'),
            esc_html(implode(', ', $examples))
        );
    }
}

/**
 * Helper functions for templates
 */
function peanut_tooltip(string $text): string {
    return Peanut_Help::tooltip($text);
}

function peanut_field_help(string $text): string {
    return Peanut_Help::field_help($text);
}

function peanut_field_examples(array $examples): string {
    return Peanut_Help::field_examples($examples);
}
