<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Helpers;

use API;

class ReportDataHelper {

	public const SLA_MONTHS = 12;

	public static function getDefaultFilter(): array {
		$prev_month = gmdate('Y-m', gmmktime(0, 0, 0, (int) gmdate('n') - 1, 1, (int) gmdate('Y')));

		return [
			'mode' => 'prev_month',
			'month' => $prev_month,
			'date_from' => '',
			'date_to' => '',
			'days_back' => 30,
			'hostgroupids' => [],
			'slaids' => [],
			'exclude_disabled' => 1
		];
	}

	public static function normalizeFilter(array $input): array {
		$defaults = self::getDefaultFilter();

		$filter = $defaults;
		$filter['mode'] = in_array($input['mode'] ?? $defaults['mode'], ['prev_month', 'specific_month', 'custom_range', 'days_back'], true)
			? (string) $input['mode']
			: $defaults['mode'];
		$filter['month'] = trim((string) ($input['month'] ?? $defaults['month']));
		$filter['date_from'] = trim((string) ($input['date_from'] ?? ''));
		$filter['date_to'] = trim((string) ($input['date_to'] ?? ''));
		$filter['days_back'] = max(1, min(366, (int) ($input['days_back'] ?? $defaults['days_back'])));
		$filter['hostgroupids'] = self::normalizeIdArray($input['hostgroupids'] ?? []);
		$filter['slaids'] = self::normalizeIdArray($input['slaids'] ?? []);
		$filter['exclude_disabled'] = !empty($input['exclude_disabled']) ? 1 : 0;

		return $filter;
	}

	public static function resolveDateRange(array $filter): array {
		switch ($filter['mode']) {
			case 'specific_month':
				if (preg_match('/^(\d{4})-(\d{2})$/', $filter['month'], $matches) === 1) {
					$year = (int) $matches[1];
					$month = (int) $matches[2];

					if ($month >= 1 && $month <= 12) {
						$from = gmmktime(0, 0, 0, $month, 1, $year);
						$to = gmmktime(0, 0, 0, $month + 1, 1, $year) - 1;

						return [$from, $to];
					}
				}
				break;

			case 'custom_range':
				$from = self::parseDateBoundary($filter['date_from'], 'start');
				$to = self::parseDateBoundary($filter['date_to'], 'end');

				if ($from !== null && $to !== null && $to >= $from) {
					return [$from, $to];
				}
				break;

			case 'days_back':
				$days_back = max(1, min(366, (int) $filter['days_back']));
				$now = time();

				return [$now - ($days_back * 86400), $now];
		}

		$first_this_month = gmmktime(0, 0, 0, (int) gmdate('n'), 1, (int) gmdate('Y'));
		$first_prev_month = gmmktime(0, 0, 0, (int) gmdate('n') - 1, 1, (int) gmdate('Y'));

		return [$first_prev_month, $first_this_month - 1];
	}

