<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Helpers;

use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Incremental numeric history cache. A live permission-filtered Item.get MUST precede each use.
 * Rules, maintenance and final availability are deliberately never cached: callers apply them anew.
 * Cache failure is an optimization failure, never evidence that a history range was empty.
 */
final class HistoryCache {
	public const SCHEMA = 1;
	public const SHARD_SECONDS = 7 * 86400;
	public const MAX_ROWS_PER_SHARD = 30000;
	private const MAX_FILE_BYTES = 16777216;
	private const MAX_JSON_BYTES = 8388608;
	private const MAX_DIRECTORY_BYTES = 268435456;
	private const MAX_DIRECTORY_FILES = 4096;
	private const CLOSED_TTL = 86400;
	private const EMPTY_TTL = 60;
	private const REFRESH_OVERLAP = 900;

	private array $options;
	private string $directory = '';
	private ?int $uid = null;
	private bool $initialized = false;
	private string $reason = '';
	private string $kind;

	/** Options: enabled, directory, namespace, kind (history/trend); now callable is for tests. No I/O until authorized. */
	public function __construct(array $options = []) {
		$this->options = $options;
		$this->kind = is_string($options['kind'] ?? 'history') ? ($options['kind'] ?? 'history') : '';
	}

	/**
	 * Return a complete, currently reusable range without invoking a history loader.
	 * Null means a missing/expired/mutable range or unavailable backend needs a live read.
	 * Authorization and input errors still propagate. Aborted probes never publish coverage.
	 */
	public function tryCachedRange(string $itemid, int $valueType, int $from, int $to, bool $authorized): ?array {
		try {
			return $this->fetchRange($itemid, $valueType, $from, $to, $authorized,
				static function(int $start, int $end): array { throw new HistoryCacheMissException(); });
		}
		catch (HistoryCacheMissException $e) { return null; }
	}

	/**
	 * Inclusive range. Loader must return COMPLETE history rows or throw; exceptions propagate unchanged.
	 * History rows contain clock,value,ns; trend rows clock,num,value_min,value_avg,value_max.
	 * Permission must be freshly checked even on an entirely cached range.
	 * @return array{rows:array,meta:array}
	 */
	public function fetchRange(string $itemid, int $valueType, int $from, int $to,
			bool $authorized, callable $loader, bool $force = false): array {
		if (!$authorized) { throw new DomainException('A live authorized item lookup is required before history cache access.'); }
		if (!in_array($this->kind, ['history', 'trend'], true)
				|| !preg_match('/^[1-9][0-9]{0,19}$/D', $itemid) || !in_array($valueType, [0, 3], true)
				|| $from < 0 || $to < $from || $to - $from > 769 * 86400) {
			throw new InvalidArgumentException('Invalid numeric history cache range.');
		}
		$this->initialize();
		$now = isset($this->options['now']) ? (int) ($this->options['now'])() : time();
		$meta = ['enabled' => (bool) ($this->options['enabled'] ?? true), 'backend_available' => $this->directory !== '',
			'hits' => 0, 'misses' => 0, 'rows_cached' => 0, 'rows_fetched' => 0, 'fetched_ranges' => [],
			'forced' => $force, 'reason' => $this->reason];
		$series = hash('sha256', self::SCHEMA."\0".$this->kind."\0".$itemid."\0".$valueType);
		$result = [];
		// 1970-01-05 is Monday; fixed UTC weeks also work through DST changes.
		$week = (int) floor(($from - 345600) / self::SHARD_SECONDS) * self::SHARD_SECONDS + 345600;
		for (; $week <= $to; $week += self::SHARD_SECONDS) {
			$start = max($from, $week); $end = min($to, $week + self::SHARD_SECONDS - 1);
			if ($this->directory === '') {
				$rows = $this->load($loader, $start, $end, $meta);
				$meta['misses']++;
			}
			else { $rows = $this->fetchShard($series, $week, $start, $end, $now, $loader, $force, $meta); }
			foreach ($rows as $row) { $result[] = $row; }
		}
		$meta['row_count'] = count($result);
		return ['rows' => $result, 'meta' => $meta];
	}

