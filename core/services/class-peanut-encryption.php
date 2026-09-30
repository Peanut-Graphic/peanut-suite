<?php
/**
 * Encryption Service
 *
 * Encrypts secrets Suite keeps at rest: the GA4 Reports OAuth access/refresh
 * tokens (option peanut_ga4_reports_tokens) and the Monitor module's Peanut
 * Connect site keys (options peanut_monitor_site_key_{id}).
 *
 * Two stored formats:
 *
 *  - V2 (written since the authenticated-encryption fix): XChaCha20-Poly1305
 *    AEAD via ext-sodium, or the sodium_compat polyfill WordPress bundles.
 *    Stored as '$PS_ENC$v2:' . base64(nonce . ciphertext . tag) with fixed
 *    associated data 'peanut-suite/v2'. The key is an HKDF-SHA256 subkey over
 *    the full, length-prefixed key material. Tampering, truncation or a wrong
 *    key makes decrypt() fail closed and return ''.
 *
 *  - LEGACY (read only): AES-256-CBC with a random IV, stored as bare
 *    base64(iv . ciphertext), key = sha256(key material). CBC has no MAC, so
 *    these values are malleable. They still decrypt; callers upgrade them to
 *    V2 on their next write (needs_reencrypt() reports them). A legacy value
 *    can never be confused with V2: '$' and ':' are not in the base64 alphabet.
 *
 * Key material is PEANUT_ENCRYPTION_KEY when defined, otherwise wp_salt('auth')
 * (AUTH_KEY . AUTH_SALT from wp-config.php, or random 64-character secrets
 * WordPress generates and stores in the database when those are missing or
 * left at the default). Changing it makes existing values undecryptable.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Encryption {

    /** Legacy cipher (read only, or written only when sodium is unavailable). */
    private const METHOD = 'AES-256-CBC';
    private const IV_LENGTH = 16;

    /** Prefix marking a V2 (authenticated) value. */
    public const V2_PREFIX = '$PS_ENC$v2:';

    /** Associated data bound into every V2 ciphertext. */
    public const V2_AD = 'peanut-suite/v2';

    /** HKDF info string for the V2 subkey. */
    public const V2_INFO = 'peanut-suite/v2/data-at-rest';

    /** Legacy AES-256 key. */
    private string $key;

    /** V2 XChaCha20-Poly1305 key. */
    private string $v2_key;

    /**
     * @param string|null $key Raw 32-byte legacy key (4.2.9 contract). When
     *                         given, the V2 key is derived from it. When null,
     *                         both keys come from the site's key material.
     */
    public function __construct(?string $key = null) {
        if ($key === null) {
            $keys = self::derive_keys(self::site_key_material());
        } else {
            $keys = ['legacy' => $key, 'v2' => self::derive_v2_key('raw-key', $key)];
        }
        $this->key = $keys['legacy'];
        $this->v2_key = $keys['v2'];
    }

    /**
     * An instance keyed from explicit key material, exactly as the site would
     * be if PEANUT_ENCRYPTION_KEY (or wp_salt('auth')) were that material.
     */
    public static function for_key_material(string $material): self {
        $instance = new self(hash('sha256', $material, true));
        $instance->v2_key = self::derive_keys($material)['v2'];
        return $instance;
    }

    /**
     * Derive both keys from key material. Pure, so it is testable without WP.
     *
     * @return array{legacy:string, v2:string}
     */
    public static function derive_keys(string $material): array {
        return [
            'legacy' => hash('sha256', $material, true),
            'v2' => self::derive_v2_key('material', $material),
        ];
    }

    private static function derive_v2_key(string $label, string $material): string {
        // Length-prefixed so no two distinct inputs share an HKDF input.
        return hash_hkdf('sha256', $label . ':' . strlen($material) . ':' . $material, 32, self::V2_INFO);
    }

    private static function site_key_material(): string {
        if (defined('PEANUT_ENCRYPTION_KEY') && PEANUT_ENCRYPTION_KEY) {
            return (string) PEANUT_ENCRYPTION_KEY;
        }
        return wp_salt('auth');
    }

    private static function v2_available(): bool {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }

    /**
     * Whether a stored value is in the V2 format.
     */
    public static function is_v2(string $data): bool {
        return strncmp($data, self::V2_PREFIX, strlen(self::V2_PREFIX)) === 0;
    }

    /**
     * True when a stored value is legacy CBC and should be rewritten as V2
     * (decrypt, encrypt, save) the next time the caller writes it.
     */
    public function needs_reencrypt(string $stored): bool {
        return $stored !== '' && !self::is_v2($stored) && self::v2_available();
    }

    /**
     * Encrypt data (V2). Returns '' on failure or for empty input.
     */
    public function encrypt(string $data): string {
        if (empty($data)) {
            return '';
        }

        if (!self::v2_available()) {
            // Degraded path: no sodium and no sodium_compat. CBC under a real
            // key still beats plaintext; it is upgraded once sodium exists.
            return $this->encrypt_legacy($data);
        }

        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($data, self::V2_AD, $nonce, $this->v2_key);
        } catch (\Throwable $e) {
            return '';
        }

        return self::V2_PREFIX . base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt data (V2 or legacy).
     *
     * Fails closed: on tampering, truncation, a wrong key or corruption it
     * returns '' (the existing failure contract), never partial plaintext.
     */
    public function decrypt(string $data): string {
        if (empty($data)) {
            return '';
        }

        if (self::is_v2($data)) {
            return $this->decrypt_v2($data) ?? '';
        }

        return $this->decrypt_legacy($data) ?? '';
    }

    private function decrypt_v2(string $data): ?string {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            return null;
        }

        $raw = base64_decode(substr($data, strlen(self::V2_PREFIX)), true);
        $nonce_len = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        if ($raw === false || strlen($raw) < $nonce_len + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            return null;
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($raw, $nonce_len),
                self::V2_AD,
                substr($raw, 0, $nonce_len),
                $this->v2_key
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $plaintext === false ? null : $plaintext;
    }

    private function encrypt_legacy(string $data): string {
        if (!function_exists('openssl_encrypt')) {
            return '';
        }

        $iv = random_bytes(self::IV_LENGTH);
        $encrypted = openssl_encrypt($data, self::METHOD, $this->key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            return '';
        }

        return base64_encode($iv . $encrypted);
    }

    private function decrypt_legacy(string $data): ?string {
        if (!function_exists('openssl_decrypt')) {
            return null;
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false || strlen($decoded) <= self::IV_LENGTH) {
            return null;
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH);

        $decrypted = openssl_decrypt($ciphertext, self::METHOD, $this->key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : null;
    }


    /**
     * Encrypt an array (JSON encoded)
     */
    public function encrypt_array(array $data): string {
        $json = wp_json_encode($data);
        if ($json === false) {
            return '';
        }
        return $this->encrypt($json);
    }

    /**
     * Decrypt to array
     */
    public function decrypt_array(string $data): ?array {
        $json = $this->decrypt($data);
        if (empty($json)) {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Check if encryption is available (V2 via sodium, or legacy CBC via OpenSSL)
     */
    public static function is_available(): bool {
        return self::v2_available() || (function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && in_array(self::METHOD, openssl_get_cipher_methods(), true));
    }

    /**
     * Mask sensitive data for display
     */
    public static function mask(string $data, int $visible_start = 0, int $visible_end = 4): string {
        $length = strlen($data);
        if ($length <= $visible_start + $visible_end) {
            return str_repeat('*', $length);
        }
        $start = substr($data, 0, $visible_start);
        $end = $visible_end > 0 ? substr($data, $length - $visible_end) : '';
        $middle = str_repeat('*', $length - $visible_start - $visible_end);
        return $start . $middle . $end;
    }

    /**
     * Generate a random token
     */
    public static function generate_token(int $length = 32): string {
        return bin2hex(random_bytes($length));
    }

    /**
     * Generate a URL-safe random token
     */
    public static function generate_url_safe_token(int $length = 32): string {
        $bytes = random_bytes((int) ceil($length * 0.75));
        return substr(strtr(base64_encode($bytes), '+/', '-_'), 0, $length);
    }
}
