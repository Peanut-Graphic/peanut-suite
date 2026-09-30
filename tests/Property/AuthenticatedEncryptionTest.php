<?php
/**
 * Authenticated (V2) encryption contract for Suite secrets at rest.
 *
 * Peanut_Encryption protects the GA4 Reports OAuth access/refresh tokens and
 * the Monitor module's Peanut Connect site keys. Through 4.2.9 it wrote
 * unauthenticated AES-256-CBC (bare base64(iv . ciphertext), key =
 * sha256(PEANUT_ENCRYPTION_KEY or wp_salt('auth'))), which is malleable.
 *
 * V2 = XChaCha20-Poly1305 (ext-sodium / WordPress's sodium_compat), stored as
 * '$PS_ENC$v2:' . base64(nonce . ciphertext . tag) with fixed associated data
 * and an HKDF-SHA256 subkey. Legacy CBC values must keep decrypting.
 *
 * @package Peanut_Suite
 */

namespace PeanutSuite\Tests\Property;

use PHPUnit\Framework\TestCase;
use Peanut_Encryption;

final class AuthenticatedEncryptionTest extends TestCase
{
    /**
     * Written by the 4.2.9 implementation with the default key derivation,
     * sha256(wp_salt('auth')), where the test mock returns 'test_salt_auth'.
     * Frozen: if this stops decrypting, every stored GA4 token stops working.
     */
    private const LEGACY_DEFAULT_FIXTURE = '4jZX9fd5hN3ny4J/W03yJycBZw0nmozlGe65Q183KspkznqG4mrqFOm6B7LVVgM8';
    private const LEGACY_DEFAULT_PLAINTEXT = 'ya29.legacy-ga4-access-token';

    /**
     * Written by 4.2.9 as new Peanut_Encryption(hash('sha256', MATERIAL, true)),
     * i.e. what a site with PEANUT_ENCRYPTION_KEY = MATERIAL stored.
     */
    private const MATERIAL = 'operator-supplied PEANUT_ENCRYPTION_KEY value';
    private const LEGACY_MATERIAL_FIXTURE = 'zys1DCLgi/RnRexK1O9eW5MadH3O25U8nk1CDyZ5HqxbGSWjkNmVFA4EdlAN4NSy';
    private const LEGACY_MATERIAL_PLAINTEXT = '1//legacy-refresh-token';

    public static function setUpBeforeClass(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        require_once dirname(__DIR__, 2) . '/core/services/class-peanut-encryption.php';
    }

    public function test_new_writes_use_the_v2_authenticated_format(): void
    {
        $enc = new Peanut_Encryption();
        $stored = $enc->encrypt('ya29.fresh-access-token');

        $this->assertStringStartsWith(Peanut_Encryption::V2_PREFIX, $stored);
        $this->assertTrue(Peanut_Encryption::is_v2($stored));
        $this->assertStringNotContainsString('fresh-access-token', $stored);
        $this->assertSame('ya29.fresh-access-token', $enc->decrypt($stored));
    }

    public function test_v2_round_trips_arbitrary_binary_and_utf8(): void
    {
        $enc = new Peanut_Encryption();
        mt_srand(1337);
        for ($i = 0; $i < 200; $i++) {
            $plain = random_bytes(mt_rand(1, 300));
            $this->assertSame($plain, $enc->decrypt($enc->encrypt($plain)));
        }
        $this->assertSame('Café ☕ token', $enc->decrypt($enc->encrypt('Café ☕ token')));
    }

    public function test_v2_payload_is_nonce_plus_ciphertext_plus_tag(): void
    {
        $stored = (new Peanut_Encryption())->encrypt('abc');
        $raw = base64_decode(substr($stored, strlen(Peanut_Encryption::V2_PREFIX)), true);

        $this->assertNotFalse($raw);
        $this->assertSame(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + 3 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES,
            strlen($raw)
        );
    }

    public function test_every_single_bit_flip_fails_closed(): void
    {
        $enc = new Peanut_Encryption();
        $stored = $enc->encrypt('site-key-secret');
        $raw = base64_decode(substr($stored, strlen(Peanut_Encryption::V2_PREFIX)), true);

        for ($i = 0; $i < strlen($raw); $i++) {
            $tampered = $raw;
            $tampered[$i] = chr(ord($tampered[$i]) ^ 0x01);
            $result = $enc->decrypt(Peanut_Encryption::V2_PREFIX . base64_encode($tampered));
            $this->assertSame('', $result, "Flipping byte $i must fail closed with the plugin's '' failure contract.");
        }
    }

