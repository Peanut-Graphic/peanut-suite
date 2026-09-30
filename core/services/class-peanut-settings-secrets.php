<?php
/**
 * Secrets stored inside the peanut_settings option.
 *
 * The GA4 Reports OAuth client secret (peanut_settings['ga4_reports_client_secret'])
 * guards the OAuth tokens that Peanut_Integration_GA4_Reports keeps encrypted,
 * so it is encrypted at rest with the same Peanut_Encryption V2 format.
 *
 *  - Stored value: '$PS_ENC$v2:...' ciphertext. A value without that prefix is
 *    a legacy plaintext secret (written before this change); it is still read
 *    as-is and is encrypted by the next settings save and by maybe_upgrade().
 *  - Read (get()): V2 values are decrypted; a value that fails authentication
 *    reads as '' (not configured), so ciphertext is never sent to Google.
 *  - Output (redact()): the secret is blanked and a "<key>_set" boolean says
 *    whether one is stored. Neither the plaintext nor the ciphertext leaves.
 *  - Save (prepare_update()): an empty submitted value keeps the stored
 *    secret, so a form or client that round-trips redact()'s output does not
 *    wipe it. Anything else replaces it.
 *
 * Only V2 is written. If sodium (and WordPress's sodium_compat) were missing,
 * Peanut_Encryption would fall back to unprefixed CBC, which this read path
 * would mistake for a plaintext secret, so in that case the value is left as
 * it was and the upgrade flag stays unset to retry once sodium exists.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Settings_Secrets {

    /** peanut_settings keys whose values are encrypted at rest. */
    public const KEYS = ['ga4_reports_client_secret'];

    /** Suffix of the display-only "a secret is stored" flag in REST output. */
    public const SET_SUFFIX = '_set';

    /** Option recording that the one-time plaintext upgrade has completed. */
    public const UPGRADE_FLAG = 'peanut_settings_secrets_version';
    public const UPGRADE_VERSION = '1';

    /**
     * Plaintext secret for use with the remote service. '' when unset or when
     * the stored value cannot be decrypted.
     */
    public static function get(array $settings, string $key): string {
        $stored = $settings[$key] ?? '';
        if (!is_string($stored) || $stored === '') {
            return '';
        }

        if (Peanut_Encryption::is_v2($stored)) {
            return (new Peanut_Encryption())->decrypt($stored);
        }

        // Legacy plaintext, stored before the secret was encrypted.
        return $stored;
    }

    /**
     * Settings safe to return to a client: secrets blanked, "<key>_set" added.
     */
    public static function redact(array $settings): array {
        foreach (self::KEYS as $key) {
            $settings[$key . self::SET_SUFFIX] = self::get($settings, $key) !== '';
            $settings[$key] = '';
        }
        return $settings;
    }

    /**
     * Merge submitted settings over the current ones for storage.
     *
     * Blank secrets and the display-only "_set" flags are dropped from the
     * submission (keep existing); every secret in the result is then stored
     * encrypted, which also upgrades a legacy plaintext value on any save.
     */
    public static function prepare_update(array $submitted, array $current): array {
        foreach (self::KEYS as $key) {
            unset($submitted[$key . self::SET_SUFFIX]);
            if (array_key_exists($key, $submitted)
                && (!is_string($submitted[$key]) || trim($submitted[$key]) === '')) {
                unset($submitted[$key]);
            }
        }

        return self::encrypt_all(array_merge($current, $submitted));
    }

    /**
     * Encrypt every plaintext secret in a settings array. V2 values are left
     * untouched (no re-encryption churn).
     */
    public static function encrypt_all(array $settings): array {
        foreach (self::KEYS as $key) {
            $value = $settings[$key] ?? '';
            if (!is_string($value) || $value === '' || Peanut_Encryption::is_v2($value)) {
                continue;
            }

            $encrypted = (new Peanut_Encryption())->encrypt($value);
            if (Peanut_Encryption::is_v2($encrypted)) {
                $settings[$key] = $encrypted;
            }
        }
        return $settings;
    }

    /**
     * True when every secret in the settings is empty or V2.
     */
    public static function all_encrypted(array $settings): bool {
        foreach (self::KEYS as $key) {
            $value = $settings[$key] ?? '';
            if (is_string($value) && $value !== '' && !Peanut_Encryption::is_v2($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * One-time upgrade: encrypt legacy plaintext secrets already stored.
     * Gated by UPGRADE_FLAG so steady state costs one (autoloaded) get_option.
     *
     * @return bool True if the upgrade ran.
     */
    public static function maybe_upgrade(): bool {
        if (get_option(self::UPGRADE_FLAG) === self::UPGRADE_VERSION) {
            return false;
        }

        $settings = get_option('peanut_settings', []);
        if (is_array($settings)) {
            $upgraded = self::encrypt_all($settings);
            if ($upgraded !== $settings) {
                update_option('peanut_settings', $upgraded);
            }
            if (!self::all_encrypted($upgraded)) {
                // Encryption unavailable: retry on a later request.
                return true;
            }
        }

        update_option(self::UPGRADE_FLAG, self::UPGRADE_VERSION);
        return true;
    }
}
