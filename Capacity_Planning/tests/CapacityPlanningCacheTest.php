<?php

declare(strict_types=1);

// Defense in depth: these public-source tests are non-web-only and must never
// run if an operator accidentally deploys tests/ below a web-served path.
if (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'wasm'], true)) {
	http_response_code(404);
	exit;
}

require_once dirname(__DIR__).'/lib/Config.php';
require_once dirname(__DIR__).'/lib/SeriesCache.php';

use Modules\CapacityPlanning\Lib\Config;
use Modules\CapacityPlanning\Lib\SeriesCache;
use Modules\CapacityPlanning\Lib\SeriesRangeIncompleteException;

final class CapacityPlanningCacheTest {
	private int $assertions = 0;

	public function run(): void {
		$this->testConfigNormalization();
		$this->testExplicitRuntimeGeneration();
		$this->testRuntimeGenerationParsers();
		$this->testNamespaceFailsClosed();
		$this->testUnauthorizedRequestNeverCallsLoader();
		$this->testDisabledCacheUsesSanitizedLiveRows();
		$this->testIncompleteRangeIsTruthfulAndSanitized();
		$this->testFutureRangeRejectedAndRefreshTailBounded();
		$this->testWebRootOverlapPredicate();
		$this->testCalendarOrderAndPublicStatus();
		$this->testCalendarSegmentsCrossYear();
		$this->testEnabledCacheRoundTripAndIncrementalExtension();
		$this->testStorageSecurityFailClosed();
		$this->testBroadCacheDirectoryRejected();

		echo "CapacityPlanningCacheTest: {$this->assertions} assertions passed.\n";
	}

	private function testConfigNormalization(): void {
		$config = Config::normalize([
			'future_setting' => 'preserved',
			'cache' => ['enabled' => '0', 'ttl_seconds' => 123, 'future' => true]
		]);
		$this->same(false, $config['cache']['enabled']);
		$this->same(1800, $config['cache']['ttl_seconds']);
		$this->same(true, $config['cache']['future']);
		$this->same('preserved', $config['future_setting']);
		$this->same([0, 900, 1800, 3600], Config::ALLOWED_CACHE_TTLS);
	}

	private function testExplicitRuntimeGeneration(): void {
		$old = getenv('CAPACITY_PLANNING_BOOT_ID');
		putenv('CAPACITY_PLANNING_BOOT_ID=explicit-test-generation');
		try {
			$generation = Config::runtimeGeneration();
			$this->same('environment', $generation['source']);
			$this->same(true, $generation['restart_safe']);
			$this->same('boot-'.substr(hash('sha256', 'explicit-test-generation'), 0, 24), $generation['id']);
		}
		finally {
			$this->restoreEnvironment('CAPACITY_PLANNING_BOOT_ID', $old);
		}
	}

	private function testRuntimeGenerationParsers(): void {
		// The pid1 command name (proc field 2) may itself contain spaces and
		// parentheses; field counting must start after the LAST ')'.
		$stat = '1 (my daemon (v2)) S 0 1 1 0 -1 4194560 0 0 0 0 0 0 0 0 20 0 1 0 12345 178745344';
		$this->same('12345', Config::parsePid1StartTime($stat));
		$this->same('', Config::parsePid1StartTime('no parenthesized command'));
		$this->same('', Config::parsePid1StartTime(
			'1 (init) S 0 1 1 0 -1 4194560 0 0 0 0 0 0 0 0 20 0 1 0 abc 178745344'
		));

		$this->same('1a2b3c4d-1111-2222-3333-444455556666',
			Config::parseBootId("  1A2B3C4D-1111-2222-3333-444455556666\n"));
		$this->same('', Config::parseBootId('not!!a-valid-boot-id'));
		$this->same('', Config::parseBootId('abc-123'));

		$this->same('1700000000', Config::parseBtime("cpu  1 2 3\nbtime 1700000000\nprocesses 9"));
		$this->same('', Config::parseBtime("cpu  1 2 3\nprocesses 9"));
	}

