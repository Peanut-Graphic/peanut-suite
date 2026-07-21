<?php
/**
 * Executable behaviour tests for licence validation.
 *
 * The 4.2.5 guards were static source scans — they asserted the SHAPE of the
 * code and never ran a line of it. That is how 4.2.5 shipped with a real
 * defect: it cached "I could not reach the server" exactly like "the server
 * says you are not licensed", so a PAYING site whose 12h cache expired during
 * any network blip dropped to free tier for a full 15 minutes. Before 4.2.5 it
 * recovered on the next page load.
 *
 * These tests execute the class against the mock WordPress harness, so they
 * can tell those two failures apart. The distinction is the whole point:
 *
 *   server says invalid  -> downgrade, cache briefly (the answer is real)
 *   server unreachable   -> keep the last known good grant (grace), back off,
 *                           and NEVER downgrade a customer for our downtime
 *
 * @package Peanut_Suite
 */

declare(strict_types=1);

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;
use Peanut_License;

final class LicenseGracePeriodTest extends TestCase
{
    private const KEY = 'PEANUT-AGENCY-REAL-KEY';

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/core/services/class-peanut-license.php';

        // Reset the mock world between tests.
        $GLOBALS['mock_options']          = [];
        $GLOBALS['mock_transients']       = [];
        $GLOBALS['mock_http_calls']       = [];
        $GLOBALS['mock_http_response']    = null;
        $GLOBALS['mock_home_url']         = 'https://client-site.example.com';
        $GLOBALS['mock_environment_type'] = 'production';
    }

    /** A successful, unsigned grant (signature path falls through during rollout). */
    private function respondActive(string $tier = 'agency'): void
    {
        $GLOBALS['mock_http_response'] = [
            'body' => json_encode([
                'success' => true,
                'status'  => 'active',
                'tier'    => $tier,
            ]),
            'response' => ['code' => 200],
        ];
    }

    /** The server is up and says this licence is no good. */
    private function respondInvalid(): void
    {
        $GLOBALS['mock_http_response'] = [
            'body'     => json_encode(['success' => false, 'message' => 'Invalid license key']),
            'response' => ['code' => 200],
        ];
    }

    /** The server cannot be reached at all. */
    private function respondUnreachable(): void
    {
        $GLOBALS['mock_http_response'] = new \WP_Error('http_request_failed', 'cURL error 28: timeout');
    }

    private function httpCallCount(): int
    {
        return count($GLOBALS['mock_http_calls'] ?? []);
    }

    public function test_a_valid_licence_is_granted_and_cached(): void
    {
        $this->respondActive();

        $result = (new Peanut_License())->validate_license(self::KEY);

        $this->assertSame('active', $result['status']);
        $this->assertNotFalse(get_transient('peanut_license_data'), 'A good grant must be cached.');
    }

    /**
     * THE REGRESSION 4.2.5 INTRODUCED.
     *
     * A paying site, previously validated, hits a network blip once its cache
     * has expired. It must not be downgraded for our downtime.
     */
    public function test_unreachable_server_does_not_downgrade_a_previously_valid_site(): void
    {
        $license = new Peanut_License();

        // Establish a good grant, then let the working cache lapse.
        $this->respondActive();
        $granted = $license->validate_license(self::KEY);
        $this->assertSame('active', $granted['status'], 'Precondition: the site starts out licensed.');
        delete_transient('peanut_license_data');

        // Server goes away.
        $this->respondUnreachable();
        $result = $license->validate_license(self::KEY);

        $this->assertSame(
            'active',
            $result['status'],
            'A paying site was downgraded because WE could not be reached. The last known '
            . 'good grant must carry it through the outage.'
        );
        $this->assertSame(
            $granted['tier'],
            $result['tier'],
            'Grace must preserve the granted tier, not just the active status.'
        );
    }

    /**
     * Grace must not become a hammer: while the server is unreachable we should
     * not re-attempt on every single call.
     */
    public function test_unreachable_server_backs_off_instead_of_retrying_every_call(): void
    {
        $license = new Peanut_License();

        $this->respondActive();
        $license->validate_license(self::KEY);
        delete_transient('peanut_license_data');

        $this->respondUnreachable();
        $GLOBALS['mock_http_calls'] = [];

        for ($i = 0; $i < 5; $i++) {
            $license->validate_license(self::KEY, true); // force refresh every time
        }

        $this->assertLessThanOrEqual(
            1,
            $this->httpCallCount(),
            'Five forced validations produced ' . $this->httpCallCount() . ' outbound requests. '
            . 'That is the loop that took the licence server down.'
        );
    }

    /**
     * An explicit "no" from a reachable server is real information and must be
     * honoured — grace is for OUR failures, not for expired customers.
     */
    public function test_server_saying_invalid_does_downgrade_even_with_a_prior_grant(): void
    {
        $license = new Peanut_License();

        $this->respondActive();
        $license->validate_license(self::KEY);
        delete_transient('peanut_license_data');

        $this->respondInvalid();
        $result = $license->validate_license(self::KEY, true);

        $this->assertNotSame(
            'active',
            $result['status'],
            'The server explicitly rejected this licence; grace must not override that.'
        );
    }

    /**
     * Grace cannot be forever, or a cancelled customer keeps their tier by
     * simply being unreachable.
     */
    public function test_grace_expires_after_the_maximum_window(): void
    {
        $license = new Peanut_License();

        $this->respondActive();
        $license->validate_license(self::KEY);
        delete_transient('peanut_license_data');

        // Age the stored grant well past any sane grace window.
        $stored = get_option('peanut_license_last_good');
        $this->assertIsArray($stored, 'A successful grant must be persisted for grace.');
        $stored['granted_at'] = time() - (400 * DAY_IN_SECONDS);
        update_option('peanut_license_last_good', $stored);

        $this->respondUnreachable();
        $result = $license->validate_license(self::KEY, true);

        $this->assertNotSame(
            'active',
            $result['status'],
            'A grant this stale must no longer be honoured.'
        );
    }

    /**
     * A site that never had a valid licence gets no grace — it simply runs free.
     */
    public function test_unreachable_with_no_prior_grant_falls_back_to_free(): void
    {
        $this->respondUnreachable();

        $result = (new Peanut_License())->validate_license(self::KEY);

        $this->assertSame('free', $result['tier']);
    }

    // ---------------------------------------------------------------------
    // Self-host short-circuit — executed, not grepped.
    // ---------------------------------------------------------------------

    /**
     * @dataProvider selfHostCases
     */
    public function test_self_host_detection(string $site_url, bool $expected_self, string $why): void
    {
        $GLOBALS['mock_home_url'] = $site_url;
        $this->respondActive();
        $GLOBALS['mock_http_calls'] = [];

        (new Peanut_License())->validate_license(self::KEY, true);

        $made_request = $this->httpCallCount() > 0;

        $this->assertSame(
            $expected_self,
            !$made_request,
            $why
        );
    }

    public static function selfHostCases(): array
    {
        return [
            'apex is the licence server' => [
                'https://peanutgraphic.com', true,
                'The licence server must not HTTP itself — that is the deadlock.',
            ],
            'www is the same host' => [
                'https://www.peanutgraphic.com', true,
                'www and apex are the same site; a string compare would miss this.',
            ],
            'uppercase is the same host' => [
                'https://WWW.PeanutGraphic.COM', true,
                'Host comparison must be case-insensitive.',
            ],
            'subdomain is NOT the licence server' => [
                'https://hub.peanutgraphic.com', false,
                'hub.peanutgraphic.com is a different site and must still validate remotely.',
            ],
            'client site is NOT the licence server' => [
                'https://energywiserewards.pepco.com', false,
                'A client site must always validate remotely.',
            ],
            'lookalike domain is NOT the licence server' => [
                'https://peanutgraphic.com.evil.example', false,
                'Suffix confusion must not short-circuit validation.',
            ],
        ];
    }
}
