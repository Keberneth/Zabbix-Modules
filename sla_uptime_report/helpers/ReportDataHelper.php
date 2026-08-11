<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Helpers;

use API;

class ReportDataHelper {

	public const SLA_MONTHS = 12;

	/**
	 * Hard bounds. All heavy fetches clamp to these and surface a warning when a
	 * cap is hit, so a single oversized request can never white-screen the frontend.
	 */
	public const MAX_RANGE_DAYS = 768;
	public const MAX_HOSTS = 2000;
	public const MAX_SLAS = 200;
	public const MAX_GROUP_OPTIONS = 500;
	public const FETCH_BATCH = 2000;

	/**
	 * Rows PROCESSED (not retained - the scan is streaming) before the history
	 * scan stops and says so. Bounds wall-clock time, not memory.
	 */
	public const MAX_HISTORY_ROWS = 2000000;
	public const HISTORY_ITEM_CHUNK = 500;
	public const ITEM_HOST_CHUNK = 1000;
	public const TRENDS_THRESHOLD_SECONDS = 7 * 86400;

	/**
	 * Trend fetches are chunked so one API call returns at most roughly this
	 * many rows. Each row costs ~470 bytes as a PHP array, so this transient is
	 * ~23 MB - it must stay well under the web server's memory_limit.
	 */
	public const TREND_ROWS_PER_CALL = 50000;

	/**
	 * Hosts that get a per-day sparkline. Per-host daily detail is the one
	 * structure that scales as hosts x days, so it is bounded; totals and the
	 * per-group daily chart are computed for every host regardless.
	 */
	public const MAX_SPARK_HOSTS = 400;

	/**
	 * Availability is detected from any one of these item keys (priority order:
	 * first match wins). All are unsigned items where value 1 means "up".
	 */
	public const AVAILABILITY_ITEM_KEYS = ['agent.ping', 'icmpping', 'zabbix[host,agent,available]'];

	/** Below this availability a host is always in the red band. */
	public const BAD_THRESHOLD = 90.0;

	/** @var array<string,string> */
	private array $notes = [];

	/** @return array<int,string> */
	public function getNotes(): array {
		return array_values($this->notes);
	}

	private function addNote(string $note): void {
		$this->notes[$note] = $note;
	}

	// ------------------------------------------------------------------ filter

	public static function getDefaultFilter(): array {
		$prev_month = gmdate('Y-m', gmmktime(0, 0, 0, (int) gmdate('n') - 1, 1, (int) gmdate('Y')));

		return [
			'mode' => 'days_back',
			'month' => $prev_month,
			'date_from' => '',
			'date_to' => '',
			'days_back' => 30,
			'hostgroupids' => [],
			'slaids' => [],
			'exclude_disabled' => 1,
			'target' => 99.0,
			'top' => 100,
			'tab' => 'overview'
		];
	}

