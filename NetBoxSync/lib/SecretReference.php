<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use InvalidArgumentException;

/** Deployment-managed credentials and direct HashiCorp Vault KV v2 references. */
final class SecretReference {

    private const MAX_SECRET_BYTES = 65536;
    private const DEFAULT_ENV_NAMES = [
        'NETBOX_API_TOKEN', 'NETBOX_TOKEN', 'ZABBIX_API_TOKEN',
        'NETBOXSYNC_ZABBIX_API_TOKEN', 'NETBOXSYNC_SHARED_SECRET'
    ];
    private const RESERVED_ENV_NAMES = [
        'NETBOXSYNC_SECRET_DIR', 'NETBOXSYNC_ALLOWED_SECRET_ENV_VARS',
        'NETBOXSYNC_ZABBIX_DB_USER', 'NETBOXSYNC_ZABBIX_DB_PASSWORD',
        'NETBOXSYNC_VAULT_ADDR', 'NETBOXSYNC_VAULT_TOKEN', 'NETBOXSYNC_VAULT_TOKEN_FILE',
        'NETBOXSYNC_VAULT_NAMESPACE', 'NETBOXSYNC_VAULT_CACERT'
    ];

    public static function isExplicitReference(string $value): bool {
        $value = trim($value);
        return strncmp($value, 'env:', 4) === 0 || strncmp($value, 'file:', 5) === 0
            || strncmp($value, 'vault:', 6) === 0;
    }

    /** Validate without reading credential material. */
    public static function normalize(string $reference): string {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }
        self::rejectMacro($reference);

        if (strncmp($reference, 'vault:', 6) === 0) {
            // Deliberately exclude percent-encoding, URLs, queries and traversal.
            // Mounts and secret paths may contain multiple safe path segments.
            if (strlen($reference) > 2048 || !preg_match(
                '#\Avault:[A-Za-z0-9][A-Za-z0-9_.-]*(?:/[A-Za-z0-9][A-Za-z0-9_.-]*)*/data/[A-Za-z0-9][A-Za-z0-9_.-]*(?:/[A-Za-z0-9][A-Za-z0-9_.-]*)*\#[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z#',
                $reference
            )) {
                throw new InvalidArgumentException('Use vault:MOUNT/data/PATH#KEY for a HashiCorp Vault KV v2 secret.');
            }
            return $reference;
        }

