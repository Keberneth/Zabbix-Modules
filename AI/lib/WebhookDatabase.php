<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

/** Use the installed frontend's database, TLS and Vault implementation. */
final class WebhookDatabase {

    public static function loadConfig(): array {
        $root = self::frontendRoot();
        $previous_directory = getcwd();

        if (!@chdir($root)) {
            throw new RuntimeException('The Zabbix frontend directory is unavailable.');
        }

        try {
            require_once $root.'/include/classes/core/APP.php';

            // This is the same initialization mode used by api_jsonrpc.php.
            // It loads zabbix.conf.php, resolves Vault DB credentials and uses
            // Zabbix's native DB TLS policy. It neither authenticates a browser
            // session nor executes an API request. Never fall back to PDO with
            // a subset of the frontend's security settings.
            \APP::getInstance()->run(\APP::EXEC_MODE_API);

            $record = Config::getModuleRecord();
            if ($record === null) {
                throw new RuntimeException('The AI module is not registered in Zabbix.');
            }
            if ((int) ($record['status'] ?? 0) !== 1) {
                throw new RuntimeException('The AI module is disabled; the standalone webhook is unavailable.');
            }

            return $record['config'];
        }
        finally {
            if (is_string($previous_directory)) {
                @chdir($previous_directory);
            }
        }
    }

    private static function frontendRoot(): string {
        $override = trim((string) getenv('ZABBIX_AI_FRONTEND_ROOT'));
        // The override belongs to server configuration, never a request or the
        // module's settings. Normal installs need no additional configuration.
        $candidates = $override !== '' ? [$override] : [
            dirname(__DIR__, 3), '/usr/share/zabbix/ui', '/usr/share/zabbix'
        ];

        foreach ($candidates as $candidate) {
            $root = realpath($candidate);
            if ($root !== false && is_file($root.'/include/classes/core/APP.php')) {
                return $root;
            }
        }

        throw new RuntimeException(
            'Cannot locate the Zabbix frontend. Set ZABBIX_AI_FRONTEND_ROOT for a nonstandard module installation.'
        );
    }
}
