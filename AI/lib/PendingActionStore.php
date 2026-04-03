<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class PendingActionStore {

    public static function create(array $config, string $server_session_id, array $action, int $ttl_seconds = 1800): string {
        $server_session_id = trim($server_session_id);
        if ($server_session_id === '') {
            throw new RuntimeException('Server session ID is required for pending actions.');
        }

        $id = Util::generateId('action');
        $data = [
            'id' => $id,
            'server_session_hash' => hash('sha256', $server_session_id),
            'created_at' => time(),
            'expires_at' => time() + max(300, $ttl_seconds),
            'action' => $action
        ];

        Filesystem::writeJsonAtomic(self::path($config, $id), $data);
        self::cleanup($config);

        return $id;
    }

    public static function consume(array $config, string $server_session_id, string $action_id): array {
        $data = self::load($config, $server_session_id, $action_id);
        $path = self::path($config, $action_id);
        if (is_file($path)) {
            @unlink($path);
        }
        return $data['action'];
    }

    public static function load(array $config, string $server_session_id, string $action_id): array {
        $server_session_id = trim($server_session_id);
        $action_id = Util::cleanId($action_id, 'action');

        if ($server_session_id === '' || $action_id === '') {
            throw new RuntimeException('Pending action ID is required.');
        }

        $data = Filesystem::readJson(self::path($config, $action_id));

        if ($data === []) {
            throw new RuntimeException('Pending action not found or already used.');
        }

        if (($data['server_session_hash'] ?? '') !== hash('sha256', $server_session_id)) {
            throw new RuntimeException('Pending action does not belong to this session.');
        }

        if ((int) ($data['expires_at'] ?? 0) < time()) {
            @unlink(self::path($config, $action_id));
            throw new RuntimeException('Pending action expired. Please ask the AI to generate it again.');
        }

        return $data;
    }

    public static function cleanup(array $config): void {
        static $cleaned = false;

        if ($cleaned) {
            return;
        }
        $cleaned = true;

        $dir = self::baseDir($config);

        if (!is_dir($dir)) {
            return;
        }

        foreach (Filesystem::safeGlob($dir.'/pending_*.json') as $file) {
            $data = Filesystem::readJson($file);
            if ($data === [] || (int) ($data['expires_at'] ?? 0) < time()) {
                @unlink($file);
            }
        }
    }

    private static function baseDir(array $config): string {
        $base = RedactionStore::baseDir($config).'/pending';
        Filesystem::ensureDir($base);
        return $base;
    }

    private static function path(array $config, string $action_id): string {
        return self::baseDir($config).'/pending_'.preg_replace('/[^A-Za-z0-9_.-]/', '_', $action_id).'.json';
    }
}
