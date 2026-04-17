<?php

declare(strict_types = 0);

namespace Modules\MessageOfTheDay\Helpers;

use API;
use CUrl;

class MotdDataProvider {

	private const SEVERITY_HIGH = 4;
	private const SEVERITY_CRITICAL = 5;

	private const TIMEPERIOD_TYPE_ONETIME = 0;
	private const TIMEPERIOD_TYPE_DAILY = 2;
	private const TIMEPERIOD_TYPE_WEEKLY = 3;
	private const TIMEPERIOD_TYPE_MONTHLY = 4;

	public function getData(): array {
		$timezone = $this->getTimezone();
		$now = new \DateTimeImmutable('now', $timezone);
		$today_start = $now->setTime(0, 0, 0);
		$tomorrow_start = $today_start->modify('+1 day');
		$week_start = $today_start->modify('monday this week');
		$week_end = $week_start->modify('+7 days');

		$problems = $this->getProblemSummary($timezone, $now);
		$unacked = $this->getUnacknowledgedProblems($timezone, $now);
		$longest = $this->getLongestRunningProblems($timezone, $now);
		$resolved = $this->getRecentlyResolvedProblems($timezone, $now);
		$health = $this->getMonitoringHealth();
		$new_hosts = $this->getNewHosts24h($timezone, $now);
		$maintenances = $this->getMaintenanceSummary($now, $today_start, $tomorrow_start, $week_end, $timezone);

		$summary_line = $this->buildSummaryLine($problems, $unacked, $maintenances);
		$banner_items = $this->buildBannerItems($problems, $unacked, $longest, $maintenances);
		$chips = $this->buildSummaryChips($problems, $unacked, $health, $new_hosts, $maintenances);

		$data = [
			'title' => _('Today\'s Reminder'),
			'generated_at' => $now->getTimestamp(),
			'generated_at_text' => $now->format('Y-m-d H:i'),
			'timezone' => $timezone->getName(),
			'summary_line' => $summary_line,
			'banner_items' => $banner_items,
			'chips' => $chips,
			'problems' => $problems,
			'unacked' => $unacked,
			'longest' => $longest,
			'resolved' => $resolved,
			'health' => $health,
			'new_hosts' => $new_hosts,
			'maintenances' => $maintenances,
			'links' => [
				'module' => $this->buildUrl('motd.view'),
				'problems' => $this->buildUrl('problem.view'),
				'maintenance' => $this->buildUrl('maintenance.list')
			]
		];

		$data['fingerprint'] = sha1(json_encode([
			$summary_line,
			$problems['high_count'],
			$problems['critical_count'],
			$unacked['count'],
			$health['stale_items_total'],
			$health['unreachable_hosts'],
			$health['unreachable_proxies'],
			$health['queue_backlog'],
			$new_hosts['count'],
			$resolved['count'],
			array_map(static function(array $item): array {
				return [
					$item['maintenanceid'] ?? '',
					$item['start_ts'] ?? 0,
					$item['end_ts'] ?? 0,
					$item['status'] ?? ''
				];
			}, $maintenances['today']),
			array_map(static function(array $item): array {
				return [
					$item['maintenanceid'] ?? '',
					$item['start_ts'] ?? 0,
					$item['end_ts'] ?? 0,
					$item['status'] ?? ''
				];
			}, $maintenances['week'])
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: 'motd');

		return $data;
	}

	private function getProblemSummary(\DateTimeZone $timezone, \DateTimeImmutable $now): array {
		$high_count = 0;
		$critical_count = 0;
		$suppressed_count = 0;
		$recent = [];

		try {
			$high_count = (int) API::Problem()->get([
				'countOutput' => true,
				'severities' => [self::SEVERITY_HIGH],
				'suppressed' => false
			]);
		}
		catch (\Throwable $e) {
			$high_count = 0;
		}

		try {
			$critical_count = (int) API::Problem()->get([
				'countOutput' => true,
				'severities' => [self::SEVERITY_CRITICAL],
				'suppressed' => false
			]);
		}
		catch (\Throwable $e) {
			$critical_count = 0;
		}

		try {
			$suppressed_count = (int) API::Problem()->get([
				'countOutput' => true,
				'severities' => [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL],
				'suppressed' => true
			]);
		}
		catch (\Throwable $e) {
			$suppressed_count = 0;
		}

		try {
			$recent = API::Problem()->get([
				'output' => ['eventid', 'objectid', 'clock', 'name', 'severity'],
				'severities' => [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL],
				'suppressed' => false,
				'sortfield' => ['eventid'],
				'sortorder' => 'DESC',
				'limit' => 8
			]);
		}
		catch (\Throwable $e) {
			$recent = [];
		}

		$formatted_recent = $this->formatProblemRows($recent, $timezone, $now);

		return [
			'high_count' => $high_count,
			'critical_count' => $critical_count,
			'suppressed_count' => $suppressed_count,
			'total' => $high_count + $critical_count,
			'recent' => $formatted_recent,
			'all_url' => $this->buildUrl('problem.view'),
			'high_url' => $this->buildProblemFilterUrl([self::SEVERITY_HIGH]),
			'critical_url' => $this->buildProblemFilterUrl([self::SEVERITY_CRITICAL]),
			'high_critical_url' => $this->buildProblemFilterUrl([self::SEVERITY_HIGH, self::SEVERITY_CRITICAL]),
			'suppressed_url' => $this->buildProblemSuppressedUrl()
		];
	}

	private function formatProblemRows(array $problems, \DateTimeZone $timezone, \DateTimeImmutable $now): array {
		if (!$problems) {
			return [];
		}

		$triggerids = [];
		foreach ($problems as $problem) {
			if (!empty($problem['objectid'])) {
				$triggerids[] = $problem['objectid'];
			}
		}

		$trigger_map = [];
		if ($triggerids) {
			try {
				$trigger_map = API::Trigger()->get([
					'output' => ['triggerid'],
					'triggerids' => array_values(array_unique($triggerids)),
					'selectHosts' => ['hostid', 'host', 'name'],
					'preservekeys' => true
				]);
			}
			catch (\Throwable $e) {
				$trigger_map = [];
			}
		}

		$formatted = [];
		foreach ($problems as $problem) {
			$severity = (int) ($problem['severity'] ?? 0);
			$clock = (int) ($problem['clock'] ?? 0);
			$triggerid = (string) ($problem['objectid'] ?? '');
			$hosts = [];

			if ($triggerid !== '' && array_key_exists($triggerid, $trigger_map)) {
				foreach ($trigger_map[$triggerid]['hosts'] ?? [] as $host) {
					$hosts[] = (string) ($host['name'] ?? $host['host'] ?? '');
				}
			}

			$hosts = array_values(array_filter(array_unique($hosts), static function(string $value): bool {
				return $value !== '';
			}));

			$formatted[] = [
				'eventid' => (string) ($problem['eventid'] ?? ''),
				'triggerid' => $triggerid,
				'name' => (string) ($problem['name'] ?? ''),
				'severity' => $severity,
				'severity_label' => $this->severityLabel($severity),
				'severity_key' => $this->severityKey($severity),
				'clock' => $clock,
				'clock_text' => $clock > 0 ? $this->formatTimestamp($clock, $timezone) : '',
				'age_text' => $clock > 0 ? $this->formatAge($clock, $now->getTimestamp()) : '',
				'acknowledged' => (int) ($problem['acknowledged'] ?? 0) === 1,
				'hosts' => $hosts,
				'host_text' => $this->formatNameList($hosts, 2),
				'url' => $this->buildProblemTriggerUrl($triggerid)
			];
		}

		return $formatted;
	}

	private function getUnacknowledgedProblems(\DateTimeZone $timezone, \DateTimeImmutable $now): array {
		$count = 0;
		$items = [];

		try {
			$count = (int) API::Problem()->get([
				'countOutput' => true,
				'severities' => [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL],
				'acknowledged' => false,
				'suppressed' => false
			]);
		}
		catch (\Throwable $e) {
			$count = 0;
		}

		try {
			$items = API::Problem()->get([
				'output' => ['eventid', 'objectid', 'clock', 'name', 'severity', 'acknowledged'],
				'severities' => [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL],
				'acknowledged' => false,
				'suppressed' => false,
				'sortfield' => ['eventid'],
				'sortorder' => 'DESC',
				'limit' => 5
			]);
		}
		catch (\Throwable $e) {
			$items = [];
		}

		return [
			'count' => $count,
			'recent' => $this->formatProblemRows($items, $timezone, $now),
			'url' => $this->buildProblemUnackedUrl()
		];
	}

	private function getLongestRunningProblems(\DateTimeZone $timezone, \DateTimeImmutable $now): array {
		$items = [];

		try {
			$items = API::Problem()->get([
				'output' => ['eventid', 'objectid', 'clock', 'name', 'severity', 'acknowledged'],
				'severities' => [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL],
				'suppressed' => false,
				'sortfield' => ['eventid'],
				'sortorder' => 'ASC',
				'limit' => 5
			]);
		}
		catch (\Throwable $e) {
			$items = [];
		}

		return [
			'recent' => $this->formatProblemRows($items, $timezone, $now)
		];
	}

	private function getRecentlyResolvedProblems(\DateTimeZone $timezone, \DateTimeImmutable $now): array {
		$since = $now->getTimestamp() - 86400;
		$problem_events = [];

		try {
			$problem_events = API::Event()->get([
				'output' => ['eventid', 'clock', 'name', 'severity', 'objectid', 'r_eventid'],
				'source' => 0,
				'object' => 0,
				'value' => 1,
				'severities' => [self::SEVERITY_HIGH, self::SEVERITY_CRITICAL],
				'sortfield' => ['eventid'],
				'sortorder' => 'DESC',
				'limit' => 200
			]);
		}
		catch (\Throwable $e) {
			$problem_events = [];
		}

		$recovery_ids = [];
		foreach ($problem_events as $event) {
			$rid = (string) ($event['r_eventid'] ?? '0');
			if ($rid !== '' && $rid !== '0') {
				$recovery_ids[] = $rid;
			}
		}

		$recovery_map = [];
		if ($recovery_ids) {
			try {
				$recoveries = API::Event()->get([
					'output' => ['eventid', 'clock'],
					'eventids' => array_values(array_unique($recovery_ids))
				]);
				foreach ($recoveries as $rec) {
					$recovery_map[(string) $rec['eventid']] = (int) $rec['clock'];
				}
			}
			catch (\Throwable $e) {
				$recovery_map = [];
			}
		}

		$resolved = [];
		$count = 0;
		$mttr_sum = 0;
		$mttr_count = 0;

		foreach ($problem_events as $event) {
			$rid = (string) ($event['r_eventid'] ?? '0');
			if ($rid === '' || $rid === '0' || !isset($recovery_map[$rid])) {
				continue;
			}
			$r_clock = $recovery_map[$rid];
			if ($r_clock < $since) {
				continue;
			}
			$p_clock = (int) ($event['clock'] ?? 0);
			$mttr = max(0, $r_clock - $p_clock);
			$mttr_sum += $mttr;
			$mttr_count++;
			$count++;

			$resolved[] = [
				'problem_event' => $event,
				'resolved_at' => $r_clock,
				'mttr' => $mttr
			];
		}

		usort($resolved, static function(array $a, array $b): int {
			return $b['resolved_at'] <=> $a['resolved_at'];
		});

		$top = array_slice($resolved, 0, 5);
		$top_rows = $this->formatProblemRows(array_column($top, 'problem_event'), $timezone, $now);

		foreach ($top_rows as $idx => &$row) {
			$entry = $top[$idx];
			$row['resolved_at'] = $entry['resolved_at'];
			$row['resolved_at_text'] = $this->formatTimestamp($entry['resolved_at'], $timezone);
			$row['resolved_age_text'] = $this->formatAge($entry['resolved_at'], $now->getTimestamp());
			$row['mttr'] = $entry['mttr'];
			$row['mttr_text'] = $this->formatDuration($entry['mttr']);
		}
		unset($row);

		return [
			'count' => $count,
			'recent' => $top_rows,
			'avg_mttr' => $mttr_count > 0 ? (int) round($mttr_sum / $mttr_count) : 0,
			'avg_mttr_text' => $mttr_count > 0 ? $this->formatDuration((int) round($mttr_sum / $mttr_count)) : ''
		];
	}

	private function getMonitoringHealth(): array {
		$stale_total = 0;
		$stale_hosts = 0;

		try {
			$stale_total = (int) API::Item()->get([
				'countOutput' => true,
				'filter' => ['state' => 1],
				'monitored' => true
			]);
		}
		catch (\Throwable $e) {
			$stale_total = 0;
		}

		if ($stale_total > 0 && $stale_total <= 20000) {
			try {
				$stale_items = API::Item()->get([
					'output' => ['hostid'],
					'filter' => ['state' => 1],
					'monitored' => true,
					'limit' => 20000
				]);
				$host_ids = [];
				foreach ($stale_items as $item) {
					if (!empty($item['hostid'])) {
						$host_ids[(string) $item['hostid']] = true;
					}
				}
				$stale_hosts = count($host_ids);
			}
			catch (\Throwable $e) {
				$stale_hosts = 0;
			}
		}

		$unreachable_hosts = 0;
		try {
			$bad = API::HostInterface()->get([
				'output' => ['hostid'],
				'filter' => ['available' => 2]
			]);
			$hostids = [];
			foreach ($bad as $iface) {
				if (!empty($iface['hostid'])) {
					$hostids[(string) $iface['hostid']] = true;
				}
			}
			$unreachable_hosts = count($hostids);
		}
		catch (\Throwable $e) {
			$unreachable_hosts = 0;
		}

		$unreachable_proxies = 0;
		try {
			$proxies = API::Proxy()->get([
				'output' => ['proxyid', 'state', 'lastaccess']
			]);
			foreach ($proxies as $proxy) {
				if ((int) ($proxy['state'] ?? 0) === 2) {
					$unreachable_proxies++;
				}
			}
		}
		catch (\Throwable $e) {
			$unreachable_proxies = 0;
		}

		$queue_backlog = null;
		try {
			$queue_items = API::Item()->get([
				'output' => ['itemid'],
				'filter' => ['key_' => 'zabbix[queue,10m]'],
				'limit' => 1
			]);
			if ($queue_items) {
				$itemid = $queue_items[0]['itemid'];
				$history = API::History()->get([
					'itemids' => [$itemid],
					'history' => 3,
					'sortfield' => 'clock',
					'sortorder' => 'DESC',
					'limit' => 1
				]);
				if ($history) {
					$queue_backlog = (int) $history[0]['value'];
				}
			}
		}
		catch (\Throwable $e) {
			$queue_backlog = null;
		}

		return [
			'stale_items_total' => $stale_total,
			'stale_items_hosts' => $stale_hosts,
			'unreachable_hosts' => $unreachable_hosts,
			'unreachable_proxies' => $unreachable_proxies,
			'queue_backlog' => $queue_backlog,
			'stale_url' => $this->buildStaleItemsUrl(),
			'unreachable_hosts_url' => $this->buildUnreachableHostsUrl(),
			'proxy_url' => $this->buildUrl('proxy.list'),
			'queue_url' => $this->buildUrl('queue.overview')
		];
	}

	private function getNewHosts24h(\DateTimeZone $timezone, \DateTimeImmutable $now): array {
		$since = $now->getTimestamp() - 86400;
		$entries = [];
		$count = 0;

		$resource_host = 4;
		$action_add = 0;
		if (class_exists('CAudit')) {
			if (defined('\CAudit::RESOURCE_HOST')) {
				$resource_host = constant('\CAudit::RESOURCE_HOST');
			}
			if (defined('\CAudit::ACTION_ADD')) {
				$action_add = constant('\CAudit::ACTION_ADD');
			}
		}

		try {
			$audit = API::AuditLog()->get([
				'output' => ['auditid', 'clock', 'resourceid', 'resourcename'],
				'filter' => [
					'resourcetype' => $resource_host,
					'action' => $action_add
				],
				'time_from' => $since,
				'sortfield' => 'clock',
				'sortorder' => 'DESC',
				'limit' => 20
			]);

			foreach ($audit as $row) {
				$clock = (int) ($row['clock'] ?? 0);
				$entries[] = [
					'name' => (string) ($row['resourcename'] ?? ''),
					'hostid' => (string) ($row['resourceid'] ?? ''),
					'clock' => $clock,
					'clock_text' => $clock > 0 ? $this->formatTimestamp($clock, $timezone) : '',
					'age_text' => $clock > 0 ? $this->formatAge($clock, $now->getTimestamp()) : ''
				];
			}
			$count = count($audit);
		}
		catch (\Throwable $e) {
			$entries = [];
			$count = 0;
		}

		return [
			'count' => $count,
			'recent' => array_slice($entries, 0, 5),
			'url' => $this->buildUrl('host.list')
		];
	}

	private function getMaintenanceSummary(
		\DateTimeImmutable $now,
		\DateTimeImmutable $today_start,
		\DateTimeImmutable $tomorrow_start,
		\DateTimeImmutable $week_end,
		\DateTimeZone $timezone
	): array {
		$maintenances = [];

		try {
			$maintenances = API::Maintenance()->get([
				'output' => ['maintenanceid', 'name', 'description', 'active_since', 'active_till', 'maintenance_type'],
				'selectHostGroups' => ['groupid', 'name'],
				'selectHosts' => ['hostid', 'host', 'name'],
				'selectTimeperiods' => 'extend',
				'sortfield' => ['active_since', 'name'],
				'sortorder' => 'ASC'
			]);
		}
		catch (\Throwable $e) {
			$maintenances = [];
		}

		$today = [];
		$week = [];
		$seen = [];

		foreach ($maintenances as $maintenance) {
			$occurrences = $this->expandMaintenanceOccurrences($maintenance, $today_start, $week_end, $timezone);

			foreach ($occurrences as $occurrence) {
				$key = ($occurrence['maintenanceid'] ?? '').':'.($occurrence['start_ts'] ?? 0).':'.($occurrence['end_ts'] ?? 0);
				if (array_key_exists($key, $seen)) {
					continue;
				}
				$seen[$key] = true;

				if ($occurrence['end_ts'] > $today_start->getTimestamp() && $occurrence['start_ts'] < $tomorrow_start->getTimestamp()) {
					$today[] = $this->decorateOccurrence($occurrence, $now, $timezone);
				}
				elseif ($occurrence['start_ts'] >= $tomorrow_start->getTimestamp() && $occurrence['start_ts'] < $week_end->getTimestamp()) {
					$week[] = $this->decorateOccurrence($occurrence, $now, $timezone);
				}
			}
		}

		usort($today, static function(array $a, array $b): int {
			return ($a['start_ts'] <=> $b['start_ts']) ?: strcmp($a['name'], $b['name']);
		});
		usort($week, static function(array $a, array $b): int {
			return ($a['start_ts'] <=> $b['start_ts']) ?: strcmp($a['name'], $b['name']);
		});

		return [
			'today' => $today,
			'week' => $week,
			'today_count' => count($today),
			'week_count' => count($week),
			'has_any' => (bool) ($today || $week)
		];
	}

	private function expandMaintenanceOccurrences(array $maintenance, \DateTimeImmutable $range_start, \DateTimeImmutable $range_end, \DateTimeZone $timezone): array {
		$occurrences = [];

		$active_since = (int) ($maintenance['active_since'] ?? 0);
		$active_till = (int) ($maintenance['active_till'] ?? 0);
		if ($active_till > 0 && $active_till <= $range_start->getTimestamp()) {
			return [];
		}
		if ($active_since > 0 && $active_since >= $range_end->getTimestamp()) {
			return [];
		}

		$global_start = $active_since > 0
			? (new \DateTimeImmutable('@'.$active_since))->setTimezone($timezone)
			: $range_start;
		$global_end = $active_till > 0
			? (new \DateTimeImmutable('@'.$active_till))->setTimezone($timezone)
			: $range_end;

		$timeperiods = is_array($maintenance['timeperiods'] ?? null) ? $maintenance['timeperiods'] : [];
		$window_start = $range_start;
		$window_end = $range_end;

		foreach ($timeperiods as $timeperiod) {
			$period = max(300, (int) ($timeperiod['period'] ?? 3600));
			$type = (int) ($timeperiod['timeperiod_type'] ?? self::TIMEPERIOD_TYPE_ONETIME);
			$start_time = (int) ($timeperiod['start_time'] ?? 0);
			$every = max(1, (int) ($timeperiod['every'] ?? 1));
			$dayofweek = (int) ($timeperiod['dayofweek'] ?? 0);
			$day = (int) ($timeperiod['day'] ?? 0);
			$month = (int) ($timeperiod['month'] ?? 0);

			switch ($type) {
				case self::TIMEPERIOD_TYPE_ONETIME:
					$start_date = (int) ($timeperiod['start_date'] ?? 0);
					if ($start_date <= 0) {
						break;
					}
					$occurrence_start = (new \DateTimeImmutable('@'.$start_date))->setTimezone($timezone);
					$this->addOccurrenceIfOverlapping($occurrences, $maintenance, $occurrence_start, $period, $window_start, $window_end, $global_start, $global_end, $timezone);
					break;

				case self::TIMEPERIOD_TYPE_DAILY:
					$scan_days = max(1, (int) ceil($period / 86400));
					$scan_start = $window_start->modify('-'.$scan_days.' days');
					$cursor = $scan_start < $global_start ? $global_start : $scan_start;
					$cursor = $cursor->setTime(0, 0, 0);
					$anchor = $global_start->setTime(0, 0, 0);
					while ($cursor < $window_end && $cursor < $global_end) {
						$days = (int) floor(($cursor->getTimestamp() - $anchor->getTimestamp()) / 86400);
						if ($days >= 0 && $days % $every === 0) {
							$occurrence_start = $cursor->modify('+'.$start_time.' seconds');
							$this->addOccurrenceIfOverlapping($occurrences, $maintenance, $occurrence_start, $period, $window_start, $window_end, $global_start, $global_end, $timezone);
						}
						$cursor = $cursor->modify('+1 day');
					}
					break;

				case self::TIMEPERIOD_TYPE_WEEKLY:
					$scan_days = max(1, (int) ceil($period / 86400));
					$scan_start = $window_start->modify('-'.$scan_days.' days');
					$cursor = $scan_start < $global_start ? $global_start : $scan_start;
					$cursor = $cursor->setTime(0, 0, 0);
					$anchor_week = $global_start->setTime(0, 0, 0)->modify('monday this week');
					while ($cursor < $window_end && $cursor < $global_end) {
						$weekday_bit = 1 << ((int) $cursor->format('N') - 1);
						$week_start = $cursor->modify('monday this week');
						$weeks = (int) floor(($week_start->getTimestamp() - $anchor_week->getTimestamp()) / 604800);
						if (($dayofweek & $weekday_bit) !== 0 && $weeks >= 0 && $weeks % $every === 0) {
							$occurrence_start = $cursor->modify('+'.$start_time.' seconds');
							$this->addOccurrenceIfOverlapping($occurrences, $maintenance, $occurrence_start, $period, $window_start, $window_end, $global_start, $global_end, $timezone);
						}
						$cursor = $cursor->modify('+1 day');
					}
					break;

				case self::TIMEPERIOD_TYPE_MONTHLY:
					$scan_days = max(1, (int) ceil($period / 86400));
					$scan_start = $window_start->modify('-'.$scan_days.' days');
					$cursor = $scan_start < $global_start ? $global_start : $scan_start;
					$cursor = $cursor->setTime(0, 0, 0);
					while ($cursor < $window_end && $cursor < $global_end) {
						if (!$this->monthMatches($month, (int) $cursor->format('n'))) {
							$cursor = $cursor->modify('+1 day');
							continue;
						}

						$matches = false;
						if ($dayofweek > 0) {
							$weekday_bit = 1 << ((int) $cursor->format('N') - 1);
							if (($dayofweek & $weekday_bit) !== 0) {
								$week_number = (int) ceil(((int) $cursor->format('j')) / 7);
								$is_last = (int) $cursor->modify('+7 days')->format('n') !== (int) $cursor->format('n');
								$matches = ($every === 5) ? (bool) $is_last : ($week_number === $every);
							}
						}
						else {
							$target_day = $day > 0 ? $day : $every;
							$last_day = (int) $cursor->format('t');
							$target_day = min(max(1, $target_day), $last_day);
							$matches = ((int) $cursor->format('j') === $target_day);
						}

						if ($matches) {
							$occurrence_start = $cursor->modify('+'.$start_time.' seconds');
							$this->addOccurrenceIfOverlapping($occurrences, $maintenance, $occurrence_start, $period, $window_start, $window_end, $global_start, $global_end, $timezone);
						}

						$cursor = $cursor->modify('+1 day');
					}
					break;
			}
		}

		return $occurrences;
	}

	private function addOccurrenceIfOverlapping(
		array &$occurrences,
		array $maintenance,
		\DateTimeImmutable $occurrence_start,
		int $period,
		\DateTimeImmutable $window_start,
		\DateTimeImmutable $window_end,
		\DateTimeImmutable $global_start,
		\DateTimeImmutable $global_end,
		\DateTimeZone $timezone
	): void {
		$occurrence_end = $occurrence_start->modify('+'.$period.' seconds');
		if ($occurrence_end > $global_end) {
			$occurrence_end = $global_end;
		}

		if ($occurrence_end <= $window_start || $occurrence_start >= $window_end) {
			return;
		}
		if ($occurrence_end <= $global_start || $occurrence_start >= $global_end) {
			return;
		}

		$groups = [];
		foreach ($maintenance['hostgroups'] ?? [] as $group) {
			$groups[] = (string) ($group['name'] ?? '');
		}
		$hosts = [];
		foreach ($maintenance['hosts'] ?? [] as $host) {
			$hosts[] = (string) ($host['name'] ?? $host['host'] ?? '');
		}

		$groups = array_values(array_filter(array_unique($groups), static function(string $value): bool {
			return $value !== '';
		}));
		$hosts = array_values(array_filter(array_unique($hosts), static function(string $value): bool {
			return $value !== '';
		}));

		$occurrences[] = [
			'maintenanceid' => (string) ($maintenance['maintenanceid'] ?? ''),
			'name' => (string) ($maintenance['name'] ?? ''),
			'description' => (string) ($maintenance['description'] ?? ''),
			'maintenance_type' => (int) ($maintenance['maintenance_type'] ?? 0),
			'start_ts' => $occurrence_start->getTimestamp(),
			'end_ts' => $occurrence_end->getTimestamp(),
			'start_text' => $this->formatTimestamp($occurrence_start->getTimestamp(), $timezone),
			'end_text' => $this->formatTimestamp($occurrence_end->getTimestamp(), $timezone),
			'day_text' => $occurrence_start->format('D d M'),
			'time_text' => $occurrence_start->format('H:i').'–'.$occurrence_end->format('H:i'),
			'groups' => $groups,
			'hosts' => $hosts,
			'scope_text' => $this->buildScopeText($groups, $hosts)
		];
	}

	private function decorateOccurrence(array $occurrence, \DateTimeImmutable $now, \DateTimeZone $timezone): array {
		$start_ts = (int) ($occurrence['start_ts'] ?? 0);
		$end_ts = (int) ($occurrence['end_ts'] ?? 0);
		$status = 'upcoming';
		$status_label = _('Upcoming');

		if ($start_ts <= $now->getTimestamp() && $end_ts > $now->getTimestamp()) {
			$status = 'ongoing';
			$status_label = _('Ongoing');
		}
		elseif ($end_ts <= $now->getTimestamp()) {
			$status = 'ended';
			$status_label = _('Ended');
		}

		$occurrence['status'] = $status;
		$occurrence['status_label'] = $status_label;
		$occurrence['start_text'] = $start_ts > 0 ? $this->formatTimestamp($start_ts, $timezone) : '';
		$occurrence['end_text'] = $end_ts > 0 ? $this->formatTimestamp($end_ts, $timezone) : '';

		return $occurrence;
	}

	private function buildSummaryLine(array $problems, array $unacked, array $maintenances): string {
		$parts = [
			sprintf(_('Critical: %1$d'), (int) $problems['critical_count']),
			sprintf(_('High: %1$d'), (int) $problems['high_count'])
		];

		if ((int) $unacked['count'] > 0) {
			$parts[] = sprintf(_('Unacked: %1$d'), (int) $unacked['count']);
		}

		if ((int) $problems['suppressed_count'] > 0) {
			$parts[] = sprintf(_('Suppressed: %1$d'), (int) $problems['suppressed_count']);
		}

		if ($maintenances['today_count'] > 0) {
			$parts[] = sprintf(_('%1$d maintenance today'), $maintenances['today_count']);
		}
		if ($maintenances['week_count'] > 0) {
			$parts[] = sprintf(_('%1$d more this week'), $maintenances['week_count']);
		}

		if (
			(int) $problems['critical_count'] === 0
			&& (int) $problems['high_count'] === 0
			&& (int) $unacked['count'] === 0
			&& (int) $problems['suppressed_count'] === 0
			&& (int) $maintenances['today_count'] === 0
			&& (int) $maintenances['week_count'] === 0
		) {
			return _('No High/Critical incidents and no maintenance windows scheduled for today or later this week.');
		}

		return implode(' · ', $parts);
	}

	private function buildBannerItems(array $problems, array $unacked, array $longest, array $maintenances): array {
		$items = [];

		if ((int) $problems['critical_count'] > 0 || (int) $problems['high_count'] > 0) {
			$items[] = [
				'type' => 'problems',
				'text' => sprintf(
					_('%1$d Critical and %2$d High unresolved problems need attention.'),
					(int) $problems['critical_count'],
					(int) $problems['high_count']
				),
				'url' => $problems['high_critical_url']
			];
		}

		if ((int) $unacked['count'] > 0) {
			$items[] = [
				'type' => 'unacked',
				'text' => sprintf(
					_('%1$d High/Critical problem(s) still unacknowledged.'),
					(int) $unacked['count']
				),
				'url' => $unacked['url']
			];
		}

		if (!empty($longest['recent'])) {
			$oldest = $longest['recent'][0];
			if (($oldest['age_text'] ?? '') !== '') {
				$host_prefix = $oldest['host_text'] !== '' ? $oldest['host_text'].' — ' : '';
				$items[] = [
					'type' => 'longest',
					'text' => sprintf(
						_('Oldest open: %1$s%2$s — %3$s'),
						$host_prefix,
						$oldest['name'],
						$oldest['age_text']
					),
					'url' => $oldest['url']
				];
			}
		}

		foreach (array_slice($maintenances['today'], 0, 2) as $occurrence) {
			$items[] = [
				'type' => 'maintenance',
				'text' => sprintf(
					_('%1$s today %2$s — %3$s'),
					$occurrence['name'],
					$occurrence['time_text'],
					$occurrence['scope_text'] !== '' ? $occurrence['scope_text'] : _('No host scope provided')
				),
				'url' => $this->buildUrl('motd.view')
			];
		}

		foreach (array_slice($problems['recent'], 0, 3) as $problem) {
			$host_prefix = $problem['host_text'] !== '' ? $problem['host_text'].' — ' : '';
			$items[] = [
				'type' => 'event',
				'text' => sprintf(
					_('%1$s%2$s (%3$s)'),
					$host_prefix,
					$problem['name'],
					$problem['severity_label']
				),
				'url' => $problem['url']
			];
		}

		if (!$items) {
			$items[] = [
				'type' => 'info',
				'text' => _('All clear: there are no active High/Critical incidents and no maintenance windows later this week.'),
				'url' => ''
			];
		}

		return $items;
	}

	private function buildSummaryChips(array $problems, array $unacked, array $health, array $new_hosts, array $maintenances): array {
		$chips = [
			[
				'label' => _('Critical'),
				'value' => (string) (int) $problems['critical_count'],
				'kind' => 'critical',
				'url' => $problems['critical_url']
			],
			[
				'label' => _('High'),
				'value' => (string) (int) $problems['high_count'],
				'kind' => 'high',
				'url' => $problems['high_url']
			]
		];

		if ((int) $unacked['count'] > 0) {
			$chips[] = [
				'label' => _('Unacked'),
				'value' => (string) (int) $unacked['count'],
				'kind' => 'unacked',
				'url' => $unacked['url']
			];
		}

		if ((int) $problems['suppressed_count'] > 0) {
			$chips[] = [
				'label' => _('Suppressed'),
				'value' => (string) (int) $problems['suppressed_count'],
				'kind' => 'suppressed',
				'url' => $problems['suppressed_url']
			];
		}

		if ((int) $health['stale_items_total'] > 0) {
			$label = (int) $health['stale_items_hosts'] > 0
				? sprintf('%d / %d', (int) $health['stale_items_hosts'], (int) $health['stale_items_total'])
				: (string) (int) $health['stale_items_total'];
			$chips[] = [
				'label' => _('Stale items'),
				'value' => $label,
				'kind' => 'stale',
				'url' => $health['stale_url']
			];
		}

		if ((int) $health['unreachable_hosts'] > 0 || (int) $health['unreachable_proxies'] > 0) {
			$value = (int) $health['unreachable_hosts'];
			if ((int) $health['unreachable_proxies'] > 0) {
				$value = $value.' + '.(int) $health['unreachable_proxies'].'p';
			}
			$chips[] = [
				'label' => _('Unreachable'),
				'value' => (string) $value,
				'kind' => 'unreachable',
				'url' => $health['unreachable_hosts_url']
			];
		}

		if ($health['queue_backlog'] !== null && (int) $health['queue_backlog'] > 0) {
			$chips[] = [
				'label' => _('Queue'),
				'value' => (string) (int) $health['queue_backlog'],
				'kind' => 'queue',
				'url' => $health['queue_url']
			];
		}

		if ((int) $new_hosts['count'] > 0) {
			$chips[] = [
				'label' => _('New hosts 24h'),
				'value' => (string) (int) $new_hosts['count'],
				'kind' => 'activity',
				'url' => $new_hosts['url']
			];
		}

		$chips[] = [
			'label' => _('Today'),
			'value' => (string) (int) $maintenances['today_count'],
			'kind' => 'maintenance',
			'url' => $this->buildUrl('maintenance.list')
		];
		$chips[] = [
			'label' => _('This week'),
			'value' => (string) (int) $maintenances['week_count'],
			'kind' => 'maintenance',
			'url' => $this->buildUrl('maintenance.list')
		];

		return $chips;
	}

	private function buildScopeText(array $groups, array $hosts): string {
		$parts = [];
		if ($groups) {
			$parts[] = _('Groups').': '.$this->formatNameList($groups, 3);
		}
		if ($hosts) {
			$parts[] = _('Hosts').': '.$this->formatNameList($hosts, 3);
		}
		return implode(' · ', $parts);
	}

	private function formatNameList(array $items, int $limit): string {
		$items = array_values(array_filter(array_map(static function($value): string {
			return trim((string) $value);
		}, $items), static function(string $value): bool {
			return $value !== '';
		}));

		if (!$items) {
			return '';
		}

		$display = array_slice($items, 0, $limit);
		$text = implode(', ', $display);
		if (count($items) > $limit) {
			$text .= sprintf(_(' +%1$d more'), count($items) - $limit);
		}
		return $text;
	}

	private function formatTimestamp(int $timestamp, \DateTimeZone $timezone): string {
		return (new \DateTimeImmutable('@'.$timestamp))
			->setTimezone($timezone)
			->format('Y-m-d H:i');
	}

	private function formatAge(int $timestamp, int $now): string {
		$diff = max(0, $now - $timestamp);
		if ($diff < 60) {
			return _('just now');
		}
		if ($diff < 3600) {
			return sprintf(_('%1$dm ago'), (int) floor($diff / 60));
		}
		if ($diff < 86400) {
			return sprintf(_('%1$dh ago'), (int) floor($diff / 3600));
		}
		return sprintf(_('%1$dd ago'), (int) floor($diff / 86400));
	}

	private function severityLabel(int $severity): string {
		switch ($severity) {
			case self::SEVERITY_CRITICAL:
				return _('Critical');
			case self::SEVERITY_HIGH:
				return _('High');
			default:
				return _('Unknown');
		}
	}

	private function severityKey(int $severity): string {
		switch ($severity) {
			case self::SEVERITY_CRITICAL:
				return 'critical';
			case self::SEVERITY_HIGH:
				return 'high';
			default:
				return 'info';
		}
	}

	private function formatDuration(int $seconds): string {
		$seconds = max(0, $seconds);
		if ($seconds < 60) {
			return sprintf(_('%1$ds'), $seconds);
		}
		if ($seconds < 3600) {
			return sprintf(_('%1$dm'), (int) floor($seconds / 60));
		}
		if ($seconds < 86400) {
			$hours = (int) floor($seconds / 3600);
			$minutes = (int) floor(($seconds % 3600) / 60);
			return $minutes > 0
				? sprintf(_('%1$dh %2$dm'), $hours, $minutes)
				: sprintf(_('%1$dh'), $hours);
		}
		$days = (int) floor($seconds / 86400);
		$hours = (int) floor(($seconds % 86400) / 3600);
		return $hours > 0
			? sprintf(_('%1$dd %2$dh'), $days, $hours)
			: sprintf(_('%1$dd'), $days);
	}

	private function monthMatches(int $month_mask, int $month_number): bool {
		if ($month_mask === 0) {
			return true;
		}
		$bit = 1 << ($month_number - 1);
		return ($month_mask & $bit) !== 0;
	}

	private function getTimezone(): \DateTimeZone {
		try {
			return new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
		}
		catch (\Throwable $e) {
			return new \DateTimeZone('UTC');
		}
	}

	private function buildUrl(string $action): string {
		return (new CUrl('zabbix.php'))
			->setArgument('action', $action)
			->getUrl();
	}

	private function buildProblemFilterUrl(array $severities): string {
		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('show', 3);

		foreach ($severities as $severity) {
			$url->setArgument('severities['.(int) $severity.']', (int) $severity);
		}

		return $url->getUrl();
	}

	private function buildProblemSuppressedUrl(): string {
		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('show', 3)
			->setArgument('show_suppressed', 1);

		foreach ([self::SEVERITY_HIGH, self::SEVERITY_CRITICAL] as $severity) {
			$url->setArgument('severities['.$severity.']', $severity);
		}

		return $url->getUrl();
	}

	private function buildProblemUnackedUrl(): string {
		$url = (new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('show', 3)
			->setArgument('acknowledgement_status', 1);

		foreach ([self::SEVERITY_HIGH, self::SEVERITY_CRITICAL] as $severity) {
			$url->setArgument('severities['.$severity.']', $severity);
		}

		return $url->getUrl();
	}

	private function buildStaleItemsUrl(): string {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'latest.view')
			->setArgument('filter_state', 1)
			->setArgument('filter_set', 1)
			->getUrl();
	}

	private function buildUnreachableHostsUrl(): string {
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'host.view')
			->setArgument('filter_set', 1)
			->setArgument('filter_status', 1)
			->getUrl();
	}

	private function buildProblemTriggerUrl(string $triggerid): string {
		if ($triggerid === '') {
			return $this->buildUrl('problem.view');
		}

		return (new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('show', 3)
			->setArgument('triggerids['.$triggerid.']', $triggerid)
			->getUrl();
	}
}
