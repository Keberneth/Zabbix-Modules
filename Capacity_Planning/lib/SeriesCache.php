<?php

declare(strict_types=1);

namespace Modules\CapacityPlanning\Lib;

use DomainException;
use InvalidArgumentException;
use RuntimeException;

require_once __DIR__.'/Config.php';

/**
 * Permission-gated shared cache for anonymous numeric Zabbix series only.
 *
 * SECURITY CONTRACT:
 * - fetchRange() must be called only after a live, permission-filtered
 *   API::Item()->get() lookup; authorized=false is rejected before any cache
 *   path is resolved or opened.
 * - Payloads contain numeric item values and timestamps only. Host/item names,
 *   groups, macros, current state and final forecasts are never accepted.
 * - Storage is filesystem protected (0700 directories / 0600 files), not
 *   encrypted. If private ownership/permissions cannot be verified the cache
 *   fails closed and the supplied live loader is used instead.
 *
 * Restart invalidation uses the OS boot identity where available. Zabbix does
 * not expose a dependable server-service restart epoch to frontend modules, so
 * a service-only restart requires force=true or the explicit clear operation.
 */
final class SeriesCache {
	public const CACHE_SCHEMA = 1;
	public const MODULE_GENERATION = 'capacity-planning-1.3.0-series-1';

	private const MAX_RANGE_SECONDS = 730 * 86400;
	private const MAX_FUTURE_SKEW_SECONDS = 300;
	private const LOCK_WAIT_MICROSECONDS = 2000000;
	private const LOCK_POLL_MICROSECONDS = 50000;
	private const TREND_OVERLAP_SECONDS = 2 * 3600;
	private const HISTORY_OVERLAP_SECONDS = 15 * 60;
	private const MAX_STATUS_FILES = 10000;
	private const MAX_CLEAR_FILES = 10000;
	private const MAX_CLEANUP_EVICTIONS = 500;
	private const MAX_CACHE_FILE_BYTES = 16777216; // 16 MiB compressed.
	private const MAX_INFLATED_BYTES = 67108864; // 64 MiB JSON.
	private const MAX_ROWS_PER_SHARD = 100000;
	private const MAX_CACHE_ENTRIES = 100000;
	private const MAX_USAGE_SCAN_ENTRIES = 200000;
	private const USAGE_LEDGER_SCHEMA = 2;
	private const ALLOWED_KINDS = ['trend', 'history', 'recent_history', 'history_fallback'];

	private array $settings;
	private string $base_dir = '';
	private string $install_root = '';
	private string $generation_root = '';
	private array $runtime_generation;
	private ?int $effective_uid = null;
	private string $owner_identity_source = 'unavailable';
	private bool $storage_secure = false;
	private bool $backend_available = false;
	private bool $cleanup_attempted = false;
	private string $unavailable_reason = '';

	/**
	 * @param array $settings Either ['enabled'=>bool,'ttl_seconds'=>int] or the
	 *                        complete module configuration containing cache[].
	 */
	public function __construct(array $settings) {
		$cache = is_array($settings['cache'] ?? null) ? $settings['cache'] : $settings;
		$normalized = Config::normalize(['cache' => $cache]);
		$this->settings = $normalized['cache'];
		$this->runtime_generation = Config::runtimeGeneration();

		try {
			$this->initializeStorage();
		}
		catch (\Throwable $e) {
			$this->backend_available = false;
			$this->unavailable_reason = $e instanceof CacheSecurityException
				? $e->reason()
				: 'cache_io_unavailable';
		}
	}

	/**
	 * Load a range, using month shards for trends and UTC-day shards for raw
	 * history. The loader receives an inclusive missing/refresh range and must
	 * return numeric API-shaped rows:
	 *   trend:  clock,num,value_min,value_avg,value_max
	 *   history: clock,value[,ns]
	 *
	 * @return array{rows: array, cache: array}
	 */
	public function fetchRange(string $itemid, int $valueType, string $seriesKind, int $from, int $to,
			bool $authorized, callable $loader, bool $force = false): array {
		// Do not even derive a cache key for an item the current user cannot see.
		if (!$authorized) {
			throw new DomainException('Series cache access requires a live authorized item lookup.');
		}
		$this->validateRequest($itemid, $valueType, $seriesKind, $from, $to);

		if (!$this->settings['enabled'] || !$this->backend_available) {
			$range_incomplete = false;
			$incomplete_reason = '';
			try {
				$rows = $this->loadLive($loader, $seriesKind, $from, $to);
			}
			catch (SeriesRangeIncompleteException $e) {
				// An exhausted API budget is evidence about an incomplete range, not
				// evidence that the unqueried interval contains no values.
				$rows = $this->normalizeRows($e->rows(), $seriesKind, $from, $to);
				$range_incomplete = true;
				$incomplete_reason = $e->reason();
			}

			return [
				'rows' => $rows,
				'cache' => $this->resultMeta([
					'used' => false,
					'live_fallback' => true,
					'reason' => !$this->settings['enabled'] ? 'disabled' : $this->unavailable_reason,
					'range_incomplete' => $range_incomplete,
					'incomplete_reason' => $incomplete_reason
				])
			];
		}

		$series_key = hash('sha256', implode("\0", [
			(string) self::CACHE_SCHEMA, self::MODULE_GENERATION, $itemid, (string) $valueType, $seriesKind
		]));
		$segments = $this->calendarSegments($seriesKind, $from, $to);
		$all_rows = [];
		$hits = 0;
		$misses = 0;
		$writes = 0;
		$stale = false;
		$fetched_ranges = [];
		$cache_failed = false;
		$range_incomplete = false;
		$incomplete_reason = '';
		$incomplete_range = null;

		foreach ($segments as $segment) {
			try {
				if ($cache_failed) {
					$result = [
						'rows' => $this->loadLive($loader, $seriesKind, $segment['from'], $segment['to']),
						'hit' => false,
						'miss' => true,
						'written' => false,
						'stale' => false,
						'fetched_ranges' => [[$segment['from'], $segment['to']]]
					];
				}
				else {
					$result = $this->fetchShard(
						$series_key, $valueType, $seriesKind, $segment, $loader, $force
					);
					// A write failure returns the already-loaded rows once and disables
					// subsequent cache I/O for this request.
					$cache_failed = !$this->backend_available;
				}
			}
			catch (SeriesRangeIncompleteException $e) {
				foreach ($this->normalizeRows($e->rows(), $seriesKind, $segment['from'], $segment['to']) as $row) {
					$all_rows[] = $row;
				}
				$misses++;
				$range_incomplete = true;
				$incomplete_reason = $e->reason();
				$incomplete_range = [$segment['from'], $segment['to']];
				// Raw shards are newest-first. Stop here so an exhausted loader is
				// never called for older shards and no unqueried shard is cached empty.
				break;
			}
			catch (CacheSecurityException | CacheStorageException $e) {
				$this->backend_available = false;
				$this->unavailable_reason = $e instanceof CacheSecurityException
					? $e->reason()
					: 'cache_io_unavailable';
				$cache_failed = true;
				try {
					$result = [
						'rows' => $this->loadLive($loader, $seriesKind, $segment['from'], $segment['to']),
						'hit' => false,
						'miss' => true,
						'written' => false,
						'stale' => false,
						'fetched_ranges' => [[$segment['from'], $segment['to']]]
					];
				}
				catch (SeriesRangeIncompleteException $incomplete) {
					foreach ($this->normalizeRows(
						$incomplete->rows(), $seriesKind, $segment['from'], $segment['to']
					) as $row) {
						$all_rows[] = $row;
					}
					$misses++;
					$range_incomplete = true;
					$incomplete_reason = $incomplete->reason();
					$incomplete_range = [$segment['from'], $segment['to']];
					break;
				}
			}
			foreach ($result['rows'] as $row) {
				$all_rows[] = $row;
			}
			$hits += $result['hit'] ? 1 : 0;
			$misses += $result['miss'] ? 1 : 0;
			$writes += $result['written'] ? 1 : 0;
			$stale = $stale || $result['stale'];
			foreach ($result['fetched_ranges'] as $range) {
				$fetched_ranges[] = $range;
			}
		}

		$all_rows = $this->deduplicateAndSort($all_rows, $seriesKind);

		$result = [
			'rows' => $all_rows,
			'cache' => $this->resultMeta([
				'used' => $hits > 0 || $writes > 0,
				'hit' => !$range_incomplete && !$cache_failed && $misses === 0,
				'partial_hit' => $hits > 0 && ($misses > 0 || $range_incomplete),
				'shard_hits' => $hits,
				'shard_misses' => $misses,
				'shards_written' => $writes,
				'stale' => $stale,
				'fetched_ranges' => $fetched_ranges,
				'live_fallback' => $cache_failed,
				'reason' => $cache_failed ? $this->unavailable_reason : '',
				'range_incomplete' => $range_incomplete,
				'incomplete_reason' => $incomplete_reason,
				'incomplete_range' => $incomplete_range
			])
		];
		$this->maybeCleanup();

		return $result;
	}

