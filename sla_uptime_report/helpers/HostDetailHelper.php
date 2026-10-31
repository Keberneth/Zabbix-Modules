<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Helpers;

use API;

/** On-demand evidence for one permission-scoped host; all intervals are [start, end). */
final class HostDetailHelper {
	public const MAX_INTERVALS = 5000;

	public static function validRange(int $from, int $to): bool {
		return $from > 0 && $to >= $from && $to <= time()
			&& $to - $from <= ReportDataHelper::MAX_RANGE_DAYS * 86400;
	}

	/** Only known report fields become a return link; never accept a redirect URL. */
	public static function returnFilter(string $raw, int $from, int $to): array {
		$input = strlen($raw) <= 16000 ? json_decode($raw, true) : null;
		if (!is_array($input)) {
			$input = ['mode' => 'custom_range', 'date_from' => gmdate('Y-m-d', $from),
				'date_to' => gmdate('Y-m-d', $to)];
		}
		foreach ($input as $key => $value) {
			if (in_array($key, ['hostgroupids', 'slaids'], true)) {
				if (!is_array($value)) { unset($input[$key]); }
			}
			elseif (!is_scalar($value)) { unset($input[$key]); }
		}
		$filter = ReportDataHelper::normalizeFilter($input);
		$filter['tab'] = 'availability';
		return $filter;
	}

	public static function validMeasurementId(string $measurementid): bool {
		return $measurementid === '' || preg_match('/^[a-f0-9]{32}$/D', $measurementid) === 1;
	}

	public function build(string $hostid, int $from, int $to, string $measurementid = ''): array {
		if (!self::validRange($from, $to)) {
			throw new \InvalidArgumentException(_('Invalid host detail period.'));
		}
		if (!self::validMeasurementId($measurementid)) {
			throw new \InvalidArgumentException(_('Invalid measurement selection.'));
		}
		$hosts = API::Host()->get([
			'output' => ['hostid', 'host', 'name', 'status'], 'hostids' => [$hostid],
			'selectHostGroups' => ['groupid', 'name']
		]);
		if (!is_array($hosts) || count($hosts) !== 1) {
			throw new \InvalidArgumentException(_('Host is unavailable or you do not have permission to read it.'));
		}
		$host = reset($hosts);
		$settings = ReportSettings::get();
		$excluded = ReportSettings::isExcluded($settings, $hostid, array_column($host['hostgroups'], 'groupid'));
		$measurements = ReportSettings::selectMeasurements([$host], $settings);
		// Never accept another host's selection, even if an unexpected API response includes it.
		$measurements = array_filter($measurements,
			static fn(array $measurement): bool => (string) $measurement['hostid'] === $hostid);
		if ($measurementid !== '' && !isset($measurements[$measurementid])) {
			throw new \InvalidArgumentException(_('This measurement is no longer configured for this host. Return to the report and choose a current measurement.'));
		}
		$selection_required = $measurementid === '' && count($measurements) > 1;
		$measurement = $measurementid !== '' ? $measurements[$measurementid]
			: (count($measurements) === 1 ? reset($measurements) : null);
		$item = $measurement['item'] ?? null;
		$report = new ReportDataHelper();
		$maintenance = $report->getMaintenanceWindows([$hostid => array_column($host['hostgroups'], 'name')], $from, $to, true);
		$windows = $maintenance['hosts'][$hostid] ?? [];
		$warnings = $report->getNotes();
		$result = [
			'host' => $host, 'item' => $item, 'excluded' => $excluded, 'from' => $from, 'to' => $to,
			'measurement' => $measurement, 'measurements' => array_values($measurements),
			'selection_required' => $selection_required,
			'source' => 'history', 'interval' => null, 'fixed_cadence' => false,
			'maintenance' => $maintenance['details'][$hostid] ?? [],
			'maintenance_seconds' => array_sum(array_map(static fn(array $w): int => $w[1] - $w[0], $windows)),
			'timezone' => $maintenance['timezone'], 'intervals' => [], 'warnings' => $warnings,
			'complete' => true, 'samples' => 0
		];
		if ($selection_required) {
			$result['warnings'][] = _('This host has several measurements. Select the website or scenario to inspect its own readings and gaps.');
			return $result;
		}
		if ($item === null) {
			$result['warnings'][] = _('No enabled numeric item matches this measurement\'s rule.');
			return $result;
		}
		$use_trends = !ReportDataHelper::usesHistory($item, $from, $to);
		if ($use_trends) {
			$result['source'] = 'trends';
			$this->readTrends($result, $item, $windows);
		}
		else {
			$this->readHistory($result, $item, $windows);
		}
		// Incomplete maintenance or history reads must never authorize an exclusion.
		if (!$result['complete'] || $warnings !== [] || $excluded) {
			foreach ($result['intervals'] as &$interval) {
				$interval['creatable'] = false;
			}
			unset($interval);
		}
		return $result;
	}

