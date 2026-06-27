<?php
declare(strict_types=1);

namespace Modules\NetworkMap\Lib;

final class Config {
    private static ?array $config = null;

    public static function all(): array {
        if (self::$config === null) {
            self::$config = self::load();
        }

        return self::$config;
    }

    public static function get(string $path, mixed $default = null): mixed {
        $value = self::all();

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private static function load(): array {
        $config = self::defaults();
        $local_path = dirname(__DIR__) . '/config.local.php';

        if (is_file($local_path)) {
            $override = require $local_path;

            if (is_array($override)) {
                $config = self::merge($config, $override);
            }
        }

        $config['cache_dir'] = self::normalizeDirectory((string) $config['cache_dir']);
        $config['cache_ttl_seconds'] = max(0, (int) $config['cache_ttl_seconds']);
        $config['history_window_hours'] = max(1, (int) $config['history_window_hours']);
        $config['history_limit_per_item'] = max(1000, (int) $config['history_limit_per_item']);
        $config['host_label_source'] = self::normalizeHostLabelSource((string) ($config['host_label_source'] ?? 'visible'));
        $config['append_primary_ip_to_host_labels'] = (bool) ($config['append_primary_ip_to_host_labels'] ?? true);

        if (!is_array($config['zabbix_item_names'] ?? null) || $config['zabbix_item_names'] === []) {
            $config['zabbix_item_names'] = [
                'linux-network-connections',
                'windows-network-connections'
            ];
        }

        if (!is_array($config['node_color_map'] ?? null)) {
            $config['node_color_map'] = self::defaults()['node_color_map'];
        }

        return $config;
    }

    private static function defaults(): array {
        $cache_dir = self::envValue('NETWORK_MAP_CACHE_DIR');
        $cache_ttl = self::envValue('NETWORK_MAP_CACHE_TTL_SECONDS');
        $history_window = self::envValue('NETWORK_MAP_HISTORY_WINDOW_HOURS');
        $history_limit = self::envValue('NETWORK_MAP_HISTORY_LIMIT_PER_ITEM');
        $host_label_source = self::envValue('NETWORK_MAP_HOST_LABEL_SOURCE');

        return [
            'cache_dir' => $cache_dir !== false ? $cache_dir : (sys_get_temp_dir() . '/network-map'),
            'cache_ttl_seconds' => (int) ($cache_ttl !== false ? $cache_ttl : 1800),
            'history_window_hours' => (int) ($history_window !== false ? $history_window : 24),
            'history_limit_per_item' => (int) ($history_limit !== false ? $history_limit : 50000),
            'host_label_source' => $host_label_source !== false ? $host_label_source : 'visible',
            'append_primary_ip_to_host_labels' => self::envBool('NETWORK_MAP_APPEND_PRIMARY_IP_TO_HOST_LABELS', true),
            'zabbix_item_names' => [
                'linux-network-connections',
                'windows-network-connections'
            ],
            'node_color_map' => [
                'monitored_host' => '#007bff',
                'private_ip' => '#6c757d',
                'external' => '#ff3366'
            ]
        ];
    }

    private static function merge(array $defaults, array $override): array {
        foreach ($override as $key => $value) {
            if (
                array_key_exists($key, $defaults)
                && is_array($defaults[$key])
                && is_array($value)
                && self::isAssoc($defaults[$key])
                && self::isAssoc($value)
            ) {
                $defaults[$key] = self::merge($defaults[$key], $value);
            }
            else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    private static function isAssoc(array $value): bool {
        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    private static function envBool(string $name, bool $default, ?string $legacy_name = null): bool {
        $value = self::envValue($name, $legacy_name);

        if ($value === false || $value === '') {
            return $default;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $bool ?? $default;
    }

    private static function normalizeDirectory(string $path): string {
        $path = trim($path);

        if ($path === '') {
            return sys_get_temp_dir() . '/network-map';
        }

        return rtrim($path, '/');
    }

    private static function envValue(string $name, ?string $legacy_name = null): string|false {
        $value = getenv($name);

        if ($value === false || $value === '') {
            if ($legacy_name !== null) {
                $value = getenv($legacy_name);
            }
        }

        return ($value === false || $value === '') ? false : $value;
    }

    private static function normalizeHostLabelSource(string $value): string {
        $value = strtolower(trim($value));

        return in_array($value, ['technical', 'visible'], true) ? $value : 'visible';
    }
}