	private function fetchShard(string $series, int $week, int $from, int $to, int $now,
			callable $loader, bool $force, array &$meta): array {
		$file = $this->directory.'/'.$series.'-'.$week.'.json.gz';
		$lock = null;
		try {
			// Fixed lock stripes bound file count while letting unrelated cached ranges load concurrently.
			$stripe = hexdec(substr(hash('sha256', $series."\0".$week), 0, 2)) % 32;
			$lock = $this->lock($this->directory.'/shard-'.sprintf('%02d', $stripe).'.lock');
			$record = $this->read($file, $series, $week);
		}
		catch (HistoryCacheStorageException $e) {
			if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
			$meta['reason'] = $e->getMessage(); $meta['misses']++;
			return $this->load($loader, $from, $to, $meta);
		}
		try {
			$rows = $record['rows'];
			$coverage = $record['coverage'];
			unset($record);
			$valid = [];
			foreach ($coverage as $span) {
				$ttl = $span[3] ? self::EMPTY_TTL : self::CLOSED_TTL;
				if ($span[2] <= $now && $now - $span[2] < $ttl) { $valid[] = [$span[0], $span[1]]; }
			}
			$missing = $force ? [[$from, $to]] : self::missing($from, $to, $valid);
			// Always refresh the mutable tail, including overlap: late values can arrive behind the newest point.
			$mutable_from = $this->kind === 'trend' ? (int) floor(($now - 7200) / 3600) * 3600 : $now - self::REFRESH_OVERLAP;
			if ($to >= $mutable_from) { $missing[] = [max($from, $mutable_from), $to]; }
			$missing = self::merge($missing);
			if ($missing === []) { $meta['hits']++; }
			else { $meta['misses']++; }
			foreach ($missing as [$start, $end]) {
				$fresh = $this->load($loader, $start, $end, $meta);
				// A refresh is authoritative, including deletions/empty ranges, not append-only.
				$rows = array_values(array_filter($rows, static fn(array $row): bool => $row['clock'] < $start || $row['clock'] > $end));
				array_push($rows, ...$fresh);
				$next = [];
				foreach ($coverage as $span) {
					if ($span[1] < $start || $span[0] > $end) { $next[] = $span; continue; }
					if ($span[0] < $start) { $next[] = [$span[0], $start - 1, $span[2], $span[3]]; }
					if ($span[1] > $end) { $next[] = [$end + 1, $span[1], $span[2], $span[3]]; }
				}
				$next[] = [$start, $end, $now, $fresh === []];
				$coverage = $next;
			}
			// Cached and freshly loaded rows were already validated and have disjoint timestamp ranges.
			// Sort their shallow merge without allocating another full set of sample arrays.
			usort($rows, static fn(array $a, array $b): int => ($a['clock'] <=> $b['clock']) ?: (($a['ns'] ?? 0) <=> ($b['ns'] ?? 0)));
			if ($missing !== []) {
				try { $this->write($file, ['schema' => self::SCHEMA, 'kind' => $this->kind, 'series' => $series, 'week' => $week, 'rows' => $rows, 'coverage' => $coverage]); }
				catch (HistoryCacheStorageException $e) { $meta['reason'] = $e->getMessage(); }
			}
			$selected = array_values(array_filter($rows, static fn(array $row): bool => $row['clock'] >= $from && $row['clock'] <= $to));
			foreach ($selected as $row) {
				$from_cache = true;
				foreach ($missing as [$start, $end]) { if ($row['clock'] >= $start && $row['clock'] <= $end) { $from_cache = false; break; } }
				if ($from_cache) { $meta['rows_cached']++; }
			}
			return $selected;
		}
		finally { flock($lock, LOCK_UN); fclose($lock); }
	}