	private function readHistory(array &$result, array $item, array $windows): void {
		$cursor = $result['from'];
		$to = $result['to'];
		$previous = null;
		$interval = self::fixedInterval($item['delay'] ?? null);
		$result['fixed_cadence'] = $interval !== null;
		$processed = 0;
		do {
			$batch = API::History()->get([
				'output' => ['clock', 'value'], 'itemids' => [$item['itemid']],
				'history' => (int) $item['value_type'], 'time_from' => $cursor, 'time_till' => $to,
				'sortfield' => 'clock', 'sortorder' => 'ASC', 'limit' => ReportDataHelper::FETCH_BATCH
			]);
			if (!is_array($batch)) {
				$result['complete'] = false;
				$result['warnings'][] = _('History could not be read completely. No maintenance can be created from this result.');
				break;
			}
			if ($batch === []) {
				break;
			}
			if ($interval === null) {
				$interval = self::estimateInterval($batch);
			}
			$result['interval'] = $interval;
			$full = count($batch) >= ReportDataHelper::FETCH_BATCH;
			$last_clock = (int) $batch[count($batch) - 1]['clock'];
			if ($full && $last_clock <= $cursor) {
				$result['complete'] = false;
				$result['warnings'][] = _('Too many readings share a timestamp to scan safely. Narrow the period.');
				break;
			}
			foreach ($batch as $row) {
				$clock = (int) $row['clock'];
				if ($full && $clock >= $last_clock) {
					continue;
				}
				$result['samples']++;
				$processed++;
				if ($previous === null) {
					if ($clock - $result['from'] >= $interval) {
						$this->append($result, $result['from'], $clock, 'missing', false, $windows);
					}
				}
				else {
					$this->consumePair($result, $previous, $clock, $interval, $item['rule'], $windows);
				}
				$previous = $row;
			}
			if ($processed >= ReportDataHelper::MAX_HISTORY_ROWS) {
				$result['complete'] = false;
				$result['warnings'][] = _('The history scan limit was reached. Narrow the period; maintenance creation is disabled.');
				break;
			}
			if (!$full) {
				break;
			}
			$cursor = $last_clock;
		} while (true);

		if ($result['complete']) {
			if ($previous !== null) {
				$this->consumePair($result, $previous, $to + 1, $interval, $item['rule'], $windows, false);
			}
			else {
				$this->append($result, $result['from'], $to + 1, 'missing', false, $windows);
				$result['warnings'][] = _('No history samples exist in this period. Retention, disabled collection and an outage cannot be distinguished.');
			}
		}
		if (!$result['fixed_cadence']) {
			$result['warnings'][] = _('The polling interval is estimated from available readings. Flexible schedules, dependent items and retention can create apparent gaps; automatic maintenance creation requires a fixed configured interval.');
		}
	}

	private function consumePair(array &$result, array $row, int $next, int $interval, array $rule,
			array $windows, bool $bracketed = true): void {
		$clock = (int) $row['clock'];
		$until = min($next, $clock + $interval);
		if (!ReportSettings::sampleIsUp((float) $row['value'], $rule)) {
			$this->append($result, $clock, $until, 'failed', false, $windows);
		}
		// Tolerate small polling jitter, but retain a missed poll whose recovery arrives a second early.
		$jitter = max(1, min(5, (int) floor($interval / 10)));
		if ($next - ($clock + $interval) > $jitter) {
			$this->append($result, $clock + $interval, $next, 'missing',
				$bracketed && $result['fixed_cadence'], $windows);
		}
	}

