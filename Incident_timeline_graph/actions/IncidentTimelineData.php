<?php

declare(strict_types=1);

namespace Modules\IncidentTimeline\Actions;

use API;
use CController;
use CControllerResponseData;

final class IncidentTimelineInputException extends \RuntimeException {}

final class IncidentTimelineData extends CController {
	// ~25 months. Lets the UI request long, multi-month windows; the server keeps
	// per-request work bounded by bucketing + per-severity counting.
	private const MAX_RANGE_DAYS = 768;
	private const MAX_RANGE_SECONDS = self::MAX_RANGE_DAYS * 86400;
	private const FETCH_BATCH = 2000;
	private const MAX_EVENTS = 200000;
	private const RECOVERY_BATCH = 1000;
	private const MAX_FILTER_HOSTS = 5000;

	// Granularity auto-selection thresholds (in days) and forced-granularity clamps.
	private const AUTO_DAY_MAX_DAYS = 45;
	private const AUTO_WEEK_MAX_DAYS = 186;
	private const FORCE_DAY_MAX_DAYS = 120;
	private const FORCE_WEEK_MAX_DAYS = 840;

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		// Undeclared params are silently dropped by CController; everything the UI
		// can send must be declared here. All optional except the time range.
		$fields = [
			'time_from' => 'required|string',
			'time_to' => 'required|string',
			'bucket' => 'in auto,day,week,month',
			'mode' => 'in aggregate,incidents,resolve,top_triggers',
			'severity' => 'in 0,1,2,3,4,5',
			'severities' => 'string',
			'groupids' => 'string',
			'hostids' => 'string',
			'name' => 'string',
			'name_regex' => 'in 0,1',
			'group' => 'string',
			'host' => 'string',
			'template' => 'string'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		try {
			set_time_limit(300);
			// Bound PCRE work for the user-supplied regex name filter (ReDoS guard);
			// the pattern may be evaluated once per scanned event.
			ini_set('pcre.backtrack_limit', '100000');
			ini_set('pcre.recursion_limit', '10000');

			$mode = (string) $this->getInput('mode', 'aggregate');

			if ($mode === 'resolve') {
				$this->respondJson($this->resolveFilters());
				return;
			}

			$time_from = $this->parseTimestamp((string) $this->getInput('time_from'));
			$time_to = $this->parseTimestamp((string) $this->getInput('time_to'));

			if ($time_from === null || $time_to === null || $time_from > $time_to) {
				$this->respondJsonError(_('Invalid time range.'), 400);
				return;
			}
			if (($time_to - $time_from) > self::MAX_RANGE_SECONDS) {
				$this->respondJsonError(_('The selected time range is too large.'), 400);
				return;
			}

			// Common event filter (host/group/severity/name) shared by every query.
			$filter = $this->buildEventFilter();
			if ($filter['error'] !== null) {
				$this->respondJsonError($filter['error'], 400);
				return;
			}

			if ($mode === 'incidents') {
				$this->handleIncidents($time_from, $time_to, $filter);
			}
			elseif ($mode === 'top_triggers') {
				$this->handleTopTriggers($time_from, $time_to, $filter);
			}
			else {
				$this->handleAggregate($time_from, $time_to, $filter);
			}
		}
		catch (IncidentTimelineInputException $e) {
			$this->respondJsonError($e->getMessage(), 400);
		}
		catch (\Throwable $e) {
			// Log the real cause; return a generic message (avoid leaking internal
			// paths/DB/API detail to the client). User-meaningful validation errors
			// are returned directly via respondJsonError() and never reach here.
			error_log('IncidentTimeline data error: '.$e->getMessage());
			$this->respondJsonError(_('An internal error occurred while loading incident data.'), 500);
		}
	}

	// -------------------------------------------------------------------------
	// Filter resolution (host group / host / template names -> ids)
	// -------------------------------------------------------------------------

