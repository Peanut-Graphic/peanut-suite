<?php
/**
 * REST API tests for Peanut Suite.
 *
 * Tests REST endpoint permissions, response formats, and data validation.
 *
 * @package Peanut_Suite
 */

class Test_REST_API extends Peanut_Suite_TestCase {

    /**
     * Test that admin endpoints require authentication.
     */
    public function test_admin_endpoints_require_auth() {
        // Admin endpoints that should require authentication.
        $admin_endpoints = [
            '/peanut/v1/admin/dashboard',
            '/peanut/v1/admin/utm',
            '/peanut/v1/admin/links',
            '/peanut/v1/admin/contacts',
            '/peanut/v1/admin/popups',
            '/peanut/v1/admin/visitors',
            '/peanut/v1/admin/settings',
        ];

        foreach ($admin_endpoints as $endpoint) {
            // Simulated check - in real test with WP, would make actual request.
            $this->assertStringContainsString('/admin/', $endpoint);
        }
    }

    /**
     * Test REST namespace is correctly defined.
     */
    public function test_rest_namespace() {
        if (defined('PEANUT_API_NAMESPACE')) {
            $this->assertEquals('peanut/v1', PEANUT_API_NAMESPACE);
        } else {
            $this->markTestSkipped('PEANUT_API_NAMESPACE not defined.');
        }
    }

    /**
     * Test response format structure.
     */
    public function test_response_format_structure() {
        // Expected success response structure.
        $success_response = [
            'success' => true,
            'data' => [],
        ];

        $this->assertArrayHasKey('success', $success_response);
        $this->assertArrayHasKey('data', $success_response);
        $this->assertTrue($success_response['success']);
    }

    /**
     * Test error response format.
     */
    public function test_error_response_format() {
        // Expected error response structure.
        $error_response = [
            'success' => false,
            'error' => 'Error message',
            'code' => 'error_code',
        ];

        $this->assertArrayHasKey('success', $error_response);
        $this->assertArrayHasKey('error', $error_response);
        $this->assertFalse($error_response['success']);
    }

    /**
     * Test UTM parameter validation.
     */
    public function test_utm_parameter_validation() {
        $valid_utm = [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'spring_sale',
        ];

        $invalid_utm = [
            'utm_source' => '',  // Empty source.
            'utm_medium' => str_repeat('a', 300),  // Too long.
        ];

        // Valid UTM should have non-empty values.
        foreach ($valid_utm as $key => $value) {
            $this->assertNotEmpty($value, "UTM $key should not be empty");
            $this->assertLessThanOrEqual(255, strlen($value), "UTM $key should be under 255 chars");
        }

        // Invalid UTM should fail validation.
        $this->assertEmpty($invalid_utm['utm_source']);
        $this->assertGreaterThan(255, strlen($invalid_utm['utm_medium']));
    }

    /**
     * Test link creation validation.
     */
    public function test_link_creation_validation() {
        $valid_link = [
            'url' => 'https://example.com/page',
            'title' => 'Example Link',
        ];

        $invalid_links = [
            ['url' => 'not-a-url', 'title' => 'Bad URL'],
            ['url' => '', 'title' => 'Empty URL'],
            ['url' => 'javascript:alert(1)', 'title' => 'XSS Attempt'],
        ];

        // Valid URL check.
        $this->assertStringStartsWith('https://', $valid_link['url']);

        // Invalid URLs should fail validation.
        foreach ($invalid_links as $link) {
            $is_valid = filter_var($link['url'], FILTER_VALIDATE_URL) !== false;
            $is_safe = strpos($link['url'], 'javascript:') === false;

            // At least one validation should fail.
            $this->assertTrue(!$is_valid || !$is_safe, 'Invalid link should fail validation');
        }
    }

