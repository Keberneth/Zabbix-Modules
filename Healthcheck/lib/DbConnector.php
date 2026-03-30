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

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $DB = null;

            /** @noinspection PhpIncludeInspection */
            require $path;

            if (is_array($DB) && !empty($DB['DATABASE'])) {
                return $DB;
            }
        }

        throw new RuntimeException('Cannot locate zabbix.conf.php or the DB configuration is empty.');
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