	/**
	 * mode=resolve: turn free-text group/host/template filters into id lists so the
	 * (progressive, per-severity) data calls can pass cheap, pre-resolved ids.
	 */
	private function resolveFilters(): array {
		$group = trim((string) $this->getInput('group', ''));
		$host = trim((string) $this->getInput('host', ''));
		$template = trim((string) $this->getInput('template', ''));

		$groupids = [];
		$groups_matched = 0;
		$empty = false;
		$notes = [];

		if ($group !== '') {
			$rows = $this->requireRows(API::HostGroup()->get(['output' => ['groupid'], 'search' => ['name' => $group]]));
			$groupids = array_values(array_map(static fn ($g) => (string) $g['groupid'], $rows));
			$groups_matched = count($groupids);
			$notes[] = sprintf(_('%1$d host group(s)'), $groups_matched);
			if (!$groupids) {
				$empty = true;
			}
		}

		// Resolve a single host-id set. When both host-name and template are given,
		// the constraints are ANDed in ONE Host.get so the cap yields the true
		// (capped) intersection instead of intersecting two independently-capped
		// sets (which could be falsely empty for a template matching > cap hosts).
		$hostids = [];
		$truncated = false;
		if (($host !== '' || $template !== '') && !$empty) {
			$tids = [];
			if ($template !== '') {
				$tpls = $this->requireRows(API::Template()->get([
					'output' => ['templateid'], 'search' => ['host' => $template, 'name' => $template],
					'searchByAny' => true
				]));
				$tids = array_map(static fn ($t) => (string) $t['templateid'], $tpls);
				if (!$tids) {
					$empty = true;
				}
			}

			if (!$empty) {
				$params = ['output' => ['hostid'], 'limit' => self::MAX_FILTER_HOSTS + 1, 'sortfield' => 'hostid'];
				if ($host !== '') {
					$params['search'] = ['host' => $host, 'name' => $host];
					$params['searchByAny'] = true;
				}
				if ($tids) {
					$params['templateids'] = $tids;
				}
				if ($groupids) {
					$params['groupids'] = $groupids;
				}
				$rows = $this->requireRows(API::Host()->get($params));
				$hostids = array_values(array_unique(array_map(static fn ($h) => (string) $h['hostid'], $rows)));
				if (count($hostids) > self::MAX_FILTER_HOSTS) {
					$truncated = true;
					$hostids = array_slice($hostids, 0, self::MAX_FILTER_HOSTS);
				}
				$notes[] = sprintf(_('%1$d host(s)'), count($hostids));
				if ($truncated) {
					$notes[] = _('(host list truncated — narrow the filter)');
				}
				if (!$hostids) {
					$empty = true;
				}
			}
		}

		return [
			'groupids' => array_values($groupids),
			'hostids' => array_values($hostids),
			'empty' => $empty,
			'truncated' => $truncated,
			'summary' => $notes ? implode(' • ', $notes) : ''
		];
	}

