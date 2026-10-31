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
if (!defined('ITEM_VALUE_TYPE_FLOAT')) { define('ITEM_VALUE_TYPE_FLOAT', 0); }
if (!defined('ITEM_VALUE_TYPE_UINT64')) { define('ITEM_VALUE_TYPE_UINT64', 3); }
require_once dirname(__DIR__).'/actions/CapacityPlanningData.php';

use Modules\CapacityPlanning\Actions\CapacityPlanningData;

final class CapacityPlanningDiskPersistenceTest {
	private const NOW = 2000073600;
	private const GIB = 1073741824.0;
	private CapacityPlanningData $controller;
	private int $assertions = 0;

	public function __construct() {
		$this->controller = (new ReflectionClass(CapacityPlanningData::class))->newInstanceWithoutConstructor();
	}

	private function call(string $name, array $args) {
		$method = new ReflectionMethod(CapacityPlanningData::class, $name);
		if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
		return $method->invokeArgs($this->controller, $args);
	}

	private function check(bool $condition, string $message): void {
		$this->assertions++;
		if (!$condition) { throw new RuntimeException($message); }
	}

	private function rows(string $shape, int $days = 92, float $phase = 0.0): array {
		$rows = [];
		for ($hour = 0; $hour < $days * 24; $hour++) {
			$day = 92 - $days + $hour / 24;
			$used = 500.0 + $day * 0.5;
			if ($shape === 'step' || $shape === 'step_cleanup') {
				$used += $day >= 76 ? 50 : 0;
				if ($shape === 'step_cleanup') { $used -= $day >= 85 ? 20 : 0; }
			}
			if ($shape === 'ramp' || $shape === 'ramp_cleanup') {
				$used += max(0.0, $day - 61) * 2.5;
				if ($shape === 'ramp_cleanup') { $used -= $day >= 92 - $phase ? 25 : 0; }
			}
			if ($shape === 'weekly' || $shape === 'fortnightly') {
				$period = $shape === 'weekly' ? 7 : 14;
				$used += $day >= 61 ? floor(($day - 61 + $phase) / $period) * $period * 18 / 7 : 0;
			}
			$value = $used * self::GIB;
			$rows[] = ['clock' => self::NOW - $days * 86400 + $hour * 3600,
				'num' => 60, 'min' => $value, 'avg' => $value, 'max' => $value,
				'bucket_seconds' => 3600, 'bucket_coverage_pct' => 100.0];
		}
		return $rows;
	}

	private function forecast(array $rows, bool $percentage_only = false, bool $breached = false): array {
		$used = $breached ? 990 * self::GIB : $rows[count($rows) - 1]['avg'];
		$capacity = 1000 * self::GIB;
		$spec = ['id' => 'd0', 'used' => $percentage_only ? null : $used,
			'free' => $percentage_only ? null : $capacity - $used,
			'total' => $percentage_only ? null : $capacity, 'pused' => $used * 100 / $capacity,
			'warn_pct' => 90.0, 'crit_pct' => 95.0, 'warn_free' => 0.0, 'crit_free' => 0.0,
			'os' => 'Windows', 'fs_kind' => 'Local', 'ok' => true,
			'byte_source' => $percentage_only ? 'none' : 'used'];
		$pct_rows = $this->call('transformRows', [$rows, 'scale', 100 / $capacity]);
		return $this->call('forecastDisk', [$spec, $percentage_only ? [] : $rows,
			'trends', self::NOW, null, $pct_rows, 'trends', null]);
	}

