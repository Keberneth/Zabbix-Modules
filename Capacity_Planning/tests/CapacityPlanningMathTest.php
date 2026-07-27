<?php

declare(strict_types=1);

// Defense in depth: these public-source tests are non-web-only and must never
// run if an operator accidentally deploys tests/ below a web-served path.
if (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'wasm'], true)) {
	http_response_code(404);
	exit;
}

// Minimal Zabbix frontend stubs let the controller's pure calculation methods
// run from the CLI. No API method is called by this regression suite.
if (!class_exists('CController')) {
	class CController {}
}
if (!class_exists('CControllerResponseData')) {
	class CControllerResponseData {}
}
if (!class_exists('API')) {
	class API {}
}
if (!function_exists('_')) {
	function _(string $value): string {
		return $value;
	}
}
if (!defined('ITEM_VALUE_TYPE_FLOAT')) {
	define('ITEM_VALUE_TYPE_FLOAT', 0);
}
if (!defined('ITEM_VALUE_TYPE_UINT64')) {
	define('ITEM_VALUE_TYPE_UINT64', 3);
}

require_once dirname(__DIR__).'/actions/CapacityPlanningData.php';

use Modules\CapacityPlanning\Actions\CapacityPlanningData;

final class CapacityPlanningMathTest {
	private CapacityPlanningData $controller;
	private int $assertions = 0;

	public function __construct() {
		$reflection = new ReflectionClass(CapacityPlanningData::class);
		$this->controller = $reflection->newInstanceWithoutConstructor();
	}

	public function run(): void {
		$this->testInventoryScopeParserSupportsLiteralsRegexAndEscapes();
		$this->testInventoryScopeMatchingUsesOrSemantics();
		$this->testInventoryScopeRejectsUnsafeExpressions();
		$this->testInventoryScopeCapUsesSentinelAndFailsClosed();
		$this->testInventoryScopePreviewDistinguishesUnavailableData();
		$this->testSparseResourceBaselineIsRejected();
		$this->testFreshHighCurrentIsWatchWithoutBaseline();
		$this->testNearFullMaximaDoNotInventDuration();
		$this->testRecurringThirtyMinuteEpisodesAreHigh();
		$this->testOngoingCriticalRequiresCurrentSaturation();
		$this->testSingleSampleNearFullDurationIsUnknown();
		$this->testSparseRecentBucketDoesNotHideQualifiedTrendHour();
		$this->testSparseRecentBucketDoesNotUpgradeConfidence();
		$this->testPollingRawBaselineRetainsTrendDuration();
		$this->testIncompleteNearFullBucketIsCountedWithUnknownDuration();
		$this->testDownwardRegimeIsolatedFromCurrentRisk();
		$this->testImpossiblePercentageRowsAreRejected();
		$this->testDiskUsableCapacityAvoidsLinuxTotal();
		$this->testSparseDiskSeriesHasNoModel();
		$this->testCurrentDiskBreachSurvivesMissingHistory();
		$this->testDirectPercentageSeriesDrivesPercentageEta();
		$this->testLowConfidencePercentageDoesNotCapByteRisk();
		$this->testInvalidDirectPercentageFallsBackToDerived();
		$this->testUnqualifiedDirectPercentageFallsBackToDerived();
		$this->testFreshResourceAlternativesBeatStalePreferredItems();
		$this->testFreshFilesystemCompositionBeatsStaleCompleteness();
		$this->testStaleOptionalLinuxMetricsDoNotMakeFilesystemStale();
		$this->testWindowsUsedAndFreeDoNotRequireFreshTotal();
		$this->testInvalidFilesystemValuesDoNotCreateCurrentBreach();
		$this->testFilesystemStaleStatusRequiresNeededContributor();
		$this->testPfreeSupportsCurrentAndHistoricalUsedPercent();
		$this->testSameDepthMacroUsesLowestNumericTemplateId();
		$this->testTemplateTopologyTraversalIsBoundedAndCycleSafe();
		$this->testBoundedItemIngestionDetectsSentinelRow();
		$this->testHostMaintenanceIsNormalized();
		$this->testMaintenanceStalenessAttributionIsBounded();
		$this->testMaintenanceExplainedResourceGapIsNotQualityFailure();
		$this->testMaintenanceExplainedDiskGapIsNotQualityFailure();
		$this->testMaintenanceBlocksCurrentForecastEscalation();
		$this->testForecastSpecValidatesCurrentObservationFlag();
		$this->testForecastSpecEnforcesResourceBatchLimit();
		$this->testDownsampleSeriesTimestamps();
		$this->testMissingResourceItemsRemainVisible();
		$this->testInventoryFacetsAreCompactAndSorted();

		echo "CapacityPlanningMathTest: {$this->assertions} assertions passed.\n";
	}

	private function call(string $method, array $arguments = []) {
		$reflection = new ReflectionMethod(CapacityPlanningData::class, $method);
		$reflection->setAccessible(true);
		return $reflection->invokeArgs($this->controller, $arguments);
	}

	private function resetQuality(): void {
		$reflection = new ReflectionProperty(CapacityPlanningData::class, 'quality');
		$reflection->setAccessible(true);
		$reflection->setValue($this->controller, []);
	}

	private function qualityIssues(): array {
		$reflection = new ReflectionProperty(CapacityPlanningData::class, 'quality');
		$reflection->setAccessible(true);
		return $reflection->getValue($this->controller);
	}

	private function assertSame($expected, $actual, string $message): void {
		$this->assertions++;
		if ($expected !== $actual) {
			throw new RuntimeException($message.' Expected '.var_export($expected, true)
				.', got '.var_export($actual, true).'.');
		}
	}

	private function assertTrue(bool $condition, string $message): void {
		$this->assertions++;
		if (!$condition) {
			throw new RuntimeException($message);
		}
	}

	private function assertAlmost(float $expected, float $actual, float $delta, string $message): void {
		$this->assertions++;
		if (abs($expected - $actual) > $delta) {
			throw new RuntimeException($message.' Expected '.$expected.', got '.$actual.'.');
		}
	}

	private function resourceSpec(int $now, string $type = 'CPU', float $current = 70.0): array {
		return [
			'id' => 'r0',
			'rtype' => $type,
			'current' => $current,
			'lastclock' => $now,
			'data_status' => 'OK',
			'warn' => 85.0,
			'crit' => 95.0
		];
	}

	private function host(string $os = 'Linux'): array {
		return [
			'name' => 'test-host',
			'os' => $os,
			'maintenance' => ['active' => false, 'type' => 'none', 'id' => null, 'since' => null]
		];
	}

	private function item(string $itemid, string $key, float $value, int $clock): array {
		return [
			'itemid' => $itemid,
			'key' => $key,
			'name' => $key,
			'state' => 0,
			'lastvalue' => $value,
			'lastclock' => $clock,
			'tags' => []
		];
	}

	private function macroIndex(): array {
		return ['by_entity' => ['h1' => []], 'levels' => ['h1' => []], 'global' => []];
	}

	private function fiveMinuteRow(int $clock, float $minimum, float $average, float $maximum): array {
		return [
			'clock' => $clock,
			'num' => 5,
			'min' => $minimum,
			'avg' => $average,
			'max' => $maximum,
			'source' => 'recent_history',
			'bucket_seconds' => 300,
			'expected_num' => 5,
			'bucket_coverage_pct' => 100.0,
			'complete' => true,
			'duration_confirmable' => true,
			'baseline_eligible' => true
		];
	}

	private function testInventoryScopeParserSupportsLiteralsRegexAndEscapes(): void {
		$parsed = $this->call('parseFilterExpression', [
			'Production, db\,primary, /^(prod,blue)-(eu|us)-\d+$/i, production'
		]);
		$this->assertSame(null, $parsed['error'],
			'A valid comma-separated scope expression should parse without an error.');
		$this->assertSame(4, count($parsed['terms']),
			'Duplicate tokens should retain their cursor index for term-specific suggestions.');
		$this->assertSame(['literal', 'literal', 'regex', 'literal'], array_column($parsed['terms'], 'kind'),
			'Plain and slash-delimited scope values must retain their matching type.');
		$this->assertSame('db,primary', $parsed['terms'][1]['value'],
			'An escaped comma must remain inside a literal value.');

		$escaped = $this->call('parseFilterExpression', ['\/api\,v1, C:\\\\Windows']);
		$this->assertSame(['/api,v1', 'C:\Windows'], array_column($escaped['terms'], 'value'),
			'Escapes must support a literal leading slash, comma and backslash.');
	}