	/**
	 * Assemble the shared event.get filter (host/group ids, severity, name search)
	 * from already-resolved request inputs.
	 *
	 * @return array{base: array, severities: ?array, severity_single: ?int, name_regex: ?string, error: ?string}
	 */
	private function buildEventFilter(): array {
		$base = ['source' => 0, 'object' => 0, 'value' => 1];

		$groupids = $this->parseIds((string) $this->getInput('groupids', ''));
		$hostids = $this->parseIds((string) $this->getInput('hostids', ''));
		if ($groupids) {
			$base['groupids'] = $groupids;
		}
		if ($hostids) {
			$base['hostids'] = $hostids;
		}

		// Severity scope: a single `severity` (progressive timeline loading) takes
		// precedence; otherwise an optional `severities` CSV (severity-checkbox
		// filter for top-triggers / incidents). null => all six severities.
		$severity_single = null;
		$severities = null;
		$sev_in = (string) $this->getInput('severity', '');
		if ($sev_in !== '' && ctype_digit($sev_in)) {
			$severity_single = (int) $sev_in;
			$severities = [$severity_single];
		}
		else {
			$list = [];
			foreach (explode(',', (string) $this->getInput('severities', '')) as $s) {
				$s = trim($s);
				if ($s !== '' && (!ctype_digit($s) || (int) $s > 5)) {
					throw new IncidentTimelineInputException(_('Invalid severity filter.'));
				}
				if ($s !== '') {
					$list[(int) $s] = (int) $s;
				}
			}
			if ($list && count($list) < 6) {
				$severities = array_values($list);
			}
		}

		$name = trim((string) $this->getInput('name', ''));
		$name_regex_flag = ((string) $this->getInput('name_regex', '0')) === '1';
		$name_regex = null;

		if ($name !== '') {
			if ($name_regex_flag) {
				if (strlen($name) > 1000) {
					return ['base' => $base, 'severities' => $severities, 'severity_single' => $severity_single,
						'name_regex' => null, 'error' => _('The regular expression filter is too long.')];
				}
				$pattern = '~' . str_replace('~', '\\~', $name) . '~i';
				if (@preg_match($pattern, '') === false) {
					return ['base' => $base, 'severities' => $severities, 'severity_single' => $severity_single,
						'name_regex' => null, 'error' => _('Invalid regular expression in the incident name filter.')];
				}
				$name_regex = $pattern;
			}
			else {
				// Substring match pushed to the DB (LIKE %name%).
				$base['search'] = ['name' => $name];
			}
		}

		return [
			'base' => $base,
			'severities' => $severities,         // explicit list, or null = all six
			'severity_single' => $severity_single, // set only for progressive loading
			'name_regex' => $name_regex,
			'error' => null
		];
	}

	private function parseIds(string $csv): array {
		$out = [];
		foreach (explode(',', $csv) as $part) {
			$part = trim($part);
			if ($part !== '' && (!ctype_digit($part) || trim($part, '0') === '')) {
				throw new IncidentTimelineInputException(_('Invalid host or host group identifier.'));
			}
			if ($part !== '') {
				$out[] = $part;
			}
		}
		return array_values(array_unique($out));
	}