	/** Public status intentionally omits the filesystem path. */
	public function publicStatus(): array {
		$files = 0;
		$bytes = 0;
		$status_scan_complete = true;
		try {
			if ($this->storage_secure && is_dir($this->install_root)) {
				$cache_files = $this->safeFiles($this->install_root, self::MAX_STATUS_FILES + 1);
				$status_scan_complete = count($cache_files) <= self::MAX_STATUS_FILES;
				foreach (array_slice($cache_files, 0, self::MAX_STATUS_FILES) as $file) {
					if ($this->isDataFile($file)) {
						$files++;
						$bytes += max(0, (int) @filesize($file));
					}
				}
			}
		}
		catch (\Throwable) {
			$status_scan_complete = false;
		}

		return [
			'enabled' => (bool) $this->settings['enabled'],
			'ttl_seconds' => (int) $this->settings['ttl_seconds'],
			'backend_available' => $this->backend_available,
			'unavailable_reason' => $this->backend_available ? '' : $this->unavailable_reason,
			'protection' => 'filesystem permissions (not encrypted)',
			'private_permissions_verified' => $this->storage_secure,
			'owner_identity_source' => $this->owner_identity_source,
			'hashed_filenames' => true,
			'cache_schema' => self::CACHE_SCHEMA,
			'module_generation' => self::MODULE_GENERATION,
			'boot_invalidation' => [
				'available' => (bool) ($this->runtime_generation['restart_safe'] ?? false),
				'source' => (string) ($this->runtime_generation['source'] ?? 'unavailable')
			],
			'zabbix_service_restart_detection' => 'manual refresh or clear required',
			'files' => $files,
			'bytes' => $bytes,
			'scan_complete' => $status_scan_complete,
			'max_bytes' => Config::cacheMaxBytes(),
			'max_cache_entries' => self::MAX_CACHE_ENTRIES,
			'quota_scope' => 'cache-owned payload bytes and entries',
			'quota_ledger_durability' => function_exists('fsync')
				? 'fsync' : 'fflush with boot-bound rescan'
		];
	}

	/** Clear all cache generations for this Zabbix installation. */
	public function clear(): array {
		if (!$this->storage_secure || $this->install_root === '' || !is_dir($this->install_root)) {
			return ['ok' => false, 'removed_files' => 0, 'reason' => $this->unavailable_reason ?: 'cache_unavailable'];
		}

		$clear_lock_path = $this->install_root.DIRECTORY_SEPARATOR.'.clear.lock';
		$clear_lock = $this->acquireLock($clear_lock_path, LOCK_EX);
		if ($clear_lock === null) {
			return ['ok' => false, 'removed_files' => 0, 'reason' => 'cache_busy'];
		}

		try {
			$this->invalidateUsageLedger();
			$files = $this->safeFiles($this->install_root, self::MAX_CLEAR_FILES + 1, true);
			$complete = count($files) <= self::MAX_CLEAR_FILES;
			$removed = 0;
			foreach (array_slice($files, 0, self::MAX_CLEAR_FILES) as $file) {
				if ($file === $clear_lock_path) {
					continue;
				}
				if (is_file($file) && !is_link($file) && @unlink($file)) {
					$removed++;
				}
			}
			$complete = $this->removeEmptyDirectories($this->install_root, self::MAX_CLEAR_FILES)
				&& $complete;
			$this->ensurePrivateDirectory($this->install_root);

			return [
				'ok' => $complete,
				'removed_files' => $removed,
				'reason' => $complete ? '' : 'clear_limit_reached',
				'complete' => $complete
			];
		}
		finally {
			@flock($clear_lock, LOCK_UN);
			@fclose($clear_lock);
		}
	}

	public static function clearAll(): array {
		return (new self(Config::cacheSettings()))->clear();
	}