	public static function normalizeFilter(array $input): array {
		$defaults = self::getDefaultFilter();

		$filter = $defaults;
		$filter['mode'] = in_array($input['mode'] ?? $defaults['mode'], ['prev_month', 'specific_month', 'custom_range', 'days_back'], true)
			? (string) $input['mode']
			: $defaults['mode'];
		$filter['month'] = preg_match('/^\d{4}-\d{2}$/', trim((string) ($input['month'] ?? ''))) === 1
			? trim((string) $input['month'])
			: $defaults['month'];
		$filter['date_from'] = substr(trim((string) ($input['date_from'] ?? '')), 0, 10);
		$filter['date_to'] = substr(trim((string) ($input['date_to'] ?? '')), 0, 10);
		$filter['days_back'] = max(1, min(366, (int) ($input['days_back'] ?? $defaults['days_back'])));
		$filter['hostgroupids'] = self::normalizeIdArray($input['hostgroupids'] ?? []);
		$filter['slaids'] = self::normalizeIdArray($input['slaids'] ?? []);
		$filter['exclude_disabled'] = !empty($input['exclude_disabled']) ? 1 : 0;

		// Accept a comma decimal separator; anything else non-numeric falls
		// back to the default rather than silently truncating ("99,5" must
		// not become 99.0).
		$target_raw = str_replace(',', '.', trim((string) ($input['target'] ?? $defaults['target'])));
		$target = is_numeric($target_raw) ? (float) $target_raw : $defaults['target'];
		$filter['target'] = ($target >= 50.0 && $target <= 99.999) ? round($target, 3) : $defaults['target'];

		$filter['top'] = max(10, min(500, (int) ($input['top'] ?? $defaults['top'])));
		$filter['tab'] = in_array($input['tab'] ?? '', ['overview', 'slas', 'availability'], true)
			? (string) $input['tab']
			: $defaults['tab'];

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

						return self::clampRange([$from, $to]);
					}
				}
				break;

			case 'custom_range':
				$from = self::parseDateBoundary($filter['date_from'], 'start');
				$to = self::parseDateBoundary($filter['date_to'], 'end');

				if ($from !== null && $to !== null && $to >= $from) {
					return self::clampRange([$from, $to]);
				}
				break;

			case 'days_back':
				$days_back = max(1, min(366, (int) $filter['days_back']));
				$now = time();

				return self::clampRange([$now - ($days_back * 86400), $now]);
		}

		$first_this_month = gmmktime(0, 0, 0, (int) gmdate('n'), 1, (int) gmdate('Y'));
		$first_prev_month = gmmktime(0, 0, 0, (int) gmdate('n') - 1, 1, (int) gmdate('Y'));

		return self::clampRange([$first_prev_month, $first_this_month - 1]);
	}

	/**
	 * @param array{0:int,1:int} $range
	 * @return array{0:int,1:int}
	 */
	private static function clampRange(array $range): array {
		[$from, $to] = $range;
		$max_seconds = self::MAX_RANGE_DAYS * 86400;

		if (($to - $from) > $max_seconds) {
			$from = $to - $max_seconds;
		}

		return [$from, $to];
	}

	// ------------------------------------------------------------------ report

	/**
	 * Build the whole report from one place, so every tab and the export read
	 * the same numbers.
	 */
	public function buildReport(array $filter, int $time_from, int $time_to): array {
		$report = $this->emptyReport($filter);

		$report['period'] = [
			'from' => $time_from,
			'to' => $time_to,
			'days' => max(1, (int) ceil(($time_to - $time_from + 1) / 86400)),
			'label' => $this->formatPeriodLabel($time_from, $time_to)
		];

		// Span without the inclusive +1: a "7 days back" selection is exactly
		// the threshold and must still take the precise raw-history path.
		$report['source_used'] = ($time_to - $time_from) > self::TRENDS_THRESHOLD_SECONDS ? 'trends' : 'history';

		$report['group_options'] = $this->getGroupOptions();
		$report['sla_options'] = $this->getSlaOptions();

		// The intersections exist for the filter chips only. Scoping uses the
		// SUBMITTED ids: a selected group or SLA that stopped resolving (lost
		// its hosts, was disabled, fell past a truncation cap) must narrow the
		// report to nothing and say so - silently widening to "everything the
		// account can read" would leak other customers' data into an export.
		$report['selected_groupids'] = array_values(array_intersect(
			$filter['hostgroupids'],
			array_map(static fn(array $g): string => (string) $g['groupid'], $report['group_options'])
		));
		$report['selected_slaids'] = array_values(array_intersect(
			$filter['slaids'],
			array_map(static fn(array $s): string => (string) $s['slaid'], $report['sla_options'])
		));

		if ($filter['hostgroupids'] !== [] && count($report['selected_groupids']) < count($filter['hostgroupids'])) {
			$this->addNote(_('Some selected host groups no longer exist, have no hosts, or are not visible to you. The report covers only the ones that resolved.'));
		}
		if ($filter['slaids'] !== [] && count($report['selected_slaids']) < count($filter['slaids'])) {
			$this->addNote(_('Some selected SLAs are disabled, deleted or not visible to you. The report covers only the ones that resolved.'));
		}

		$availability = $this->buildAvailability(
			$filter['hostgroupids'] !== [] ? $report['selected_groupids'] : [],
			$time_from,
			$time_to,
			(bool) $filter['exclude_disabled'],
			(float) $filter['target'],
			(int) $filter['top'],
			$report['group_options'],
			$filter['hostgroupids'] !== []
		);
		$report['groups'] = $availability['groups'];
		$report['fleet'] = $availability['fleet'];
		$report['daily'] = $availability['daily'];
		$report['availability_health'] = $availability['health'];

		$slas = ($filter['slaids'] !== [] && $report['selected_slaids'] === [])
			? ['slas' => [], 'summary' => $report['sla_summary']]
			: $this->buildSlas($report['selected_slaids'], $time_to);
		$report['slas'] = $slas['slas'];
		$report['sla_summary'] = $slas['summary'];

		$report['cards'] = $this->buildCards($report, $filter);
		$report['attention'] = $this->buildAttention($report, $filter);
		$report['warnings'] = array_merge($report['warnings'], $this->getNotes());

		return $report;
	}

	public function emptyReport(array $filter): array {
		return [
			'period' => ['from' => 0, 'to' => 0, 'days' => 0, 'label' => ''],
			'source_used' => 'history',
			'group_options' => [],
			'sla_options' => [],
			'selected_groupids' => [],
			'selected_slaids' => [],
			'groups' => [],
			'fleet' => [
				'avg' => null, 'hosts_total' => 0, 'with_data' => 0, 'below_target' => 0,
				'na' => 0, 'downtime_seconds' => 0, 'worst_host' => null, 'worst_pct' => null
			],
			'daily' => ['dates' => [], 'series' => []],
			'availability_health' => ['critical' => [], 'warning' => [], 'nodata' => []],
			'slas' => [],
			'sla_summary' => [
				'slas_total' => 0, 'services_total' => 0, 'meeting' => 0, 'below' => 0, 'na' => 0,
				'breach_months' => 0
			],
			'cards' => [],
			'attention' => [],
			'warnings' => [],
			'error' => null
		];
	}

	public function formatPeriodLabel(int $time_from, int $time_to): string {
		if ($time_from <= 0 || $time_to <= 0) {
			return '';
		}

		return gmdate('j M Y', $time_from).' – '.gmdate('j M Y', $time_to).' UTC';
	}

	// ----------------------------------------------------------- filter options

	/**
	 * Host groups offered in the filter: every group that contains at least one
	 * real host. Listed as chips; the client filters them by text.
	 */
	private function getGroupOptions(): array {
		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'with_hosts' => true,
			'sortfield' => 'name'
		]);

		if (!is_array($groups)) {
			return [];
		}

		if (count($groups) > self::MAX_GROUP_OPTIONS) {
			$groups = array_slice($groups, 0, self::MAX_GROUP_OPTIONS);
			$this->addNote(_s('The host group list is limited to the first %1$d groups.', self::MAX_GROUP_OPTIONS));
		}

		return array_map(static function(array $group): array {
			return ['groupid' => (string) $group['groupid'], 'name' => (string) $group['name']];
		}, $groups);
	}

	private function getSlaOptions(): array {
		$slas = API::Sla()->get([
			'output' => ['slaid', 'name', 'slo', 'status'],
			'filter' => ['status' => ZBX_SLA_STATUS_ENABLED],
			'sortfield' => 'name'
		]);

		if (!is_array($slas)) {
			return [];
		}

		if (count($slas) > self::MAX_SLAS) {
			$slas = array_slice($slas, 0, self::MAX_SLAS);
			$this->addNote(_s('The SLA list is limited to the first %1$d SLAs.', self::MAX_SLAS));
		}

		return array_map(static function(array $sla): array {
			return [
				'slaid' => (string) $sla['slaid'],
				'name' => (string) $sla['name'],
				'slo' => isset($sla['slo']) ? (float) $sla['slo'] : null
			];
		}, $slas);
	}

	// ------------------------------------------------------------- availability

	/**
	 * Host availability: totals, per-day series, group roll-ups and fleet
	 * figures, computed once for every consumer.
	 */
	private function buildAvailability(
		array $groupids,
		int $time_from,
		int $time_to,
		bool $exclude_disabled,
		float $target,
		int $top,
		array $group_options,
		bool $filtered_selection = false
	): array {
		$empty = [
			'groups' => [],
			'fleet' => [
				'avg' => null, 'hosts_total' => 0, 'with_data' => 0, 'below_target' => 0,
				'na' => 0, 'downtime_seconds' => 0, 'worst_host' => null, 'worst_pct' => null
			],
			'daily' => ['dates' => [], 'series' => []],
			'health' => ['critical' => [], 'warning' => [], 'nodata' => []]
		];

		// An explicit selection that resolved to nothing stays nothing.
		if ($groupids === [] && $filtered_selection) {
			return $empty;
		}

		$group_params = [
			'output' => ['groupid', 'name'],
			'with_hosts' => true,
			'sortfield' => 'name'
		];
		if ($groupids !== []) {
			$group_params['groupids'] = $groupids;
		}

		$groups = API::HostGroup()->get($group_params);
		if (!is_array($groups) || $groups === []) {
			return $empty;
		}

		$group_map = [];
		foreach ($groups as $group) {
			$group_map[(string) $group['groupid']] = (string) $group['name'];
		}

		// Colour slots: each group's BASE slot follows its position in the full
		// option list, so filtering rarely repaints survivors - but two groups
		// whose base slots collide would sit side by side in the stacked chart
		// wearing the same colour, which is worse. Collisions among the groups
		// actually present are re-slotted to free colours (name order, so the
		// resolution itself is deterministic). Slot 8 stays reserved for the
		// chart's "Other" bucket.
		$group_slots = ChartRenderer::SERIES_SLOTS - 1;
		$base_slot = [];
		$slot = 0;
		foreach ($group_options as $option) {
			$base_slot[(string) $option['name']] = $slot % $group_slots;
			$slot++;
		}

		$slot_map = [];
		$used = [];
		$present = array_values($group_map);
		sort($present, SORT_NATURAL | SORT_FLAG_CASE);
		foreach ($present as $group_name) {
			$candidate = $base_slot[$group_name] ?? 0;
			if (count($used) < $group_slots) {
				while (isset($used[$candidate])) {
					$candidate = ($candidate + 1) % $group_slots;
				}
			}
			$slot_map[$group_name] = $candidate;
			$used[$candidate] = true;
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
			return $empty;
		}

		if (count($hosts) > self::MAX_HOSTS) {
			$hosts = array_slice($hosts, 0, self::MAX_HOSTS);
			$this->addNote(_s('Availability is limited to the first %1$d hosts; narrow the host group filter to see the rest.', self::MAX_HOSTS));
		}

		$host_rows = [];
		$hostids = [];
		foreach ($hosts as $host) {
			$hostid = (string) $host['hostid'];
			$hostids[] = $hostid;

			$names = [];
			foreach ((array) ($host['hostgroups'] ?? []) as $group) {
				$groupid = (string) $group['groupid'];
				if (isset($group_map[$groupid])) {
					$names[] = $group_map[$groupid];
				}
			}

			$host_rows[$hostid] = [
				'hostid' => $hostid,
				'host' => (string) (($host['name'] ?? '') !== '' ? $host['name'] : $host['host']),
				'enabled' => (int) ($host['status'] ?? 0) === 0,
				'groups' => $names,
				'item_key' => null,
				'pct' => null,
				'state' => 'noitem',
				'uptime_seconds' => 0,
				'downtime_seconds' => 0,
				'spark' => []
			];
		}

		// Resolve one availability item per host (key priority order).
		$priority = array_flip(self::AVAILABILITY_ITEM_KEYS);
		$items_by_host = [];

		foreach (array_chunk($hostids, self::ITEM_HOST_CHUNK) as $hostid_chunk) {
			$items = API::Item()->get([
				'output' => ['itemid', 'hostid', 'key_', 'delay'],
				'hostids' => $hostid_chunk,
				'filter' => ['key_' => self::AVAILABILITY_ITEM_KEYS]
			]);

			if (!is_array($items)) {
				continue;
			}

			foreach ($items as $item) {
				$hostid = (string) $item['hostid'];
				$itemid = (string) $item['itemid'];
				$rank = $priority[(string) $item['key_']] ?? PHP_INT_MAX;
				$current = $items_by_host[$hostid] ?? null;

				if ($current === null
						|| $rank < $current['priority']
						|| ($rank === $current['priority'] && strcmp($itemid, $current['itemid']) < 0)) {
					$items_by_host[$hostid] = [
						'itemid' => $itemid,
						'hostid' => $hostid,
						'key_' => (string) $item['key_'],
						'delay' => isset($item['delay']) ? (string) $item['delay'] : null,
						'priority' => $rank
					];
				}
			}
		}

		foreach ($items_by_host as $hostid => $item) {
			if (isset($host_rows[$hostid])) {
				$host_rows[$hostid]['item_key'] = $item['key_'];
			}
		}

		// Each host's downtime is charged to its FIRST group in the daily
		// chart, so the stacked total equals the real downtime, not a multiple.
		$group_of_host = [];
		foreach ($host_rows as $hostid => $row) {
			if ($row['groups'] !== []) {
				$group_of_host[$hostid] = $row['groups'][0];
			}
		}

		$measured = (($time_to - $time_from) > self::TRENDS_THRESHOLD_SECONDS)
			? $this->availabilityFromTrends(array_values($items_by_host), $time_from, $time_to, $group_of_host)
			: $this->availabilityFromHistory(array_values($items_by_host), $time_from, $time_to, $group_of_host);
		$group_daily = $measured['group_daily'];

		// Merge measurements into the host rows.
		foreach ($host_rows as $hostid => $row) {
			$info = $measured['hosts'][$hostid] ?? null;

			if ($row['item_key'] === null) {
				$host_rows[$hostid]['state'] = 'noitem';
				continue;
			}

			if ($info === null || $info['pct'] === null) {
				$host_rows[$hostid]['state'] = 'nodata';
				continue;
			}

			$pct = (float) $info['pct'];
			$host_rows[$hostid]['pct'] = $pct;
			$host_rows[$hostid]['uptime_seconds'] = (int) $info['uptime_seconds'];
			$host_rows[$hostid]['downtime_seconds'] = (int) $info['downtime_seconds'];
			$host_rows[$hostid]['spark'] = $info['spark'];
			$host_rows[$hostid]['state'] = $this->availabilityState($pct, $target);
		}

		// ---- group roll-ups -------------------------------------------------
		$group_rollups = [];
		foreach ($group_map as $group_name) {
			$group_rollups[$group_name] = [
				'name' => $group_name,
				'token' => ChartRenderer::seriesToken($slot_map[$group_name] ?? 0),
				'hosts_total' => 0,
				'with_data' => 0,
				'avg' => null,
				'bands' => ['ok' => 0, 'warn' => 0, 'bad' => 0, 'na' => 0],
				'downtime_seconds' => 0,
				'worst_host' => null,
				'worst_pct' => null,
				'rows' => [],
				'rows_total' => 0
			];
		}

		foreach ($host_rows as $row) {
			foreach ($row['groups'] as $group_name) {
				if (!isset($group_rollups[$group_name])) {
					continue;
				}

				$rollup = &$group_rollups[$group_name];
				$rollup['hosts_total']++;
				$rollup['rows'][] = $row;

				if ($row['pct'] === null) {
					$rollup['bands']['na']++;
				}
				else {
					$rollup['with_data']++;
					$rollup['downtime_seconds'] += (int) $row['downtime_seconds'];
					$band = $row['state'] === 'ok' ? 'ok' : ($row['state'] === 'warn' ? 'warn' : 'bad');
					$rollup['bands'][$band]++;

					if ($rollup['worst_pct'] === null || $row['pct'] < $rollup['worst_pct']) {
						$rollup['worst_pct'] = $row['pct'];
						$rollup['worst_host'] = $row['host'];
					}
				}
				unset($rollup);
			}
		}

		foreach ($group_rollups as $group_name => $rollup) {
			$values = [];
			foreach ($rollup['rows'] as $row) {
				if ($row['pct'] !== null) {
					$values[] = (float) $row['pct'];
				}
			}
			$group_rollups[$group_name]['avg'] = $values !== [] ? array_sum($values) / count($values) : null;

			// Worst first inside each group, hosts without data at the end.
			usort($group_rollups[$group_name]['rows'], static function(array $a, array $b): int {
				if (($a['pct'] === null) !== ($b['pct'] === null)) {
					return $a['pct'] === null ? 1 : -1;
				}
				if ($a['pct'] === null) {
					return strcasecmp($a['host'], $b['host']);
				}

				return $a['pct'] <=> $b['pct'] ?: strcasecmp($a['host'], $b['host']);
			});

			$group_rollups[$group_name]['rows_total'] = count($group_rollups[$group_name]['rows']);
			// The untruncated list survives for the CSV export; slicing only
			// affects what the HTML tables show. Copy-on-write keeps this cheap.
			$group_rollups[$group_name]['rows_all'] = $group_rollups[$group_name]['rows'];
			$group_rollups[$group_name]['rows'] = array_slice($group_rollups[$group_name]['rows'], 0, $top);
		}

		// Drop empty groups, keep name order.
		$group_rollups = array_filter($group_rollups, static fn(array $g): bool => $g['hosts_total'] > 0);
		ksort($group_rollups, SORT_NATURAL | SORT_FLAG_CASE);

		// ---- fleet ----------------------------------------------------------
		$fleet = [
			'avg' => null, 'hosts_total' => count($host_rows), 'with_data' => 0, 'below_target' => 0,
			'na' => 0, 'downtime_seconds' => 0, 'worst_host' => null, 'worst_pct' => null
		];
		$values = [];
		foreach ($host_rows as $row) {
			if ($row['pct'] === null) {
				$fleet['na']++;
				continue;
			}

			$fleet['with_data']++;
			$values[] = (float) $row['pct'];
			$fleet['downtime_seconds'] += (int) $row['downtime_seconds'];
			if ($row['pct'] < $target) {
				$fleet['below_target']++;
			}
			if ($fleet['worst_pct'] === null || $row['pct'] < $fleet['worst_pct']) {
				$fleet['worst_pct'] = $row['pct'];
				$fleet['worst_host'] = $row['host'];
			}
		}
		$fleet['avg'] = $values !== [] ? array_sum($values) / count($values) : null;

		// ---- daily downtime, stacked by group -------------------------------
		$daily = $this->buildDailyFromGroups($group_daily, $group_rollups);

		// ---- health lists (over ALL hosts, never a truncated table) ---------
		$health = ['critical' => [], 'warning' => [], 'nodata' => []];
		foreach ($host_rows as $row) {
			if ($row['state'] === 'bad') {
				$health['critical'][] = $row;
			}
			elseif ($row['state'] === 'warn') {
				$health['warning'][] = $row;
			}
			elseif ($row['state'] === 'noitem' || $row['state'] === 'nodata') {
				$health['nodata'][] = $row;
			}
		}
		usort($health['critical'], static fn(array $a, array $b): int => ($a['pct'] ?? 0) <=> ($b['pct'] ?? 0));
		usort($health['warning'], static fn(array $a, array $b): int => ($a['pct'] ?? 0) <=> ($b['pct'] ?? 0));

		return [
			'groups' => array_values($group_rollups),
			'fleet' => $fleet,
			'daily' => $daily,
			'health' => $health
		];
	}

	/**
	 * Downtime minutes per day, stacked by host group (top groups by downtime,
	 * tail folded into "Other"). Consumes the per-group accumulators built
	 * while streaming, so no per-host daily detail is retained.
	 *
	 * @param array<string,array<string,float>> $group_daily group => date => minutes
	 * @return array{dates:array,series:array}
	 */
	private function buildDailyFromGroups(array $group_daily, array $group_rollups): array {
		if ($group_daily === []) {
			return ['dates' => [], 'series' => []];
		}

		$dates_seen = [];
		$totals = [];
		foreach ($group_daily as $group_name => $days) {
			$totals[$group_name] = array_sum($days);
			foreach ($days as $date => $_minutes) {
				$dates_seen[$date] = true;
			}
		}

		$dates = array_keys($dates_seen);
		sort($dates);
		arsort($totals);

		$series = [];
		$other = [];
		$index = 0;
		foreach (array_keys($totals) as $group_name) {
			$values = [];
			foreach ($dates as $date) {
				$values[] = round((float) ($group_daily[$group_name][$date] ?? 0.0), 2);
			}

			if ($index < ChartRenderer::SERIES_SLOTS - 1 || count($totals) <= ChartRenderer::SERIES_SLOTS) {
				$series[] = [
					'label' => $group_name,
					'token' => $group_rollups[$group_name]['token'] ?? ChartRenderer::seriesToken($index),
					'values' => $values
				];
			}
			else {
				foreach ($values as $i => $value) {
					$other[$i] = ($other[$i] ?? 0.0) + $value;
				}
			}
			$index++;
		}

		if ($other !== []) {
			$series[] = [
				'label' => _('Other groups'),
				'token' => '--sr-s8',
				'values' => array_values($other)
			];
		}

		return ['dates' => $dates, 'series' => $series];
	}

	// ------------------------------------------------------------ availability IO
	// ------------------------------------------------------------ availability IO

	/**
	 * Decode one availability sample into up (1) / down (0).
	 *
	 * agent.ping and icmpping are plain 0/1. zabbix[host,agent,available] is
	 * 0 = unknown, 1 = available, 2 = NOT available - so "2" must count as
	 * down, and clamping it to 1 would invert downtime into uptime. The band
	 * test treats anything that averages near 1 as up and everything else
	 * (0 = unknown/down, 2 = unavailable) as down.
	 */
	private function sampleUp(float $value): float {
		return ($value >= 0.5 && $value < 1.5) ? 1.0 : 0.0;
	}

	/**
	 * Raw-history availability for short windows (<= TRENDS_THRESHOLD_SECONDS).
	 *
	 * STREAMING: each page of rows is folded into per-item counters and
	 * discarded, so memory is O(items x days-in-window), never O(rows).
	 * Availability per item = ok samples / expected samples, where expected =
	 * max(observed, window / interval) - a polling gap counts as downtime.
	 * An item with zero samples reports null (no data), never 0%.
	 *
	 * Keyset pagination detail: a full page may cut a run of same-clock rows
	 * in half, so on a full page the rows carrying the final clock are NOT
	 * processed and the cursor is set to that clock - they are re-read intact
	 * on the next page. Progress is guaranteed because one chunk can produce
	 * at most HISTORY_ITEM_CHUNK rows per second, which is below FETCH_BATCH.
	 *
	 * @param array<int,array{itemid:string,hostid:string,delay:?string}> $items
	 * @param array<string,string> $group_of_host hostid => charged group name
	 */
	private function availabilityFromHistory(array $items, int $time_from, int $time_to,
			array $group_of_host): array {
		$result = ['hosts' => [], 'group_daily' => []];
		$window_seconds = max(1, ($time_to - $time_from) + 1);

		if ($items === []) {
			return $result;
		}

		$itemids = [];
		$host_by_item = [];
		$delay_by_item = [];
		$state = [];

		foreach ($items as $item) {
			$itemid = (string) $item['itemid'];
			$itemids[] = $itemid;
			$host_by_item[$itemid] = (string) $item['hostid'];
			$delay_by_item[$itemid] = $item['delay'] ?? null;
			$state[$itemid] = [
				'ok' => 0,
				'seen' => 0,
				'prev' => null,
				'deltas' => [],
				'days' => []
			];
		}

		$processed = 0;
		$limit_reached = false;

		foreach (array_chunk($itemids, self::HISTORY_ITEM_CHUNK) as $itemid_chunk) {
			$cursor = $time_from;

			do {
				$batch = API::History()->get([
					'output' => ['itemid', 'clock', 'value'],
					'itemids' => $itemid_chunk,
					'history' => ITEM_VALUE_TYPE_UINT64,
					'time_from' => $cursor,
					'time_till' => $time_to,
					'sortfield' => 'clock',
					'sortorder' => 'ASC',
					'limit' => self::FETCH_BATCH
				]);

				if (!is_array($batch) || $batch === []) {
					break;
				}

				$full_page = count($batch) >= self::FETCH_BATCH;
				$last_clock = (int) $batch[count($batch) - 1]['clock'];

				foreach ($batch as $row) {
					$clock = (int) $row['clock'];

					// A full page may split a same-second run; leave that
					// second for the next page so no row is lost.
					if ($full_page && $clock >= $last_clock) {
						continue;
					}

					$itemid = (string) $row['itemid'];
					$item_state = &$state[$itemid];

					$item_state['seen']++;
					if ($this->sampleUp((float) $row['value']) >= 1.0) {
						$item_state['ok']++;
						$date = gmdate('Y-m-d', $clock);
						$item_state['days'][$date] = ($item_state['days'][$date] ?? 0) + 1;
					}

					if ($item_state['prev'] !== null && count($item_state['deltas']) < 512) {
						$delta = $clock - $item_state['prev'];
						if ($delta > 0) {
							$item_state['deltas'][] = $delta;
						}
					}
					$item_state['prev'] = $clock;
					unset($item_state);

					$processed++;
				}

				if ($processed >= self::MAX_HISTORY_ROWS) {
					$limit_reached = true;
					break;
				}

				if (!$full_page || $last_clock >= $time_to) {
					break;
				}

				$cursor = $last_clock;
			}
			while (true);

			if ($limit_reached) {
				break;
			}
		}

		if ($limit_reached) {
			$this->addNote(_s('The history scan stopped after %1$s samples. Hosts scanned before the stop are exact; the rest report "no data". Narrow the host group filter for a complete scan.', self::MAX_HISTORY_ROWS));
		}

		foreach ($itemids as $itemid) {
			$item_state = $state[$itemid];
			$hostid = $host_by_item[$itemid];

			if ($item_state['seen'] === 0) {
				// Zero samples is "no data", never "0% available" - the row
				// cap, a dead item and a decommissioned host all look the
				// same here, and inventing a hard-down verdict for them
				// would page someone about a host that may be fine.
				$result['hosts'][$hostid] = [
					'pct' => null, 'uptime_seconds' => 0, 'downtime_seconds' => 0, 'spark' => []
				];
				continue;
			}

			$interval = $this->inferIntervalFromDeltas($item_state['deltas'], $delay_by_item[$itemid] ?? null);
			$expected = max($item_state['seen'], (int) floor($window_seconds / max(1, $interval)));
			$pct = $expected > 0 ? (($item_state['ok'] / $expected) * 100.0) : null;
			$uptime_seconds = $pct !== null ? (int) round(($pct / 100.0) * $window_seconds) : 0;

			// Daily series: every day inside the window is charged against its
			// expected sample count, so a day with no samples at all shows as
			// a full day of downtime - matching the headline number instead of
			// silently vanishing from the chart.
			$spark = [];
			$group = $group_of_host[$hostid] ?? null;
			for ($day_start = $time_from; $day_start <= $time_to; $day_start = $day_end + 1) {
				$date = gmdate('Y-m-d', $day_start);
				$day_end = min($time_to, (int) gmmktime(23, 59, 59,
					(int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)));

				$overlap = $day_end - $day_start + 1;
				$day_expected = max(1, (int) floor($overlap / max(1, $interval)));
				$day_ok = min($day_expected, (int) ($item_state['days'][$date] ?? 0));
				$availability = $day_ok / $day_expected;

				$spark[] = $availability * 100.0;
				if ($group !== null) {
					$result['group_daily'][$group][$date] =
						($result['group_daily'][$group][$date] ?? 0.0)
						+ (1.0 - $availability) * ($overlap / 60.0);
				}
			}

			$result['hosts'][$hostid] = [
				'pct' => $pct,
				'uptime_seconds' => max(0, min($window_seconds, $uptime_seconds)),
				'downtime_seconds' => max(0, $window_seconds - $uptime_seconds),
				'spark' => $spark
			];
		}

		return $result;
	}

	/**
	 * Trend-based availability for long windows.
	 *
	 * Each hourly trend row is decoded with sampleUp() (so the 0/1/2 agent
	 * item cannot invert downtime) and folded straight into per-host counters
	 * and per-GROUP daily downtime; per-host daily detail exists only for the
	 * first MAX_SPARK_HOSTS hosts' sparklines. Memory is O(hosts + groups x
	 * days + spark hosts x days), never O(rows): chunk sizes keep every API
	 * response under ~TREND_ROWS_PER_CALL rows and each response is discarded
	 * after folding.
	 *
	 * Hours with no trend data are excluded from the denominator rather than
	 * counted as downtime (trend gaps usually mean retention, not outage);
	 * a host with zero coverage reports null.
	 *
	 * @param array<int,array{itemid:string,hostid:string,delay:?string}> $items
	 * @param array<string,string> $group_of_host hostid => charged group name
	 */
	private function availabilityFromTrends(array $items, int $time_from, int $time_to,
			array $group_of_host): array {
		$result = ['hosts' => [], 'group_daily' => []];
		$window_seconds = max(1, ($time_to - $time_from) + 1);

		if ($items === []) {
			return $result;
		}

		$itemids = [];
		$host_by_item = [];
		$spark_hosts = [];
		foreach ($items as $item) {
			$itemids[] = (string) $item['itemid'];
			$host_by_item[(string) $item['itemid']] = (string) $item['hostid'];
			if (count($spark_hosts) < self::MAX_SPARK_HOSTS) {
				$spark_hosts[(string) $item['hostid']] = true;
			}
		}

		if (count($items) > self::MAX_SPARK_HOSTS) {
			$this->addNote(_s('Daily trend sparklines are drawn for the first %1$d hosts; totals and charts cover every host.', self::MAX_SPARK_HOSTS));
		}

		$hours_in_window = max(1, (int) ceil($window_seconds / 3600));
		$chunk_size = max(1, min(1000, (int) floor(self::TREND_ROWS_PER_CALL / $hours_in_window)));

		$up_hours = [];
		$covered_hours = [];
		$spark_up = [];
		$spark_covered = [];

		foreach (array_chunk($itemids, $chunk_size) as $itemid_chunk) {
			$trends = API::Trend()->get([
				'output' => ['itemid', 'clock', 'value_avg'],
				'itemids' => $itemid_chunk,
				'time_from' => $time_from,
				'time_till' => $time_to
			]);

			if (!is_array($trends)) {
				continue;
			}

			foreach ($trends as $trend) {
				$itemid = (string) $trend['itemid'];
				if (!isset($host_by_item[$itemid])) {
					continue;
				}

				$hostid = $host_by_item[$itemid];
				$value = $this->sampleUp((float) $trend['value_avg']);
				$date = gmdate('Y-m-d', (int) $trend['clock']);

				$up_hours[$hostid] = ($up_hours[$hostid] ?? 0.0) + $value;
				$covered_hours[$hostid] = ($covered_hours[$hostid] ?? 0) + 1;

				$group = $group_of_host[$hostid] ?? null;
				if ($group !== null && $value < 1.0) {
					$result['group_daily'][$group][$date] =
						($result['group_daily'][$group][$date] ?? 0.0) + (1.0 - $value) * 60.0;
				}

				if (isset($spark_hosts[$hostid])) {
					$spark_up[$hostid][$date] = ($spark_up[$hostid][$date] ?? 0.0) + $value;
					$spark_covered[$hostid][$date] = ($spark_covered[$hostid][$date] ?? 0) + 1;
				}
			}

			unset($trends);
		}

		foreach ($items as $item) {
			$hostid = (string) $item['hostid'];
			$covered = $covered_hours[$hostid] ?? 0;

			if ($covered === 0) {
				$result['hosts'][$hostid] = [
					'pct' => null, 'uptime_seconds' => 0, 'downtime_seconds' => 0, 'spark' => []
				];
				continue;
			}

			$pct = max(0.0, min(100.0, (($up_hours[$hostid] ?? 0.0) / $covered) * 100.0));
			$uptime_seconds = (int) round(($pct / 100.0) * $window_seconds);

			$spark = [];
			if (isset($spark_covered[$hostid])) {
				$days = $spark_covered[$hostid];
				ksort($days);
				foreach ($days as $date => $day_covered) {
					$availability = $day_covered > 0
						? max(0.0, min(1.0, ($spark_up[$hostid][$date] ?? 0.0) / $day_covered))
						: 1.0;
					$spark[] = $availability * 100.0;
				}
			}

			$result['hosts'][$hostid] = [
				'pct' => $pct,
				'uptime_seconds' => max(0, min($window_seconds, $uptime_seconds)),
				'downtime_seconds' => max(0, $window_seconds - $uptime_seconds),
				'spark' => $spark
			];
		}

		return $result;
	}

	// ---------------------------------------------------------------------- SLA

	/**
	 * Rolling 12-month SLI per SLA/service, with an error budget for the most
	 * recent period. Values stay floats; formatting happens at the view.
	 */
	private function buildSlas(array $slaids, int $time_to): array {
		$summary = [
			'slas_total' => 0, 'services_total' => 0, 'meeting' => 0, 'below' => 0, 'na' => 0,
			'breach_months' => 0
		];

		$params = [
			'output' => ['slaid', 'name', 'slo', 'status', 'period'],
			'filter' => ['status' => ZBX_SLA_STATUS_ENABLED],
			'sortfield' => 'name'
		];
		if ($slaids !== []) {
			$params['slaids'] = $slaids;
		}

		$slas = API::Sla()->get($params);
		if (!is_array($slas) || $slas === []) {
			return ['slas' => [], 'summary' => $summary];
		}

		if (count($slas) > self::MAX_SLAS) {
			$slas = array_slice($slas, 0, self::MAX_SLAS);
			$this->addNote(_s('The SLA report is limited to the first %1$d SLAs; refine the SLA filter to see the rest.', self::MAX_SLAS));
		}

		$end_month = (int) gmdate('n', $time_to);
		$end_year = (int) gmdate('Y', $time_to);
		$end_index = ($end_year * 12) + ($end_month - 1);
		$start_index = $end_index - (self::SLA_MONTHS - 1);
		$period_from = gmmktime(0, 0, 0, ($start_index % 12) + 1, 1, intdiv($start_index, 12));

		$now = time();
		$result = [];

		foreach ($slas as $sla) {
			$slaid = (string) $sla['slaid'];
			$slo = isset($sla['slo']) ? (float) $sla['slo'] : null;

			$services = API::Service()->get([
				'output' => ['serviceid', 'name'],
				'slaids' => [$slaid],
				'sortfield' => 'name'
			]);

			if (!is_array($services) || $services === []) {
				continue;
			}

			$service_ids = array_map(static fn(array $s): string => (string) $s['serviceid'], $services);
			$service_names = [];
			foreach ($services as $service) {
				$service_names[(string) $service['serviceid']] = (string) $service['name'];
			}

			$sli_result = API::Sla()->getSli([
				'slaid' => $slaid,
				'serviceids' => $service_ids,
				'periods' => self::SLA_MONTHS,
				'period_from' => $period_from
			]);

			if (!is_array($sli_result) || empty($sli_result['periods'])) {
				continue;
			}

			// Label periods by what they actually are - getSli returns whatever
			// the SLA's period type is (daily/weekly/monthly/quarterly/annual),
			// and captioning a week as a month would misdate every breach.
			$sla_period = (int) ($sla['period'] ?? 2);
			$months = [];
			$periods = [];
			foreach ($sli_result['periods'] as $period) {
				$from_ts = (int) $period['period_from'];
				switch ($sla_period) {
					case 0: // daily
					case 1: // weekly
						$months[] = gmdate('M j', $from_ts);
						break;
					case 3: // quarterly
						$months[] = 'Q'.((int) ceil((int) gmdate('n', $from_ts) / 3))." '".gmdate('y', $from_ts);
						break;
					case 4: // annually
						$months[] = gmdate('Y', $from_ts);
						break;
					default: // monthly
						$months[] = gmdate('Y-m', $from_ts);
				}
				$periods[] = [
					'from' => $from_ts,
					'to' => (int) $period['period_to']
				];
			}

			$sli_matrix = isset($sli_result['sli']) && is_array($sli_result['sli']) ? $sli_result['sli'] : [];

			// The matrix columns follow the RESPONSE's serviceids (which the API
			// sorts), not the request order. Mapping columns by the request
			// order silently attributes one service's SLI to another.
			$column_by_service = [];
			foreach ((array) ($sli_result['serviceids'] ?? []) as $column => $response_serviceid) {
				$column_by_service[(string) $response_serviceid] = (int) $column;
			}

			$service_rows = [];
			$meeting = 0;
			$below = 0;
			$na = 0;
			$all_values = [];

			foreach ($service_ids as $serviceid) {
				$service_index = $column_by_service[(string) $serviceid] ?? null;

				$sli = [];
				for ($period_index = 0; $period_index < count($months); $period_index++) {
					$value = null;
					if ($service_index !== null && isset($sli_matrix[$period_index][$service_index]['sli'])) {
						$raw = (float) $sli_matrix[$period_index][$service_index]['sli'];
						$value = $raw < 0 ? null : $raw;
					}
					$sli[] = $value;
					if ($value !== null) {
						$all_values[] = $value;
					}
				}

				// Latest measured month and its state against the SLO.
				$latest = null;
				foreach (array_reverse($sli) as $value) {
					if ($value !== null) {
						$latest = $value;
						break;
					}
				}

				$breaches = 0;
				if ($slo !== null) {
					foreach ($sli as $value) {
						if ($value !== null && $value < $slo) {
							$breaches++;
						}
					}
				}

				if ($latest === null || $slo === null) {
					$state = 'na';
					$na++;
				}
				elseif ($latest >= $slo) {
					$state = 'ok';
					$meeting++;
				}
				else {
					$state = ($slo - $latest) <= 0.5 ? 'warn' : 'bad';
					$below++;
				}

				// Error budget for the most recent period: how much of the
				// allowed downtime has been consumed so far.
				$budget = null;
				$last_period = $periods !== [] ? end($periods) : null;
				$last_sli = $sli !== [] ? end($sli) : null;
				if ($slo !== null && $slo < 100.0 && $last_period !== null && $last_sli !== null) {
					$period_len = max(1, $last_period['to'] - $last_period['from']);
					$elapsed = max(1, min($now, $last_period['to']) - $last_period['from']);
					$allowed = $period_len * (1.0 - $slo / 100.0);
					$consumed = $elapsed * (1.0 - $last_sli / 100.0);

					$budget = [
						'allowed_seconds' => (int) round($allowed),
						'consumed_seconds' => (int) round($consumed),
						'remaining_seconds' => (int) round($allowed - $consumed),
						'used_pct' => $allowed > 0 ? ($consumed / $allowed) * 100.0 : null
					];
				}

				$service_rows[] = [
					'serviceid' => $serviceid,
					'name' => $service_names[$serviceid] ?? ('Service '.$serviceid),
					'sli' => $sli,
					'latest' => $latest,
					'state' => $state,
					'breaches' => $breaches,
					'budget' => $budget
				];

				$summary['services_total']++;
				$summary['breach_months'] += $breaches;
			}

			$summary['meeting'] += $meeting;
			$summary['below'] += $below;
			$summary['na'] += $na;

			// Problem services first.
			$order = ['bad' => 0, 'warn' => 1, 'na' => 2, 'ok' => 3];
			usort($service_rows, static function(array $a, array $b) use ($order): int {
				return ($order[$a['state']] ?? 9) <=> ($order[$b['state']] ?? 9)
					?: strcasecmp($a['name'], $b['name']);
			});

			$result[] = [
				'slaid' => $slaid,
				'name' => (string) $sla['name'],
				'slo' => $slo,
				'months' => $months,
				'periods' => $periods,
				'services' => $service_rows,
				'meeting' => $meeting,
				'below' => $below,
				'na' => $na,
				'avg' => $all_values !== [] ? array_sum($all_values) / count($all_values) : null
			];
		}

		usort($result, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

		$summary['slas_total'] = count($result);

		return ['slas' => $result, 'summary' => $summary];
	}

	// ------------------------------------------------------------------- rollups

	private function buildCards(array $report, array $filter): array {
		$fleet = $report['fleet'];
		$sla = $report['sla_summary'];
		$target = (float) $filter['target'];

		$cards = [];

		$cards[] = [
			'key' => 'fleet',
			'label' => _('Fleet availability'),
			'value' => $fleet['avg'] === null ? '—' : $this->formatPct($fleet['avg'], 2),
			'sub' => _s('Average across %1$d hosts with data', (int) $fleet['with_data']),
			'tone' => $fleet['avg'] === null ? 'neutral'
				: ($fleet['avg'] >= $target ? 'ok' : ($fleet['avg'] >= self::BAD_THRESHOLD ? 'warning' : 'critical'))
		];

		$meeting_target = max(0, (int) $fleet['with_data'] - (int) $fleet['below_target']);
		$cards[] = [
			'key' => 'target',
			'label' => _s('Hosts meeting %1$s', $this->formatTargetPct($target)),
			'value' => $fleet['with_data'] > 0
				? $meeting_target.' / '.(int) $fleet['with_data']
				: '—',
			'sub' => (int) $fleet['below_target'] === 0
				? _('Every measured host is on target')
				: ((int) $fleet['below_target'] === 1
					? _('1 host is below target')
					: _s('%1$d hosts are below target', (int) $fleet['below_target'])),
			'tone' => $fleet['with_data'] === 0 ? 'neutral'
				: ((int) $fleet['below_target'] === 0 ? 'ok'
					: ((int) $fleet['below_target'] <= 2 ? 'warning' : 'critical'))
		];

		$cards[] = [
			'key' => 'downtime',
			'label' => _('Total downtime'),
			'value' => $fleet['with_data'] > 0 ? $this->formatDuration((int) $fleet['downtime_seconds']) : '—',
			'sub' => $fleet['worst_host'] === null
				? _('Summed across all measured hosts')
				: _s('Worst: %1$s at %2$s', (string) $fleet['worst_host'], $this->formatPct($fleet['worst_pct'], 2)),
			'tone' => 'neutral'
		];

		if ((int) $sla['services_total'] > 0) {
			$graded = (int) $sla['meeting'] + (int) $sla['below'];
			$cards[] = [
				'key' => 'sla',
				'label' => _('Services meeting their SLO'),
				'value' => $graded > 0 ? (int) $sla['meeting'].' / '.$graded : '—',
				'sub' => $graded === 0
					? _('No measured SLA months in this period')
					: ((int) $sla['below'] === 0
					? _s('Across %1$d SLAs', (int) $sla['slas_total'])
					: ((int) $sla['below'] === 1
						? _('1 service is below SLO')
						: _s('%1$d services are below SLO', (int) $sla['below']))),
				'tone' => $graded === 0 ? 'neutral' : ((int) $sla['below'] === 0 ? 'ok' : 'critical')
			];

			$cards[] = [
				'key' => 'breaches',
				'label' => _('SLO breaches, last 12 months'),
				'value' => (string) (int) $sla['breach_months'],
				'sub' => _('Service-months below the SLO'),
				'tone' => (int) $sla['breach_months'] === 0 ? 'ok' : 'warning'
			];
		}

		if ((int) $fleet['na'] > 0) {
			$cards[] = [
				'key' => 'nodata',
				'label' => _('Hosts without data'),
				'value' => (string) (int) $fleet['na'],
				'sub' => _('No availability item, or no samples in the period'),
				'tone' => 'warning'
			];
		}

		return $cards;
	}

	private function buildAttention(array $report, array $filter): array {
		$items = [];
		$target = (float) $filter['target'];

		// Services below their SLO in the latest measured month.
		foreach ($report['slas'] as $sla) {
			foreach ($sla['services'] as $service) {
				if ($service['state'] === 'bad' || $service['state'] === 'warn') {
					$gap = $sla['slo'] !== null && $service['latest'] !== null
						? $sla['slo'] - $service['latest']
						: null;
					$items[] = [
						'severity' => $service['state'] === 'bad' ? 'critical' : 'warning',
						'scope' => _('SLA'),
						'title' => _s('%1$s is below its SLO', $service['name']),
						'detail' => _s(
							'%1$s against an SLO of %2$s (%3$s short). SLA: %4$s.',
							$this->formatPct($service['latest'], 2),
							$this->formatPct($sla['slo'], 2),
							$gap !== null ? $this->formatPct($gap, 2) : '—',
							$sla['name']
						)
					];
				}

				if ($service['budget'] !== null && $service['budget']['used_pct'] !== null
						&& $service['budget']['used_pct'] >= 80.0 && $service['state'] === 'ok') {
					$items[] = [
						'severity' => 'warning',
						'scope' => _('Error budget'),
						'title' => _s('%1$s has used %2$s of its error budget', $service['name'],
							$this->formatPct($service['budget']['used_pct'], 0)),
						'detail' => _s(
							'%1$s of allowed downtime remains this period.',
							$this->formatDuration(max(0, (int) $service['budget']['remaining_seconds']))
						)
					];
				}
			}
		}

		// Hosts, worst first, from the untruncated health lists.
		foreach ($report['availability_health']['critical'] as $row) {
			$items[] = [
				'severity' => 'critical',
				'scope' => _('Host'),
				'title' => _s('%1$s availability is %2$s', $row['host'], $this->formatPct($row['pct'], 2)),
				'detail' => _s(
					'%1$s of downtime in this period. Group: %2$s.',
					$this->formatDuration((int) $row['downtime_seconds']),
					implode(', ', $row['groups'])
				)
			];
		}

		foreach ($report['availability_health']['warning'] as $row) {
			$items[] = [
				'severity' => 'warning',
				'scope' => _('Host'),
				'title' => _s('%1$s is below the %2$s target', $row['host'], $this->formatTargetPct($target)),
				'detail' => _s(
					'%1$s availability, %2$s of downtime. Group: %3$s.',
					$this->formatPct($row['pct'], 2),
					$this->formatDuration((int) $row['downtime_seconds']),
					implode(', ', $row['groups'])
				)
			];
		}

		// Hosts without data are NOT listed here: they have their own KPI card
		// and appear as "No item"/"No data" rows on the availability tab. A
		// fleet with many unmeasured hosts would otherwise drown the real
		// problems and make the attention badge cry wolf.

		$order = ['critical' => 0, 'warning' => 1, 'info' => 2];
		usort($items, static fn(array $a, array $b): int => ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9));

		return $items;
	}

	// -------------------------------------------------------------------- states

	public function availabilityState(?float $pct, float $target): string {
		if ($pct === null) {
			return 'nodata';
		}
		if ($pct >= $target) {
			return 'ok';
		}
		if ($pct >= self::BAD_THRESHOLD) {
			return 'warn';
		}

		return 'bad';
	}

	/**
	 * SLI cell state against the SLO: ok at/above, warn within half a point
	 * below, bad further below, na unmeasured.
	 */
	public function sliState(?float $pct, ?float $slo): string {
		if ($pct === null) {
			return 'na';
		}

		if ($slo !== null) {
			if ($pct >= $slo) {
				return 'ok';
			}

			return ($slo - $pct) <= 0.5 ? 'warn' : 'bad';
		}

		return $pct >= 99.0 ? 'ok' : ($pct >= self::BAD_THRESHOLD ? 'warn' : 'bad');
	}

	// ---------------------------------------------------------------- formatting

	/**
	 * The availability target with exactly the precision the user set - a
	 * 99.95% target must never round up to "100.0%".
	 */
	public function formatTargetPct(float $target): string {
		$text = rtrim(rtrim(number_format($target, 3, '.', ''), '0'), '.');

		return $text.'%';
	}

	public function formatPct(?float $value, int $decimals = 2): string {
		if ($value === null) {
			return '—';
		}

		return number_format($value, $decimals, '.', ' ').'%';
	}

	public function formatDuration(int $seconds): string {
		$seconds = max(0, $seconds);

		if ($seconds < 60) {
			return $seconds.'s';
		}

		$days = intdiv($seconds, 86400);
		$seconds %= 86400;
		$hours = intdiv($seconds, 3600);
		$seconds %= 3600;
		$minutes = intdiv($seconds, 60);

		$parts = [];
		if ($days > 0) {
			$parts[] = $days.'d';
		}
		if ($hours > 0 || $days > 0) {
			$parts[] = $hours.'h';
		}
		$parts[] = $minutes.'m';

		return implode(' ', $parts);
	}

	public function formatInt($value): string {
		if ($value === null) {
			return '—';
		}

		return number_format((float) $value, 0, '.', ' ');
	}

	public function formatDateTime($timestamp): string {
		if ($timestamp === null || (int) $timestamp <= 0) {
			return '—';
		}

		return gmdate('Y-m-d H:i', (int) $timestamp).' UTC';
	}

	public function shortMonth(string $ym): string {
		if (preg_match('/^(\d{4})-(\d{2})$/', $ym, $matches) === 1) {
			// The 12-period window nearly always crosses a year boundary, so
			// every label carries its year: "Sep '25".
			return gmdate("M 'y", gmmktime(0, 0, 0, (int) $matches[2], 1, (int) $matches[1]));
		}

		return $ym;
	}

	// ----------------------------------------------------------------------- CSV

	public function flattenSlaRows(array $slas): array {
		$rows = [];

		foreach ($slas as $sla) {
			foreach ($sla['services'] as $service) {
				foreach ($sla['months'] as $i => $month) {
					$value = $service['sli'][$i] ?? null;
					$rows[] = [
						$sla['slaid'],
						$sla['name'],
						$service['serviceid'],
						$service['name'],
						$sla['slo'] !== null ? number_format($sla['slo'], 4, '.', '') : '',
						$month,
						$value !== null ? number_format($value, 4, '.', '') : ''
					];
				}
			}
		}

		return $rows;
	}

	public function flattenAvailabilityRows(array $groups, int $time_from, int $time_to): array {
		$rows = [];

		foreach ($groups as $group) {
			foreach (($group['rows_all'] ?? $group['rows']) as $row) {
				$rows[] = [
					$group['name'],
					$row['host'],
					$row['pct'] !== null ? number_format((float) $row['pct'], 4, '.', '') : '',
					$row['state'],
					$row['uptime_seconds'],
					$row['downtime_seconds'],
					$row['item_key'] ?? '',
					gmdate('Y-m-d H:i:s', $time_from),
					gmdate('Y-m-d H:i:s', $time_to)
				];
			}
		}

		return $rows;
	}

	public function flattenDailyRows(array $daily): array {
		$rows = [];

		foreach ($daily['dates'] as $i => $date) {
			$row = [$date];
			$total = 0.0;
			foreach ($daily['series'] as $series) {
				$value = (float) ($series['values'][$i] ?? 0.0);
				$row[] = number_format($value, 2, '.', '');
				$total += $value;
			}
			$row[] = number_format($total, 2, '.', '');
			$rows[] = $row;
		}

		return $rows;
	}

	// ------------------------------------------------------------------ internals

	private static function normalizeIdArray($value): array {
		if (!is_array($value)) {
			return [];
		}

		$result = [];
		foreach ($value as $id) {
			if (!is_scalar($id)) {
				continue;
			}
			$id = trim((string) $id);
			if ($id !== '' && ctype_digit($id)) {
				$result[$id] = $id;
			}
		}

		return array_values($result);
	}

	private static function parseDateBoundary(string $value, string $type): ?int {
		$value = trim($value);

		if ($value === '' || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1) {
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

	/**
	 * Polling interval for an availability item: the configured delay when it
	 * is a positive number, else the median of the observed sample deltas
	 * (collected while streaming), else 60s.
	 */
	private function inferIntervalFromDeltas(array $deltas, ?string $delay): int {
		$parsed_delay = $this->parseDelayToSeconds($delay);

		if ($parsed_delay !== null && $parsed_delay > 0) {
			return $parsed_delay;
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
			switch (strtolower($matches[2])) {
				case 's': return $value;
				case 'm': return $value * 60;
				case 'h': return $value * 3600;
				case 'd': return $value * 86400;
				case 'w': return $value * 604800;
			}
		}

		return null;
	}
}