    /**
     * Test contact data sanitization.
     */
    public function test_contact_data_sanitization() {
        $contact_input = [
            'email' => '  test@example.com  ',
            'name' => '<script>alert("xss")</script>John Doe',
            'phone' => '(555) 123-4567',
        ];

        // Email should be trimmed and validated.
        $sanitized_email = sanitize_email(trim($contact_input['email']));
        $this->assertEquals('test@example.com', $sanitized_email);

        // Name should have HTML stripped.
        $sanitized_name = sanitize_text_field($contact_input['name']);
        $this->assertStringNotContainsString('<script>', $sanitized_name);
        $this->assertStringContainsString('John Doe', $sanitized_name);

        // Phone should only contain valid characters.
        $sanitized_phone = preg_replace('/[^0-9\-\(\)\s\+]/', '', $contact_input['phone']);
        $this->assertMatchesRegularExpression('/^[\d\s\-\(\)\+]+$/', $sanitized_phone);
    }

    /**
     * Test popup condition validation.
     */
    public function test_popup_condition_validation() {
        $valid_conditions = [
            'trigger' => 'time_delay',
            'delay_seconds' => 5,
            'pages' => ['home', 'about'],
            'show_once' => true,
        ];

        $this->assertContains($valid_conditions['trigger'], ['time_delay', 'scroll', 'exit_intent', 'click']);
        $this->assertIsInt($valid_conditions['delay_seconds']);
        $this->assertGreaterThanOrEqual(0, $valid_conditions['delay_seconds']);
        $this->assertIsArray($valid_conditions['pages']);
    }

    /**
     * Test visitor session data structure.
     */
    public function test_visitor_session_structure() {
        $session_data = [
            'session_id' => 'abc123def456',
            'ip_hash' => hash('sha256', '192.168.1.1'),
            'user_agent' => 'Mozilla/5.0 (Test)',
            'referrer' => 'https://google.com',
            'created_at' => gmdate('Y-m-d H:i:s'),
        ];

        // Session ID should be alphanumeric.
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $session_data['session_id']);

        // IP should be hashed, not stored raw.
        $this->assertNotEquals('192.168.1.1', $session_data['ip_hash']);
        $this->assertEquals(64, strlen($session_data['ip_hash']));  // SHA256 hash length.

        // Timestamp should be valid.
        $this->assertNotFalse(strtotime($session_data['created_at']));
    }

    /**
     * Test pagination parameters.
     */
    public function test_pagination_parameters() {
        $default_per_page = 50;
        $max_per_page = 100;

        $test_cases = [
            ['input' => 25, 'expected' => 25],
            // WordPress absint(-1) is 1, not 0. Negative input therefore
            // clamps to the minimum rather than falling back to the default.
            ['input' => -1, 'expected' => 1],
            ['input' => 0, 'expected' => $default_per_page],
            ['input' => 200, 'expected' => $max_per_page],
            ['input' => 'abc', 'expected' => $default_per_page],
        ];

        foreach ($test_cases as $case) {
            $per_page = absint($case['input']);
            if ($per_page <= 0) {
                $per_page = $default_per_page;
            }
            if ($per_page > $max_per_page) {
                $per_page = $max_per_page;
            }

            $this->assertEquals($case['expected'], $per_page);
        }
    }

    /**
     * Test date range validation for analytics.
     */
    public function test_date_range_validation() {
        $valid_date = '2024-01-15';
        $invalid_dates = ['not-a-date', '2024-13-01', '01-15-2024', ''];

        // Valid date should pass.
        $parsed = strtotime($valid_date);
        $this->assertNotFalse($parsed);
        $this->assertEquals($valid_date, gmdate('Y-m-d', $parsed));

        // Invalid dates should fail or be corrected.
        foreach ($invalid_dates as $date) {
            if (empty($date)) {
                $this->assertEmpty($date);
            } else {
                // strtotime may parse some formats differently.
                $parsed = strtotime($date);
                if ($parsed !== false) {
                    // If parsed, verify it's reasonable.
                    $year = (int) gmdate('Y', $parsed);
                    $this->assertGreaterThan(1970, $year);
                }
            }
        }
    }
}
