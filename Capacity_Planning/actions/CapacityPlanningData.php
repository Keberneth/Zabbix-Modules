<?php

declare(strict_types=1);

namespace Modules\CapacityPlanning\Actions;

use API;
use CController;
use CControllerResponseData;

/**
 * JSON data backend for the Capacity Planning & Prediction report.
 *
 * Modes:
 *   resolve   — free-text group/host/template filters -> id lists (cheap, cached client-side)
 *   inventory — discover filesystem / CPU / memory items, resolve effective Zabbix
 *               threshold macros (host -> template chain -> global, with contexts)
 *               and return current-state findings without any time series.
 *   forecast  — for a small batch of findings, fetch hourly trends (bounded raw-history
 *               fallback), compute robust Theil-Sen growth, nested window statistics,
 *               threshold ETAs, risk severity and a chart-ready daily series.
 *
 * Everything is read-only. All statistics are computed in UTC integer math.
 */
final class CapacityPlanningData extends CController {
	private const MAX_FILTER_HOSTS = 5000;
	private const MAX_HOSTS = 1000;
	private const MAX_ITEMS = 30000;
	private const MAX_FINDINGS = 3000;
	private const MAX_QUALITY_ISSUES = 400;
	private const HOST_CHUNK = 200;
	private const MACRO_ENTITY_CHUNK = 500;

	private const MAX_LOOKBACK_DAYS = 730;
	private const FORECAST_BATCH_MAX = 10;
	private const MAX_TREND_ROWS = 20000;
	private const MAX_HISTORY_ROWS = 50000;
	private const HISTORY_FETCH_BATCH = 10000;
	private const HISTORY_FALLBACK_DAYS = 7;
	private const SERIES_MAX_POINTS = 420;

	private const STALE_DISK_SECONDS = 24 * 3600;
	private const STALE_RESOURCE_SECONDS = 4 * 3600;
	private const MIN_DISK_GROWTH_BYTES_DAY = 1048576.0; // 1 MiB/day noise floor

	private const DISK_WARN_DEFAULT = 90.0;
	private const DISK_CRIT_DEFAULT = 95.0;
	private const REMOTE_DISK_WARN_DEFAULT = 85.0;
	private const REMOTE_DISK_CRIT_DEFAULT = 90.0;
	private const CPU_WARN_DEFAULT = 90.0;
	private const CPU_CRIT_DEFAULT = 99.0;
	private const MEMORY_WARN_DEFAULT = 90.0;
	private const MEMORY_CRIT_DEFAULT = 95.0;

	// Nested analysis windows: key => days. Order matters (longest first).
	private const WINDOWS = ['12m' => 365, '6m' => 183, '3m' => 92, '1m' => 31, '1w' => 7];

	private const SEVERITY_ORDER = [
		'Unknown' => -1, 'Healthy' => 0, 'Watch' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4
	];

	/** @var array<int, array{sev: string, host: string, resource: string, issue: string, detail: string}> */
	private array $quality = [];

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		// Undeclared params are silently dropped by CController; everything the UI
		// can send must be declared here.
		$fields = [
			'mode' => 'in resolve,inventory,forecast',
			'group' => 'string',
			'host' => 'string',
			'template' => 'string',
			'groupids' => 'string',
			'hostids' => 'string',
			'time_from' => 'string',
			'time_to' => 'string',
			'specs' => 'string'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		try {
			set_time_limit(300);
			// Bound PCRE work: regex-context macro values are user-controlled patterns
			// that are evaluated against filesystem names (ReDoS guard).
			ini_set('pcre.backtrack_limit', '100000');
			ini_set('pcre.recursion_limit', '10000');

			$mode = (string) $this->getInput('mode', 'inventory');

			if ($mode === 'resolve') {
				$this->respondJson($this->resolveFilters());
			}
			elseif ($mode === 'forecast') {
				$this->handleForecast();
			}
			else {
				$this->handleInventory();
			}
		}
		catch (\Throwable $e) {
			// Log the real cause; return a generic message (avoid leaking internal
			// paths/DB/API detail to the client). User-meaningful validation errors
			// are returned directly via respondJsonError() and never reach here.
			error_log('CapacityPlanning data error: '.$e->getMessage());
			$this->respondJsonError(_('An internal error occurred while loading capacity data.'), 500);
		}
	}

	// -------------------------------------------------------------------------
	// Filter resolution (host group / host / template names -> ids)
	// -------------------------------------------------------------------------

