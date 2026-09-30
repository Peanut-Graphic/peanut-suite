<?php
/**
 * Storage for the GA Integration module's Google OAuth credentials
 * (option peanut_ga_credentials).
 *
 * The OAuth client secret and the access/refresh tokens were stored in
 * plaintext, and the admin page printed the client secret into a password
 * input's value attribute (so it was in page source). They are now encrypted
 * at rest with Peanut_Encryption V2, following Peanut_Settings_Secrets:
 *
 *  - Stored value: '$PS_ENC$v2:...' ciphertext. A value without that prefix
 *    is legacy plaintext (written before this change); it is still read as-is
 *    and is encrypted by the next save and by maybe_upgrade().
 *  - Read (load()): V2 values are decrypted; a value that fails
 *    authentication reads as '' (not configured), so ciphertext is never sent
 *    to Google.
 *  - Save (merge_submission()): an empty submitted client secret keeps the
 *    stored one, so a form that never re-displays the secret does not wipe it.
 *
 * Non-secret fields (client_id, property_id, gsc_property, token_expires)
 * stay plaintext.
 *
 * Only V2 is written. If sodium (and WordPress's sodium_compat) were missing,
 * Peanut_Encryption would fall back to unprefixed CBC, which the read path
 * would mistake for plaintext, so in that case the value is left as it was
 * and the upgrade flag stays unset to retry once sodium exists.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_GA_Credentials {

    public const OPTION = 'peanut_ga_credentials';

    /** Fields encrypted at rest. */
    public const SECRET_FIELDS = ['client_secret', 'access_token', 'refresh_token'];

    /** Option recording that the one-time plaintext upgrade has completed. */
    public const UPGRADE_FLAG = 'peanut_ga_credentials_secrets_version';
    public const UPGRADE_VERSION = '1';

    public const DEFAULTS = [
        'client_id' => '',
        'client_secret' => '',
        'access_token' => '',
        'refresh_token' => '',
        'token_expires' => 0,
        'property_id' => '',
        'gsc_property' => '',
    ];

    /**
     * Credentials with secrets in plaintext, for use with Google only.
     * A secret that cannot be decrypted reads as ''.
     */
    public static function load(): array {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        $credentials = array_merge(self::DEFAULTS, $stored);
        foreach (self::SECRET_FIELDS as $field) {
            $credentials[$field] = self::read_secret($credentials[$field]);
        }
        return $credentials;
    }

    /**
     * Persist credentials given in plaintext; secrets are encrypted.
     */
    public static function save(array $credentials): bool {
        $credentials = array_merge(self::DEFAULTS, $credentials);

        // Keep the stored ciphertext of a secret whose value is unchanged, so
        // saving unrelated fields does not re-encrypt (churn) every secret.
        $stored = get_option(self::OPTION, []);
        if (is_array($stored)) {
            foreach (self::SECRET_FIELDS as $field) {
                $existing = $stored[$field] ?? '';
                if (is_string($existing) && Peanut_Encryption::is_v2($existing)
                    && is_string($credentials[$field]) && $credentials[$field] !== ''
                    && hash_equals(self::read_secret($existing), $credentials[$field])) {
                    $credentials[$field] = $existing;
                }
            }
        }

        return update_option(self::OPTION, self::encrypt_all($credentials));
    }

    /**
     * Merge a settings-form submission over the current (plaintext)
     * credentials. A blank client secret keeps the stored one. Tokens are
     * never taken from a submission.
     */
    public static function merge_submission(array $submitted, array $current): array {
        $merged = $current;
        foreach (['client_id', 'property_id', 'gsc_property'] as $field) {
            if (array_key_exists($field, $submitted)) {
                $merged[$field] = (string) $submitted[$field];
            }
        }

        $secret = $submitted['client_secret'] ?? '';
        if (is_string($secret) && trim($secret) !== '') {
            $merged['client_secret'] = trim($secret);
        }

        return $merged;
    }

    /**
     * True when a usable client secret is stored (display "secret is set").
     */
    public static function has_client_secret(): bool {
        return self::load()['client_secret'] !== '';
    }

    /**
     * Encrypt every plaintext secret field. V2 values are left untouched.
     */
    public static function encrypt_all(array $credentials): array {
        foreach (self::SECRET_FIELDS as $field) {
            $value = $credentials[$field] ?? '';
            if (!is_string($value) || $value === '' || Peanut_Encryption::is_v2($value)) {
                continue;
            }

            $encrypted = (new Peanut_Encryption())->encrypt($value);
            if (Peanut_Encryption::is_v2($encrypted)) {
                $credentials[$field] = $encrypted;
            }
        }
        return $credentials;
    }

    /**
     * True when every secret field is empty or V2.
     */
    public static function all_encrypted(array $credentials): bool {
        foreach (self::SECRET_FIELDS as $field) {
            $value = $credentials[$field] ?? '';
            if (is_string($value) && $value !== '' && !Peanut_Encryption::is_v2($value)) {
                return false;
            }
        }
        return true;
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

        $stored = get_option(self::OPTION, []);
        if (is_array($stored) && $stored !== []) {
            $upgraded = self::encrypt_all($stored);
            if ($upgraded !== $stored) {
                update_option(self::OPTION, $upgraded);
            }
            if (!self::all_encrypted($upgraded)) {
                // Encryption unavailable: retry on a later request.
                return true;
            }
        }

        update_option(self::UPGRADE_FLAG, self::UPGRADE_VERSION);
        return true;
    }

    private static function read_secret($stored): string {
        if (!is_string($stored) || $stored === '') {
            return '';
        }
        if (Peanut_Encryption::is_v2($stored)) {
            return (new Peanut_Encryption())->decrypt($stored);
        }
        // Legacy plaintext, stored before the secret was encrypted.
        return $stored;
    }
}
