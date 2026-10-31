<?php declare(strict_types = 1);

require __DIR__.'/../helpers/HistoryCache.php';
use Modules\SlaUptimeReport\Helpers\HistoryCache;

$checks = 0;
function check(bool $value, string $message): void {
	global $checks;
	if (!$value) { throw new RuntimeException($message); }
	$checks++;
}
function rejected(callable $fn, string $message): void {
	try { $fn(); } catch (Throwable $e) { check(true, $message); return; }
	throw new RuntimeException($message);
}
function clean(string $directory): void {
	if (!is_dir($directory) || is_link($directory)) { return; }
	foreach (new DirectoryIterator($directory) as $entry) {
		if ($entry->isDot()) { continue; }
		if ($entry->isDir() && !$entry->isLink()) { clean($entry->getPathname()); }
		else { unlink($entry->getPathname()); }
	}
	rmdir($directory);
}

$root = sys_get_temp_dir().'/sla-history-test-'.bin2hex(random_bytes(6));
$now = 1760000000;
$options = ['directory' => $root, 'namespace' => 'test-instance', 'now' => static function() use (&$now): int { return $now; }];
$calls = [];
$samples = [];
$loader = static function(int $from, int $to) use (&$calls, &$samples): array {
	$calls[] = [$from, $to];
	return array_values(array_filter($samples, static fn(array $row): bool => $row['clock'] >= $from && $row['clock'] <= $to));
};
$week = (int) floor(($now - 345600) / HistoryCache::SHARD_SECONDS) * HistoryCache::SHARD_SECONDS + 345600;
$from = $week - 2 * HistoryCache::SHARD_SECONDS + 120;
try {
	$cache = new HistoryCache($options);
	rejected(static fn() => $cache->fetchRange('1', 3, $from, $from + 300, false, $loader), 'Unauthorized cache access must fail.');
	rejected(static fn() => $cache->tryCachedRange('1', 3, $from, $from + 300, false), 'Cache probes must not suppress authorization errors.');
	check(!file_exists($root) && $calls === [], 'Authorization failure performs no storage or history access.');
	check($cache->tryCachedRange('1', 3, $from, $from + 300, true) === null && glob($root.'/*/*.json.gz') === [],
		'A cold probe returns null without writing coverage or calling history.');
	$samples = [['clock' => $from + 30, 'value' => '200', 'ns' => 3, 'name' => 'must not persist'],
		['clock' => $from + 30, 'value' => '302', 'ns' => 4], ['clock' => $from + 180, 'value' => '500']];
	$first = $cache->fetchRange('1', 3, $from, $from + 300, true, $loader);
	check(count($first['rows']) === 3, 'Nanosecond identity preserves two readings within one second.');
	check($first['meta']['rows_fetched'] === 3 && $first['meta']['misses'] === 1, 'Cold load reports fetched samples.');
	if (PHP_OS_FAMILY !== 'Linux') {
		check(!$first['meta']['backend_available'], 'Unsupported platform uses live fallback.');
		echo "History cache: $checks checks passed (live fallback only on this platform).\n";
		exit;
	}
	check($first['meta']['backend_available'], 'Linux private cache is available.');
	$files = glob($root.'/*/*.json.gz');
	check(count($files) === 1 && (fileperms($files[0]) & 0777) === 0600, 'Shard has owner-only file permissions.');
	check((fileperms($root) & 0777) === 0700 && (fileperms(dirname($files[0])) & 0777) === 0700, 'Directories have owner-only permissions.');
	$payload = gzdecode(file_get_contents($files[0]));
	check(strpos($payload, 'must not persist') === false && strpos($payload, 'name') === false, 'Only numeric sample data reaches disk.');
	$calls = [];
	$warm = (new HistoryCache($options))->fetchRange('1', 3, $from, $from + 300, true, $loader);
	check($warm['rows'] === $first['rows'] && $calls === [], 'A new request reuses complete closed history without API calls.');
	check($warm['meta']['rows_cached'] === 3 && $warm['meta']['hits'] === 1, 'Warm load reports cached samples.');
	$probed = $cache->tryCachedRange('1', 3, $from, $from + 300, true);
	check($probed !== null && $probed['rows'] === $warm['rows'] && $probed['meta']['hits'] === 1 && $calls === [],
		'A warm probe returns the usual complete result and cache statistics.');
	check($cache->tryCachedRange('1', 3, $from, $from + 400, true) === null,
		'A partly covered probe never returns a partial hit.');
	$cache->fetchRange('1', 3, $from + 20, $from + 200, true, $loader);
	check($calls === [], 'Narrowing the period reuses covered data.');
	$cache->fetchRange('1', 3, $from - 30, $from + 400, true, $loader);
	check($calls === [[$from - 30, $from - 1], [$from + 301, $from + 400]], 'Expanding the range fetches only uncovered edges.');
	$calls = [];
	$cache->fetchRange('2', 3, $from, $from + 300, true, $loader);
	check(count($calls) === 1, 'Different items cannot share history.');
	$calls = [];
	$cache->fetchRange('1', 0, $from, $from + 300, true, $loader);
	check(count($calls) === 1, 'Float and unsigned history types are separate.');
	$calls = [];
	(new HistoryCache(array_replace($options, ['namespace' => 'another-instance'])))->fetchRange('1', 3, $from, $from + 300, true, $loader);
	check(count($calls) === 1, 'Different installations cannot share item IDs.');

	$calls = []; $empty_from = $from + 1000;
	$cache->fetchRange('5', 3, $empty_from, $empty_from + 100, true, $loader);
	$cache->fetchRange('5', 3, $empty_from, $empty_from + 100, true, $loader);
	check(count($calls) === 1, 'A completely queried empty range is briefly cached.');
	$now += 61;
	$samples[] = ['clock' => $empty_from + 50, 'value' => 200];
	check($cache->tryCachedRange('5', 3, $empty_from, $empty_from + 100, true) === null,
		'An expired empty range is a probe miss.');
	$refilled = $cache->fetchRange('5', 3, $empty_from, $empty_from + 100, true, $loader);
	check(count($calls) === 2 && count($refilled['rows']) === 1, 'Empty range expiry admits late/backfilled history.');

	$calls = []; $tail_start = $now - 1800;
	$samples = [['clock' => $tail_start + 10, 'value' => 200], ['clock' => $now - 200, 'value' => 200]];
	$cache->fetchRange('6', 3, $tail_start, $now, true, $loader);
	$calls = []; $now += 60;
	$samples[1]['value'] = 503; $samples[] = ['clock' => $now - 20, 'value' => 302];
	$tail = $cache->fetchRange('6', 3, $tail_start, $now, true, $loader);
	check($cache->tryCachedRange('6', 3, $tail_start, $now, true) === null,
		'A cached mutable tail still needs a live refresh.');
	check($calls === [[$now - 900, $now]], 'Moving window fetches just recent overlap and new tail.');
	check(array_column($tail['rows'], 'value') === ['200', '503', '302'], 'Authoritative tail refresh replaces old values and appends new samples.');
	check($tail['meta']['rows_cached'] === 1 && $tail['meta']['rows_fetched'] === 2, 'Warm/tail statistics match actual sources.');
	$samples = [$samples[0]]; $calls = [];
	$deleted = $cache->fetchRange('6', 3, $tail_start, $now, true, $loader);
	check(count($deleted['rows']) === 1, 'Empty refresh removes samples no longer returned by history.');

	$calls = [];
	$cache->fetchRange('1', 3, $from, $from + 300, true, $loader, true);
	check($calls === [[$from, $from + 300]], 'Force refresh bypasses cached coverage.');
	$calls = []; $now += 86401;
	$cache->fetchRange('2', 3, $from, $from + 300, true, $loader);
	check(count($calls) === 1, 'Closed history is refreshed after its bounded lifetime.');

	$calls = [];
	$bad = static function(int $start, int $end): array { throw new RuntimeException('partial scan'); };
	rejected(static fn() => $cache->fetchRange('7', 3, $from, $from + 300, true, $bad), 'Incomplete loader exceptions propagate.');
	$cache->fetchRange('7', 3, $from, $from + 300, true, $loader);
	check(count($calls) === 1, 'A partial scan was never marked as covered.');
	$calls = []; $files = glob($root.'/*/*.json.gz');
	foreach ($files as $file) { file_put_contents($file, 'corrupt'); }
	$cache->fetchRange('7', 3, $from, $from + 300, true, $loader);
	check(count($calls) === 1, 'Corrupt cache data causes a live reload.');

	$calls = []; $disabled_root = $root.'-disabled';
	$disabled = new HistoryCache(array_replace($options, ['directory' => $disabled_root, 'enabled' => false]));
	check($disabled->tryCachedRange('1', 3, $from, $from + 300, true) === null,
		'A disabled backend is a probe miss.');
	$disabled->fetchRange('1', 3, $from, $from + 300, true, $loader);
	check(count($calls) === 1 && !file_exists($disabled_root), 'Disabled cache performs no disk I/O and loads live.');
	$calls = []; chmod($root, 0755);
	$unsafe = (new HistoryCache($options))->fetchRange('1', 3, $from, $from + 300, true, $loader);
	check(!$unsafe['meta']['backend_available'] && count($calls) === 1, 'Unsafe cache permissions use live history.');
	chmod($root, 0700);
	$calls = [];
	$directory = $root.'/'.hash('sha256', HistoryCache::SCHEMA."\0".realpath(dirname(__DIR__))."\0test-instance");
	$series = hash('sha256', HistoryCache::SCHEMA."\0history\0".'1'."\0".'3');
	$shard_week = (int) floor(($from - 345600) / HistoryCache::SHARD_SECONDS) * HistoryCache::SHARD_SECONDS + 345600;
	$stripe = hexdec(substr(hash('sha256', $series."\0".$shard_week), 0, 2)) % 32;
	$other_item = 30;
	do {
		$other_series = hash('sha256', HistoryCache::SCHEMA."\0history\0".(string) $other_item."\0".'3');
		$other_stripe = hexdec(substr(hash('sha256', $other_series."\0".$shard_week), 0, 2)) % 32;
		if ($other_stripe !== $stripe) { break; }
		$other_item++;
	} while (true);
	$cache->fetchRange((string) $other_item, 3, $from, $from + 300, true, $loader);
	$calls = [];
	$lock = fopen($directory.'/shard-'.sprintf('%02d', $stripe).'.lock', 'r+b'); flock($lock, LOCK_EX | LOCK_NB);
	$other_warm = $cache->fetchRange((string) $other_item, 3, $from, $from + 300, true, $loader);
	check($calls === [] && $other_warm['meta']['hits'] === 1, 'A busy shard does not disable cached reads on another lock stripe.');
	$busy = $cache->fetchRange('1', 3, $from, $from + 300, true, $loader);
	flock($lock, LOCK_UN); fclose($lock);
	check(count($calls) === 1 && $busy['meta']['reason'] === 'cache_busy_live_fallback', 'A busy writer never blocks the report.');
	$calls = [];
	$boundary = $week - HistoryCache::SHARD_SECONDS;
	$cache->fetchRange('11', 3, $boundary - 120, $boundary + 120, true, $loader);
	check($calls === [[$boundary - 120, $boundary - 1], [$boundary, $boundary + 120]], 'UTC week boundaries produce complete disjoint inclusive ranges.');
	$calls = [];
	$cache->fetchRange('11', 3, $boundary - 60, $boundary + 180, true, $loader);
	check($calls === [[$boundary + 121, $boundary + 180]], 'Sliding a closed window only fetches its missing edge.');

	$trend_calls = [];
	$trend_rows = [['clock'=>$from + 60, 'num'=>'60', 'value_min'=>'0', 'value_avg'=>'0.75', 'value_max'=>'1', 'name'=>'secret name']];
	$trend_loader = static function(int $start, int $end) use (&$trend_calls, &$trend_rows): array {
		$trend_calls[] = [$start, $end];
		return array_values(array_filter($trend_rows, static fn(array $row): bool => $row['clock'] >= $start && $row['clock'] <= $end));
	};
	$trends = new HistoryCache($options + ['kind'=>'trend']);
	$trend_first = $trends->fetchRange('1', 3, $from, $from + 300, true, $trend_loader);
	check(count($trend_calls) === 1 && $trend_first['rows'][0]['value_avg'] === '0.75', 'Trend rows are independent from raw history for the same item.');
	check(!isset($trend_first['rows'][0]['name']) && $trend_first['rows'][0]['num'] === 60, 'Trend cache only retains numeric data and counts.');
	$trend_calls = [];
	$trend_warm = $trends->fetchRange('1', 3, $from, $from + 300, true, $trend_loader);
	check($trend_calls === [] && $trend_warm['rows'] === $trend_first['rows'], 'Completed trend ranges are reused without API calls.');
	$trend_rows = [['clock'=>$now - 3600, 'num'=>10, 'value_min'=>0, 'value_avg'=>0.5, 'value_max'=>1]];
	$trends->fetchRange('12', 3, $now - 10800, $now, true, $trend_loader);
	$trend_calls = []; $trend_rows[0]['num'] = 60;
	$updated = $trends->fetchRange('12', 3, $now - 10800, $now, true, $trend_loader);
	check($trend_calls === [[(int) floor(($now - 7200) / 3600) * 3600, $now]] && $updated['rows'][0]['num'] === 60, 'Trend refresh replaces the last two hours as aggregates settle.');
	rejected(static fn() => $trends->fetchRange('13', 3, $from, $from + 300, true,
		static fn(): array => [['clock'=>$from, 'num'=>0, 'value_min'=>0, 'value_avg'=>0, 'value_max'=>0]]), 'Invalid trend counts never become cached evidence.');

	$calls = [];
	$quota_file = $directory.'/quota-reserved';
	$quota = fopen($quota_file, 'x+b'); chmod($quota_file, 0600); ftruncate($quota, 268435456); fclose($quota);
	$samples = [['clock'=>$from + 30, 'value'=>200]];
	$quota_result = $cache->fetchRange('14', 3, $from, $from + 300, true, $loader);
	check(count($calls) === 1 && count($quota_result['rows']) === 1
		&& $quota_result['meta']['reason'] === 'cache_capacity_live_result', 'Cache capacity failure returns the completed live result without querying twice.');
	unlink($quota_file);
	$calls = [];
	$previous_webroot = $_SERVER['DOCUMENT_ROOT'] ?? null;
	$_SERVER['DOCUMENT_ROOT'] = sys_get_temp_dir();
	$webroot_result = (new HistoryCache(array_replace($options, ['directory'=>$root.'-webroot'])))->fetchRange('15', 3, $from, $from + 300, true, $loader);
	if ($previous_webroot === null) { unset($_SERVER['DOCUMENT_ROOT']); } else { $_SERVER['DOCUMENT_ROOT'] = $previous_webroot; }
	check(!$webroot_result['meta']['backend_available'] && $webroot_result['meta']['reason'] === 'cache_directory_in_webroot'
		&& !file_exists($root.'-webroot') && count($calls) === 1, 'Web-accessible storage is rejected before creating files.');

	$bulk_calls = 0;
	$bulk_loader = static function(int $start, int $end) use (&$bulk_calls): array {
		$bulk_calls++; $rows = [];
		for ($clock = $start; $clock <= $end; $clock++) { $rows[] = ['clock'=>$clock,'value'=>'18446744073709551615','ns'=>0]; }
		return $rows;
	};
	$bulk = $cache->fetchRange('20', 3, $from, $from + 29999, true, $bulk_loader);
	check(count($bulk['rows']) === 30000 && $bulk['meta']['reason'] === '', 'Maximum-size numeric shard writes successfully.');
	unset($bulk); $bulk_calls = 0;
	$bulk = $cache->fetchRange('20', 3, $from, $from + 29999, true, $bulk_loader);
	check(count($bulk['rows']) === 30000 && $bulk_calls === 0, 'Maximum-size shard reads from cache.');
	unset($bulk); $bulk_calls = 0;
	$bulk = $cache->fetchRange('20', 3, $from + 20000, $from + 39999, true, $bulk_loader);
	check(count($bulk['rows']) === 20000 && $bulk_calls === 1 && $bulk['meta']['reason'] === 'cache_shard_limit_live_result',
		'Oversized merged shard returns the requested complete range without another full normalization or API retry.');
	unset($bulk); $bulk_calls = 0;
	$bulk = $cache->fetchRange('20', 3, $from, $from + 29999, true, $bulk_loader, true);
	check(count($bulk['rows']) === 30000 && $bulk_calls === 1, 'Maximum-size shard refresh keeps complete results.');
	unset($bulk);
	check(memory_get_peak_usage(true) < 96 * 1024 * 1024, 'Maximum-size cache read, merge and refresh remain below 96 MiB in the standalone fixture.');
	echo "History cache: $checks checks passed.\n";
}
finally { clean($root); }