    public function test_truncated_and_malformed_v2_values_fail_closed(): void
    {
        $enc = new Peanut_Encryption();
        $stored = $enc->encrypt('site-key-secret');

        $this->assertSame('', $enc->decrypt(substr($stored, 0, -4)));
        $this->assertSame('', $enc->decrypt(Peanut_Encryption::V2_PREFIX . base64_encode(str_repeat("\0", 20))));
        $this->assertSame('', $enc->decrypt(Peanut_Encryption::V2_PREFIX . '!!!not base64!!!'));
        $this->assertSame('', $enc->decrypt(Peanut_Encryption::V2_PREFIX));
    }

    public function test_v2_value_cannot_be_downgraded_to_the_legacy_path(): void
    {
        // Stripping the prefix must not let an attacker route V2 bytes through
        // unauthenticated CBC and get plaintext back.
        $enc = new Peanut_Encryption();
        $stored = $enc->encrypt('site-key-secret');
        $bare = substr($stored, strlen(Peanut_Encryption::V2_PREFIX));

        $this->assertNotSame('site-key-secret', $enc->decrypt($bare));
    }

    public function test_wrong_key_fails_for_v2(): void
    {
        $a = Peanut_Encryption::for_key_material('material-a');
        $b = Peanut_Encryption::for_key_material('material-b');

        $stored = $a->encrypt('refresh-token');

        $this->assertSame('refresh-token', $a->decrypt($stored));
        $this->assertSame('', $b->decrypt($stored));
    }

    public function test_legacy_cbc_value_from_4_2_9_still_decrypts_with_default_key(): void
    {
        $this->assertSame(
            self::LEGACY_DEFAULT_PLAINTEXT,
            (new Peanut_Encryption())->decrypt(self::LEGACY_DEFAULT_FIXTURE)
        );
    }

    public function test_legacy_cbc_value_from_4_2_9_still_decrypts_with_operator_key(): void
    {
        $this->assertSame(
            self::LEGACY_MATERIAL_PLAINTEXT,
            Peanut_Encryption::for_key_material(self::MATERIAL)->decrypt(self::LEGACY_MATERIAL_FIXTURE)
        );
        // The explicit-raw-key constructor keeps its 4.2.9 meaning too.
        $this->assertSame(
            self::LEGACY_MATERIAL_PLAINTEXT,
            (new Peanut_Encryption(hash('sha256', self::MATERIAL, true)))->decrypt(self::LEGACY_MATERIAL_FIXTURE)
        );
    }

    public function test_legacy_value_under_wrong_key_does_not_return_the_plaintext(): void
    {
        $this->assertNotSame(
            self::LEGACY_DEFAULT_PLAINTEXT,
            Peanut_Encryption::for_key_material('some other site')->decrypt(self::LEGACY_DEFAULT_FIXTURE)
        );
    }

    public function test_v2_subkey_is_separated_from_the_legacy_key_and_uses_full_material(): void
    {
        $long = str_repeat('k', 100);
        $a = Peanut_Encryption::derive_keys($long . 'A');
        $b = Peanut_Encryption::derive_keys($long . 'B');

        $this->assertSame(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, strlen($a['v2']));
        $this->assertNotSame($a['v2'], $b['v2']);
        $this->assertNotSame($a['legacy'], $a['v2']);
        $this->assertSame(hash('sha256', $long . 'A', true), $a['legacy']);
    }

    public function test_needs_reencrypt_flags_only_legacy_values(): void
    {
        $enc = new Peanut_Encryption();

        $this->assertTrue($enc->needs_reencrypt(self::LEGACY_DEFAULT_FIXTURE));
        $this->assertFalse($enc->needs_reencrypt($enc->encrypt('x')));
        $this->assertFalse($enc->needs_reencrypt(''));
    }

    public function test_lazy_upgrade_decrypt_then_encrypt_writes_v2(): void
    {
        $enc = new Peanut_Encryption();

        $plain = $enc->decrypt(self::LEGACY_DEFAULT_FIXTURE);
        $upgraded = $enc->encrypt($plain);

        $this->assertStringStartsWith(Peanut_Encryption::V2_PREFIX, $upgraded);
        $this->assertFalse($enc->needs_reencrypt($upgraded));
        $this->assertSame(self::LEGACY_DEFAULT_PLAINTEXT, $enc->decrypt($upgraded));
    }

    public function test_arrays_round_trip_through_v2(): void
    {
        $enc = new Peanut_Encryption();
        $stored = $enc->encrypt_array(['token' => 'abc', 'n' => 3]);

        $this->assertTrue(Peanut_Encryption::is_v2($stored));
        $this->assertSame(['token' => 'abc', 'n' => 3], $enc->decrypt_array($stored));
    }

    public function test_empty_input_keeps_its_contract(): void
    {
        $enc = new Peanut_Encryption();

        $this->assertSame('', $enc->encrypt(''));
        $this->assertSame('', $enc->decrypt(''));
    }
}
