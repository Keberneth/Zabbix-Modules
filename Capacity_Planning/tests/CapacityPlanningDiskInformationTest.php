<?php

declare(strict_types=1);

if (!in_array(PHP_SAPI, ['cli', 'phpdbg', 'wasm'], true)) { http_response_code(404); exit; }
if (!class_exists('CController')) { class CController {} }
if (!class_exists('CControllerResponseData')) { class CControllerResponseData {} }
if (!class_exists('API')) { class API {} }
if (!function_exists('_')) { function _(string $value): string { return $value; } }
if (!defined('ITEM_VALUE_TYPE_FLOAT')) { define('ITEM_VALUE_TYPE_FLOAT', 0); }
if (!defined('ITEM_VALUE_TYPE_UINT64')) { define('ITEM_VALUE_TYPE_UINT64', 3); }
require_once dirname(__DIR__).'/actions/CapacityPlanningData.php';

use Modules\CapacityPlanning\Actions\CapacityPlanningData;

final class CapacityPlanningDiskInformationTest {
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
	private function rows(callable $value, int $days = 92, int $age = 0): array {
		$rows = [];
		for ($hour = 0; $hour < $days * 24; $hour++) {
			$used = $value($hour / 24);
			if ($used === null) { continue; }
			$used *= self::GIB;
			$rows[] = ['clock' => self::NOW - ($days + $age) * 86400 + $hour * 3600,
				'num' => 60, 'min' => $used, 'avg' => $used, 'max' => $used,
				'bucket_seconds' => 3600, 'bucket_coverage_pct' => 100.0];
		}
		return $rows;
	}
	private function forecast(array $rows, bool $percentage_only = false, ?float $current_pct = null): array {
		$capacity = 1000 * self::GIB;
		$used = $current_pct !== null ? $capacity * $current_pct / 100 : $rows[count($rows) - 1]['avg'];
		$spec = ['id' => 'd0', 'used' => $percentage_only ? null : $used,
			'free' => $percentage_only ? null : $capacity - $used,
			'total' => $percentage_only ? null : $capacity, 'pused' => $used * 100 / $capacity,
			'warn_pct' => 90.0, 'crit_pct' => 95.0, 'warn_free' => 0.0, 'crit_free' => 0.0,
			'os' => 'Windows', 'fs_kind' => 'Local', 'ok' => true,
			'byte_source' => $percentage_only ? 'none' : 'used'];
		$pct = $this->call('transformRows', [$rows, 'scale', 100 / $capacity]);
		return $this->call('forecastDisk', [$spec, $percentage_only ? [] : $rows, 'trends', self::NOW, null, $pct, 'trends', null]);
	}
	public function run(): void {
		$this->check($this->call('formatDiskObservation', [0.000000001, false, true]) !== '+0 pp',
			'A nonzero observed percentage rate must not be formatted as zero.');
		$low_fit_long = $this->rows(static fn (float $day): float => $day < 61
			? 300 + $day * 3 + 300 * sin($day * M_PI / 2) : 483 + ($day - 61) * 0.25);
		$fallback = $this->forecast($low_fit_long);
		$this->check($fallback['windows']['3m']['r2'] < 0.35 && $fallback['windows']['1m']['r2'] >= 0.35,
			'The fixture must contain a weak longer fit and a qualified slower recent fit.');
		$this->check($fallback['sel'] === '1m' && $fallback['forecast_status'] === 'projected',
			'A fit-qualified monthly fallback must be tried when preferred longer windows have weak fits.');
		$this->check($fallback['acceleration_evidence']['confirmed'], 'A monthly fallback must still pass recent persistence checks.');
		$this->check(in_array('fit_window_fallback', $fallback['forecast_reason_codes'], true), 'Disclose the window fallback.');
		$fallback_step = $this->forecast($this->rows(static fn (float $day): float => $day < 61
			? 300 + $day * 3 + 300 * sin($day * M_PI / 2) : 483 + ($day - 61) * 0.25 + ($day >= 76 ? 50 : 0)));
		$this->check($fallback_step['sel'] === '1m' && !$fallback_step['forecast_reliable'],
			'Fit-aware fallback must not bypass the monthly step-change guard.');
		$this->check(in_array('unconfirmed_monthly_growth', $fallback_step['forecast_reason_codes'], true),
			'An unconfirmed monthly fallback needs a specific reason.');
		$this->check(strpos($fallback_step['information']['summary'], 'not yet corroborated') !== false,
			'The table summary must explain unconfirmed monthly growth directly.');

		$stats = static fn (int $days, float $slope, float $r2): array => ['days' => $days, 'cov' => 95.0,
			'slope' => $slope, 'r2' => $r2];
		$preferred = ['3m' => $stats(92, 2.0, 0.1), '6m' => $stats(183, 1.0, 0.6), '12m' => $stats(365, 1.0, 0.9),
			'1m' => $stats(31, 0.25, 1.0), '1w' => $stats(7, 0.25, 1.0)];
		$this->check($this->call('selectModelWindow', [$preferred]) === '6m',
			'Use the first qualified fit in the existing preference order, not the highest retrospective R².');
		$selected = $stats(92, 4 * 1048576.0, 0.9);
		$this->check($this->call('forecastConfidence', [$selected, ['1m' => $stats(31, -100.0, 0.9)], 1048576.0]) === 'High',
			'A near-zero opposite slope must not veto a meaningful qualified trend.');
		$this->check($this->call('forecastConfidence', [$selected, ['1m' => $stats(31, -4 * 1048576.0, 0.1)], 1048576.0]) === 'High',
			'An opposite but weak recent fit must not veto a qualified trend.');
		$this->check($this->call('forecastConfidence', [$selected, ['1m' => $stats(31, -4 * 1048576.0, 0.9)], 1048576.0]) === 'Low',
			'A qualified opposing rate above the noise floor must still veto the forecast.');

		$stable = $this->forecast($this->rows(static fn (float $day): float => 500.0));
		$this->check($stable['forecast_status'] === 'stable' && $stable['eta']['full_days'] === null,
			'A qualified constant history is stable, not an unknown or finite fill date.');
		$pct_stable = $this->forecast($this->rows(static fn (float $day): float => 500.0), true);
		$this->check(strpos($pct_stable['information']['forecast'], 'byte-based Full date cannot be assessed') !== false,
			'A stable percentage must not imply a known byte-based fill rate.');
		$shrinking = $this->forecast($this->rows(static fn (float $day): float => 650 - $day));
		$this->check($shrinking['forecast_status'] === 'shrinking' && $shrinking['eta']['full_days'] === null,
			'A qualified negative trend must be described as shrinking.');
		$shrinking_critical = $this->forecast($this->rows(static fn (float $day): float => 650 - $day), false, 99.0);
		$this->check($shrinking_critical['severity'] === 'Critical' && $shrinking_critical['forecast_status'] === 'shrinking',
			'A decreasing historical trend must not erase a current critical breach.');
		$monthly_cleanup_rows = $this->rows(static fn (float $day): float => 500 + $day - ($day >= 16 ? 100 : 0), 31);
		$monthly_cleanup = $this->forecast($monthly_cleanup_rows);
		$this->check($monthly_cleanup['sel'] === '1m' && $monthly_cleanup['windows']['1m']['slope'] < 0
			&& $monthly_cleanup['windows']['1w']['slope'] > 0 && $monthly_cleanup['windows']['2w']['slope'] > 0,
			'The cleanup fixture must have a negative monthly line but qualified ongoing recent growth.');
		$this->check($monthly_cleanup['forecast_status'] === 'unavailable' && $monthly_cleanup['severity'] === 'Unknown'
			&& in_array('recent_pattern_change', $monthly_cleanup['forecast_reason_codes'], true),
			'A cleanup must not make ongoing recent growth look safely shrinking.');
		$this->check($this->forecast($monthly_cleanup_rows, false, 99.0)['severity'] === 'Critical',
			'The post-cleanup pattern guard must preserve current critical evidence.');
		$pct_cleanup = $this->forecast($monthly_cleanup_rows, true);
		$this->check(strpos($pct_cleanup['information']['forecast'], 'percentage-threshold date') !== false,
			'A percentage-only pattern change must not be described as a known byte fill behavior.');
		$flat_noise = $this->forecast($this->rows(static fn (float $day): float => 500 + ((int) $day % 2 === 0 ? 0.1 : -0.1)));
		$this->check($flat_noise['forecast_status'] === 'unavailable' && $flat_noise['severity'] === 'Unknown',
			'Noisy flat history must not silently bypass the fit guard or imply Healthy.');
		$this->check(strpos($flat_noise['information']['recent'], 'fluctuate') !== false,
			'Noisy flat history should still explain the observed fluctuations and little net change.');
		$this->check(in_array('weak_fit', $flat_noise['forecast_reason_codes'], true), 'Explain weak fit explicitly.');
		$this->check(strpos($flat_noise['information']['summary'], 'uneven usage prevents a reliable date') !== false,
			'The table summary must explain weak fit without requiring a detail dialog.');
		$short = $this->forecast($this->rows(static fn (float $day): float => 500 + $day, 5));
		$this->check(stripos($short['information']['summary'], 'too little qualified history') !== false,
			'The table summary must distinguish short history from uneven usage.');
		$stale = $this->forecast($this->rows(static fn (float $day): float => 500 + $day, 92, 10));
		$this->check(stripos($stale['information']['summary'], 'stale history') !== false,
			'The table summary must identify stale historical evidence.');

		$spike = $this->forecast($this->rows(static fn (float $day): float => 500 + $day * 0.1 + ($day >= 86 ? 450 : 0)));
		$this->check($spike['severity'] === 'Critical', 'A recent spike with a current breach must stay Critical.');
		$this->check(in_array('observed_abrupt_increase', $spike['forecast_reason_codes'], true), 'Identify actual material daily-average increases.');
		$this->check(strpos($spike['information']['summary'], 'Critical now') !== false,
			'Information must lead with the current breach when present.');
		$this->check(strpos(implode(' ', $spike['information']['signals']), 'not a repeating future') !== false,
			'An observed spike must not be asserted to repeat indefinitely.');
		$gap = $this->forecast($this->rows(static fn (float $day): ?float => $day >= 61 && $day < 66 ? null : ($day < 61 ? 500.0 : 600.0)));
		$this->check(!in_array('observed_abrupt_increase', $gap['forecast_reason_codes'], true),
			'A missing multi-day interval must not be described as a measured abrupt daily jump.');

		$recent_shift = $this->forecast($this->rows(static fn (float $day): float => 500 + $day * 0.15 + max(0, $day - 358) * 14, 365), false, 99.0);
		$this->check($recent_shift['severity'] === 'Critical' && $recent_shift['forecast_status'] === 'unavailable',
			'A recent faster accumulation pattern must invalidate a slower long-baseline date without changing current Critical.');
		$this->check(in_array('recent_pattern_change', $recent_shift['forecast_reason_codes'], true)
			&& $recent_shift['eta']['full_days'] === null && $recent_shift['confidence'] === 'Low',
			'Recent pattern changes must withhold the false long-run Full date and explain why.');
		$settled = $this->forecast($this->rows(static fn (float $day): float => 500 + $day * 0.5 + ($day >= 76 ? 50 : 0)));
		$this->check(!in_array('recent_pattern_change', $settled['forecast_reason_codes'], true) && $settled['growth_day'] > 0,
			'A settled one-off addition must not be mistaken for a continuing rate change.');
		foreach (range(0, 13) as $phase) {
			$bursts = $this->forecast($this->rows(static fn (float $day): float => 200 + $day * 0.5 + floor(($day + $phase) / 14) * 36));
			$this->check(!in_array('recent_pattern_change', $bursts['forecast_reason_codes'], true),
				'An established fortnightly burst cycle must not be mistaken for a new regime at phase '.$phase.'.');
		}

		$pct = $this->forecast($this->rows(static fn (float $day): float => 500 + $day * 0.1 + ($day >= 86 ? 100 : 0)), true);
		$this->check(in_array('percentage_basis', $pct['forecast_reason_codes'], true), 'Percentage-only observations must disclose capacity-change ambiguity.');
		$this->check(strpos(implode(' ', $pct['information']['signals']), 'percentage headroom') !== false,
			'A percentage rise must not be presented as measured consumed-byte growth.');
		foreach (['summary', 'current', 'recent', 'long_term', 'forecast'] as $field) {
			$this->check(is_string($pct['information'][$field]) && $pct['information'][$field] !== '', 'Information '.$field.' must be a readable string.');
		}
		$this->check(count(array_filter($pct['information']['signals'], 'is_string')) === count($pct['information']['signals']),
			'Information signals are human-readable strings, not machine codes.');
		echo "CapacityPlanningDiskInformationTest: {$this->assertions} assertions passed.\n";
	}
}

(new CapacityPlanningDiskInformationTest())->run();