	private function load(callable $loader, int $from, int $to, array &$meta): array {
		// Deliberately outside storage-error catches: a partial API read must never become cached coverage.
		$rows = $this->normalizeRows($loader($from, $to), $from, $to);
		$meta['rows_fetched'] += count($rows); $meta['fetched_ranges'][] = [$from, $to];
		return $rows;
	}

	private function normalizeRows($input, int $from, int $to): array {
		if (!is_array($input)) { throw new RuntimeException('History loader did not return a complete numeric row array.'); }
		$rows = [];
		foreach ($input as $row) {
			if (!is_array($row) || !isset($row['clock'])
					|| (!is_int($row['clock']) && (!is_string($row['clock']) || !ctype_digit($row['clock'])))) {
				throw new RuntimeException('History contains an invalid numeric sample.');
			}
			$clock = (int) $row['clock']; $ns = $this->kind === 'trend' ? 0 : ($row['ns'] ?? 0);
			if ((!is_int($ns) && (!is_string($ns) || !ctype_digit($ns)))
					|| (int) $ns < 0 || (int) $ns > 999999999 || $clock < $from || $clock > $to) {
				throw new RuntimeException('History contains an invalid sample timestamp.');
			}
			$sample = ['clock' => $clock];
			foreach ($this->kind === 'trend' ? ['value_min', 'value_avg', 'value_max'] : ['value'] as $field) {
				if (!isset($row[$field]) || !is_numeric($row[$field]) || strlen((string) $row[$field]) > 256 || !is_finite((float) $row[$field])) {
					throw new RuntimeException('History contains an invalid numeric sample.');
				}
				$sample[$field] = (string) $row[$field];
			}
			if ($this->kind === 'trend') {
				$num = $row['num'] ?? null;
				if ((!is_int($num) && (!is_string($num) || !ctype_digit($num))) || (int) $num < 1) {
					throw new RuntimeException('Trend history contains an invalid sample count.');
				}
				$sample['num'] = (int) $num;
			}
			else { $sample['ns'] = (int) $ns; }
			$rows[$clock.':'.(int) $ns] = $sample;
		}
		$rows = array_values($rows);
		usort($rows, static fn(array $a, array $b): int => ($a['clock'] <=> $b['clock']) ?: (($a['ns'] ?? 0) <=> ($b['ns'] ?? 0)));
		return $rows;
	}

