<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use ErrorException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Creates the small, short-lived database connection used by the standalone
 * runner to read this module's settings from the Zabbix frontend database.
 */
final class DbConnector {

    public const CONFIG_PATH_ENV = 'ZABBIX_WEB_CONFIG';
    public const LEGACY_CONFIG_PATH_ENV = 'ZABBIX_FRONTEND_CONFIG';
    public const DB_USER_ENV = 'NETBOXSYNC_ZABBIX_DB_USER';
    public const DB_PASSWORD_ENV = 'NETBOXSYNC_ZABBIX_DB_PASSWORD';

    public static function connect(?string $config_path = null): PDO {
        $path = self::discoverConfigPath($config_path);

        return self::connectFromConfig(self::readConfig($path));
    }

    /**
     * Locate zabbix.conf.php. An explicit argument or environment override is
     * authoritative: a bad override fails instead of silently using another DB.
     */
    public static function discoverConfigPath(?string $config_path = null): string {
        $config_path = trim((string) $config_path);

        if ($config_path !== '') {
            return self::validateConfigPath($config_path);
        }

        foreach ([self::CONFIG_PATH_ENV, self::LEGACY_CONFIG_PATH_ENV] as $environment_name) {
            $environment_path = getenv($environment_name);
            if ($environment_path !== false && trim((string) $environment_path) !== '') {
                return self::validateConfigPath(trim((string) $environment_path));
            }
        }

        // When installed under <frontend>/modules/<module>, this resolves to
        // the frontend root. The remaining entries cover common distro layouts.
        $frontend_root = dirname(__DIR__, 3);
        $candidates = [
            $frontend_root.'/conf/zabbix.conf.php',
            $frontend_root.'/ui/conf/zabbix.conf.php',
            '/etc/zabbix/web/zabbix.conf.php',
            '/etc/zabbix/zabbix.conf.php',
            '/usr/share/zabbix/conf/zabbix.conf.php',
            '/usr/share/zabbix/ui/conf/zabbix.conf.php'
        ];

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                $resolved = realpath($candidate);
                return $resolved !== false ? $resolved : $candidate;
            }
        }

        throw new RuntimeException(
            'Unable to find the Zabbix frontend configuration. Set '.self::CONFIG_PATH_ENV
            .' (legacy: '.self::LEGACY_CONFIG_PATH_ENV.') or pass '
            .'--frontend-config=/path/to/zabbix.conf.php.'
        );
    }

    /**
     * Load only the $DB array from the trusted Zabbix frontend configuration.
     * Output is discarded so an accidental echo cannot corrupt CLI JSON output.
     */
    public static function readConfig(string $config_path): array {
        $config_path = self::validateConfigPath($config_path);

        $loader = static function (string $path): array {
            $DB = [];
            $buffer_level = ob_get_level();

            set_error_handler(static function (
                int $severity,
                string $message,
                string $file = '',
                int $line = 0
            ): bool {
                if ((error_reporting() & $severity) === 0) {
                    return false;
                }

                throw new ErrorException($message, 0, $severity, $file, $line);
            });

            ob_start();

            try {
                include $path;
            }
            finally {
                while (ob_get_level() > $buffer_level) {
                    ob_end_clean();
                }
                restore_error_handler();
            }

            return is_array($DB) ? $DB : [];
        };

        try {
            $database = $loader($config_path);
        }
        catch (Throwable $e) {
            throw new RuntimeException(
                'Unable to load Zabbix frontend configuration '.$config_path.': '.$e->getMessage(),
                0,
                $e
            );
        }

        if ($database === []) {
            throw new RuntimeException(
                'Zabbix frontend configuration '.$config_path.' does not define a usable $DB array.'
            );
        }

        return $database;
    }

    /**
     * Public separately from connect() to keep DSN construction testable and to
     * allow callers which already loaded the frontend configuration to reuse it.
     */
    public static function connectFromConfig(array $database): PDO {
        $type = strtoupper(trim((string) ($database['TYPE'] ?? '')));
        $driver = $type === 'MYSQL' ? 'mysql' : (
            in_array($type, ['POSTGRESQL', 'POSTGRES', 'PGSQL'], true) ? 'pgsql' : ''
        );

        if ($driver === '') {
            throw new RuntimeException(
                'Unsupported Zabbix database type "'.($type !== '' ? $type : 'unknown')
                .'". The standalone runner supports MySQL/MariaDB and PostgreSQL.'
            );
        }

        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException(
                'PDO driver "'.$driver.'" is not installed for the PHP CLI runtime.'
            );
        }

        $server = trim((string) ($database['SERVER'] ?? ''));
        $server = $server !== '' ? $server : 'localhost';
        $name = trim((string) ($database['DATABASE'] ?? ''));
        $user_override = getenv(self::DB_USER_ENV);
        $password_override = getenv(self::DB_PASSWORD_ENV);
        $user = $user_override !== false
            ? (string) $user_override
            : (string) ($database['USER'] ?? '');
        $password = $password_override !== false
            ? (string) $password_override
            : (string) ($database['PASSWORD'] ?? '');
        $port = self::normalizePort($database['PORT'] ?? '');

        if ($name === '') {
            throw new RuntimeException('The Zabbix frontend database name is empty.');
        }

        if (trim((string) ($database['VAULT'] ?? '')) !== '' && ($user === '' || $password === '')) {
            throw new RuntimeException(
                'The Zabbix frontend uses Vault-backed database credentials, which the standalone '
                .'runner cannot resolve directly. Set '.self::DB_USER_ENV.' and '
                .self::DB_PASSWORD_ENV.' in the service environment.'
            );
        }

        self::assertDsnValue($server, 'database server');
        self::assertDsnValue($name, 'database name');

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false
        ];

        if ($driver === 'mysql') {
            if ($server !== '' && $server[0] === '/') {
                $dsn = 'mysql:unix_socket='.$server.';dbname='.$name.';charset=utf8mb4';
            }
            else {
                $dsn = 'mysql:host='.$server;
                if ($port !== null) {
                    $dsn .= ';port='.$port;
                }
                $dsn .= ';dbname='.$name.';charset=utf8mb4';
            }

            self::addMySqlTlsOptions($options, $database);
        }
        else {
            $dsn = 'pgsql:host='.$server;
            if ($port !== null) {
                $dsn .= ';port='.$port;
            }
            $dsn .= ';dbname='.$name;
            $dsn .= self::buildPostgreSqlTlsDsn($database);
        }

        try {
            $connection = new PDO($dsn, $user, $password, $options);

            if ($driver === 'pgsql') {
                $schema = trim((string) ($database['SCHEMA'] ?? ''));
                if ($schema !== '') {
                    if (strpos($schema, "\0") !== false) {
                        throw new RuntimeException('The PostgreSQL schema contains an invalid null byte.');
                    }

                    $connection->exec('SET search_path TO "'.str_replace('"', '""', $schema).'"');
                }
            }

            return $connection;
        }
        catch (PDOException $e) {
            throw new RuntimeException(
                'Unable to connect to the Zabbix '.$driver.' database: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    private static function validateConfigPath(string $path): string {
        if (strpos($path, "\0") !== false) {
            throw new RuntimeException('The Zabbix frontend configuration path contains a null byte.');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Zabbix frontend configuration is not readable: '.$path);
        }

        $resolved = realpath($path);

        return $resolved !== false ? $resolved : $path;
    }

    private static function normalizePort($value): ?int {
        $value = trim((string) $value);

        if ($value === '' || $value === '0') {
            return null;
        }

        if (!ctype_digit($value)) {
            throw new RuntimeException('The Zabbix frontend database port is invalid.');
        }

        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException('The Zabbix frontend database port is outside the valid range.');
        }

        return $port;
    }

    private static function assertDsnValue(string $value, string $label): void {
        if (strpos($value, ';') !== false || strpos($value, "\0") !== false) {
            throw new RuntimeException('The Zabbix '.$label.' contains an unsupported DSN delimiter.');
        }
    }

    private static function addMySqlTlsOptions(array &$options, array $database): void {
        if (!self::truthy($database['ENCRYPTION'] ?? false)) {
            return;
        }

        $mapping = [
            'CA_FILE' => 'PDO::MYSQL_ATTR_SSL_CA',
            'KEY_FILE' => 'PDO::MYSQL_ATTR_SSL_KEY',
            'CERT_FILE' => 'PDO::MYSQL_ATTR_SSL_CERT',
            'CIPHER_LIST' => 'PDO::MYSQL_ATTR_SSL_CIPHER'
        ];

        foreach ($mapping as $config_key => $constant_name) {
            $value = trim((string) ($database[$config_key] ?? ''));
            if ($value !== '' && defined($constant_name)) {
                $options[constant($constant_name)] = $value;
            }
        }

        $verify_constant = 'PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT';
        if (defined($verify_constant)) {
            $options[constant($verify_constant)] = self::truthy($database['VERIFY_HOST'] ?? false);
        }
    }

    private static function buildPostgreSqlTlsDsn(array $database): string {
        if (!self::truthy($database['ENCRYPTION'] ?? false)) {
            return '';
        }

        $ca_file = trim((string) ($database['CA_FILE'] ?? ''));
        $verify_host = self::truthy($database['VERIFY_HOST'] ?? false);
        $suffix = ';sslmode='.($verify_host ? 'verify-full' : ($ca_file !== '' ? 'verify-ca' : 'require'));

        $mapping = [
            'CA_FILE' => 'sslrootcert',
            'CERT_FILE' => 'sslcert',
            'KEY_FILE' => 'sslkey'
        ];

        foreach ($mapping as $config_key => $dsn_key) {
            $value = trim((string) ($database[$config_key] ?? ''));
            if ($value === '') {
                continue;
            }

            self::assertDsnValue($value, 'TLS file path');
            $suffix .= ';'.$dsn_key.'='.$value;
        }

        return $suffix;
    }

    private static function truthy($value): bool {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