	private function testInventoryScopeMatchingUsesOrSemantics(): void {
		$terms = $this->call('parseFilterExpression', ['web, /^db-\d+$/i'])['terms'];
		$this->assertTrue($this->call('matchesFilterValues', [['WEB-frontend-01'], $terms]),
			'Plain scope values must match as case-insensitive contains.');
		$this->assertTrue($this->call('matchesFilterValues', [['db-12'], $terms]),
			'A regex alternative must match within the same field.');
		$this->assertSame(false, $this->call('matchesFilterValues', [['application-01'], $terms]),
			'Multiple values in one field must use OR without matching unrelated names.');
		$this->assertTrue($this->call('matchesFilterValues', [['alias', 'DB-42'], $terms]),
			'Host/template aliases should be matched as alternatives.');
	}

	private function testInventoryScopeRejectsUnsafeExpressions(): void {
		$cases = [
			'/unclosed' => 'unclosed regex',
			'/foo/q' => 'unsupported flag',
			'/foo/ii' => 'repeated flag',
			'//i' => 'empty regex',
			'/[a-/' => 'invalid regex'
		];
		foreach ($cases as $expression => $label) {
			$parsed = $this->call('parseFilterExpression', [$expression]);
			$this->assertTrue($parsed['error'] !== null, 'The parser must reject '.$label.'.');
			$this->assertSame([], $parsed['terms'], 'A rejected '.$label.' must not yield usable terms.');
		}

		$too_many = implode(',', array_map(static fn (int $i): string => 'value-'.$i, range(1, 21)));
		$this->assertTrue($this->call('parseFilterExpression', [$too_many])['error'] !== null,
			'More than the allowed number of scope values must be rejected.');
		$too_many_regex = implode(',', array_map(
			static fn (int $i): string => '/regex-'.$i.'/', range(1, 6)
		));
		$this->assertTrue($this->call('parseFilterExpression', [$too_many_regex])['error'] !== null,
			'More than the allowed number of regular expressions must be rejected.');
		$this->assertTrue($this->call('parseFilterExpression', [',,,'])['error'] !== null,
			'A delimiter-only scope must not silently become an unfiltered request.');
		$this->assertTrue($this->call('parseFilterExpression', [str_repeat('x', 257)])['error'] !== null,
			'An oversized individual scope value must be rejected.');
		$this->assertTrue($this->call('parseFilterExpression', [str_repeat('x', 2049)])['error'] !== null,
			'An oversized scope field must be rejected before matching.');

		$fields = [];
		foreach (['group', 'host', 'template'] as $field_index => $field) {
			$values = array_map(
				static fn (int $i): string => 'field-'.$field_index.'-'.$i,
				range(1, 7)
			);
			$fields[$field] = $this->call('parseFilterExpression', [implode(',', $values)])['terms'];
		}
		$this->assertTrue($this->call('validateFilterSet', [$fields]) !== null,
			'The complete three-field scope must enforce one total value cap before API work.');

		$fields = [
			'group' => $this->call('parseFilterExpression', ['/g1/,/g2/'])['terms'],
			'host' => $this->call('parseFilterExpression', ['/h1/,/h2/'])['terms'],
			'template' => $this->call('parseFilterExpression', ['/t1/,/t2/'])['terms']
		];
		$this->assertTrue($this->call('validateFilterSet', [$fields]) !== null,
			'The complete three-field scope must enforce one total regex cap before API work.');
	}

	private function testInventoryScopeCapUsesSentinelAndFailsClosed(): void {
		$target = [];
		$rows = [];
		for ($i = 1; $i <= 5000; $i++) {
			$rows[] = ['hostid' => (string) $i, 'name' => 'host-'.$i, 'host' => 'host-'.$i];
		}
		$truncated = $this->call('mergeApiFilterRows', [&$target, $rows, 'hostid', ['name', 'host'], []]);
		$this->assertSame(false, $truncated,
			'Exactly the supported number of matches must not be reported as truncated.');
		$this->assertSame(5000, count($target), 'All exact-cap matches must be retained.');

		$rows[] = ['hostid' => '5001', 'name' => 'host-5001', 'host' => 'host-5001'];
		$target = [];
		$truncated = $this->call('mergeApiFilterRows', [&$target, $rows, 'hostid', ['name', 'host'], []]);
		$this->assertSame(true, $truncated,
			'The cap-plus-one sentinel must mark scope resolution incomplete.');
		$blocked = $this->call('blockedScopeResolution', [
			[], $target, [], $target, [], [], [], [], ['limit reached'],
			['groups' => false, 'hosts' => true, 'templates' => false, 'resolved_hosts' => true,
				'hosts_available' => true, 'resolved_hosts_available' => true]
		]);
		$this->assertSame(true, $blocked['blocked'], 'An incomplete scope must explicitly block Apply.');
		$this->assertSame([], $blocked['hostids'],
			'An incomplete scope must never expose a partial host allow-list as safe.');
	}

	private function testInventoryScopePreviewDistinguishesUnavailableData(): void {
		$preview = $this->call('unavailableFilterPreview');
		$this->assertSame(false, $preview['available'],
			'A group-only preview must distinguish an unqueried host count from zero hosts.');
		$this->assertSame(null, $preview['count'],
			'An unavailable host count must not be represented as an exact zero.');

		$matches = [
			'2' => ['id' => '2', 'label' => 'Production'],
			'1' => ['id' => '1', 'label' => 'Database']
		];
		$active = ['2' => $matches['2']];
		$term_sample = $this->call('buildFilterTermSample', [
			1, ['kind' => 'literal', 'value' => 'prod'], $active, false
		]);
		$preview = $this->call('buildFilterPreview', [$matches, false, [$term_sample]]);
		$this->assertSame(['Database', 'Production'], array_column($preview['samples'], 'label'),
			'Combined preview samples should be naturally sorted.');
		$this->assertSame(['Production'], array_column($preview['active_samples'], 'label'),
			'Type-ahead must expose samples for the current comma-separated term.');
		$this->assertSame(1, $preview['term_samples'][0]['index'],
			'Per-term samples must retain their parsed token index for cursor-aware suggestions.');
	}

	private function hourlyRow(int $clock, float $value): array {
		return [
			'clock' => $clock,
			'num' => 60,
			'min' => $value,
			'avg' => $value,
			'max' => $value,
			'source' => 'trend',
			'bucket_seconds' => 3600,
			'expected_num' => 60,
			'bucket_coverage_pct' => 100.0,
			'complete' => true,
			'duration_confirmable' => true,
			'baseline_eligible' => true
		];
	}

	private function testSparseResourceBaselineIsRejected(): void {
		$now = 2000000000;
		$rows = [$this->hourlyRow($now - 86400, 50.0)];
		$windows = $this->call('summarizeWindows', [$rows, $now, 85.0, 95.0]);
		$this->assertSame(null, $this->call('selectResourceWindow', [$windows]),
			'Sparse resource data must not become a qualified baseline.');
		$result = $this->call('forecastResource', [
			$this->resourceSpec($now), $rows, 'trends', $now, null
		]);
		$this->assertSame('Unknown', $result['severity'],
			'Sparse non-saturated resource data must remain Unknown.');
	}

	private function testFreshHighCurrentIsWatchWithoutBaseline(): void {
		$now = 2000000000;
		$result = $this->call('forecastResource', [
			$this->resourceSpec($now, 'CPU', 90.0), [], 'none', $now, 'No history'
		]);
		$this->assertSame('Watch', $result['severity'],
			'A fresh value above the review threshold should be Watch even when duration is unknown.');
		$this->assertSame('no_data', $result['status'],
			'The Watch observation must still disclose that no historical baseline exists.');
	}

