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
		$this->testFreshnessBoundsTheUncoveredTail();
		$this->testCapacityEvictionStartsBeforeTheHardCap();
		$this->testWebRootOverlapPredicate();
		$this->testCalendarOrderAndPublicStatus();
		$this->testCalendarSegmentsCrossYear();
		$this->testFastPathOrderingDeduplicationAndRangeBoundaries();
		$this->testCleanupPlanningUsesHardLimits();
		$this->testEnabledCacheRoundTripAndIncrementalExtension();
		$this->testMultiDayHistoryRoundTripIsChronologicalAndDeduplicated();
		$this->testResumableClearAndFreshMiss();
		$this->testResumableEmptyDirectoryClear();
		$this->testCheckedLedgerInvalidation();
		$this->testClearRejectsSymlinkPayload();
		$this->testClearRejectsSpecialLockFile();
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
		$this->enablePrivateAccess($method);
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
		$this->enablePrivateAccess($fresh_method);
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

		$stale_cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$stale_method = new ReflectionMethod($stale_cache, 'refreshPlan');
		$this->enablePrivateAccess($stale_method);
		$stale_state = [
			'covered_from' => $fresh_segment['from'],
			'covered_to' => $fresh_segment['to'],
			'refreshed_at' => time() - 3600,
			'rows' => []
		];
		$closed_segment = $fresh_segment;
		$closed_segment['shard_to'] = time() - 1;
		$this->same([], $stale_method->invoke(
			$stale_cache, $stale_state, $closed_segment, 'history', false
		));
		$mutable_plan = $stale_method->invoke(
			$stale_cache, $stale_state, $fresh_segment, 'history', false
		);
		$this->same(1, count($mutable_plan));
		$this->same($fresh_segment['to'], $mutable_plan[0][1]);
	}

	/**
	 * refreshed_at records when a fetch ran, covered_to how far it reached. A
	 * request with a historic time_to stamps the first without advancing the
	 * second, so freshness must bound the uncovered tail as well — otherwise that
	 * shard serves a silently truncated series to every later request as a hit.
	 */
	private function testFreshnessBoundsTheUncoveredTail(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$plan_method = new ReflectionMethod($cache, 'refreshPlan');
		$this->enablePrivateAccess($plan_method);
		$usable_method = new ReflectionMethod($cache, 'stateUsable');
		$this->enablePrivateAccess($usable_method);

		$now = time();
		$segment = ['from' => $now - 30 * 86400, 'to' => $now, 'shard_to' => $now + 86400];
		$poisoned = [
			'covered_from' => $segment['from'],
			// Written moments ago, but only reaching 19 days back.
			'covered_to' => $now - 19 * 86400,
			'refreshed_at' => $now,
			'rows' => []
		];
		$plan = $plan_method->invoke($cache, $poisoned, $segment, 'trend', false);
		$this->same(1, count($plan));
		$this->same($segment['to'], $plan[0][1]);
		$this->same(false, $usable_method->invoke($cache, $poisoned, $segment, 'trend'));

		// A gap inside the TTL is ordinary freshness and must still skip the tail.
		$normal = [
			'covered_from' => $segment['from'],
			'covered_to' => $now - 600,
			'refreshed_at' => $now - 600,
			'rows' => []
		];
		$this->same([], $plan_method->invoke($cache, $normal, $segment, 'trend', false));
		$this->same(true, $usable_method->invoke($cache, $normal, $segment, 'trend'));

		// A shard ending before the request even begins holds no rows for it and
		// must never be reported as a usable hit.
		$empty_slice = [
			'covered_from' => $segment['from'],
			'covered_to' => $segment['from'] - 1,
			'refreshed_at' => $now,
			'rows' => []
		];
		$this->same(false, $usable_method->invoke($cache, $empty_slice, $segment, 'trend'));
	}

	/**
	 * Quota reservation makes an actual breach impossible, so eviction has to
	 * start at a high-water mark or the cache wedges at the ceiling and rejects
	 * every new shard until the 30-day idle rule frees space.
	 */
	private function testCapacityEvictionStartsBeforeTheHardCap(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$method = new ReflectionMethod($cache, 'cleanupEvictionCandidates');
		$this->enablePrivateAccess($method);
		$now = time();
		$entries = [];
		for ($i = 0; $i < 10; $i++) {
			$entries[] = [
				'path' => '/private/cache/'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'.json.gz',
				'mtime' => $now - 100 + $i,
				'size' => 10
			];
		}

		// 98 of 100 bytes used: under the cap, but past the watermark.
		$victims = $method->invoke($cache, $entries, $now, 86400, 98, 10, 100, 1000, 5);
		$this->same(true, count($victims) > 0);
		$this->same($entries[0]['path'], $victims[0]['path']);

		// Comfortably below the watermark nothing is reclaimed.
		$this->same([], $method->invoke($cache, $entries, $now, 86400, 50, 10, 100, 1000, 5));
	}

	private function testWebRootOverlapPredicate(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$method = new ReflectionMethod($cache, 'pathsOverlap');
		$this->enablePrivateAccess($method);
		$this->same(true, $method->invoke($cache, '/usr/share/zabbix/cache', '/usr/share/zabbix'));
		$this->same(true, $method->invoke($cache, '/usr/share', '/usr/share/zabbix'));
		$this->same(false, $method->invoke($cache, '/var/cache/zabbix-capacity', '/usr/share/zabbix'));
	}

	private function testCalendarOrderAndPublicStatus(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$method = new ReflectionMethod($cache, 'calendarSegments');
		$this->enablePrivateAccess($method);
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
		$this->enablePrivateAccess($method);
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

	private function testFastPathOrderingDeduplicationAndRangeBoundaries(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$normalize = new ReflectionMethod($cache, 'normalizeRows');
		$this->enablePrivateAccess($normalize);
		$rows_in_range = new ReflectionMethod($cache, 'rowsInRange');
		$this->enablePrivateAccess($rows_in_range);
		$replace_range = new ReflectionMethod($cache, 'replaceRange');
		$this->enablePrivateAccess($replace_range);

		$normalized = $normalize->invoke($cache, [
			['clock' => 300, 'ns' => 2, 'value' => '3'],
			['clock' => 100, 'ns' => 1, 'value' => '1'],
			['clock' => 200, 'ns' => 9, 'value' => '2'],
			['clock' => 200, 'ns' => 1, 'value' => '1.5'],
			// The later duplicate is the authoritative API row, matching the
			// established deduplication semantics.
			['clock' => 200, 'ns' => 9, 'value' => '2.5'],
			['clock' => 99, 'ns' => 1, 'value' => 'outside-request'],
			['clock' => 250, 'ns' => 3, 'value' => INF]
		], 'history', 100, 300);
		$this->same([
			['clock' => 100, 'ns' => 1, 'value' => 1.0],
			['clock' => 200, 'ns' => 1, 'value' => 1.5],
			['clock' => 200, 'ns' => 9, 'value' => 2.5],
			['clock' => 300, 'ns' => 2, 'value' => 3.0]
		], $normalized);

		// Range endpoints are inclusive, and all nanosecond-distinct rows at a
		// boundary clock must survive the binary-search fast path.
		$this->same([
			['clock' => 200, 'ns' => 1, 'value' => 1.5],
			['clock' => 200, 'ns' => 9, 'value' => 2.5]
		], $rows_in_range->invoke($cache, $normalized, 200, 200));
		$this->same([], $rows_in_range->invoke($cache, $normalized, 201, 299));
		$this->same($normalized, $rows_in_range->invoke($cache, $normalized, 100, 300));

		$replacement = $replace_range->invoke($cache, $normalized, [
			['clock' => 200, 'ns' => 5, 'value' => 20.0],
			['clock' => 250, 'ns' => 1, 'value' => 25.0],
			['clock' => 300, 'ns' => 5, 'value' => 30.0]
		], 200, 300, 'history');
		$this->same([
			['clock' => 100, 'ns' => 1, 'value' => 1.0],
			['clock' => 200, 'ns' => 5, 'value' => 20.0],
			['clock' => 250, 'ns' => 1, 'value' => 25.0],
			['clock' => 300, 'ns' => 5, 'value' => 30.0]
		], $replacement);
	}

	private function testCleanupPlanningUsesHardLimits(): void {
		$cache = new SeriesCache(['enabled' => false, 'ttl_seconds' => 1800]);
		$method = new ReflectionMethod($cache, 'cleanupEvictionCandidates');
		$this->enablePrivateAccess($method);
		$now = time();
		$entries = [];
		for ($i = 0; $i < 11; $i++) {
			$entries[] = [
				'path' => '/private/cache/'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'.json.gz',
				'mtime' => $now - 100 + $i,
				'size' => 10
			];
		}

		// Eleven entries may be above a small UI/status display limit, but they
		// are not over this simulated hard limit and must not trigger eviction.
		$this->same([], $method->invoke($cache, $entries, $now, 86400, 110, 11, 10000, 100, 5));
		$byte_victims = $method->invoke($cache, $entries, $now, 86400, 110, 11, 50, 100, 5);
		$this->same(5, count($byte_victims));
		$this->same($entries[0]['path'], $byte_victims[0]['path']);
		$entry_victims = $method->invoke($cache, $entries, $now, 86400, 110, 11, 10000, 10, 2);
		$this->same(2, count($entry_victims));

		$entries[10]['mtime'] = $now - 90000;
		$idle_victims = $method->invoke($cache, $entries, $now, 86400, 110, 11, 10000, 100, 5);
		$this->same(1, count($idle_victims));
		$this->same($entries[10]['path'], $idle_victims[0]['path']);
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
			$this->enablePrivateAccess($uid_method);
			$this->same(true, is_int($uid_method->invoke($cache, false)));
			$this->same(true, in_array($cache->publicStatus()['owner_identity_source'], [
				'proc-self-status', 'private-file-probe'
			], true));
			$install_root_property = new ReflectionProperty($cache, 'install_root');
			$this->enablePrivateAccess($install_root_property);
			$this->same(true, is_file(
				(string) $install_root_property->getValue($cache).DIRECTORY_SEPARATOR.'.clear.lock'
			), 'Initialization must create/use the shared clear guard before exposing the generation.');

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
			$this->enablePrivateAccess($ledger);
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
			$this->removeTree($base);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_DIR', $old_dir);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_NAMESPACE', $old_namespace);
			$this->restoreEnvironment('CAPACITY_PLANNING_BOOT_ID', $old_boot);
		}
	}

	private function testMultiDayHistoryRoundTripIsChronologicalAndDeduplicated(): void {
		$old_dir = getenv('CAPACITY_PLANNING_CACHE_DIR');
		$old_namespace = getenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		$old_boot = getenv('CAPACITY_PLANNING_BOOT_ID');
		$base = rtrim(sys_get_temp_dir(), "\\/").DIRECTORY_SEPARATOR
			.'capacity-planning-history-order-test-'.bin2hex(random_bytes(6));
		putenv('CAPACITY_PLANNING_CACHE_DIR='.$base);
		putenv('CAPACITY_PLANNING_CACHE_NAMESPACE=history-order-test');
		putenv('CAPACITY_PLANNING_BOOT_ID=history-order-test-boot');

		try {
			$cache = new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]);
			if (!$cache->publicStatus()['backend_available']) {
				echo "CapacityPlanningCacheTest: multi-day history round trip skipped (POSIX backend unavailable).\n";
				return;
			}

			$day = (int) (floor((time() - 6 * 86400) / 86400) * 86400);
			$from = $day + 100;
			$to = $day + 3 * 86400 + 300;
			$calls = [];
			$loader = static function (int $range_from, int $range_to) use (&$calls): array {
				$calls[] = [$range_from, $range_to];
				$call = count($calls);
				$offset = max(1, min(60, intdiv(max(3, $range_to - $range_from), 3)));
				$early = $range_from + $offset;
				$late = $range_to - $offset;

				// Deliberately newest-first and duplicated. Normalization must sort
				// within each shard and retain the later duplicate value.
				return [
					['clock' => $late, 'ns' => 2, 'value' => 200 + $call],
					['clock' => $early, 'ns' => 1, 'value' => 100 + $call],
					['clock' => $late, 'ns' => 2, 'value' => 300 + $call]
				];
			};

			$first = $cache->fetchRange('42001', 0, 'history', $from, $to, true, $loader);
			$this->same(4, count($calls));
			$this->same(true, $calls[0][0] > $calls[count($calls) - 1][0]);
			$this->same(8, count($first['rows']));
			$keys = array_map(static fn (array $row): string => $row['clock'].':'.$row['ns'], $first['rows']);
			$this->same(count($keys), count(array_unique($keys)));
			$sorted_keys = $keys;
			usort($sorted_keys, static function (string $left, string $right): int {
				[$left_clock, $left_ns] = array_map('intval', explode(':', $left, 2));
				[$right_clock, $right_ns] = array_map('intval', explode(':', $right, 2));
				return ($left_clock <=> $right_clock) ?: ($left_ns <=> $right_ns);
			});
			$this->same($sorted_keys, $keys);
			$this->same([], array_values(array_filter($first['rows'],
				static fn (array $row): bool => $row['ns'] === 2 && $row['value'] < 300)));

			$cache_loader_called = false;
			$second = $cache->fetchRange('42001', 0, 'history', $from, $to, true,
				static function () use (&$cache_loader_called): array {
					$cache_loader_called = true;
					return [];
				});
			$this->same(false, $cache_loader_called);
			$this->same(true, $second['cache']['hit']);
			$this->same($first['rows'], $second['rows']);
		}
		finally {
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_DIR', $old_dir);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_NAMESPACE', $old_namespace);
			$this->restoreEnvironment('CAPACITY_PLANNING_BOOT_ID', $old_boot);
			$this->removeTree($base);
		}
	}

	private function testResumableClearAndFreshMiss(): void {
		$old_dir = getenv('CAPACITY_PLANNING_CACHE_DIR');
		$old_namespace = getenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		$old_boot = getenv('CAPACITY_PLANNING_BOOT_ID');
		$base = rtrim(sys_get_temp_dir(), "\\/").DIRECTORY_SEPARATOR
			.'capacity-planning-clear-'.bin2hex(random_bytes(6));
		putenv('CAPACITY_PLANNING_CACHE_DIR='.$base);
		putenv('CAPACITY_PLANNING_CACHE_NAMESPACE=resumable-clear');
		putenv('CAPACITY_PLANNING_BOOT_ID=resumable-clear-boot');

		try {
			$cache = new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]);
			if (!$cache->publicStatus()['backend_available']) {
				echo "CapacityPlanningCacheTest: resumable clear skipped (POSIX backend unavailable).\n";
				return;
			}
			$year = (int) gmdate('Y');
			$month = (int) gmdate('n');
			$from = gmmktime(0, 0, 0, $month - 3, 1, $year);
			$to = gmmktime(0, 0, 0, $month - 2, 1, $year) - 1;
			$loader = static fn (int $range_from, int $range_to): array => [[
				'clock' => $range_from + min(3600, $range_to - $range_from),
				'num' => 1,
				'value_min' => 1,
				'value_avg' => 2,
				'value_max' => 3
			]];
			foreach (['41001', '41002', '41003'] as $itemid) {
				$seeded = $cache->fetchRange($itemid, 0, 'trend', $from, $to, true, $loader);
				$this->same(1, $seeded['cache']['shards_written']);
			}
			$this->same(3, $cache->publicStatus()['files']);

			$first = $cache->clear(2);
			$this->same(true, $first['ok']);
			$this->same(false, $first['complete']);
			$this->same(true, $first['progress']);
			$this->same('clear_in_progress', $first['reason']);
			$this->same(2, $first['removed_files']);

			$total_removed = $first['removed_files'];
			$clear_calls = 1;
			do {
				$next = $cache->clear(2);
				$clear_calls++;
				$this->same(true, $next['ok']);
				$total_removed += $next['removed_files'];
				if ($clear_calls > 20) {
					$this->fail('Bounded payload/directory clear did not converge.');
				}
			} while (!$next['complete']);
			$this->same('', $next['reason']);
			$this->same(3, $total_removed);
			$this->same(true, $clear_calls > 1);
			$this->same(0, $cache->publicStatus()['files']);

			$calls = 0;
			$after_clear = $cache->fetchRange('41001', 0, 'trend', $from, $to, true,
				static function (int $range_from, int $range_to) use (&$calls): array {
					$calls++;
					return [[
						'clock' => $range_from + min(3600, $range_to - $range_from),
						'num' => 1,
						'value_min' => 4,
						'value_avg' => 5,
						'value_max' => 6
					]];
				});
			$this->same(1, $calls);
			$this->same(1, $after_clear['cache']['shard_misses']);
			$this->same(4.0, $after_clear['rows'][0]['value_min']);
		}
		finally {
			$this->removeTree($base);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_DIR', $old_dir);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_NAMESPACE', $old_namespace);
			$this->restoreEnvironment('CAPACITY_PLANNING_BOOT_ID', $old_boot);
		}
	}

	private function testResumableEmptyDirectoryClear(): void {
		$old_dir = getenv('CAPACITY_PLANNING_CACHE_DIR');
		$old_namespace = getenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		$old_boot = getenv('CAPACITY_PLANNING_BOOT_ID');
		$base = rtrim(sys_get_temp_dir(), "\\/").DIRECTORY_SEPARATOR
			.'capacity-planning-empty-dirs-'.bin2hex(random_bytes(6));
		putenv('CAPACITY_PLANNING_CACHE_DIR='.$base);
		putenv('CAPACITY_PLANNING_CACHE_NAMESPACE=resumable-empty-directories');
		putenv('CAPACITY_PLANNING_BOOT_ID=resumable-empty-directories-boot');

		try {
			$cache = new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]);
			if (!$cache->publicStatus()['backend_available']) {
				echo "CapacityPlanningCacheTest: empty-directory clear skipped (POSIX backend unavailable).\n";
				return;
			}
			$generation = new ReflectionProperty($cache, 'generation_root');
			$this->enablePrivateAccess($generation);
			$generation_root = (string) $generation->getValue($cache);
			$bucket = $generation_root.DIRECTORY_SEPARATOR.'00';
			mkdir($bucket, 0700, true);
			@chmod($bucket, 0700);
			for ($i = 0; $i < 6; $i++) {
				$series = $bucket.DIRECTORY_SEPARATOR.hash('sha256', 'empty-series-'.$i);
				mkdir($series, 0700);
				@chmod($series, 0700);
			}

			$calls = 0;
			do {
				$result = $cache->clear(2);
				$calls++;
				$this->same(true, $result['ok']);
				if (!$result['complete']) {
					$this->same('clear_in_progress', $result['reason']);
					$this->same(true, $result['progress']);
				}
				if ($calls > 10) {
					$this->fail('Bounded empty-directory clear did not converge.');
				}
			} while (!$result['complete']);
			$this->same(true, $calls > 1);
			$this->same(0, $result['removed_files']);
			$scan = new ReflectionMethod($cache, 'scanUsageState');
			$this->enablePrivateAccess($scan);
			$this->same(['bytes' => 0, 'files' => 0], $scan->invoke($cache));
		}
		finally {
			$this->removeTree($base);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_DIR', $old_dir);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_NAMESPACE', $old_namespace);
			$this->restoreEnvironment('CAPACITY_PLANNING_BOOT_ID', $old_boot);
		}
	}

	private function testCheckedLedgerInvalidation(): void {
		$old_dir = getenv('CAPACITY_PLANNING_CACHE_DIR');
		$old_namespace = getenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		$old_boot = getenv('CAPACITY_PLANNING_BOOT_ID');
		$base = rtrim(sys_get_temp_dir(), "\\/").DIRECTORY_SEPARATOR
			.'capacity-planning-ledger-clear-'.bin2hex(random_bytes(6));
		putenv('CAPACITY_PLANNING_CACHE_DIR='.$base);
		putenv('CAPACITY_PLANNING_CACHE_NAMESPACE=checked-ledger-clear');
		putenv('CAPACITY_PLANNING_BOOT_ID=checked-ledger-clear-boot');

		try {
			$cache = new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]);
			if (!$cache->publicStatus()['backend_available']) {
				echo "CapacityPlanningCacheTest: checked ledger invalidation skipped (POSIX backend unavailable).\n";
				return;
			}
			$ledger_path = new ReflectionMethod($cache, 'usageLedgerPath');
			$this->enablePrivateAccess($ledger_path);
			$path = (string) $ledger_path->invoke($cache);
			$write = new ReflectionMethod($cache, 'writeUsageLedger');
			$this->enablePrivateAccess($write);
			$write->invoke($cache, ['bytes' => 123, 'files' => 9]);
			$this->same(true, is_file($path));
			$this->same(true, $cache->clear(2)['complete']);
			$this->same(false, file_exists($path));
			$install_root_property = new ReflectionProperty($cache, 'install_root');
			$this->enablePrivateAccess($install_root_property);
			$install_root = (string) $install_root_property->getValue($cache);
			$quota_tmp = $install_root.DIRECTORY_SEPARATOR
				.'.quota-state.json.tmp.'.str_repeat('a', 16);
			file_put_contents($quota_tmp, 'stale quota temp');
			@chmod($quota_tmp, 0600);
			$quota_tmp_result = $cache->clear(2);
			$this->same(true, $quota_tmp_result['complete']);
			$this->same(1, $quota_tmp_result['removed_files']);
			$this->same(false, file_exists($quota_tmp));

			$near_miss = $install_root.DIRECTORY_SEPARATOR.'.quota-state.json.tmp.not-hex';
			file_put_contents($near_miss, 'must not be deleted');
			@chmod($near_miss, 0600);
			$near_miss_result = $cache->clear(2);
			$this->same(false, $near_miss_result['ok']);
			$this->same(false, $near_miss_result['complete']);
			$this->same(false, $near_miss_result['progress']);
			$this->same('cache_unknown_file', $near_miss_result['reason']);
			$this->same(true, is_file($near_miss));
			@unlink($near_miss);

			mkdir($path, 0700);
			@chmod($path, 0700);
			$failed = $cache->clear(2);
			$this->same(false, $failed['ok']);
			$this->same(false, $failed['complete']);
			$this->same('cache_ledger_invalidation_failed', $failed['reason']);
			@rmdir($path);

			$unknown = $install_root
				.DIRECTORY_SEPARATOR.'unknown'.DIRECTORY_SEPARATOR.'tree';
			mkdir($unknown, 0700, true);
			$unknown_result = $cache->clear(10);
			$this->same(false, $unknown_result['ok']);
			$this->same('cache_unknown_directory', $unknown_result['reason']);
			$this->same(true, is_dir($unknown));
		}
		finally {
			$this->removeTree($base);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_DIR', $old_dir);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_NAMESPACE', $old_namespace);
			$this->restoreEnvironment('CAPACITY_PLANNING_BOOT_ID', $old_boot);
		}
	}

	private function testClearRejectsSymlinkPayload(): void {
		$old_dir = getenv('CAPACITY_PLANNING_CACHE_DIR');
		$old_namespace = getenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		$old_boot = getenv('CAPACITY_PLANNING_BOOT_ID');
		$base = rtrim(sys_get_temp_dir(), "\\/").DIRECTORY_SEPARATOR
			.'capacity-planning-clear-symlink-'.bin2hex(random_bytes(6));
		putenv('CAPACITY_PLANNING_CACHE_DIR='.$base);
		putenv('CAPACITY_PLANNING_CACHE_NAMESPACE=clear-symlink');
		putenv('CAPACITY_PLANNING_BOOT_ID=clear-symlink-boot');
		$external = $base.'-external.json.gz';

		try {
			$cache = new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]);
			if (!$cache->publicStatus()['backend_available'] || !function_exists('symlink')) {
				echo "CapacityPlanningCacheTest: clear symlink rejection skipped (POSIX backend unavailable).\n";
				return;
			}
			$generation = new ReflectionProperty($cache, 'generation_root');
			$this->enablePrivateAccess($generation);
			$generation_root = (string) $generation->getValue($cache);
			file_put_contents($external, 'must remain');
			@chmod($external, 0600);
			$link = $generation_root.DIRECTORY_SEPARATOR.'outside.json.gz';
			if (!@symlink($external, $link)) {
				echo "CapacityPlanningCacheTest: clear symlink rejection skipped (symlink creation denied).\n";
				return;
			}

			$result = $cache->clear(1);
			$this->same(false, $result['ok']);
			$this->same(false, $result['complete']);
			$this->same('cache_symlink_rejected', $result['reason']);
			$this->same('must remain', file_get_contents($external));
		}
		finally {
			$this->removeTree($base);
			@unlink($external);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_DIR', $old_dir);
			$this->restoreEnvironment('CAPACITY_PLANNING_CACHE_NAMESPACE', $old_namespace);
			$this->restoreEnvironment('CAPACITY_PLANNING_BOOT_ID', $old_boot);
		}
	}

	private function testClearRejectsSpecialLockFile(): void {
		if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_mkfifo')) {
			echo "CapacityPlanningCacheTest: FIFO lock rejection skipped (posix_mkfifo unavailable).\n";
			return;
		}
		$old_dir = getenv('CAPACITY_PLANNING_CACHE_DIR');
		$old_namespace = getenv('CAPACITY_PLANNING_CACHE_NAMESPACE');
		$old_boot = getenv('CAPACITY_PLANNING_BOOT_ID');
		$base = rtrim(sys_get_temp_dir(), "\\/").DIRECTORY_SEPARATOR
			.'capacity-planning-special-lock-'.bin2hex(random_bytes(6));
		putenv('CAPACITY_PLANNING_CACHE_DIR='.$base);
		putenv('CAPACITY_PLANNING_CACHE_NAMESPACE=special-lock-rejection');
		putenv('CAPACITY_PLANNING_BOOT_ID=special-lock-rejection-boot');

		try {
			$cache = new SeriesCache(['enabled' => true, 'ttl_seconds' => 1800]);
			if (!$cache->publicStatus()['backend_available']) {
				echo "CapacityPlanningCacheTest: FIFO lock rejection skipped (POSIX backend unavailable).\n";
				return;
			}
			$install_root_property = new ReflectionProperty($cache, 'install_root');
			$this->enablePrivateAccess($install_root_property);
			$fifo = (string) $install_root_property->getValue($cache)
				.DIRECTORY_SEPARATOR.'.shard-lock-aa.lock';
			if (!@posix_mkfifo($fifo, 0600)) {
				echo "CapacityPlanningCacheTest: FIFO lock rejection skipped (FIFO creation denied).\n";
				return;
			}
			@chmod($fifo, 0600);

			$result = $cache->clear(10);
			$this->same(false, $result['ok']);
			$this->same(false, $result['complete']);
			$this->same('cache_file_unsafe', $result['reason']);
			$this->same(true, file_exists($fifo));
		}
		finally {
			$this->removeTree($base);
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

	private function enablePrivateAccess(object $reflection): void {
		// Private reflection is directly invokable since PHP 8.1 and calling
		// setAccessible() is deprecated in PHP 8.5. PHP 8.0 still needs it.
		if (PHP_VERSION_ID < 80100) {
			$reflection->setAccessible(true);
		}
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
