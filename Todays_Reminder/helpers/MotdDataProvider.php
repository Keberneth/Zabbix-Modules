<?php

declare(strict_types = 0);

namespace Modules\MessageOfTheDay\Helpers;

require_once __DIR__.'/MaintenanceSchedule.php';
require_once __DIR__.'/RequestContext.php';

use API;
use CUrl;

class MotdDataProvider {
	private const CACHE_TTL = 45;
	private const RECOVERY_CACHE_TTL = 300;
	private const CACHE_VERSION = '1.1.2';
	private const EVENT_PAGE_SIZE = 1000;
	private const MAX_EVENT_SCAN = 20000;
	private const MAX_SCOPE_HOSTS = 20000;
	private const MAX_HEALTH_HOSTS = 5000;
	private const MAX_STALE_ITEMS = 2000;
	private const MAX_QUEUE_AGE = 900;

	private $filters = ['groupid' => '0', 'tag' => '', 'tag_value' => ''];
	private $hostids = null;
	private $scope_groups = [];
	private $scope_group_names = [];
	private $scope_failed = false;
	private $issues = [];
	private $success = [];
	private $failures = [];
	private $trigger_cache = [];
	private $maintenance_timezone = null;
	private $deadline;
	private $cache_scope = null;
	private $with_recovery = true;

	/**
	 * A cache is private to one user, installation, filter, language and timezone. The banner never shows the
	 * 24-hour recovery report, so banner requests skip it and use their own cache entry.
	 */
	public function getData(string $cache_scope = 'global', array $filters = [], bool $with_recovery = true): array {
		$this->filters = self::normalizeFilters($filters);
		$this->hostids = null;
		$this->scope_groups = [];
		$this->scope_group_names = [];
		$this->scope_failed = false;
		$this->issues = $this->success = $this->failures = $this->trigger_cache = [];
		$this->cache_scope = $cache_scope;
		$this->with_recovery = $with_recovery;
		$scope = $with_recovery ? $cache_scope : $cache_scope.'|banner';
		$cached = $this->readCache($scope);
		if ($cached !== null) return $cached;
		$data = $this->buildData();
		$this->writeCache($scope, $data);
		return $data;
	}

	public static function normalizeFilters(array $filters): array {
		return RequestContext::filters($filters);
	}

