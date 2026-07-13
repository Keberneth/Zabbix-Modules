<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use JsonException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Reads the module settings without bootstrapping the Zabbix web frontend.
 */
final class StandaloneConfig {

    public const MODULE_ID = 'custom_netbox_sync';
    private const MAX_CONFIG_BYTES = 4194304;

    public static function load(PDO $database): array {
        try {
            $statement = $database->prepare(
                'SELECT config, status FROM module WHERE id = :module_id LIMIT 1'
            );
            $statement->execute([':module_id' => self::MODULE_ID]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
        }
        catch (PDOException $e) {
            throw new RuntimeException(
                'Unable to read NetBox Sync settings from the Zabbix module table: '.$e->getMessage(),
                0,
                $e
            );
        }

        if (!is_array($row)) {
            throw new RuntimeException(
                'NetBox Sync module "'.self::MODULE_ID.'" is not registered in the Zabbix module table.'
            );
        }

        if ((int) ($row['status'] ?? 0) !== 1) {
            throw new RuntimeException(
                'NetBox Sync is disabled in Zabbix; unattended synchronization will not run.'
            );
        }

        $raw = $row['config'] ?? '';
        if ($raw === null || trim((string) $raw) === '') {
            return Config::defaults();
        }

        if (!is_string($raw)) {
            throw new RuntimeException('NetBox Sync module configuration has an unsupported database value type.');
        }

        if (strlen($raw) > self::MAX_CONFIG_BYTES) {
            throw new RuntimeException('NetBox Sync module configuration exceeds the safe size limit.');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        }
        catch (JsonException $e) {
            throw new RuntimeException(
                'NetBox Sync module configuration contains invalid JSON: '.$e->getMessage(),
                0,
                $e
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('NetBox Sync module configuration must be a JSON object.');
        }

        $defaults = Config::defaults();
        foreach ($decoded as $section => $value) {
            if (isset($defaults[$section]) && is_array($defaults[$section]) && !is_array($value)) {
                throw new RuntimeException(
                    'NetBox Sync module configuration section "'.$section.'" must be an object or array.'
                );
            }
        }

        return self::mergeRecursiveDistinct($defaults, $decoded);
    }

    /** Match Config's merge behavior: objects merge recursively, lists replace. */
    private static function mergeRecursiveDistinct(array $defaults, array $overrides): array {
        foreach ($overrides as $key => $value) {
            if (
                is_array($value)
                && isset($defaults[$key])
                && is_array($defaults[$key])
                && self::isAssociative($value)
                && self::isAssociative($defaults[$key])
            ) {
                $defaults[$key] = self::mergeRecursiveDistinct($defaults[$key], $value);
                continue;
            }

            $defaults[$key] = $value;
        }

        return $defaults;
    }

    private static function isAssociative(array $value): bool {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }
}
