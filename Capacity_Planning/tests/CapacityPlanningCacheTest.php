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
		$this->testNamespaceFailsClosed();
		$this->testUnauthorizedRequestNeverCallsLoader();
		$this->testDisabledCacheUsesSanitizedLiveRows();
		$this->testIncompleteRangeIsTruthfulAndSanitized();
		$this->testFutureRangeRejectedAndRefreshTailBounded();
		$this->testWebRootOverlapPredicate();
		$this->testCalendarOrderAndPublicStatus();
		$this->testEnabledCacheRoundTripAndIncrementalExtension();

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
