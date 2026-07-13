<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

/**
 * Application-layer encryption-at-rest for the secrets the module stores in the
 * Zabbix `module` config (provider API keys, Zabbix/NetBox tokens, the webhook
 * shared secret).
 *
 * Design goals:
 *   - Required for inline secrets. New inline secrets and pending confirmed
 *     action payloads (writes and sensitive reads) fail closed unless
 *     ZABBIX_AI_ENCRYPTION_KEY (or ZABBIX_AI_ENCRYPTION_KEY_FILE) and a crypto backend
 *     are available. Environment-variable secret references need no storage.
 *   - Backward compatible. Values are tagged with an "enc:v1:" prefix; any value
 *     without it is treated as plain text and returned as-is, so previously
 *     stored plaintext secrets can be migrated once the encryption key is
 *     configured and settings are saved. Without a key they are refused unless
 *     the explicit development-only plaintext escape hatch is enabled.
 *   - Fail-safe. If a value is encrypted but the key is missing/wrong (or the
 *     crypto extension is unavailable), decryption returns '' rather than
 *     throwing — the affected feature reports "secret unavailable" instead of
 *     taking down the whole UI.
 *
 * Key handling: the key material may be provided directly in
 * ZABBIX_AI_ENCRYPTION_KEY or loaded from the server-admin-controlled absolute
 * path in ZABBIX_AI_ENCRYPTION_KEY_FILE. A fixed 32-byte key is derived from it
 * with SHA-256. The same material must be available on every frontend node. It
 * never touches the database, so a DB dump / backup / configuration export no
 * longer leaks the secrets.
 */
class Crypto {

    private const PREFIX = 'enc:v1:';
    private const ENV_KEY = 'ZABBIX_AI_ENCRYPTION_KEY';
    private const ENV_KEY_FILE = 'ZABBIX_AI_ENCRYPTION_KEY_FILE';
    private const ENV_ALLOW_PLAINTEXT = 'ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS';

    private const BACKEND_OPENSSL = "\x01";
    private const BACKEND_SODIUM = "\x02";

    private static bool $key_cache_initialized = false;
    private static ?string $resolved_key_cache = null;
    private static string $key_source_cache = 'none';

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
    public static function status(bool $configured_plaintext_allowed = false): array {
        $backend = self::backend();
        $environment_plaintext_allowed = self::allowsPlaintextSecrets();

        return [
            'available' => self::isAvailable(),
            'has_key' => self::resolveKey() !== null,
            'key_source' => self::keySource(),
            'backend' => $backend !== '' ? $backend : 'none',
            'configured_plaintext_allowed' => $configured_plaintext_allowed,
            'environment_plaintext_allowed' => $environment_plaintext_allowed,
            'plaintext_allowed' => $configured_plaintext_allowed || $environment_plaintext_allowed
        ];
    }

    /** Explicit development-only escape hatch for legacy plaintext configs. */
    public static function allowsPlaintextSecrets(): bool {
        $value = strtolower(trim((string) getenv(self::ENV_ALLOW_PLAINTEXT)));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Reset the request-local key snapshot. Normal web requests never need to
     * call this; it exists for CLI workers/tests which deliberately change
     * environment/key files without starting a new PHP request.
     */
    public static function resetRuntimeKeyCache(): void {
        self::$key_cache_initialized = false;
        self::$resolved_key_cache = null;
        self::$key_source_cache = 'none';
    }

    /**
     * Best-effort encryption primitive. Security-sensitive persistence callers
     * must use encryptRequired(), which verifies ciphertext and fails closed.
     */
    public static function encrypt(string $value): string {
        if ($value === '' || self::isEncrypted($value) || SecretReference::isExplicitReference($value)) {
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
     * Encrypt a non-empty value and fail closed if encryption cannot be
     * guaranteed. Use this for pending write payloads and newly persisted
     * inline secrets; those must never fall back to plaintext.
     */
    public static function encryptRequired(string $value, string $purpose = 'sensitive data'): string {
        if ($value === '') {
            return '';
        }
        if (SecretReference::isExplicitReference($value)) {
            return $value;
        }
        if (self::isEncrypted($value)) {
            return $value;
        }
        if (!self::isAvailable()) {
            throw new \RuntimeException(
                'Cannot store '.$purpose.' securely. Configure ZABBIX_AI_ENCRYPTION_KEY or '
                .'ZABBIX_AI_ENCRYPTION_KEY_FILE '
                .'for the PHP/web process and ensure OpenSSL or Sodium is available.'
            );
        }

        $encrypted = self::encrypt($value);
        if (!self::isEncrypted($encrypted)) {
            throw new \RuntimeException('Encryption failed while storing '.$purpose.'; nothing was saved.');
        }

        return $encrypted;
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

    /** Decrypt a value which is required to have been encrypted at rest. */
    public static function decryptRequired(string $value, string $purpose = 'sensitive data'): string {
        if (!self::isEncrypted($value)) {
            throw new \RuntimeException('Refusing plaintext '.$purpose.' from protected storage.');
        }

        $plain = self::decrypt($value);
        if ($plain === '') {
            throw new \RuntimeException(
                'Could not decrypt '.$purpose.'. Check the Zabbix AI encryption key on every frontend node.'
            );
        }

        return $plain;
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

    /** Opaque equality fingerprint which cannot be brute-forced without the deployment key. */
    public static function keyedFingerprint(string $value, string $purpose = 'sensitive binding'): string {
        $key = self::resolveKey();
        if ($key === null) {
            throw new \RuntimeException(
                'Cannot bind '.$purpose.' without ZABBIX_AI_ENCRYPTION_KEY or ZABBIX_AI_ENCRYPTION_KEY_FILE.'
            );
        }

        return hash_hmac('sha256', $value, $key);
    }

    /**
     * Derive the 32-byte key from direct environment material, or from the
     * server-configured key file when the direct value is absent. Direct
     * ZABBIX_AI_ENCRYPTION_KEY deliberately keeps precedence for backward
     * compatibility during staged migrations.
     */
    private static function resolveKey(): ?string {
        if (self::$key_cache_initialized) {
            return self::$resolved_key_cache;
        }
        self::$key_cache_initialized = true;

        $material = getenv(self::ENV_KEY);

        if (is_string($material) && trim($material) !== '') {
            self::$key_source_cache = 'environment';
            self::$resolved_key_cache = hash('sha256', trim($material), true);

            return self::$resolved_key_cache;
        }

        $key_file = getenv(self::ENV_KEY_FILE);
        if (!is_string($key_file) || trim($key_file) === '') {
            return null;
        }

        try {
            $material = SecretReference::readServerConfiguredFile(
                trim($key_file),
                'Zabbix AI encryption key'
            );
        }
        catch (\Throwable $e) {
            // Preserve the existing fail-safe contract: unavailable/corrupt key
            // material makes encryption unavailable and decryption return empty.
            return null;
        }

        self::$key_source_cache = 'file';
        self::$resolved_key_cache = hash('sha256', trim($material), true);

        return self::$resolved_key_cache;
    }

    /** Non-secret source label for the settings status banner. */
    private static function keySource(): string {
        self::resolveKey();

        return self::$key_source_cache;
    }
}