	private static function merge(array $ranges): array {
		usort($ranges, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
		$out = [];
		foreach ($ranges as [$from, $to]) {
			$last = count($out) - 1;
			if ($last >= 0 && $from <= $out[$last][1] + 1) { $out[$last][1] = max($out[$last][1], $to); }
			else { $out[] = [$from, $to]; }
		}
		return $out;
	}

	private static function missing(int $from, int $to, array $coverage): array {
		$out = []; $cursor = $from;
		foreach (self::merge($coverage) as [$start, $end]) {
			if ($end < $cursor || $start > $to) { continue; }
			if ($start > $cursor) { $out[] = [$cursor, min($to, $start - 1)]; }
			$cursor = max($cursor, $end + 1);
			if ($cursor > $to) { break; }
		}
		if ($cursor <= $to) { $out[] = [$cursor, $to]; }
		return $out;
	}

	private function initialize(): void {
		if ($this->initialized) { return; }
		$this->initialized = true;
		if (!($this->options['enabled'] ?? true)) { $this->reason = 'disabled'; return; }
		try {
			if (PHP_OS_FAMILY !== 'Linux' || !function_exists('gzencode') || !function_exists('gzdecode')) {
				throw new HistoryCacheStorageException('secure_backend_unavailable');
			}
			$this->uid = function_exists('posix_geteuid') ? posix_geteuid() : (@stat('/proc/self')['uid'] ?? null);
			if (!is_int($this->uid)) { throw new HistoryCacheStorageException('process_owner_unavailable'); }
			$namespace = $this->options['namespace'] ?? getenv('SLA_REPORT_CACHE_NAMESPACE');
			if (!$namespace) {
				$db = $GLOBALS['DB'] ?? [];
				if (!is_array($db) || empty($db['TYPE']) || empty($db['DATABASE'])) {
					throw new HistoryCacheStorageException('installation_identity_unavailable');
				}
				$identity = [];
				foreach (['TYPE', 'SERVER', 'PORT', 'DATABASE', 'SCHEMA'] as $field) { $identity[$field] = (string) ($db[$field] ?? ''); }
				$namespace = json_encode($identity, JSON_THROW_ON_ERROR);
			}
			$base = rtrim((string) ($this->options['directory'] ?? getenv('SLA_REPORT_CACHE_DIR') ?: sys_get_temp_dir().'/zabbix-sla-history-'.$this->uid), '/');
			if ($base === '' || $base[0] !== '/' || is_link($base)) { throw new HistoryCacheStorageException('unsafe_cache_directory'); }
			$parent = realpath(dirname($base));
			if ($parent === false || $parent.'/'.basename($base) !== $base) { throw new HistoryCacheStorageException('unsafe_cache_directory'); }
			$web = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
			if ($web !== false && ($base === $web || strpos($base, rtrim($web, '/').'/') === 0)) {
				throw new HistoryCacheStorageException('cache_directory_in_webroot');
			}
			if (!is_dir($base) && !@mkdir($base, 0700)) { throw new HistoryCacheStorageException('cache_directory_unavailable'); }
			$this->checkPath($base, true);
			$directory = $base.'/'.hash('sha256', self::SCHEMA."\0".(realpath(dirname(__DIR__)) ?: dirname(__DIR__))."\0".(string) $namespace);
			if (!is_dir($directory) && !@mkdir($directory, 0700)) { throw new HistoryCacheStorageException('cache_directory_unavailable'); }
			$this->checkPath($directory, true);
			$this->directory = $directory;
		}
		catch (\Throwable $e) { $this->reason = $e instanceof HistoryCacheStorageException ? $e->getMessage() : 'cache_io_unavailable'; }
	}

	private function checkPath(string $path, bool $directory): void {
		clearstatcache(true, $path); $stat = @lstat($path);
		if ($stat === false || is_link($path) || $stat['uid'] !== $this->uid
				|| ($stat['mode'] & 0777) !== ($directory ? 0700 : 0600)
				|| ($directory ? !is_dir($path) : !is_file($path))) {
			throw new HistoryCacheStorageException('unsafe_cache_permissions');
		}
	}

	private function lock(string $path) {
		if (!file_exists($path) && !is_link($path)) {
			$old = umask(0077); $handle = @fopen($path, 'x+b'); umask($old);
			if (is_resource($handle)) { fclose($handle); }
		}
		$this->checkPath($path, false);
		$handle = @fopen($path, 'r+b');
		if ($handle === false) { throw new HistoryCacheStorageException('cache_lock_unavailable'); }
		if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); throw new HistoryCacheStorageException('cache_busy_live_fallback'); }
		return $handle;
	}

	private function read(string $file, string $series, int $week): array {
		$empty = ['rows' => [], 'coverage' => []];
		if (!file_exists($file) && !is_link($file)) { return $empty; }
		$this->checkPath($file, false);
		try {
			$size = @filesize($file);
			if ($size === false || $size > self::MAX_FILE_BYTES) { return $empty; }
			$compressed = @file_get_contents($file, false, null, 0, self::MAX_FILE_BYTES + 1);
			if ($compressed === false || strlen($compressed) > self::MAX_FILE_BYTES) { return $empty; }
			$json = @gzdecode($compressed, self::MAX_JSON_BYTES);
			if ($json === false) { return $empty; }
			$data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
			if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA || ($data['kind'] ?? '') !== $this->kind || ($data['series'] ?? '') !== $series
					|| ($data['week'] ?? null) !== $week || !is_array($data['rows'] ?? null)
					|| count($data['rows']) > self::MAX_ROWS_PER_SHARD || !is_array($data['coverage'] ?? null)
					|| count($data['coverage']) > 8192) { return $empty; }
			foreach ($data['coverage'] as $span) {
				if (!is_array($span) || count($span) !== 4 || !is_int($span[0]) || !is_int($span[1])
						|| !is_int($span[2]) || !is_bool($span[3]) || $span[0] < max(0, $week)
						|| $span[1] >= $week + self::SHARD_SECONDS || $span[0] > $span[1] || $span[2] < 0) { return $empty; }
			}
			$data['rows'] = $this->normalizeRows($data['rows'], max(0, $week), $week + self::SHARD_SECONDS - 1);
			return $data;
		}
		catch (\Throwable $e) { return $empty; }
	}

	private function write(string $file, array $data): void {
		if (count($data['rows']) > self::MAX_ROWS_PER_SHARD || count($data['coverage']) > 8192) {
			throw new HistoryCacheStorageException('cache_shard_limit_live_result');
		}
		$json = json_encode($data, JSON_THROW_ON_ERROR);
		if (strlen($json) > self::MAX_JSON_BYTES) { throw new HistoryCacheStorageException('cache_shard_limit_live_result'); }
		$compressed = gzencode($json, 1);
		if ($compressed === false || strlen($compressed) > self::MAX_FILE_BYTES) { throw new HistoryCacheStorageException('cache_shard_limit_live_result'); }
		// Quota lock is held only during the bounded scan/publication, never during a history API call.
		$quota = $this->lock($this->directory.'/quota.lock');
		try { $this->publish($file, $compressed); }
		finally { flock($quota, LOCK_UN); fclose($quota); }
	}

	private function publish(string $file, string $compressed): void {
		$files = []; $bytes = 0; $count = 0;
		foreach (new \DirectoryIterator($this->directory) as $entry) {
			if ($entry->isDot()) { continue; }
			$count++;
			if ($count > self::MAX_DIRECTORY_FILES || $entry->isLink() || !$entry->isFile()) {
				throw new HistoryCacheStorageException('cache_directory_limit_live_result');
			}
			$this->checkPath($entry->getPathname(), false);
			if ($entry->getPathname() === $file) { continue; }
			// Interrupted writes and unrelated files still consume quota; only our shard files are evictable.
			$bytes += $entry->getSize();
			if (preg_match('/^[a-f0-9]{64}--?[0-9]+\.json\.gz$/D', $entry->getFilename())) {
				$files[] = [$entry->getMTime(), $entry->getPathname(), $entry->getSize()];
			}
		}
		usort($files, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
		while ($bytes + strlen($compressed) > self::MAX_DIRECTORY_BYTES || $count >= self::MAX_DIRECTORY_FILES) {
			$oldest = array_shift($files);
			if ($oldest === null || !@unlink($oldest[1])) { throw new HistoryCacheStorageException('cache_capacity_live_result'); }
			$bytes -= $oldest[2]; $count--;
		}
		$temp = $this->directory.'/.write-'.bin2hex(random_bytes(12));
		$old = umask(0077); $handle = @fopen($temp, 'x+b'); umask($old);
		if ($handle === false) { throw new HistoryCacheStorageException('cache_write_unavailable'); }
		try {
			$offset = 0;
			while ($offset < strlen($compressed)) {
				$written = fwrite($handle, substr($compressed, $offset));
				if ($written === false || $written === 0) { throw new HistoryCacheStorageException('cache_write_unavailable'); }
				$offset += $written;
			}
			if (!fflush($handle)) { throw new HistoryCacheStorageException('cache_write_unavailable'); }
			fclose($handle); $handle = null;
			if (!@rename($temp, $file)) { throw new HistoryCacheStorageException('cache_write_unavailable'); }
		}
		finally { if (is_resource($handle)) { fclose($handle); } if (is_file($temp)) { @unlink($temp); } }
	}
}

final class HistoryCacheStorageException extends RuntimeException {}
final class HistoryCacheMissException extends RuntimeException {}
