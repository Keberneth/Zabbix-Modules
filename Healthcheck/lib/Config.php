<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Lib;

use API,
    PDO,
    RuntimeException,
    Throwable;

class Config {

    public const MODULE_ID = 'healthcheck_monitor';

    public static function defaults(): array {
        return [
            'checks' => [],
            'history' => [
                'retention_days' => 90,
                'default_period_days' => 7,
                'recent_runs_limit' => 200
            ]
        ];
    }

    public static function getModuleRecord(?PDO $pdo = null): ?array {
        $pdo = $pdo ?? DbConnector::connect();

        $stmt = $pdo->prepare(
            'SELECT moduleid, id, relative_path, status, config'.
            ' FROM module'.
            ' WHERE id = :id'.
            ' LIMIT 1'
        );
        $stmt->execute([':id' => self::MODULE_ID]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['config'] = self::mergeWithDefaults(self::decodeConfig($row['config'] ?? ''));

        return $row;
    }

    public static function get(?PDO $pdo = null): array {
        $record = self::getModuleRecord($pdo);

        return $record ? $record['config'] : self::defaults();
    }

    public static function save(array $config, ?PDO $pdo = null): void {
        $pdo = $pdo ?? DbConnector::connect();
        $record = self::getModuleRecord($pdo);

        if (!$record) {
            throw new RuntimeException('Healthcheck module is not registered in the Zabbix module table.');
        }

        $config = self::mergeWithDefaults($config);
        $json = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            if (class_exists('API')) {
                API::Module()->update([[
                    'moduleid' => $record['moduleid'],
                    'config' => $config
                ]]);

                return;
            }
        }
        catch (Throwable $e) {
            // fall back to direct table update below
        }

        $stmt = $pdo->prepare(
            'UPDATE module'.
            ' SET config = :config'.
            ' WHERE moduleid = :moduleid'
        );
        $stmt->execute([
            ':config' => $json,
            ':moduleid' => $record['moduleid']
        ]);
    }

    public static function sanitizeForView(array $config): array {
        $config = self::mergeWithDefaults($config);

        foreach ($config['checks'] as &$check) {
            $check['zabbix_api_token_present'] = trim((string) ($check['zabbix_api_token'] ?? '')) !== '';
            $check['zabbix_api_token'] = '';
        }
        unset($check);

        return $config;
    }

    public static function buildFromPost(array $post, array $current_config): array {
        $current_config = self::mergeWithDefaults($current_config);
        $new_config = self::defaults();

        $new_config['checks'] = [];
        $current_checks = self::indexById($current_config['checks']);

        foreach (($post['checks'] ?? []) as $check) {
            if (!is_array($check)) {
                continue;
            }

            $is_empty = trim((string) ($check['name'] ?? '')) === ''
                && trim((string) ($check['zabbix_api_url'] ?? '')) === ''
                && trim((string) ($check['ping_url'] ?? '')) === '';

            if ($is_empty) {
                continue;
            }

            $id = Util::cleanId($check['id'] ?? '', 'check');
            $existing = $current_checks[$id] ?? [];

            $clear_token = Util::truthy($check['clear_zabbix_api_token'] ?? false);
            $token_input = Util::cleanString($check['zabbix_api_token'] ?? '');
            $token = $clear_token
                ? ''
                : (($token_input !== '') ? $token_input : (string) ($existing['zabbix_api_token'] ?? ''));

            $new_config['checks'][] = [
                'id' => $id,
                'name' => Util::cleanString($check['name'] ?? '', 128),
                'enabled' => Util::truthy($check['enabled'] ?? false),
                'interval_seconds' => Util::cleanInt($check['interval_seconds'] ?? 300, 300, 30, 86400),
                'ping_url' => Util::cleanUrl($check['ping_url'] ?? ''),
                'zabbix_api_url' => Util::cleanUrl($check['zabbix_api_url'] ?? ''),
                'zabbix_api_token' => $token,
                'zabbix_api_token_env' => Util::cleanString($check['zabbix_api_token_env'] ?? '', 128),
                'verify_peer' => Util::truthy($check['verify_peer'] ?? false),
                'timeout' => Util::cleanInt($check['timeout'] ?? 10, 10, 3, 300),
                'freshness_max_age' => Util::cleanInt($check['freshness_max_age'] ?? 900, 900, 60, 86400),
                'host_limit' => Util::cleanInt($check['host_limit'] ?? 5000, 5000, 1, 50000),
                'item_limit_per_host' => Util::cleanInt($check['item_limit_per_host'] ?? 10000, 10000, 1, 50000),
                'auth_mode' => self::normalizeAuthMode($check['auth_mode'] ?? 'auto')
            ];
        }

        $new_config['history'] = [
            'retention_days' => Util::cleanInt($post['history']['retention_days'] ?? 90, 90, 1, 3650),
            'default_period_days' => Util::cleanInt($post['history']['default_period_days'] ?? 7, 7, 1, 365),
            'recent_runs_limit' => Util::cleanInt($post['history']['recent_runs_limit'] ?? 200, 200, 20, 1000)
        ];

        return self::mergeWithDefaults($new_config);
    }

    public static function getCheck(array $config, string $check_id): ?array {
        $config = self::mergeWithDefaults($config);

        foreach ($config['checks'] as $check) {
            if (($check['id'] ?? '') === $check_id) {
                return $check;
            }
        }

        return null;
    }

    public static function getEnabledChecks(array $config): array {
        $config = self::mergeWithDefaults($config);

        return array_values(array_filter($config['checks'], static function(array $check): bool {
            return Util::truthy($check['enabled'] ?? false);
        }));
    }

    public static function resolveSecret($plain_value, $env_name = ''): string {
        $plain_value = trim((string) $plain_value);
        $env_name = trim((string) $env_name);

        if ($env_name === '' && strncmp($plain_value, 'env:', 4) === 0) {
            $env_name = substr($plain_value, 4);
            $plain_value = '';
        }

        if ($env_name !== '') {
            $env_value = getenv($env_name);
            if ($env_value !== false && $env_value !== null) {
                return trim((string) $env_value);
            }
        }

        return $plain_value;
    }

    public static function mergeWithDefaults(array $config): array {
        $defaults = self::defaults();
        $merged = $defaults;

        foreach ($config as $key => $value) {
            if ($key === 'checks') {
                $merged[$key] = is_array($value) ? array_values($value) : [];
            }
            elseif (isset($defaults[$key]) && is_array($defaults[$key]) && is_array($value)) {
                $merged[$key] = array_replace_recursive($defaults[$key], $value);
            }
            else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    private static function decodeConfig($config): array {
        if (is_array($config)) {
            return $config;
        }

        $config = trim((string) $config);

        if ($config === '') {
            return self::defaults();
        }

        $decoded = json_decode($config, true);

        return is_array($decoded) ? $decoded : self::defaults();
    }

    private static function indexById(array $rows): array {
        $indexed = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $indexed[$id] = $row;
            }
        }

        return $indexed;
    }

    private static function normalizeAuthMode($value): string {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['auto', 'bearer', 'legacy_auth_field'], true)
            ? $value
            : 'auto';
    }
}