        if (strncmp($reference, 'file:', 5) === 0) {
            $name = substr($reference, 5);
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $name)) {
                throw new InvalidArgumentException('Secret file references must use file:NAME with a single logical filename, not a path.');
            }
            return 'file:'.$name;
        }

        $name = strncmp($reference, 'env:', 4) === 0 ? substr($reference, 4) : $reference;
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/', $name)) {
            throw new InvalidArgumentException('Use env:NAME, file:NAME or vault:MOUNT/data/PATH#KEY for a secret reference.');
        }
        self::assertAllowedEnvironment($name);
        return 'env:'.$name;
    }

    public static function resolve(string $reference): string {
        $reference = self::normalize($reference);
        if ($reference === '') {
            throw new InvalidArgumentException('The configured secret reference is empty.');
        }

        if (strncmp($reference, 'vault:', 6) === 0) {
            return VaultClient::read($reference);
        }

        if (strncmp($reference, 'env:', 4) === 0) {
            $name = substr($reference, 4);
            $value = getenv($name);
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('The configured secret environment variable "'.$name.'" is unavailable or empty in this PHP process.');
            }
            return self::validateValue($value);
        }

        $directory = getenv('NETBOXSYNC_SECRET_DIR');
        if (!is_string($directory) || !preg_match('#\A(?:/|[A-Za-z]:[\\\\/]|[\\\\/]{2})#', $directory)) {
            throw new InvalidArgumentException('file: references require an absolute NETBOXSYNC_SECRET_DIR set by the server administrator.');
        }
        $root = realpath($directory);
        if ($root === false || !is_dir($root) || !is_readable($root)) {
            throw new InvalidArgumentException('NETBOXSYNC_SECRET_DIR is unavailable or unreadable.');
        }
        self::assertSafePermissions($root);

        $path = realpath($root.DIRECTORY_SEPARATOR.substr($reference, 5));
        if ($path === false || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The configured secret file is missing or unreadable.');
        }
        $prefix = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalized_path = str_replace('\\', '/', $path);
        $contained = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($normalized_path, $prefix, strlen($prefix)) === 0
            : strncmp($normalized_path, $prefix, strlen($prefix)) === 0;
        if (!$contained) {
            throw new InvalidArgumentException('The configured secret file escapes NETBOXSYNC_SECRET_DIR.');
        }
        self::rejectVaultTokenFile($path);
        self::assertSafePermissions($path);
        $value = @file_get_contents($path, false, null, 0, self::MAX_SECRET_BYTES + 1);
        if (!is_string($value)) {
            throw new InvalidArgumentException('The configured secret file could not be read.');
        }
        return self::validateValue($value);
    }

    public static function validateValue(string $value): string {
        if (strlen($value) > self::MAX_SECRET_BYTES) {
            throw new InvalidArgumentException('The configured credential exceeds the size limit.');
        }
        // Files produced by secret managers commonly end with a newline.
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('The configured credential is empty.');
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('The configured credential contains invalid control characters.');
        }
        self::rejectMacro($value);
        return $value;
    }

    public static function rejectMacro(string $value): void {
        if (strpos($value, '{$') !== false) {
            throw new InvalidArgumentException('Zabbix user macros are not expanded by NetBox Sync. Use vault:MOUNT/data/PATH#KEY, env:NAME or file:NAME.');
        }
    }

    private static function rejectVaultTokenFile(string $path): void {
        $configured = trim((string) getenv('NETBOXSYNC_VAULT_TOKEN_FILE'));
        $reserved = $configured !== '' ? realpath($configured) : false;
        if ($reserved === false) {
            return;
        }
        $same_path = DIRECTORY_SEPARATOR === '\\' ? strcasecmp($path, $reserved) === 0 : $path === $reserved;
        // Also refuse a hard link to the bootstrap credential on POSIX filesystems.
        $file_stat = @stat($path);
        $reserved_stat = @stat($reserved);
        $same_inode = DIRECTORY_SEPARATOR === '/' && is_array($file_stat) && is_array($reserved_stat)
            && $file_stat['ino'] > 0 && $file_stat['dev'] === $reserved_stat['dev']
            && $file_stat['ino'] === $reserved_stat['ino'];
        if ($same_path || $same_inode) {
            throw new InvalidArgumentException('The Vault authentication token file cannot be used as an integration credential.');
        }
    }

    private static function assertAllowedEnvironment(string $name): void {
        if (in_array($name, self::RESERVED_ENV_NAMES, true)) {
            throw new InvalidArgumentException('This environment variable is reserved and cannot be used as a credential reference.');
        }
        if (in_array($name, self::DEFAULT_ENV_NAMES, true) || strncmp($name, 'NETBOXSYNC_SECRET_', 18) === 0) {
            return;
        }
        $configured = getenv('NETBOXSYNC_ALLOWED_SECRET_ENV_VARS');
        $allowed = preg_split('/[\s,]+/', is_string($configured) ? trim($configured) : '', -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($allowed) && in_array($name, $allowed, true)) {
            return;
        }
        throw new InvalidArgumentException('The secret environment variable is not allowed. Use a documented name, NETBOXSYNC_SECRET_* name, or ask the server administrator to add it to NETBOXSYNC_ALLOWED_SECRET_ENV_VARS.');
    }

    private static function assertSafePermissions(string $path): void {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        clearstatcache(true, $path);
        $permissions = @fileperms($path);
        if (!is_int($permissions) || ($permissions & 0022) !== 0) {
            throw new InvalidArgumentException('The configured secret directory or file is group/world writable and was refused.');
        }
    }
}
