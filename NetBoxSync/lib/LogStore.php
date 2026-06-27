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

    /** Fields exposed as multi-select facets (exact match). */
    private const FACET_FIELDS = ['host', 'os', 'target_type', 'sync_id', 'field', 'disk_name'];

    /** Value fields scanned by the free-text "q" box (never field names/keys). */
    private const Q_FIELDS = [
        'host', 'os', 'sync_id', 'target_type', 'target_name',
        'field', 'old_value', 'new_value', 'message', 'disk_name'
    ];

    /** Columns that accept a per-column substring filter. */
    private const COL_FIELDS = [
        'timestamp', 'host', 'os', 'sync_id', 'target_type', 'target_name',
        'field', 'old_value', 'new_value', 'message', 'disk_name', 'hostid'
    ];

    private string $log_path;
    private string $events_path;

    public function __construct(string $log_path) {
        $this->log_path = Util::cleanPath($log_path);
        $this->events_path = $this->log_path === '' ? '' : $this->log_path.'/events';
    }

    public function eventsPath(): string {
        return $this->events_path;
    }

    /** Facet field names exposed to the UI as multi-selects (exact match). */
    public static function facetFields(): array {
        return self::FACET_FIELDS;
    }

    /** Column names that accept a per-column substring filter. */
    public static function columnFields(): array {
        return self::COL_FIELDS;
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

    /**
     * Fetch a window of log rows for one tab (type) and, in the SAME file scan,
     * tally the facet value lists. Facets are computed over the type-filtered set
     * (independent of the exact/column/text filters) so the multi-selects keep
     * offering every value available for the tab. Returns items, paging metadata
     * and the formatted facets.
     */
    public function query(array $filters = [], int $limit = 250, int $offset = 0): array {
        $limit = max(1, min(2000, $limit));
        $offset = max(0, $offset);

        $files = $this->collectLogFiles($filters['since'] ?? '', $filters['until'] ?? '');
        $type = strtolower((string) ($filters['type'] ?? ''));

        $facet_tally = array_fill_keys(self::FACET_FIELDS, []);
        $matched = [];
        $matched_count = 0;
        $window = $offset + $limit + 1;
        $scanned = 0;
        $capped = false;

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $scanned++;
                if ($scanned > self::MAX_ROWS_SCAN) {
                    $capped = true;
                    break 2;
                }

                $decoded = json_decode((string) $lines[$i], true);
                if (!is_array($decoded)) {
                    continue;
                }

                if ($type !== '' && strtolower((string) ($decoded['type'] ?? '')) !== $type) {
                    continue;
                }

                foreach (self::FACET_FIELDS as $field) {
                    $value = (string) ($decoded[$field] ?? '');
                    if ($value !== '') {
                        $facet_tally[$field][$value] = ($facet_tally[$field][$value] ?? 0) + 1;
                    }
                }

                if (!$this->matchesFilters($decoded, $filters)) {
                    continue;
                }

                $matched_count++;
                if (count($matched) < $window) {
                    $matched[] = $decoded;
                }
            }
        }

        $items = array_slice($matched, $offset, $limit);

        return [
            'items' => $items,
            'count' => count($items),
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => $matched_count > ($offset + $limit) || $capped,
            'facets' => $this->formatFacets($facet_tally)
        ];
    }

    /**
     * Count matching rows per type in a single scan so the tab badges can show
     * exact totals without four extra full fetches. Ignores the type filter (it
     * tallies every tab) but honours the since/until/exact/column/text filters.
     * A capped flag signals the MAX_ROWS_SCAN ceiling was hit.
     */
    public function counts(array $filters = []): array {
        $files = $this->collectLogFiles($filters['since'] ?? '', $filters['until'] ?? '');
        $counts = array_fill_keys(self::TYPES, 0);
        $scanned = 0;
        $capped = false;

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                $scanned++;
                if ($scanned > self::MAX_ROWS_SCAN) {
                    $capped = true;
                    break 2;
                }

                $decoded = json_decode((string) $line, true);
                if (!is_array($decoded)) {
                    continue;
                }

                $type = strtolower((string) ($decoded['type'] ?? ''));
                if (!array_key_exists($type, $counts)) {
                    continue;
                }

                if (!$this->matchesFilters($decoded, $filters)) {
                    continue;
                }

                $counts[$type]++;
            }
        }

        return [
            'counts' => $counts,
            'capped' => $capped
        ];
    }

    private function formatFacets(array $tally): array {
        $facets = [];

        foreach ($tally as $field => $values) {
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

    /**
     * Apply the non-type filters to a decoded row:
     *  - exact[field] => values : case-insensitive equality, any-of (facets).
     *  - col[field]   => needle : case-insensitive substring (per-column boxes).
     *  - q            => text   : case-insensitive substring over VALUE fields
     *                             only (never field names or the raw JSON keys).
     * The type filter is handled by the caller before this runs.
     */
    private function matchesFilters(array $event, array $filters): bool {
        foreach (($filters['exact'] ?? []) as $key => $values) {
            if (!is_array($values) || $values === []) {
                continue;
            }
            if (!$this->equalsAny((string) ($event[$key] ?? ''), $values)) {
                return false;
            }
        }

        foreach (($filters['col'] ?? []) as $key => $needle) {
            $needle = trim((string) $needle);
            if ($needle === '') {
                continue;
            }
            if (stripos((string) ($event[$key] ?? ''), $needle) === false) {
                return false;
            }
        }

        $text = trim((string) ($filters['q'] ?? ''));
        if ($text !== '' && !$this->matchesText($event, $text)) {
            return false;
        }

        return true;
    }

    private function equalsAny(string $current, array $values): bool {
        foreach ($values as $value) {
            if (strcasecmp($current, (string) $value) === 0) {
                return true;
            }
        }

        return false;
    }

    private function matchesText(array $event, string $text): bool {
        $needle = strtolower($text);

        foreach (self::Q_FIELDS as $field) {
            $value = strtolower((string) ($event[$field] ?? ''));
            if ($value !== '' && strpos($value, $needle) !== false) {
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
