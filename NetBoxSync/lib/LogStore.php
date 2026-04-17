<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Lib;

use RuntimeException;

class LogStore {

    public const TYPE_ADDED = 'added';
    public const TYPE_CHANGED = 'changed';
    public const TYPE_REMOVED = 'removed';
    public const TYPE_ERROR = 'error';

    private const TYPES = [
        self::TYPE_ADDED,
        self::TYPE_CHANGED,
        self::TYPE_REMOVED,
        self::TYPE_ERROR
    ];

    private const MAX_ROWS_SCAN = 20000;
    private const MAX_FILES = 366;

    private string $log_path;
    private string $events_path;

    public function __construct(string $log_path) {
        $this->log_path = Util::cleanPath($log_path);
        $this->events_path = $this->log_path === '' ? '' : $this->log_path.'/events';
    }

    public function eventsPath(): string {
        return $this->events_path;
    }

    public function isConfigured(): bool {
        return $this->events_path !== '';
    }

    public function ensureDirectories(): void {
        if (!$this->isConfigured()) {
            return;
        }

        foreach ([$this->log_path, $this->events_path] as $path) {
            if (is_dir($path)) {
                if (!is_writable($path)) {
                    throw new RuntimeException(Util::buildDirectoryHint($path, 'is not writable', 'Log path'));
                }
                continue;
            }

            if (!@mkdir($path, 0770, true) && !is_dir($path)) {
                throw new RuntimeException(Util::buildDirectoryHint($path, 'could not be created', 'Log path'));
            }
        }
    }

    public function record(array $event): void {
        if (!$this->isConfigured()) {
            return;
        }

        $type = strtolower((string) ($event['type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            return;
        }

        if (empty($event['timestamp'])) {
            $event['timestamp'] = gmdate('c');
        }

        $event['type'] = $type;
        $event = $this->normalizeEvent($event);
        $date = substr((string) $event['timestamp'], 0, 10);

        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = gmdate('Y-m-d');
        }

        try {
            $this->ensureDirectories();
        }
        catch (\Throwable $e) {
            return;
        }

        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($line === false) {
            return;
        }

        @file_put_contents(
            $this->events_path.'/'.$date.'.jsonl',
            $line."\n",
            FILE_APPEND
        );
    }

    public function query(array $filters = [], int $limit = 500, int $offset = 0): array {
        $limit = max(1, min(2000, $limit));
        $offset = max(0, $offset);

        $files = $this->collectLogFiles($filters['since'] ?? '', $filters['until'] ?? '');
        $matches = [];
        $scanned = 0;

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $scanned++;
                if ($scanned > self::MAX_ROWS_SCAN) {
                    break 2;
                }

                $decoded = json_decode((string) $lines[$i], true);
                if (!is_array($decoded)) {
                    continue;
                }

                if (!$this->matches($decoded, $filters)) {
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
            'has_more' => $total > $offset + $limit || $scanned > self::MAX_ROWS_SCAN
        ];
    }

    public function facets(array $filters = [], array $fields = [
        'host', 'os', 'target_type', 'sync_id', 'field', 'disk_name'
    ]): array {
        $files = $this->collectLogFiles($filters['since'] ?? '', $filters['until'] ?? '');
        $type = strtolower((string) ($filters['type'] ?? ''));

        $facets = array_fill_keys($fields, []);
        $scanned = 0;

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $scanned++;
                if ($scanned > self::MAX_ROWS_SCAN) {
                    break 2;
                }

                $decoded = json_decode((string) $line, true);
                if (!is_array($decoded)) {
                    continue;
                }

                if ($type !== '' && strtolower((string) ($decoded['type'] ?? '')) !== $type) {
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

    public function clear(): int {
        if (!$this->isConfigured() || !is_dir($this->events_path)) {
            return 0;
        }

        $files = glob($this->events_path.'/*.jsonl');

        if (!is_array($files)) {
            return 0;
        }

        $removed = 0;
        foreach ($files as $file) {
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    private function collectLogFiles(string $since, string $until): array {
        if (!$this->isConfigured() || !is_dir($this->events_path)) {
            return [];
        }

        $files = glob($this->events_path.'/*.jsonl');

        if (!is_array($files) || $files === []) {
            return [];
        }

        sort($files);

        $since = $this->cleanDate($since);
        $until = $this->cleanDate($until);

        $filtered = [];
        foreach ($files as $file) {
            $date = basename($file, '.jsonl');
            if ($since !== '' && strcmp($date, $since) < 0) {
                continue;
            }
            if ($until !== '' && strcmp($date, $until) > 0) {
                continue;
            }
            $filtered[] = $file;
        }

        if (count($filtered) > self::MAX_FILES) {
            $filtered = array_slice($filtered, -self::MAX_FILES);
        }

        return array_reverse($filtered);
    }

    private function matches(array $event, array $filters): bool {
        $type = strtolower((string) ($filters['type'] ?? ''));
        if ($type !== '' && strtolower((string) ($event['type'] ?? '')) !== $type) {
            return false;
        }

        $filterable = ['host', 'target_type', 'target_name', 'sync_id', 'field', 'os', 'disk_name'];

        foreach ($filterable as $key) {
            if (!array_key_exists($key, $filters)) {
                continue;
            }

            $values = $this->normalizeFilterList($filters[$key]);
            if ($values === []) {
                continue;
            }

            if (!$this->containsAny((string) ($event[$key] ?? ''), $values)) {
                return false;
            }
        }

        $text = trim((string) ($filters['q'] ?? ''));
        if ($text !== '') {
            $haystack = strtolower(json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            if (strpos($haystack, strtolower($text)) === false) {
                return false;
            }
        }

        return true;
    }

    private function normalizeFilterList($input): array {
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

    private function containsAny(string $current, array $needles): bool {
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

    private function cleanDate(string $value): string {
        $value = trim($value);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }

        return $value;
    }

    private function normalizeEvent(array $event): array {
        $string_fields = [
            'host', 'hostid', 'target_type', 'target_name', 'sync_id',
            'field', 'old_value', 'new_value', 'os', 'disk_name', 'message'
        ];

        foreach ($string_fields as $key) {
            if (array_key_exists($key, $event) && $event[$key] !== null) {
                $event[$key] = Util::truncate((string) $event[$key], 2000);
            }
        }

        if (isset($event['target_id']) && !is_int($event['target_id'])) {
            $event['target_id'] = is_numeric($event['target_id']) ? (int) $event['target_id'] : null;
        }

        return $event;
    }
}
