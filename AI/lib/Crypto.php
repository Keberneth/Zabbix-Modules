<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

/**
 * Application-layer encryption-at-rest for the secrets the module stores in the
 * Zabbix `module` config (provider API keys, Zabbix/NetBox tokens, the webhook
 * shared secret).
 *
 * Design goals — chosen specifically so this can never "brick" the module:
 *   - Opt-in. Encryption only activates when an encryption key is provided via
 *     the ZABBIX_AI_ENCRYPTION_KEY environment variable. With no key set the
 *     module behaves exactly as before (secrets stored verbatim), so existing
 *     installs are unaffected and nothing can break by simply upgrading.
 *   - Backward compatible. Values are tagged with an "enc:v1:" prefix; any value
 *     without it is treated as plain text and returned as-is, so previously
 *     stored plaintext secrets keep working and are upgraded to ciphertext the
 *     next time settings are saved.
 *   - Fail-safe. If a value is encrypted but the key is missing/wrong (or the
 *     crypto extension is unavailable), decryption returns '' rather than
 *     throwing — the affected feature reports "secret unavailable" instead of
 *     taking down the whole UI.
 *
 * Key handling: the env var value is any passphrase/secret string; a fixed
 * 32-byte key is derived from it with SHA-256. Using an environment variable
 * (rather than a file) is what makes this safe across the module's supported
 * topologies — a single server sets it once, a multi-server install sets the
 * SAME value on each frontend node, and Docker/Compose passes it as an
 * environment variable to every frontend container. The key never touches the
 * database, so a DB dump / backup / configuration export no longer leaks the
 * secrets.
 */
class Crypto {

    private const PREFIX = 'enc:v1:';
    private const ENV_KEY = 'ZABBIX_AI_ENCRYPTION_KEY';

    private const BACKEND_OPENSSL = "\x01";
    private const BACKEND_SODIUM = "\x02";

    public static function isEncrypted($value): bool {
        return is_string($value) && strncmp($value, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /**
     * True when a key is configured AND a crypto backend is available, i.e. when
     * new secrets will actually be encrypted at rest.
     */
    public static function isAvailable(): bool {
        return self::resolveKey() !== null && self::backend() !== '';
    }

    /**
     * Status for the settings UI: whether encryption-at-rest is active and which
     * primitive is in use.
     */
    public static function status(): array {
        $backend = self::backend();

        return [
            'available' => self::isAvailable(),
            'has_key' => self::resolveKey() !== null,
            'backend' => $backend !== '' ? $backend : 'none'
        ];
    }

    /**
     * Encrypt a secret for storage. No-ops (returns the input unchanged) when the
     * value is empty, already encrypted, an "env:" indirection reference, or when
     * no key/backend is available — so callers can apply it unconditionally.
     */
    public static function encrypt(string $value): string {
        if ($value === '' || self::isEncrypted($value) || strncmp($value, 'env:', 4) === 0) {
            return $value;
        }

        $key = self::resolveKey();
        $backend = self::backend();

        if ($key === null || $backend === '') {
            return $value;
        }

        try {
            if ($backend === 'sodium') {
                $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
                $cipher = sodium_crypto_secretbox($value, $nonce, $key);
                $blob = self::BACKEND_SODIUM.$nonce.$cipher;
            }
            else {
                $iv = random_bytes(12);
                $tag = '';
                $cipher = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

                if ($cipher === false) {
                    return $value;
                }

                $blob = self::BACKEND_OPENSSL.$iv.$tag.$cipher;
            }

            return self::PREFIX.base64_encode($blob);
        }
        catch (\Throwable $e) {
            // Never let an encryption hiccup lose the secret — fall back to
            // storing it as-is (the UI still warns it is unencrypted).
            return $value;
        }
    }

    /**
     * Decrypt a stored secret. Plain (untagged) values are returned unchanged.
     * On any failure (missing/wrong key, unavailable extension, corrupt blob)
     * this returns '' so the caller fails safe instead of crashing.
     */
    public static function decrypt(string $value): string {
        if (!self::isEncrypted($value)) {
            return $value;
        }

        $blob = base64_decode(substr($value, strlen(self::PREFIX)), true);

        if ($blob === false || strlen($blob) < 2) {
            return '';
        }

        $key = self::resolveKey();

        if ($key === null) {
            return '';
        }

        $marker = $blob[0];
        $payload = substr($blob, 1);

        try {
            if ($marker === self::BACKEND_SODIUM && function_exists('sodium_crypto_secretbox_open')) {
                $nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

                if (strlen($payload) <= $nonce_len) {
                    return '';
                }

                $nonce = substr($payload, 0, $nonce_len);
                $cipher = substr($payload, $nonce_len);
                $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);

                return is_string($plain) ? $plain : '';
            }

            if ($marker === self::BACKEND_OPENSSL && function_exists('openssl_decrypt')) {
                if (strlen($payload) <= 28) {
                    return '';
                }

                $iv = substr($payload, 0, 12);
                $tag = substr($payload, 12, 16);
                $cipher = substr($payload, 28);
                $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

                return is_string($plain) ? $plain : '';
            }
        }
        catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    private static function backend(): string {
        if (function_exists('sodium_crypto_secretbox')) {
            return 'sodium';
        }

        if (function_exists('openssl_encrypt')) {
            return 'openssl';
        }

        return '';
    }

    /**
     * Derive the 32-byte key from the ZABBIX_AI_ENCRYPTION_KEY environment
     * variable, or null when it is not set. Any passphrase is accepted and
     * hashed to a fixed-length key.
     */
    private static function resolveKey(): ?string {
        $material = getenv(self::ENV_KEY);

        if (!is_string($material) || trim($material) === '') {
            return null;
        }

        return hash('sha256', trim($material), true);
    }
}