	private function buildData(): array {
		$this->deadline = microtime(true) + 20;
		$timezone = $this->getTimezone();
		$now = new \DateTimeImmutable('now', $timezone);
		$today = $now->setTime(0, 0);
		$this->resolveScope();
		$groups = $this->query('HostGroup', ['output' => ['groupid', 'name'], 'sortfield' => 'name'], 'filters', false);
		$this->expandScopeGroups($groups ?? []);
		// The banner's sections come first. The recovery report scans event history, so it runs last:
		// a slow scan or a spent time budget can then only affect that report.
		$problems = $this->getProblemSummary();
		$unacked = $this->getUnacknowledgedProblems();
		$longest = $this->getLongestRunningProblems();
		$maintenances = $this->getMaintenanceSummary($now, $today, $today->modify('+1 day'),
			$today->modify('monday this week')->modify('+7 days'), $timezone);
		$health = $this->getMonitoringHealth($now);
		$new_hosts = $this->getNewHosts24h($timezone, $now);
		$this->primeTriggerCache([$problems['_recent_raw'], $unacked['_recent_raw'], $longest['_recent_raw']]);
		$resolved = $this->with_recovery ? $this->getRecoveryReport($now) : self::skippedRecoveryReport();
		if (isset($resolved['as_of'])) $resolved['as_of_text'] = $this->formatTimestamp($resolved['as_of'], $timezone);
		$this->primeTriggerCache([$resolved['_top_raw']]);
		foreach (['problems', 'unacked', 'longest'] as $section) {
			${$section}['recent'] = $this->formatProblemRows(${$section}['_recent_raw'], $timezone, $now);
			unset(${$section}['_recent_raw']);
		}
		$resolved['recent'] = $this->formatProblemRows($resolved['_top_raw'], $timezone, $now);
		foreach ($resolved['recent'] as $idx => &$row) {
			$meta = $resolved['_top_meta'][$idx];
			$row['resolved_at'] = $meta['resolved_at'];
			$row['resolved_at_text'] = $this->formatTimestamp($meta['resolved_at'], $timezone);
			$row['resolved_age_text'] = $this->formatAge($meta['resolved_at'], $now->getTimestamp());
			$row['mttr'] = $meta['mttr'];
			$row['mttr_text'] = $this->formatDuration($meta['mttr']);
		}
		unset($row, $resolved['_top_raw'], $resolved['_top_meta']);
		$sections = compact('problems', 'unacked', 'longest', 'resolved', 'health', 'new_hosts', 'maintenances');
		foreach ($sections as $name => &$section) $section += $this->sectionState($name);
		unset($section);
		extract($sections, EXTR_OVERWRITE);
		$has_data = $problems['high_count'] !== null || $problems['critical_count'] !== null
			|| $unacked['count'] !== null || $resolved['count'] !== null || $new_hosts['count'] !== null
			|| $maintenances['today_count'] !== null || (isset($this->success['longest']) && !isset($this->failures['longest']));
		foreach (['stale_items_total', 'unreachable_hosts', 'unreachable_proxies', 'queue_backlog'] as $metric) {
			$has_data = $has_data || $health[$metric] !== null;
		}
		$status = !$this->issues ? 'ok' : (!$has_data ? 'unavailable' : 'partial');
		$data = $sections + [
			'title' => _('Today\'s Reminder'), 'generated_at' => $now->getTimestamp(),
			'generated_at_text' => $now->format('Y-m-d H:i'), 'timezone' => $timezone->getName(),
			'maintenance_timezone' => $this->maintenance_timezone,
			'filters' => $this->filters, 'filter_options' => ['groups' => $groups ?? []],
			'status' => $status, 'issues' => array_values($this->issues),
			'summary_line' => $this->buildSummaryLine($problems, $unacked, $maintenances),
			'banner_items' => $this->buildBannerItems($problems, $unacked, $longest, $maintenances),
			'chips' => $this->buildSummaryChips($problems, $unacked, $health, $new_hosts, $maintenances),
			'links' => ['module' => $this->moduleUrl(), 'problems' => $this->buildProblemFilterUrl([4, 5]),
				'maintenance' => $this->moduleUrl().'#motd-maintenance']
		];
		// Include every displayed field, including incident identity, coverage and relative age.
		$fingerprint_data = $data;
		unset($fingerprint_data['generated_at'], $fingerprint_data['generated_at_text']);
		$data['fingerprint'] = hash('sha256', json_encode($fingerprint_data,
			JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		return $data;
	}

	private function resolveScope(): void {
		if ($this->filters['groupid'] === '0' && $this->filters['tag'] === '') return;
		$options = ['output' => ['hostid'], 'selectHostGroups' => ['groupid', 'name'],
			'limit' => self::MAX_SCOPE_HOSTS + 1, 'sortfield' => 'hostid'];
		if ($this->filters['groupid'] !== '0') $options['groupids'] = [$this->filters['groupid']];
		if ($this->filters['tag'] !== '') {
			$options['inheritedTags'] = true;
			$options['tags'] = [['tag' => $this->filters['tag'], 'value' => $this->filters['tag_value'],
				'operator' => $this->filters['tag_value'] === '' ? 4 : 1]];
		}
		$hosts = $this->query('Host', $options, 'filters', false);
		if ($hosts === null || count($hosts) > self::MAX_SCOPE_HOSTS) {
			$this->scope_failed = true;
			$this->hostids = [];
			$this->issue('filters', $hosts === null ? _('The selected host scope could not be loaded.')
				: _('The selected scope exceeds 20,000 hosts. Choose a smaller group or tag.'));
			return;
		}
		$this->hostids = array_map('strval', array_column($hosts, 'hostid'));
		foreach ($hosts as $host) {
			foreach ($host['hostgroups'] ?? [] as $group) {
				$this->scope_groups[(string) $group['groupid']] = true;
				if (isset($group['name'])) $this->scope_group_names[(string) $group['name']] = true;
			}
		}
	}

	private function expandScopeGroups(array $groups): void {
		if ($this->hostids === null || !$this->scope_groups) return;
		$names = [];
		foreach ($groups as $group) {
			if (isset($this->scope_groups[(string) $group['groupid']])) {
				$names[] = (string) $group['name'];
				$this->scope_group_names[(string) $group['name']] = true;
			}
		}
		// Zabbix maintenance assigned to a parent host group also covers its subgroups.
		foreach ($groups as $group) {
			foreach ($names as $name) {
				$prefix = (string) $group['name'].'/';
				if (strncmp($name, $prefix, strlen($prefix)) === 0) $this->scope_groups[(string) $group['groupid']] = true;
			}
		}
	}

	/**
	 * Failures are data, never a healthy zero. Empty scopes never widen to all hosts. Unbudgeted calls are small
	 * ID lookups that finish rows already fetched, so they run even after the time budget is spent.
	 */
	private function query(string $service, array $options, string $section, bool $scoped = true, bool $budgeted = true) {
		if ($scoped && $this->scope_failed) {
			$this->issue($section, _('Unavailable because the selected host scope could not be loaded.'));
			return null;
		}
		if ($scoped && $this->hostids === []) {
			$this->success[$section] = true;
			return !empty($options['countOutput']) ? 0 : [];
		}
		if ($scoped && $this->hostids !== null) $options['hostids'] = $this->hostids;
		if ($service === 'AuditLog' && (int) (\CWebUser::$data['type'] ?? 0) < 3) {
			$this->issue($section, _('New-host activity requires permission to read the audit log.'));
			return null;
		}
		if ($budgeted && $this->deadline !== null && microtime(true) >= $this->deadline) {
			$this->issue($section, _('The data collection time budget was reached. Refresh to retry.'));
			return null;
		}
		try {
			$result = API::$service()->get($options);
			if (!empty($options['countOutput'])) {
				if (!(is_int($result) && $result >= 0) && !(is_string($result) && preg_match('/^[0-9]+$/D', $result))) {
					throw new \RuntimeException('Unexpected API count response.');
				}
			}
			elseif (!is_array($result)) throw new \RuntimeException('Unexpected API row response.');
			$this->success[$section] = true;
			return !empty($options['countOutput']) ? (int) $result : $result;
		}
		catch (\Throwable $e) {
			$this->issue($section, _('Data could not be loaded or is not permitted for this account.'));
			error_log('Today\'s Reminder '.$section.' '.$service.'.get: '.$e->getMessage());
			return null;
		}
	}

	private function issue(string $section, string $message): void {
		$this->failures[$section] = true;
		$this->issues[$section.'|'.$message] = ['section' => $section, 'message' => $message];
	}

	private function sectionState(string $section): array {
		$messages = [];
		foreach ($this->issues as $issue) if ($issue['section'] === $section) $messages[] = $issue['message'];
		return ['status' => isset($this->failures[$section])
			? (isset($this->success[$section]) ? 'partial' : 'unavailable') : 'ok',
			'message' => implode(' ', $messages)];
	}

	private function getProblemSummary(): array {
		$high = $this->query('Problem', ['countOutput' => true, 'severities' => [4], 'suppressed' => false], 'problems');
		$critical = $this->query('Problem', ['countOutput' => true, 'severities' => [5], 'suppressed' => false], 'problems');
		$suppressed = $this->query('Problem', ['countOutput' => true, 'severities' => [4, 5], 'suppressed' => true], 'problems');
		$recent = $this->query('Problem', $this->problemOptions(8), 'problems');
		return ['high_count' => $high, 'critical_count' => $critical, 'suppressed_count' => $suppressed,
			'total' => $high === null || $critical === null ? null : $high + $critical,
			'_recent_raw' => $recent ?? [], 'all_url' => $this->buildProblemFilterUrl([4, 5]),
			'high_url' => $this->buildProblemFilterUrl([4]), 'critical_url' => $this->buildProblemFilterUrl([5]),
			'high_critical_url' => $this->buildProblemFilterUrl([4, 5]),
			'suppressed_url' => $this->buildProblemFilterUrl([4, 5], ['show_suppressed' => 1])];
	}

	private function problemOptions(int $limit, string $sort = 'DESC'): array {
		return ['output' => ['eventid', 'objectid', 'clock', 'name', 'severity', 'acknowledged'],
			'severities' => [4, 5], 'suppressed' => false, 'sortfield' => ['eventid'],
			'sortorder' => $sort, 'limit' => $limit];
	}

	private function getUnacknowledgedProblems(): array {
		$count = $this->query('Problem', ['countOutput' => true, 'severities' => [4, 5],
			'acknowledged' => false, 'suppressed' => false], 'unacked');
		$rows = $this->query('Problem', $this->problemOptions(5) + ['acknowledged' => false], 'unacked');
		return ['count' => $count, '_recent_raw' => $rows ?? [],
			'url' => $this->buildProblemFilterUrl([4, 5], ['acknowledgement_status' => 1])];
	}

	private function getLongestRunningProblems(): array {
		return ['_recent_raw' => $this->query('Problem', $this->problemOptions(5, 'ASC'), 'longest') ?? []];
	}

	/** The overview reuses a successful recovery report for five minutes; a failed or cut-short scan is retried. */
	private function getRecoveryReport(\DateTimeImmutable $now): array {
		$scope = $this->cache_scope === null ? null : $this->cache_scope.'|recovery';
		$cached = $scope === null ? null
			: $this->readCache($scope, self::RECOVERY_CACHE_TTL, ['report', 'issues', 'generated_at']);
		if ($cached !== null && is_array($cached['report']) && is_array($cached['issues'])) {
			$this->success['resolved'] = true;
			foreach ($cached['issues'] as $message) $this->issue('resolved', (string) $message);
			// The card shows when a reused report was made, since it can be older than the page.
			return ['as_of' => (int) $cached['generated_at']] + $cached['report'];
		}
		$report = $this->getRecentlyResolvedProblems($now);
		$failed = $report['_failed'];
		unset($report['_failed']);
		if ($scope !== null && !$failed) {
			$issues = [];
			foreach ($this->issues as $issue) if ($issue['section'] === 'resolved') $issues[] = $issue['message'];
			$this->writeCache($scope, ['report' => $report, 'issues' => $issues, 'generated_at' => $now->getTimestamp()]);
		}
		return $report;
	}

	private static function skippedRecoveryReport(): array {
		return ['count' => null, 'count_is_lower_bound' => false, 'truncated' => false, 'candidates_scanned' => 0,
			'avg_mttr' => null, 'avg_mttr_text' => '', '_top_raw' => [], '_top_meta' => [],
			'status' => 'skipped', 'message' => ''];
	}

	private function getRecentlyResolvedProblems(\DateTimeImmutable $now): array {
		$since = $now->getTimestamp() - 86400;
		// Overlap filters include old starts whose recovery falls in the last 24 hours.
		// event.get cannot filter r_eventid directly; recoveries are joined by event ID.
		// Zabbix evaluates this filter across the whole event history, so there is no separate count query:
		// a page shorter than the page size ends the scan.
		$base = ['source' => 0, 'object' => 0, 'value' => 1, 'severities' => [4, 5],
			'problem_time_from' => $since, 'problem_time_till' => $now->getTimestamp()];
		$resolved = [];
		$scanned = 0;
		$cursor = null;
		$loaded = $failed = $complete = $missing = false;
		while ($scanned < self::MAX_EVENT_SCAN) {
			// The page that reaches the cap asks for one extra row, to tell "exactly at the cap" from "more".
			$final = $scanned + self::EVENT_PAGE_SIZE >= self::MAX_EVENT_SCAN;
			$options = $base + ['output' => ['eventid', 'clock', 'name', 'severity', 'objectid', 'r_eventid'],
				'sortfield' => 'eventid', 'sortorder' => 'DESC', 'limit' => self::EVENT_PAGE_SIZE + ($final ? 1 : 0)];
			if ($cursor !== null) $options['eventid_till'] = $cursor;
			$events = $this->query('Event', $options, 'resolved');
			if ($events === null) { $failed = true; break; }
			$more = count($events) > self::EVENT_PAGE_SIZE;
			if ($more) $events = array_slice($events, 0, self::EVENT_PAGE_SIZE);
			$loaded = true;
			$scanned += count($events);
			$recovery_ids = [];
			foreach ($events as $event) if (!empty($event['r_eventid'])) $recovery_ids[(string) $event['r_eventid']] = true;
			// Finishing a fetched page is a cheap ID lookup: it is not cut off by the time budget, so a slow
			// history scan that ends just past the budget still produces a report that can be reused.
			$recoveries = $recovery_ids ? $this->query('Event', ['output' => ['eventid', 'clock'],
				'eventids' => array_keys($recovery_ids), 'source' => 0, 'object' => 0, 'value' => 0], 'resolved', true, false) : [];
			if ($recoveries === null) { $failed = true; break; }
			$clocks = array_column($recoveries, 'clock', 'eventid');
			foreach ($events as $event) {
				$rid = (string) ($event['r_eventid'] ?? '0');
				if ($rid === '0' || $rid === '') continue;
				if (!isset($clocks[$rid])) { $missing = true; continue; }
				$recovered = (int) $clocks[$rid];
				if ($recovered >= $since && $recovered <= $now->getTimestamp()) {
					$resolved[(string) $event['eventid']] = ['problem_event' => $event,
						'resolved_at' => $recovered, 'mttr' => max(0, $recovered - (int) $event['clock'])];
				}
			}
			if (count($events) < self::EVENT_PAGE_SIZE || ($final && !$more)) { $complete = true; break; }
			$last = end($events);
			$next = $this->previousId((string) $last['eventid']);
			if ($next === '0') { $complete = true; break; }
			if ($next === $cursor) break;
			$cursor = $next;
		}
		$complete = $complete && !$missing;
		if ($loaded && !$complete) {
			$this->issue('resolved', _('The recovery report is incomplete. The count is a minimum; average MTTR is unavailable.'));
		}
		usort($resolved, static function(array $a, array $b): int { return $b['resolved_at'] <=> $a['resolved_at']; });
		$count = $loaded ? count($resolved) : null;
		$mean = $complete && $count > 0 ? (int) round(array_sum(array_column($resolved, 'mttr')) / $count) : null;
		$top = array_slice($resolved, 0, 5);
		return ['count' => $count, 'count_is_lower_bound' => !$complete && $count !== null,
			'truncated' => !$complete, 'candidates_scanned' => $scanned,
			'avg_mttr' => $mean, 'avg_mttr_text' => $mean === null ? '' : $this->formatDuration($mean),
			'_top_raw' => array_column($top, 'problem_event'), '_top_meta' => $top, '_failed' => $failed];
	}

	private function previousId(string $id): string {
		// Zabbix IDs may exceed PHP_INT_MAX; keep pagination arithmetic decimal-string safe.
		for ($i = strlen($id) - 1; $i >= 0; $i--) {
			if ($id[$i] !== '0') {
				$id[$i] = (string) ((int) $id[$i] - 1);
				return ltrim($id, '0') ?: '0';
			}
			$id[$i] = '9';
		}
		return '0';
	}

	private function getMonitoringHealth(\DateTimeImmutable $now): array {
		$messages = [];
		$total = $this->query('Item', ['countOutput' => true, 'filter' => ['state' => 1], 'monitored' => true], 'health');
		$affected = $total === null ? null : 0;
		$stale_truncated = false;
		if ($total > 0) {
			$items = $this->query('Item', ['output' => ['hostid'], 'filter' => ['state' => 1],
				'monitored' => true, 'sortfield' => 'itemid', 'limit' => self::MAX_STALE_ITEMS], 'health');
			$affected = $items === null ? null : count(array_unique(array_column($items, 'hostid')));
			$stale_truncated = $items !== null && count($items) < $total;
		}
		if ($total === null || $affected === null) $messages['stale_items_total'] = _('Unsupported-item coverage is unavailable.');
		if ($stale_truncated) {
			$messages['stale_items_hosts'] = _('Affected hosts is a minimum from the first 2,000 unsupported items.');
			$this->issue('health', $messages['stale_items_hosts']);
		}
		$hosts = $this->query('Host', ['output' => ['hostid', 'name', 'host', 'active_available'],
			'filter' => ['status' => 0], 'selectInterfaces' => ['available'],
			'sortfield' => 'hostid', 'limit' => self::MAX_HEALTH_HOSTS + 1], 'health');
		$unreachable = null;
		$details = [];
		$host_truncated = $hosts !== null && count($hosts) > self::MAX_HEALTH_HOSTS;
		if ($hosts !== null) {
			$unreachable = 0;
			foreach (array_slice($hosts, 0, self::MAX_HEALTH_HOSTS) as $host) {
				$bad = (int) ($host['active_available'] ?? 0) === 2;
				foreach ($host['interfaces'] ?? [] as $interface) $bad = $bad || (int) ($interface['available'] ?? 0) === 2;
				if ($bad) {
					$unreachable++;
					if (count($details) < 50) {
						$details[] = ['hostid' => (string) $host['hostid'], 'name' => (string) ($host['name'] ?? $host['host']),
							'url' => (new CUrl('zabbix.php'))->setArgument('action', 'latest.view')
								->setArgument('hostids', [(string) $host['hostid']])->setArgument('filter_set', 1)->getUrl()];
					}
				}
			}
		}
		if ($unreachable === null) $messages['unreachable_hosts'] = _('Host availability is unavailable.');
		elseif ($host_truncated) {
			$messages['unreachable_hosts'] = _('Minimum count from the first 5,000 monitored hosts.');
			$this->issue('health', $messages['unreachable_hosts']);
		}
		$proxies = $this->query('Proxy', ['output' => ['proxyid', 'state']], 'health', false);
		$offline = $proxies === null ? null : 0;
		$unknown_proxies = 0;
		foreach ($proxies ?? [] as $proxy) {
			$state = (int) ($proxy['state'] ?? 0);
			$offline += $state === 1 ? 1 : 0;
			$unknown_proxies += $state === 0 ? 1 : 0;
		}
		if ($offline === null) $messages['unreachable_proxies'] = _('Proxy availability is unavailable.');
		elseif ($unknown_proxies) {
			$messages['unreachable_proxies'] = sprintf(_('%1$d proxy states are unknown.'), $unknown_proxies);
			$this->issue('health', $messages['unreachable_proxies']);
		}
		$queue = null;
		$queue_clock = null;
		$queue_items = $this->query('Item', ['output' => ['itemid'], 'selectHosts' => ['monitored_by'],
			'filter' => ['key_' => 'zabbix[queue,10m]'], 'templated' => false, 'monitored' => true,
			'sortfield' => 'itemid', 'limit' => 51], 'health', false);
		$server_item = null;
		foreach ($queue_items ?? [] as $item) {
			if ((int) ($item['hosts'][0]['monitored_by'] ?? -1) === 0) { $server_item = $item['itemid']; break; }
		}
		if ($server_item !== null) {
			$history = $this->query('History', ['output' => ['clock', 'value'], 'itemids' => [$server_item],
				'history' => 3, 'time_from' => $now->getTimestamp() - self::MAX_QUEUE_AGE,
				'time_till' => $now->getTimestamp(), 'sortfield' => 'clock', 'sortorder' => 'DESC', 'limit' => 1], 'health', false);
			if ($history) { $queue = (int) $history[0]['value']; $queue_clock = (int) $history[0]['clock']; }
		}
		if ($queue === null) {
			$messages['queue_backlog'] = _('No permitted Zabbix server queue sample from the last 15 minutes.');
			$this->issue('health', $messages['queue_backlog']);
		}
		return ['stale_items_total' => $total, 'stale_items_hosts' => $affected,
			'stale_items_hosts_truncated' => $stale_truncated, 'unreachable_hosts' => $unreachable,
			'unreachable_hosts_truncated' => $host_truncated, 'unreachable_details' => $details,
			'unreachable_details_truncated' => $host_truncated || $unreachable > count($details),
			'unreachable_proxies' => $offline, 'unknown_proxies' => $unknown_proxies,
			'queue_backlog' => $queue, 'queue_sample_at' => $queue_clock,
			'field_messages' => $messages, 'global_metrics' => true,
			'stale_url' => $this->buildStaleItemsUrl(), 'unreachable_hosts_url' => $this->moduleUrl().'#motd-monitoring-health',
			'proxy_url' => $this->buildUrl('proxy.list'), 'queue_url' => $this->buildUrl('queue.overview')];
	}

	private function getNewHosts24h(\DateTimeZone $timezone, \DateTimeImmutable $now): array {
		if ($this->scope_failed) {
			$this->issue('new_hosts', _('Unavailable because the selected host scope could not be loaded.'));
			return ['count' => null, 'recent' => [], 'url' => $this->moduleUrl().'#motd-monitoring-health'];
		}
		if ($this->hostids === []) {
			$this->success['new_hosts'] = true;
			return ['count' => 0, 'recent' => [], 'url' => $this->moduleUrl().'#motd-monitoring-health'];
		}
		$partial_audit = false;
		try {
			if (class_exists('CSettingsHelper') && defined('CSettingsHelper::AUDITLOG_ENABLED')
					&& (int) \CSettingsHelper::get(\CSettingsHelper::AUDITLOG_ENABLED) !== 1) {
				$this->issue('new_hosts', _('Audit logging is disabled; new-host activity cannot be assessed.'));
				return ['count' => null, 'recent' => [], 'url' => $this->moduleUrl().'#motd-monitoring-health'];
			}
			if (class_exists('CSettingsHelper') && defined('CSettingsHelper::AUDITLOG_MODE')
					&& (int) \CSettingsHelper::get(\CSettingsHelper::AUDITLOG_MODE) !== 1) {
				$partial_audit = true;
				$this->issue('new_hosts', _('System-action audit logging is disabled; discovered or autoregistered hosts may be missing.'));
			}
		}
		catch (\Throwable $e) {
			$partial_audit = true;
			$this->issue('new_hosts', _('Audit logging coverage could not be verified.'));
		}
		$filter = ['resourcetype' => 4, 'action' => 0];
		if ($this->hostids !== null) $filter['resourceid'] = $this->hostids;
		$options = ['filter' => $filter, 'time_from' => $now->getTimestamp() - 86400,
			'time_till' => $now->getTimestamp()];
		$count = $this->query('AuditLog', $options + ['countOutput' => true], 'new_hosts', false);
		$rows = $count > 0 ? $this->query('AuditLog', $options + [
			'output' => ['clock', 'resourceid', 'resourcename'], 'sortfield' => ['clock', 'auditid'],
			'sortorder' => 'DESC', 'limit' => 5], 'new_hosts', false) : [];
		$recent = [];
		foreach ($rows ?? [] as $row) {
			$clock = (int) $row['clock'];
			$recent[] = ['hostid' => (string) $row['resourceid'], 'name' => (string) $row['resourcename'],
				'clock' => $clock, 'clock_text' => $this->formatTimestamp($clock, $timezone),
				'age_text' => $this->formatAge($clock, $now->getTimestamp())];
		}
		return ['count' => $count, 'recent' => $recent, 'url' => $this->moduleUrl().'#motd-monitoring-health',
			'count_is_lower_bound' => $partial_audit && $count !== null,
			'coverage_note' => _('Recorded host additions in the audit log; discovery activity depends on audit settings.')];
	}

	private function getMaintenanceSummary(\DateTimeImmutable $now, \DateTimeImmutable $today,
		\DateTimeImmutable $tomorrow, \DateTimeImmutable $week_end, \DateTimeZone $timezone): array {
		$today_rows = $week_rows = [];
		try {
			$schedule_timezone = $this->getMaintenanceTimezone();
			$this->maintenance_timezone = $schedule_timezone->getName();
		}
		catch (\Throwable $e) {
			$this->issue('maintenances', _('The configured maintenance scheduling timezone is invalid.'));
			return ['today' => [], 'week' => [], 'today_count' => null, 'week_count' => null, 'has_any' => false];
		}
		if ($this->scope_failed) {
			$this->issue('maintenances', _('Unavailable because the selected host scope could not be loaded.'));
			$maintenances = null;
		}
		elseif ($this->hostids === []) { $this->success['maintenances'] = true; $maintenances = []; }
		else {
			$maintenances = $this->query('Maintenance', [
				'output' => ['maintenanceid', 'name', 'description', 'active_since', 'active_till', 'maintenance_type'],
				'selectHostGroups' => ['groupid', 'name'], 'selectHosts' => ['hostid', 'host', 'name'],
				'selectTimeperiods' => 'extend', 'sortfield' => ['active_since', 'name'], 'sortorder' => 'ASC'], 'maintenances', false);
		}
		$seen = [];
		foreach ($maintenances ?? [] as $maintenance) {
			if ($this->hostids !== null) {
				$matches = (bool) array_intersect($this->hostids, array_column($maintenance['hosts'] ?? [], 'hostid'));
				foreach ($maintenance['hostgroups'] ?? [] as $group) {
					$matches = $matches || isset($this->scope_groups[(string) $group['groupid']]);
					foreach ($this->scope_group_names as $name => $_) {
						$prefix = (string) $group['name'].'/';
						$matches = $matches || $name === $group['name'] || strncmp($name, $prefix, strlen($prefix)) === 0;
					}
				}
				if (!$matches) continue;
			}
			try {
				$occurrences = MaintenanceSchedule::expand($maintenance, $today->setTimezone($schedule_timezone),
					$week_end->setTimezone($schedule_timezone), $schedule_timezone);
			}
			catch (\Throwable $e) { $this->issue('maintenances', _('Some maintenance windows could not be calculated.')); continue; }
			foreach ($occurrences as $occurrence) {
				$key = $occurrence['maintenanceid'].':'.$occurrence['start_ts'].':'.$occurrence['end_ts'];
				if (isset($seen[$key])) continue;
				$seen[$key] = true;
				$occurrence = $this->decorateOccurrence($occurrence, $now, $timezone);
				if ($occurrence['end_ts'] > $today->getTimestamp() && $occurrence['start_ts'] < $tomorrow->getTimestamp()) {
					$today_rows[] = $occurrence;
				}
				elseif ($occurrence['start_ts'] >= $tomorrow->getTimestamp() && $occurrence['start_ts'] < $week_end->getTimestamp()) {
					$week_rows[] = $occurrence;
				}
			}
		}
		$sort = static function(array $a, array $b): int { return ($a['start_ts'] <=> $b['start_ts']) ?: strcmp($a['name'], $b['name']); };
		usort($today_rows, $sort);
		usort($week_rows, $sort);
		return ['today' => $today_rows, 'week' => $week_rows,
			'today_count' => $maintenances === null || isset($this->failures['maintenances']) ? null : count($today_rows),
			'week_count' => $maintenances === null || isset($this->failures['maintenances']) ? null : count($week_rows),
			'has_any' => (bool) ($today_rows || $week_rows)];
	}

	private function decorateOccurrence(array $occurrence, \DateTimeImmutable $now, \DateTimeZone $timezone): array {
		$start = (new \DateTimeImmutable('@'.$occurrence['start_ts']))->setTimezone($timezone);
		$end = (new \DateTimeImmutable('@'.$occurrence['end_ts']))->setTimezone($timezone);
		$ongoing = $start <= $now && $end > $now;
		$occurrence['status'] = $ongoing ? 'ongoing' : ($end <= $now ? 'ended' : 'upcoming');
		$occurrence['status_label'] = $ongoing ? _('Ongoing') : ($end <= $now ? _('Ended') : _('Upcoming'));
		$occurrence['start_text'] = $start->format('Y-m-d H:i');
		$occurrence['end_text'] = $end->format('Y-m-d H:i');
		$occurrence['day_text'] = $start->format('D d M');
		$occurrence['time_text'] = $start->format('Y-m-d') === $end->format('Y-m-d')
			? $start->format('H:i').'–'.$end->format('H:i')
			: $occurrence['start_text'].' – '.$occurrence['end_text'];
		$occurrence['remaining_text'] = $ongoing
			? sprintf(_('%1$s remaining'), $this->formatDuration($end->getTimestamp() - $now->getTimestamp())) : '';
		$parts = [];
		if ($occurrence['groups']) $parts[] = _('Groups').': '.$this->formatNameList($occurrence['groups'], 3);
		if ($occurrence['hosts']) $parts[] = _('Hosts').': '.$this->formatNameList($occurrence['hosts'], 3);
		$occurrence['scope_text'] = implode(' · ', $parts);
		return $occurrence;
	}

	private function primeTriggerCache(array $row_sets): void {
		$ids = [];
		foreach ($row_sets as $rows) foreach ($rows as $row) {
			if (!isset($this->trigger_cache[(string) $row['objectid']])) $ids[(string) $row['objectid']] = true;
		}
		if (!$ids) return;
		$triggers = $this->query('Trigger', ['output' => ['triggerid'], 'triggerids' => array_keys($ids),
			'selectHosts' => ['hostid', 'host', 'name'], 'preservekeys' => true], 'details', true, false);
		$this->trigger_cache += $triggers ?? [];
	}

	private function formatProblemRows(array $rows, \DateTimeZone $timezone, \DateTimeImmutable $now): array {
		$result = [];
		foreach ($rows as $row) {
			$severity = (int) ($row['severity'] ?? 0);
			$clock = (int) ($row['clock'] ?? 0);
			$triggerid = (string) ($row['objectid'] ?? '');
			$hosts = [];
			foreach ($this->trigger_cache[$triggerid]['hosts'] ?? [] as $host) $hosts[] = (string) ($host['name'] ?? $host['host'] ?? '');
			$hosts = array_values(array_unique(array_filter($hosts)));
			$result[] = ['eventid' => (string) $row['eventid'], 'triggerid' => $triggerid, 'name' => (string) $row['name'],
				'severity' => $severity, 'severity_label' => $severity === 5 ? _('Critical') : _('High'),
				'severity_key' => $severity === 5 ? 'critical' : 'high', 'clock' => $clock,
				'clock_text' => $clock > 0 ? $this->formatTimestamp($clock, $timezone) : '',
				'age_text' => $clock > 0 ? $this->formatAge($clock, $now->getTimestamp()) : '',
				'acknowledged' => (int) ($row['acknowledged'] ?? 0) === 1, 'hosts' => $hosts,
				'host_text' => $this->formatNameList($hosts, 2),
				'url' => (new CUrl('tr_events.php'))->setArgument('triggerid', $triggerid)
					->setArgument('eventid', (string) $row['eventid'])->getUrl()];
		}
		return $result;
	}
	private function buildSummaryLine(array $problems, array $unacked, array $maintenance): string {
		$parts = [sprintf(_('Critical: %1$s'), $this->countText($problems['critical_count'])),
			sprintf(_('High: %1$s'), $this->countText($problems['high_count'])),
			sprintf(_('Unacked: %1$s'), $this->countText($unacked['count'])),
			sprintf(_('Suppressed: %1$s'), $this->countText($problems['suppressed_count'])),
			sprintf(_('Maintenance today: %1$s'), $this->countText($maintenance['today_count'])),
			sprintf(_('Later this week: %1$s'), $this->countText($maintenance['week_count']))];
		if ($this->issues) $parts[] = _('Some data is unavailable or incomplete');
		return implode(' · ', $parts);
	}

	private function buildBannerItems(array $problems, array $unacked, array $longest, array $maintenance): array {
		$items = [];
		if ($this->issues) {
			$items[] = ['type' => 'warning', 'text' => _('Some data is unavailable or incomplete. Open details for coverage.'), 'url' => $this->moduleUrl()];
		}
		if ($unacked['count'] > 0) {
			$items[] = ['type' => 'unacked', 'text' => sprintf(_('%1$d High/Critical problems still unacknowledged.'), $unacked['count']), 'url' => $unacked['url']];
		}
		if ($longest['recent']) {
			$oldest = $longest['recent'][0];
			$items[] = ['type' => 'longest', 'text' => sprintf(_('Oldest open: %1$s — %2$s'), $oldest['name'], $oldest['age_text']), 'url' => $oldest['url']];
		}
		foreach (array_slice($problems['recent'], 0, 3) as $problem) {
			$items[] = ['type' => 'event', 'text' => ($problem['host_text'] !== '' ? $problem['host_text'].' — ' : '')
				.$problem['name'].' ('.$problem['severity_label'].')', 'url' => $problem['url']];
		}
		foreach (array_slice($maintenance['today'], 0, 2) as $window) {
			$items[] = ['type' => 'maintenance', 'text' => $window['name'].' — '.$window['time_text'], 'url' => $this->moduleUrl().'#motd-maintenance'];
		}
		if (!$items) $items[] = ['type' => 'info', 'text' => _('No visible active High/Critical incidents or maintenance windows today.'), 'url' => ''];
		return $items;
	}

	private function buildSummaryChips(array $problems, array $unacked, array $health, array $new_hosts, array $maintenance): array {
		$chips = [];
		foreach ([[_('Critical'), $problems['critical_count'], 'critical', $problems['critical_url']],
			[_('High'), $problems['high_count'], 'high', $problems['high_url']],
			[_('Unacked'), $unacked['count'], 'unacked', $unacked['url']],
			[_('Suppressed'), $problems['suppressed_count'], 'suppressed', $problems['suppressed_url']]] as $chip) {
			$chips[] = ['label' => $chip[0], 'value' => $this->countText($chip[1]), 'kind' => $chip[2], 'url' => $chip[3]];
		}
		$unsupported = $this->countText($health['stale_items_total']);
		if ($health['stale_items_total'] !== null && $health['stale_items_hosts'] !== null) {
			$unsupported = sprintf(_('%1$d items · %2$s hosts'), $health['stale_items_total'],
				(string) $health['stale_items_hosts'].($health['stale_items_hosts_truncated'] ? '+' : ''));
		}
		$chips[] = ['label' => _('Unsupported'), 'value' => $unsupported, 'kind' => 'stale', 'url' => $health['stale_url']];
		$chips[] = ['label' => _('Unreachable hosts'), 'value' => $this->countText($health['unreachable_hosts'])
			.($health['unreachable_hosts_truncated'] ? '+' : ''), 'kind' => 'unreachable', 'url' => $health['unreachable_hosts_url']];
		foreach ([[_('Queue (global)'), $health['queue_backlog'], 'queue', $health['queue_url']],
			[_('New hosts 24h'), $new_hosts['count'] === null ? null : (string) $new_hosts['count'].(!empty($new_hosts['count_is_lower_bound']) ? '+' : ''), 'activity', $new_hosts['url']],
			[_('Today'), $maintenance['today_count'], 'maintenance', $this->moduleUrl().'#motd-maintenance'],
			[_('This week'), $maintenance['week_count'], 'maintenance', $this->moduleUrl().'#motd-maintenance']] as $chip) {
			$chips[] = ['label' => $chip[0], 'value' => $this->countText($chip[1]), 'kind' => $chip[2], 'url' => $chip[3]];
		}
		return $chips;
	}

	private function countText($value): string { return $value === null ? _('unavailable') : (string) $value; }

	private function formatNameList(array $items, int $limit): string {
		$items = array_values(array_unique(array_filter($items)));
		return implode(', ', array_slice($items, 0, $limit))
			.(count($items) > $limit ? sprintf(_(' +%1$d more'), count($items) - $limit) : '');
	}

	private function formatTimestamp(int $timestamp, \DateTimeZone $timezone): string {
		return (new \DateTimeImmutable('@'.$timestamp))->setTimezone($timezone)->format('Y-m-d H:i');
	}

	private function formatAge(int $timestamp, int $now): string {
		$diff = max(0, $now - $timestamp);
		if ($diff < 60) return _('just now');
		if ($diff < 3600) return sprintf(_('%1$dm ago'), (int) floor($diff / 60));
		if ($diff < 86400) return sprintf(_('%1$dh ago'), (int) floor($diff / 3600));
		return sprintf(_('%1$dd ago'), (int) floor($diff / 86400));
	}

	private function formatDuration(int $seconds): string {
		$seconds = max(0, $seconds);
		if ($seconds < 60) return sprintf(_('%1$ds'), $seconds);
		if ($seconds < 3600) return sprintf(_('%1$dm'), (int) floor($seconds / 60));
		if ($seconds < 86400) return sprintf(_('%1$dh %2$dm'), (int) floor($seconds / 3600), (int) floor(($seconds % 3600) / 60));
		return sprintf(_('%1$dd %2$dh'), (int) floor($seconds / 86400), (int) floor(($seconds % 86400) / 3600));
	}

	private function getTimezone(): \DateTimeZone {
		try { return new \DateTimeZone(date_default_timezone_get() ?: 'UTC'); }
		catch (\Throwable $e) { return new \DateTimeZone('UTC'); }
	}

	private function maintenanceTimezoneName(): string {
		$override = getenv('MOTD_MAINTENANCE_TIMEZONE');
		if ($override !== false && $override !== '') return $override;
		if (class_exists('CSettingsHelper') && defined('CSettingsHelper::DEFAULT_TIMEZONE')) {
			$name = (string) \CSettingsHelper::get(\CSettingsHelper::DEFAULT_TIMEZONE);
			if ($name !== '' && $name !== 'system') return $name;
		}
		return (string) (ini_get('date.timezone') ?: 'UTC');
	}

	private function getMaintenanceTimezone(): \DateTimeZone {
		return new \DateTimeZone($this->maintenanceTimezoneName());
	}

	private function buildUrl(string $action): string {
		return (new CUrl('zabbix.php'))->setArgument('action', $action)->getUrl();
	}

	private function moduleUrl(): string {
		$url = (new CUrl('zabbix.php'))->setArgument('action', 'motd.view');
		foreach ($this->filters as $key => $value) $url->setArgument($key, $value);
		return $url->getUrl();
	}

	private function buildProblemFilterUrl(array $severities, array $extra = []): string {
		if ($this->scope_failed || $this->hostids === [] || ($this->hostids !== null && count($this->hostids) > 100)) return $this->moduleUrl();
		$url = (new CUrl('zabbix.php'))->setArgument('action', 'problem.view')->setArgument('show', 3)
			->setArgument('filter_set', 1)->setArgument('severities', $severities)->setArgument('show_suppressed', 0)
			->setArgument('show_symptoms', 1);
		if ($this->hostids !== null) $url->setArgument('hostids', $this->hostids);
		foreach ($extra as $key => $value) $url->setArgument($key, $value);
		return $url->getUrl();
	}

	private function buildStaleItemsUrl(): string {
		if ($this->scope_failed || $this->hostids === [] || ($this->hostids !== null && count($this->hostids) > 100)) return $this->moduleUrl().'#motd-monitoring-health';
		$url = (new CUrl('zabbix.php'))->setArgument('action', 'latest.view')->setArgument('state', 1)->setArgument('filter_set', 1);
		if ($this->hostids !== null) $url->setArgument('hostids', $this->hostids);
		return $url->getUrl();
	}

	private function installSalt(): string {
		global $DB;
		return __DIR__.'|'.($DB['DATABASE'] ?? '').'@'.($DB['SERVER'] ?? '').':'.($DB['PORT'] ?? '');
	}

	private function cacheFile(string $scope): string {
		$user = class_exists('CWebUser') ? \CWebUser::$data : [];
		try { $schedule_zone = $this->maintenanceTimezoneName(); }
		catch (\Throwable $e) { $schedule_zone = 'unavailable'; }
		$key = [self::CACHE_VERSION, $scope, $this->filters, $this->getTimezone()->getName(), $schedule_zone,
			$user['lang'] ?? '', $user['roleid'] ?? '', $user['ugsetid'] ?? '', $user['type'] ?? ''];
		return sys_get_temp_dir().'/zbx_motd_'.hash('sha256', $this->installSalt()).'/'.hash('sha256', json_encode($key)).'.json';
	}

	private function readCache(string $scope, int $ttl = self::CACHE_TTL,
			array $required = ['fingerprint', 'status', 'generated_at']): ?array {
		$file = $this->cacheFile($scope);
		if (is_link(dirname($file)) || is_link($file) || !is_file($file)) return null;
		if ((@fileperms(dirname($file)) & 0077) !== 0 || (@fileperms($file) & 0077) !== 0) return null;
		if (function_exists('posix_geteuid') && (@fileowner(dirname($file)) !== posix_geteuid() || @fileowner($file) !== posix_geteuid())) return null;
		$mtime = @filemtime($file);
		if ($mtime === false || time() - $mtime > $ttl || $mtime > time()) return null;
		$raw = @file_get_contents($file);
		$decoded = $raw === false ? null : json_decode($raw, true);
		if (!is_array($decoded)) return null;
		foreach ($required as $key) if (!isset($decoded[$key])) return null;
		return $decoded;
	}

	private function writeCache(string $scope, array $data): void {
		$file = $this->cacheFile($scope);
		$dir = dirname($file);
		if (is_link($dir) || (!is_dir($dir) && !@mkdir($dir, 0700, true))) return;
		if (function_exists('posix_geteuid') && @fileowner($dir) !== posix_geteuid()) return;
		if (!@chmod($dir, 0700)) return;
		$json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false) return;
		$tmp = @tempnam($dir, 'write-');
		if ($tmp === false) return;
		if (!@chmod($tmp, 0600) || @file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $file)) @unlink($tmp);
	}
}
