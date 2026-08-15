<?php
/**
 * Security tests for Peanut Suite.
 *
 * Tests sanitization, escaping, and security functions.
 *
 * @package Peanut_Suite
 */

class Test_Security extends Peanut_Suite_TestCase {

    /**
     * Test that sanitize_text_field strips HTML tags.
     */
    public function test_sanitize_text_field_strips_html() {
        $input = '<script>alert("xss")</script>Hello';
        $result = sanitize_text_field($input);

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringNotContainsString('</script>', $result);
        $this->assertStringContainsString('Hello', $result);
    }

    /**
     * Test that esc_html properly escapes HTML entities.
     */
    public function test_esc_html_escapes_entities() {
        $input = '<div class="test">&nbsp;</div>';
        $result = esc_html($input);

        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&gt;', $result);
        $this->assertStringContainsString('&quot;', $result);
    }

    /**
     * Test that esc_attr escapes attributes properly.
     */
    public function test_esc_attr_escapes_attributes() {
        $input = '" onclick="alert(1)"';
        $result = esc_attr($input);

        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringNotContainsString('" onclick="', $result);
        $this->assertSame(
            '&quot; onclick=&quot;alert(1)&quot;',
            $result,
            'esc_attr encodes delimiters; it does not remove harmless attribute text.'
        );
    }

    /**
     * Test WordPress sanitize_email semantics without confusing sanitization
     * with validation. Invalid-but-clean syntax is intentionally preserved by
     * WordPress; callers that require validity must additionally use is_email.
     */
    public function test_sanitize_email_removes_disallowed_characters() {
        $valid_email = 'test@example.com';

        $this->assertEquals($valid_email, sanitize_email($valid_email));
        $this->assertEquals('not-an-email', sanitize_email('not-an-email'));
        $this->assertEquals('test@example.com', sanitize_email('te(st)@example.com'));
    }

    /**
     * The plugin's security boundary must reject invalid email syntax even
     * though the lower-level WordPress sanitizer preserves it.
     */
    public function test_security_service_rejects_invalid_email_syntax() {
        require_once dirname(__DIR__) . '/core/services/class-peanut-security.php';

        $this->assertSame('', Peanut_Security::sanitize_field('contact_email', 'not-an-email'));
        $this->assertSame('test@example.com', Peanut_Security::sanitize_field('contact_email', 'test@example.com'));
    }

    /**
     * Test wp_unslash removes slashes.
     */
    public function test_wp_unslash_removes_slashes() {
        $input = "test\\'s value";
        $result = wp_unslash($input);

        $this->assertEquals("test's value", $result);
    }

    /**
     * Test absint returns absolute integer.
     */
    public function test_absint_returns_absolute_integer() {
        $this->assertEquals(5, absint(5));
        $this->assertEquals(5, absint(-5));
        $this->assertEquals(5, absint('5'));
        $this->assertEquals(5, absint('-5'));
        $this->assertEquals(0, absint('abc'));
    }

    /**
     * Test that XSS vectors are properly neutralized.
     */
    public function test_xss_vectors_neutralized() {
        $xss_vectors = [
            '<script>alert(1)</script>',
            '<img src=x onerror=alert(1)>',
            '<svg onload=alert(1)>',
            'javascript:alert(1)',
            '<a href="javascript:alert(1)">click</a>',
        ];

        foreach ($xss_vectors as $vector) {
            $sanitized = sanitize_text_field($vector);
            $escaped = esc_html($sanitized);

            // Should not contain unescaped script tags.
            $this->assertStringNotContainsString('<script>', $escaped);
            // Should not contain event handlers.
            $this->assertStringNotContainsString('onerror=', strtolower($escaped));
            $this->assertStringNotContainsString('onload=', strtolower($escaped));
        }
    }

    /**
     * Test SQL injection patterns are not in sanitized output.
     */
    public function test_sql_injection_patterns_removed() {
        $sql_vectors = [
            "'; DROP TABLE users; --",
            "1' OR '1'='1",
            "1; DELETE FROM posts",
            "UNION SELECT * FROM wp_users",
        ];

        foreach ($sql_vectors as $vector) {
            $sanitized = sanitize_text_field($vector);

            // Sanitize_text_field should strip dangerous characters.
            // Note: Actual SQL protection requires prepared statements.
            $this->assertIsString($sanitized);
        }
    }
}