	private function fetchShard(string $series_key, int $value_type, string $series_kind, array $segment,
			callable $loader, bool $force): array {
		$clear_guard = $this->acquireLock($this->install_root.DIRECTORY_SEPARATOR.'.clear.lock', LOCK_SH);
		if ($clear_guard === null) {
			throw new CacheStorageException('Cache clear is in progress.');
		}
		try {
		$directory = $this->generation_root.DIRECTORY_SEPARATOR.substr($series_key, 0, 2)
			.DIRECTORY_SEPARATOR.$series_key;
		// Do not create per-series directories or per-shard lock files before a
		// publish has passed the global quota reservation. Existing paths are
		// still checked for symlink substitution before a cache read.
		$this->assertNoSymlinkComponents($directory);
		$shard_hash = hash('sha256', $segment['id']);
		$path = $directory.DIRECTORY_SEPARATOR.$shard_hash.'.json.gz';
		// A fixed 256-lock stripe prevents distinct over-quota requests from
		// leaving an unbounded number of persistent lock files/inodes.
		$lock_stripe = substr(hash('sha256', $series_key."\0".$segment['id']), 0, 2);
		$lock_path = $this->install_root.DIRECTORY_SEPARATOR.'.shard-lock-'.$lock_stripe.'.lock';
		$state = $this->readShard($path, $series_key, $value_type, $series_kind, $segment);
		$plan = $this->refreshPlan($state, $segment, $series_kind, $force);

		if (!$plan) {
			return [
				'rows' => $this->rowsInRange($state['rows'], $segment['from'], $segment['to']),
				'hit' => true, 'miss' => false, 'written' => false, 'stale' => false, 'fetched_ranges' => []
			];
		}

		$lock = $this->acquireLock($lock_path);
		if ($lock === null) {
			if ($this->stateUsable($state, $segment, $series_kind)) {
				return [
					'rows' => $this->rowsInRange($state['rows'], $segment['from'], $segment['to']),
					'hit' => true, 'miss' => false, 'written' => false, 'stale' => true, 'fetched_ranges' => []
				];
			}

			$rows = $this->loadLive($loader, $series_kind, $segment['from'], $segment['to']);
			return [
				'rows' => $rows, 'hit' => false, 'miss' => true, 'written' => false,
				'stale' => false, 'fetched_ranges' => [[$segment['from'], $segment['to']]]
			];
		}

		try {
			// Another worker may have filled the shard while this request waited.
			$state = $this->readShard($path, $series_key, $value_type, $series_kind, $segment);
			$plan = $this->refreshPlan($state, $segment, $series_kind, $force);
			if (!$plan) {
				return [
					'rows' => $this->rowsInRange($state['rows'], $segment['from'], $segment['to']),
					'hit' => true, 'miss' => false, 'written' => false, 'stale' => false,
					'fetched_ranges' => []
				];
			}

			$fetched_ranges = [];
			foreach ($plan as [$range_from, $range_to]) {
				try {
					$new_rows = $this->loadLive($loader, $series_kind, $range_from, $range_to);
				}
				catch (SeriesRangeIncompleteException $e) {
					$partial_rows = $this->normalizeRows(
						$e->rows(), $series_kind, $range_from, $range_to
					);
					$combined = $this->replaceRange(
						$state['rows'], $partial_rows, $range_from, $range_to, $series_kind
					);
					throw new SeriesRangeIncompleteException(
						$this->rowsInRange($combined, $segment['from'], $segment['to']),
						$e->reason()
					);
				}
				catch (\Throwable $e) {
					if ($this->stateUsable($state, $segment, $series_kind)) {
						return [
							'rows' => $this->rowsInRange($state['rows'], $segment['from'], $segment['to']),
							'hit' => true, 'miss' => false, 'written' => false, 'stale' => true,
							'fetched_ranges' => []
						];
					}
					throw $e;
				}
				$state['rows'] = $this->replaceRange($state['rows'], $new_rows, $range_from, $range_to,
					$series_kind);
				$state['covered_from'] = $state['covered_from'] === null
					? $range_from : min($state['covered_from'], $range_from);
				$state['covered_to'] = $state['covered_to'] === null
					? $range_to : max($state['covered_to'], $range_to);
				$state['refreshed_at'] = time();
				$fetched_ranges[] = [$range_from, $range_to];
			}

			try {
				$this->writeShard($path, $state, $series_key, $value_type, $series_kind, $segment['id']);
			}
			catch (CacheSecurityException | CacheStorageException | CacheCapacityException | \JsonException $e) {
				// The live loader has already succeeded. Return those rows once rather
				// than retrying it merely because the optional cache write failed.
				$this->backend_available = false;
				$this->unavailable_reason = $e instanceof CacheSecurityException
					? $e->reason()
					: ($e instanceof CacheCapacityException ? $e->reason() : 'cache_io_unavailable');

				return [
					'rows' => $this->rowsInRange($state['rows'], $segment['from'], $segment['to']),
					'hit' => false, 'miss' => true, 'written' => false, 'stale' => false,
					'fetched_ranges' => $fetched_ranges
				];
			}

			return [
				'rows' => $this->rowsInRange($state['rows'], $segment['from'], $segment['to']),
				'hit' => false, 'miss' => true, 'written' => true, 'stale' => false,
				'fetched_ranges' => $fetched_ranges
			];
		}
		finally {
			@flock($lock, LOCK_UN);
			@fclose($lock);
		}
		}
		finally {
			@flock($clear_guard, LOCK_UN);
			@fclose($clear_guard);
		}
	}

	private function refreshPlan(array $state, array $segment, string $series_kind, bool $force): array {
		if ($force || $state['covered_from'] === null || $state['covered_to'] === null) {
			return [[$segment['from'], $segment['to']]];
		}

		$plan = [];
		if ($state['covered_from'] > $segment['from']) {
			$plan[] = [$segment['from'], min($segment['to'], $state['covered_from'] - 1)];
		}

		$is_mutable = $segment['shard_to'] >= time();
		$ttl = (int) $this->settings['ttl_seconds'];
		$fresh = $ttl > 0 && time() - (int) $state['refreshed_at'] < $ttl;
		$prefix_backfill = $plan !== [];
		if ($state['covered_to'] < $segment['to']
				&& (!$is_mutable || !$fresh || $prefix_backfill)) {
			$overlap = $series_kind === 'trend' ? self::TREND_OVERLAP_SECONDS : self::HISTORY_OVERLAP_SECONDS;
			$plan[] = [max($segment['from'], $state['covered_to'] - $overlap), $segment['to']];
		}
		elseif ($is_mutable && !$fresh) {
			$overlap = $series_kind === 'trend' ? self::TREND_OVERLAP_SECONDS : self::HISTORY_OVERLAP_SECONDS;
			$refresh_tail = min((int) $state['covered_to'], (int) $segment['to']);
			$plan[] = [max($segment['from'], $refresh_tail - $overlap), $segment['to']];
		}

		return $this->mergeRanges($plan);
	}

	private function stateUsable(array $state, array $segment, string $series_kind): bool {
		if ($state['covered_from'] === null || $state['covered_to'] === null
				|| $state['covered_from'] > $segment['from']) {
			return false;
		}
		if ($state['covered_to'] >= $segment['to']) {
			return true;
		}

		$ttl = (int) $this->settings['ttl_seconds'];
		return $segment['shard_to'] >= time() && $ttl > 0
			&& time() - (int) $state['refreshed_at'] < $ttl;
	}

	private function loadLive(callable $loader, string $series_kind, int $from, int $to): array {
		$rows = $loader($from, $to);
		if (!is_array($rows)) {
			throw new RuntimeException('Series loader must return an array.');
		}

		return $this->normalizeRows($rows, $series_kind, $from, $to);
	}