	private function testNearFullMaximaDoNotInventDuration(): void {
		$now = 2000000000;
		$rows = [];
		foreach ([2, 5] as $day) {
			foreach ([0, 600, 1200] as $offset) {
				$rows[] = $this->fiveMinuteRow($now - $day * 86400 + $offset, 65.0, 72.0, 100.0);
			}
		}
		$result = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now), $rows, $now, true
		]);
		$this->assertSame('Watch', $result['severity'], 'Repeated maxima should be Watch observations.');
		$this->assertSame(6, $result['max_observation_count'], 'All near-full maxima should be counted.');
		$this->assertSame(0, $result['confirmed_episode_count'], 'Maxima alone must not prove duration.');
		$this->assertAlmost(0.0, $result['confirmed_total_minutes'], 0.001,
			'Maxima alone must contribute zero confirmed duration.');
	}

	private function testRecurringThirtyMinuteEpisodesAreHigh(): void {
		$now = 2000000000;
		$rows = [];
		foreach ([2, 4, 6] as $day) {
			$start = $now - $day * 86400;
			for ($bucket = 0; $bucket < 6; $bucket++) {
				$rows[] = $this->fiveMinuteRow($start + $bucket * 300, 96.0, 98.0, 100.0);
			}
		}
		$result = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now), $rows, $now, true
		]);
		$this->assertSame('High', $result['severity'], 'Three recurring 30-minute episodes must be High.');
		$this->assertSame(3, $result['confirmed_episode_count'], 'Episode count should preserve gaps.');
		$this->assertAlmost(30.0, $result['confirmed_longest_minutes'], 0.001,
			'Longest episode should be 30 minutes.');
		$this->assertAlmost(90.0, $result['confirmed_total_minutes'], 0.001,
			'Total confirmed duration should be 90 minutes.');
	}

	private function testOngoingCriticalRequiresCurrentSaturation(): void {
		$now = 2000000000;
		$row = $this->hourlyRow($now - 3600, 97.0);
		$low_current = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now, 'CPU', 70.0), [$row], $now, true
		]);
		$this->assertSame('Medium', $low_current['severity'],
			'A completed saturated hour must not be called ongoing after current utilization recovers.');
		$this->assertAlmost(0.0, $low_current['confirmed_ongoing_minutes'], 0.001,
			'Recovered current utilization must clear ongoing duration.');
		$high_current = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now, 'CPU', 97.0), [$row], $now, true
		]);
		$this->assertSame('Critical', $high_current['severity'],
			'A confirmed 60-minute episode that is still saturated may be Critical.');
	}

	private function testSingleSampleNearFullDurationIsUnknown(): void {
		$now = 2000000000;
		$row = $this->fiveMinuteRow($now - 600, 99.0, 99.0, 99.0);
		$row['num'] = 1;
		$row['duration_confirmable'] = false;
		$result = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now, 'Memory', 70.0), [$row], $now, true
		]);
		$this->assertSame(1, $result['duration_unknown_max_count'],
			'A one-sample near-full bucket must be labelled unknown-duration even when min equals max.');
		$this->assertSame(0, $result['confirmed_episode_count'],
			'A one-sample bucket must not become a confirmed episode.');
	}

	private function testSparseRecentBucketDoesNotHideQualifiedTrendHour(): void {
		$now = 2000000000;
		$hour = intdiv($now - 7200, 3600) * 3600;
		$trend = $this->hourlyRow($hour, 97.0);
		$trend['max'] = 100.0;
		$sparse = $this->fiveMinuteRow($hour + 300, 60.0, 70.0, 100.0);
		$sparse['num'] = 1;
		$sparse['bucket_coverage_pct'] = 20.0;
		$sparse['baseline_eligible'] = false;
		$sparse['duration_confirmable'] = false;
		$result = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now), [$trend, $sparse], $now, true
		]);
		$this->assertSame(1, $result['confirmed_episode_count'],
			'A sparse recent bucket must not suppress qualified trend evidence for the same hour.');
		$this->assertSame(1, $result['confirmed_trend_episode_count'],
			'The retained qualified hour must remain identified as trend evidence.');
		$this->assertSame(1, $result['duration_unknown_max_count'],
			'The sparse raw maximum should still be disclosed as duration-unknown.');
		$this->assertSame(1, $result['max_observation_count'],
			'An overlapping trend maximum must not duplicate the more precise raw peak observation.');
	}

	private function testSparseRecentBucketDoesNotUpgradeConfidence(): void {
		$now = 2000000000;
		$rows = [];
		for ($hour = 31 * 24; $hour >= 1; $hour--) {
			$rows[] = $this->hourlyRow($now - $hour * 3600, 60.0);
		}
		$sparse = $this->fiveMinuteRow($now - 600, 60.0, 60.0, 60.0);
		$sparse['num'] = 1;
		$sparse['bucket_coverage_pct'] = 20.0;
		$sparse['baseline_eligible'] = false;
		$sparse['duration_confirmable'] = false;
		$rows[] = $sparse;
		$result = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now), $rows, $now, true
		]);
		$this->assertTrue($result['coverage_pct'] >= 99.0,
			'Complete hourly trends should still provide broad analysis coverage.');
		$this->assertAlmost(0.0, (float) $result['qualified_recent_hours'], 0.001,
			'One sparse raw bucket must not count as qualified high-resolution coverage.');
		$this->assertSame('Medium', $result['confidence'],
			'Broad trend coverage plus one sparse raw bucket must not be upgraded to High confidence.');
	}

	private function testPollingRawBaselineRetainsTrendDuration(): void {
		$now = 2000000000;
		$hour = intdiv($now - 7200, 3600) * 3600;
		$trend = $this->hourlyRow($hour, 97.0);
		$raw = [];
		for ($bucket = 0; $bucket < 12; $bucket++) {
			$row = $this->fiveMinuteRow($hour + $bucket * 300, 70.0, 70.0, 70.0);
			$row['duration_confirmable'] = false;
			$raw[] = $row;
		}
		$merged = $this->call('mergeRecentResourceRows', [[$trend], $raw]);
		$retained_trend = array_values(array_filter($merged,
			static fn (array $row): bool => ($row['source'] ?? '') === 'trend'));
		$this->assertSame(1, count($retained_trend),
			'Polling-style raw buckets may replace the baseline but must not erase qualified trend duration.');
		$this->assertSame(false, $retained_trend[0]['baseline_eligible'],
			'The retained trend must be saturation-only so it cannot double-count the raw baseline.');
		$this->assertSame(true, $retained_trend[0]['saturation_only'],
			'The retained trend must be explicitly marked for saturation evidence.');
		$result = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now), $merged, $now, true
		]);
		$this->assertSame(1, $result['confirmed_trend_episode_count'],
			'The saturation-only trend must retain its confirmed duration evidence.');

		$mixed_raw = $raw;
		foreach ($mixed_raw as $index => &$row) {
			$row['min'] = 97.0;
			$row['avg'] = 97.0;
			$row['max'] = 97.0;
			$row['duration_confirmable'] = $index < 8;
		}
		unset($row);
		$mixed = $this->call('mergeRecentResourceRows', [[$trend], $mixed_raw]);
		$mixed_result = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now), $mixed, $now, true
		]);
		$this->assertSame(1, $mixed_result['confirmed_episode_count'],
			'Overlapping trend and partially confirmable raw buckets must remain one episode.');
		$this->assertAlmost(60.0, (float) $mixed_result['confirmed_total_minutes'], 0.001,
			'Overlapping raw intervals must not inflate one confirmed trend hour beyond 60 minutes.');
		$this->assertSame(1, $mixed_result['confirmed_trend_episode_count'],
			'The complete trend should provide the single duration source for its saturated hour.');

		$nonconfirming_trend = $trend;
		$nonconfirming_trend['min'] = 90.0;
		$raw_duration = $this->call('mergeRecentResourceRows', [[$nonconfirming_trend], $mixed_raw]);
		$raw_result = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now), $raw_duration, $now, true
		]);
		$this->assertAlmost(40.0, (float) $raw_result['confirmed_total_minutes'], 0.001,
			'Confirmable raw duration must remain when the retained trend minimum does not prove saturation.');
		$this->assertSame(1, $raw_result['confirmed_history_episode_count'],
			'Raw buckets must remain the duration source when the overlapping trend does not confirm saturation.');

		foreach ($raw as &$row) {
			$row['duration_confirmable'] = true;
		}
		unset($row);
		$fully_confirmable = $this->call('mergeRecentResourceRows', [[$trend], $raw]);
		$this->assertSame(0, count(array_filter($fully_confirmable,
			static fn (array $row): bool => ($row['source'] ?? '') === 'trend')),
			'Raw duration-confirmable coverage of at least 75% must replace the overlapping trend.');
	}

	private function testIncompleteNearFullBucketIsCountedWithUnknownDuration(): void {
		$now = 2000000000;
		$row = $this->fiveMinuteRow(intdiv($now - 60, 300) * 300, 99.0, 99.0, 100.0);
		$row['complete'] = false;
		$row['baseline_eligible'] = false;
		$row['duration_confirmable'] = false;
		$result = $this->call('analyzeResourceSaturation', [
			$this->resourceSpec($now), [$row], $now, true
		]);
		$this->assertSame(1, $result['max_observation_count'],
			'An incomplete current bucket maximum must remain visible as an observation.');
		$this->assertSame(1, $result['duration_unknown_max_count'],
			'An incomplete bucket cannot prove peak duration.');
		$this->assertSame(0, $result['confirmed_episode_count'],
			'An incomplete bucket must never become a confirmed episode.');
	}

	private function testDownwardRegimeIsolatedFromCurrentRisk(): void {
		$now = 2000000000;
		$rows = [];
		$start = $now - 42 * 86400;
		for ($hour = 0; $hour < 42 * 24; $hour++) {
			$clock = $start + $hour * 3600;
			$rows[] = $this->hourlyRow($clock, $clock < $now - 21 * 86400 ? 96.0 : 60.0);
		}
		$result = $this->call('forecastResource', [
			$this->resourceSpec($now, 'Memory', 60.0), $rows, 'trends', $now, null
		]);
		$this->assertTrue($result['regime']['detected'], 'The persistent downward regime must be detected.');
		$this->assertSame('downward', $result['regime']['direction'], 'Regime direction should be downward.');
		$this->assertAlmost(60.0, (float) $result['regime']['recent_average'], 0.1,
			'Post-change baseline should be approximately 60%.');
		$this->assertSame('High', $result['historical_saturation']['severity'],
			'Pre-change saturation should remain historical High evidence.');
		$this->assertSame('Healthy', $result['saturation']['severity'],
			'Post-change saturation should be Healthy.');
		$this->assertSame('Healthy', $result['severity'],
			'Historical pressure must not escalate the current capacity rating.');
	}

	private function testImpossiblePercentageRowsAreRejected(): void {
		$rows = [
			$this->fiveMinuteRow(1000, 10.0, 20.0, 30.0),
			$this->fiveMinuteRow(1300, 90.0, 80.0, 100.0),
			$this->fiveMinuteRow(1600, 99.0, 100.0, 100.4)
		];
		[$valid, $rejected] = $this->call('sanitizePercentageRows', [$rows]);
		$this->assertSame(2, count($valid), 'Two plausible percentage rows should remain.');
		$this->assertSame(1, $rejected, 'One internally inconsistent row should be rejected.');
		$this->assertAlmost(100.0, (float) $valid[1]['max'], 0.001,
			'Harmless percentage rounding drift should clamp to 100%.');
	}

	private function testDiskUsableCapacityAvoidsLinuxTotal(): void {
		$linux = $this->call('usableFilesystemCapacity', ['Linux', 1000.0, 700.0, 200.0, 77.78]);
		$this->assertAlmost(900.0, (float) $linux, 0.001,
			'Linux usable capacity must be used plus available space, not total.');
		$this->assertSame(null,
			$this->call('usableFilesystemCapacity', ['Linux', 1000.0, null, null, null]),
			'Linux total alone must not be treated as usable capacity.');
		$this->assertAlmost(1000.0, (float) $this->call('usableFilesystemCapacity',
			['Windows', 1000.0, null, null, null]), 0.001,
			'Windows total may be used as the final capacity fallback.');
	}

	private function testSparseDiskSeriesHasNoModel(): void {
		$now = 2000000000;
		$windows = $this->call('summarizeWindows', [
			[$this->hourlyRow($now - 86400, 1.0)], $now, null, null
		]);
		$this->assertSame(null, $this->call('selectModelWindow', [$windows]),
			'A one-day disk series must not produce a growth model.');
	}

	private function testCurrentDiskBreachSurvivesMissingHistory(): void {
		$now = 2000000000;
		$spec = [
			'id' => 'd0', 'used' => 960.0, 'free' => 40.0, 'pused' => 96.0, 'total' => 1000.0,
			'warn_pct' => 90.0, 'crit_pct' => 95.0, 'warn_free' => 0.0, 'crit_free' => 0.0,
			'os' => 'Linux', 'fs_kind' => 'Local', 'ok' => true
		];
		$result = $this->call('forecastDisk', [$spec, [], 'none', $now, 'No history']);
		$this->assertSame('no_data', $result['status'], 'Missing history should be reported honestly.');
		$this->assertSame('Critical', $result['severity'],
			'A fresh current critical breach must remain Critical without history.');
	}

	private function testDirectPercentageSeriesDrivesPercentageEta(): void {
		$now = 2000000000;
		$byte_rows = [];
		$pct_rows = [];
		for ($hour = 0; $hour < 70 * 24; $hour++) {
			$day = $hour / 24;
			$clock = $now - 70 * 86400 + $hour * 3600;
			$byte_rows[] = $this->hourlyRow($clock, 600000000000.0 + $day * 10000000000.0);
			$pct_rows[] = $this->hourlyRow($clock, 52.75 + $day * 0.25);
		}
		$spec = [
			'id' => 'd0', 'used' => 700000000000.0, 'free' => 300000000000.0,
			'pused' => 70.0, 'total' => 1000000000000.0,
			'warn_pct' => 90.0, 'crit_pct' => 95.0, 'warn_free' => 0.0, 'crit_free' => 0.0,
			'os' => 'Linux', 'fs_kind' => 'Local', 'ok' => true
		];
		$result = $this->call('forecastDisk', [
			$spec, $byte_rows, 'trends', $now, null, $pct_rows, 'trends', null
		]);
		$this->assertTrue($result['pct_series_direct'],
			'The direct pused history should be identified as the percentage source.');
		$this->assertAlmost(0.25, (float) $result['growth_pct_day'], 0.001,
			'Percentage growth must come from direct pused history rather than current-capacity scaling.');
		$this->assertAlmost(80.0, (float) $result['eta']['warn_days'], 0.2,
			'Percentage ETA should use current pused distance divided by the direct percentage slope.');
	}

	private function testLowConfidencePercentageDoesNotCapByteRisk(): void {
		$now = 2000000000;
		$byte_rows = [];
		for ($hour = 0; $hour < 70 * 24; $hour++) {
			$day = $hour / 24;
			$byte_rows[] = $this->hourlyRow($now - 70 * 86400 + $hour * 3600,
				600000000000.0 + $day * 10000000000.0);
		}
		$pct_rows = [];
		for ($hour = 0; $hour < 7 * 24; $hour++) {
			$pct_rows[] = $this->hourlyRow($now - 7 * 86400 + $hour * 3600, 69.0 + $hour / 240.0);
		}
		$spec = [
			'id' => 'd0', 'used' => 950000000000.0, 'free' => 50000000000.0,
			'pused' => 70.0, 'total' => 1000000000000.0,
			'warn_pct' => 90.0, 'crit_pct' => 95.0, 'warn_free' => 0.0, 'crit_free' => 0.0,
			'os' => 'Linux', 'fs_kind' => 'Local', 'ok' => true
		];
		$result = $this->call('forecastDisk', [
			$spec, $byte_rows, 'trends', $now, null, $pct_rows, 'trends', null
		]);
		$this->assertSame('Low', $result['pct_confidence'], 'The short pused model should be low confidence.');
		$this->assertSame('High', $result['byte_confidence'], 'The sustained byte model should be high confidence.');
		$this->assertSame('Critical', $result['severity'],
			'An unrelated low-confidence pused model must not cap a high-confidence near-full byte forecast.');
	}

	private function testInvalidDirectPercentageFallsBackToDerived(): void {
		$now = 2000000000;
		$byte_rows = [];
		$invalid_pct_rows = [];
		for ($hour = 0; $hour < 70 * 24; $hour++) {
			$day = $hour / 24;
			$clock = $now - 70 * 86400 + $hour * 3600;
			$byte_rows[] = $this->hourlyRow($clock, 600000000000.0 + $day * 10000000000.0);
			$invalid_pct_rows[] = $this->hourlyRow($clock, 150.0);
		}
		$spec = [
			'id' => 'd0', 'used' => 700000000000.0, 'free' => 300000000000.0,
			'pused' => 70.0, 'total' => 1000000000000.0,
			'warn_pct' => 90.0, 'crit_pct' => 95.0, 'warn_free' => 0.0, 'crit_free' => 0.0,
			'os' => 'Linux', 'fs_kind' => 'Local', 'ok' => true
		];
		$result = $this->call('forecastDisk', [
			$spec, $byte_rows, 'trends', $now, null, $invalid_pct_rows, 'trends', null
		]);
		$this->assertSame(false, $result['pct_series_direct'],
			'An entirely invalid direct pused series must not be labelled as the active source.');
		$this->assertAlmost(1.0, (float) $result['growth_pct_day'], 0.001,
			'After invalid direct pused data, percentage growth should fall back to byte growth over usable capacity.');
	}

	private function testUnqualifiedDirectPercentageFallsBackToDerived(): void {
		$now = 2000000000;
		$byte_rows = [];
		for ($hour = 0; $hour < 70 * 24; $hour++) {
			$day = $hour / 24;
			$byte_rows[] = $this->hourlyRow($now - 70 * 86400 + $hour * 3600,
				600000000000.0 + $day * 10000000000.0);
		}
		$sparse_pct_rows = [];
		for ($hour = 0; $hour < 24; $hour++) {
			$sparse_pct_rows[] = $this->hourlyRow($now - 86400 + $hour * 3600, 69.0 + $hour / 24.0);
		}
		$spec = [
			'id' => 'd0', 'used' => 700000000000.0, 'free' => 300000000000.0,
			'pused' => 70.0, 'total' => 1000000000000.0,
			'warn_pct' => 90.0, 'crit_pct' => 95.0, 'warn_free' => 0.0, 'crit_free' => 0.0,
			'os' => 'Linux', 'fs_kind' => 'Local', 'ok' => true
		];
		$result = $this->call('forecastDisk', [
			$spec, $byte_rows, 'trends', $now, null, $sparse_pct_rows, 'trends', null
		]);
		$this->assertSame(false, $result['pct_series_direct'],
			'Valid but unqualified direct pused history must yield to a qualified derived model.');
		$this->assertAlmost(1.0, (float) $result['growth_pct_day'], 0.001,
			'Sparse direct pused history should fall back to the qualified byte slope.');
		$this->assertTrue(strpos((string) $result['note'], 'did not meet') !== false,
			'The report must disclose why direct pused history was not used.');
	}

	private function testFreshResourceAlternativesBeatStalePreferredItems(): void {
		$now = 2000000000;
		$hosts = ['h1' => $this->host()];

		$items = ['h1' => [
			$this->item('1', 'system.cpu.util', 99.0, $now - 5 * 3600),
			$this->item('2', 'vm.cpu.util', 25.0, $now - 60),
			$this->item('3', 'vm.memory.utilization', 50.0, $now - 60)
		]];
		$result = $this->call('buildResourceFindings', [$hosts, $items, $this->macroIndex(), $now]);
		$this->assertSame('2', $result[0]['itemid'],
			'A fresh alternate CPU key must beat a stale preferred key.');
		$this->assertAlmost(25.0, (float) $result[0]['current'], 0.001,
			'The selected CPU value must come from the fresh alternate.');

		$items = ['h1' => [
			$this->item('4', 'system.cpu.util', 99.0, $now - 5 * 3600),
			$this->item('5', 'system.cpu.util[,idle]', 70.0, $now - 30),
			$this->item('6', 'vm.memory.utilization', 98.0, $now - 5 * 3600),
			$this->item('7', 'vm.memory.size[pavailable]', 65.0, $now - 30)
		]];
		$result = $this->call('buildResourceFindings', [$hosts, $items, $this->macroIndex(), $now]);
		$this->assertSame('5', $result[0]['itemid'], 'Fresh CPU idle must beat stale direct utilization.');
		$this->assertAlmost(30.0, (float) $result[0]['current'], 0.001,
			'CPU idle must be inverted to used utilization.');
		$this->assertSame('7', $result[1]['itemid'],
			'Fresh pavailable must beat stale direct memory utilization.');
		$this->assertAlmost(35.0, (float) $result[1]['current'], 0.001,
			'Memory pavailable must be inverted to used utilization.');

		$items = ['h1' => [
			$this->item('8', 'system.cpu.util', 150.0, $now - 10),
			$this->item('9', 'system.cpu.util[,idle]', 70.0, $now - 20),
			$this->item('10', 'vm.memory.utilization', 150.0, $now - 10),
			$this->item('11', 'vm.memory.size[pavailable]', 65.0, $now - 20)
		]];
		$result = $this->call('buildResourceFindings', [$hosts, $items, $this->macroIndex(), $now]);
		$this->assertSame('9', $result[0]['itemid'],
			'A plausible transformed CPU source must beat a newer impossible direct value.');
		$this->assertSame('11', $result[1]['itemid'],
			'A plausible transformed memory source must beat a newer impossible direct value.');

		$items['h1'] = [
			$this->item('12', 'system.cpu.util', 40.0, $now - 120),
			$this->item('13', 'vm.cpu.util', 45.0, $now - 30),
			$this->item('14', 'vm.memory.utilization', 50.0, $now - 30)
		];
		$result = $this->call('buildResourceFindings', [$hosts, $items, $this->macroIndex(), $now]);
		$this->assertSame('13', $result[0]['itemid'],
			'Equivalent fresh utilization sources must be ordered by actual recency before key preference.');
	}

	private function testFreshFilesystemCompositionBeatsStaleCompleteness(): void {
		$now = 2000000000;
		$hosts = ['h1' => $this->host()];
		$items = ['h1' => [
			$this->item('1', 'vfs.fs.size[/data,used]', 900.0, $now - 25 * 3600),
			$this->item('2', 'vfs.fs.size[/data,free]', 100.0, $now - 25 * 3600),
			$this->item('3', 'vfs.fs.size[/data,total]', 1000.0, $now - 25 * 3600),
			$this->item('4', 'vfs.fs.dependent.size[/data,used]', 600.0, $now - 60),
			$this->item('5', 'vfs.fs.dependent.size[/data,free]', 400.0, $now - 60)
		]];
		$result = $this->call('buildDiskFindings', [$hosts, $items, $this->macroIndex(), $now]);
		$this->assertTrue(strpos($result[0]['item_key'], '.dependent.') !== false,
			'A fresh usable filesystem family must beat a stale fuller family.');
		$this->assertSame('OK', $result[0]['status'], 'The fresh dependent composition must be current.');
		$this->assertAlmost(60.0, (float) $result[0]['pused'], 0.001,
			'Current percentage must come from the selected fresh family.');

		$items = ['h1' => [
			$this->item('6', 'vfs.fs.size[/bad,used]', -1.0, $now - 10),
			$this->item('7', 'vfs.fs.size[/bad,free]', 100.0, $now - 10),
			$this->item('8', 'vfs.fs.size[/bad,total]', 1000.0, $now - 10),
			$this->item('9', 'vfs.fs.size[/bad,pused]', 150.0, $now - 10),
			$this->item('10', 'vfs.fs.dependent.size[/bad,used]', 500.0, $now - 60),
			$this->item('11', 'vfs.fs.dependent.size[/bad,free]', 500.0, $now - 60)
		]];
		$result = $this->call('buildDiskFindings', [$hosts, $items, $this->macroIndex(), $now]);
		$this->assertTrue(strpos($result[0]['item_key'], '.dependent.') !== false,
			'Invalid metrics must not make an underivable family win selection.');
	}

	private function testStaleOptionalLinuxMetricsDoNotMakeFilesystemStale(): void {
		$now = 2000000000;
		$items = ['h1' => [
			$this->item('1', 'vfs.fs.size[/,used]', 900.0, $now - 60),
			$this->item('2', 'vfs.fs.size[/,free]', 100.0, $now - 60),
			$this->item('3', 'vfs.fs.size[/,total]', 1200.0, $now - 25 * 3600),
			$this->item('4', 'vfs.fs.size[/,pused]', 90.0, $now - 25 * 3600)
		]];
		$this->resetQuality();
		$result = $this->call('buildDiskFindings', [
			['h1' => $this->host()], $items, $this->macroIndex(), $now
		]);
		$this->assertSame('OK', $result[0]['status'],
			'Fresh Linux used+free must be complete despite stale optional total and pused.');
		$this->assertSame(['total', 'pused'], $result[0]['stale_metrics'],
			'Optional stale metrics should remain disclosed without controlling status.');
		$stale = array_filter($this->qualityIssues(),
			static fn (array $issue): bool => $issue['issue'] === 'Stale filesystem data');
		$this->assertSame(0, count($stale), 'Optional stale metrics must not create a stale-data warning.');
	}

	private function testWindowsUsedAndFreeDoNotRequireFreshTotal(): void {
		$now = 2000000000;
		$items = ['h1' => [
			$this->item('1', 'vfs.fs.size[C:,used]', 600.0, $now - 60),
			$this->item('2', 'vfs.fs.size[C:,free]', 400.0, $now - 60),
			$this->item('3', 'vfs.fs.size[C:,total]', 1000.0, $now - 25 * 3600)
		]];
		$result = $this->call('buildDiskFindings', [
			['h1' => $this->host('Windows')], $items, $this->macroIndex(), $now
		]);
		$this->assertSame('OK', $result[0]['status'],
			'Windows used+free is a complete snapshot without a fresh total metric.');
		$this->assertAlmost(1000.0, (float) $result[0]['usable'], 0.001,
			'Windows usable capacity should be derived from used+free.');
	}

	private function testInvalidFilesystemValuesDoNotCreateCurrentBreach(): void {
		$now = 2000000000;
		$hosts = ['h1' => $this->host()];
		$this->resetQuality();
		$optional = ['h1' => [
			$this->item('1', 'vfs.fs.size[/,used]', 960.0, $now - 60),
			$this->item('2', 'vfs.fs.size[/,free]', 40.0, $now - 60),
			$this->item('3', 'vfs.fs.size[/,total]', -1.0, $now - 30)
		]];
		$result = $this->call('buildDiskFindings', [$hosts, $optional, $this->macroIndex(), $now]);
		$this->assertSame('OK', $result[0]['status'],
			'An invalid optional total must not poison valid used+free.');
		$this->assertSame('Critical', $result[0]['current_severity'],
			'Valid used+free should still produce a real current breach.');
		$invalid = array_filter($this->qualityIssues(),
			static fn (array $issue): bool => $issue['issue'] === 'Invalid current filesystem value');
		$this->assertSame(1, count($invalid), 'Invalid optional values must be reported exactly once.');

		$this->resetQuality();
		$required = ['h1' => [
			$this->item('4', 'vfs.fs.size[/broken,used]', -1.0, $now - 20),
			$this->item('5', 'vfs.fs.size[/broken,free]', 1.0, $now - 20),
			$this->item('6', 'vfs.fs.size[/broken,pused]', 150.0, $now - 20)
		]];
		$result = $this->call('buildDiskFindings', [$hosts, $required, $this->macroIndex(), $now]);
		$this->assertSame('Incomplete', $result[0]['status'],
			'Invalid required values must leave the filesystem incomplete.');
		$this->assertSame('Unknown', $result[0]['current_severity'],
			'Invalid required bytes must not trigger a current breach.');
	}

	private function testFilesystemStaleStatusRequiresNeededContributor(): void {
		$now = 2000000000;
		$hosts = ['h1' => $this->host()];
		$used_total = ['h1' => [
			$this->item('1', 'vfs.fs.size[/one,used]', 600.0, $now - 60),
			$this->item('2', 'vfs.fs.size[/one,total]', 1000.0, $now - 25 * 3600)
		]];
		$result = $this->call('buildDiskFindings', [$hosts, $used_total, $this->macroIndex(), $now]);
		$this->assertSame('Incomplete', $result[0]['status'],
			'A stale Linux total cannot complete fresh used and must not cause Stale status.');

		$used_free = ['h1' => [
			$this->item('3', 'vfs.fs.size[/two,used]', 600.0, $now - 60),
			$this->item('4', 'vfs.fs.size[/two,free]', 400.0, $now - 25 * 3600)
		]];
		$result = $this->call('buildDiskFindings', [$hosts, $used_free, $this->macroIndex(), $now]);
		$this->assertSame('Stale', $result[0]['status'],
			'A stale free metric that would complete used+free must cause Stale status.');
	}

	private function testPfreeSupportsCurrentAndHistoricalUsedPercent(): void {
		$now = 2000000000;
		$items = ['h1' => [
			$this->item('1', 'vfs.fs.size[/data,free]', 400.0, $now - 60),
			$this->item('2', 'vfs.fs.size[/data,pfree]', 40.0, $now - 30)
		]];
		$result = $this->call('buildDiskFindings', [
			['h1' => $this->host()], $items, $this->macroIndex(), $now
		]);
		$this->assertSame('OK', $result[0]['status'], 'Fresh free+pfree must be a valid composition.');
		$this->assertAlmost(60.0, (float) $result[0]['pused'], 0.001,
			'Current pfree must be inverted to pused.');
		$this->assertAlmost(1000.0, (float) $result[0]['usable'], 0.001,
			'Free bytes and pfree must derive usable capacity.');
		$this->assertSame('2', $result[0]['pct_itemid'], 'Pfree should be selected as the direct percentage series.');
		$this->assertSame('invert', $result[0]['pct_tr'], 'Pfree history must carry an invert transform.');
		$this->assertAlmost(100.0, (float) $result[0]['pct_pr'], 0.001,
			'Pfree history inversion must use 100 percent.');
		$rows = $this->call('transformRows', [[
			['clock' => $now - 3600, 'num' => 1, 'min' => 35.0, 'avg' => 40.0, 'max' => 45.0]
		], 'invert', 100.0]);
		$this->assertAlmost(60.0, (float) $rows[0]['avg'], 0.001,
			'Historical pfree averages must become used-percent averages.');
		$this->assertAlmost(55.0, (float) $rows[0]['min'], 0.001,
			'Historical inversion must swap extrema correctly.');
		$this->assertAlmost(65.0, (float) $rows[0]['max'], 0.001,
			'Historical inversion must swap extrema correctly.');

		$with_direct_pused = ['h1' => [
			$this->item('6', 'vfs.fs.size[/direct,used]', 600.0, $now - 60),
			$this->item('7', 'vfs.fs.size[/direct,free]', 400.0, $now - 60),
			$this->item('8', 'vfs.fs.size[/direct,pused]', 60.0, $now - 20)
		]];
		$direct_result = $this->call('buildDiskFindings', [
			['h1' => $this->host()], $with_direct_pused, $this->macroIndex(), $now
		]);
		$this->assertSame('8', $direct_result[0]['pct_itemid'],
			'Fresh direct pused history must remain available when used+free supplies current bytes.');
		$this->assertSame('identity', $direct_result[0]['pct_tr'],
			'Direct pused history must remain an identity percentage series.');

		$conflicting = ['h1' => [
			$this->item('3', 'vfs.fs.size[/conflict,used]', 500.0, $now - 10),
			$this->item('4', 'vfs.fs.size[/conflict,pused]', 50.0, $now - 120),
			$this->item('5', 'vfs.fs.size[/conflict,pfree]', 10.0, $now - 30)
		]];
		$result = $this->call('buildDiskFindings', [
			['h1' => $this->host()], $conflicting, $this->macroIndex(), $now
		]);
		$this->assertAlmost(90.0, (float) $result[0]['pused'], 0.001,
			'Current pused must be derived from the fresher selected pfree composition.');
		$this->assertSame('5', $result[0]['pct_itemid'],
			'The percentage history item must match the selected composition contributor.');
	}

	private function testSameDepthMacroUsesLowestNumericTemplateId(): void {
		$macro = static function (string $raw, string $value, string $entityid): array {
			return ['raw' => $raw, 'base' => 'CPU.UTIL.CRIT', 'context' => null,
				'regex' => false, 'value' => $value, 'type' => 0, 'entityid' => $entityid];
		};
		$index = [
			'by_entity' => [
				'h' => [],
				'2' => [$macro('{$CPU.UTIL.CRIT}', '90', '2')],
				'10' => [$macro('{$CPU.UTIL.CRIT}', '95', '10')]
			],
			'levels' => ['h' => [0 => ['10', '2']]],
			'global' => []
		];
		$result = $this->call('resolveMacro', [$index, 'h', 'CPU.UTIL.CRIT', []]);
		$this->assertSame(false, $result['ambiguous'],
			'Different same-depth template IDs have deterministic numeric precedence.');
		$this->assertSame('90', $result['value'],
			'The lowest numeric template ID must win same-depth precedence.');
		$index['levels']['h'][0] = ['2', '10'];
		$reversed = $this->call('resolveMacro', [$index, 'h', 'CPU.UTIL.CRIT', []]);
		$this->assertSame($result['value'], $reversed['value'],
			'Reversing same-depth template input order must not change the selected value.');
	}

	private function testTemplateTopologyTraversalIsBoundedAndCycleSafe(): void {
		$graph = [
			'2' => [['templateid' => '10']],
			'10' => [['templateid' => '2']]
		];
		$loader = static function (array $ids) use ($graph): array {
			$rows = [];
			foreach ($ids as $id) {
				if (isset($graph[$id])) {
					$rows[] = ['templateid' => $id, 'parentTemplates' => $graph[$id]];
				}
			}
			return $rows;
		};
		$result = $this->call('traverseTemplateTopology', [['2'], $loader, 10]);
		$this->assertSame(false, $result['incomplete'], 'A reachable cycle must terminate without truncation.');
		$this->assertSame(['10'], $result['parents']['2'], 'The direct template parent must be retained.');
		$this->assertSame(['2'], $result['parents']['10'], 'Cycle protection must retain but not revisit the edge.');

		$cap_loader = static function (array $ids): array {
			$rows = [];
			foreach ($ids as $id) {
				$parents = $id === '1' ? [['templateid' => '2'], ['templateid' => '3']] : [];
				$rows[] = ['templateid' => $id, 'parentTemplates' => $parents];
			}
			return $rows;
		};
		$capped = $this->call('traverseTemplateTopology', [['1'], $cap_loader, 2]);
		$this->assertSame(true, $capped['incomplete'],
			'The explicit topology safety cap must surface incomplete resolution.');
		$this->assertTrue(strpos($capped['reason'], 'safety cap') !== false,
			'The incomplete topology result must explain that its safety cap was reached.');
	}

	private function testBoundedItemIngestionDetectsSentinelRow(): void {
		$row = static function (string $itemid): array {
			return [
				'itemid' => $itemid,
				'hostid' => '1',
				'name' => 'Item '.$itemid,
				'key_' => 'system.cpu.test['.$itemid.']',
				'value_type' => 0,
				'units' => '%',
				'lastvalue' => '50',
				'lastclock' => '2000000000',
				'state' => 0,
				'tags' => []
			];
		};
		[$accepted, $truncated] = $this->call('ingestBoundedItemRows', [
			[], [$row('1'), $row('2'), $row('3')], 2
		]);
		$this->assertSame(true, $truncated,
			'The remaining-capacity plus one sentinel row must mark item ingestion truncated.');
		$this->assertSame(['1', '2'], array_map('strval', array_keys($accepted)),
			'Bounded item ingestion must stop exactly at the global capacity.');

		[$exact, $exact_truncated] = $this->call('ingestBoundedItemRows', [
			[], [$row('1'), $row('2')], 2
		]);
		$this->assertSame(false, $exact_truncated,
			'Without a sentinel row the pure ingestion step must not invent query truncation.');
		$this->assertSame(2, count($exact), 'An exact-fit result must retain both rows.');
	}

	private function testHostMaintenanceIsNormalized(): void {
		$no_data = $this->call('normalizeHostMaintenance', [[
			'maintenance_status' => '1', 'maintenance_type' => '1',
			'maintenanceid' => '42', 'maintenance_from' => '1999990000'
		]]);
		$this->assertSame(true, $no_data['active'], 'Effective maintenance must be marked active.');
		$this->assertSame('no_data_collection', $no_data['type'],
			'Maintenance type 1 must be exposed as no-data collection.');
		$this->assertSame('42', $no_data['id'], 'The effective maintenance ID must remain a string.');
		$this->assertSame(1999990000, $no_data['since'], 'The effective maintenance start must be retained.');

		$with_data = $this->call('normalizeHostMaintenance', [[
			'maintenance_status' => 1, 'maintenance_type' => 0
		]]);
		$this->assertSame('with_data_collection', $with_data['type'],
			'Active type 0 maintenance must continue to permit current observations.');

		$inactive = $this->call('normalizeHostMaintenance', [[
			'maintenance_status' => 0, 'maintenance_type' => 1,
			'maintenanceid' => '42', 'maintenance_from' => 1999990000
		]]);
		$this->assertSame(['active' => false, 'type' => 'none', 'id' => null, 'since' => null], $inactive,
			'Inactive residual maintenance fields must not become an active no-data state.');
	}

	private function testMaintenanceStalenessAttributionIsBounded(): void {
		$since = 1999990000;
		$host = ['maintenance' => [
			'active' => true, 'type' => 'no_data_collection', 'id' => '42', 'since' => $since
		]];
		$this->assertSame(true, $this->call('maintenanceExplainsStaleValue', [$host, $since - 60, 4 * 3600]),
			'A value that was fresh when maintenance began may be treated as an expected gap.');
		$this->assertSame(false,
			$this->call('maintenanceExplainsStaleValue', [$host, $since - 4 * 3600 - 1, 4 * 3600]),
			'A value already stale before maintenance must remain a quality issue.');
		$this->assertSame(false, $this->call('maintenanceExplainsStaleValue', [$host, 0, 4 * 3600]),
			'Maintenance must not invent a cause for an item that never collected a value.');

		$host['maintenance']['type'] = 'with_data_collection';
		$this->assertSame(false, $this->call('maintenanceExplainsStaleValue', [$host, $since - 60, 4 * 3600]),
			'Maintenance with data collection must not excuse stale telemetry.');
	}

	private function testMaintenanceExplainedResourceGapIsNotQualityFailure(): void {
		$now = 2000000000;
		$since = $now - 5 * 3600;
		$lastclock = $since - 60;
		$hosts = ['h1' => [
			'name' => 'maintenance-host', 'os' => 'Linux',
			'maintenance' => ['active' => true, 'type' => 'no_data_collection', 'id' => '42', 'since' => $since]
		]];
		$items = ['h1' => [[
			'itemid' => '1', 'key' => 'system.cpu.util', 'name' => 'CPU utilization',
			'state' => 0, 'lastvalue' => 99.0, 'lastclock' => $lastclock
		]]];
		$macro_index = ['by_entity' => ['h1' => []], 'levels' => ['h1' => []], 'global' => []];

		$this->resetQuality();
		$result = $this->call('buildResourceFindings', [$hosts, $items, $macro_index, $now]);
		$cpu = $result[0];
		$this->assertSame('Stale', $cpu['status'], 'Maintenance must not hide the underlying stale status.');
		$this->assertSame(true, $cpu['expected_gap'], 'The maintenance-created CPU gap must be explicit.');
		$this->assertSame(false, $cpu['current_observation_usable'],
			'The last accepted maintenance value must not be treated as current.');
		$stale_issues = array_values(array_filter($this->qualityIssues(),
			static fn (array $issue): bool => ($issue['issue'] ?? '') === 'CPU data is stale'));
		$this->assertSame(0, count($stale_issues),
			'An explained maintenance gap must not generate a generic stale-data warning.');

		$items['h1'][0]['lastclock'] = $since - 4 * 3600 - 1;
		$this->resetQuality();
		$preexisting = $this->call('buildResourceFindings', [$hosts, $items, $macro_index, $now]);
		$this->assertSame(false, $preexisting[0]['expected_gap'],
			'A CPU value already stale before maintenance must not be marked expected.');
		$stale_issues = array_values(array_filter($this->qualityIssues(),
			static fn (array $issue): bool => ($issue['issue'] ?? '') === 'CPU data is stale'));
		$this->assertSame(1, count($stale_issues),
			'Pre-existing CPU staleness must remain a data-quality warning during maintenance.');
	}

	private function testMaintenanceExplainedDiskGapIsNotQualityFailure(): void {
		$now = 2000000000;
		$since = $now - 25 * 3600;
		$lastclock = $since - 60;
		$hosts = ['h1' => [
			'name' => 'maintenance-host', 'os' => 'Linux',
			'maintenance' => ['active' => true, 'type' => 'no_data_collection', 'id' => '42', 'since' => $since]
		]];
		$values = ['total' => 1000.0, 'used' => 960.0, 'free' => 40.0, 'pused' => 96.0];
		$items = [];
		$itemid = 1;
		foreach ($values as $metric => $value) {
			$items[] = [
				'itemid' => (string) $itemid++, 'key' => 'vfs.fs.size[/,'.$metric.']',
				'name' => 'Filesystem / '.$metric, 'state' => 0, 'lastvalue' => $value,
				'lastclock' => $lastclock, 'tags' => []
			];
		}
		$macro_index = ['by_entity' => ['h1' => []], 'levels' => ['h1' => []], 'global' => []];

		$this->resetQuality();
		$result = $this->call('buildDiskFindings', [$hosts, ['h1' => $items], $macro_index, $now]);
		$this->assertSame(1, count($result), 'The maintained filesystem must remain visible.');
		$this->assertSame('Stale', $result[0]['status'], 'Maintenance must preserve disk data status.');
		$this->assertSame(true, $result[0]['expected_gap'], 'The maintenance-created disk gap must be explicit.');
		$this->assertSame('Unknown', $result[0]['current_severity'],
			'Last accepted disk usage must not become a current breach.');
		$stale_issues = array_values(array_filter($this->qualityIssues(),
			static fn (array $issue): bool => ($issue['issue'] ?? '') === 'Stale filesystem data'));
		$this->assertSame(0, count($stale_issues),
			'An explained filesystem maintenance gap must not generate a generic stale warning.');
	}

	private function testMaintenanceBlocksCurrentForecastEscalation(): void {
		$now = 2000000000;
		$resource_spec = $this->resourceSpec($now, 'CPU', 99.0);
		$resource_spec['current_observation_usable'] = false;
		$resource = $this->call('forecastResource', [$resource_spec, [], 'none', $now, null]);
		$this->assertSame('Unknown', $resource['severity'],
			'A last accepted CPU value must not create an ongoing current-risk rating during no-data maintenance.');
		$this->assertTrue(strpos(implode(' ', $resource['reasons']), 'maintenance without data collection') !== false,
			'The resource forecast must disclose why the last value was not treated as current.');

		$disk_spec = [
			'id' => 'd0', 'used' => 960.0, 'free' => 40.0, 'pused' => 96.0, 'total' => 1000.0,
			'warn_pct' => 90.0, 'crit_pct' => 95.0, 'warn_free' => 0.0, 'crit_free' => 0.0,
			'os' => 'Linux', 'fs_kind' => 'Local', 'ok' => true,
			'current_observation_usable' => false
		];
		$disk = $this->call('forecastDisk', [$disk_spec, [], 'none', $now, null]);
		$this->assertSame('Unknown', $disk['severity'],
			'A last accepted filesystem value must not create a current critical rating during maintenance.');
		$this->assertSame(null, $disk['eta']['crit_days'],
			'An ETA must not use a last accepted value as the current starting point.');
	}

	private function testForecastSpecValidatesCurrentObservationFlag(): void {
		$entry = [
			'id' => 'r0', 'itemid' => '1', 'kind' => 'res', 'rtype' => 'CPU',
			'current_observation_usable' => false
		];
		$parsed = $this->call('parseSpecs', [json_encode([$entry])]);
		$this->assertSame(false, $parsed[0]['current_observation_usable'],
			'The forecast parser must preserve an explicit unusable-current flag.');

		$entry['current_observation_usable'] = 'false';
		$this->assertSame(null, $this->call('parseSpecs', [json_encode([$entry])]),
			'The forecast parser must reject a non-boolean current-observation flag.');
	}

	private function testForecastSpecEnforcesResourceBatchLimit(): void {
		$res = static fn (int $i): array => [
			'id' => 'r'.$i, 'itemid' => (string) ($i + 1), 'kind' => 'res', 'rtype' => 'CPU'
		];
		$disk = static fn (int $i): array => [
			'id' => 'd'.$i, 'itemid' => (string) (100 + $i), 'kind' => 'disk'
		];

		$this->assertSame(null,
			$this->call('parseSpecs', [json_encode([$res(0), $res(1), $res(2)])]),
			'Resource forecast batches beyond the advertised limit must be rejected server-side.');

		$two = $this->call('parseSpecs', [json_encode([$res(0), $res(1)])]);
		$this->assertSame(2, count($two),
			'A resource forecast batch at the advertised limit must be accepted.');

		$mixed = $this->call('parseSpecs',
			[json_encode(array_merge([$res(0), $res(1)], array_map($disk, range(0, 7))))]);
		$this->assertSame(10, count($mixed),
			'Disk specs must not count against the resource forecast batch limit.');
	}

	private function testDownsampleSeriesTimestamps(): void {
		$base = 86400 * 19000;

		$dense = $this->call('downsampleSeries', [[
			['clock' => $base + 3600, 'num' => 2, 'min' => 1.0, 'avg' => 2.0, 'max' => 3.0],
			['clock' => $base + 86400 + 3600, 'num' => 1, 'min' => 2.0, 'avg' => 4.0, 'max' => 6.0],
			['clock' => $base + 2 * 86400 + 3600, 'num' => 1, 'min' => 3.0, 'avg' => 5.0, 'max' => 7.0]
		]]);
		$this->assertSame([$base, $base + 86400, $base + 2 * 86400], array_column($dense, 0),
			'Ungrouped dense series must keep exact UTC day-start timestamps.');
		$this->assertSame(2.0, $dense[0][2],
			'The dense day average must be unchanged by the timestamp weighting.');

		// 421 populated days force two-day groups. The first group pairs day 0
		// (three samples) with day 40 (one sample) across a 40-day gap.
		$rows = [
			['clock' => $base + 100, 'num' => 3, 'min' => 1.0, 'avg' => 2.0, 'max' => 3.0],
			['clock' => $base + 40 * 86400 + 200, 'num' => 1, 'min' => 4.0, 'avg' => 4.0, 'max' => 4.0]
		];
		for ($i = 41; $i <= 459; $i++) {
			$rows[] = ['clock' => $base + $i * 86400, 'num' => 1, 'min' => 5.0, 'avg' => 5.0, 'max' => 5.0];
		}
		$sparse = $this->call('downsampleSeries', [$rows]);
		$this->assertSame($base + 10 * 86400, $sparse[0][0],
			'A sparse group must be stamped at its sample-weighted mean clock, not its first day.');
		$this->assertSame(2.5, $sparse[0][2],
			'The grouped average must stay sample-weighted.');
		$this->assertTrue($sparse[0][0] >= $base && $sparse[0][0] <= $base + 40 * 86400,
			'The stamped clock must lie inside the group\'s populated day range.');
	}

	private function testMissingResourceItemsRemainVisible(): void {
		$hosts = ['h1' => [
			'name' => 'host-without-items', 'os' => 'Linux',
			'maintenance' => [
				'active' => true, 'type' => 'no_data_collection', 'id' => '42', 'since' => 1999990000
			]
		]];
		$macro_index = ['by_entity' => ['h1' => []], 'levels' => ['h1' => []], 'global' => []];
		$result = $this->call('buildResourceFindings', [$hosts, [], $macro_index, 2000000000]);
		$this->assertSame(2, count($result),
			'A selected host without visible items must still have CPU and memory coverage findings.');
		$this->assertSame(['Missing', 'Missing'], array_column($result, 'status'),
			'Missing CPU and memory collection must be explicit instead of disappearing from the report.');
		$this->assertSame([false, false], array_column($result, 'expected_gap'),
			'Maintenance must not be claimed as the cause of missing item configuration.');
	}

	private function testInventoryFacetsAreCompactAndSorted(): void {
		$hosts = [
			'10' => ['name' => 'web10', 'groups' => ['Web', '', 'Production'], 'os' => 'Linux'],
			'2' => ['name' => 'web2', 'groups' => ['Production', 'Databases'], 'os' => 'Windows']
		];
		$result = $this->call('buildInventoryFacets', [$hosts]);
		$this->assertSame(['web2', 'web10'], array_column($result['hosts'], 'name'),
			'Host facet labels should use natural case-insensitive ordering.');
		$this->assertSame(['2', '10'], array_column($result['hosts'], 'hostid'),
			'Host facet IDs must remain strings for exact browser-side matching.');
		$this->assertSame(['Databases', 'Production'], $result['hosts'][0]['groups'],
			'Each host group list should be sorted.');
		$this->assertSame(['Production', 'Web'], $result['hosts'][1]['groups'],
			'Empty group names should be removed from the host facet.');
		$this->assertSame(['Databases', 'Production', 'Web'], $result['hostgroups'],
			'Top-level group facets should be deduplicated and naturally sorted.');
		$this->assertSame('none', $result['hosts'][0]['maintenance']['type'],
			'Every host facet must carry a normalized maintenance state.');
	}
}

(new CapacityPlanningMathTest())->run();
