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
		$software_update = $this->getSoftwareUpdateStatus($timezone);
		$maintenances = $this->getMaintenanceSummary($now, $today_start, $tomorrow_start, $week_end, $timezone);

		$summary_line = $this->buildSummaryLine($problems, $software_update, $maintenances);
		$banner_items = $this->buildBannerItems($problems, $software_update, $maintenances);
		$chips = $this->buildSummaryChips($problems, $software_update, $maintenances);

		$data = [
			'title' => _('Today\'s Reminder'),
			'generated_at' => $now->getTimestamp(),
			'generated_at_text' => $now->format('Y-m-d H:i'),
			'timezone' => $timezone->getName(),
			'summary_line' => $summary_line,
			'banner_items' => $banner_items,
			'chips' => $chips,
			'problems' => $problems,
			'software_update' => $software_update,
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
			$software_update['available'],
			$software_update['latest_version'],
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

		$triggerids = [];
		foreach ($recent as $problem) {
			if (array_key_exists('objectid', $problem) && $problem['objectid'] !== '') {
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

		$formatted_recent = [];
		foreach ($recent as $problem) {
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

			$formatted_recent[] = [
				'eventid' => (string) ($problem['eventid'] ?? ''),
				'name' => (string) ($problem['name'] ?? ''),
				'severity' => $severity,
				'severity_label' => $this->severityLabel($severity),
				'severity_key' => $this->severityKey($severity),
				'clock' => $clock,
				'clock_text' => $clock > 0 ? $this->formatTimestamp($clock, $timezone) : '',
				'age_text' => $clock > 0 ? $this->formatAge($clock, $now->getTimestamp()) : '',
				'hosts' => $hosts,
				'host_text' => $this->formatNameList($hosts, 2),
				'url' => $this->buildProblemEventUrl((string) ($problem['eventid'] ?? ''))
			];
		}

		return [
			'high_count' => $high_count,
			'critical_count' => $critical_count,
			'total' => $high_count + $critical_count,
			'recent' => $formatted_recent,
			'all_url' => $this->buildUrl('problem.view'),
			'high_url' => $this->buildProblemFilterUrl([self::SEVERITY_HIGH]),
			'critical_url' => $this->buildProblemFilterUrl([self::SEVERITY_CRITICAL])
		];
	}

	private function getSoftwareUpdateStatus(\DateTimeZone $timezone): array {
		$enabled = null;
		$check_data = null;

		try {
			if (class_exists('CSettingsHelper')) {
				if (method_exists('CSettingsHelper', 'isSoftwareUpdateCheckEnabled')) {
					$enabled = (bool) \CSettingsHelper::isSoftwareUpdateCheckEnabled();
				}

				$const_name = 'CSettingsHelper::SOFTWARE_UPDATE_CHECK_DATA';
				if (defined($const_name)) {
					$key = constant($const_name);
					if (method_exists('CSettingsHelper', 'getPublic')) {
						$check_data = @\CSettingsHelper::getPublic($key);
					}
					if ((!is_array($check_data) || !$check_data) && method_exists('CSettingsHelper', 'getPrivate')) {
						$check_data = @\CSettingsHelper::getPrivate($key);
					}
				}
			}
		}
		catch (\Throwable $e) {
			$check_data = null;
		}

		if (is_string($check_data)) {
			$decoded = json_decode($check_data, true);
			if (is_array($decoded)) {
				$check_data = $decoded;
			}
		}

		if (!is_array($check_data)) {
			$check_data = [];
		}

		$current_version = (string) ($check_data['current_version'] ?? (defined('ZABBIX_VERSION') ? ZABBIX_VERSION : ''));
		$latest_release = $check_data['latest_release'] ?? [];
		$latest_version = '';
		$release_notes_url = '';
		$checked_at = 0;
		$end_of_full_support = 0;

		if (is_array($latest_release)) {
			$latest_version = (string) ($latest_release['release'] ?? $latest_release['version'] ?? $latest_release['name'] ?? '');
			$release_notes_url = (string) ($latest_release['release_notes_url'] ?? $latest_release['url'] ?? $check_data['release_notes_url'] ?? '');
			$checked_at = (int) ($latest_release['created'] ?? $check_data['checked_at'] ?? 0);
		}
		elseif (is_string($latest_release)) {
			$latest_version = $latest_release;
		}

		$end_of_full_support = (int) ($check_data['end_of_full_support'] ?? 0);

		$available = null;
		if ($latest_version !== '') {
			if ($current_version !== '') {
				$available = version_compare($this->normalizeVersion($latest_version), $this->normalizeVersion($current_version), '>');
			}
			else {
				$available = true;
			}
		}

		$message = '';
		if ($available === true) {
			$message = sprintf(_('Update available: %1$s (current: %2$s).'), $latest_version, $current_version !== '' ? $current_version : _('unknown'));
		}
		elseif ($available === false && $latest_version !== '') {
			$message = sprintf(_('Frontend release is up to date (%1$s).'), $current_version !== '' ? $current_version : $latest_version);
		}
		elseif ($enabled === false) {
			$message = _('Software update check is disabled.');
		}
		else {
			$message = _('Software update data is unavailable.');
		}

		$support_message = '';
		if ($end_of_full_support > 0) {
			$support_message = sprintf(
				_('End of full support: %1$s.'),
				$this->formatTimestamp($end_of_full_support, $timezone)
			);
		}

		return [
			'enabled' => $enabled,
			'available' => $available,
			'current_version' => $current_version,
			'latest_version' => $latest_version,
			'release_notes_url' => $release_notes_url,
			'checked_at' => $checked_at,
			'checked_at_text' => $checked_at > 0 ? $this->formatTimestamp($checked_at, $timezone) : '',
			'end_of_full_support' => $end_of_full_support,
			'end_of_full_support_text' => $end_of_full_support > 0 ? $this->formatTimestamp($end_of_full_support, $timezone) : '',
			'message' => $message,
			'support_message' => $support_message
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

	private function buildSummaryLine(array $problems, array $software_update, array $maintenances): string {
		$parts = [
			sprintf(_('Critical: %1$d'), (int) $problems['critical_count']),
			sprintf(_('High: %1$d'), (int) $problems['high_count'])
		];

		if ($software_update['available'] === true && $software_update['latest_version'] !== '') {
			$parts[] = sprintf(_('Update: %1$s'), $software_update['latest_version']);
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
			&& $software_update['available'] !== true
			&& (int) $maintenances['today_count'] === 0
			&& (int) $maintenances['week_count'] === 0
		) {
			return _('No High/Critical incidents, no update alert, and no maintenance windows scheduled for today or later this week.');
		}

		return implode(' · ', $parts);
	}

	private function buildBannerItems(array $problems, array $software_update, array $maintenances): array {
		$items = [];

		if ((int) $problems['critical_count'] > 0 || (int) $problems['high_count'] > 0) {
			$items[] = [
				'type' => 'problems',
				'text' => sprintf(
					_('%1$d Critical and %2$d High unresolved problems need attention.'),
					(int) $problems['critical_count'],
					(int) $problems['high_count']
				),
				'url' => $problems['all_url']
			];
		}

		if ($software_update['available'] === true && $software_update['latest_version'] !== '') {
			$items[] = [
				'type' => 'update',
				'text' => $software_update['message'],
				'url' => $software_update['release_notes_url'] !== '' ? $software_update['release_notes_url'] : $this->buildUrl('motd.view')
			];
		}
		elseif ($software_update['support_message'] !== '') {
			$items[] = [
				'type' => 'update',
				'text' => $software_update['support_message'],
				'url' => ''
			];
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

	private function buildSummaryChips(array $problems, array $software_update, array $maintenances): array {
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
			],
			[
				'label' => _('Today'),
				'value' => (string) (int) $maintenances['today_count'],
				'kind' => 'maintenance',
				'url' => $this->buildUrl('motd.view')
			],
			[
				'label' => _('This week'),
				'value' => (string) (int) $maintenances['week_count'],
				'kind' => 'maintenance',
				'url' => $this->buildUrl('motd.view')
			]
		];

		if ($software_update['available'] === true && $software_update['latest_version'] !== '') {
			$chips[] = [
				'label' => _('Update'),
				'value' => $software_update['latest_version'],
				'kind' => 'update',
				'url' => $software_update['release_notes_url'] !== '' ? $software_update['release_notes_url'] : $this->buildUrl('motd.view')
			];
		}

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

	private function normalizeVersion(string $version): string {
		if (preg_match('/\d+(?:\.\d+)+(?:[a-z0-9.-]+)?/i', $version, $matches)) {
			return $matches[0];
		}
		return $version;
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
		return (new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('filter_set', 1)
			->setArgument('filter_severities[]', $severities)
			->getUrl();
	}

	private function buildProblemEventUrl(string $eventid): string {
		if ($eventid === '') {
			return $this->buildUrl('problem.view');
		}

		return (new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('eventid', $eventid)
			->getUrl();
	}
}