	private function normalizeRows(array $rows, string $series_kind, int $from, int $to): array {
		$normalized = [];
		foreach ($rows as $row) {
			if (!is_array($row) || !isset($row['clock']) || !is_numeric($row['clock'])) {
				continue;
			}
			$clock = (int) $row['clock'];
			if ($clock < $from || $clock > $to || $clock <= 0) {
				continue;
			}

			if ($series_kind === 'trend') {
				$min = $this->finiteNumber($row['value_min'] ?? $row['min'] ?? null);
				$avg = $this->finiteNumber($row['value_avg'] ?? $row['avg'] ?? null);
				$max = $this->finiteNumber($row['value_max'] ?? $row['max'] ?? null);
				if ($min === null || $avg === null || $max === null) {
					continue;
				}
				$normalized[] = [
					'clock' => $clock,
					'num' => max(1, (int) ($row['num'] ?? 1)),
					'value_min' => $min,
					'value_avg' => $avg,
					'value_max' => $max
				];
			}
			else {
				$value = $this->finiteNumber($row['value'] ?? null);
				if ($value === null) {
					continue;
				}
				$normalized[] = [
					'clock' => $clock,
					'ns' => max(0, (int) ($row['ns'] ?? 0)),
					'value' => $value
				];
			}
		}

		return $this->deduplicateAndSort($normalized, $series_kind);
	}

	private function replaceRange(array $old_rows, array $new_rows, int $from, int $to,
			string $series_kind): array {
		$kept = array_values(array_filter($old_rows,
			static fn (array $row): bool => (int) $row['clock'] < $from || (int) $row['clock'] > $to));

		return $this->deduplicateAndSort(array_merge($kept, $new_rows), $series_kind);
	}

	private function deduplicateAndSort(array $rows, string $series_kind): array {
		$indexed = [];
		foreach ($rows as $row) {
			$key = (string) $row['clock'];
			if ($series_kind !== 'trend') {
				$key .= ':'.(string) ($row['ns'] ?? 0);
			}
			$indexed[$key] = $row;
		}
		$rows = array_values($indexed);
		usort($rows, static fn (array $a, array $b): int =>
			((int) $a['clock'] <=> (int) $b['clock']) ?: ((int) ($a['ns'] ?? 0) <=> (int) ($b['ns'] ?? 0)));

		return $rows;
	}

	private function rowsInRange(array $rows, int $from, int $to): array {
		return array_values(array_filter($rows,
			static fn (array $row): bool => (int) $row['clock'] >= $from && (int) $row['clock'] <= $to));
	}

	private function calendarSegments(string $series_kind, int $from, int $to): array {
		$segments = [];
		$cursor = $from;
		while ($cursor <= $to) {
			if ($series_kind === 'trend') {
				$year = (int) gmdate('Y', $cursor);
				$month = (int) gmdate('n', $cursor);
				$shard_from = gmmktime(0, 0, 0, $month, 1, $year);
				$next = $month === 12
					? gmmktime(0, 0, 0, 1, 1, $year + 1)
					: gmmktime(0, 0, 0, $month + 1, 1, $year);
				$id = gmdate('Y-m', $shard_from);
			}
			else {
				$shard_from = (int) (floor($cursor / 86400) * 86400);
				$next = $shard_from + 86400;
				$id = gmdate('Y-m-d', $shard_from);
			}
			$shard_to = $next - 1;
			$segment_to = min($to, $shard_to);
			$segments[] = [
				'id' => $id,
				'from' => max($from, $shard_from),
				'to' => $segment_to,
				'shard_from' => $shard_from,
				'shard_to' => $shard_to
			];
			$cursor = $segment_to + 1;
		}

		// Raw-history APIs are safety-capped. Newest-first shard processing
		// preserves the evidence nearest to now if a caller reaches that cap.
		return $series_kind === 'trend' ? $segments : array_reverse($segments);
	}

	private function mergeRanges(array $ranges): array {
		$ranges = array_values(array_filter($ranges,
			static fn (array $range): bool => $range[0] <= $range[1]));
		usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
		$merged = [];
		foreach ($ranges as $range) {
			$last = count($merged) - 1;
			if ($last >= 0 && $range[0] <= $merged[$last][1] + 1) {
				$merged[$last][1] = max($merged[$last][1], $range[1]);
			}
			else {
				$merged[] = $range;
			}
		}

		return $merged;
	}

	private function readShard(string $path, string $series_key, int $value_type, string $series_kind,
			array $segment): array {
		$empty = ['covered_from' => null, 'covered_to' => null, 'refreshed_at' => 0, 'rows' => []];
		if (!is_file($path)) {
			return $empty;
		}
		$this->assertPrivateFile($path);
		$size = @filesize($path);
		if ($size === false || $size < 1 || $size > self::MAX_CACHE_FILE_BYTES) {
			throw new CacheSecurityException('cache_file_size_invalid');
		}
		$encoded = @file_get_contents($path);
		if ($encoded === false) {
			return $empty;
		}
		$json = function_exists('gzdecode') ? @gzdecode($encoded, self::MAX_INFLATED_BYTES + 1) : false;
		if ($json === false || strlen($json) > self::MAX_INFLATED_BYTES) {
			return $empty;
		}
		try {
			$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		}
		catch (\JsonException) {
			return $empty;
		}
		if (!is_array($data) || ($data['schema'] ?? null) !== self::CACHE_SCHEMA
				|| ($data['module_generation'] ?? '') !== self::MODULE_GENERATION
				|| !hash_equals($series_key, (string) ($data['series_fingerprint'] ?? ''))
				|| ($data['series_kind'] ?? '') !== $series_kind
				|| (int) ($data['value_type'] ?? -1) !== $value_type
				|| ($data['shard'] ?? '') !== $segment['id']) {
			return $empty;
		}
		$covered_from = is_numeric($data['covered_from'] ?? null) ? (int) $data['covered_from'] : null;
		$covered_to = is_numeric($data['covered_to'] ?? null) ? (int) $data['covered_to'] : null;
		$refreshed_at = max(0, (int) ($data['refreshed_at'] ?? 0));
		$rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
		if (count($rows) > self::MAX_ROWS_PER_SHARD
				|| $covered_from === null || $covered_to === null || $covered_from > $covered_to
				|| $covered_from < $segment['shard_from'] || $covered_to > $segment['shard_to']
				|| $refreshed_at <= 0 || $refreshed_at > time() + 300) {
			return $empty;
		}

		return [
			'covered_from' => $covered_from,
			'covered_to' => $covered_to,
			'refreshed_at' => $refreshed_at,
			'rows' => $this->normalizeRows($rows, $series_kind,
				$segment['shard_from'], $segment['shard_to'])
		];
	}