	private function resolveFilters(): array {
		$group = trim((string) $this->getInput('group', ''));
		$host = trim((string) $this->getInput('host', ''));
		$template = trim((string) $this->getInput('template', ''));

		$groupids = [];
		$empty = false;
		$notes = [];

		if ($group !== '') {
			$rows = API::HostGroup()->get(['output' => ['groupid'], 'search' => ['name' => $group]]) ?: [];
			$groupids = array_values(array_map(static fn ($g) => (string) $g['groupid'], $rows));
			$notes[] = sprintf(_('%1$d host group(s)'), count($groupids));
			if (!$groupids) {
				$empty = true;
			}
		}

		// Resolve one host-id set. When both host-name and template are given the
		// constraints are ANDed in ONE Host.get so the cap yields the true (capped)
		// intersection instead of intersecting two independently-capped sets.
		$hostids = [];
		$truncated = false;
		if (($host !== '' || $template !== '') && !$empty) {
			$tids = [];
			if ($template !== '') {
				$tpls = API::Template()->get(['output' => ['templateid'], 'search' => ['host' => $template]]) ?: [];
				$tids = array_map(static fn ($t) => (string) $t['templateid'], $tpls);
				if (!$tids) {
					$empty = true;
				}
			}

			if (!$empty) {
				$params = ['output' => ['hostid'], 'limit' => self::MAX_FILTER_HOSTS];
				if ($host !== '') {
					$params['search'] = ['host' => $host];
				}
				if ($tids) {
					$params['templateids'] = $tids;
				}
				if ($groupids) {
					$params['groupids'] = $groupids;
				}
				$rows = API::Host()->get($params) ?: [];
				$hostids = array_values(array_unique(array_map(static fn ($h) => (string) $h['hostid'], $rows)));
				$notes[] = sprintf(_('%1$d host(s)'), count($hostids));
				if (count($hostids) >= self::MAX_FILTER_HOSTS) {
					$truncated = true;
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

	private function parseIds(string $csv): array {
		// Hard caps keep a hostile CSV from turning into a huge SQL IN clause.
		if (strlen($csv) > 120000) {
			$csv = substr($csv, 0, 120000);
		}
		$out = [];
		foreach (explode(',', $csv, self::MAX_FILTER_HOSTS + 1) as $part) {
			$part = trim($part);
			if ($part !== '' && ctype_digit($part)) {
				$out[] = $part;
				if (count($out) >= self::MAX_FILTER_HOSTS) {
					break;
				}
			}
		}
		return array_values(array_unique($out));
	}

	// -------------------------------------------------------------------------
	// Inventory mode
	// -------------------------------------------------------------------------

	private function handleInventory(): void {
		$groupids = $this->parseIds((string) $this->getInput('groupids', ''));
		$hostids = $this->parseIds((string) $this->getInput('hostids', ''));

		[$hosts, $hosts_truncated] = $this->fetchHosts($groupids, $hostids);
		if (!$hosts) {
			$this->respondJson([
				'disks' => [], 'resources' => [], 'quality' => [],
				'meta' => ['hosts_analyzed' => 0, 'generated_at' => time(), 'hosts_truncated' => false,
					'items_truncated' => false, 'findings_truncated' => false]
			]);
			return;
		}

		[$items_by_host, $items_truncated] = $this->fetchItems(array_keys($hosts));
		$macro_index = $this->buildMacroIndex($hosts);

		$now = time();
		$disks = $this->buildDiskFindings($hosts, $items_by_host, $macro_index, $now);
		$resources = $this->buildResourceFindings($hosts, $items_by_host, $macro_index, $now);

		$findings_truncated = false;
		if (count($disks) + count($resources) > self::MAX_FINDINGS) {
			$findings_truncated = true;
			$disk_budget = max(0, self::MAX_FINDINGS - count($resources));
			$disks = array_slice($disks, 0, $disk_budget);
			if (count($resources) > self::MAX_FINDINGS) {
				$resources = array_slice($resources, 0, self::MAX_FINDINGS);
			}
		}

		$this->respondJson([
			'disks' => $disks,
			'resources' => $resources,
			'quality' => array_slice($this->quality, 0, self::MAX_QUALITY_ISSUES),
			'meta' => [
				'hosts_analyzed' => count($hosts),
				'generated_at' => $now,
				'hosts_truncated' => $hosts_truncated,
				'items_truncated' => $items_truncated,
				'findings_truncated' => $findings_truncated,
				'quality_truncated' => count($this->quality) > self::MAX_QUALITY_ISSUES,
				'forecast_batch_max' => self::FORECAST_BATCH_MAX,
				'max_lookback_days' => self::MAX_LOOKBACK_DAYS
			]
		]);
	}

	/**
	 * @return array{0: array<string, array>, 1: bool} hosts keyed by hostid, truncated flag
	 */
	private function fetchHosts(array $groupids, array $hostids): array {
		$params = [
			'output' => ['hostid', 'host', 'name', 'maintenance_status'],
			'monitored_hosts' => true,
			'selectHostGroups' => ['groupid', 'name'],
			'selectParentTemplates' => ['templateid', 'host', 'name'],
			'selectInventory' => ['os', 'os_full', 'type'],
			'sortfield' => 'name',
			'limit' => self::MAX_HOSTS + 1
		];
		if ($groupids) {
			$params['groupids'] = $groupids;
		}
		if ($hostids) {
			$params['hostids'] = $hostids;
		}

		$rows = API::Host()->get($params);
		if (!is_array($rows)) {
			$rows = [];
		}
		$truncated = count($rows) > self::MAX_HOSTS;
		if ($truncated) {
			$rows = array_slice($rows, 0, self::MAX_HOSTS);
		}

		$hosts = [];
		$visible_groupids = $groupids ? array_flip($groupids) : null;
		foreach ($rows as $row) {
			$hostid = (string) $row['hostid'];
			$groups = [];
			foreach (($row['hostgroups'] ?? []) as $g) {
				$name = (string) ($g['name'] ?? '');
				if ($name !== ''
						&& ($visible_groupids === null || isset($visible_groupids[(string) ($g['groupid'] ?? '')]))) {
					$groups[$name] = true;
				}
			}
			$templates = [];
			foreach (($row['parentTemplates'] ?? []) as $t) {
				if (!empty($t['templateid'])) {
					$templates[] = [
						'templateid' => (string) $t['templateid'],
						'name' => (string) ($t['name'] ?? $t['host'] ?? '')
					];
				}
			}
			$host = [
				'hostid' => $hostid,
				'host' => (string) ($row['host'] ?? $hostid),
				'name' => (string) ($row['name'] ?? $row['host'] ?? $hostid),
				'groups' => array_keys($groups),
				'templates' => $templates,
				'inventory' => is_array($row['inventory'] ?? null) ? $row['inventory'] : []
			];
			$host['os'] = $this->detectOs($host, null);
			$hosts[$hostid] = $host;
		}

		return [$hosts, $truncated];
	}

	private function detectOs(array $host, ?string $fs_hint): string {
		$parts = [
			(string) ($host['inventory']['os'] ?? ''),
			(string) ($host['inventory']['os_full'] ?? ''),
			(string) ($host['inventory']['type'] ?? '')
		];
		foreach ($host['templates'] as $t) {
			$parts[] = $t['name'];
		}
		$text = mb_strtolower(implode(' ', $parts));
		if (strpos($text, 'windows') !== false) {
			return 'Windows';
		}
		foreach (['linux', 'rhel', 'red hat', 'ubuntu', 'suse', 'debian', 'fedora'] as $token) {
			if (strpos($text, $token) !== false) {
				return 'Linux';
			}
		}
		if ($fs_hint !== null && $fs_hint !== '') {
			if (preg_match('/^[a-z]:/i', $fs_hint) === 1 || strncmp($fs_hint, '\\\\', 2) === 0) {
				return 'Windows';
			}
			if ($fs_hint[0] === '/') {
				return 'Linux';
			}
		}
		return 'Unknown';
	}

	/**
	 * Fetch resource-related items in bounded host chunks.
	 *
	 * @return array{0: array<string, array<int, array>>, 1: bool} items grouped by hostid, truncated flag
	 */
	private function fetchItems(array $hostids): array {
		// 'vm.mem' (no dot) intentionally covers both vm.memory.* and vm.mem.* keys.
		$searches = ['vfs.fs.', 'system.cpu.', 'vm.mem', 'vm.cpu.'];
		$by_id = [];
		$truncated = false;

		foreach (array_chunk($hostids, self::HOST_CHUNK) as $chunk) {
			foreach ($searches as $search) {
				$rows = API::Item()->get([
					'output' => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units', 'lastvalue',
						'lastclock', 'state', 'error'],
					'hostids' => $chunk,
					'monitored' => true,
					'search' => ['key_' => $search],
					'startSearch' => true,
					'selectTags' => ['tag', 'value'],
					'limit' => self::MAX_ITEMS
				]);
				if (!is_array($rows)) {
					continue;
				}
				foreach ($rows as $row) {
					$itemid = (string) ($row['itemid'] ?? '');
					if ($itemid === '' || isset($by_id[$itemid])) {
						continue;
					}
					if (count($by_id) >= self::MAX_ITEMS) {
						$truncated = true;
						break 3;
					}
					$by_id[$itemid] = [
						'itemid' => $itemid,
						'hostid' => (string) ($row['hostid'] ?? ''),
						'name' => (string) ($row['name'] ?? ''),
						'key' => (string) ($row['key_'] ?? ''),
						'value_type' => (int) ($row['value_type'] ?? 0),
						'units' => (string) ($row['units'] ?? ''),
						'lastvalue' => $this->safeFloat($row['lastvalue'] ?? null),
						'lastclock' => (int) ($row['lastclock'] ?? 0),
						'state' => (int) ($row['state'] ?? 0),
						'tags' => is_array($row['tags'] ?? null) ? $row['tags'] : []
					];
				}
			}
		}

		$by_host = [];
		foreach ($by_id as $item) {
			$by_host[$item['hostid']][] = $item;
		}
		return [$by_host, $truncated];
	}

	// -------------------------------------------------------------------------
	// Effective user-macro resolution (host -> template chain -> global)
	// -------------------------------------------------------------------------

	/**
	 * Build a macro lookup index following Zabbix precedence: host macros win,
	 * then linked templates by dependency depth, then global macros. Contexts
	 * resolve exact-match first, regex-context second (each across all scopes).
	 */
	private function buildMacroIndex(array $hosts): array {
		// The host scope itself is permission-filtered (host.get in fetchHosts).
		// Threshold macros, however, mostly live on TEMPLATES, which regular users
		// usually cannot read — resolving them without permission checks reproduces
		// the thresholds the Zabbix server actually alarms on. Only text macros
		// matching three threshold-name prefixes are read; secret/vault macros are
		// skipped during resolution and raw values are never sent to the client.
		$template_parents = [];
		$template_rows = API::Template()->get([
			'output' => ['templateid'],
			'selectParentTemplates' => ['templateid'],
			'nopermissions' => true,
			'limit' => 10000
		]);
		if (is_array($template_rows)) {
			foreach ($template_rows as $row) {
				$tid = (string) $row['templateid'];
				$parents = [];
				foreach (($row['parentTemplates'] ?? []) as $p) {
					if (!empty($p['templateid'])) {
						$parents[] = (string) $p['templateid'];
					}
				}
				$template_parents[$tid] = $parents;
			}
		}

		$host_levels = [];
		$relevant = [];
		foreach ($hosts as $hostid => $host) {
			$hostid = (string) $hostid; // PHP stores numeric-string keys as ints
			$relevant[$hostid] = true;
			$direct = [];
			foreach ($host['templates'] as $t) {
				$direct[$t['templateid']] = true;
			}
			$levels = [];
			$seen = [];
			$current = array_keys($direct);
			while ($current) {
				$current = array_values(array_filter($current, static fn ($tid) => !isset($seen[$tid])));
				if (!$current) {
					break;
				}
				sort($current, SORT_NATURAL);
				$levels[] = $current;
				$parents = [];
				foreach ($current as $tid) {
					$seen[$tid] = true;
					$relevant[$tid] = true;
					foreach (($template_parents[$tid] ?? []) as $pid) {
						$parents[$pid] = true;
					}
				}
				$current = array_keys(array_diff_key($parents, $seen));
			}
			$host_levels[$hostid] = $levels;
		}

		$macro_searches = ['VFS.FS.', 'CPU.UTIL', 'MEMORY.'];
		$by_entity = [];
		$entity_ids = array_keys($relevant);
		sort($entity_ids, SORT_NATURAL);
		foreach (array_chunk($entity_ids, self::MACRO_ENTITY_CHUNK) as $chunk) {
			foreach ($macro_searches as $search) {
				$rows = API::UserMacro()->get([
					'output' => ['hostid', 'macro', 'value', 'type'],
					'hostids' => $chunk,
					'search' => ['macro' => $search],
					'nopermissions' => true
				]);
				if (!is_array($rows)) {
					continue;
				}
				foreach ($rows as $row) {
					$parsed = $this->parseMacro((string) ($row['macro'] ?? ''), (string) ($row['value'] ?? ''),
						(int) ($row['type'] ?? 0));
					if ($parsed !== null) {
						$by_entity[(string) ($row['hostid'] ?? '')][] = $parsed;
					}
				}
			}
		}

		$global = [];
		foreach ($macro_searches as $search) {
			$rows = API::UserMacro()->get([
				'output' => ['macro', 'value', 'type'],
				'globalmacro' => true,
				'search' => ['macro' => $search],
				'nopermissions' => true
			]);
			if (!is_array($rows)) {
				continue;
			}
			foreach ($rows as $row) {
				$parsed = $this->parseMacro((string) ($row['macro'] ?? ''), (string) ($row['value'] ?? ''),
					(int) ($row['type'] ?? 0));
				if ($parsed !== null) {
					$global[] = $parsed;
				}
			}
		}

		return ['levels' => $host_levels, 'by_entity' => $by_entity, 'global' => $global];
	}

	private function parseMacro(string $raw, string $value, int $type): ?array {
		if (preg_match('/^\{\$([A-Z0-9_.]+)(?::(.*))?\}$/i', trim($raw), $m) !== 1) {
			return null;
		}
		$context = null;
		$regex_context = false;
		if (isset($m[2])) {
			$ctx = trim($m[2]);
			if (stripos($ctx, 'regex:') === 0) {
				$regex_context = true;
				$ctx = substr($ctx, 6);
			}
			$context = $this->unquoteContext($ctx);
		}
		return [
			'raw' => $raw,
			'base' => strtoupper($m[1]),
			'context' => $context,
			'regex' => $regex_context,
			'value' => $value,
			'type' => $type
		];
	}

	private function unquoteContext(string $value): string {
		$value = trim($value);
		if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
			$value = substr($value, 1, -1);
			$value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
		}
		return $value;
	}

	/**
	 * Ordered macro scopes for one host: host itself, templates by depth, global.
	 *
	 * @return array<int, array{0: string, 1: array<int, array>}> [label, macros]
	 */
	private function macroScopes(array $index, string $hostid): array {
		$scopes = [['host', $index['by_entity'][$hostid] ?? []]];
		foreach (($index['levels'][$hostid] ?? []) as $depth => $level) {
			foreach ($level as $tid) {
				$scopes[] = ['template depth '.($depth + 1), $index['by_entity'][$tid] ?? []];
			}
		}
		$scopes[] = ['global', $index['global']];
		return $scopes;
	}

	/**
	 * Resolve {$BASE:"context"} across scopes: for each candidate context, exact
	 * context matches (all scopes) beat regex context matches (all scopes);
	 * afterwards the plain (context-free) macro is resolved by scope precedence.
	 *
	 * @return array{value: ?string, source: string, ambiguous: bool}
	 */
	private function resolveMacro(array $index, string $hostid, string $base, array $contexts): array {
		$base = strtoupper($base);
		$scopes = $this->macroScopes($index, $hostid);

		foreach ($contexts as $context) {
			if ($context === '') {
				continue;
			}
			foreach ($scopes as [$label, $macros]) {
				$exact = [];
				foreach ($macros as $macro) {
					if ($macro['base'] === $base && $macro['type'] === 0 && $macro['context'] !== null
							&& !$macro['regex'] && $macro['context'] === $context) {
						$exact[] = $macro;
					}
				}
				if ($exact) {
					usort($exact, static fn ($a, $b) => strcmp($a['raw'], $b['raw']));
					return ['value' => $exact[0]['value'], 'source' => $label, 'ambiguous' => count($exact) > 1];
				}
			}
			foreach ($scopes as [$label, $macros]) {
				$matched = [];
				foreach ($macros as $macro) {
					if ($macro['base'] === $base && $macro['type'] === 0 && $macro['context'] !== null
							&& $macro['regex'] && @preg_match('~'.str_replace('~', '\~', $macro['context']).'~', $context) === 1) {
						$matched[] = $macro;
					}
				}
				if ($matched) {
					usort($matched, static fn ($a, $b) => strcmp($a['raw'], $b['raw']));
					return ['value' => $matched[0]['value'], 'source' => $label, 'ambiguous' => count($matched) > 1];
				}
			}
		}

		foreach ($scopes as [$label, $macros]) {
			$plain = [];
			foreach ($macros as $macro) {
				if ($macro['base'] === $base && $macro['type'] === 0 && $macro['context'] === null) {
					$plain[] = $macro;
				}
			}
			if ($plain) {
				usort($plain, static fn ($a, $b) => strcmp($a['raw'], $b['raw']));
				return ['value' => $plain[0]['value'], 'source' => $label, 'ambiguous' => count($plain) > 1];
			}
		}

		return ['value' => null, 'source' => 'not found', 'ambiguous' => false];
	}

	/**
	 * @return array{v: ?float, src: string, fb: bool} percentage threshold (0..100)
	 */
	private function percentThreshold(array $macro, float $fallback): array {
		$parsed = $this->parseZabbixNumber($macro['value']);
		if ($parsed === null || $parsed < 0 || $parsed > 100) {
			return ['v' => $fallback, 'src' => sprintf(_('fallback %s%%'), $this->trimFloat($fallback)), 'fb' => true];
		}
		return ['v' => $parsed, 'src' => $macro['source'], 'fb' => false];
	}

	/**
	 * @return array{v: float, src: string, fb: bool} byte threshold (0 = disabled)
	 */
	private function bytesThreshold(array $macro): array {
		$parsed = $this->parseZabbixNumber($macro['value']);
		if ($parsed === null || $parsed < 0) {
			return ['v' => 0.0, 'src' => _('disabled'), 'fb' => true];
		}
		return ['v' => $parsed, 'src' => $macro['source'], 'fb' => false];
	}

	/**
	 * Absolute free-space thresholds live under different macro families depending
	 * on template generation; try each until one resolves to a value.
	 */
	private function freeSpaceThreshold(array $macro_index, string $hostid, string $fs, string $level): array {
		foreach (['VFS.FS.MAX.GB.'.$level, 'VFS.FS.FREE.MIN.'.$level] as $base) {
			$macro = $this->resolveMacro($macro_index, $hostid, $base, [$fs]);
			if ($macro['value'] !== null) {
				return $this->bytesThreshold($macro);
			}
		}
		return $this->bytesThreshold(['value' => null, 'source' => 'not found', 'ambiguous' => false]);
	}

	private function parseZabbixNumber(?string $value): ?float {
		if ($value === null) {
			return null;
		}
		$raw = str_replace(',', '.', trim($value));
		if ($raw !== '' && substr($raw, -1) === '%') {
			$raw = trim(substr($raw, 0, -1));
		}
		if (preg_match('/^([+-]?(?:\d+(?:\.\d*)?|\.\d+))\s*'
				.'(B|K|KB|KIB|M|MB|MIB|G|GB|GIB|T|TB|TIB|P|PB|PIB)?$/i', $raw, $m) !== 1) {
			return null;
		}
		$number = (float) $m[1];
		$suffix = strtoupper($m[2] ?? '');
		$powers = ['' => 0, 'B' => 0, 'K' => 1, 'KB' => 1, 'KIB' => 1, 'M' => 2, 'MB' => 2, 'MIB' => 2,
			'G' => 3, 'GB' => 3, 'GIB' => 3, 'T' => 4, 'TB' => 4, 'TIB' => 4, 'P' => 5, 'PB' => 5, 'PIB' => 5];
		return $number * (1024 ** $powers[$suffix]);
	}

	private function trimFloat(float $value): string {
		return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
	}

	// -------------------------------------------------------------------------
	// Disk findings
	// -------------------------------------------------------------------------

	private function buildDiskFindings(array $hosts, array $items_by_host, array $macro_index, int $now): array {
		$findings = [];
		$idx = 0;

		foreach ($hosts as $hostid => $host) {
			$hostid = (string) $hostid; // PHP stores numeric-string keys as ints
			$families = $this->chooseFilesystemFamilies($items_by_host[$hostid] ?? []);
			if (!$families) {
				continue;
			}
			ksort($families, SORT_NATURAL);

			foreach ($families as $fs => $metrics) {
				$sample = reset($metrics);
				$fstype = $this->itemTag($sample, 'fstype');
				$kind = $this->filesystemKind($fs, $fstype);
				$os = $host['os'] !== 'Unknown' ? $host['os'] : $this->detectOs($host, $fs);

				$display_context = $fs;
				foreach ($metrics as $item) {
					if (preg_match('/^FS\s+\[(.+?)\]\s*:/i', $item['name'], $m) === 1) {
						$display_context = trim($m[1]);
						break;
					}
				}
				// Latest Windows pused triggers use exactly label(name) as context,
				// while GB triggers use the raw FSNAME.
				$pct_contexts = ($os === 'Windows' && $display_context !== $fs) ? [$display_context] : [$fs];

				$warn_fallback = $kind === 'Remote' ? self::REMOTE_DISK_WARN_DEFAULT : self::DISK_WARN_DEFAULT;
				$crit_fallback = $kind === 'Remote' ? self::REMOTE_DISK_CRIT_DEFAULT : self::DISK_CRIT_DEFAULT;
				$warn_pct = $this->percentThreshold(
					$this->resolveMacro($macro_index, $hostid, 'VFS.FS.PUSED.MAX.WARN', $pct_contexts), $warn_fallback);
				$crit_pct = $this->percentThreshold(
					$this->resolveMacro($macro_index, $hostid, 'VFS.FS.PUSED.MAX.CRIT', $pct_contexts), $crit_fallback);
				$warn_gb = $this->freeSpaceThreshold($macro_index, $hostid, $fs, 'WARN');
				$crit_gb = $this->freeSpaceThreshold($macro_index, $hostid, $fs, 'CRIT');

				if ($warn_pct['v'] !== null && $crit_pct['v'] !== null && $warn_pct['v'] >= $crit_pct['v']) {
					$this->addQuality('Warning', $host['name'], $fs, _('Invalid percentage threshold order'),
						_('Warning percentage should be lower than critical percentage.'));
				}

				$total = $this->currentMetric($metrics, 'total');
				$used = $this->currentMetric($metrics, 'used');
				$free = $this->currentMetric($metrics, 'free');
				$pused = $this->currentMetric($metrics, 'pused');
				if ($used === null && $total !== null && $free !== null) {
					$used = max(0.0, $total - $free);
				}
				if ($free === null && $total !== null && $used !== null) {
					$free = max(0.0, $total - $used);
				}
				if ($pused === null && $used !== null && $free !== null && $used + $free > 0) {
					$pused = $used / ($used + $free) * 100;
				}
				if ($pused === null) {
					$pfree = $this->currentMetric($metrics, 'pfree');
					if ($pfree !== null) {
						$pused = max(0.0, 100.0 - $pfree);
					}
				}

				[$series_item, $transform, $parameter] = $this->usedSeriesSpec($metrics, $total, $used, $pused);

				$lastclock = 0;
				foreach ($metrics as $item) {
					if ($item['state'] === 0 && $item['lastvalue'] !== null && $item['lastclock'] > $lastclock) {
						$lastclock = $item['lastclock'];
					}
				}

				if ($lastclock === 0 || $now - $lastclock > self::STALE_DISK_SECONDS) {
					$status = 'Stale';
					$this->addQuality('Warning', $host['name'], $fs, _('Stale filesystem data'),
						$lastclock > 0
							? sprintf(_('Last value: %1$s'), gmdate('Y-m-d H:i', $lastclock).' UTC')
							: _('No values have been collected.'));
				}
				elseif ($total === null || $used === null || $free === null) {
					$status = 'Incomplete';
					$this->addQuality('Warning', $host['name'], $fs, _('Incomplete filesystem item set'),
						_('Expected total, used and available byte items were not all usable.'));
				}
				else {
					$status = 'OK';
				}

				$findings[] = [
					'id' => 'd'.$idx++,
					'hostid' => $hostid,
					'host' => $host['name'],
					'os' => $os,
					'fs' => $fs,
					'kind' => $kind,
					'itemid' => $series_item !== null ? $series_item['itemid'] : null,
					'item_key' => $series_item !== null ? $series_item['key'] : '',
					'tr' => $transform,
					'pr' => $parameter,
					'total' => $total,
					'used' => $used,
					'free' => $free,
					'pused' => $pused !== null ? round($pused, 3) : null,
					'lastclock' => $lastclock ?: null,
					'warn_pct' => $warn_pct,
					'crit_pct' => $crit_pct,
					'warn_free' => $warn_gb,
					'crit_free' => $crit_gb,
					'status' => $status
				];
			}
		}

		return $findings;
	}

	/**
	 * Group filesystem items into (filesystem -> metric -> item), choosing the
	 * most complete key family (standard vs dependent) per filesystem.
	 *
	 * @return array<string, array<string, array>>
	 */
	private function chooseFilesystemFamilies(array $host_items): array {
		$families = [];
		foreach ($host_items as $item) {
			$parsed = $this->parseFsKey($item['key']);
			if ($parsed === null) {
				continue;
			}
			[$fs, $metric] = $parsed;
			$family = strpos($item['key'], '.dependent.') !== false ? 'dependent' : 'standard';
			$current = $families[$fs][$family][$metric] ?? null;
			if ($current === null || $this->itemRank($item) > $this->itemRank($current)) {
				$families[$fs][$family][$metric] = $item;
			}
		}

		$selected = [];
		foreach ($families as $fs => $by_family) {
			$best = null;
			$best_score = null;
			foreach ($by_family as $family => $metrics) {
				$completeness = 0;
				foreach (['used', 'free', 'total', 'pused'] as $metric) {
					if (isset($metrics[$metric])) {
						$completeness++;
					}
				}
				$supported = 0;
				$usable = 0;
				$freshness = 0;
				foreach ($metrics as $item) {
					if ($item['state'] === 0) {
						$supported++;
						if ($item['lastvalue'] !== null && $item['lastclock'] > 0) {
							$usable++;
						}
						if ($item['lastclock'] > $freshness) {
							$freshness = $item['lastclock'];
						}
					}
				}
				$score = [$usable, $supported, $completeness, $freshness, $family === 'dependent' ? 1 : 0];
				if ($best_score === null || $score > $best_score) {
					$best_score = $score;
					$best = $metrics;
				}
			}
			if ($best !== null) {
				$selected[$fs] = $best;
			}
		}
		return $selected;
	}

	/**
	 * @return array{0: string, 1: string}|null [filesystem, metric]
	 */
	private function parseFsKey(string $key): ?array {
		if (preg_match('/^vfs\.fs(?:\.dependent)?\.size\[(.*),(free|used|total|pused|pfree)\]$/i',
				trim($key), $m) !== 1) {
			return null;
		}
		$fs = trim($m[1]);
		if (strlen($fs) >= 2 && $fs[0] === '"' && substr($fs, -1) === '"') {
			$fs = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($fs, 1, -1));
		}
		return [$fs, strtolower($m[2])];
	}

	private function itemRank(array $item): array {
		return [
			$item['state'] === 0 ? 1 : 0,
			($item['lastvalue'] !== null && $item['lastclock'] > 0) ? 1 : 0,
			$item['lastclock'],
			(int) $item['itemid']
		];
	}

	private function itemTag(array $item, string $tag): string {
		foreach ($item['tags'] as $t) {
			if (strcasecmp((string) ($t['tag'] ?? ''), $tag) === 0) {
				return (string) ($t['value'] ?? '');
			}
		}
		return '';
	}

	private function filesystemKind(string $fs, string $fstype): string {
		$remote_types = ['nfs', 'nfs4', 'cifs', 'smb', 'smbfs', 'fuse.sshfs', 'ceph', 'glusterfs'];
		if (in_array(strtolower($fstype), $remote_types, true)
				|| strncmp($fs, '\\\\', 2) === 0
				|| preg_match('~^[^/]+:/~', $fs) === 1) {
			return 'Remote';
		}
		return 'Local';
	}

	private function currentMetric(array $metrics, string $metric): ?float {
		$item = $metrics[$metric] ?? null;
		return ($item !== null && $item['state'] === 0 && $item['lastvalue'] !== null && $item['lastclock'] > 0)
			? $item['lastvalue']
			: null;
	}

	/**
	 * Pick the best time-series item for used-bytes growth analysis.
	 *
	 * @return array{0: ?array, 1: string, 2: ?float} [item, transform, parameter]
	 */
	private function usedSeriesSpec(array $metrics, ?float $total, ?float $used, ?float $pused): array {
		$usable = function (string $metric) use ($metrics): ?array {
			$item = $metrics[$metric] ?? null;
			return ($item !== null && $item['state'] === 0 && $item['lastvalue'] !== null && $item['lastclock'] > 0)
				? $item
				: null;
		};

		$used_item = $usable('used');
		if ($used_item !== null) {
			return [$used_item, 'identity', null];
		}
		$free_item = $usable('free');
		if ($free_item !== null && $total !== null) {
			return [$free_item, 'invert', $total];
		}
		$pused_item = $usable('pused');
		if ($pused_item !== null) {
			$capacity = null;
			if ($used !== null && $pused !== null && $pused > 0) {
				$capacity = $used / ($pused / 100);
			}
			elseif ($total !== null) {
				$capacity = $total;
			}
			return [$pused_item, 'scale', $capacity !== null ? $capacity / 100 : null];
		}
		return [null, 'identity', null];
	}

	// -------------------------------------------------------------------------
	// CPU / memory findings
	// -------------------------------------------------------------------------

	private function buildResourceFindings(array $hosts, array $items_by_host, array $macro_index, int $now): array {
		$findings = [];
		$idx = 0;

		foreach ($hosts as $hostid => $host) {
			$hostid = (string) $hostid; // PHP stores numeric-string keys as ints
			$host_items = $items_by_host[$hostid] ?? [];
			if (!$host_items) {
				continue;
			}

			// CPU ------------------------------------------------------------
			$cpu_item = $this->chooseExactItem($host_items, ['system.cpu.util', 'vm.cpu.util']);
			$cpu_transform = 'identity';
			$cpu_parameter = null;
			if ($cpu_item === null) {
				$idle = $this->chooseExactItem($host_items, ['system.cpu.util[,idle]']);
				if ($idle !== null) {
					$cpu_item = $idle;
					$cpu_transform = 'invert';
					$cpu_parameter = 100.0;
				}
			}

			$cpu_crit = $this->percentThreshold(
				$this->resolveMacro($macro_index, $hostid, 'CPU.UTIL.CRIT', []), self::CPU_CRIT_DEFAULT);
			if ($host['os'] === 'Windows') {
				// The latest Windows template only carries CPU.UTIL.CRIT; the review
				// threshold is analytic, not a real second macro.
				$alarm = $cpu_crit['v'] ?? self::CPU_WARN_DEFAULT;
				$cpu_warn = ['v' => max(0.0, min($alarm - 10.0, self::CPU_WARN_DEFAULT)),
					'src' => _('review level (alarm − 10 pp)'), 'fb' => true];
			}
			else {
				$cpu_warn = $this->percentThreshold(
					$this->resolveMacro($macro_index, $hostid, 'CPU.UTIL.WARN', []), self::CPU_WARN_DEFAULT);
				if ($cpu_warn['fb']) {
					$legacy = $this->percentThreshold(
						$this->resolveMacro($macro_index, $hostid, 'CPU.UTIL.WARNING', []), self::CPU_WARN_DEFAULT);
					if (!$legacy['fb']) {
						$cpu_warn = $legacy;
					}
				}
				if ($cpu_crit['v'] !== null && $cpu_warn['v'] !== null && $cpu_crit['v'] <= $cpu_warn['v']) {
					$cpu_warn = ['v' => min(self::CPU_WARN_DEFAULT, max(0.0, $cpu_crit['v'] - 5.0)),
						'src' => _('fallback below critical'), 'fb' => true];
				}
			}

			// lastvalue without a lastclock is a never-collected sentinel, not a value.
			$current_cpu = ($cpu_item !== null && $cpu_item['lastclock'] > 0) ? $cpu_item['lastvalue'] : null;
			if ($current_cpu !== null && $cpu_transform === 'invert') {
				$current_cpu = 100.0 - $current_cpu;
			}
			$cpu_num = $this->chooseExactItem($host_items, ['system.cpu.num']);
			$cpu_count = ($cpu_num !== null && $cpu_num['lastclock'] > 0) ? $cpu_num['lastvalue'] : null;

			$cpu_status = 'OK';
			if ($cpu_item === null) {
				$cpu_status = 'Missing';
				$this->addQuality('Warning', $host['name'], 'CPU', _('CPU utilization item missing'),
					_('Expected system.cpu.util, vm.cpu.util or system.cpu.util[,idle].'));
			}
			elseif ($now - $cpu_item['lastclock'] > self::STALE_RESOURCE_SECONDS) {
				$cpu_status = 'Stale';
				$this->addQuality('Warning', $host['name'], 'CPU', _('CPU data is stale'),
					$cpu_item['lastclock'] > 0
						? sprintf(_('Last value: %1$s'), gmdate('Y-m-d H:i', $cpu_item['lastclock']).' UTC')
						: _('No values have been collected.'));
			}

			$findings[] = [
				'id' => 'r'.$idx++,
				'hostid' => $hostid,
				'host' => $host['name'],
				'os' => $host['os'],
				'rtype' => 'CPU',
				'metric' => _('CPU utilization'),
				'itemid' => $cpu_item !== null ? $cpu_item['itemid'] : null,
				'item_key' => $cpu_item !== null ? $cpu_item['key'] : '',
				'tr' => $cpu_transform,
				'pr' => $cpu_parameter,
				'current' => $current_cpu !== null ? round($current_cpu, 3) : null,
				'provisioned' => $cpu_count,
				'unit' => 'logical CPUs',
				'lastclock' => $cpu_item !== null ? $cpu_item['lastclock'] : null,
				'warn' => $cpu_warn,
				'crit' => $cpu_crit,
				'status' => $cpu_status
			];

			// Memory ----------------------------------------------------------
			$memory_keys = $host['os'] === 'Windows'
				? ['vm.memory.util', 'vm.memory.utilization', 'vm.mem.util', 'vm.memory.size[pused]']
				: ['vm.memory.utilization', 'vm.memory.util', 'vm.mem.util', 'vm.memory.size[pused]'];
			$memory_item = $this->chooseExactItem($host_items, $memory_keys);
			$memory_transform = 'identity';
			$memory_parameter = null;
			if ($memory_item === null) {
				$pavailable = $this->chooseExactItem($host_items, ['vm.memory.size[pavailable]']);
				if ($pavailable !== null) {
					$memory_item = $pavailable;
					$memory_transform = 'invert';
					$memory_parameter = 100.0;
				}
			}
			$memory_total = $this->chooseExactItem($host_items, ['vm.memory.size[total]']);

			$memory_alarm = $this->percentThreshold(
				$this->resolveMacro($macro_index, $hostid, 'MEMORY.UTIL.MAX', []), self::MEMORY_CRIT_DEFAULT);
			$alarm_value = $memory_alarm['v'] ?? self::MEMORY_CRIT_DEFAULT;
			$memory_review = ['v' => max(0.0, min(self::MEMORY_WARN_DEFAULT, $alarm_value - 5.0)),
				'src' => _('review level (alarm − 5 pp)'), 'fb' => true];

			$current_memory = ($memory_item !== null && $memory_item['lastclock'] > 0)
				? $memory_item['lastvalue']
				: null;
			if ($current_memory !== null && $memory_transform === 'invert') {
				$current_memory = 100.0 - $current_memory;
			}

			$memory_status = 'OK';
			if ($memory_item === null) {
				$memory_status = 'Missing';
				$this->addQuality('Warning', $host['name'], 'Memory', _('Memory utilization item missing'),
					_('Expected vm.memory.utilization, vm.memory.util, vm.mem.util, pused or pavailable.'));
			}
			elseif ($now - $memory_item['lastclock'] > self::STALE_RESOURCE_SECONDS) {
				$memory_status = 'Stale';
				$this->addQuality('Warning', $host['name'], 'Memory', _('Memory data is stale'),
					$memory_item['lastclock'] > 0
						? sprintf(_('Last value: %1$s'), gmdate('Y-m-d H:i', $memory_item['lastclock']).' UTC')
						: _('No values have been collected.'));
			}

			$findings[] = [
				'id' => 'r'.$idx++,
				'hostid' => $hostid,
				'host' => $host['name'],
				'os' => $host['os'],
				'rtype' => 'Memory',
				'metric' => _('Memory utilization'),
				'itemid' => $memory_item !== null ? $memory_item['itemid'] : null,
				'item_key' => $memory_item !== null ? $memory_item['key'] : '',
				'tr' => $memory_transform,
				'pr' => $memory_parameter,
				'current' => $current_memory !== null ? round($current_memory, 3) : null,
				'provisioned' => ($memory_total !== null && $memory_total['lastclock'] > 0)
					? $memory_total['lastvalue']
					: null,
				'unit' => 'bytes',
				'lastclock' => $memory_item !== null ? $memory_item['lastclock'] : null,
				'warn' => $memory_review,
				'crit' => $memory_alarm,
				'status' => $memory_status
			];
		}

		return $findings;
	}

	private function chooseExactItem(array $host_items, array $keys): ?array {
		$rank = array_flip($keys);
		$best = null;
		$best_score = null;
		foreach ($host_items as $item) {
			if (!isset($rank[$item['key']]) || $item['state'] !== 0) {
				continue;
			}
			$score = [
				($item['lastvalue'] !== null && $item['lastclock'] > 0) ? 1 : 0,
				-$rank[$item['key']],
				$item['lastclock'],
				(int) $item['itemid']
			];
			if ($best_score === null || $score > $best_score) {
				$best_score = $score;
				$best = $item;
			}
		}
		return $best;
	}

	private function addQuality(string $sev, string $host, string $resource, string $issue, string $detail): void {
		if (count($this->quality) < self::MAX_QUALITY_ISSUES + 1) {
			$this->quality[] = ['sev' => $sev, 'host' => $host, 'resource' => $resource,
				'issue' => $issue, 'detail' => $detail];
		}
	}

	// -------------------------------------------------------------------------
	// Forecast mode
	// -------------------------------------------------------------------------

	private function handleForecast(): void {
		$time_from = (int) $this->getInput('time_from', '0');
		$time_to = (int) $this->getInput('time_to', '0');
		if ($time_from <= 0 || $time_to <= 0 || $time_from >= $time_to) {
			$this->respondJsonError(_('Invalid time range.'), 400);
			return;
		}
		if ($time_to - $time_from > self::MAX_LOOKBACK_DAYS * 86400) {
			$this->respondJsonError(_('The selected lookback window is too large.'), 400);
			return;
		}

		$specs = $this->parseSpecs((string) $this->getInput('specs', ''));
		if ($specs === null) {
			$this->respondJsonError(_('Invalid forecast request.'), 400);
			return;
		}

		// Resolve item metadata once; API read-permissions apply here, so items the
		// user cannot read simply do not come back and are reported as denied.
		$itemids = [];
		foreach ($specs as $spec) {
			$itemids[$spec['itemid']] = true;
		}
		$item_meta = [];
		if ($itemids) {
			$rows = API::Item()->get([
				'output' => ['itemid', 'value_type'],
				'itemids' => array_keys($itemids),
				'webitems' => true,
				'preservekeys' => true
			]);
			if (is_array($rows)) {
				foreach ($rows as $itemid => $row) {
					$item_meta[(string) $itemid] = (int) ($row['value_type'] ?? 0);
				}
			}
		}

		$now = time();
		$results = [];
		foreach ($specs as $spec) {
			if (!isset($item_meta[$spec['itemid']])) {
				$results[] = ['id' => $spec['id'], 'status' => 'denied'];
				continue;
			}
			[$source, $rows, $note] = $this->fetchSeriesRows($spec['itemid'], $item_meta[$spec['itemid']],
				$time_from, $time_to);
			$rows = $this->transformRows($rows, $spec['tr'], $spec['pr']);
			if (!$rows) {
				$results[] = ['id' => $spec['id'], 'status' => 'no_data', 'note' => $note];
				continue;
			}
			if ($spec['kind'] === 'disk') {
				$results[] = $this->forecastDisk($spec, $rows, $source, $now);
			}
			else {
				$results[] = $this->forecastResource($spec, $rows, $source, $now);
			}
		}

		$this->respondJson([
			'forecasts' => $results,
			'meta' => ['generated_at' => $now, 'time_from' => $time_from, 'time_to' => $time_to]
		]);
	}

	/**
	 * Validate the client-provided forecast batch. Every numeric field is only a
	 * math parameter for this response (thresholds, current values); item access
	 * itself is enforced through the API in handleForecast().
	 */
	private function parseSpecs(string $raw): ?array {
		if ($raw === '' || strlen($raw) > 20000) {
			return null;
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded) || count($decoded) === 0 || count($decoded) > self::FORECAST_BATCH_MAX) {
			return null;
		}

		$num = function ($value, ?float $min = null, ?float $max = null): ?float {
			if ($value === null || !is_numeric($value)) {
				return null;
			}
			$value = (float) $value;
			if (!is_finite($value)) {
				return null;
			}
			if ($min !== null && $value < $min) {
				return null;
			}
			if ($max !== null && $value > $max) {
				return null;
			}
			return $value;
		};

		$specs = [];
		foreach ($decoded as $entry) {
			if (!is_array($entry)) {
				return null;
			}
			$id = (string) ($entry['id'] ?? '');
			$itemid = (string) ($entry['itemid'] ?? '');
			$kind = (string) ($entry['kind'] ?? '');
			$tr = (string) ($entry['tr'] ?? 'identity');
			$rtype = (string) ($entry['rtype'] ?? '');
			if (preg_match('/^[dr]\d{1,6}$/', $id) !== 1 || !ctype_digit($itemid)
					|| !in_array($kind, ['disk', 'res'], true)
					|| !in_array($tr, ['identity', 'invert', 'scale'], true)
					|| !in_array($rtype, ['', 'CPU', 'Memory'], true)) {
				return null;
			}
			$specs[] = [
				'id' => $id,
				'itemid' => $itemid,
				'kind' => $kind,
				'tr' => $tr,
				'rtype' => $rtype,
				'ok' => !isset($entry['ok']) || !empty($entry['ok']),
				'pr' => $num($entry['pr'] ?? null),
				'total' => $num($entry['total'] ?? null, 0.0),
				'used' => $num($entry['used'] ?? null, 0.0),
				'free' => $num($entry['free'] ?? null, 0.0),
				'pused' => $num($entry['pused'] ?? null, 0.0, 100.0),
				'warn_pct' => $num($entry['warn_pct'] ?? null, 0.0, 100.0),
				'crit_pct' => $num($entry['crit_pct'] ?? null, 0.0, 100.0),
				'warn_free' => $num($entry['warn_free'] ?? null, 0.0),
				'crit_free' => $num($entry['crit_free'] ?? null, 0.0),
				'current' => $num($entry['current'] ?? null, 0.0, 100.0),
				'warn' => $num($entry['warn'] ?? null, 0.0, 100.0),
				'crit' => $num($entry['crit'] ?? null, 0.0, 100.0)
			];
		}
		return $specs;
	}

