<?php
defined('ABSPATH') || exit;

/**
 * Guardify_Crypto — at-rest protection for secrets kept in wp_options.
 *
 * WordPress has no key store, so a shared secret has to live in the options table.
 * Storing it in plaintext means a single database dump — a leaked backup, an SQL
 * injection in an unrelated plugin, a misconfigured phpMyAdmin — hands over a
 * working credential. Encrypting it with a key derived from the wp-config.php salts
 * raises the bar: an attacker now needs the database *and* the filesystem.
 *
 * This is deliberately not presented as strong protection against a full site
 * compromise. If an attacker can read wp-config.php they can decrypt. The goal is to
 * make database-only exposure non-fatal, which covers the majority of real incidents.
 */
class Guardify_Crypto {

    const CIPHER = 'aes-256-gcm';
    const PREFIX = 'gfenc1:';

    /**
     * Derive a 32-byte key from the site's WordPress salts.
     *
     * Falls back through the available salt constants so the derivation still works
     * on installs with an unusual wp-config.php. If none are defined the class
     * reports itself unavailable rather than encrypting with a guessable key.
     */
    private static function key() {
        $material = '';
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT'] as $const) {
            if (defined($const)) {
                $value = constant($const);
                // Freshly generated wp-config files use placeholder text; ignore it.
                if (is_string($value) && $value !== '' && strpos($value, 'put your unique phrase here') === false) {
                    $material .= $value;
                }
            }
        }

        if (strlen($material) < 16) {
            return false;
        }

        return hash_hkdf('sha256', $material, 32, 'guardify-secret-v1');
    }

    /**
     * Whether encryption is usable on this host.
     */
    public static function is_available() {
        return function_exists('openssl_encrypt')
            && function_exists('hash_hkdf')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true)
            && self::key() !== false;
    }

    /**
     * Encrypt a value for storage. Returns the plaintext unchanged (and unprefixed)
     * when encryption is unavailable, so the caller keeps working on hosts without
     * OpenSSL rather than losing the ability to authenticate.
     *
     * @param string $plaintext
     * @return string
     */
    public static function encrypt($plaintext) {
        if ($plaintext === '' || !self::is_available()) {
            return $plaintext;
        }

        $key = self::key();
        $iv  = random_bytes(12); // 96-bit nonce, the recommended size for GCM
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            return $plaintext;
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt a stored value. Values written before encryption was available are
     * returned as-is, so upgrades do not need a migration step.
     *
     * @param string $stored
     * @return string
     */
    public static function decrypt($stored) {
        if (!is_string($stored) || $stored === '') {
            return '';
        }
        if (strpos($stored, self::PREFIX) !== 0) {
            return $stored; // legacy plaintext value
        }
        if (!self::is_available()) {
            return '';
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) { // 12 IV + 16 tag + >=1 byte
            return '';
        }

        $iv         = substr($raw, 0, 12);
        $tag        = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? '' : $plaintext;
    }

    /**
     * Cryptographically strong random hex string.
     *
     * @param int $bytes
     * @return string
     */
    public static function random_hex($bytes = 16) {
        return bin2hex(random_bytes(max(1, (int) $bytes)));
    }
}