	private function writeShard(string $path, array $state, string $series_key, int $value_type, string $series_kind,
			string $shard_id): void {
		if (count($state['rows']) > self::MAX_ROWS_PER_SHARD) {
			throw new CacheStorageException('Series cache shard exceeds the safe row limit.');
		}
		$payload = [
			'schema' => self::CACHE_SCHEMA,
			'module_generation' => self::MODULE_GENERATION,
			'series_fingerprint' => $series_key,
			'series_kind' => $series_kind,
			'value_type' => $value_type,
			'shard' => $shard_id,
			'covered_from' => $state['covered_from'],
			'covered_to' => $state['covered_to'],
			'refreshed_at' => $state['refreshed_at'],
			'rows' => $state['rows']
		];
		$json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		if (strlen($json) > self::MAX_INFLATED_BYTES) {
			throw new CacheStorageException('Series cache payload exceeds the safe JSON size limit.');
		}
		$encoded = function_exists('gzencode') ? gzencode($json, 6) : false;
		if ($encoded === false) {
			throw new CacheStorageException('Unable to encode protected series cache payload.');
		}
		if (strlen($encoded) > self::MAX_CACHE_FILE_BYTES) {
			throw new CacheStorageException('Series cache payload exceeds the safe file size limit.');
		}
		if (is_link($path)) {
			throw new CacheSecurityException('cache_symlink_rejected');
		}
		$quota_lock = $this->acquireLock(
			$this->install_root.DIRECTORY_SEPARATOR.'.quota.lock',
			LOCK_EX
		);
		if ($quota_lock === null) {
			throw new CacheStorageException('Unable to acquire the cache quota lock.');
		}
		$tmp = $path.'.tmp.'.bin2hex(random_bytes(8));
		$old_umask = umask(0077);
		try {
			$usage = $this->loadUsageState();
			$old_size = 0;
			$old_exists = is_file($path);
			if ($old_exists) {
				$this->assertPrivateFile($path);
				$measured = @filesize($path);
				if ($measured === false || $measured < 0) {
					throw new CacheStorageException('Unable to measure the existing cache shard.');
				}
				$old_size = (int) $measured;
				if ($usage['bytes'] < $old_size || $usage['files'] < 1) {
					$usage = $this->scanUsageState();
					$this->writeUsageLedger($usage);
				}
			}

			$new_size = strlen($encoded);
			// Atomic replacement temporarily retains the old shard while the new
			// temp file exists. Reserve its bytes plus every directory/file entry
			// that this publish can create before touching the series directory.
			$prefix_directory = dirname(dirname($path));
			$series_directory = dirname($path);
			$missing_directories = (is_dir($prefix_directory) ? 0 : 1)
				+ (is_dir($series_directory) ? 0 : 1);
			$max_bytes = Config::cacheMaxBytes();
			if ($usage['bytes'] > $max_bytes || $new_size > $max_bytes - $usage['bytes']
					|| $usage['files'] > self::MAX_CACHE_ENTRIES - $missing_directories - 1) {
				throw new CacheCapacityException('cache_capacity_reached');
			}
			$peak_bytes = $usage['bytes'] + $new_size;
			$peak_files = $usage['files'] + $missing_directories + 1;
			$this->writeUsageLedger(['bytes' => $peak_bytes, 'files' => $peak_files]);
			$this->ensurePrivateDirectory(dirname($path));

			if (@file_put_contents($tmp, $encoded, LOCK_EX) === false || !@chmod($tmp, 0600)) {
				throw new CacheStorageException('Unable to write protected series cache payload.');
			}
			$this->assertPrivateFile($tmp);
			if (!@rename($tmp, $path)) {
				throw new CacheStorageException('Unable to publish series cache payload atomically.');
			}
			@chmod($path, 0600);
			$this->assertPrivateFile($path);
			$this->writeUsageLedger([
				// Never reduce the ledger during replacement. This remains safe if
				// an abrupt OS stop persists the ledger rename but not the shard
				// replacement; advisory cleanup later reconciles conservative excess.
				'bytes' => max($usage['bytes'], $usage['bytes'] - $old_size + $new_size),
				'files' => $usage['files'] + $missing_directories + ($old_exists ? 0 : 1)
			]);
		}
		finally {
			umask($old_umask);
			if (is_file($tmp) && !is_link($tmp)) {
				@unlink($tmp);
			}
			@flock($quota_lock, LOCK_UN);
			@fclose($quota_lock);
		}
	}

	/** @return array{bytes: int, files: int} */
	private function loadUsageState(): array {
		$path = $this->usageLedgerPath();
		if (is_link($path)) {
			throw new CacheSecurityException('cache_symlink_rejected');
		}
		if (is_file($path)) {
			$this->assertPrivateFile($path);
			$size = @filesize($path);
			$json = $size !== false && $size > 0 && $size <= 4096
				? @file_get_contents($path) : false;
			if ($json !== false) {
				try {
					$data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
				}
				catch (\JsonException) {
					$data = null;
				}
				$checksum_input = is_array($data) ? implode("\0", [
					(string) ($data['schema'] ?? ''),
					(string) ($data['bytes'] ?? ''),
					(string) ($data['files'] ?? ''),
					(string) ($data['updated_at'] ?? ''),
					(string) ($data['runtime_generation'] ?? '')
				]) : '';
				if (is_array($data) && ($data['schema'] ?? null) === self::USAGE_LEDGER_SCHEMA
						&& is_int($data['bytes'] ?? null) && $data['bytes'] >= 0
						&& is_int($data['files'] ?? null) && $data['files'] >= 0
						&& is_int($data['updated_at'] ?? null) && $data['updated_at'] > 0
						&& ($data['runtime_generation'] ?? '') === ($this->runtime_generation['id'] ?? '')
						&& is_string($data['checksum'] ?? null)
						&& hash_equals(hash('sha256', $checksum_input), $data['checksum'])) {
					return ['bytes' => $data['bytes'], 'files' => $data['files']];
				}
			}
		}

		$usage = $this->scanUsageState();
		$this->writeUsageLedger($usage);

		return $usage;
	}

