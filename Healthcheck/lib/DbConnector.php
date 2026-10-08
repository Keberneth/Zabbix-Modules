<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Lib;

use PDO,
    RuntimeException;

class DbConnector {

    public static function loadConfigArray(): array {
        if (isset($GLOBALS['DB']) && is_array($GLOBALS['DB']) && !empty($GLOBALS['DB']['DATABASE'])) {
            return $GLOBALS['DB'];
        }

        self::defineMissingZabbixConstants();

        $paths = array_values(array_filter([
            getenv('ZABBIX_WEB_CONFIG') ?: null,
            '/etc/zabbix/web/zabbix.conf.php',
            '/etc/zabbix/zabbix.conf.php',
            dirname(__DIR__, 3).'/conf/zabbix.conf.php',
            dirname(__DIR__, 4).'/conf/zabbix.conf.php'
        ]));

        // The same file is often reachable through several candidates (the Docker image symlinks
        // ui/conf/zabbix.conf.php to /etc/zabbix/web). Requiring it twice is a fatal "Cannot redeclare"
        // when the file defines helper functions, so load every real file at most once.
        $seen = [];
        $fallback = null;

        foreach ($paths as $path) {
            $real = is_file($path) ? realpath($path) : false;
            if ($real === false || isset($seen[$real])) {
                continue;
            }
            $seen[$real] = true;

            $DB = null;

            /** @noinspection PhpIncludeInspection */
            require $real;

            if (is_array($DB) && !empty($DB['DATABASE'])) {
                return $DB;
            }

            if (is_array($DB) && $fallback === null) {
                $fallback = $DB;
            }
        }

        // Official Zabbix Docker images build zabbix.conf.php from DB_SERVER_* variables that the
        // entrypoint exports to php-fpm only. A CLI run through "docker exec" sees the container's
        // original MYSQL_* / POSTGRES_* variables instead, so derive the same values from those.
        $docker = self::dockerEnvConfig();
        if ($docker !== null) {
            // Keep anything the file did resolve (TLS options, vault settings); fill the rest.
            $resolved = array_filter($fallback ?? [], static function($value): bool {
                return $value !== '' && $value !== null;
            });

            return array_merge($docker, $resolved);
        }

        throw new RuntimeException('Cannot locate zabbix.conf.php or the DB configuration is empty.');
    }

    /**
     * DB settings the way the official zabbix-web Docker entrypoint derives them, or null when the
     * environment does not look like one of those containers.
     */
    private static function dockerEnvConfig(): ?array {
        $type = strtoupper(self::env('DB_SERVER_TYPE'));
        if ($type === '') {
            if (self::env('POSTGRES_USER') !== '' || self::env('POSTGRES_DB') !== '') {
                $type = 'POSTGRESQL';
            }
            elseif (self::env('MYSQL_USER') !== '' || self::env('MYSQL_DATABASE') !== '') {
                $type = 'MYSQL';
            }
            else {
                return null;
            }
        }

        $pgsql = ($type === 'POSTGRESQL');

        $config = [
            'TYPE' => $type,
            'SERVER' => self::env('DB_SERVER_HOST', $pgsql ? 'postgres-server' : 'mysql-server'),
            'PORT' => self::env('DB_SERVER_PORT', $pgsql ? '5432' : '3306'),
            'DATABASE' => self::env('DB_SERVER_DBNAME', self::env($pgsql ? 'POSTGRES_DB' : 'MYSQL_DATABASE', 'zabbix')),
            'USER' => self::env('DB_SERVER_USER', self::env($pgsql ? 'POSTGRES_USER' : 'MYSQL_USER', 'zabbix')),
            'PASSWORD' => self::env('DB_SERVER_PASS', self::env($pgsql ? 'POSTGRES_PASSWORD' : 'MYSQL_PASSWORD', 'zabbix')),
            'SCHEMA' => self::env('DB_SERVER_SCHEMA', $pgsql ? 'public' : '')
        ];

        return $config;
    }

    /**
     * Environment variable with Docker-secret support: NAME, else the file named by NAME_FILE.
     */
    private static function env(string $name, string $default = ''): string {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return $value;
        }

        $file = getenv($name.'_FILE');
        if ($file !== false && $file !== '' && is_readable($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                return rtrim($content, "\r\n");
            }
        }

        return $default;
    }

    public static function connect(?array $db_config = null): PDO {
        $db_config = $db_config ?? self::loadConfigArray();

        $type = strtoupper((string) ($db_config['TYPE'] ?? 'MYSQL'));
        $host = (string) ($db_config['SERVER'] ?? 'localhost');
        $port = (int) ($db_config['PORT'] ?? 0);
        $dbname = (string) ($db_config['DATABASE'] ?? '');
        $user = (string) ($db_config['USER'] ?? '');
        $password = (string) ($db_config['PASSWORD'] ?? '');
        $schema = (string) ($db_config['SCHEMA'] ?? '');

        if ($dbname === '') {
            throw new RuntimeException('The Zabbix DB configuration does not contain a database name.');
        }

        if ($type === 'POSTGRESQL') {
            $dsn = 'pgsql:host='.$host.';dbname='.$dbname;
            if ($port > 0) {
                $dsn .= ';port='.$port;
            }
        }
        else {
            $dsn = 'mysql:host='.$host.';dbname='.$dbname.';charset=utf8mb4';
            if ($port > 0) {
                $dsn .= ';port='.$port;
            }
        }

        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5
        ]);

        if ($type === 'POSTGRESQL' && $schema !== '' && preg_match('/^[A-Za-z0-9_]+$/', $schema)) {
            $pdo->exec('SET search_path TO "'.$schema.'"');
        }

        return $pdo;
    }

    private static function defineMissingZabbixConstants(): void {
        $constants = [
            'IMAGE_FORMAT_PNG' => 0,
            'IMAGE_FORMAT_JPEG' => 1,
            'IMAGE_FORMAT_TEXT' => 2,
            'IMAGE_FORMAT_GIF' => 3
        ];

        foreach ($constants as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }
}
