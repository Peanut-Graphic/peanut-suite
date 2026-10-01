<?php
/**
 * Webhook replay guard.
 *
 * Shared by every inbound signed webhook (POST /webhooks/receive*,
 * POST /formflow/event, POST /webhooks/stripe):
 *
 *  - Timestamp tolerance. A delivery must carry a timestamp that its
 *    signature covers, and that timestamp must be within the tolerance
 *    window (default 300 seconds, filter `peanut_webhook_timestamp_tolerance`)
 *    of the server clock, in either direction. Stale and future-dated
 *    deliveries are rejected.
 *
 *  - Delivery dedupe. Each accepted delivery claims a key (derived from
 *    signed content only, never from an unsigned header) for at least the
 *    tolerance window. A second delivery with the same key is a replay.
 *
 * Claims are rows in wp_options named `peanut_whr_<sha1>`, inserted with
 * INSERT IGNORE so two concurrent copies of one delivery cannot both claim
 * it (the option_name UNIQUE index decides). The value is the claim's expiry
 * as a Unix timestamp; expired claims are purged daily and opportunistically.
 * A claim that outlives its TTL is harmless: its key embeds the signed
 * timestamp, which is by then outside the tolerance window.
 *
 * @package Peanut_Suite
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Webhook_Replay_Guard {

    /** Default tolerance window, seconds (Stripe's default). */
    public const DEFAULT_TOLERANCE = 300;

    /** Option-name prefix for dedupe claims. */
    public const CLAIM_PREFIX = 'peanut_whr_';

    /** Reasons returned by check_timestamp(). */
    public const OK = '';
    public const MISSING = 'missing_timestamp';
    public const STALE = 'stale_timestamp';
    public const FUTURE = 'future_timestamp';

    /**
     * Tolerance window in seconds, clamped to 30..3600.
     */
    public static function tolerance(): int {
        $tolerance = (int) apply_filters('peanut_webhook_timestamp_tolerance', self::DEFAULT_TOLERANCE);
        return max(30, min(3600, $tolerance));
    }

    /**
     * Claim TTL: comfortably longer than any delivery the tolerance window
     * still accepts (a timestamp up to `tolerance` in the future stays valid
     * for 2 x tolerance).
     */
    public static function claim_ttl(): int {
        return (2 * self::tolerance()) + 60;
    }

    /**
     * Parse a sender timestamp: Unix seconds (int or digit string; 13-digit
     * millisecond values are accepted) or an ISO 8601 / RFC 3339 string.
     *
     * @param mixed $value Raw value.
     * @return int|null Unix seconds, or null when absent or unparseable.
     */
    public static function parse_timestamp($value): ?int {
        if (is_int($value)) {
            return $value > 9999999999 ? intdiv($value, 1000) : $value;
        }
        if (is_float($value)) {
            return self::parse_timestamp((int) $value);
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{9,13}$/', $value)) {
            return self::parse_timestamp((int) $value);
        }
        // Dates only: require a YYYY-MM-DD prefix so free text such as
        // "now" or "+1 year" (which strtotime would accept) is refused.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value)) {
            return null;
        }

        $parsed = strtotime($value);
        return $parsed === false ? null : $parsed;
    }

    /**
     * Check a signed timestamp against the tolerance window.
     *
     * @param int|null $timestamp Unix seconds the signature covers.
     * @param int|null $now       Clock override (tests).
     * @return string self::OK, or one of MISSING / STALE / FUTURE.
     */
    public static function check_timestamp(?int $timestamp, ?int $now = null): string {
        if ($timestamp === null) {
            return self::MISSING;
        }

        $now = $now ?? time();
        $tolerance = self::tolerance();

        if ($timestamp < $now - $tolerance) {
            return self::STALE;
        }
        if ($timestamp > $now + $tolerance) {
            return self::FUTURE;
        }
        return self::OK;
    }

    /**
     * Dedupe key for a delivery, from signed material only.
     *
     * @param string $scope Receiver + source (e.g. "receive:formflow-lite").
     * @param string $id    Signed delivery identity (event id, or a digest of
     *                      the signed bytes).
     */
    public static function key(string $scope, string $id): string {
        return self::CLAIM_PREFIX . sha1($scope . "\0" . $id);
    }

    /**
     * Claim a delivery. True the first time a key is seen within its TTL;
     * false for a replay.
     *
     * @throws RuntimeException When the claim cannot be recorded (database
     *                          error). Callers answer non-2xx so the sender
     *                          retries: a delivery we cannot dedupe is
     *                          neither accepted nor silently acknowledged.
     */
    public static function claim(string $scope, string $id, ?int $ttl = null, ?int $now = null): bool {
        global $wpdb;

        $now = $now ?? time();
        $ttl = $ttl ?? self::claim_ttl();
        $name = self::key($scope, $id);

        if (random_int(1, 50) === 1) {
            self::purge_expired($now);
        }

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
            $name,
            (string) ($now + $ttl),
            'no'
        ));

        if ($inserted === false) {
            throw new RuntimeException('Webhook replay claim could not be recorded.');
        }

        return $inserted === 1;
    }

    /**
     * Release a claim, so a delivery that failed after claiming (storage or
     * handler error) can be retried by its sender.
     */
    public static function release(string $scope, string $id): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s",
            self::key($scope, $id)
        ));
    }

    /**
     * Delete expired claims.
     *
     * @return int Rows deleted.
     */
    public static function purge_expired(?int $now = null): int {
        global $wpdb;

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
            $wpdb->esc_like(self::CLAIM_PREFIX) . '%',
            $now ?? time()
        ));

        return is_int($deleted) ? $deleted : 0;
    }

    /**
     * Delete every claim (uninstall).
     */
    public static function purge_all(): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like(self::CLAIM_PREFIX) . '%'
        ));
    }
}