	/**
	 * Fetch hourly trend rows for one item (sorted; trend.get has no ORDER BY),
	 * falling back to a bounded raw-history scan bucketed to hourly rows.
	 *
	 * @return array{0: string, 1: array<int, array>, 2: ?string} [source, rows, note]
	 */
	private function fetchSeriesRows(string $itemid, int $value_type, int $time_from, int $time_to): array {
		$rows = [];
		if (in_array($value_type, [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true)) {
			$trends = API::Trend()->get([
				'output' => ['clock', 'num', 'value_min', 'value_avg', 'value_max'],
				'itemids' => [$itemid],
				'time_from' => $time_from,
				'time_till' => $time_to,
				'limit' => self::MAX_TREND_ROWS
			]);
			if (is_array($trends)) {
				$deduped = [];
				foreach ($trends as $row) {
					$clock = (int) ($row['clock'] ?? 0);
					$avg = $this->safeFloat($row['value_avg'] ?? null);
					$min = $this->safeFloat($row['value_min'] ?? null);
					$max = $this->safeFloat($row['value_max'] ?? null);
					if ($clock > 0 && $avg !== null && $min !== null && $max !== null) {
						$deduped[$clock] = ['clock' => $clock, 'num' => max(1, (int) ($row['num'] ?? 1)),
							'min' => $min, 'avg' => $avg, 'max' => $max];
					}
				}
				ksort($deduped);
				$rows = array_values($deduped);
			}
		}
		if ($rows) {
			return ['trends', $rows, null];
		}

		// History fallback: recent bounded raw history bucketed hourly.
		if (!in_array($value_type, [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true)) {
			return ['none', [], _('Unsupported item value type for trend analysis.')];
		}
		$fallback_from = max($time_from, $time_to - self::HISTORY_FALLBACK_DAYS * 86400);
		$buckets = [];
		$cursor = $fallback_from;
		$fetched = 0;
		while ($fetched < self::MAX_HISTORY_ROWS) {
			$batch = API::History()->get([
				'output' => ['clock', 'value'],
				'history' => $value_type,
				'itemids' => [$itemid],
				'time_from' => $cursor,
				'time_till' => $time_to,
				'sortfield' => 'clock',
				'sortorder' => 'ASC',
				'limit' => self::HISTORY_FETCH_BATCH
			]);
			if (!is_array($batch) || !$batch) {
				break;
			}
			$last_clock = $cursor;
			foreach ($batch as $row) {
				$clock = (int) ($row['clock'] ?? 0);
				$value = $this->safeFloat($row['value'] ?? null);
				if ($clock > 0 && $value !== null) {
					$hour = $clock - ($clock % 3600);
					if (!isset($buckets[$hour])) {
						$buckets[$hour] = ['n' => 0, 'sum' => 0.0, 'min' => $value, 'max' => $value];
					}
					$buckets[$hour]['n']++;
					$buckets[$hour]['sum'] += $value;
					$buckets[$hour]['min'] = min($buckets[$hour]['min'], $value);
					$buckets[$hour]['max'] = max($buckets[$hour]['max'], $value);
				}
				if ($clock > $last_clock) {
					$last_clock = $clock;
				}
			}
			$fetched += count($batch);
			if (count($batch) < self::HISTORY_FETCH_BATCH) {
				break;
			}
			$cursor = $last_clock + 1;
		}
		if (!$buckets) {
			return ['none', [], _('No trend or history values were returned.')];
		}
		ksort($buckets);
		$rows = [];
		foreach ($buckets as $hour => $b) {
			$rows[] = ['clock' => $hour, 'num' => $b['n'], 'min' => $b['min'],
				'avg' => $b['sum'] / $b['n'], 'max' => $b['max']];
		}
		return ['history', $rows, _('Hourly trends were unavailable; a bounded raw-history fallback was used.')];
	}

	private function transformRows(array $rows, string $transform, ?float $parameter): array {
		if ($transform === 'identity') {
			return $rows;
		}
		if ($parameter === null) {
			return [];
		}
		$out = [];
		foreach ($rows as $row) {
			if ($transform === 'invert') {
				$out[] = ['clock' => $row['clock'], 'num' => $row['num'],
					'min' => $parameter - $row['max'], 'avg' => $parameter - $row['avg'],
					'max' => $parameter - $row['min']];
			}
			else { // scale
				$out[] = ['clock' => $row['clock'], 'num' => $row['num'],
					'min' => $row['min'] * $parameter, 'avg' => $row['avg'] * $parameter,
					'max' => $row['max'] * $parameter];
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Statistics
	// -------------------------------------------------------------------------

	/**
	 * Compute nested window statistics over sorted hourly rows.
	 *
	 * @return array<string, array> keyed by window id
	 */
	private function summarizeWindows(array $rows, int $now, ?float $warn, ?float $crit): array {
		$result = [];
		foreach (self::WINDOWS as $window => $days) {
			$cutoff = $now - $days * 86400;
			$selected = [];
			foreach ($rows as $row) {
				if ($row['clock'] >= $cutoff) {
					$selected[] = $row;
				}
			}
			if (!$selected) {
				$result[$window] = ['days' => 0, 'cov' => 0.0, 'n' => 0, 'avg' => null, 'p95' => null,
					'peak' => null, 'slope' => null, 'r2' => null, 'above_warn' => null, 'above_crit' => null,
					'first' => null, 'last' => null];
				continue;
			}

			$total_num = 0;
			$weighted_sum = 0.0;
			$peak = null;
			$hours = [];
			$values = [];
			$weights = [];
			foreach ($selected as $row) {
				$w = max(1, $row['num']);
				$total_num += $w;
				$weighted_sum += $row['avg'] * $w;
				$values[] = $row['avg'];
				$weights[] = $w;
				$hours[intdiv($row['clock'], 3600)] = true;
				if ($peak === null || $row['max'] > $peak) {
					$peak = $row['max'];
				}
			}
			$first = $selected[0]['clock'];
			$last = $selected[count($selected) - 1]['clock'];
			$data_days = max(1, min($days, (int) ceil(($last - $first + 3600) / 86400)));
			[$slope, $r2] = $this->theilSen($this->dailyPoints($selected));

			$above = function (?float $threshold) use ($values, $weights, $total_num): ?float {
				if ($threshold === null || $total_num <= 0) {
					return null;
				}
				$sum = 0;
				foreach ($values as $i => $value) {
					if ($value > $threshold) {
						$sum += $weights[$i];
					}
				}
				return $sum / $total_num * 100;
			};

			$result[$window] = [
				'days' => $data_days,
				'cov' => min(100.0, count($hours) / ($days * 24) * 100),
				'n' => $total_num,
				'avg' => $weighted_sum / $total_num,
				'p95' => $this->weightedPercentile($values, $weights, 0.95),
				'peak' => $peak,
				'slope' => $slope,
				'r2' => $r2,
				'above_warn' => $above($warn),
				'above_crit' => $above($crit),
				'first' => $first,
				'last' => $last
			];
		}
		return $result;
	}

	/**
	 * @return array<int, array{0: float, 1: float}> (day offset, weighted daily average)
	 */
	private function dailyPoints(array $rows): array {
		$buckets = [];
		foreach ($rows as $row) {
			$day = intdiv($row['clock'], 86400);
			$w = max(1, $row['num']);
			if (!isset($buckets[$day])) {
				$buckets[$day] = [0.0, 0];
			}
			$buckets[$day][0] += $row['avg'] * $w;
			$buckets[$day][1] += $w;
		}
		if (!$buckets) {
			return [];
		}
		ksort($buckets);
		$first_day = array_key_first($buckets);
		$points = [];
		foreach ($buckets as $day => [$sum, $count]) {
			if ($count > 0) {
				$points[] = [(float) ($day - $first_day), $sum / $count];
			}
		}
		return $points;
	}

	/**
	 * Robust Theil-Sen slope (median of pairwise slopes over <= 60 sampled daily
	 * points) with a clamped R² quality measure against the median-intercept fit.
	 *
	 * @return array{0: ?float, 1: ?float} [slope per day, r2]
	 */
	private function theilSen(array $points): array {
		$count = count($points);
		if ($count > 60) {
			$sampled = [];
			$picked = [];
			for ($i = 0; $i < 60; $i++) {
				$picked[(int) round($i * ($count - 1) / 59)] = true;
			}
			foreach (array_keys($picked) as $index) {
				$sampled[] = $points[$index];
			}
			$points = $sampled;
			$count = count($points);
		}
		if ($count < 3) {
			return [null, null];
		}

		$slopes = [];
		for ($a = 0; $a < $count - 1; $a++) {
			for ($b = $a + 1; $b < $count; $b++) {
				if ($points[$b][0] != $points[$a][0]) {
					$slopes[] = ($points[$b][1] - $points[$a][1]) / ($points[$b][0] - $points[$a][0]);
				}
			}
		}
		if (!$slopes) {
			return [null, null];
		}
		$slope = $this->median($slopes);

		$intercepts = [];
		$observed = [];
		foreach ($points as [$x, $y]) {
			$intercepts[] = $y - $slope * $x;
			$observed[] = $y;
		}
		$intercept = $this->median($intercepts);
		$mean = array_sum($observed) / count($observed);
		$residual = 0.0;
		$total = 0.0;
		foreach ($points as [$x, $y]) {
			$residual += ($y - ($intercept + $slope * $x)) ** 2;
			$total += ($y - $mean) ** 2;
		}
		if ($total == 0.0) {
			$r2 = $residual == 0.0 ? 1.0 : 0.0;
		}
		else {
			$r2 = max(0.0, min(1.0, 1.0 - $residual / $total));
		}
		return [$slope, $r2];
	}

	private function median(array $values): float {
		sort($values);
		$count = count($values);
		$mid = intdiv($count, 2);
		return $count % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
	}

	private function weightedPercentile(array $values, array $weights, float $quantile): ?float {
		if (!$values || count($values) !== count($weights)) {
			return null;
		}
		$pairs = [];
		foreach ($values as $i => $value) {
			$pairs[] = [$value, max(1, $weights[$i])];
		}
		usort($pairs, static fn ($a, $b) => $a[0] <=> $b[0]);
		$total = 0;
		foreach ($pairs as [, $w]) {
			$total += $w;
		}
		$target = max(1.0, min((float) $total, $total * $quantile));
		$cumulative = 0;
		foreach ($pairs as [$value, $w]) {
			$cumulative += $w;
			if ($cumulative >= $target) {
				return $value;
			}
		}
		return $pairs[count($pairs) - 1][0];
	}

	private function selectModelWindow(array $windows): ?string {
		foreach ([['3m', 60, 55.0], ['6m', 90, 45.0], ['12m', 180, 45.0], ['1m', 21, 55.0], ['1w', 5, 55.0]]
				as [$window, $min_days, $min_cov]) {
			$stats = $windows[$window] ?? null;
			if ($stats !== null && $stats['slope'] !== null && $stats['days'] >= $min_days
					&& $stats['cov'] >= $min_cov) {
				return $window;
			}
		}
		$best = null;
		$best_days = -1;
		foreach ($windows as $window => $stats) {
			if ($stats['slope'] !== null && $stats['days'] >= 3 && $stats['days'] > $best_days) {
				$best = $window;
				$best_days = $stats['days'];
			}
		}
		return $best;
	}

	private function forecastConfidence(?array $selected, array $windows): string {
		if ($selected === null) {
			return 'Low';
		}
		$one_month = $windows['1m'] ?? null;
		$agreement = true;
		if ($one_month !== null && $one_month['slope'] !== null && $selected['slope'] !== null
				&& abs($selected['slope']) > 0) {
			$agreement = $one_month['slope'] == 0.0
				|| ($one_month['slope'] <=> 0) === ($selected['slope'] <=> 0);
		}
		if ($selected['days'] >= 60 && $selected['cov'] >= 70 && ($selected['r2'] ?? 0) >= 0.55 && $agreement) {
			return 'High';
		}
		if ($selected['days'] >= 21 && $selected['cov'] >= 45 && $agreement) {
			return 'Medium';
		}
		return 'Low';
	}

	// -------------------------------------------------------------------------
	// Disk forecast (ETA to thresholds and full)
	// -------------------------------------------------------------------------

	private function forecastDisk(array $spec, array $rows, string $source, int $now): array {
		// Usable capacity favors used/pused so reserved blocks do not skew the pct math.
		$capacity = null;
		if ($spec['used'] !== null && $spec['pused'] !== null && $spec['pused'] > 0) {
			$capacity = $spec['used'] / ($spec['pused'] / 100);
		}
		if ($capacity === null) {
			$capacity = $spec['total'];
		}

		$used_warn = [];
		$used_crit = [];
		if ($capacity !== null) {
			if ($spec['warn_pct'] !== null) {
				$used_warn[] = $capacity * $spec['warn_pct'] / 100;
			}
			if ($spec['crit_pct'] !== null) {
				$used_crit[] = $capacity * $spec['crit_pct'] / 100;
			}
		}
		$free_ref = ($spec['used'] !== null && $spec['free'] !== null)
			? $spec['used'] + $spec['free']
			: $spec['total'];
		if ($free_ref !== null) {
			if (($spec['warn_free'] ?? 0) > 0) {
				$used_warn[] = max(0.0, $free_ref - $spec['warn_free']);
			}
			if (($spec['crit_free'] ?? 0) > 0) {
				$used_crit[] = max(0.0, $free_ref - $spec['crit_free']);
			}
		}
		$warn_used = $used_warn ? min($used_warn) : null;
		$crit_used = $used_crit ? min($used_crit) : null;

		$windows = $this->summarizeWindows($rows, $now, $warn_used, $crit_used);
		$selected_window = $this->selectModelWindow($windows);
		$selected = $selected_window !== null ? $windows[$selected_window] : null;
		$confidence = $this->forecastConfidence($selected, $windows);

		$slope = $selected !== null ? $selected['slope'] : null;
		$accelerating = false;
		$recent = $windows['1m'] ?? null;
		if ($slope !== null && $recent !== null && $recent['slope'] !== null
				&& $recent['slope'] > max($slope * 1.5, self::MIN_DISK_GROWTH_BYTES_DAY)) {
			$slope = $recent['slope'];
			$accelerating = true;
		}
		if ($slope !== null && $slope < self::MIN_DISK_GROWTH_BYTES_DAY) {
			$slope = null;
		}

		$eta = static function (?float $distance, ?float $rate): ?float {
			if ($distance === null) {
				return null;
			}
			if ($distance <= 0) {
				return 0.0;
			}
			if ($rate === null || !is_finite($rate) || $rate <= 0) {
				return null;
			}
			return $distance / $rate;
		};

		// pct-threshold ETA in the bytes domain (single derived series); the free-GB
		// candidates were folded into warn_used/crit_used for the window statistics,
		// so the per-basis distances are computed separately here.
		$days_warn_pct_only = ($capacity !== null && $spec['warn_pct'] !== null && $spec['used'] !== null)
			? $eta($capacity * $spec['warn_pct'] / 100 - $spec['used'], $slope)
			: null;
		$days_warn_gb = ($free_ref !== null && ($spec['warn_free'] ?? 0) > 0 && $spec['free'] !== null)
			? $eta($spec['free'] - $spec['warn_free'], $slope)
			: null;
		$days_crit_pct_only = ($capacity !== null && $spec['crit_pct'] !== null && $spec['used'] !== null)
			? $eta($capacity * $spec['crit_pct'] / 100 - $spec['used'], $slope)
			: null;
		$days_crit_gb = ($free_ref !== null && ($spec['crit_free'] ?? 0) > 0 && $spec['free'] !== null)
			? $eta($spec['free'] - $spec['crit_free'], $slope)
			: null;
		$days_full = $spec['free'] !== null ? $eta($spec['free'], $slope) : null;

		$min_eta = static function (array $candidates): array {
			$best = null;
			$basis = '';
			foreach ($candidates as [$label, $value]) {
				if ($value !== null && ($best === null || $value < $best)) {
					$best = $value;
					$basis = $label;
				}
			}
			return [$best, $basis];
		};
		[$days_warn, $warn_basis] = $min_eta([['used %', $days_warn_pct_only], ['free GB', $days_warn_gb]]);
		[$days_crit, $crit_basis] = $min_eta([['used %', $days_crit_pct_only], ['free GB', $days_crit_gb]]);
		[$days_next, $next_basis] = $min_eta([
			['warning used %', $days_warn_pct_only], ['warning free GB', $days_warn_gb],
			['critical used %', $days_crit_pct_only], ['critical free GB', $days_crit_gb]
		]);

		$current_warn = ($spec['pused'] !== null && $spec['warn_pct'] !== null && $spec['pused'] > $spec['warn_pct'])
			|| ($spec['free'] !== null && ($spec['warn_free'] ?? 0) > 0 && $spec['free'] < $spec['warn_free']);
		$current_crit = ($spec['pused'] !== null && $spec['crit_pct'] !== null && $spec['pused'] > $spec['crit_pct'])
			|| ($spec['free'] !== null && ($spec['crit_free'] ?? 0) > 0 && $spec['free'] < $spec['crit_free']);

		$severity = $this->diskSeverity($current_warn, $current_crit, $days_warn, $days_crit, $days_full,
			$confidence, $spec['ok']);

		return [
			'id' => $spec['id'],
			'status' => 'ok',
			'source' => $source,
			'windows' => $this->roundWindows($windows),
			'sel' => $selected_window,
			'confidence' => $confidence,
			'accelerating' => $accelerating,
			'growth_day' => $slope,
			'growth_pct_day' => ($slope !== null && $capacity !== null && $capacity > 0)
				? round($slope / $capacity * 100, 5)
				: null,
			'eta' => [
				'warn_days' => $this->roundDays($days_warn),
				'warn_date' => $this->dateAfter($now, $days_warn),
				'warn_basis' => $warn_basis,
				'crit_days' => $this->roundDays($days_crit),
				'crit_date' => $this->dateAfter($now, $days_crit),
				'crit_basis' => $crit_basis,
				'full_days' => $this->roundDays($days_full),
				'full_date' => $this->dateAfter($now, $days_full),
				'next_days' => $this->roundDays($days_next),
				'next_date' => $this->dateAfter($now, $days_next),
				'next_basis' => $next_basis
			],
			'severity' => $severity,
			'recommendation' => $this->diskRecommendation($severity),
			'series' => $this->downsampleSeries($rows)
		];
	}

	private function diskSeverity(bool $current_warn, bool $current_crit, ?float $days_warn, ?float $days_crit,
			?float $days_full, string $confidence, bool $data_ok): string {
		if ($current_crit) {
			return 'Critical';
		}
		if ($current_warn) {
			return 'High';
		}
		$next_critical = null;
		foreach ([$days_crit, $days_full] as $candidate) {
			if ($candidate !== null && ($next_critical === null || $candidate < $next_critical)) {
				$next_critical = $candidate;
			}
		}
		if ($next_critical === null && $days_warn === null) {
			return $data_ok ? 'Healthy' : 'Unknown';
		}

		// The critical-ETA and warning-ETA tiers are ranked independently and the
		// higher one wins, so a near warning is never demoted by a far critical.
		$from_critical = 'Healthy';
		if ($next_critical !== null) {
			if ($next_critical <= 7) {
				$from_critical = 'Critical';
			}
			elseif ($next_critical <= 30) {
				$from_critical = 'High';
			}
			elseif ($next_critical <= 90) {
				$from_critical = 'Medium';
			}
			elseif ($next_critical <= 180) {
				$from_critical = 'Watch';
			}
		}
		$from_warning = 'Healthy';
		if ($days_warn !== null) {
			if ($days_warn <= 7) {
				$from_warning = 'High';
			}
			elseif ($days_warn <= 30) {
				$from_warning = 'Medium';
			}
			elseif ($days_warn <= 90) {
				$from_warning = 'Watch';
			}
		}
		$proposed = self::SEVERITY_ORDER[$from_critical] >= self::SEVERITY_ORDER[$from_warning]
			? $from_critical
			: $from_warning;

		if ($confidence === 'Low' && self::SEVERITY_ORDER[$proposed] > self::SEVERITY_ORDER['Medium']) {
			return 'Medium';
		}
		return $proposed;
	}

	private function diskRecommendation(string $severity): string {
		switch ($severity) {
			case 'Critical':
				return _('Act now: validate the growth source, clean up safely or extend this filesystem. Confirm backup and application impact before changes.');
			case 'High':
				return _('Plan remediation in the current change window; identify the growth owner and decide between cleanup, retention changes or extension.');
			case 'Medium':
				return _('Create a capacity action and verify the forecast against application retention and expected projects.');
			case 'Watch':
				return _('Review growth monthly and investigate recent acceleration.');
			case 'Unknown':
				return _('Restore or extend Zabbix trend retention before making a capacity decision.');
			default:
				return _('No capacity action indicated; continue normal monitoring.');
		}
	}

	// -------------------------------------------------------------------------
	// CPU / memory forecast (baseline severity)
	// -------------------------------------------------------------------------

	private function forecastResource(array $spec, array $rows, string $source, int $now): array {
		$windows = $this->summarizeWindows($rows, $now, $spec['warn'], $spec['crit']);
		$selected_window = $this->selectResourceWindow($windows);
		$selected = $selected_window !== null ? $windows[$selected_window] : null;

		$severity = $this->resourceSeverity($spec, $selected, $windows);

		return [
			'id' => $spec['id'],
			'status' => 'ok',
			'source' => $source,
			'windows' => $this->roundWindows($windows),
			'sel' => $selected_window,
			'growth_pct_day' => $selected !== null && $selected['slope'] !== null
				? round($selected['slope'], 5)
				: null,
			'severity' => $severity,
			'recommendation' => $this->resourceRecommendation($spec['rtype'] ?? '', $severity),
			'series' => $this->downsampleSeries($rows)
		];
	}

	private function selectResourceWindow(array $windows): ?string {
		foreach ([['1m', 21, 55.0], ['3m', 60, 45.0], ['1w', 5, 55.0], ['6m', 90, 40.0], ['12m', 180, 40.0]]
				as [$window, $min_days, $min_cov]) {
			$stats = $windows[$window] ?? null;
			if ($stats !== null && $stats['days'] >= $min_days && $stats['cov'] >= $min_cov) {
				return $window;
			}
		}
		$best = null;
		$best_days = -1;
		foreach ($windows as $window => $stats) {
			if ($stats['n'] > 0 && $stats['days'] > $best_days) {
				$best = $window;
				$best_days = $stats['days'];
			}
		}
		return $best;
	}

	private function resourceSeverity(array $spec, ?array $stats, array $windows): string {
		if ($stats === null) {
			return 'Unknown';
		}
		$warn = $spec['warn'];
		$crit = $spec['crit'];
		if ($crit !== null && (($stats['avg'] !== null && $stats['avg'] >= $crit)
				|| (($stats['above_crit'] ?? 0) >= 10 && ($stats['p95'] ?? 0) >= $crit))) {
			return 'Critical';
		}
		if (($crit !== null && ($stats['p95'] ?? 0) >= $crit && ($stats['above_crit'] ?? 0) >= 2)
				|| ($warn !== null && ($stats['above_warn'] ?? 0) >= 20 && ($stats['p95'] ?? 0) >= $warn)) {
			return 'High';
		}
		if ($warn !== null && ($stats['p95'] ?? 0) >= $warn && ($stats['above_warn'] ?? 0) >= 5) {
			return 'Medium';
		}
		if ($warn !== null && $spec['current'] !== null && $spec['current'] >= $warn) {
			return 'Watch';
		}
		$one_month = $windows['1m'] ?? null;
		$three_months = $windows['3m'] ?? null;
		if ($one_month !== null && $three_months !== null
				&& $one_month['avg'] !== null && $three_months['avg'] !== null && $three_months['avg'] > 0
				&& $one_month['avg'] > $three_months['avg'] * 1.2
				&& $warn !== null && ($one_month['p95'] ?? 0) >= $warn * 0.85) {
			return 'Watch';
		}
		return 'Healthy';
	}

	private function resourceRecommendation(string $rtype, string $severity): string {
		if ($severity === 'Critical' || $severity === 'High') {
			return $rtype === 'CPU'
				? _('Confirm sustained workload, run queue and top processes. Optimize or reschedule first; add vCPU only when legitimate demand remains constrained.')
				: _('Confirm sustained pressure with processes, paging/swap and application behavior. Fix leaks or tuning first; add RAM when legitimate working-set demand remains.');
		}
		switch ($severity) {
			case 'Medium':
				return _('Create a capacity review and validate the next maintenance-window requirement.');
			case 'Watch':
				return _('Track the recent baseline change; a single high sample is not an upgrade decision.');
			case 'Unknown':
				return _('Restore item collection or trend retention before sizing this resource.');
			default:
				return _('No resource expansion indicated; continue normal monitoring.');
		}
	}

	// -------------------------------------------------------------------------
	// Payload helpers
	// -------------------------------------------------------------------------

	private function roundWindows(array $windows): array {
		$out = [];
		foreach ($windows as $window => $s) {
			$out[$window] = [
				'days' => $s['days'],
				'cov' => round($s['cov'], 1),
				'n' => $s['n'],
				'avg' => $s['avg'] !== null ? round($s['avg'], 4) : null,
				'p95' => $s['p95'] !== null ? round($s['p95'], 4) : null,
				'peak' => $s['peak'] !== null ? round($s['peak'], 4) : null,
				'slope' => $s['slope'] !== null ? round($s['slope'], 6) : null,
				'r2' => $s['r2'] !== null ? round($s['r2'], 3) : null,
				'above_warn' => $s['above_warn'] !== null ? round($s['above_warn'], 2) : null,
				'above_crit' => $s['above_crit'] !== null ? round($s['above_crit'], 2) : null,
				'first' => $s['first'],
				'last' => $s['last']
			];
		}
		return $out;
	}

	private function roundDays(?float $days): ?float {
		if ($days === null || !is_finite($days)) {
			return null;
		}
		return round($days, 2);
	}

	private function dateAfter(int $now, ?float $days): ?int {
		if ($days === null || !is_finite($days) || $days < 0 || $days > 3650) {
			return null;
		}
		return $now + (int) round($days * 86400);
	}

	/**
	 * Chart-ready daily min/avg/max series, capped at SERIES_MAX_POINTS points.
	 *
	 * @return array<int, array{0: int, 1: float, 2: float, 3: float}>
	 */
	private function downsampleSeries(array $rows): array {
		$daily = [];
		foreach ($rows as $row) {
			$day = $row['clock'] - ($row['clock'] % 86400);
			$w = max(1, $row['num']);
			if (!isset($daily[$day])) {
				$daily[$day] = ['sum' => 0.0, 'n' => 0, 'min' => $row['min'], 'max' => $row['max']];
			}
			$daily[$day]['sum'] += $row['avg'] * $w;
			$daily[$day]['n'] += $w;
			$daily[$day]['min'] = min($daily[$day]['min'], $row['min']);
			$daily[$day]['max'] = max($daily[$day]['max'], $row['max']);
		}
		ksort($daily);

		$group = max(1, (int) ceil(count($daily) / self::SERIES_MAX_POINTS));
		$points = [];
		$bucket = null;
		$index = 0;
		foreach ($daily as $day => $d) {
			if ($bucket === null) {
				$bucket = ['clock' => $day, 'sum' => 0.0, 'n' => 0, 'min' => $d['min'], 'max' => $d['max']];
			}
			$bucket['sum'] += $d['sum'];
			$bucket['n'] += $d['n'];
			$bucket['min'] = min($bucket['min'], $d['min']);
			$bucket['max'] = max($bucket['max'], $d['max']);
			$index++;
			if ($index % $group === 0) {
				$points[] = [$bucket['clock'], round($bucket['min'], 4),
					round($bucket['sum'] / max(1, $bucket['n']), 4), round($bucket['max'], 4)];
				$bucket = null;
			}
		}
		if ($bucket !== null) {
			$points[] = [$bucket['clock'], round($bucket['min'], 4),
				round($bucket['sum'] / max(1, $bucket['n']), 4), round($bucket['max'], 4)];
		}
		return $points;
	}

	private function safeFloat($value): ?float {
		if ($value === null || $value === '' || !is_numeric($value)) {
			return null;
		}
		$value = (float) $value;
		return is_finite($value) ? $value : null;
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
