<?php
/**
 * Auto-update enrolment.
 *
 * Fleet drift, 2026-07-21: sites sat on 4.1.5 and 2.6.4 for months while the
 * licence server advertised 4.2.6 correctly. The updater was never broken —
 * on a live stuck site get_remote_update_info() returned 4.2.6, the filter was
 * hooked, and invoking check_for_update() directly added it to the response.
 *
 * The real cause: our plugins were never in WordPress's `auto_update_plugins`
 * list, so every release landed as a notice in wp-admin waiting for a human
 * click. On sites we maintain but rarely log into, that is permanent drift —
 * and it silently defeats security releases.
 *
 * The fix enrols the plugin once. "Once" is the important part: if someone
 * later turns auto-updates off in wp-admin, that decision must stick rather
 * than being re-applied on the next page load.
 *
 * @package Peanut_Suite
 */

declare(strict_types=1);

namespace PeanutSuite\Tests\Regression;

use PHPUnit\Framework\TestCase;
use Peanut_Updater;

final class AutoUpdateEnrolmentTest extends TestCase
{
    private const PLUGIN_FILE = 'peanut-suite/peanut-suite.php';

    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__, 2) . '/core/services/class-peanut-updater.php';

        $GLOBALS['mock_options']    = [];
        $GLOBALS['mock_transients'] = [];
    }

    private function autoUpdateList(): array
    {
        return (array) get_site_option('auto_update_plugins', []);
    }

    public function test_plugin_enrols_itself_in_auto_updates(): void
    {
        Peanut_Updater::ensure_auto_update_enrolment(self::PLUGIN_FILE);

        $this->assertContains(
            self::PLUGIN_FILE,
            $this->autoUpdateList(),
            'The plugin never enrols itself, so releases sit in wp-admin unclicked — '
            . 'which is exactly how sites drifted to 4.1.5 and 2.6.4.'
        );
    }

    public function test_enrolment_preserves_other_plugins(): void
    {
        update_site_option('auto_update_plugins', ['akismet/akismet.php', 'hello.php']);

        Peanut_Updater::ensure_auto_update_enrolment(self::PLUGIN_FILE);

        $list = $this->autoUpdateList();
        $this->assertContains('akismet/akismet.php', $list, 'Other plugins must not be disturbed.');
        $this->assertContains('hello.php', $list);
        $this->assertContains(self::PLUGIN_FILE, $list);
    }

    public function test_enrolment_is_idempotent(): void
    {
        Peanut_Updater::ensure_auto_update_enrolment(self::PLUGIN_FILE);
        Peanut_Updater::ensure_auto_update_enrolment(self::PLUGIN_FILE);
        Peanut_Updater::ensure_auto_update_enrolment(self::PLUGIN_FILE);

        $this->assertSame(
            1,
            count(array_keys($this->autoUpdateList(), self::PLUGIN_FILE, true)),
            'Repeated enrolment must not duplicate the entry.'
        );
    }

    /**
     * The consent rule. Someone who turns auto-updates OFF in wp-admin has made
     * a decision, and we must not silently override it on the next page load.
     */
    public function test_a_deliberate_opt_out_is_respected(): void
    {
        // First run enrols and records that we have done so.
        Peanut_Updater::ensure_auto_update_enrolment(self::PLUGIN_FILE);
        $this->assertContains(self::PLUGIN_FILE, $this->autoUpdateList());

        // The site owner turns it off in wp-admin.
        update_site_option('auto_update_plugins', []);

        // We must not put it back.
        Peanut_Updater::ensure_auto_update_enrolment(self::PLUGIN_FILE);

        $this->assertNotContains(
            self::PLUGIN_FILE,
            $this->autoUpdateList(),
            'We re-enabled auto-updates after the site owner deliberately disabled them. '
            . 'Enrolment is a one-time default, not a policy we enforce.'
        );
    }

    /**
     * The one-time marker must be what gates re-enrolment, so the decision is
     * durable rather than depending on list contents.
     */
    public function test_enrolment_records_that_it_ran(): void
    {
        $this->assertFalse(
            (bool) get_site_option('peanut_auto_update_enrolled', false),
            'Precondition: not yet enrolled.'
        );

        Peanut_Updater::ensure_auto_update_enrolment(self::PLUGIN_FILE);

        $this->assertTrue(
            (bool) get_site_option('peanut_auto_update_enrolled', false),
            'Enrolment must leave a durable marker, or it will re-apply forever.'
        );
    }

    /**
     * A site that already opted in before this code existed must not be
     * disturbed, and must still get the marker so nothing re-runs.
     */
    public function test_already_enrolled_site_is_left_alone(): void
    {
        update_site_option('auto_update_plugins', [self::PLUGIN_FILE]);

        Peanut_Updater::ensure_auto_update_enrolment(self::PLUGIN_FILE);

        $this->assertSame(
            1,
            count(array_keys($this->autoUpdateList(), self::PLUGIN_FILE, true)),
            'An already-enrolled site must not gain a duplicate entry.'
        );
    }

    /**
     * A filter gives us an escape hatch for a client who does not want us
     * touching their update behaviour at all.
     */
    public function test_enrolment_can_be_disabled_by_filter(): void
    {
        $GLOBALS['mock_filter_overrides']['peanut_suite_auto_enrol_updates'] = false;

        Peanut_Updater::ensure_auto_update_enrolment(self::PLUGIN_FILE);

        unset($GLOBALS['mock_filter_overrides']['peanut_suite_auto_enrol_updates']);

        $this->assertNotContains(
            self::PLUGIN_FILE,
            $this->autoUpdateList(),
            'The opt-out filter must be honoured.'
        );
    }
}