	private function testNamespaceFailsClosed(): void {
		putenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		global $DB;
		$previous = $DB ?? null;
		$DB = [];
		$this->same('', Config::installNamespace());
		$DB = $previous;
	}

	private function testUnauthorizedRequestNeverCallsLoader(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$called = false;
		try {
			$cache->fetchRange('10001', 0, 'trend', 100, 200, false,
				static function () use (&$called): array {
					$called = true;
					return [];
				});
			$this->fail('Unauthorized cache request was accepted.');
		}
		catch (DomainException) {
			$this->same(false, $called);
		}
	}

	private function testDisabledCacheUsesSanitizedLiveRows(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$result = $cache->fetchRange('10001', 0, 'trend', 100, 200, true,
			static fn (int $from, int $to): array => [[
				'clock' => 150, 'num' => 2, 'value_min' => '1', 'value_avg' => '2',
				'value_max' => '3', 'host_name' => 'must not be cached or returned'
			]]);
		$this->same(false, $result['cache']['used']);
		$this->same(true, $result['cache']['live_fallback']);
		$this->same([
			'clock' => 150, 'num' => 2, 'value_min' => 1.0, 'value_avg' => 2.0, 'value_max' => 3.0
		], $result['rows'][0]);
	}

	private function testIncompleteRangeIsTruthfulAndSanitized(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$result = $cache->fetchRange('10001', 0, 'history', 100, 200, true,
			static function (): array {
				throw new SeriesRangeIncompleteException([[
					'clock' => 175,
					'ns' => 4,
					'value' => '7.5',
					'host_name' => 'must not be returned'
				]], 'history_budget_exhausted');
			});
		$this->same(true, $result['cache']['range_incomplete']);
		$this->same('history_budget_exhausted', $result['cache']['incomplete_reason']);
		$this->same(['clock' => 175, 'ns' => 4, 'value' => 7.5], $result['rows'][0]);
	}

