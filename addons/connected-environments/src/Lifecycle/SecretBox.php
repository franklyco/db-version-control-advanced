<?php

namespace Dbvc\Connected\Lifecycle;

/**
 * Encrypts the hub credential at rest with a key derived from this
 * installation's `AUTH_KEY`/`AUTH_SALT` (wp-config, outside the content
 * database). A database copied to an installation with different salts
 * cannot open the secret, which turns silent clone reuse into an explicit
 * `credentials_unreadable` hold. libsodium is preferred; AES-256-GCM via
 * OpenSSL is the fallback. Without either, enrollment fails closed.
 */
final class SecretBox
{
    private const PREFIX_SODIUM = 'v1:sodium:';
    private const PREFIX_OPENSSL = 'v1:openssl:';

    /**
     * @param string $plaintext
     * @return string|null
     */
    public static function seal($plaintext)
    {
        $key = self::key();
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox((string) $plaintext, $nonce, $key);
            return self::PREFIX_SODIUM . base64_encode($nonce . $cipher);
        }
        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt((string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($cipher === false) {
                return null;
            }
            return self::PREFIX_OPENSSL . base64_encode($iv . $tag . $cipher);
        }

        return null;
    }

    /**
     * @param string $sealed
     * @return string|null
     */
    public static function open($sealed)
    {
        $key = self::key();
        $sealed = (string) $sealed;
        if (strpos($sealed, self::PREFIX_SODIUM) === 0 && function_exists('sodium_crypto_secretbox_open')) {
            $raw = base64_decode(substr($sealed, strlen(self::PREFIX_SODIUM)), true);
            if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
                return null;
            }
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
            return $plain === false ? null : $plain;
        }
        if (strpos($sealed, self::PREFIX_OPENSSL) === 0 && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($sealed, strlen(self::PREFIX_OPENSSL)), true);
            if ($raw === false || strlen($raw) <= 28) {
                return null;
            }
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return $plain === false ? null : $plain;
        }

        return null;
    }

    /**
     * @return bool
     */
    public static function is_available()
    {
        return function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt');
    }

    /**
     * @return string 32-byte key.
     */
    private static function key()
    {
        return hash('sha256', wp_salt('auth') . '|dbvc-connected-environments|hub-credential', true);
    }
}
