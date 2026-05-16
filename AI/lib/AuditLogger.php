<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

class AuditLogger {

    private static ?string $request_id = null;
    private static bool $maintenance_done = false;

    public static function requestId(): string {
        if (self::$request_id === null) {
            self::$request_id = Util::generateId('req');
        }

        return self::$request_id;
    }

    public static function log(array $config, string $category, array $entry): void {
        try {
            $config = Config::mergeWithDefaults($config);

            if (!Util::truthy($config['logging']['enabled'] ?? false)) {
                return;
            }

            if (!self::categoryEnabled($config, $category)) {
                return;
            }

            self::runMaintenance($config);

            $path = self::currentLogPath($config);
            $max_payload_chars = (int) ($config['logging']['max_payload_chars'] ?? 8000);
            $include_payloads = Util::truthy($config['logging']['include_payloads'] ?? true);
            $include_mapping_details = Util::truthy($config['logging']['include_mapping_details'] ?? false);

            if (!$include_payloads) {
                unset($entry['payload']);
            }

            if (!$include_mapping_details && isset($entry['security']['mapping_details'])) {
                unset($entry['security']['mapping_details']);
            }

            $record = [
                'ts' => gmdate('c'),
                'request_id' => self::requestId(),
                'category' => $category,
                'event' => Util::cleanString($entry['event'] ?? 'event', 128),
                'source' => Util::cleanString($entry['source'] ?? '', 128),
                'status' => Util::cleanString($entry['status'] ?? 'ok', 32),
                'message' => Util::truncate((string) ($entry['message'] ?? ''), 1000),
                'user' => self::currentUserInfo(),
                'remote_addr' => Util::cleanString($_SERVER['REMOTE_ADDR'] ?? '', 128),
                'provider' => Util::truncateMixed($entry['provider'] ?? [], 1000, 50),
                'tool' => Util::cleanString($entry['tool'] ?? '', 128),
                'duration_ms' => isset($entry['duration_ms']) ? (int) $entry['duration_ms'] : null,
                'security' => Util::truncateMixed($entry['security'] ?? [], $max_payload_chars, 200),
                'payload' => Util::truncateMixed($entry['payload'] ?? [], $max_payload_chars, 200),
                'meta' => Util::truncateMixed($entry['meta'] ?? [], 1000, 100)
            ];

            if (!$include_payloads) {
                unset($record['payload']);
            }

            if (empty($record['provider'])) {
                unset($record['provider']);
            }
            if ($record['tool'] === '') {
                unset($record['tool']);
            }
            if ($record['duration_ms'] === null) {
                unset($record['duration_ms']);
            }
            if (empty($record['security'])) {
                unset($record['security']);
            }
            if (empty($record['meta'])) {
                unset($record['meta']);
            }

            Filesystem::appendLine(
                $path,
                json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }
        catch (\Throwable $e) {
            // Logging must never break the module.
        }
    }

    private const QUERY_MAX_SCAN = 20000;

    public static function query(array $config, array $filters = [], int $limit = 250, int $offset = 0): array {
        $config = Config::mergeWithDefaults($config);
        $limit = max(1, min(2000, $limit));
        $offset = max(0, $offset);
        self::runMaintenance($config);

        $files = self::listFilesInRange($config, $filters['since'] ?? '', $filters['until'] ?? '');
        $matches = [];
        $scanned = 0;
        $cap = self::QUERY_MAX_SCAN;

        foreach ($files as $file) {
            foreach (self::readFileLines($file) as $line) {
                $scanned++;
                if ($scanned > $cap) {
                    break 2;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    continue;
                }

                if (!self::queryMatches($decoded, $filters)) {
                    continue;
                }

                $matches[] = $decoded;
                if (count($matches) >= $offset + $limit + 1) {
                    break 2;
                }
            }
        }

        $total = count($matches);
        $items = array_slice($matches, $offset, $limit);

        return [
            'items' => $items,
            'count' => count($items),
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => $total > $offset + $limit || $scanned > $cap
        ];
    }

    public static function facets(array $config, array $filters = [], array $fields = [
        'category', 'status', 'source', 'tool'
    ]): array {
        $config = Config::mergeWithDefaults($config);
        self::runMaintenance($config);

        $files = self::listFilesInRange($config, $filters['since'] ?? '', $filters['until'] ?? '');
        $facets = array_fill_keys($fields, []);
        $scanned = 0;
        $cap = self::QUERY_MAX_SCAN;

        // Apply only the time + tab filter when computing facets so the user
        // still sees all available values for the other dimensions.
        $facet_filters = [
            'category' => $filters['category'] ?? '',
            'since' => $filters['since'] ?? '',
            'until' => $filters['until'] ?? ''
        ];

        foreach ($files as $file) {
            foreach (self::readFileLines($file) as $line) {
                $scanned++;
                if ($scanned > $cap) {
                    break 2;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    continue;
                }

                if (!self::queryMatches($decoded, $facet_filters)) {
                    continue;
                }

                foreach ($fields as $field) {
                    $value = (string) ($decoded[$field] ?? '');
                    if ($value === '') {
                        continue;
                    }

                    $facets[$field][$value] = ($facets[$field][$value] ?? 0) + 1;
                }
            }
        }

        foreach ($facets as $field => $values) {
            ksort($values, SORT_NATURAL | SORT_FLAG_CASE);
            $facets[$field] = array_map(
                static function($value, $count) { return ['value' => (string) $value, 'count' => (int) $count]; },
                array_keys($values),
                array_values($values)
            );
        }

        return $facets;
    }

    public static function clear(array $config): int {
        $config = Config::mergeWithDefaults($config);

        $removed = 0;

        foreach (Filesystem::safeGlob(self::logDir($config).'/ai-*.jsonl') as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        foreach (Filesystem::safeGlob(self::archiveDir($config).'/ai-*.jsonl*') as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    public static function summary(array $config): array {
        $config = Config::mergeWithDefaults($config);
        self::runMaintenance($config);

        $log_path = self::logDir($config);
        $archive_path = self::archiveDir($config);
        $live_files = Filesystem::safeGlob($log_path.'/ai-*.jsonl');
        $archived_files = Filesystem::safeGlob($archive_path.'/ai-*.jsonl*');

        return [
            'enabled' => Util::truthy($config['logging']['enabled'] ?? false),
            'path' => $log_path,
            'archive_path' => $archive_path,
            'archive_enabled' => Util::truthy($config['logging']['archive_enabled'] ?? true),
            'compress_archives' => Util::truthy($config['logging']['compress_archives'] ?? true),
            'retention_days' => (int) ($config['logging']['retention_days'] ?? 30),
            'current_log_file' => self::currentLogPath($config),
            'live_file_count' => count($live_files),
            'archive_file_count' => count($archived_files),
            'path_writable' => is_dir($log_path) ? is_writable($log_path) : is_writable(dirname($log_path)),
            'archive_path_writable' => is_dir($archive_path) ? is_writable($archive_path) : is_writable(dirname($archive_path))
        ];
    }

    public static function permissionNote(): string {
        return 'The active web/PHP process must be able to create, read, and append files in the selected log and archive directories. Recommended Linux mode is 02770 (rwx for owner and group, setgid so new files inherit the group); files default to 0640. The directory group must match the php-fpm worker user (on RHEL/Alma/Rocky with nginx + php-fpm this is normally "apache", not "nginx" — confirm with `ps -eo user,comm | grep php-fpm`). On SELinux systems, label custom writable paths with httpd_sys_rw_content_t.';
    }

    private static function categoryEnabled(array $config, string $category): bool {
        $enabled = $config['logging']['categories'] ?? [];

        return Util::truthy($enabled[$category] ?? false);
    }

    private static function currentUserInfo(): array {
        $info = [];

        if (class_exists('CWebUser') && isset(\CWebUser::$data) && is_array(\CWebUser::$data)) {
            $info['userid'] = (string) (\CWebUser::$data['userid'] ?? '');
            $info['username'] = (string) (\CWebUser::$data['username'] ?? \CWebUser::$data['alias'] ?? '');
            $info['name'] = trim((string) ((\CWebUser::$data['name'] ?? '').' '.(\CWebUser::$data['surname'] ?? '')));
        }

        return array_filter($info, static function($value) {
            return trim((string) $value) !== '';
        });
    }

    private static function runMaintenance(array $config): void {
        if (self::$maintenance_done) {
            return;
        }
        self::$maintenance_done = true;

        try {
            Filesystem::ensureDir(self::logDir($config));
            Filesystem::ensureDir(self::archiveDir($config));
            self::archiveOldLogs($config);
            self::pruneOldArchives($config);
        }
        catch (\Throwable $e) {
            // Do not throw from maintenance.
        }
    }

    private static function archiveOldLogs(array $config): void {
        if (!Util::truthy($config['logging']['archive_enabled'] ?? true)) {
            return;
        }

        $today = gmdate('Y-m-d');
        $log_dir = self::logDir($config);
        $archive_dir = self::archiveDir($config);
        $compress = Util::truthy($config['logging']['compress_archives'] ?? true);

        foreach (Filesystem::safeGlob($log_dir.'/ai-*.jsonl') as $file) {
            if (strpos(basename($file), 'ai-'.$today.'.jsonl') === 0) {
                continue;
            }

            $target = $archive_dir.'/'.basename($file).($compress ? '.gz' : '');

            if (is_file($target)) {
                @unlink($file);
                continue;
            }

            if ($compress) {
                $raw = @file_get_contents($file);
                if ($raw === false) {
                    continue;
                }

                $gz = function_exists('gzencode') ? gzencode($raw, 6) : false;
                if ($gz === false) {
                    continue;
                }

                if (@file_put_contents($target, $gz, LOCK_EX) !== false) {
                    @chmod($target, 0640);
                    @unlink($file);
                }
            }
            else {
                Filesystem::moveFile($file, $target);
            }
        }
    }

    private static function pruneOldArchives(array $config): void {
        $retention_days = max(1, (int) ($config['logging']['retention_days'] ?? 30));
        $cutoff = time() - ($retention_days * 86400);

        foreach (Filesystem::safeGlob(self::archiveDir($config).'/ai-*.jsonl*') as $file) {
            $mtime = (int) @filemtime($file);
            if ($mtime > 0 && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }

    private static function listLogFiles(array $config): array {
        $files = array_merge(
            Filesystem::safeGlob(self::logDir($config).'/ai-*.jsonl'),
            Filesystem::safeGlob(self::archiveDir($config).'/ai-*.jsonl*')
        );

        usort($files, static function($a, $b) {
            return strcmp($b, $a);
        });

        return $files;
    }

    private static function readFileLines(string $file): array {
        if (substr($file, -3) === '.gz' && function_exists('gzopen')) {
            $lines = [];
            $handle = @gzopen($file, 'rb');
            if ($handle === false) {
                return [];
            }

            try {
                while (!gzeof($handle)) {
                    $line = gzgets($handle);
                    if ($line !== false) {
                        $lines[] = $line;
                    }
                }
            }
            finally {
                @gzclose($handle);
            }

            return array_reverse($lines);
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return array_reverse(is_array($lines) ? $lines : []);
    }

    private static function queryMatches(array $entry, array $filters): bool {
        // Tab filter: 'category' = 'all' (show everything) or 'errors' (show
        // entries where status === 'error' OR category === 'errors') or any
        // specific category name.
        $tab = strtolower(trim((string) ($filters['category'] ?? '')));
        if ($tab !== '' && $tab !== 'all') {
            if ($tab === 'errors') {
                $is_error = strtolower((string) ($entry['status'] ?? '')) === 'error'
                    || strtolower((string) ($entry['category'] ?? '')) === 'errors';
                if (!$is_error) {
                    return false;
                }
            }
            elseif ($tab === 'tools') {
                $cat = strtolower((string) ($entry['category'] ?? ''));
                if ($cat !== 'reads' && $cat !== 'writes') {
                    return false;
                }
            }
            elseif (strtolower((string) ($entry['category'] ?? '')) !== $tab) {
                return false;
            }
        }

        // Multi-value field filters (substring match, case-insensitive).
        foreach (['category', 'status', 'source', 'tool', 'event', 'request_id'] as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }

            $values = self::normalizeFilterList($filters[$key]);
            if ($values === []) {
                continue;
            }

            if (!self::containsAny((string) ($entry[$key] ?? ''), $values)) {
                return false;
            }
        }

        // Time range — work on the ISO 8601 'ts' prefix YYYY-MM-DD.
        $ts_date = substr((string) ($entry['ts'] ?? ''), 0, 10);

        $since = self::cleanIsoDate($filters['since'] ?? '');
        if ($since !== '' && $ts_date !== '' && strcmp($ts_date, $since) < 0) {
            return false;
        }

        $until = self::cleanIsoDate($filters['until'] ?? '');
        if ($until !== '' && $ts_date !== '' && strcmp($ts_date, $until) > 0) {
            return false;
        }

        // Free text search across the full encoded record.
        $text = trim((string) ($filters['q'] ?? ''));
        if ($text !== '') {
            $haystack = strtolower(json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if (strpos($haystack, strtolower($text)) === false) {
                return false;
            }
        }

        return true;
    }

    private static function normalizeFilterList($input): array {
        if (is_string($input)) {
            $input = array_map('trim', explode(',', $input));
        }

        if (!is_array($input)) {
            return [];
        }

        $values = [];
        foreach ($input as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private static function containsAny(string $current, array $needles): bool {
        $current_lower = strtolower($current);

        foreach ($needles as $needle) {
            $needle_lower = strtolower((string) $needle);
            if ($needle_lower === '') {
                continue;
            }

            if ($current_lower === $needle_lower) {
                return true;
            }

            if (strpos($current_lower, $needle_lower) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function cleanIsoDate(string $value): string {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : '';
    }

    private static function listFilesInRange(array $config, string $since, string $until): array {
        $files = self::listLogFiles($config);
        $since = self::cleanIsoDate($since);
        $until = self::cleanIsoDate($until);

        if ($since === '' && $until === '') {
            return $files;
        }

        $filtered = [];
        foreach ($files as $file) {
            // Filename shape: ai-YYYY-MM-DD.jsonl[.gz]
            if (!preg_match('/ai-(\d{4}-\d{2}-\d{2})\.jsonl/', basename($file), $m)) {
                $filtered[] = $file;
                continue;
            }

            $date = $m[1];
            if ($since !== '' && strcmp($date, $since) < 0) {
                continue;
            }
            if ($until !== '' && strcmp($date, $until) > 0) {
                continue;
            }

            $filtered[] = $file;
        }

        return $filtered;
    }

    private static function logDir(array $config): string {
        $path = Util::cleanPath($config['logging']['path'] ?? '/tmp/zabbix-ai-module/logs');
        if ($path === '') {
            $path = '/tmp/zabbix-ai-module/logs';
        }
        Filesystem::ensureDir($path);
        return $path;
    }

    private static function archiveDir(array $config): string {
        $path = Util::cleanPath($config['logging']['archive_path'] ?? '/tmp/zabbix-ai-module/archive');
        if ($path === '') {
            $path = '/tmp/zabbix-ai-module/archive';
        }
        Filesystem::ensureDir($path);
        return $path;
    }

    private static function currentLogPath(array $config): string {
        return self::logDir($config).'/ai-'.gmdate('Y-m-d').'.jsonl';
    }
}