	private function testFutureRangeRejectedAndRefreshTailBounded(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 0]);
		$called = false;
		try {
			$cache->fetchRange('10001', 0, 'history', time() - 3600, time() + 301, true,
				static function () use (&$called): array {
					$called = true;
					return [];
				});
			$this->fail('A future cache range was accepted.');
		}
		catch (InvalidArgumentException) {
			$this->same(false, $called);
		}

		$method = new ReflectionMethod($cache, 'refreshPlan');
		$method->setAccessible(true);
		$segment_to = time();
		$segment = [
			'from' => $segment_to - 3600,
			'to' => $segment_to,
			'shard_to' => $segment_to + 3600
		];
		$state = [
			'covered_from' => $segment['from'],
			'covered_to' => $segment_to + 86400,
			'refreshed_at' => 0,
			'rows' => []
		];
		$plan = $method->invoke($cache, $state, $segment, 'history', false);
		$this->same($segment_to - 900, $plan[0][0]);
		$this->same($segment_to, $plan[0][1]);

		$fresh_cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$fresh_method = new ReflectionMethod($fresh_cache, 'refreshPlan');
		$fresh_method->setAccessible(true);
		$fresh_segment = [
			'from' => $segment_to - 7200,
			'to' => $segment_to,
			'shard_to' => $segment_to + 3600
		];
		$fresh_state = [
			'covered_from' => $fresh_segment['from'] + 600,
			'covered_to' => $fresh_segment['to'] - 600,
			'refreshed_at' => time(),
			'rows' => []
		];
		$fresh_plan = $fresh_method->invoke($fresh_cache, $fresh_state, $fresh_segment, 'history', false);
		$this->same(2, count($fresh_plan));
		$this->same($fresh_segment['from'], $fresh_plan[0][0]);
		$this->same($fresh_segment['to'], $fresh_plan[1][1]);
	}

	private function testWebRootOverlapPredicate(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$method = new ReflectionMethod($cache, 'pathsOverlap');
		$method->setAccessible(true);
		$this->same(true, $method->invoke($cache, '/usr/share/zabbix/cache', '/usr/share/zabbix'));
		$this->same(true, $method->invoke($cache, '/usr/share', '/usr/share/zabbix'));
		$this->same(false, $method->invoke($cache, '/var/cache/zabbix-capacity', '/usr/share/zabbix'));
	}

	private function testCalendarOrderAndPublicStatus(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$method = new ReflectionMethod($cache, 'calendarSegments');
		$method->setAccessible(true);
		$from = gmmktime(0, 0, 0, 1, 30, 2026);
		$to = gmmktime(0, 0, 0, 2, 2, 2026);
		$history = $method->invoke($cache, 'history', $from, $to);
		$trend = $method->invoke($cache, 'trend', $from, $to);
		$this->same('2026-02-02', $history[0]['id']);
		$this->same('2026-01-30', $history[count($history) - 1]['id']);
		$this->same('2026-01', $trend[0]['id']);
		$this->same('2026-02', $trend[count($trend) - 1]['id']);
		$this->same('capacity-planning-1.3.0-series-1', SeriesCache::MODULE_GENERATION);
		$status = $cache->publicStatus();
		$this->same(false, array_key_exists('directory', $status));
		$this->same('filesystem permissions (not encrypted)', $status['protection']);
	}

	private function testCalendarSegmentsCrossYear(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$method = new ReflectionMethod($cache, 'calendarSegments');
		$method->setAccessible(true);
		$from = gmmktime(0, 0, 0, 12, 30, 2025);
		$to = gmmktime(0, 0, 0, 1, 2, 2026);

		$trend = $method->invoke($cache, 'trend', $from, $to);
		$this->same(2, count($trend));
		$this->same('2025-12', $trend[0]['id']);
		$this->same('2026-01', $trend[1]['id']);
		// The December shard must roll over into the next year, not month 13.
		$this->same(gmmktime(0, 0, 0, 1, 1, 2026) - 1, $trend[0]['shard_to']);
		$this->same(gmmktime(0, 0, 0, 1, 1, 2026), $trend[1]['shard_from']);
		$this->same($from, $trend[0]['from']);
		$this->same($to, $trend[1]['to']);

		$history = $method->invoke($cache, 'history', $from, $to);
		$this->same(['2026-01-02', '2026-01-01', '2025-12-31', '2025-12-30'],
			array_column($history, 'id'));
		$this->same($to, $history[0]['to']);
		$this->same($from, $history[3]['from']);
	}

	private function testEnabledCacheRoundTripAndIncrementalExtension(): void {
		$old_dir = getenv('CAPACITY_PLANNING_CACHE_DIR');
		$old_namespace = getenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		$old_boot = getenv('CAPACITY_PLANNING_BOOT_ID');
		$base = rtrim(sys_get_temp_dir(), "\\/").DIRECTORY_SEPARATOR
			.'capacity-planning-test-'.bin2hex(random_bytes(6));
		putenv('CAPACITY_PLANNING_CACHE_DIR='.$base);
		putenv('CAPACITY_PLANNING_CACHE_NAMESPACE=standalone-round-trip');
		putenv('CAPACITY_PLANNING_BOOT_ID=standalone-test-boot');

		try {
			$cache = new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]);
			if (!$cache->publicStatus()['backend_available']) {
				echo "CapacityPlanningCacheTest: enabled cache round trip skipped (POSIX backend unavailable).\n";
				return;
			}
			$uid_method = new ReflectionMethod($cache, 'detectEffectiveUid');
			$uid_method->setAccessible(true);
			$this->same(true, is_int($uid_method->invoke($cache, false)));
			$this->same(true, in_array($cache->publicStatus()['owner_identity_source'], [
				'proc-self-status', 'private-file-probe'
			], true));

			$current_year = (int) gmdate('Y');
			$current_month = (int) gmdate('n');
			$month_start = gmmktime(0, 0, 0, $current_month - 3, 1, $current_year);
			$month_end = gmmktime(0, 0, 0, $current_month - 2, 1, $current_year) - 1;
			$previous_start = gmmktime(0, 0, 0, $current_month - 4, 1, $current_year);
			$calls = [];
			$loader = static function (int $from, int $to) use (&$calls): array {
				$calls[] = [$from, $to];
				return [[
					'clock' => $from + min(3600, $to - $from),
					'num' => 1,
					'value_min' => 1,
					'value_avg' => 2,
					'value_max' => 3
				]];
			};

			$first = $cache->fetchRange('20001', 0, 'trend', $month_start, $month_end, true, $loader);
			$this->same(1, count($calls));
			$this->same(1, count($first['rows']));

			$calls = [];
			$expanded = $cache->fetchRange('20001', 0, 'trend', $previous_start, $month_end, true, $loader);
			$this->same(1, count($calls));
			$this->same(2, count($expanded['rows']));
			$this->same(true, $expanded['cache']['partial_hit']);

			$calls = [];
			$hit = $cache->fetchRange('20001', 0, 'trend', $previous_start, $month_end, true, $loader);
			$this->same(0, count($calls));
			$this->same(true, $hit['cache']['hit']);

			// Seed only the middle of another past-month shard. Expanding it
			// produces a two-range refresh plan (prefix and tail). If the tail is
			// incomplete, the successful prefix and cached middle must still be
			// returned, but neither new range may be persisted as complete.
			$middle_from = $month_start + 86400;
			$middle_to = $month_end - 86400;
			$cache->fetchRange('20002', 0, 'trend', $middle_from, $middle_to, true,
				static fn (int $from, int $to): array => [[
					'clock' => $from + min(3600, $to - $from),
					'num' => 1,
					'value_min' => 1,
					'value_avg' => 2,
					'value_max' => 3
				]]);
			$partial_calls = 0;
			$partial = $cache->fetchRange('20002', 0, 'trend', $month_start, $month_end, true,
				static function (int $from, int $to) use (&$partial_calls): array {
					$partial_calls++;
					$row = [
						'clock' => $from + min(3600, $to - $from),
						'num' => 1,
						'value_min' => 4,
						'value_avg' => 5,
						'value_max' => 6
					];
					if ($partial_calls === 2) {
						throw new SeriesRangeIncompleteException([$row], 'trend_source_truncated');
					}
					return [$row];
				});
			$this->same(2, $partial_calls);
			$this->same(true, $partial['cache']['range_incomplete']);
			$this->same(3, count($partial['rows']));

			$recovery_calls = 0;
			$cache->fetchRange('20002', 0, 'trend', $month_start, $month_end, true,
				static function (int $from, int $to) use (&$recovery_calls): array {
					$recovery_calls++;
					return [[
						'clock' => $from + min(3600, $to - $from),
						'num' => 1,
						'value_min' => 7,
						'value_avg' => 8,
						'value_max' => 9
					]];
				});
			$this->same(2, $recovery_calls);

			$ledger = new ReflectionMethod($cache, 'writeUsageLedger');
			$ledger->setAccessible(true);
			$ledger->invoke($cache, ['bytes' => Config::cacheMaxBytes() - 1, 'files' => 1]);
			$quota_result = $cache->fetchRange('29999', 0, 'trend', $month_start, $month_end, true,
				static fn (int $from, int $to): array => [[
					'clock' => $from + min(3600, $to - $from),
					'num' => 1,
					'value_min' => 10,
					'value_avg' => 11,
					'value_max' => 12
				]]);
			$this->same(1, count($quota_result['rows']));
			$this->same(0, $quota_result['cache']['shards_written']);
			$this->same(true, $quota_result['cache']['live_fallback']);
			$this->same('cache_capacity_reached', $quota_result['cache']['reason']);
			$blocked_series_key = hash('sha256', implode("\0", [
				(string) SeriesCache::CACHE_SCHEMA,
				SeriesCache::MODULE_GENERATION,
				'29999',
				'0',
				'trend'
			]));
			$blocked_directory = $base.DIRECTORY_SEPARATOR.Config::installNamespace()
				.DIRECTORY_SEPARATOR.'schema-'.SeriesCache::CACHE_SCHEMA.'-'
				.substr(hash('sha256', SeriesCache::MODULE_GENERATION), 0, 12).'-'
				.Config::runtimeGeneration()['id']
				.DIRECTORY_SEPARATOR.substr($blocked_series_key, 0, 2)
				.DIRECTORY_SEPARATOR.$blocked_series_key;
			$this->same(false, is_dir($blocked_directory));
			$this->same(true, $cache->clear()['ok']);
		}
		finally {
			$install_root = $base.DIRECTORY_SEPARATOR.Config::installNamespace();
			@unlink($install_root.DIRECTORY_SEPARATOR.'.clear.lock');
			@rmdir($install_root);
			@rmdir($base);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_DIR', $old_dir);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_NAMESPACE', $old_namespace);
			$this->restoreEnvironment('CAPACITY_PLANNING_BOOT_ID', $old_boot);
		}
	}

	private function testStorageSecurityFailClosed(): void {
		$old_dir = getenv('CAPACITY_PLANNING_CACHE_DIR');
		$old_namespace = getenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		$old_boot = getenv('CAPACITY_PLANNING_BOOT_ID');
		$base = rtrim(sys_get_temp_dir(), "\\/").DIRECTORY_SEPARATOR
			.'capacity-planning-sec-'.bin2hex(random_bytes(6));
		putenv('CAPACITY_PLANNING_CACHE_DIR='.$base);
		putenv('CAPACITY_PLANNING_CACHE_NAMESPACE=security-fail-closed');
		putenv('CAPACITY_PLANNING_BOOT_ID=security-test-boot');

		try {
			$cache = new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]);
			if (!$cache->publicStatus()['backend_available']) {
				echo "CapacityPlanningCacheTest: storage security fail-closed test skipped (POSIX backend unavailable).\n";
				return;
			}

			$current_year = (int) gmdate('Y');
			$current_month = (int) gmdate('n');
			$month_start = gmmktime(0, 0, 0, $current_month - 3, 1, $current_year);
			$month_end = gmmktime(0, 0, 0, $current_month - 2, 1, $current_year) - 1;
			$seeded = $cache->fetchRange('30001', 0, 'trend', $month_start, $month_end, true,
				static fn (int $from, int $to): array => [[
					'clock' => $from + 3600,
					'num' => 1,
					'value_min' => 1,
					'value_avg' => 2,
					'value_max' => 3
				]]);
			$this->same(1, $seeded['cache']['shards_written']);

			$series_key = hash('sha256', implode("\0", [
				(string) SeriesCache::CACHE_SCHEMA,
				SeriesCache::MODULE_GENERATION,
				'30001',
				'0',
				'trend'
			]));
			$shard_file = $base.DIRECTORY_SEPARATOR.Config::installNamespace()
				.DIRECTORY_SEPARATOR.'schema-'.SeriesCache::CACHE_SCHEMA.'-'
				.substr(hash('sha256', SeriesCache::MODULE_GENERATION), 0, 12).'-'
				.Config::runtimeGeneration()['id']
				.DIRECTORY_SEPARATOR.substr($series_key, 0, 2)
				.DIRECTORY_SEPARATOR.$series_key
				.DIRECTORY_SEPARATOR.hash('sha256', gmdate('Y-m', $month_start)).'.json.gz';
			$this->same(true, is_file($shard_file));

			// A shard readable beyond the owner must never be trusted: the read
			// fails closed and the request degrades to a live load.
			$this->same(true, chmod($shard_file, 0640));
			$loose_calls = 0;
			$loose = (new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]))
				->fetchRange('30001', 0, 'trend', $month_start, $month_end, true,
					static function (int $from, int $to) use (&$loose_calls): array {
						$loose_calls++;
						return [[
							'clock' => $from + 3600,
							'num' => 1,
							'value_min' => 4,
							'value_avg' => 5,
							'value_max' => 6
						]];
					});
			$this->same(1, $loose_calls);
			$this->same(true, $loose['cache']['live_fallback']);
			$this->same('cache_file_not_private', $loose['cache']['reason']);

			// A torn/truncated gzip payload is a shard miss (refetched and
			// republished), never an exception or stale data.
			$this->same(true, chmod($shard_file, 0600));
			file_put_contents($shard_file, substr((string) file_get_contents($shard_file), 0, 8));
			$torn_calls = 0;
			$torn = (new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]))
				->fetchRange('30001', 0, 'trend', $month_start, $month_end, true,
					static function (int $from, int $to) use (&$torn_calls): array {
						$torn_calls++;
						return [[
							'clock' => $from + 3600,
							'num' => 1,
							'value_min' => 7,
							'value_avg' => 8,
							'value_max' => 9
						]];
					});
			$this->same(1, $torn_calls);
			$this->same(false, $torn['cache']['live_fallback']);
			$this->same(1, $torn['cache']['shard_misses']);
			$this->same(1, $torn['cache']['shards_written']);
			$this->same(7.0, $torn['rows'][0]['value_min']);
		}
		finally {
			$this->removeTree($base);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_DIR', $old_dir);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_NAMESPACE', $old_namespace);
			$this->restoreEnvironment('CAPACITY_PLANNING_BOOT_ID', $old_boot);
		}
	}

	private function testBroadCacheDirectoryRejected(): void {
		// Linux-only: on macOS /etc is a symlink to /private/etc, so the guard
		// that fires is cache_symlink_rejected instead of cache_path_too_broad.
		if (PHP_OS_FAMILY !== 'Linux') {
			echo "CapacityPlanningCacheTest: broad cache directory test skipped (Linux-only /etc path semantics).\n";
			return;
		}
		$old_dir = getenv('CAPACITY_PLANNING_CACHE_DIR');
		$old_namespace = getenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		$old_boot = getenv('CAPACITY_PLANNING_BOOT_ID');
		putenv('CAPACITY_PLANNING_CACHE_DIR=/etc');
		putenv('CAPACITY_PLANNING_CACHE_NAMESPACE=broad-root-test');
		putenv('CAPACITY_PLANNING_BOOT_ID=broad-root-boot');

		try {
			$status = (new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]))->publicStatus();
			$this->same(false, $status['backend_available']);
			$this->same('cache_path_too_broad', $status['unavailable_reason']);
			// The rejection must happen before any directory is created below /etc.
			$this->same(false, is_dir('/etc/'.Config::installNamespace()));
		}
		finally {
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_DIR', $old_dir);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_NAMESPACE', $old_namespace);
			$this->restoreEnvironment('CAPACITY_PLANNING_BOOT_ID', $old_boot);
		}
	}

	private function removeTree(string $path): void {
		if (!is_dir($path) || is_link($path)) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ($iterator as $entry) {
			$entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
		}
		@rmdir($path);
	}

	private function restoreEnvironment(string $name, $value): void {
		putenv($value === false ? $name : $name.'='.$value);
	}

	private function same($expected, $actual): void {
		$this->assertions++;
		if ($expected !== $actual) {
			throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true));
		}
	}

	private function fail(string $message): void {
		throw new RuntimeException($message);
	}
}

(new CapacityPlanningCacheTest())->run();
