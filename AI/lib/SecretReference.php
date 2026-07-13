<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

/**
 * Resolve deployment-managed secret references without giving module settings
 * access to arbitrary files on the frontend host.
 *
 * Supported references:
 *   - NAME / env:NAME: an environment variable (bare NAME is retained for the
 *     module's existing *_env settings)
 *   - file:NAME: a logical file below the server-admin-controlled
 *     ZABBIX_AI_SECRET_DIR
 *
 * file: references deliberately accept a single logical name, not a path. The
 * canonical target must remain below ZABBIX_AI_SECRET_DIR, including after
 * resolving symlinks. This lets secret-volume layouts work while preventing a
 * Zabbix administrator from turning a provider credential field into an
 * arbitrary local-file read primitive.
 */
final class SecretReference {

    private const ENV_SECRET_DIR = 'ZABBIX_AI_SECRET_DIR';
    private const ENV_ALLOWED_ENV_VARS = 'ZABBIX_AI_ALLOWED_SECRET_ENV_VARS';
    private const ENV_ENCRYPTION_KEY_FILE = 'ZABBIX_AI_ENCRYPTION_KEY_FILE';
    private const MAX_SECRET_BYTES = 65536;

    private const DEFAULT_ALLOWED_ENV_VARS = [
        'OPENAI_API_KEY',
        'ANTHROPIC_API_KEY',
        'ZABBIX_API_TOKEN',
        'NETBOX_TOKEN',
        'AI_WEBHOOK_SECRET'
    ];

    private const RESERVED_ENV_VARS = [
        'ZABBIX_AI_ENCRYPTION_KEY',
        'ZABBIX_AI_ENCRYPTION_KEY_FILE',
        'ZABBIX_AI_ALLOW_PLAINTEXT_SECRETS',
        'ZABBIX_AI_SECRET_DIR',
        'ZABBIX_AI_ALLOWED_SECRET_ENV_VARS'
    ];

    public static function isExplicitReference($value): bool {
        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);