	/** @return array{bytes: int, files: int} */
	private function scanUsageState(): array {
		$bytes = 0;
		$files = 0;
		$visited = 0;
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($this->install_root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ($iterator as $entry) {
			if (++$visited > self::MAX_USAGE_SCAN_ENTRIES) {
				// Persist a conservative over-quota sentinel so subsequent requests
				// fail closed without repeatedly walking an unexpectedly large tree.
				return [
					'bytes' => Config::cacheMaxBytes() + 1,
					'files' => self::MAX_CACHE_ENTRIES + 1
				];
			}
			if ($entry->isLink()) {
				throw new CacheSecurityException('cache_symlink_rejected');
			}
			$path = $entry->getPathname();
			$name = basename($path);
			if ($entry->isDir()) {
				$this->assertPrivateModeAndOwner($path, true);
				$files++;
			}
			elseif ($entry->isFile()) {
				$is_fixed_root_metadata = dirname($path) === $this->install_root && (
					$name === '.clear.lock' || $name === '.quota.lock' || $name === '.quota-state.json'
					|| preg_match('/^\.shard-lock-[a-f0-9]{2}\.lock$/', $name) === 1
				);
				if ($is_fixed_root_metadata) {
					continue;
				}
				$this->assertPrivateFile($path);
				$size = @filesize($path);
				if ($size === false || $size < 0) {
					throw new CacheStorageException('Unable to measure protected cache usage.');
				}
				$bytes += (int) $size;
				$files++;
			}
			else {
				continue;
			}
			if ($bytes > Config::cacheMaxBytes() || $files > self::MAX_CACHE_ENTRIES) {
				return ['bytes' => $bytes, 'files' => $files];
			}
		}

		return ['bytes' => $bytes, 'files' => $files];
	}

	private function usageLedgerPath(): string {
		return $this->install_root.DIRECTORY_SEPARATOR.'.quota-state.json';
	}

	/** @param array{bytes: int, files: int} $usage */
	private function writeUsageLedger(array $usage): void {
		$path = $this->usageLedgerPath();
		if (is_link($path)) {
			throw new CacheSecurityException('cache_symlink_rejected');
		}
		$ledger = [
			'schema' => self::USAGE_LEDGER_SCHEMA,
			'bytes' => max(0, (int) $usage['bytes']),
			'files' => max(0, (int) $usage['files']),
			'updated_at' => time(),
			'runtime_generation' => (string) ($this->runtime_generation['id'] ?? '')
		];
		$ledger['checksum'] = hash('sha256', implode("\0", [
			(string) $ledger['schema'],
			(string) $ledger['bytes'],
			(string) $ledger['files'],
			(string) $ledger['updated_at'],
			(string) $ledger['runtime_generation']
		]));
		$json = json_encode($ledger, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		$handle = null;
		$old_umask = umask(0077);
		try {
			// The quota lock serializes all readers/writers, so updating one stable
			// inode in place is safe. A torn ledger fails its checksum and is rebuilt
			// by an exact scan; its boot-bound generation also prevents trusting a
			// pre-restart ledger on PHP 8.0 where fsync() is unavailable.
			$handle = @fopen($path, 'c+b');
			if ($handle === false || !@chmod($path, 0600)) {
				throw new CacheStorageException('Unable to create the cache quota ledger.');
			}
			$this->assertPrivateFile($path);
			if (!@ftruncate($handle, 0) || !@rewind($handle)) {
				throw new CacheStorageException('Unable to reset the cache quota ledger.');
			}
			$offset = 0;
			$length = strlen($json);
			while ($offset < $length) {
				$written = @fwrite($handle, substr($json, $offset));
				if ($written === false || $written === 0) {
					throw new CacheStorageException('Unable to write the cache quota ledger.');
				}
				$offset += $written;
			}
			if (!@fflush($handle)) {
				throw new CacheStorageException('Unable to flush the cache quota ledger.');
			}
			if (function_exists('fsync') && !@fsync($handle)) {
				throw new CacheStorageException('Unable to sync the cache quota ledger.');
			}
			$this->assertPrivateFile($path);
		}
		finally {
			umask($old_umask);
			if (is_resource($handle)) {
				@fclose($handle);
			}
		}
	}

	private function acquireLock(string $path, int $operation = LOCK_EX, bool $wait = true) {
		if (is_link($path)) {
			throw new CacheSecurityException('cache_symlink_rejected');
		}
		$old_umask = umask(0077);
		$handle = @fopen($path, 'c');
		umask($old_umask);
		if ($handle === false || !@chmod($path, 0600)) {
			if (is_resource($handle)) {
				@fclose($handle);
			}
			return null;
		}
		$this->assertPrivateFile($path);
		$deadline = microtime(true) + self::LOCK_WAIT_MICROSECONDS / 1000000;
		do {
			if (@flock($handle, $operation | LOCK_NB)) {
				return $handle;
			}
			if (!$wait) {
				break;
			}
			usleep(self::LOCK_POLL_MICROSECONDS);
		} while (microtime(true) < $deadline);
		@fclose($handle);

		return null;
	}

	private function initializeStorage(): void {
		$this->base_dir = Config::cacheDirectory();
		$namespace = Config::installNamespace();
		if ($namespace === '') {
			throw new CacheSecurityException('install_namespace_unavailable');
		}
		if (empty($this->runtime_generation['restart_safe']) || ($this->runtime_generation['id'] ?? '') === '') {
			throw new CacheSecurityException('boot_identity_unavailable');
		}
		if (DIRECTORY_SEPARATOR === '\\') {
			throw new CacheSecurityException('posix_permissions_unavailable');
		}
		if (!function_exists('fileowner')) {
			throw new CacheSecurityException('owner_verification_unavailable');
		}
		$this->effective_uid = $this->detectEffectiveUid();
		if ($this->effective_uid === null) {
			throw new CacheSecurityException('owner_verification_unavailable');
		}
		$this->assertSafeRootPath($this->base_dir);
		$this->install_root = $this->base_dir.DIRECTORY_SEPARATOR.$namespace;
		$this->generation_root = $this->install_root.DIRECTORY_SEPARATOR
			.'schema-'.self::CACHE_SCHEMA.'-'.substr(hash('sha256', self::MODULE_GENERATION), 0, 12)
			.'-'.(string) $this->runtime_generation['id'];
		// Verify every cache-owned ancestor, not just the leaf. Otherwise an
		// existing group/world-readable install directory could expose the data.
		$this->ensurePrivateDirectory($this->base_dir);
		$this->ensurePrivateDirectory($this->install_root);
		$this->ensurePrivateDirectory($this->generation_root);
		// Re-resolve after creation so aliases and symlinked ancestors cannot
		// evade the pre-creation document-root check.
		$this->assertSafeRootPath((string) realpath($this->base_dir));
		$this->storage_secure = true;
		$this->backend_available = true;
	}

	private function assertSafeRootPath(string $path): void {
		if ($path === '' || strpos($path, "\0") !== false || $path[0] !== '/') {
			throw new CacheSecurityException('cache_path_unsafe');
		}
		$normalized = preg_replace('#/+#', '/', rtrim(str_replace('\\', '/', $path), '/')) ?: '';
		if ($normalized === '') {
			throw new CacheSecurityException('cache_path_unsafe');
		}
		foreach (explode('/', trim($normalized, '/')) as $component) {
			if ($component === '.' || $component === '..') {
				throw new CacheSecurityException('cache_path_unsafe');
			}
		}
		$normalized = $this->canonicalizePotentialPath($normalized);
		if (in_array($normalized, [
			'/', '/bin', '/boot', '/dev', '/etc', '/home', '/lib', '/lib64', '/media', '/mnt',
			'/opt', '/proc', '/root', '/run', '/sbin', '/srv', '/sys', '/tmp', '/usr', '/var',
			'/usr/local', '/usr/share', '/var/cache', '/var/lib', '/var/log', '/var/tmp'
		], true)) {
			throw new CacheSecurityException('cache_path_too_broad');
		}
		$module_root = dirname(__DIR__);
		$forbidden_roots = [$module_root, (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')];
		$modules_root = dirname($module_root);
		if (strtolower(basename($modules_root)) === 'modules') {
			$frontend_root = dirname($modules_root);
			if (strtolower(basename($frontend_root)) === 'local') {
				$frontend_root = dirname($frontend_root);
			}
			$forbidden_roots[] = $modules_root;
			$forbidden_roots[] = $frontend_root;
		}
		$configured_frontend_root = getenv('CAPACITY_PLANNING_FRONTEND_ROOT');
		if ($configured_frontend_root !== false && trim($configured_frontend_root) !== '') {
			$forbidden_roots[] = trim($configured_frontend_root);
		}
		foreach ($forbidden_roots as $forbidden) {
			$forbidden = $forbidden !== '' ? $this->canonicalizePotentialPath($forbidden) : '';
			if ($forbidden !== '' && $this->pathsOverlap($normalized, $forbidden)) {
				throw new CacheSecurityException('cache_path_overlaps_web_root');
			}
		}
		$this->assertNoSymlinkComponents($normalized);
	}

	private function pathsOverlap(string $left, string $right): bool {
		$left = rtrim(str_replace('\\', '/', $left), '/') ?: '/';
		$right = rtrim(str_replace('\\', '/', $right), '/') ?: '/';

		return $left === $right
			|| strpos($left.'/', $right.'/') === 0
			|| strpos($right.'/', $left.'/') === 0;
	}

	private function assertNoSymlinkComponents(string $path): void {
		$cursor = '/';
		foreach (array_values(array_filter(explode('/', trim($path, '/')), 'strlen')) as $part) {
			$cursor = rtrim($cursor, '/').'/'.$part;
			if (is_link($cursor)) {
				throw new CacheSecurityException('cache_symlink_rejected');
			}
		}
	}

	private function canonicalizePotentialPath(string $path): string {
		$path = preg_replace('#/+#', '/', rtrim(str_replace('\\', '/', $path), '/')) ?: '/';
		$tail = [];
		$probe = $path;
		while ($probe !== '/' && !file_exists($probe) && !is_link($probe)) {
			array_unshift($tail, basename($probe));
			$probe = dirname($probe);
		}
		$resolved = realpath($probe);
		if ($resolved === false) {
			throw new CacheSecurityException('cache_path_unresolvable');
		}
		$resolved = rtrim(str_replace('\\', '/', $resolved), '/');
		foreach ($tail as $component) {
			$resolved .= '/'.$component;
		}

		return $resolved === '' ? '/' : $resolved;
	}

	private function ensurePrivateDirectory(string $path): void {
		$this->assertNoSymlinkComponents($path);
		$old_umask = umask(0077);
		try {
			if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
				throw new CacheStorageException('Unable to create series cache directory.');
			}
			if (is_link($path) || !@chmod($path, 0700)) {
				throw new CacheSecurityException('cache_directory_not_private');
			}
		}
		finally {
			umask($old_umask);
		}
		$this->assertPrivateModeAndOwner($path, true);
	}

	private function assertPrivateFile(string $path): void {
		if (is_link($path) || !is_file($path)) {
			throw new CacheSecurityException('cache_file_unsafe');
		}
		$this->assertPrivateModeAndOwner($path, false);
	}

	private function detectEffectiveUid(bool $allow_posix = true): ?int {
		if ($allow_posix && function_exists('posix_geteuid')) {
			$uid = posix_geteuid();
			if (is_int($uid) && $uid >= 0) {
				$this->owner_identity_source = 'posix_geteuid';
				return $uid;
			}
		}

		$status_path = '/proc/self/status';
		if (is_file($status_path) && !is_link($status_path)) {
			$status = (string) @file_get_contents($status_path);
			if (preg_match('/^Uid:\s+\d+\s+(\d+)\s+/m', $status, $match) === 1) {
				$this->owner_identity_source = 'proc-self-status';
				return (int) $match[1];
			}
		}

		// POSIX ownership metadata is available in PHP core even when Alpine's
		// optional php-posix package is absent. A private, exclusively-created
		// probe gives us the owner identity used by this PHP process.
		$probe = rtrim(sys_get_temp_dir(), '/').'/capacity-owner-probe-'.bin2hex(random_bytes(8));
		$handle = null;
		$old_umask = umask(0077);
		try {
			$handle = @fopen($probe, 'x+b');
			if ($handle === false || !@chmod($probe, 0600)) {
				return null;
			}
			$stat = @fstat($handle);
			if (is_array($stat) && isset($stat['uid']) && is_numeric($stat['uid'])
					&& (int) $stat['uid'] >= 0) {
				$this->owner_identity_source = 'private-file-probe';
				return (int) $stat['uid'];
			}
		}
		finally {
			umask($old_umask);
			if (is_resource($handle)) {
				@fclose($handle);
			}
			if (is_file($probe) && !is_link($probe)) {
				@unlink($probe);
			}
		}

		return null;
	}

	private function assertPrivateModeAndOwner(string $path, bool $directory): void {
		$permissions = @fileperms($path);
		if ($permissions === false || (($permissions & 077) !== 0)) {
			throw new CacheSecurityException($directory
				? 'cache_directory_not_private' : 'cache_file_not_private');
		}
		$owner = @fileowner($path);
		if ($this->effective_uid === null || $owner === false || (int) $owner !== $this->effective_uid) {
			throw new CacheSecurityException('cache_owner_mismatch');
		}
	}

	private function validateRequest(string $itemid, int $value_type, string $series_kind, int $from,
			int $to): void {
		if ($itemid === '' || !ctype_digit($itemid)) {
			throw new InvalidArgumentException('Invalid item ID.');
		}
		if (!in_array($value_type, [0, 3], true)) {
			throw new InvalidArgumentException('Unsupported numeric item value type.');
		}
		if (!in_array($series_kind, self::ALLOWED_KINDS, true)) {
			throw new InvalidArgumentException('Unsupported series kind.');
		}
		if ($from <= 0 || $to <= 0 || $from >= $to || $to - $from > self::MAX_RANGE_SECONDS
				|| $to > time() + self::MAX_FUTURE_SKEW_SECONDS) {
			throw new InvalidArgumentException('Invalid series range.');
		}
	}

	private function finiteNumber($value): ?float {
		if (!is_numeric($value)) {
			return null;
		}
		$value = (float) $value;

		return is_finite($value) ? $value : null;
	}

	private function resultMeta(array $extra): array {
		return array_replace([
			'enabled' => (bool) $this->settings['enabled'],
			'backend_available' => $this->backend_available,
			'ttl_seconds' => (int) $this->settings['ttl_seconds'],
			'protection' => 'filesystem permissions (not encrypted)',
			'cache_schema' => self::CACHE_SCHEMA,
			'module_generation' => self::MODULE_GENERATION
		], $extra);
	}

	/** Opportunistic cleanup bounded by both file count and configured limits. */
	private function maybeCleanup(): void {
		if (!$this->storage_secure || $this->cleanup_attempted) {
			return;
		}
		$this->cleanup_attempted = true;

		$clear_guard = null;
		try {
			if (random_int(1, 50) !== 1) {
				return;
			}
			// The installation-wide exclusive guard ensures cleanup never runs
			// concurrently with a fetch (which holds the shared guard) or an
			// explicit clear operation.
			$clear_guard = $this->acquireLock(
				$this->install_root.DIRECTORY_SEPARATOR.'.clear.lock',
				LOCK_EX,
				false
			);
			if ($clear_guard === null) {
				return;
			}
			$now = time();
			$max_idle = Config::cacheMaxIdleSeconds();
			$files = $this->safeFiles($this->install_root, self::MAX_STATUS_FILES + 1);
			$scan_truncated = count($files) > self::MAX_STATUS_FILES;
			$entries = [];
			$total = 0;
			foreach (array_slice($files, 0, self::MAX_STATUS_FILES) as $file) {
				if (is_link($file)) {
					continue;
				}
				$mtime = max(0, (int) @filemtime($file));
				$size = max(0, (int) @filesize($file));
				if ($mtime > 0 && $now - $mtime > $max_idle
						&& $this->deleteDataFile($file)) {
					continue;
				}
				$entries[] = ['path' => $file, 'mtime' => $mtime, 'size' => $size];
				$total += $size;
			}

			$max_bytes = Config::cacheMaxBytes();
			if ($scan_truncated || $total > $max_bytes) {
				usort($entries, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
				$target = $scan_truncated ? 0 : (int) floor($max_bytes * 0.9);
				$evictions = 0;
				foreach ($entries as $entry) {
					if ((!$scan_truncated && $total <= $target)
							|| $evictions >= self::MAX_CLEANUP_EVICTIONS) {
						break;
					}
					if ($this->deleteDataFile($entry['path'])) {
						$total -= $entry['size'];
						$evictions++;
					}
				}
			}
			$this->cleanupAbandonedFiles($now);
			$this->invalidateUsageLedger();
			$this->removeEmptyDirectories($this->install_root);
		}
		catch (\Throwable) {
			// Cleanup is advisory and must never break a report request.
		}
		finally {
			if (is_resource($clear_guard)) {
				@flock($clear_guard, LOCK_UN);
				@fclose($clear_guard);
			}
		}
	}

	private function safeFiles(string $root, int $limit, bool $include_locks = false): array {
		$files = [];
		if (!is_dir($root) || is_link($root)) {
			return $files;
		}
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ($iterator as $entry) {
			if (count($files) >= $limit) {
				break;
			}
			if ($entry->isLink()) {
				continue;
			}
			$path = $entry->getPathname();
			if ($entry->isFile() && ($this->isDataFile($path) || $include_locks)) {
				$files[] = $path;
			}
		}

		return $files;
	}

	private function isDataFile(string $path): bool {
		return substr($path, -8) === '.json.gz';
	}

	/** Called only while holding the installation-wide exclusive clear guard. */
	private function deleteDataFile(string $data_file): bool {
		if (!$this->isDataFile($data_file) || is_link($data_file) || !is_file($data_file)) {
			return false;
		}

		return (bool) @unlink($data_file);
	}

	/**
	 * Remove abandoned temporary files. Root-level quota-state leftovers are
	 * checked every pass; shard publish leftovers are looked for in one random
	 * hash bucket per generation so each pass stays bounded. Shard locks are
	 * striped at the installation root and need no per-shard cleanup here.
	 */
	private function cleanupAbandonedFiles(int $now): void {
		$root_entries = new \DirectoryIterator($this->install_root);
		foreach ($root_entries as $entry) {
			if (!$entry->isDot() && !$entry->isLink() && $entry->isFile()
					&& strpos($entry->getFilename(), '.quota-state.json.tmp.') === 0
					&& $now - max(0, (int) $entry->getMTime()) > 3600) {
				@unlink($entry->getPathname());
			}
		}

		$bucket = sprintf('%02x', random_int(0, 255));
		$removed = 0;
		$generations = 0;
		$iterator = new \DirectoryIterator($this->install_root);
		foreach ($iterator as $generation) {
			if ($generation->isDot() || $generation->isLink() || !$generation->isDir()) {
				continue;
			}
			if (++$generations > 64) {
				break;
			}
			$bucket_root = $generation->getPathname().DIRECTORY_SEPARATOR.$bucket;
			if (!is_dir($bucket_root) || is_link($bucket_root)) {
				continue;
			}
			$files = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($bucket_root, \FilesystemIterator::SKIP_DOTS),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);
			foreach ($files as $entry) {
				if ($removed >= self::MAX_CLEANUP_EVICTIONS || $entry->isLink() || !$entry->isFile()) {
					continue;
				}
				$path = $entry->getPathname();
				if (strpos(basename($path), '.json.gz.tmp.') !== false
						&& $now - max(0, (int) @filemtime($path)) > 3600 && @unlink($path)) {
					$removed++;
				}
			}
		}
	}

	private function invalidateUsageLedger(): void {
		$path = $this->usageLedgerPath();
		if (is_file($path) && !is_link($path)) {
			@unlink($path);
		}
	}

	private function removeEmptyDirectories(string $root, int $limit = self::MAX_STATUS_FILES): bool {
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		$visited = 0;
		$complete = true;
		foreach ($iterator as $entry) {
			if (++$visited > $limit) {
				$complete = false;
				break;
			}
			if ($entry->isDir() && !$entry->isLink()) {
				@rmdir($entry->getPathname());
			}
		}

		return $complete;
	}
}

final class CacheSecurityException extends RuntimeException {
	private string $reason_code;

	public function __construct(string $reason_code) {
		$this->reason_code = $reason_code;
		parent::__construct($reason_code);
	}

	public function reason(): string {
		return $this->reason_code;
	}
}

final class CacheStorageException extends RuntimeException {
}

final class CacheCapacityException extends RuntimeException {
	private string $reason_code;

	public function __construct(string $reason_code) {
		$this->reason_code = $reason_code;
		parent::__construct($reason_code);
	}

	public function reason(): string {
		return $this->reason_code;
	}
}

/**
 * Loader signal for a range that was only partly queried (for example because
 * the per-request Zabbix history budget was exhausted). Carried rows are
 * normalized by SeriesCache, returned to the caller, and never persisted as a
 * complete shard.
 */
final class SeriesRangeIncompleteException extends RuntimeException {
	private array $partial_rows;
	private string $reason_code;

	public function __construct(array $partial_rows = [], string $reason_code = 'source_range_incomplete') {
		$this->partial_rows = $partial_rows;
		$this->reason_code = $reason_code !== '' ? $reason_code : 'source_range_incomplete';
		parent::__construct($this->reason_code);
	}

	public function rows(): array {
		return $this->partial_rows;
	}

	public function reason(): string {
		return $this->reason_code;
	}
}
