<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class RedactionStore {

    public static function load(array $config, string $server_session_id, string $client_session_id): array {
        $path = self::sessionPath($config, $server_session_id, $client_session_id);
        $data = Filesystem::readJson($path);

        if ($data === []) {
            $data = [
                'forward' => [],
                'reverse' => [],
                'meta' => [],
                'counters' => [
                    'hostname' => 0,
                    'ipv4' => 0,
                    'ipv6' => 0,
                    'fqdn' => 0,
                    'url' => 0,
                    'os' => 0,
                    'custom' => 0,
                    'service' => 0
                ],
                'created_at' => time(),
                'updated_at' => time()
            ];
        }

        $data['forward'] = is_array($data['forward'] ?? null) ? $data['forward'] : [];
        $data['reverse'] = is_array($data['reverse'] ?? null) ? $data['reverse'] : [];
        $data['meta'] = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $data['counters'] = is_array($data['counters'] ?? null) ? $data['counters'] : [];
        $data['updated_at'] = time();

        self::cleanup($config);

        return $data;
    }

    public static function save(array $config, string $server_session_id, string $client_session_id, array $state): void {
        $path = self::sessionPath($config, $server_session_id, $client_session_id);
        $state['updated_at'] = time();
        $state['created_at'] = (int) ($state['created_at'] ?? time());
        Filesystem::writeJsonAtomic($path, $state);
        self::cleanup($config);
    }

    public static function delete(array $config, string $server_session_id, string $client_session_id): void {
        $path = self::sessionPath($config, $server_session_id, $client_session_id);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function cleanup(array $config): void {
        static $cleaned = false;

        if ($cleaned) {
            return;
        }
        $cleaned = true;

        $ttl_hours = max(1, (int) ($config['security']['session_ttl_hours'] ?? 12));
        $max_age = time() - ($ttl_hours * 3600);
        $dir = self::baseDir($config);

        if (!is_dir($dir)) {
            return;
        }

        foreach (Filesystem::safeGlob($dir.'/*.json') as $file) {
            $mtime = (int) @filemtime($file);
            if ($mtime > 0 && $mtime < $max_age) {
                @unlink($file);
            }
        }
    }

    public static function baseDir(array $config): string {
        $path = Util::cleanPath($config['security']['state_path'] ?? '/tmp/zabbix-ai-module/state');

        if ($path === '') {
            throw new RuntimeException('Security state path is empty.');
        }

        Filesystem::ensureDir($path);

        return $path;
    }

    private static function sessionPath(array $config, string $server_session_id, string $client_session_id): string {
        $server_session_id = trim($server_session_id);
        $client_session_id = trim($client_session_id);

        if ($server_session_id === '' || $client_session_id === '') {
            throw new RuntimeException('A valid server and client session ID is required for redaction state.');
        }

        $hash = hash('sha256', $server_session_id.'|'.$client_session_id);

        return self::baseDir($config).'/redaction_'.$hash.'.json';
    }
}
