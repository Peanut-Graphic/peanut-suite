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
 * Which senders sign, and how (HMAC-SHA256):
 *  - FormFlow Lite: source "formflow-lite", header X-FFFL-Signature: <hex>
 *    over the raw body; the body carries a signed "timestamp" (ISO 8601).
 *  - FormFlow Pro: sends no source (arrives as "unknown"), header
 *    X-ISF-Signature: <hex> over the raw body; signed body "timestamp".
 *  - Anything else: X-Webhook-Signature (or X-Signature, X-Hub-Signature-256,
 *    X-Peanut-Signature): "sha256=<hex>" or bare <hex>, over EITHER
 *      "<unix ts>.<raw body>" with the same <unix ts> in X-Peanut-Timestamp
 *      (preferred), OR the raw body alone when the JSON body carries a
 *      "timestamp" (Unix seconds or ISO 8601).
 *
 * Replay protection (signed sources): the signed timestamp must be within
 * Peanut_Webhook_Replay_Guard::tolerance() (default 300 s) of the server
 * clock, and each signed delivery is accepted once (see the controller).
 * A signed delivery with no signed timestamp is rejected.
 *
 * The source is taken from the route (POST /webhooks/receive/{source});
 * see Webhooks_Controller for the deprecated legacy route.
 *
 * Sources with NO secret configured are accepted unsigned (existing product
 * behavior); the Webhooks admin page lists them as unsigned.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(__DIR__, 2) . '/core/services/class-peanut-encryption.php';
require_once dirname(__DIR__, 2) . '/core/services/class-peanut-webhook-replay-guard.php';

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

    /** Headers carrying the timestamp of the "<ts>.<body>" signing scheme. */
    private const TIMESTAMP_HEADERS = ['HTTP_X_PEANUT_TIMESTAMP', 'HTTP_X_WEBHOOK_TIMESTAMP'];

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

        return self::matches($payload, $signature, $source, $secret);
    }

    /**
     * Verify a signed delivery: signature, signed timestamp, tolerance window.
     *
     * Accepts "<ts>.<body>" signed with the timestamp from X-Peanut-Timestamp
     * (or X-Webhook-Timestamp), or the raw body alone when the signed JSON
     * body has a "timestamp". The timestamp header is trusted only when the
     * signature covers it; senders' other timestamp headers (X-FFFL-Timestamp,
     * X-ISF-Timestamp) are not signed and are ignored.
     *
     * Call only for a source that has a secret (has_secret()).
     *
     * @param string   $payload          Raw request body.
     * @param string   $signature        Signature header value.
     * @param string   $source           Source bound by the route.
     * @param string   $header_timestamp Timestamp header value ('' if none).
     * @param array    $decoded          Decoded JSON body.
     * @param int|null $now              Clock override (tests).
     * @return array{ok: bool, error: string, timestamp: int|null}
     */
    public static function verify_delivery(string $payload, string $signature, string $source, string $header_timestamp, array $decoded, ?int $now = null): array {
        $fail = static fn(string $error, ?int $ts = null): array => ['ok' => false, 'error' => $error, 'timestamp' => $ts];

        $secret = self::get_secret($source);
        if ($secret === '' || $signature === '') {
            return $fail('invalid_signature');
        }

        $timestamp = null;
        $header_timestamp = trim($header_timestamp);
        if (preg_match('/^\d{1,13}$/', $header_timestamp)
            && self::matches($header_timestamp . '.' . $payload, $signature, $source, $secret)) {
            $timestamp = Peanut_Webhook_Replay_Guard::parse_timestamp($header_timestamp);
        } elseif (self::matches($payload, $signature, $source, $secret)) {
            $timestamp = Peanut_Webhook_Replay_Guard::parse_timestamp($decoded['timestamp'] ?? null);
        } else {
            return $fail('invalid_signature');
        }

        $reason = Peanut_Webhook_Replay_Guard::check_timestamp($timestamp, $now);
        if ($reason !== Peanut_Webhook_Replay_Guard::OK) {
            return $fail($reason, $timestamp);
        }

        return ['ok' => true, 'error' => '', 'timestamp' => $timestamp];
    }

    /**
     * Timestamp header for the "<ts>.<body>" scheme ('' when absent).
     */
    public static function get_timestamp_from_headers(): string {
        foreach (self::TIMESTAMP_HEADERS as $header) {
            if (!empty($_SERVER[$header]) && is_string($_SERVER[$header])) {
                return sanitize_text_field(wp_unslash($_SERVER[$header]));
            }
        }
        return '';
    }

    /**
     * Whether $signature is the HMAC of $signed under $secret, in the
     * format $source uses.
     */
    private static function matches(string $signed, string $signature, string $source, string $secret): bool {
        return match ($source) {
            'formflow-lite', 'formflow' => self::verify_formflow($signed, $signature, $secret),
            default => self::verify_hmac($signed, $signature, $secret),
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
     * Source a sender NAMED in the request (X-Webhook-Source header, else the
     * body "source" field), 'unknown' when it names none.
     *
     * Request input, not a binding: only the deprecated legacy route
     * (POST /webhooks/receive) uses it to pick a source, and the per-source
     * route uses it only to reject a request whose label disagrees with the
     * route.
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