	public function run(): void {
		foreach (['step', 'step_cleanup'] as $shape) {
			$result = $this->forecast($this->rows($shape));
			$this->check(!$result['accelerating'], 'An isolated addition or cleanup must not establish recurring acceleration.');
			$this->check($result['acceleration_unconfirmed'], 'Rejected acceleration needs a visible review signal.');
			$this->check($result['sel'] === '3m', 'Keep the qualified longer baseline when acceleration is unconfirmed.');
			$this->check(abs($result['growth_day'] / self::GIB - 0.5) < 0.01, 'Retain baseline growth instead of declaring growth stopped.');
			$this->check($result['confidence'] !== 'High', 'An unstable recent increase must cap baseline confidence.');
			$this->check(strpos($result['recommendation'], 'Verify recent bulk additions') !== false,
				'Baseline health must not hide the recent-growth review requirement.');
		}

		$ramp = $this->forecast($this->rows('ramp'));
		$this->check($ramp['accelerating'] && abs($ramp['growth_day'] / self::GIB - 3) < 0.01,
			'A sustained higher rate must still activate the monthly override.');
		$this->check($ramp['acceleration_evidence']['confirmed'], 'Confirmed acceleration must expose corroborating evidence.');

		foreach (range(0, 6) as $phase) {
			$weekly = $this->forecast($this->rows('weekly', 92, (float) $phase));
			$this->check($weekly['accelerating'], 'Recurring weekly bursts must remain detectable at phase '.$phase.'.');
			$this->check($weekly['growth_day'] / self::GIB > 2.5, 'Recurring bursts must not be reduced to quiet-day growth.');
		}
		$fortnightly = $this->forecast($this->rows('fortnightly', 92, 5.0));
		$this->check($fortnightly['accelerating'], 'A quiet latest week must not hide corroborated fortnightly bursts.');
		$this->check($fortnightly['acceleration_evidence']['confirmation_window'] === '2w',
			'Fortnightly bursts must be confirmed using net growth and fit from the same fortnight.');

		$after_cleanup = $this->forecast($this->rows('ramp_cleanup', 92, 7.1));
		$this->check($after_cleanup['accelerating'], 'A clean recent week can confirm growth despite an earlier cleanup.');
		$this->check($after_cleanup['acceleration_evidence']['confirmation_window'] === '1w',
			'Use the corroborated post-cleanup week when the fortnight is distorted by cleanup.');
		$this->check(abs(($after_cleanup['eta']['full_days'] - $ramp['eta']['full_days']) - 25 / 3) < 0.02,
			'Cleanup must extend runway from the current free-space anchor while confirmed growth stays unchanged.');
		$during_cleanup = $this->forecast($this->rows('ramp_cleanup', 92, 6.9));
		$this->check($during_cleanup['acceleration_unconfirmed'] && !$during_cleanup['accelerating'],
			'Cleanup inside both candidate intervals prevents claiming a confirmed recent net rate.');
		$this->check($during_cleanup['growth_day'] > 0, 'A recent cleanup must not be described as stopped growth.');

		$short_step = $this->forecast($this->rows('step', 31));
		$this->check($short_step['sel'] === '1m' && $short_step['acceleration_unconfirmed'],
			'Guard a monthly-only step model even when there is no longer baseline to override.');
		$this->check(!$short_step['forecast_reliable'] && $short_step['eta']['full_days'] === null,
			'An unconfirmed monthly-only rate must not publish a precise Full date.');
		$this->check($short_step['severity'] === 'Unknown', 'Unconfirmed monthly-only evidence must not imply health.');
		$this->check($this->forecast($this->rows('step', 31), false, true)['severity'] === 'Critical',
			'Unconfirmed growth must preserve a current critical breach.');
		$short_ramp = $this->forecast($this->rows('ramp', 31));
		$this->check($short_ramp['forecast_reliable'] && $short_ramp['eta']['full_days'] !== null,
			'A smooth monthly-only ramp with recent corroboration remains forecastable.');

		$pct_step = $this->forecast($this->rows('step'), true);
		$this->check($pct_step['acceleration_unconfirmed'] && !$pct_step['pct_accelerating'],
			'Percentage-only models require the same step-change protection.');
		$this->check(abs($pct_step['growth_pct_day'] - 0.05) < 0.001,
			'Percentage-only models retain their qualified baseline.');
		$pct_ramp = $this->forecast($this->rows('ramp'), true);
		$this->check($pct_ramp['pct_accelerating'] && abs($pct_ramp['growth_pct_day'] - 0.3) < 0.001,
			'Confirmed percentage-only acceleration remains available.');
		$pct_short_step = $this->forecast($this->rows('step', 31), true);
		$this->check(!$pct_short_step['forecast_reliable'] && $pct_short_step['eta']['crit_days'] === null,
			'Unconfirmed monthly-only percentages must not publish threshold dates.');

		$sparse_windows = ['1w' => ['days' => 7, 'cov' => 10.0, 'slope' => 3.0, 'r2' => 1.0],
			'2w' => ['days' => 14, 'cov' => 10.0, 'slope' => 3.0, 'r2' => 1.0]];
		$sparse = $this->call('confirmDiskGrowthPersistence', [$sparse_windows, $this->rows('ramp'), self::NOW,
			3.0, 0.5, 0.01]);
		$this->check(!$sparse['confirmed'], 'A plausible endpoint delta cannot replace represented coverage.');
		echo "CapacityPlanningDiskPersistenceTest: {$this->assertions} assertions passed.\n";
	}
}

(new CapacityPlanningDiskPersistenceTest())->run();
