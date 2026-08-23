<?php
/**
 * PHPUnit bootstrap file for Peanut Suite tests.
 *
 * @package Peanut_Suite
 */

// Define test mode.
define('PEANUT_SUITE_TESTING', true);

// Composer autoloader.
$composer_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// Try to load WordPress test environment.
$_tests_dir = getenv('WP_TESTS_DIR');

if (!$_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

// Check if WordPress test suite is available.
if (file_exists($_tests_dir . '/includes/functions.php')) {
    // WordPress test environment available.
    require_once $_tests_dir . '/includes/functions.php';

    /**
     * Manually load the plugin being tested.
     */
    function _manually_load_plugin() {
        require dirname(__DIR__) . '/peanut-suite.php';
    }
    tests_add_filter('muplugins_loaded', '_manually_load_plugin');

    // Start up the WP testing environment.
    require $_tests_dir . '/includes/bootstrap.php';
} else {
    // Standalone testing without WordPress - load mocks.
    require_once __DIR__ . '/mocks/wordpress-mocks.php';

    // Mirror the public plugin constants that are normally defined by the main
    // plugin file before its database and REST classes are loaded.
    if (!defined('PEANUT_API_NAMESPACE')) {
        define('PEANUT_API_NAMESPACE', 'peanut/v1');
    }
    if (!defined('PEANUT_TABLE_PREFIX')) {
        define('PEANUT_TABLE_PREFIX', 'peanut_');
    }

    // Load plugin files that can be tested standalone.
    // Note: Most tests will require WP test environment.
}

/**
 * Base test case class for Peanut Suite.
 */
abstract class Peanut_Suite_TestCase extends \PHPUnit\Framework\TestCase {

    /**
     * Set up before each test.
     */
    protected function setUp(): void {
        parent::setUp();
    }

    /**
     * Tear down after each test.
     */
    protected function tearDown(): void {
        parent::tearDown();
    }
}