        return strncmp($value, 'env:', 4) === 0 || strncmp($value, 'file:', 5) === 0;
    }

    /** Validate and canonicalize a persisted reference without reading it. */
    public static function normalize(string $reference): string {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }

        if (strncmp($reference, 'env:', 4) === 0) {
            $name = trim(substr($reference, 4));
            if (!self::isValidEnvironmentName($name)) {
                throw new RuntimeException('The configured secret environment-variable name is invalid.');
            }
            self::assertAllowedEnvironmentName($name);

            return 'env:'.$name;
        }

        if (strncmp($reference, 'file:', 5) === 0) {
            $name = trim(substr($reference, 5));
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $name)) {
                throw new RuntimeException(
                    'The configured secret file reference is invalid; file: references accept a logical name only.'
                );
            }

            return 'file:'.$name;
        }

        if (self::isValidEnvironmentName($reference)) {
            self::assertAllowedEnvironmentName($reference);

            return 'env:'.$reference;
        }

        throw new RuntimeException(
            'The configured secret reference has an unsupported format; use env:NAME or file:NAME.'
        );
    }

    /** Resolve a configured reference, throwing when its source is unavailable. */
    public static function resolve(string $reference, string $purpose = 'secret'): string {
        $reference = trim($reference);

        if ($reference === '') {
            throw new RuntimeException('The configured '.$purpose.' reference is empty.');
        }

        if (strncmp($reference, 'env:', 4) === 0) {
            return self::readEnvironment(substr($reference, 4), $purpose);
        }

        if (strncmp($reference, 'file:', 5) === 0) {
            return self::readConfinedFile(substr($reference, 5), $purpose);
        }

        // Backward compatibility for the existing *_env fields.
        if (self::isValidEnvironmentName($reference)) {
            return self::readEnvironment($reference, $purpose);
        }

        throw new RuntimeException(
            'The configured '.$purpose.' reference has an unsupported format; use env:NAME or file:NAME.'
        );
    }

    /**
     * Read a file path supplied by the server administrator (never by module
     * settings). Used for the database/pending-payload encryption master key.
     */
    public static function readServerConfiguredFile(string $path, string $purpose = 'secret'): string {
        $path = trim($path);

        if ($path === '' || !self::isAbsolutePath($path)) {
            throw new RuntimeException('The configured '.$purpose.' file must use an absolute path.');
        }

        $canonical = realpath($path);
        if ($canonical === false || !is_file($canonical) || !is_readable($canonical)) {
            throw new RuntimeException('The configured '.$purpose.' file is missing or unreadable.');
        }

        $canonical_parent = realpath(dirname($canonical));
        if ($canonical_parent === false || !is_dir($canonical_parent)) {
            throw new RuntimeException('The configured '.$purpose.' file directory is unavailable.');
        }
        self::assertSafePermissions($canonical_parent, $purpose.' file directory');
        self::assertSafePermissions($canonical, $purpose.' file');

        return self::readValue($canonical, $purpose);
    }

    private static function readEnvironment(string $name, string $purpose): string {
        $name = trim($name);

        if (!self::isValidEnvironmentName($name)) {
            throw new RuntimeException('The configured '.$purpose.' environment-variable name is invalid.');
        }
        self::assertAllowedEnvironmentName($name);

        $value = getenv($name);
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                'The configured '.$purpose.' environment variable "'.$name.'" is missing or empty.'
            );
        }

        return trim($value);
    }

    private static function readConfinedFile(string $name, string $purpose): string {
        $name = trim($name);

        // A flat logical name keeps traversal, alternate separators, drive
        // prefixes and URI-like wrappers out before any filesystem lookup.
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/', $name)) {
            throw new RuntimeException(
                'The configured '.$purpose.' file reference is invalid; file: references accept a logical name only.'
            );
        }

        $root = getenv(self::ENV_SECRET_DIR);
        $root = is_string($root) ? trim($root) : '';
        if ($root === '' || !self::isAbsolutePath($root)) {
            throw new RuntimeException(
                'file: references require an absolute ZABBIX_AI_SECRET_DIR configured by the server administrator.'
            );
        }

        $canonical_root = realpath($root);
        if ($canonical_root === false || !is_dir($canonical_root) || !is_readable($canonical_root)) {
            throw new RuntimeException('ZABBIX_AI_SECRET_DIR is missing or unreadable.');
        }
        self::assertSafePermissions($canonical_root, 'secret directory');

        $candidate = $canonical_root.DIRECTORY_SEPARATOR.$name;
        $canonical_file = realpath($candidate);
        if ($canonical_file === false || !is_file($canonical_file) || !is_readable($canonical_file)) {
            throw new RuntimeException('The configured '.$purpose.' file reference is missing or unreadable.');
        }

        if (!self::isWithinDirectory($canonical_file, $canonical_root)) {
            throw new RuntimeException('The configured '.$purpose.' file reference escapes ZABBIX_AI_SECRET_DIR.');
        }

        // The database/pending-payload master key must never be reachable
        // through an operator-controlled provider/token reference, even if a
        // deployment accidentally places it under the reference directory.
        $master_key_path = getenv(self::ENV_ENCRYPTION_KEY_FILE);
        $master_key_path = is_string($master_key_path) ? trim($master_key_path) : '';
        $canonical_master_key = $master_key_path !== '' ? realpath($master_key_path) : false;
        if (is_string($canonical_master_key) && self::samePath($canonical_file, $canonical_master_key)) {
            throw new RuntimeException('The configured secret file reference points to the reserved encryption master key.');
        }

        self::assertSafePermissions($canonical_file, $purpose.' file');

        return self::readValue($canonical_file, $purpose);
    }

    private static function readValue(string $path, string $purpose): string {
        $value = @file_get_contents($path, false, null, 0, self::MAX_SECRET_BYTES + 1);

        if (!is_string($value)) {
            throw new RuntimeException('The configured '.$purpose.' file could not be read.');
        }
        if (strlen($value) > self::MAX_SECRET_BYTES) {
            throw new RuntimeException('The configured '.$purpose.' file is too large.');
        }

        // Secret files commonly end in one newline. Preserve all other bytes so
        // credentials are not silently changed.
        $value = rtrim($value, "\r\n");
        if (trim($value) === '') {
            throw new RuntimeException('The configured '.$purpose.' file is empty.');
        }

        return $value;
    }

    private static function assertSafePermissions(string $path, string $label): void {
        // POSIX mode bits do not have equivalent semantics on Windows. On Unix,
        // reject group/world-writable roots and files to prevent substitution by
        // less-privileged accounts. Read ACLs remain a deployment responsibility.
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }

        clearstatcache(true, $path);
        $permissions = @fileperms($path);
        if (!is_int($permissions) || ($permissions & 0022) !== 0) {
            throw new RuntimeException('The configured '.$label.' is group/world writable and was refused.');
        }
    }

    private static function isWithinDirectory(string $path, string $directory): bool {
        $path = str_replace('\\', '/', $path);
        $directory = rtrim(str_replace('\\', '/', $directory), '/');
        $prefix = $directory.'/';

        if (DIRECTORY_SEPARATOR === '\\') {
            return strncasecmp($path, $prefix, strlen($prefix)) === 0;
        }

        return strncmp($path, $prefix, strlen($prefix)) === 0;
    }

    private static function samePath(string $left, string $right): bool {
        $left = str_replace('\\', '/', $left);
        $right = str_replace('\\', '/', $right);

        return DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($left, $right) === 0
            : strcmp($left, $right) === 0;
    }

    private static function isAbsolutePath(string $path): bool {
        return preg_match('#\A(?:/|[A-Za-z]:[\\\\/]|[\\\\/]{2})#', $path) === 1;
    }

    private static function isValidEnvironmentName(string $name): bool {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) === 1;
    }

    private static function assertAllowedEnvironmentName(string $name): void {
        if (in_array($name, self::RESERVED_ENV_VARS, true)) {
            throw new RuntimeException(
                'The configured secret environment variable "'.$name.'" is reserved and cannot be referenced from AI Settings.'
            );
        }

        if (in_array($name, self::DEFAULT_ALLOWED_ENV_VARS, true)
                || strncmp($name, 'ZABBIX_AI_SECRET_', 17) === 0) {
            return;
        }

        $configured = getenv(self::ENV_ALLOWED_ENV_VARS);
        $configured = is_string($configured) ? $configured : '';
        $allowed = preg_split('/[\s,]+/', trim($configured), -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($allowed) && in_array($name, $allowed, true)) {
            return;
        }

        throw new RuntimeException(
            'The configured secret environment variable "'.$name.'" is not allowlisted. Use a standard name, '
            .'a ZABBIX_AI_SECRET_* name, or add it to ZABBIX_AI_ALLOWED_SECRET_ENV_VARS in the PHP/web process.'
        );
    }
}
