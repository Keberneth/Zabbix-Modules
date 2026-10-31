<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'wasm'], true)) {
	http_response_code(404);
	exit;
}

if (!class_exists('CController')) { class CController {} }
if (!class_exists('CControllerResponseData')) { class CControllerResponseData {} }
if (!class_exists('API')) { class API {} }
if (!function_exists('_')) { function _(string $value): string { return $value; } }

require_once dirname(__DIR__).'/actions/CapacityPlanningData.php';

use Modules\CapacityPlanning\Actions\CapacityPlanningData;

$controller = (new ReflectionClass(CapacityPlanningData::class))->newInstanceWithoutConstructor();
$call = static function (string $method, array $arguments) use ($controller) {
	$reflection = new ReflectionMethod(CapacityPlanningData::class, $method);
	if (PHP_VERSION_ID < 80100) { $reflection->setAccessible(true); }
	return $reflection->invokeArgs($controller, $arguments);
};
$assertions = 0;
$check = static function (bool $condition, string $message) use (&$assertions): void {
	$assertions++;
	if (!$condition) { throw new RuntimeException($message); }
};
$near = static fn ($a, $b): bool => abs($a - $b) < 0.00001;
$now = 20000 * 86400;
$row = static fn (int $clock, int $num = 60, int $seconds = 3600): array => [
	'clock' => $clock, 'num' => $num, 'min' => 50.0, 'avg' => 50.0, 'max' => 50.0,
	'source' => 'trend', 'bucket_seconds' => $seconds, 'expected_num' => $seconds / 60,
	'bucket_coverage_pct' => min(100.0, $num / ($seconds / 60) * 100),
	'complete' => true, 'duration_confirmable' => $num >= 2, 'baseline_eligible' => true
];
$spec = ['id' => 'r0', 'rtype' => 'CPU', 'current' => 50.0,
	'lastclock' => $now, 'data_status' => 'OK', 'warn' => 85.0, 'crit' => 95.0];

// One received sample per expected minute of a whole hour is only 1/60 coverage.
$sparse = [];
for ($hour = 0; $hour < 31 * 24; $hour++) {
	$sparse[] = $row($now - 31 * 86400 + $hour * 3600, 1);
}
$windows = $call('summarizeWindows', [$sparse, $now, 85.0, 95.0]);
$check($near($windows['1m']['cov'], 100 / 60), 'Known sample loss must reduce baseline coverage.');
$check($windows['1m']['days'] === 31, 'Observed days must remain distinct from sample coverage.');
$result = $call('forecastResource', [$spec, $sparse, 'trends', $now, null]);
$check($result['severity'] === 'Unknown', 'Sparse hourly samples must not establish a Healthy baseline.');

// Five minutes in every hour must not be rounded up to an entire observed hour.
$five_minute = array_map(static function (array $sample): array {
	$sample['num'] = 5;
	$sample['bucket_seconds'] = 300;
	$sample['expected_num'] = 5;
	$sample['bucket_coverage_pct'] = 100.0;
	return $sample;
}, $sparse);
$windows = $call('summarizeWindows', [$five_minute, $now, null, null]);
$check($near($windows['1m']['cov'], 100 / 12), 'Five-minute evidence must cover only five minutes.');
$interval = $call('resourceIntervalStats', [$five_minute, $now - 31 * 86400, $now, 31, 'test', null, null]);
$check($near($interval['cov'], 100 / 12), 'Regime windows must use the same time coverage as baselines.');

// Two distant observed days cannot satisfy a 21-day data-duration requirement.
$gapped = [];
foreach ([31, 1] as $day) {
	for ($hour = 0; $hour < 24; $hour++) { $gapped[] = $row($now - $day * 86400 + $hour * 3600); }
}
$windows = $call('summarizeWindows', [$gapped, $now, null, null]);
$check($windows['1m']['days'] === 2, 'Missing intervening days must not count as observed days.');
$interval = $call('resourceIntervalStats', [$gapped, $now - 31 * 86400, $now, 31, 'test', null, null]);
$check($interval['days'] === 2, 'Regime day completeness must count observed dates.');
$coverage = $call('observedWindowCoverage', [[$row($now - 3600), $row($now - 3600)], $now - 3600, $now, 1]);
$check($near($coverage[1], 100), 'Overlapping buckets must not double coverage.');
$windows = $call('summarizeWindows', [[$row($now + 3600)], $now, null, null]);
$check($windows['1m']['n'] === 0, 'Future rows cannot count as historical observations.');