	private function parseTimestamp(string $value): ?int {
		if (!ctype_digit($value)) {
			return null;
		}
		$timestamp = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 253402300799]]);
		return $timestamp === false ? null : $timestamp;
	}

	/** An API failure must never be presented as a successful empty result. */
	private function requireRows($rows): array {
		if (!is_array($rows)) {
			throw new \RuntimeException('Failed to retrieve incident report data from the API.');
		}
		return $rows;
	}

	private function matchesName(?string $pattern, string $name): bool {
		if ($pattern === null) {
			return true;
		}
		$result = @preg_match($pattern, $name);
		if ($result === false) {
			throw new IncidentTimelineInputException(_('The regular expression could not be evaluated within its limits. Simplify the expression or use a substring filter.'));
		}
		return $result === 1;
	}

	// -------------------------------------------------------------------------
	// Aggregate mode (counts per bucket per severity)
	// -------------------------------------------------------------------------

	private function handleAggregate(int $time_from, int $time_to, array $filter): void {
		$bucket_req = (string) $this->getInput('bucket', 'auto');
		[$granularity, $granularity_clamped] = $this->resolveGranularity($time_from, $time_to, $bucket_req);
		$origin = $time_from - ($time_from % 86400);
		$buckets = $this->buildBuckets($time_from, $time_to, $granularity, $origin);

		$severities = $filter['severities'] ?? [0, 1, 2, 3, 4, 5];
		$severity_counts = array_fill(0, 6, 0);
		$limit_reached = false;

		if ($filter['name_regex'] !== null) {
			// Regex name filter cannot be pushed to SQL -> fetch lean rows and match.
			$key_of = $this->makeKeyResolver($granularity, $origin);
			$pattern = $filter['name_regex'];
			$query = $filter['base'];
			$query['severities'] = $severities;
			$query['output'] = ['eventid', 'clock', 'severity', 'name'];

			$limit_reached = $this->paginateEvents($query, $time_from, $time_to, function (string $eid, array $ev)
					use (&$buckets, &$severity_counts, $key_of, $pattern): void {
				if (!$this->matchesName($pattern, (string) ($ev['name'] ?? ''))) {
					return;
				}
				$sev = (int) $ev['severity'];
				if ($sev < 0 || $sev > 5) {
					return;
				}
				$key = $key_of((int) $ev['clock']);
				if (isset($buckets[$key])) {
					$buckets[$key]['sev_'.$sev]++;
				}
				$severity_counts[$sev]++;
			});
		}
		else {
			// COUNT path: no rows transferred. One whole-range count per severity to
			// skip empties, then one indexed COUNT per (bucket, severity).
			foreach ($severities as $sev) {
				$total_sev = $this->countEvents($filter['base'], $sev, $time_from, $time_to);
				$severity_counts[$sev] = $total_sev;
				if ($total_sev <= 0) {
					continue;
				}
				foreach ($buckets as $key => $bucket) {
					// Clamp the bucket to the requested window so the first/last
					// (calendar-aligned) week/month bars do not count events outside
					// [time_from, time_to]; matches makeKeyResolver() binning.
					$b_from = max((int) $bucket['start'], $time_from);
					$b_to = min((int) $bucket['end'], $time_to);
					$c = $this->countEvents($filter['base'], $sev, $b_from, $b_to);
					if ($c > 0) {
						$buckets[$key]['sev_'.$sev] = $c;
					}
				}
			}
		}

		$bucket_list = array_values($buckets);
		$total = array_sum($severity_counts);

		$this->respondJson([
			'buckets' => $bucket_list,
			'severity_summary' => $this->buildSeveritySummary($severity_counts),
			'meta' => [
				'time_from' => $time_from,
				'time_to' => $time_to,
				'generated_at' => time(),
				'granularity' => $granularity,
				'granularity_clamped' => $granularity_clamped,
				'bucket_count' => count($bucket_list),
				'limit' => self::MAX_EVENTS,
				'limit_reached' => $limit_reached,
				'total_incidents' => $total,
				'severity' => $filter['severity_single'],
				'mode' => 'aggregate',
				'count_method' => $filter['name_regex'] !== null ? 'rows' : 'count'
			]
		]);
	}

	private function countEvents(array $base, int $severity, int $from, int $to): int {
		$params = $base;
		$params['severities'] = [$severity];
		$params['time_from'] = $from;
		$params['time_till'] = $to;
		$params['countOutput'] = true;
		$res = API::Event()->get($params);
		if ((!is_int($res) && !(is_string($res) && ctype_digit($res))) || (int) $res < 0) {
			throw new \RuntimeException('Failed to count incidents from the API.');
		}
		return (int) $res;
	}

	// -------------------------------------------------------------------------
	// Incidents mode (per-event detail for drill-down / CSV)
	// -------------------------------------------------------------------------

	private function handleIncidents(int $time_from, int $time_to, array $filter): void {
		$bucket_req = (string) $this->getInput('bucket', 'auto');
		[$granularity, $granularity_clamped] = $this->resolveGranularity($time_from, $time_to, $bucket_req);
		$origin = $time_from - ($time_from % 86400);
		$buckets = $this->buildBuckets($time_from, $time_to, $granularity, $origin);
		$key_of = $this->makeKeyResolver($granularity, $origin);

		$severities = $filter['severities'] ?? [0, 1, 2, 3, 4, 5];
		$severity_counts = array_fill(0, 6, 0);
		$incidents = [];
		$recovery_ids = [];
		$pattern = $filter['name_regex'];

		$query = $filter['base'];
		$query['severities'] = $severities;
		$query['output'] = ['eventid', 'clock', 'severity', 'objectid', 'name', 'r_eventid'];

		$limit_reached = $this->paginateEvents($query, $time_from, $time_to, function (string $eid, array $ev)
				use (&$buckets, &$severity_counts, &$incidents, &$recovery_ids, $key_of, $pattern): void {
			if (!$this->matchesName($pattern, (string) ($ev['name'] ?? ''))) {
				return;
			}
			$sev = (int) $ev['severity'];
			$clock = (int) $ev['clock'];
			if ($sev >= 0 && $sev <= 5) {
				$key = $key_of($clock);
				if (isset($buckets[$key])) {
					$buckets[$key]['sev_'.$sev]++;
				}
				$severity_counts[$sev]++;
			}

			$r_eventid = (string) ($ev['r_eventid'] ?? '0');
			if ($r_eventid !== '0' && $r_eventid !== '') {
				$recovery_ids[$r_eventid] = true;
			}
			$incidents[] = [
				'eid' => $eid,
				'oid' => (string) ($ev['objectid'] ?? '0'),
				'n' => (string) ($ev['name'] ?? ''),
				's' => $sev,
				'c' => $clock,
				'r' => $r_eventid
			];
		});

		// Recovery clocks (can land after time_to, so no time filter).
		$recovery_clock_map = [];
		if ($recovery_ids) {
			foreach (array_chunk(array_keys($recovery_ids), self::RECOVERY_BATCH) as $chunk) {
				$rec = $this->requireRows(API::Event()->get(['output' => ['eventid', 'clock'], 'eventids' => $chunk, 'preservekeys' => true]));
				foreach ($rec as $re) {
					$recovery_clock_map[(string) $re['eventid']] = (int) $re['clock'];
				}
			}
		}
		$recovery_time_unavailable = 0;
		foreach ($incidents as &$inc) {
			$r = $inc['r'];
			if ($r === '0' || $r === '') {
				$inc['rc'] = 0;
				$inc['status'] = 'ongoing';
			}
			elseif (isset($recovery_clock_map[$r]) && $recovery_clock_map[$r] >= $inc['c']) {
				$inc['rc'] = $recovery_clock_map[$r];
				$inc['status'] = 'resolved';
			}
			else {
				$inc['rc'] = null;
				$inc['status'] = 'resolved_time_unavailable';
				$recovery_time_unavailable++;
			}
		}
		unset($inc);

		$bucket_list = array_values($buckets);

		$this->respondJson([
			'buckets' => $bucket_list,
			'severity_summary' => $this->buildSeveritySummary($severity_counts),
			'incidents' => $incidents,
			'meta' => [
				'time_from' => $time_from,
				'time_to' => $time_to,
				'generated_at' => time(),
				'granularity' => $granularity,
				'granularity_clamped' => $granularity_clamped,
				'bucket_count' => count($bucket_list),
				'limit' => self::MAX_EVENTS,
				'limit_reached' => $limit_reached,
				'total_incidents' => count($incidents),
				'recovery_time_unavailable' => $recovery_time_unavailable,
				'severity' => $filter['severity_single'],
				'mode' => 'incidents',
				'count_method' => 'rows'
			]
		]);
	}

	// -------------------------------------------------------------------------
	// Top triggers mode (rank triggers by problem count; dynamic with filters)
	// -------------------------------------------------------------------------

	private function handleTopTriggers(int $time_from, int $time_to, array $filter): void {
		$has_filter = (
			!empty($filter['base']['groupids']) || !empty($filter['base']['hostids'])
			|| !empty($filter['base']['search']) || $filter['name_regex'] !== null
			|| $filter['severities'] !== null
		);

		// Aggregate per trigger (objectid) during a single lean scan.
		$agg = [];                 // Per-trigger counts and known duration samples.
		$pending = [];             // r_eventid => list of [objectid, problem_clock] (for MTTR)
		$pattern = $filter['name_regex'];

		$query = $filter['base'];
		$query['severities'] = $filter['severities'] ?? [0, 1, 2, 3, 4, 5];
		$query['output'] = ['eventid', 'clock', 'severity', 'objectid', 'r_eventid'];
		if ($pattern !== null) {
			$query['output'][] = 'name';
		}

		$limit_reached = $this->paginateEvents($query, $time_from, $time_to, function (string $eid, array $ev)
				use (&$agg, &$pending, $pattern): void {
			if (!$this->matchesName($pattern, (string) ($ev['name'] ?? ''))) {
				return;
			}
			$oid = (string) ($ev['objectid'] ?? '0');
			$clock = (int) $ev['clock'];
			$sev = (int) $ev['severity'];

			if (!isset($agg[$oid])) {
				$agg[$oid] = ['count' => 0, 'first' => $clock, 'last' => $clock, 'sev' => $sev,
					'dur_sum' => 0, 'resolved' => 0, 'duration_sample_count' => 0];
			}
			$a = &$agg[$oid];
			$a['count']++;
			if ($clock < $a['first']) {
				$a['first'] = $clock;
			}
			if ($clock > $a['last']) {
				$a['last'] = $clock;
			}
			if ($sev > $a['sev']) {
				$a['sev'] = $sev;
			}
			unset($a);

			$r = (string) ($ev['r_eventid'] ?? '0');
			if ($r !== '0' && $r !== '') {
				$agg[$oid]['resolved']++;
				// A single recovery event can close several problems (correlation),
				// so collect every problem keyed by its recovery id.
				$pending[$r][] = [$oid, $clock];
			}
		});

		// MTTR: resolve recovery clocks and fold durations back per trigger.
		if ($pending) {
			foreach (array_chunk(array_keys($pending), self::RECOVERY_BATCH) as $chunk) {
				$rec = $this->requireRows(API::Event()->get(['output' => ['eventid', 'clock'], 'eventids' => $chunk, 'preservekeys' => true]));
				foreach ($rec as $reid => $re) {
					if (!isset($pending[(string) $reid])) {
						continue;
					}
					foreach ($pending[(string) $reid] as [$oid, $pclock]) {
						$dur = (int) $re['clock'] - $pclock;
						if ($dur >= 0 && isset($agg[$oid])) {
							$agg[$oid]['dur_sum'] += $dur;
							$agg[$oid]['duration_sample_count']++;
						}
					}
				}
			}
		}
		unset($pending);

		$trigger_count = count($agg);
		$total = 0;
		$recovery_time_unavailable = 0;
		foreach ($agg as $a) {
			$total += $a['count'];
			$recovery_time_unavailable += $a['resolved'] - $a['duration_sample_count'];
		}

		// Rank by problem count: up to 1000 when filtered; otherwise top 100.
		uasort($agg, static fn ($x, $y) => $y['count'] <=> $x['count']);
		$limit_applied = $has_filter ? 1000 : 100;
		$top = array_slice($agg, 0, $limit_applied, true);
		unset($agg);

		// Resolve trigger description + host for the shown triggers.
		$oids = array_keys($top);
		$trig_map = [];
		foreach (array_chunk($oids, 1000) as $chunk) {
			$trigs = $this->requireRows(API::Trigger()->get([
				'output' => ['triggerid', 'description'],
				'triggerids' => $chunk,
				'selectHosts' => ['name'],
				// Show "High CPU utilization on db01", not "... on {HOST.NAME}".
				'expandDescription' => true,
				'preservekeys' => true
			]));
			foreach ($trigs as $tid => $tr) {
				$trig_map[(string) $tid] = $tr;
			}
		}

		$rows = [];
		$rank = 0;
		foreach ($top as $oid => $a) {
			$rank++;
			$trig = $trig_map[$oid] ?? null;
			$name = ($trig && isset($trig['description']) && $trig['description'] !== '')
				? (string) $trig['description']
				: sprintf(_('Trigger %1$s'), $oid);
			$host = ($trig && !empty($trig['hosts'])) ? (string) $trig['hosts'][0]['name'] : '';

			$rows[] = [
				'rank' => $rank,
				'triggerid' => $oid,
				'name' => $name,
				'host' => $host,
				'severity' => $a['sev'],
				'count' => $a['count'],
				'first' => $a['first'],
				'last' => $a['last'],
				'resolved' => $a['resolved'],
				'duration_sample_count' => $a['duration_sample_count'],
				'recovery_time_unavailable' => $a['resolved'] - $a['duration_sample_count'],
				'mttr' => $a['duration_sample_count'] > 0 ? (int) round($a['dur_sum'] / $a['duration_sample_count']) : null,
				'share' => $total > 0 ? round($a['count'] / $total, 4) : 0
			];
		}

		$this->respondJson([
			'top_triggers' => $rows,
			'meta' => [
				'time_from' => $time_from,
				'time_to' => $time_to,
				'generated_at' => time(),
				'total_incidents' => $total,
				'recovery_time_unavailable' => $recovery_time_unavailable,
				'trigger_count' => $trigger_count,
				'shown' => count($rows),
				'limit_applied' => $limit_applied,
				'filtered' => $has_filter,
				'limit' => self::MAX_EVENTS,
				'limit_reached' => $limit_reached,
				'mode' => 'top_triggers'
			]
		]);
	}

	// -------------------------------------------------------------------------
	// Shared pagination over events (keyset on clock+eventid with overflow drain)
	// -------------------------------------------------------------------------

	/**
	 * Page through matching events, invoking $on_row($eventid, $event) for each.
	 * Returns whether MAX_EVENTS was hit. $query must already contain output +
	 * filters; this adds time window, sort, limit and preservekeys.
	 */
	private function paginateEvents(array $query, int $time_from, int $time_to, callable $on_row): bool {
		$total = 0;
		$limit_reached = false;
		$cursor_from = $time_from;
		$boundary_seen = [];

		$query['sortfield'] = ['clock', 'eventid'];
		$query['sortorder'] = 'ASC';
		$query['preservekeys'] = true;

		while ($total < self::MAX_EVENTS && $cursor_from <= $time_to) {
			$page = $query;
			$page['time_from'] = $cursor_from;
			$page['time_till'] = $time_to;
			$page['limit'] = self::FETCH_BATCH;

			$batch = $this->requireRows(API::Event()->get($page));
			if ($batch === []) {
				break;
			}

			$first = reset($batch);
			$last = end($batch);
			$min_clock = (int) $first['clock'];
			$max_clock = (int) $last['clock'];
			$count = count($batch);

			// Overflow: one second holds more than a full batch -> drain it explicitly.
			if ($count === self::FETCH_BATCH && $min_clock === $max_clock) {
				$drain = $query;
				$drain['time_from'] = $min_clock;
				$drain['time_till'] = $min_clock;
				$drain['limit'] = self::MAX_EVENTS;
				$drained = $this->requireRows(API::Event()->get($drain));
				foreach ($drained as $eid => $ev) {
					if (isset($boundary_seen[$eid])) {
						continue;
					}
					$on_row((string) $eid, $ev);
					$total++;
					if ($total >= self::MAX_EVENTS) {
						$limit_reached = true;
						break;
					}
				}
				if (count($drained) >= self::MAX_EVENTS) {
					$limit_reached = true;
				}
				$cursor_from = $min_clock + 1;
				$boundary_seen = [];
				if ($limit_reached) {
					break;
				}
				continue;
			}

			foreach ($batch as $eid => $ev) {
				if (isset($boundary_seen[$eid])) {
					continue;
				}
				$on_row((string) $eid, $ev);
				$total++;
				if ($total >= self::MAX_EVENTS) {
					$limit_reached = true;
					break 2;
				}
			}

			if ($count < self::FETCH_BATCH) {
				break;
			}

			$cursor_from = $max_clock;
			$boundary_seen = [];
			foreach ($batch as $eid => $ev) {
				if ((int) $ev['clock'] === $max_clock) {
					$boundary_seen[$eid] = true;
				}
			}
			unset($batch);
		}

		return $limit_reached;
	}

	private function buildSeveritySummary(array $severity_counts): array {
		$severity_map = [
			TRIGGER_SEVERITY_NOT_CLASSIFIED => _('Not classified'),
			TRIGGER_SEVERITY_INFORMATION => _('Information'),
			TRIGGER_SEVERITY_WARNING => _('Warning'),
			TRIGGER_SEVERITY_AVERAGE => _('Average'),
			TRIGGER_SEVERITY_HIGH => _('High'),
			TRIGGER_SEVERITY_DISASTER => _('Disaster')
		];

		$summary = [];
		foreach ($severity_map as $severity => $label) {
			$summary[] = [
				'severity' => $severity,
				'label' => $label,
				'count' => $severity_counts[$severity] ?? 0
			];
		}
		return $summary;
	}

	// -------------------------------------------------------------------------
	// Bucketing (pure UTC integer math; round-trips with makeKeyResolver)
	// -------------------------------------------------------------------------

	private function resolveGranularity(int $time_from, int $time_to, string $bucket_req): array {
		$days = intdiv($time_to - $time_from, 86400);

		if ($bucket_req === 'auto' || $bucket_req === '') {
			if ($days <= self::AUTO_DAY_MAX_DAYS) {
				return ['day', false];
			}
			if ($days <= self::AUTO_WEEK_MAX_DAYS) {
				return ['week', false];
			}
			return ['month', false];
		}
		if ($bucket_req === 'day' && $days > self::FORCE_DAY_MAX_DAYS) {
			return ['week', true];
		}
		if ($bucket_req === 'week' && $days > self::FORCE_WEEK_MAX_DAYS) {
			return ['month', true];
		}
		return [$bucket_req, false];
	}

	private function buildBuckets(int $time_from, int $time_to, string $granularity, int $origin): array {
		$buckets = [];

		if ($granularity === 'day') {
			for ($cursor = $origin; $cursor <= $time_to; $cursor += 86400) {
				$buckets[gmdate('Y-m-d', $cursor)] = $this->emptyBucket(
					gmdate('Y-m-d', $cursor), gmdate('M j', $cursor), $cursor, $cursor + 86399
				);
			}
		}
		elseif ($granularity === 'week') {
			$last_floor = $time_to - ($time_to % 86400);
			$n = intdiv($last_floor - $origin, 604800) + 1;
			for ($i = 0; $i < $n; $i++) {
				$start = $origin + $i * 604800;
				$end = $start + 604799;
				$label = gmdate('M j', $start).'–'.gmdate('M j', $start + 518400);
				$buckets[gmdate('Y-m-d', $start)] = $this->emptyBucket(gmdate('Y-m-d', $start), $label, $start, $end);
			}
		}
		else { // month
			$y = (int) gmdate('Y', $time_from);
			$m = (int) gmdate('n', $time_from);
			while (true) {
				$start = gmmktime(0, 0, 0, $m, 1, $y);
				if ($start > $time_to) {
					break;
				}
				$next_start = gmmktime(0, 0, 0, $m + 1, 1, $y);
				$buckets[gmdate('Y-m', $start)] = $this->emptyBucket(
					gmdate('Y-m', $start), gmdate('M Y', $start), $start, $next_start - 1
				);
				$m++;
				if ($m > 12) {
					$m = 1;
					$y++;
				}
			}
		}

		return $buckets;
	}

	private function emptyBucket(string $key, string $label, int $start, int $end): array {
		return [
			'key' => $key, 'label' => $label, 'start' => $start, 'end' => $end,
			'sev_0' => 0, 'sev_1' => 0, 'sev_2' => 0, 'sev_3' => 0, 'sev_4' => 0, 'sev_5' => 0
		];
	}

	private function makeKeyResolver(string $granularity, int $origin): callable {
		if ($granularity === 'day') {
			return static fn (int $clock): string => gmdate('Y-m-d', $clock);
		}
		if ($granularity === 'week') {
			return static function (int $clock) use ($origin): string {
				$i = intdiv($clock - $origin, 604800);
				return gmdate('Y-m-d', $origin + $i * 604800);
			};
		}
		return static fn (int $clock): string => gmdate('Y-m', $clock);
	}

	// -------------------------------------------------------------------------

	private function respondJson(array $payload): void {
		$json = json_encode(
			$payload,
			JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ($json === false) {
			$json = '{"error":{"code":500,"message":"Failed to encode JSON response."}}';
		}
		$this->setResponse(new CControllerResponseData(['main_block' => $json]));
	}

	private function respondJsonError(string $message, int $code): void {
		$this->respondJson(['error' => ['code' => $code, 'message' => $message]]);
	}
}