	public function getHostGroupOptions(): array {
		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'sortfield' => 'name'
		]);

		if (!is_array($groups)) {
			return [];
		}

		usort($groups, static function (array $left, array $right): int {
			return strcmp((string) $left['name'], (string) $right['name']);
		});

		return $groups;
	}

	public function getSlaOptions(): array {
		$slas = API::Sla()->get([
			'output' => ['slaid', 'name', 'status', 'slo'],
			'filter' => ['status' => ZBX_SLA_STATUS_ENABLED],
			'sortfield' => 'name'
		]);

		if (!is_array($slas)) {
			return [];
		}

		usort($slas, static function (array $left, array $right): int {
			return strcmp((string) $left['name'], (string) $right['name']);
		});

		return $slas;
	}

	public function getSelectedHostGroupOptions(array $groupids): array {
		if ($groupids === []) {
			return [];
		}

		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'groupids' => $groupids,
			'sortfield' => 'name'
		]);

		return is_array($groups) ? $groups : [];
	}

	public function getSelectedSlaOptions(array $slaids): array {
		if ($slaids === []) {
			return [];
		}

		$slas = API::Sla()->get([
			'output' => ['slaid', 'name', 'status', 'slo'],
			'slaids' => $slaids,
			'sortfield' => 'name'
		]);

		return is_array($slas) ? $slas : [];
	}

	public function fetchSlaHeatmap(array $slaids, int $time_to, int $periods = self::SLA_MONTHS): array {
		$params = [
			'output' => ['slaid', 'name', 'slo', 'status'],
			'filter' => ['status' => ZBX_SLA_STATUS_ENABLED],
			'sortfield' => 'name'
		];

		if ($slaids !== []) {
			$params['slaids'] = $slaids;
		}

		$slas = API::Sla()->get($params);

		if (!is_array($slas) || $slas === []) {
			return [];
		}

		$end_month = (int) gmdate('n', $time_to);
		$end_year = (int) gmdate('Y', $time_to);
		$end_index = ($end_year * 12) + ($end_month - 1);
		$start_index = $end_index - ($periods - 1);
		$start_year = intdiv($start_index, 12);
		$start_month = ($start_index % 12) + 1;
		$period_from = gmmktime(0, 0, 0, $start_month, 1, $start_year);

		$result = [];

		foreach ($slas as $sla) {
			$slaid = (string) $sla['slaid'];
			$slo_value = isset($sla['slo']) ? (float) $sla['slo'] : null;

			$services = API::Service()->get([
				'output' => ['serviceid', 'name'],
				'slaids' => [$slaid],
				'sortfield' => 'name'
			]);

			if (!is_array($services) || $services === []) {
				continue;
			}

			$service_ids = [];
			$service_names = [];

			foreach ($services as $service) {
				$serviceid = (string) $service['serviceid'];
				$service_ids[] = $serviceid;
				$service_names[$serviceid] = (string) $service['name'];
			}

			$sli_result = API::Sla()->getSli([
				'slaid' => $slaid,
				'serviceids' => $service_ids,
				'periods' => $periods,
				'period_from' => $period_from
			]);

			if (!is_array($sli_result) || empty($sli_result['periods'])) {
				continue;
			}

			$month_labels = [];
			foreach ($sli_result['periods'] as $period) {
				$month_labels[] = gmdate('Y-m', (int) $period['period_from']);
			}

			$sli_matrix = isset($sli_result['sli']) && is_array($sli_result['sli']) ? $sli_result['sli'] : [];
			$service_data = [];

			foreach ($service_ids as $service_index => $serviceid) {
				$monthly_sli = [];

				for ($period_index = 0; $period_index < count($month_labels); $period_index++) {
					$value = null;

					if (isset($sli_matrix[$period_index][$service_index]['sli'])) {
						$value = (float) $sli_matrix[$period_index][$service_index]['sli'];
					}

					$monthly_sli[] = ($value === null || $value < 0)
						? 'N/A'
						: $this->formatPct($value, 1);
				}

				$service_data[$serviceid] = [
					'serviceid' => $serviceid,
					'name' => $service_names[$serviceid] ?? ('Service '.$serviceid),
					'slo' => $slo_value !== null ? $this->formatPct($slo_value, 1) : 'N/A',
					'slo_value' => $slo_value,
					'month_labels' => $month_labels,
					'monthly_sli' => $monthly_sli
				];
			}

			$result[$slaid] = [
				'slaid' => $slaid,
				'sla_name' => (string) $sla['name'],
				'slo' => $slo_value,
				'service_data' => $service_data
			];
		}

		uasort($result, static function (array $left, array $right): int {
			return strcmp((string) $left['sla_name'], (string) $right['sla_name']);
		});

		return $result;
	}

	public function fetchAvailability(array $hostgroupids, int $time_from, int $time_to, bool $exclude_disabled = true): array {
		$group_params = [
			'output' => ['groupid', 'name'],
			'sortfield' => 'name'
		];

		if ($hostgroupids !== []) {
			$group_params['groupids'] = $hostgroupids;
		}

		$groups = API::HostGroup()->get($group_params);

		if (!is_array($groups) || $groups === []) {
			return [];
		}

		$group_map = [];
		foreach ($groups as $group) {
			$group_map[(string) $group['groupid']] = (string) $group['name'];
		}

		$host_params = [
			'output' => ['hostid', 'host', 'name', 'status'],
			'groupids' => array_keys($group_map),
			'selectHostGroups' => ['groupid']
		];

		if ($exclude_disabled) {
			$host_params['monitored_hosts'] = true;
		}

		$hosts = API::Host()->get($host_params);

		if (!is_array($hosts) || $hosts === []) {
			return [];
		}

		$host_name_map = [];
		$host_groups_map = [];
		$hostids = [];

		foreach ($hosts as $host) {
			$hostid = (string) $host['hostid'];
			$hostids[] = $hostid;
			$host_name_map[$hostid] = (string) (($host['name'] ?? '') !== '' ? $host['name'] : $host['host']);
			$host_groups_map[$hostid] = [];

			if (isset($host['hostgroups']) && is_array($host['hostgroups'])) {
				foreach ($host['hostgroups'] as $group) {
					$groupid = (string) $group['groupid'];

					if (isset($group_map[$groupid])) {
						$host_groups_map[$hostid][] = $group_map[$groupid];
					}
				}
			}
		}

		if ($hostids === []) {
			return [];
		}

		$items = API::Item()->get([
			'output' => ['itemid', 'hostid', 'delay'],
			'hostids' => $hostids,
			'filter' => ['key_' => 'agent.ping']
		]);

		$items_by_host = [];

		if (is_array($items)) {
			usort($items, static function (array $left, array $right): int {
				return strcmp((string) $left['itemid'], (string) $right['itemid']);
			});

			foreach ($items as $item) {
				$hostid = (string) $item['hostid'];

				if (!isset($items_by_host[$hostid])) {
					$items_by_host[$hostid] = [
						'itemid' => (string) $item['itemid'],
						'hostid' => $hostid,
						'delay' => isset($item['delay']) ? (string) $item['delay'] : null
					];
				}
			}
		}

		$window_seconds = max(1, ($time_to - $time_from) + 1);
		$availability_by_host = ($window_seconds > (7 * 86400))
			? $this->calculateAvailabilityFromTrends(array_values($items_by_host), $time_from, $time_to)
			: $this->calculateAvailabilityFromHistory(array_values($items_by_host), $time_from, $time_to);

		$result = [];

		foreach ($group_map as $group_name) {
			$result[$group_name] = [];
		}

		foreach ($hostids as $hostid) {
			$host_name = $host_name_map[$hostid] ?? ('Host '.$hostid);
			$group_names = $host_groups_map[$hostid] ?? [];
			$info = $availability_by_host[$hostid] ?? null;

			if ($info === null) {
				$info = [
					'availability' => 'Item not found',
					'pct' => null,
					'uptime_seconds' => 0,
					'downtime_seconds' => 0
				];
			}

			foreach ($group_names as $group_name) {
				$result[$group_name][] = [
					'hostid' => $hostid,
					'host' => $host_name,
					'availability' => $info['availability'],
					'availability_pct' => $info['pct'],
					'uptime_seconds' => $info['uptime_seconds'],
					'downtime_seconds' => $info['downtime_seconds']
				];
			}
		}

		foreach ($result as &$rows) {
			usort($rows, static function (array $left, array $right): int {
				return strcmp((string) $left['host'], (string) $right['host']);
			});
		}
		unset($rows);

		$result = array_filter($result, static function (array $rows): bool {
			return $rows !== [];
		});

		ksort($result);

		return $result;
	}

	public function getGroupAverage(array $rows): ?float {
		$values = [];

		foreach ($rows as $row) {
			if ($row['availability_pct'] !== null) {
				$values[] = (float) $row['availability_pct'];
			}
		}

		if ($values === []) {
			return null;
		}

		return array_sum($values) / count($values);
	}

	public function getAvailabilityBandCounts(array $rows): array {
		$counts = [
			'green' => 0,
			'yellow' => 0,
			'red' => 0,
			'na' => 0
		];

		foreach ($rows as $row) {
			$pct = $row['availability_pct'];

			if ($pct === null) {
				$counts['na']++;
			}
			elseif ($pct >= 99.0) {
				$counts['green']++;
			}
			elseif ($pct >= 90.0) {
				$counts['yellow']++;
			}
			else {
				$counts['red']++;
			}
		}

		return $counts;
	}

	public function parsePct(?string $value): ?float {
		if ($value === null) {
			return null;
		}

		$value = trim($value);

		if ($value === '' || strtoupper($value) === 'N/A') {
			return null;
		}

		if (substr($value, -1) === '%') {
			$value = substr($value, 0, -1);
		}

		return is_numeric($value) ? (float) $value : null;
	}

	public function formatPct(?float $value, int $decimals = 1): string {
		if ($value === null) {
			return 'N/A';
		}

		return number_format($value, $decimals, '.', '').'%';
	}

	public function formatDuration(int $seconds): string {
		$seconds = max(0, $seconds);

		$days = intdiv($seconds, 86400);
		$seconds %= 86400;
		$hours = intdiv($seconds, 3600);
		$seconds %= 3600;
		$minutes = intdiv($seconds, 60);
		$seconds %= 60;

		$parts = [];

		if ($days > 0) {
			$parts[] = $days.'d';
		}

		if ($hours > 0 || $days > 0) {
			$parts[] = $hours.'h';
		}

		if ($minutes > 0 || $hours > 0 || $days > 0) {
			$parts[] = $minutes.'m';
		}

		$parts[] = $seconds.'s';

		return implode(' ', $parts);
	}

	public function availabilityCssClass(?float $pct): string {
		if ($pct === null) {
			return 'slareport-na-text';
		}

		if ($pct >= 99.0) {
			return 'slareport-ok';
		}

		if ($pct >= 90.0) {
			return 'slareport-warn';
		}

		return 'slareport-bad';
	}

	public function sliCssClass(?float $pct, ?float $slo): string {
		if ($pct === null) {
			return 'slareport-cell-na';
		}

		if ($slo !== null) {
			if ($pct >= $slo) {
				return 'slareport-cell-ok';
			}

			if (($slo - $pct) <= 1.0) {
				return 'slareport-cell-warn';
			}

			return 'slareport-cell-bad';
		}

		if ($pct >= 99.0) {
			return 'slareport-cell-ok';
		}

		if ($pct >= 90.0) {
			return 'slareport-cell-warn';
		}

		return 'slareport-cell-bad';
	}

	public function flattenSlaRows(array $sla_heatmap): array {
		$rows = [];

		foreach ($sla_heatmap as $slaid => $sla) {
			foreach ($sla['service_data'] as $serviceid => $service) {
				$months = $service['month_labels'] ?? [];
				$values = $service['monthly_sli'] ?? [];

				for ($i = 0; $i < count($months); $i++) {
					$rows[] = [
						$slaid,
						$sla['sla_name'] ?? '',
						$serviceid,
						$service['name'] ?? '',
						$service['slo'] ?? 'N/A',
						$months[$i] ?? '',
						$values[$i] ?? 'N/A'
					];
				}
			}
		}

		return $rows;
	}

	public function flattenAvailabilityRows(array $availability, int $time_from, int $time_to): array {
		$rows = [];

		foreach ($availability as $group_name => $hosts) {
			foreach ($hosts as $host) {
				$rows[] = [
					$group_name,
					$host['host'] ?? '',
					$host['availability'] ?? 'N/A',
					$host['availability_pct'] !== null ? number_format((float) $host['availability_pct'], 4, '.', '') : '',
					$host['uptime_seconds'] ?? 0,
					$host['downtime_seconds'] ?? 0,
					gmdate('Y-m-d H:i:s', $time_from),
					gmdate('Y-m-d H:i:s', $time_to)
				];
			}
		}

		return $rows;
	}

	private static function normalizeIdArray($value): array {
		if (!is_array($value)) {
			return [];
		}

		$result = [];

		foreach ($value as $id) {
			$id = trim((string) $id);

			if ($id !== '' && ctype_digit($id)) {
				$result[$id] = $id;
			}
		}

		return array_values($result);
	}

	private static function parseDateBoundary(string $value, string $type): ?int {
		$value = trim($value);

		if ($value === '') {
			return null;
		}

		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
			return null;
		}

		$year = (int) $matches[1];
		$month = (int) $matches[2];
		$day = (int) $matches[3];

		if (!checkdate($month, $day, $year)) {
			return null;
		}

		return ($type === 'start')
			? gmmktime(0, 0, 0, $month, $day, $year)
			: gmmktime(23, 59, 59, $month, $day, $year);
	}

	private function calculateAvailabilityFromHistory(array $items, int $time_from, int $time_to): array {
		$result = [];
		$window_seconds = max(1, ($time_to - $time_from) + 1);

		foreach ($items as $item) {
			$history = $this->getHistoryRows((string) $item['itemid'], $time_from, $time_to);
			$interval = $this->inferInterval($history, $item['delay'] ?? null);
			$expected = count($history) + $this->countMissingSamples($history, $time_from, $time_to, $interval);

			$ok = 0;
			foreach ($history as $row) {
				if ((string) $row['value'] === '1') {
					$ok++;
				}
			}

			$pct = $expected > 0 ? (($ok / $expected) * 100.0) : null;
			$uptime_seconds = $pct !== null ? (int) round(($pct / 100.0) * $window_seconds) : 0;

			$result[(string) $item['hostid']] = [
				'availability' => $this->formatPct($pct, 1),
				'pct' => $pct,
				'uptime_seconds' => max(0, min($window_seconds, $uptime_seconds)),
				'downtime_seconds' => max(0, $window_seconds - $uptime_seconds)
			];
		}

		return $result;
	}

	private function calculateAvailabilityFromTrends(array $items, int $time_from, int $time_to): array {
		$result = [];
		$window_seconds = max(1, ($time_to - $time_from) + 1);
		$expected_hours = max(1, (int) ceil($window_seconds / 3600));

		if ($items === []) {
			return $result;
		}

		$itemids = [];
		$host_by_item = [];
		foreach ($items as $item) {
			$itemids[] = (string) $item['itemid'];
			$host_by_item[(string) $item['itemid']] = (string) $item['hostid'];
		}

		$trends = API::Trend()->get([
			'output' => ['itemid', 'clock', 'num', 'value_avg'],
			'itemids' => $itemids,
			'time_from' => $time_from,
			'time_till' => $time_to
		]);

		$up_hours_by_host = [];

		if (is_array($trends)) {
			foreach ($trends as $trend) {
				$itemid = (string) $trend['itemid'];

				if (!isset($host_by_item[$itemid])) {
					continue;
				}

				$hostid = $host_by_item[$itemid];

				if (!isset($up_hours_by_host[$hostid])) {
					$up_hours_by_host[$hostid] = 0.0;
				}

				$up_hours_by_host[$hostid] += (float) $trend['value_avg'];
			}
		}

		foreach ($items as $item) {
			$hostid = (string) $item['hostid'];
			$up_hours = $up_hours_by_host[$hostid] ?? 0.0;
			$pct = ($up_hours / $expected_hours) * 100.0;
			$pct = max(0.0, min(100.0, $pct));
			$uptime_seconds = (int) round($up_hours * 3600);

			$result[$hostid] = [
				'availability' => $this->formatPct($pct, 1),
				'pct' => $pct,
				'uptime_seconds' => max(0, min($window_seconds, $uptime_seconds)),
				'downtime_seconds' => max(0, $window_seconds - $uptime_seconds)
			];
		}

		return $result;
	}

	private function getHistoryRows(string $itemid, int $time_from, int $time_to): array {
		$rows = [];
		$cursor = $time_from;

		do {
			$chunk = API::History()->get([
				'output' => ['clock', 'value'],
				'itemids' => [$itemid],
				'history' => ITEM_VALUE_TYPE_UINT64,
				'time_from' => $cursor,
				'time_till' => $time_to,
				'sortfield' => 'clock',
				'sortorder' => 'ASC',
				'limit' => 10000
			]);

			if (!is_array($chunk) || $chunk === []) {
				break;
			}

			foreach ($chunk as $row) {
				$rows[] = $row;
			}

			$last_clock = (int) $chunk[count($chunk) - 1]['clock'];

			if ($last_clock >= $time_to || count($chunk) < 10000) {
				break;
			}

			$cursor = $last_clock + 1;
		}
		while (true);

		return $rows;
	}

	private function countMissingSamples(array $history, int $start, int $end, int $step): int {
		if ($step <= 0) {
			return 0;
		}

		$observed = count($history);

		if ($observed === 0) {
			return max(0, (int) floor(max(0, $end - $start) / $step));
		}

		$missing = max(0, (int) floor((((int) $history[0]['clock']) - $start) / $step));

		for ($i = 1; $i < $observed; $i++) {
			$gap = (int) $history[$i]['clock'] - (int) $history[$i - 1]['clock'];

			if ($gap > $step) {
				$missing += max(0, (int) floor($gap / $step) - 1);
			}
		}

		$missing += max(0, (int) floor(($end - (int) $history[$observed - 1]['clock']) / $step));

		return $missing;
	}

	private function inferInterval(array $history, ?string $delay): int {
		$parsed_delay = $this->parseDelayToSeconds($delay);

		if ($parsed_delay !== null && $parsed_delay > 0) {
			return $parsed_delay;
		}

		$deltas = [];

		for ($i = 1; $i < count($history); $i++) {
			$delta = (int) $history[$i]['clock'] - (int) $history[$i - 1]['clock'];

			if ($delta > 0) {
				$deltas[] = $delta;
			}
		}

		if ($deltas === []) {
			return 60;
		}

		sort($deltas);
		$middle = intdiv(count($deltas), 2);

		if (count($deltas) % 2 === 1) {
			return max(1, (int) $deltas[$middle]);
		}

		return max(1, (int) round(($deltas[$middle - 1] + $deltas[$middle]) / 2));
	}

	private function parseDelayToSeconds(?string $delay): ?int {
		if ($delay === null) {
			return null;
		}

		$delay = trim($delay);

		if ($delay === '') {
			return null;
		}

		if (ctype_digit($delay)) {
			return (int) $delay;
		}

		if (preg_match('/^(\d+)\s*([smhdw])$/i', $delay, $matches) === 1) {
			$value = (int) $matches[1];
			$unit = strtolower($matches[2]);

			switch ($unit) {
				case 's':
					return $value;
				case 'm':
					return $value * 60;
				case 'h':
					return $value * 3600;
				case 'd':
					return $value * 86400;
				case 'w':
					return $value * 604800;
			}
		}

		return null;
	}
}