	private function readTrends(array &$result, array $item, array $windows): void {
		$from = $result['from'];
		$to = $result['to'];
		$rows = API::Trend()->get([
			'output' => ['clock', 'num', 'value_avg', 'value_min', 'value_max'], 'itemids' => [$item['itemid']],
			'time_from' => intdiv($from, 3600) * 3600, 'time_till' => $to
		]);
		if (!is_array($rows)) {
			$result['complete'] = false;
			$result['warnings'][] = _('Hourly trends could not be read.');
			return;
		}
		$by_clock = [];
		foreach ($rows as $row) {
			if ((int) $row['num'] > 0) {
				$by_clock[(int) $row['clock']] = $row;
				$result['samples'] += (int) $row['num'];
			}
		}
		for ($hour = intdiv($from, 3600) * 3600; $hour <= $to; $hour += 3600) {
			$start = max($hour, $from);
			$end = min($hour + 3600, $to + 1);
			$covered = false;
			foreach ($windows as $window) {
				if ($window[0] < $hour + 3600 && $window[1] > $hour) {
					$covered = true;
					break;
				}
			}
			if ($covered) {
				continue;
			}
			$row = $by_clock[$hour] ?? null;
			if ($row === null) {
				$this->append($result, $start, $end, 'uncovered', false, []);
			}
			elseif ((float) $row['value_min'] !== (float) $row['value_max']) {
				$this->append($result, $start, $end, 'trend_mixed', false, []);
			}
			elseif ((float) $row['value_min'] !== 1.0 || (float) $row['value_max'] !== 1.0) {
				$this->append($result, $start, $end, 'trend_failed', false, []);
			}
		}
		$result['warnings'][] = _('Hourly buckets show approximate evidence only. Uncovered hours are excluded from the trend percentage, and mixed readings cannot locate exact outages. Use a period of seven days or less for retained raw history and per-gap maintenance creation.');
	}

	/** Append clipped, maintenance-subtracted and day-split evidence with bounded memory. */
	private function append(array &$result, int $start, int $end, string $kind, bool $creatable, array $windows): void {
		$start = max($start, $result['from']);
		$end = min($end, $result['to'] + 1);
		foreach (self::subtractWindows($start, $end, $windows) as [$part_start, $part_end]) {
			while ($part_start < $part_end) {
				$until = min($part_end, (intdiv($part_start, 86400) + 1) * 86400);
				$count = count($result['intervals']);
				$last = $count > 0 ? $result['intervals'][$count - 1] : null;
				if ($last !== null && $last['end'] === $part_start && $last['kind'] === $kind
						&& $last['bracketed'] === $creatable && intdiv($last['start'], 86400) === intdiv($part_start, 86400)) {
					$result['intervals'][$count - 1]['end'] = $until;
					$result['intervals'][$count - 1]['seconds'] = $until - $last['start'];
					$result['intervals'][$count - 1]['proposal'] = self::maintenanceProposal($last['start'], $until);
					$result['intervals'][$count - 1]['creatable'] = $creatable && self::maintenanceProposal($last['start'], $until) !== null;
				}
				elseif ($count >= self::MAX_INTERVALS) {
					$result['complete'] = false;
					$warning = _('Only the first 5000 evidence intervals are shown. Narrow the period; maintenance creation is disabled.');
					if (!in_array($warning, $result['warnings'], true)) {
						$result['warnings'][] = $warning;
					}
					return;
				}
				else {
					$proposal = self::maintenanceProposal($part_start, $until);
					$result['intervals'][] = ['start' => $part_start, 'end' => $until, 'seconds' => $until - $part_start,
						'kind' => $kind, 'bracketed' => $creatable, 'creatable' => $creatable && $proposal !== null,
						'proposal' => $proposal];
				}
				$part_start = $until;
			}
		}
	}

	public static function subtractWindows(int $start, int $end, array $windows): array {
		$parts = [];
		foreach ($windows as [$ws, $we]) {
			if ($we <= $start) { continue; }
			if ($ws >= $end) { break; }
			if ($ws > $start) { $parts[] = [$start, min($ws, $end)]; }
			$start = max($start, $we);
			if ($start >= $end) { break; }
		}
		if ($start < $end) { $parts[] = [$start, $end]; }
		return $parts;
	}

	/** Zabbix only stores whole minutes and requires at least five minutes. Never expand a gap. */
	public static function maintenanceProposal(int $start, int $end): ?array {
		$start = (int) (ceil($start / 60) * 60);
		$end = intdiv($end, 60) * 60;
		return $end - $start >= 300 ? ['start' => $start, 'end' => $end] : null;
	}

	public static function fixedInterval(?string $delay): ?int {
		if ($delay === null || preg_match('/^(\d+)([smhdw]?)$/i', trim($delay), $m) !== 1) {
			return null;
		}
		$units = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];
		$value = (int) $m[1] * $units[strtolower($m[2])];
		return $value > 0 ? $value : null;
	}

	private static function estimateInterval(array $rows): int {
		$deltas = [];
		$previous = null;
		foreach (array_slice($rows, 0, 513) as $row) {
			$clock = (int) $row['clock'];
			if ($previous !== null && $clock > $previous) { $deltas[] = $clock - $previous; }
			$previous = $clock;
		}
		if ($deltas === []) { return 60; }
		sort($deltas);
		$middle = intdiv(count($deltas), 2);
		return count($deltas) % 2 ? $deltas[$middle] : (int) round(($deltas[$middle - 1] + $deltas[$middle]) / 2);
	}
}
