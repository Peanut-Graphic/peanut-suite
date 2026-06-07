<?php
/**
 * Encryption Service
 *
 * AES-256-CBC encryption for sensitive data (tokens, API keys, etc.)
 */

if (!defined('ABSPATH')) {
    exit;
}

class Peanut_Encryption {

    private const METHOD = 'AES-256-CBC';
    private const IV_LENGTH = 16;

    private string $key;

    public function __construct(?string $key = null) {
        $this->key = $key ?? $this->derive_key();
    }

    private function derive_key(): string {
        if (defined('PEANUT_ENCRYPTION_KEY') && PEANUT_ENCRYPTION_KEY) {
            return hash('sha256', PEANUT_ENCRYPTION_KEY, true);
        }
        return hash('sha256', wp_salt('auth'), true);
    }

    /**
     * Encrypt data
     */
    public function encrypt(string $data): string {
        if (empty($data)) {
            return '';
        }

        $iv = openssl_random_pseudo_bytes(self::IV_LENGTH);
        if ($iv === false) {
            return '';
        }

        $encrypted = openssl_encrypt($data, self::METHOD, $this->key, OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            return '';
        }

        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt data
     */
    public function decrypt(string $data): string {
        if (empty($data)) {
            return '';
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false || strlen($decoded) < self::IV_LENGTH) {
            return '';
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);
        $ciphertext = substr($decoded, self::IV_LENGTH);

        $decrypted = openssl_decrypt($ciphertext, self::METHOD, $this->key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
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
     * Check if encryption is available
     */
    public static function is_available(): bool {
        return function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && in_array(self::METHOD, openssl_get_cipher_methods(), true);
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