$item = static fn (string $id, string $key, float $value, int $age, ?int $type = null): array => [
	'itemid' => $id, 'key' => $key, 'name' => $key, 'state' => 0, 'item_type' => $type,
	'lastvalue' => $value, 'lastclock' => $now - $age, 'tags' => []
];
$inventory = static function (string $os, array $items) use ($call, $now): array {
	return $call('buildResourceFindings', [
		['h1' => ['name' => 'fixture', 'os' => $os,
			'maintenance' => ['active' => false, 'type' => 'none', 'id' => null, 'since' => null]]],
		['h1' => $items], ['by_entity' => ['h1' => []], 'levels' => ['h1' => []], 'global' => []], $now
	]);
};

$findings = $inventory('Linux', [$item('1', 'vm.memory.size[pused]', 98.0, 5),
	$item('2', 'vm.memory.size[pavailable]', 60.0, 60)]);
$check($findings[1]['itemid'] === '2' && $near($findings[1]['current'], 40.0),
	'Linux pused must not override available-memory pressure even when its clock is newer.');
$findings = $inventory('Linux', [$item('1', 'vm.memory.size[pused]', 98.0, 5)]);
$check($findings[1]['itemid'] === null && $findings[1]['status'] === 'Missing',
	'Linux pused alone must not be presented as RAM pressure.');
$findings = $inventory('Windows', [$item('1', 'vm.memory.size[pused]', 98.0, 5)]);
$check($findings[1]['itemid'] === '1' && $near($findings[1]['current'], 98.0),
	'Windows pused remains a supported used-memory measurement.');

$findings = $inventory('Linux', [$item('1', 'vm.memory.utilization', 40.0, 60),
	$item('2', 'vm.memory.size[pavailable]', 70.0, 5)]);
$check($findings[1]['itemid'] === '2', 'Equivalent fresh memory sources must preserve recency preference.');
$findings = $inventory('Linux', [$item('1', 'vm.memory.utilization', 40.0, 5 * 3600),
	$item('2', 'vm.memory.size[pavailable]', 70.0, 5)]);
$check($findings[1]['itemid'] === '2', 'Fresh valid fallback must still beat stale preferred data.');

foreach ([0, 7] as $native_type) {
	$findings = $inventory('Linux', [$item('1', 'system.cpu.util', 5.0, 5, $native_type),
		$item('2', 'system.cpu.util[,idle]', 10.0, 60, $native_type)]);
	$check($findings[0]['itemid'] === '2' && $near($findings[0]['current'], 90.0),
		'Native Unix user CPU must not be mistaken for total CPU utilization.');
}
$findings = $inventory('Linux', [$item('1', 'system.cpu.util', 92.0, 5, 18),
	$item('2', 'system.cpu.util[,idle]', 10.0, 5, 0)]);
$check($findings[0]['itemid'] === '1' && $near($findings[0]['current'], 92.0),
	'Stock dependent total CPU utilization must remain preferred.');
$findings = $inventory('Linux', [$item('1', 'system.cpu.util', 5.0, 5, 0)]);
$check($findings[0]['itemid'] === null, 'Unix native user-only CPU without a total fallback must remain missing.');
$findings = $inventory('Windows', [$item('1', 'system.cpu.util', 92.0, 60, 0)]);
$check($findings[0]['itemid'] === '1', 'Native Windows CPU semantics must remain supported.');

[$normalized] = $call('ingestBoundedItemRows', [[], [
	['itemid' => '1', 'hostid' => 'h1', 'key_' => 'system.cpu.util', 'type' => '18'],
	['itemid' => '2', 'hostid' => 'h1', 'key_' => 'system.cpu.util', 'type' => '0']
], 10]);
$check($normalized['1']['item_type'] === 18 && $normalized['2']['item_type'] === 0,
	'API item types must survive inventory normalization for CPU semantics checks.');

echo "CapacityPlanningResourceAccuracyTest: {$assertions} assertions passed.\n";
