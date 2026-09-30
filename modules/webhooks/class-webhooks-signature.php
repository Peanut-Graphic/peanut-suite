<?php
/**
 * Webhooks Signature Verification
 *
 * Handles signature verification for incoming webhooks, and the per-source
 * signing secrets (option peanut_webhook_secrets, map source => secret).
 *
 * Secrets are encrypted at rest with Peanut_Encryption V2. A value without
 * the V2 prefix is a legacy plaintext secret (hand-set before this change);
 * it still verifies and is encrypted by maybe_upgrade(). A configured secret
 * that fails to decrypt fails CLOSED: the source then accepts nothing.
 *
 * Which senders sign, and how (HMAC-SHA256 over the raw request body):
 *  - FormFlow Lite: source "formflow-lite", header X-FFFL-Signature: <hex>.
 *  - FormFlow Pro: sends no source (arrives as "unknown"), header
 *    X-ISF-Signature: <hex>.
 *  - Anything else: X-Webhook-Signature (or X-Signature, X-Hub-Signature-256,
 *    X-Peanut-Signature): "sha256=<hex>" or bare <hex>; name the source with
 *    an X-Webhook-Source header or a "source" field in the JSON body.
 *
 * Sources with NO secret configured are accepted unsigned (existing product
 * behavior); the Webhooks admin page lists them as unsigned.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/core/services/class-peanut-encryption.php';

class Webhooks_Signature {

    public const OPTION = 'peanut_webhook_secrets';

    /** Option recording that the one-time plaintext upgrade has completed. */
    public const UPGRADE_FLAG = 'peanut_webhook_secrets_version';
    public const UPGRADE_VERSION = '1';

    /** Senders Suite knows about; always listed in the signing status. */
    public const KNOWN_SOURCES = ['formflow-lite', 'formflow'];

    /** Shortest secret accepted when an admin pastes one. */
    public const MIN_SECRET_LENGTH = 16;

    private const SOURCE_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';

    /**
     * Verify webhook signature
     *
     * @param string $payload Raw request body
     * @param string $signature Signature from header
     * @param string $source Webhook source identifier
     * @return bool True if signature is valid
     */
    public static function verify(string $payload, string $signature, string $source): bool {
        if (!self::has_secret($source)) {
            // No secret configured for this source: accepted unsigned
            // (existing product behavior, see the class docblock).
            return true;
        }

        $secret = self::get_secret($source);
        if ($secret === '') {
            // Configured but unreadable (tampered, or the site key changed):
            // fail closed rather than fall back to "unsigned".
            return false;
        }

        // Different sources may use different signature formats
        return match ($source) {
            'formflow-lite', 'formflow' => self::verify_formflow($payload, $signature, $secret),
            default => self::verify_hmac($payload, $signature, $secret),
        };
    }

    /**
     * Verify FormFlow signature
     *
     * FormFlow uses: sha256=HMAC-SHA256(payload, secret)
     */
    private static function verify_formflow(string $payload, string $signature, string $secret): bool {
        // FormFlow sends signature as "sha256=hash"
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Generic HMAC-SHA256 verification
     */
    private static function verify_hmac(string $payload, string $signature, string $secret): bool {
        // Strip any prefix like "sha256="
        if (str_contains($signature, '=')) {
            $parts = explode('=', $signature, 2);
            $signature = $parts[1] ?? $signature;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Plaintext signing secret for a source. '' when none is configured or
     * the stored value cannot be decrypted (use has_secret() to tell apart).
     */
    public static function get_secret(string $source): string {
        $stored = self::stored()[$source] ?? '';
        if (!is_string($stored) || $stored === '') {
            return '';
        }

        if (Peanut_Encryption::is_v2($stored)) {
            return (new Peanut_Encryption())->decrypt($stored);
        }

        // Legacy plaintext, stored before secrets were encrypted.
        return $stored;
    }

    /**
     * Whether a secret is configured for a source (readable or not).
     */
    public static function has_secret(string $source): bool {
        $stored = self::stored()[$source] ?? '';
        return is_string($stored) && $stored !== '';
    }

    /**
     * Whether a source name can hold a secret.
     */
    public static function is_valid_source(string $source): bool {
        return (bool) preg_match(self::SOURCE_PATTERN, $source);
    }

    /**
     * Set (or rotate) the signing secret for a source; stored encrypted.
     * Refuses an invalid source, a short secret, or storing without V2
     * encryption available.
     */
    public static function set_secret(string $source, string $secret): bool {
        if (!self::is_valid_source($source) || strlen($secret) < self::MIN_SECRET_LENGTH) {
            return false;
        }

        $encrypted = (new Peanut_Encryption())->encrypt($secret);
        if (!Peanut_Encryption::is_v2($encrypted)) {
            return false;
        }

        $secrets = self::stored();
        $secrets[$source] = $encrypted;

        return update_option(self::OPTION, $secrets);
    }

    /**
     * Delete webhook secret for a source
     */
    public static function delete_secret(string $source): bool {
        $secrets = self::stored();
        if (!array_key_exists($source, $secrets)) {
            return true;
        }
        unset($secrets[$source]);

        return update_option(self::OPTION, $secrets);
    }

    /**
     * Generate a new webhook secret: 256 random bits as 64 hex characters,
     * safe to paste into any sender's settings.
     */
    public static function generate_secret(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Signing status per source, never including a secret.
     *
     * Lists the built-in senders, every source with a secret, and every
     * source that has already delivered a webhook ($seen_sources).
     *
     * @param string[] $seen_sources Distinct sources in the webhook log.
     * @return array<int, array{source:string, signed:bool, readable:bool, seen:bool}>
     */
    public static function sources_status(array $seen_sources): array {
        $seen = array_values(array_filter(array_map('strval', $seen_sources), static fn($s) => $s !== ''));
        $sources = array_unique(array_merge(self::KNOWN_SOURCES, array_keys(self::stored()), $seen));
        sort($sources);

        $status = [];
        foreach ($sources as $source) {
            $source = (string) $source;
            $signed = self::has_secret($source);
            $status[] = [
                'source' => $source,
                'signed' => $signed,
                'readable' => $signed && self::get_secret($source) !== '',
                'seen' => in_array($source, $seen, true),
            ];
        }
        return $status;
    }

    /**
     * One-time upgrade: encrypt legacy plaintext secrets already stored.
     * Gated by UPGRADE_FLAG so steady state costs one get_option.
     *
     * @return bool True if the upgrade ran.
     */
    public static function maybe_upgrade(): bool {
        if (get_option(self::UPGRADE_FLAG) === self::UPGRADE_VERSION) {
            return false;
        }

        $secrets = self::stored();
        $upgraded = $secrets;
        $complete = true;
        foreach ($secrets as $source => $value) {
            if (!is_string($value) || $value === '' || Peanut_Encryption::is_v2($value)) {
                continue;
            }
            $encrypted = (new Peanut_Encryption())->encrypt($value);
            if (Peanut_Encryption::is_v2($encrypted)) {
                $upgraded[$source] = $encrypted;
            } else {
                $complete = false;
            }
        }

        if ($upgraded !== $secrets) {
            update_option(self::OPTION, $upgraded);
        }
        if (!$complete) {
            // Encryption unavailable: retry on a later request.
            return true;
        }

        update_option(self::UPGRADE_FLAG, self::UPGRADE_VERSION);
        return true;
    }

    private static function stored(): array {
        $secrets = get_option(self::OPTION, []);
        return is_array($secrets) ? $secrets : [];
    }

    /**
     * Get signature from request headers
     */
    public static function get_signature_from_headers(): ?string {
        // Check various header formats
        $headers = [
            'HTTP_X_WEBHOOK_SIGNATURE',
            'HTTP_X_FFFL_SIGNATURE',   // FormFlow Lite
            'HTTP_X_ISF_SIGNATURE',    // FormFlow Pro
            'HTTP_X_SIGNATURE',
            'HTTP_X_HUB_SIGNATURE_256',
            'HTTP_X_HUB_SIGNATURE',
            'HTTP_SIGNATURE',
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                return $_SERVER[$header];
            }
        }

        // Check for WordPress REST API header format
        $signature = isset($_SERVER['HTTP_X_PEANUT_SIGNATURE'])
            ? $_SERVER['HTTP_X_PEANUT_SIGNATURE']
            : null;

        return $signature;
    }

    /**
     * Get source from request headers or body
     */
    public static function get_source_from_request(array $payload): string {
        // Check header first
        if (!empty($_SERVER['HTTP_X_WEBHOOK_SOURCE'])) {
            return sanitize_text_field($_SERVER['HTTP_X_WEBHOOK_SOURCE']);
        }

        // Check payload
        if (!empty($payload['source'])) {
            return sanitize_text_field($payload['source']);
        }

        // Default to unknown
        return 'unknown';
    }
}
