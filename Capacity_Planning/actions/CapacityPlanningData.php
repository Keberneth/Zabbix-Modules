<?php

declare(strict_types=1);

namespace Modules\CapacityPlanning\Actions;

use API;
use CController;
use CControllerResponseData;
use Modules\CapacityPlanning\Lib\Config;
use Modules\CapacityPlanning\Lib\SeriesCache;
use Modules\CapacityPlanning\Lib\SeriesRangeIncompleteException;

require_once dirname(__DIR__).'/lib/Config.php';
require_once dirname(__DIR__).'/lib/SeriesCache.php';

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
	private const FILTER_QUERY_LIMIT = self::MAX_FILTER_HOSTS + 1;
	private const MAX_FILTER_FIELD_BYTES = 2048;
	private const MAX_FILTER_TERM_BYTES = 256;
	private const MAX_FILTER_TERMS = 20;
	private const MAX_FILTER_REGEX_TERMS = 5;
	private const MAX_FILTER_SAMPLES = 12;
	private const MAX_FILTER_TERM_SAMPLES = 6;
	private const FILTER_REGEX_FLAGS = 'imsux';
	private const MAX_HOSTS = 1000;
	private const MAX_ITEMS = 30000;
	private const MAX_FINDINGS = 3000;
	private const MAX_QUALITY_ISSUES = 400;
	private const HOST_CHUNK = 200;
	private const MACRO_ENTITY_CHUNK = 500;
	private const TEMPLATE_TOPOLOGY_CHUNK = 500;
	private const MAX_TEMPLATE_TOPOLOGY_ENTITIES = 25000;

	private const MAX_LOOKBACK_DAYS = 730;
	private const FORECAST_BATCH_MAX = 10;
	private const RESOURCE_FORECAST_BATCH_MAX = 2;
	private const MAX_TREND_ROWS = 20000;
	private const MAX_HISTORY_ROWS = 50000;
	private const HISTORY_FETCH_BATCH = 10000;
	private const HISTORY_FALLBACK_DAYS = 7;
	private const RESOURCE_HISTORY_DAYS = 31;
	private const RECENT_RESOURCE_BUCKET_SECONDS = 5 * 60;
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

	// Resource saturation policy. Maxima are observations only; a confirmed
	// episode requires a complete, sufficiently covered bucket whose minimum
	// stayed above the saturation threshold.
	private const CPU_SATURATION_PCT = 95.0;
	private const MEMORY_SATURATION_PCT = 95.0;
	private const RESOURCE_NEAR_FULL_PCT = 99.0;
	private const SATURATION_WINDOW_DAYS = 31;
	private const SATURATION_MIN_BUCKET_COVERAGE_PCT = 75.0;
	private const SATURATION_MIN_EPISODE_MINUTES = 15;
	private const SATURATION_LONG_EPISODE_MINUTES = 30;
	private const SATURATION_REPEATED_EPISODE_COUNT = 3;
	private const SATURATION_REPEATED_DAYS = 3;
	private const SATURATION_HIGH_TOTAL_MINUTES = 120;
	private const SATURATION_CRITICAL_EPISODE_MINUTES = 60;
	private const SATURATION_CRITICAL_TOTAL_MINUTES = 360;
	private const SATURATION_PEAK_WATCH_COUNT = 6;
	private const SATURATION_PEAK_WATCH_DAYS = 2;
	private const RESOURCE_REGIME_MIN_COVERAGE_PCT = 70.0;
	private const RESOURCE_REGIME_MIN_DELTA_PCT_POINTS = 10.0;
	private const RESOURCE_REGIME_MIN_RELATIVE_PCT = 20.0;

	// Nested analysis windows: key => days. Order matters (longest first).
	private const WINDOWS = ['12m' => 365, '6m' => 183, '3m' => 92, '1m' => 31, '2w' => 14, '1w' => 7];
	private const RESOURCE_WINDOW_REQUIREMENTS = [
		'1w' => [5, 60.0], '2w' => [10, 60.0], '1m' => [21, 55.0],
		'3m' => [60, 45.0], '6m' => [120, 40.0], '12m' => [180, 40.0]
	];
	private const CONFIDENCE_ORDER = ['None' => -1, 'Low' => 0, 'Medium' => 1, 'High' => 2];

	private const SEVERITY_ORDER = [
		'Unknown' => -1, 'Healthy' => 0, 'Watch' => 1, 'Medium' => 2, 'High' => 3, 'Critical' => 4
	];

	/** @var array<int, array{sev: string, host: string, resource: string, issue: string, detail: string}> */
	private array $quality = [];
	private ?SeriesCache $series_cache = null;
	private bool $force_series_refresh = false;
	private array $cache_request_meta = [
		'requests' => 0,
		'shard_hits' => 0,
		'shard_misses' => 0,
		'shards_written' => 0,
		'live_fallbacks' => 0,
		'reasons' => []
	];
	private array $cache_runtime_meta = [];

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
			'specs' => 'string',
			'refresh' => 'in 0,1',
			self::csrfTokenField() => 'string'
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
		$raw_filters = [
			'group' => (string) $this->getInput('group', ''),
			'host' => (string) $this->getInput('host', ''),
			'template' => (string) $this->getInput('template', '')
		];
		$labels = ['group' => _('Host group'), 'host' => _('Host'), 'template' => _('Template')];
		$filters = [];

		// Parse and validate every field before making an API request. In addition
		// to giving immediate UI feedback, this keeps malformed or oversized regex
		// input away from both the Zabbix API and PCRE evaluation.
		foreach ($raw_filters as $field => $raw) {
			$parsed = $this->parseFilterExpression($raw);
			if ($parsed['error'] !== null) {
				return $this->scopeFilterError($field,
					sprintf(_('%1$s filter: %2$s'), $labels[$field], $parsed['error']));
			}
			$filters[$field] = $parsed['terms'];
		}
		$set_error = $this->validateFilterSet($filters);
		if ($set_error !== null) {
			return $this->scopeFilterError('scope', $set_error);
		}

		$group_matches = [];
		$template_matches = [];
		$host_matches = [];
		$group_term_samples = [];
		$template_term_samples = [];
		$host_term_samples = [];
		$group_truncated = false;
		$template_truncated = false;
		$host_truncated = false;
		$notes = [];
		$evaluating_field = null;

		try {
			if ($filters['group']) {
				$evaluating_field = 'group';
				[$group_matches, $group_truncated, $group_term_samples] =
					$this->resolveGroupFilterTerms($filters['group']);
				$notes[] = $this->filterCountSummary(_('host group(s)'), $group_matches, $group_truncated);
			}

			if ($filters['template']) {
				$evaluating_field = 'template';
				[$template_matches, $template_truncated, $template_term_samples] =
					$this->resolveTemplateFilterTerms($filters['template']);
				$notes[] = $this->filterCountSummary(_('template(s)'), $template_matches, $template_truncated);
			}

			// A capped group/template match set cannot safely become an allow-list:
			// downstream Host.get would silently omit valid hosts. Return samples for
			// type-ahead, but fail closed until the operator narrows the expression.
			if ($group_truncated || $template_truncated) {
				return $this->blockedScopeResolution(
					$group_matches, [], $template_matches, [],
					$group_term_samples, [], $template_term_samples, [], $notes,
					['groups' => $group_truncated, 'hosts' => false,
						'templates' => $template_truncated, 'resolved_hosts' => false,
						'hosts_available' => false, 'resolved_hosts_available' => false]
				);
			}

			$groupids = array_keys($group_matches);
			$templateids = array_keys($template_matches);
			$empty = ($filters['group'] && !$groupids) || ($filters['template'] && !$templateids);

			// Host/name constraints are resolved together with group and template
			// constraints. This preserves AND semantics before the cap is applied;
			// independently capped host sets could otherwise create false negatives.
			if (!$empty && ($filters['host'] || $filters['template'])) {
				$evaluating_field = 'host';
				[$host_matches, $host_truncated, $host_term_samples] = $this->resolveHostFilterTerms(
					$filters['host'], $groupids, $templateids
				);
				$notes[] = $this->filterCountSummary(_('host(s)'), $host_matches, $host_truncated);
				$empty = !$host_matches;
			}
		}
		catch (\UnexpectedValueException $e) {
			return $this->scopeFilterError($evaluating_field ?? 'scope', $e->getMessage());
		}

		if ($host_truncated) {
			return $this->blockedScopeResolution(
				$group_matches, $host_matches, $template_matches, $host_matches,
				$group_term_samples, $host_term_samples, $template_term_samples,
				$host_term_samples, $notes,
				['groups' => false, 'hosts' => true, 'templates' => false, 'resolved_hosts' => true,
					'hosts_available' => true, 'resolved_hosts_available' => true]
			);
		}

		$groupids = array_keys($group_matches);
		$hostids = array_keys($host_matches);
		$this->sortIds($groupids);
		$this->sortIds($hostids);

		return [
			'groupids' => $groupids,
			'hostids' => $hostids,
			'empty' => $empty,
			'truncated' => false,
			'blocked' => false,
			'summary' => $notes ? implode(' • ', $notes) : '',
			'preview' => [
				'groups' => $this->buildFilterPreview($group_matches, false, $group_term_samples),
				'hosts' => ($filters['host'] || $filters['template'] || $empty)
					? $this->buildFilterPreview($host_matches, false, $host_term_samples)
					: $this->unavailableFilterPreview(),
				'templates' => $this->buildFilterPreview(
					$template_matches, false, $template_term_samples
				),
				'resolved_hosts' => ($filters['host'] || $filters['template'] || $empty)
					? $this->buildFilterPreview($host_matches, false, $host_term_samples)
					: $this->unavailableFilterPreview()
			]
		];
	}

	/**
	 * Split a comma-separated scope expression. Commas inside /regex/flags or
	 * escaped as \, remain part of the term. In a plain value, \\ represents one
	 * backslash and a leading \/ represents a literal leading slash. Plain values
	 * use contains matching; slash-delimited values compile to bounded PCRE.
	 *
	 * @return array{terms: array<int, array{kind: string, value: string, pattern?: string}>, error: ?string}
	 */
	private function parseFilterExpression(string $raw): array {
		if (strlen($raw) > self::MAX_FILTER_FIELD_BYTES) {
			return ['terms' => [], 'error' => sprintf(
				_('is too long (maximum %1$d bytes).'), self::MAX_FILTER_FIELD_BYTES
			)];
		}

		$parts = $this->splitFilterExpression($raw);
		$terms = [];
		$regex_count = 0;
		foreach ($parts as $part) {
			$part = trim($part);
			if ($part === '') {
				continue;
			}
			if (count($terms) >= self::MAX_FILTER_TERMS) {
				return ['terms' => [], 'error' => sprintf(
					_('contains more than %1$d values.'), self::MAX_FILTER_TERMS
				)];
			}
			if (strlen($part) > self::MAX_FILTER_TERM_BYTES) {
				return ['terms' => [], 'error' => sprintf(
					_('contains a value longer than %1$d bytes.'), self::MAX_FILTER_TERM_BYTES
				)];
			}

			if ($part[0] === '/') {
				$regex = $this->parseRegexFilterTerm($part);
				if ($regex['error'] !== null) {
					return ['terms' => [], 'error' => $regex['error']];
				}
				if ($regex_count >= self::MAX_FILTER_REGEX_TERMS) {
					return ['terms' => [], 'error' => sprintf(
						_('contains more than %1$d regular expressions.'),
						self::MAX_FILTER_REGEX_TERMS
					)];
				}
				$terms[] = ['kind' => 'regex', 'value' => $part, 'pattern' => $regex['pattern']];
				$regex_count++;
			}
			else {
				$literal = $this->decodeLiteralFilterTerm($part);
				$terms[] = ['kind' => 'literal', 'value' => $literal];
			}
		}
		if (!$terms && trim($raw) !== '') {
			return ['terms' => [], 'error' => _('does not contain a value to match.')];
		}

		return ['terms' => $terms, 'error' => null];
	}

	private function validateFilterSet(array $filters): ?string {
		$term_count = 0;
		$regex_count = 0;
		foreach ($filters as $terms) {
			$term_count += count($terms);
			foreach ($terms as $term) {
				$regex_count += $term['kind'] === 'regex' ? 1 : 0;
			}
		}
		if ($term_count > self::MAX_FILTER_TERMS) {
			return sprintf(
				_('Inventory scope contains more than %1$d values in total.'), self::MAX_FILTER_TERMS
			);
		}
		if ($regex_count > self::MAX_FILTER_REGEX_TERMS) {
			return sprintf(
				_('Inventory scope contains more than %1$d regular expressions in total.'),
				self::MAX_FILTER_REGEX_TERMS
			);
		}
		return null;
	}

	/** @return string[] */
	private function splitFilterExpression(string $raw): array {
		$parts = [];
		$buffer = '';
		$in_regex = false;
		$escaped = false;
		$term_started = false;

		for ($i = 0, $length = strlen($raw); $i < $length; $i++) {
			$char = $raw[$i];
			if ($escaped) {
				// Preserve the escape for the parser. Outside regex it distinguishes
				// literal comma/backslash and a literal leading slash from syntax.
				$buffer .= '\\'.$char;
				$escaped = false;
				$term_started = $term_started || !ctype_space($char);
				continue;
			}
			if ($char === '\\') {
				$escaped = true;
				continue;
			}
			if (!$in_regex && !$term_started && $char === '/') {
				$in_regex = true;
			}
			elseif ($in_regex && $char === '/') {
				$in_regex = false;
			}
			if (!$in_regex && $char === ',') {
				$parts[] = $buffer;
				$buffer = '';
				$term_started = false;
				continue;
			}
			$buffer .= $char;
			$term_started = $term_started || !ctype_space($char);
		}
		if ($escaped) {
			$buffer .= '\\';
		}
		$parts[] = $buffer;

		return $parts;
	}

	private function decodeLiteralFilterTerm(string $term): string {
		$out = '';
		for ($i = 0, $length = strlen($term); $i < $length; $i++) {
			if ($term[$i] === '\\' && $i + 1 < $length
					&& in_array($term[$i + 1], [',', '\\', '/'], true)) {
				$out .= $term[++$i];
			}
			else {
				$out .= $term[$i];
			}
		}
		return $out;
	}

	/** @return array{pattern: string, error: ?string} */
	private function parseRegexFilterTerm(string $term): array {
		$closing = null;
		$escaped = false;
		for ($i = 1, $length = strlen($term); $i < $length; $i++) {
			$char = $term[$i];
			if ($escaped) {
				$escaped = false;
				continue;
			}
			if ($char === '\\') {
				$escaped = true;
				continue;
			}
			if ($char === '/') {
				$closing = $i;
				break;
			}
		}

		if ($closing === null) {
			return ['pattern' => '', 'error' => _('contains an unclosed regular expression.')];
		}
		$body = substr($term, 1, $closing - 1);
		$flags = substr($term, $closing + 1);
		if ($body === '') {
			return ['pattern' => '', 'error' => _('contains an empty regular expression.')];
		}
		if (strlen($body) > self::MAX_FILTER_TERM_BYTES) {
			return ['pattern' => '', 'error' => sprintf(
				_('contains a regular expression longer than %1$d bytes.'), self::MAX_FILTER_TERM_BYTES
			)];
		}
		if ($flags !== '' && strspn($flags, self::FILTER_REGEX_FLAGS) !== strlen($flags)) {
			return ['pattern' => '', 'error' => sprintf(
				_('uses unsupported regular-expression flags; allowed flags are %1$s.'),
				self::FILTER_REGEX_FLAGS
			)];
		}
		if (count(array_unique(str_split($flags))) !== strlen($flags)) {
			return ['pattern' => '', 'error' => _('repeats a regular-expression flag.')];
		}

		$delimiter = $this->chooseRegexDelimiter($body);
		$pattern = $delimiter.$this->escapeRegexDelimiter($body, $delimiter).$delimiter.$flags;
		if (@preg_match($pattern, '') === false) {
			return ['pattern' => '', 'error' => _('contains an invalid regular expression.')];
		}

		return ['pattern' => $pattern, 'error' => null];
	}

	private function chooseRegexDelimiter(string $body): string {
		foreach (['~', '#', '%', '!', ';', '@'] as $delimiter) {
			if (strpos($body, $delimiter) === false) {
				return $delimiter;
			}
		}
		return '~';
	}

	private function escapeRegexDelimiter(string $body, string $delimiter): string {
		$out = '';
		$backslashes = 0;
		for ($i = 0, $length = strlen($body); $i < $length; $i++) {
			$char = $body[$i];
			if ($char === $delimiter && $backslashes % 2 === 0) {
				$out .= '\\';
			}
			$out .= $char;
			$backslashes = $char === '\\' ? $backslashes + 1 : 0;
		}
		return $out;
	}

	private function lowerFilterValue(string $value): string {
		return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
	}

	private function matchesFilterValues(array $values, array $terms): bool {
		if (!$terms) {
			return true;
		}
		foreach ($terms as $term) {
			foreach ($values as $value) {
				$value = (string) $value;
				if ($term['kind'] === 'literal') {
					$matched = function_exists('mb_stripos')
						? mb_stripos($value, $term['value'], 0, 'UTF-8') !== false
						: stripos($value, $term['value']) !== false;
					if ($matched) {
						return true;
					}
				}
				else {
					$result = @preg_match($term['pattern'], $value);
					if ($result === false) {
						throw new \UnexpectedValueException(
							_('A regular expression could not be evaluated safely; narrow or simplify it.')
						);
					}
					if ($result === 1) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/** @return array{0: array<string, array{id: string, label: string}>, 1: bool, 2: array} */
	private function resolveGroupFilterTerms(array $terms): array {
		$matches = [];
		$term_samples = [];
		$truncated = false;
		$literal_cache = [];
		foreach ($this->filterTermsByKind($terms, 'literal') as $index => $term) {
			$cache_key = $this->lowerFilterValue($term['value']);
			if (!array_key_exists($cache_key, $literal_cache)) {
				$literal_cache[$cache_key] = API::HostGroup()->get([
					'output' => ['groupid', 'name'],
					'search' => ['name' => $term['value']],
					'limit' => self::FILTER_QUERY_LIMIT
				]) ?: [];
			}
			$rows = $literal_cache[$cache_key];
			$term_matches = [];
			$term_truncated = $this->mergeApiFilterRows(
				$term_matches, $rows, 'groupid', ['name'], [$term]
			);
			$truncated = $this->mergeApiFilterRows($matches, $rows, 'groupid', ['name'], [$term])
				|| $term_truncated || $truncated;
			$term_samples[$index] = $this->buildFilterTermSample(
				$index, $term, $term_matches, $term_truncated
			);
		}

		$regex_terms = $this->filterTermsByKind($terms, 'regex');
		if ($regex_terms) {
			$rows = API::HostGroup()->get([
				'output' => ['groupid', 'name'], 'limit' => self::FILTER_QUERY_LIMIT
			]) ?: [];
			$source_truncated = count($rows) >= self::FILTER_QUERY_LIMIT;
			$truncated = $this->mergeApiFilterRows(
				$matches, $rows, 'groupid', ['name'], $regex_terms
			) || $source_truncated || $truncated;
			foreach ($regex_terms as $index => $term) {
				$term_matches = [];
				$this->mergeApiFilterRows(
					$term_matches, $rows, 'groupid', ['name'], [$term]
				);
				$term_samples[$index] = $this->buildFilterTermSample(
					$index, $term, $term_matches, $source_truncated
				);
			}
		}

		ksort($term_samples);
		return [$matches, $truncated, array_values($term_samples)];
	}

	/** @return array{0: array<string, array{id: string, label: string}>, 1: bool, 2: array} */
	private function resolveTemplateFilterTerms(array $terms): array {
		$matches = [];
		$term_samples = [];
		$truncated = false;
		$literal_cache = [];
		foreach ($this->filterTermsByKind($terms, 'literal') as $index => $term) {
			$cache_key = $this->lowerFilterValue($term['value']);
			if (!array_key_exists($cache_key, $literal_cache)) {
				$literal_cache[$cache_key] = API::Template()->get([
					'output' => ['templateid', 'host', 'name'],
					'search' => ['host' => $term['value'], 'name' => $term['value']],
					'searchByAny' => true,
					'limit' => self::FILTER_QUERY_LIMIT
				]) ?: [];
			}
			$rows = $literal_cache[$cache_key];
			$term_matches = [];
			$term_truncated = $this->mergeApiFilterRows(
				$term_matches, $rows, 'templateid', ['name', 'host'], [$term]
			);
			$truncated = $this->mergeApiFilterRows(
				$matches, $rows, 'templateid', ['name', 'host'], [$term]
			) || $term_truncated || $truncated;
			$term_samples[$index] = $this->buildFilterTermSample(
				$index, $term, $term_matches, $term_truncated
			);
		}

		$regex_terms = $this->filterTermsByKind($terms, 'regex');
		if ($regex_terms) {
			$rows = API::Template()->get([
				'output' => ['templateid', 'host', 'name'], 'limit' => self::FILTER_QUERY_LIMIT
			]) ?: [];
			$source_truncated = count($rows) >= self::FILTER_QUERY_LIMIT;
			$truncated = $this->mergeApiFilterRows(
				$matches, $rows, 'templateid', ['name', 'host'], $regex_terms
			) || $source_truncated || $truncated;
			foreach ($regex_terms as $index => $term) {
				$term_matches = [];
				$this->mergeApiFilterRows(
					$term_matches, $rows, 'templateid', ['name', 'host'], [$term]
				);
				$term_samples[$index] = $this->buildFilterTermSample(
					$index, $term, $term_matches, $source_truncated
				);
			}
		}

		ksort($term_samples);
		return [$matches, $truncated, array_values($term_samples)];
	}

	/** @return array{0: array<string, array{id: string, label: string}>, 1: bool, 2: array} */
	private function resolveHostFilterTerms(array $terms, array $groupids, array $templateids): array {
		$matches = [];
		$term_samples = [];
		$truncated = false;
		$base = [
			'output' => ['hostid', 'host', 'name'],
			'monitored_hosts' => true,
			'limit' => self::FILTER_QUERY_LIMIT
		];
		if ($groupids) {
			$base['groupids'] = $groupids;
		}
		if ($templateids) {
			$base['templateids'] = $templateids;
		}

		$literals = $this->filterTermsByKind($terms, 'literal');
		$literal_cache = [];
		foreach ($literals as $index => $term) {
			$cache_key = $this->lowerFilterValue($term['value']);
			if (!array_key_exists($cache_key, $literal_cache)) {
				$params = $base;
				$params['search'] = ['host' => $term['value'], 'name' => $term['value']];
				$params['searchByAny'] = true;
				$literal_cache[$cache_key] = API::Host()->get($params) ?: [];
			}
			$rows = $literal_cache[$cache_key];
			$term_matches = [];
			$term_truncated = $this->mergeApiFilterRows(
				$term_matches, $rows, 'hostid', ['name', 'host'], [$term]
			);
			$truncated = $this->mergeApiFilterRows(
				$matches, $rows, 'hostid', ['name', 'host'], [$term]
			) || $term_truncated || $truncated;
			$term_samples[$index] = $this->buildFilterTermSample(
				$index, $term, $term_matches, $term_truncated
			);
		}

		$regex_terms = $this->filterTermsByKind($terms, 'regex');
		if ($regex_terms || !$terms) {
			$rows = API::Host()->get($base) ?: [];
			$source_truncated = count($rows) >= self::FILTER_QUERY_LIMIT;
			$truncated = $this->mergeApiFilterRows(
				$matches, $rows, 'hostid', ['name', 'host'], $regex_terms
			) || $source_truncated || $truncated;
			foreach ($regex_terms as $index => $term) {
				$term_matches = [];
				$this->mergeApiFilterRows(
					$term_matches, $rows, 'hostid', ['name', 'host'], [$term]
				);
				$term_samples[$index] = $this->buildFilterTermSample(
					$index, $term, $term_matches, $source_truncated
				);
			}
		}

		ksort($term_samples);
		return [$matches, $truncated, array_values($term_samples)];
	}

	/** @return array<int, array> */
	private function filterTermsByKind(array $terms, string $kind): array {
		return array_filter($terms, static fn (array $term): bool => $term['kind'] === $kind);
	}

	/**
	 * Merge API-visible rows into a bounded id/name map. The API query itself is
	 * capped at MAX+1; retaining that sentinel lets callers distinguish exactly
	 * MAX matches from an incomplete result without an extra count query.
	 */
	private function mergeApiFilterRows(
		array &$target,
		array $rows,
		string $id_key,
		array $name_keys,
		array $terms
	): bool {
		$truncated = count($rows) >= self::FILTER_QUERY_LIMIT;
		foreach ($rows as $row) {
			$id = (string) ($row[$id_key] ?? '');
			if ($id === '') {
				continue;
			}
			$values = [];
			foreach ($name_keys as $key) {
				$value = trim((string) ($row[$key] ?? ''));
				if ($value !== '') {
					$values[] = $value;
				}
			}
			if (!$values || !$this->matchesFilterValues($values, $terms)) {
				continue;
			}
			if (!isset($target[$id]) && count($target) >= self::FILTER_QUERY_LIMIT) {
				$truncated = true;
				continue;
			}
			$target[$id] = ['id' => $id, 'label' => $values[0]];
		}
		if (count($target) >= self::FILTER_QUERY_LIMIT) {
			$truncated = true;
		}
		return $truncated;
	}

	private function filterCountSummary(string $label, array $matches, bool $lower_bound): string {
		return $lower_bound
			? sprintf(_('at least %1$d %2$s'), count($matches), $label)
			: sprintf(_('%1$d %2$s'), count($matches), $label);
	}

	private function buildFilterTermSample(
		int $index,
		array $term,
		array $matches,
		bool $lower_bound
	): array {
		return [
			'index' => $index,
			'kind' => $term['kind'],
			'value' => $term['value'],
			'count' => count($matches),
			'count_is_lower_bound' => $lower_bound,
			'samples' => $this->sortFilterSamples($matches, self::MAX_FILTER_TERM_SAMPLES)
		];
	}

	private function sortFilterSamples(array $matches, int $limit): array {
		$samples = array_values($matches);
		usort($samples, static fn (array $a, array $b): int =>
			strnatcasecmp($a['label'], $b['label']) ?: strnatcasecmp($a['id'], $b['id']));
		return array_slice($samples, 0, $limit);
	}

	private function buildFilterPreview(array $matches, bool $lower_bound, array $term_samples = []): array {
		$active = $term_samples ? $term_samples[count($term_samples) - 1]['samples'] : [];
		return [
			'available' => true,
			'count' => count($matches),
			'count_is_lower_bound' => $lower_bound,
			'samples' => $this->sortFilterSamples($matches, self::MAX_FILTER_SAMPLES),
			'active_samples' => $active,
			'term_samples' => $term_samples
		];
	}

	private function unavailableFilterPreview(): array {
		return [
			'available' => false,
			'count' => null,
			'count_is_lower_bound' => false,
			'samples' => [],
			'active_samples' => [],
			'term_samples' => []
		];
	}

	private function blockedScopeResolution(
		array $groups,
		array $hosts,
		array $templates,
		array $resolved_hosts,
		array $group_term_samples,
		array $host_term_samples,
		array $template_term_samples,
		array $resolved_host_term_samples,
		array $notes,
		array $lower_bounds
	): array {
		$notes[] = _('Scope resolution exceeded its safety limit; narrow the filter before applying it.');
		return [
			// Never expose a partial allow-list as safe to apply.
			'groupids' => [],
			'hostids' => [],
			'empty' => true,
			'truncated' => true,
			'blocked' => true,
			'summary' => implode(' • ', $notes),
			'preview' => [
				'groups' => $this->buildFilterPreview(
					$groups, (bool) $lower_bounds['groups'], $group_term_samples
				),
				'hosts' => $lower_bounds['hosts_available']
					? $this->buildFilterPreview($hosts, (bool) $lower_bounds['hosts'], $host_term_samples)
					: $this->unavailableFilterPreview(),
				'templates' => $this->buildFilterPreview(
					$templates, (bool) $lower_bounds['templates'], $template_term_samples
				),
				'resolved_hosts' => $lower_bounds['resolved_hosts_available']
					? $this->buildFilterPreview(
						$resolved_hosts, (bool) $lower_bounds['resolved_hosts'],
						$resolved_host_term_samples
					)
					: $this->unavailableFilterPreview()
			]
		];
	}

	private function scopeFilterError(string $field, string $message): array {
		return ['error' => ['code' => 400, 'message' => $message, 'field' => $field]];
	}

	private function sortIds(array &$ids): void {
		usort($ids, static fn (string $a, string $b): int => strnatcasecmp($a, $b));
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
				'facets' => ['hosts' => [], 'hostgroups' => []],
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
			'facets' => $this->buildInventoryFacets($hosts),
			'meta' => [
				'hosts_analyzed' => count($hosts),
				'generated_at' => $now,
				'hosts_truncated' => $hosts_truncated,
				'items_truncated' => $items_truncated,
				'findings_truncated' => $findings_truncated,
				'quality_truncated' => count($this->quality) > self::MAX_QUALITY_ISSUES,
				'forecast_batch_max' => self::FORECAST_BATCH_MAX,
				'resource_forecast_batch_max' => self::RESOURCE_FORECAST_BATCH_MAX,
				'max_lookback_days' => self::MAX_LOOKBACK_DAYS
			]
		]);
	}

	/**
	 * Return compact, permission-filtered host metadata once per inventory
	 * response. Findings reference this data by hostid so group names do not have
	 * to be repeated on every filesystem and CPU/memory row.
	 */
	private function buildInventoryFacets(array $hosts): array {
		$facet_hosts = [];
		$hostgroups = [];

		foreach ($hosts as $hostid => $host) {
			$groups = array_values(array_filter(array_map('strval', $host['groups'] ?? []),
				static fn (string $name): bool => $name !== ''));
			natcasesort($groups);
			$groups = array_values($groups);
			foreach ($groups as $group) {
				$hostgroups[$group] = true;
			}

			$facet_hosts[] = [
				'hostid' => (string) $hostid,
				'name' => (string) ($host['name'] ?? $host['host'] ?? $hostid),
				'groups' => $groups,
				'os' => (string) ($host['os'] ?? 'Unknown'),
				'maintenance' => is_array($host['maintenance'] ?? null)
					? $host['maintenance']
					: $this->normalizeHostMaintenance([])
			];
		}

		usort($facet_hosts, static fn (array $a, array $b): int =>
			strnatcasecmp($a['name'], $b['name']) ?: strnatcasecmp($a['hostid'], $b['hostid']));
		$group_names = array_keys($hostgroups);
		natcasesort($group_names);

		return ['hosts' => $facet_hosts, 'hostgroups' => array_values($group_names)];
	}

	/**
	 * @return array{0: array<string, array>, 1: bool} hosts keyed by hostid, truncated flag
	 */
	private function fetchHosts(array $groupids, array $hostids): array {
		$params = [
			'output' => ['hostid', 'host', 'name', 'maintenanceid', 'maintenance_status',
				'maintenance_type', 'maintenance_from'],
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
				'inventory' => is_array($row['inventory'] ?? null) ? $row['inventory'] : [],
				'maintenance' => $this->normalizeHostMaintenance($row)
			];
			$host['os'] = $this->detectOs($host, null);
			$hosts[$hostid] = $host;
		}

		return [$hosts, $truncated];
	}

	/**
	 * Normalize the effective maintenance fields returned by host.get. The host
	 * object is authoritative for the maintenance occurrence that is active now;
	 * inactive residual type/id values must never be interpreted as active state.
	 */
	private function normalizeHostMaintenance(array $row): array {
		$active = (int) ($row['maintenance_status'] ?? 0) === 1;
		$no_data = $active && (int) ($row['maintenance_type'] ?? 0) === 1;
		$id = $active ? (string) ($row['maintenanceid'] ?? '') : '';
		$since = $active ? (int) ($row['maintenance_from'] ?? 0) : 0;

		return [
			'active' => $active,
			'type' => !$active ? 'none' : ($no_data ? 'no_data_collection' : 'with_data_collection'),
			'id' => $id !== '' && $id !== '0' ? $id : null,
			'since' => $since > 0 ? $since : null
		];
	}

	private function isNoDataMaintenance(array $host): bool {
		$maintenance = is_array($host['maintenance'] ?? null) ? $host['maintenance'] : [];
		return !empty($maintenance['active']) && ($maintenance['type'] ?? '') === 'no_data_collection';
	}

	/**
	 * Attribute staleness to maintenance only when the value was still within its
	 * normal freshness allowance as maintenance began. Missing/never-collected
	 * values and values already stale before maintenance remain quality issues.
	 */
	private function maintenanceExplainsStaleValue(array $host, int $lastclock, int $stale_seconds): bool {
		if (!$this->isNoDataMaintenance($host) || $lastclock <= 0 || $stale_seconds <= 0) {
			return false;
		}

		$since = (int) ($host['maintenance']['since'] ?? 0);
		return $since > 0 && $lastclock >= max(1, $since - $stale_seconds);
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
		$searches = ['vfs.fs.', 'system.cpu.', 'vm.mem', 'vm.cpu.',
			'wmi.get[root/cimv2,"Select NumberOfLogicalProcessors'];
		$by_id = [];
		$truncated = false;

		foreach (array_chunk($hostids, self::HOST_CHUNK) as $chunk) {
			foreach ($searches as $search) {
				$remaining = self::MAX_ITEMS - count($by_id);
				if ($remaining <= 0) {
					$truncated = true;
					break 2;
				}
				$rows = API::Item()->get([
					'output' => ['itemid', 'hostid', 'name', 'key_', 'value_type', 'units', 'lastvalue',
						'lastclock', 'state', 'error'],
					'hostids' => $chunk,
					'monitored' => true,
					'search' => ['key_' => $search],
					'startSearch' => true,
					'selectTags' => ['tag', 'value'],
					// One sentinel row proves that this query does not fit in the
					// remaining global budget. Never ask each search for MAX_ITEMS.
					'limit' => $remaining + 1
				]);
				if (!is_array($rows)) {
					continue;
				}
				[$by_id, $query_truncated] = $this->ingestBoundedItemRows($by_id, $rows, self::MAX_ITEMS);
				if ($query_truncated || count($by_id) >= self::MAX_ITEMS) {
					// Reaching the global cap is conservatively incomplete because later
					// searches/host chunks were not queried.
					$truncated = true;
					break 2;
				}
			}
		}

		$by_host = [];
		foreach ($by_id as $item) {
			$by_host[$item['hostid']][] = $item;
		}
		return [$by_host, $truncated];
	}

	/**
	 * Normalize and ingest API item rows without exceeding one global capacity.
	 * The caller requests remaining+1 rows, so an oversized result is a sentinel
	 * that must be recorded before any rows are accepted.
	 */
	private function ingestBoundedItemRows(array $by_id, array $rows, int $capacity): array {
		$capacity = max(0, $capacity);
		$remaining = max(0, $capacity - count($by_id));
		$truncated = count($rows) > $remaining;
		foreach ($rows as $row) {
			$itemid = (string) ($row['itemid'] ?? '');
			if ($itemid === '' || isset($by_id[$itemid])) {
				continue;
			}
			if (count($by_id) >= $capacity) {
				$truncated = true;
				break;
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

		return [$by_id, $truncated];
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
		$direct_template_ids = [];
		foreach ($hosts as $host) {
			foreach (($host['templates'] ?? []) as $template) {
				$templateid = (string) ($template['templateid'] ?? '');
				if ($templateid !== '') {
					$direct_template_ids[$templateid] = true;
				}
			}
		}
		$topology = $this->traverseTemplateTopology(
			array_keys($direct_template_ids),
			static function (array $templateids): array {
				$rows = API::Template()->get([
					'output' => ['templateid'],
					'templateids' => $templateids,
					'selectParentTemplates' => ['templateid'],
					'nopermissions' => true
				]);
				return is_array($rows) ? $rows : [];
			}
		);
		$template_parents = $topology['parents'];
		if ($topology['incomplete']) {
			$this->addQuality('Warning', _('All analyzed hosts'), _('Threshold macros'),
				_('Template topology incomplete'),
				sprintf(_('Only a bounded portion of the linked-template ancestry could be resolved (%1$s). Threshold macro precedence may therefore be incomplete.'),
					$topology['reason']));
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
		$macro_seen = [];
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
					$entityid = (string) ($row['hostid'] ?? '');
					$parsed = $this->parseMacro((string) ($row['macro'] ?? ''), (string) ($row['value'] ?? ''),
						(int) ($row['type'] ?? 0), $entityid);
					if ($parsed !== null) {
						$identity = $entityid."\0".$parsed['raw']."\0".$parsed['value']."\0".$parsed['type'];
						if (!isset($macro_seen[$identity])) {
							$macro_seen[$identity] = true;
							$by_entity[$entityid][] = $parsed;
						}
					}
				}
			}
		}

		$global = [];
		$global_seen = [];
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
					(int) ($row['type'] ?? 0), 'global');
				if ($parsed !== null) {
					$identity = $parsed['raw']."\0".$parsed['value']."\0".$parsed['type'];
					if (!isset($global_seen[$identity])) {
						$global_seen[$identity] = true;
						$global[] = $parsed;
					}
				}
			}
		}

		return ['levels' => $host_levels, 'by_entity' => $by_entity, 'global' => $global];
	}

	/**
	 * Walk only the template ancestry reachable from the analyzed hosts. The
	 * loader is injected so cycle, missing-row and safety-cap behavior remains a
	 * pure, regression-testable operation.
	 *
	 * @return array{parents: array<string, array<int, string>>, incomplete: bool, reason: string}
	 */
	private function traverseTemplateTopology(array $direct_template_ids, callable $loader,
			?int $safety_cap = null): array {
		$cap = max(1, $safety_cap ?? self::MAX_TEMPLATE_TOPOLOGY_ENTITIES);
		$initial = [];
		foreach ($direct_template_ids as $templateid) {
			$templateid = (string) $templateid;
			if ($templateid !== '') {
				$initial[$templateid] = true;
			}
		}
		$frontier = array_map('strval', array_keys($initial));
		sort($frontier, SORT_NATURAL);
		$incomplete = false;
		$reasons = [];
		if (count($frontier) > $cap) {
			$frontier = array_slice($frontier, 0, $cap);
			$incomplete = true;
			$reasons['safety cap reached'] = true;
		}
		$discovered = array_fill_keys($frontier, true);
		$parents_by_template = [];

		while ($frontier) {
			$next = [];
			foreach (array_chunk($frontier, self::TEMPLATE_TOPOLOGY_CHUNK) as $chunk) {
				try {
					$rows = $loader($chunk);
				}
				catch (\Throwable $e) {
					$rows = [];
					$incomplete = true;
					$reasons['template API lookup failed'] = true;
				}
				if (!is_array($rows)) {
					$rows = [];
					$incomplete = true;
					$reasons['template API returned no rows'] = true;
				}

				$returned = [];
				foreach ($rows as $row) {
					$templateid = (string) ($row['templateid'] ?? '');
					if ($templateid === '' || !in_array($templateid, $chunk, true)) {
						continue;
					}
					$returned[$templateid] = true;
					$parents = [];
					foreach (($row['parentTemplates'] ?? []) as $parent) {
						$parentid = (string) ($parent['templateid'] ?? '');
						if ($parentid === '' || isset($parents[$parentid])) {
							continue;
						}
						if (!isset($discovered[$parentid])) {
							if (count($discovered) >= $cap) {
								$incomplete = true;
								$reasons['safety cap reached'] = true;
								continue;
							}
							$discovered[$parentid] = true;
							$next[$parentid] = true;
						}
						$parents[$parentid] = true;
					}
					$parentids = array_map('strval', array_keys($parents));
					sort($parentids, SORT_NATURAL);
					$parents_by_template[$templateid] = $parentids;
				}
				foreach ($chunk as $templateid) {
					if (!isset($returned[$templateid])) {
						$parents_by_template[$templateid] = [];
						$incomplete = true;
						$reasons['one or more requested templates were not returned'] = true;
					}
				}
			}
			$frontier = array_map('strval', array_keys($next));
			sort($frontier, SORT_NATURAL);
		}

		ksort($parents_by_template, SORT_NATURAL);
		return [
			'parents' => $parents_by_template,
			'incomplete' => $incomplete,
			'reason' => $reasons ? implode('; ', array_keys($reasons)) : 'complete'
		];
	}

	private function parseMacro(string $raw, string $value, int $type, string $entityid = ''): ?array {
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
			'type' => $type,
			'entityid' => $entityid
		];
	}

	private function compareMacroCandidates(array $a, array $b): int {
		$a_entity = (string) ($a['entityid'] ?? '');
		$b_entity = (string) ($b['entityid'] ?? '');
		if (ctype_digit($a_entity) && ctype_digit($b_entity)) {
			$a_numeric = ltrim($a_entity, '0') ?: '0';
			$b_numeric = ltrim($b_entity, '0') ?: '0';
			$comparison = strlen($a_numeric) <=> strlen($b_numeric);
			if ($comparison === 0) {
				$comparison = strcmp($a_numeric, $b_numeric);
			}
		}
		else {
			$comparison = strcmp($a_entity, $b_entity);
		}
		if ($comparison !== 0) {
			return $comparison;
		}

		foreach (['raw', 'value'] as $field) {
			$comparison = strcmp((string) ($a[$field] ?? ''), (string) ($b[$field] ?? ''));
			if ($comparison !== 0) {
				return $comparison;
			}
		}
		return 0;
	}

	private function macroCandidatesAmbiguous(array $candidates): bool {
		if (count($candidates) < 2) {
			return false;
		}
		$winning_entity = (string) ($candidates[0]['entityid'] ?? '');
		$at_winning_precedence = array_values(array_filter($candidates,
			static fn (array $candidate): bool => (string) ($candidate['entityid'] ?? '') === $winning_entity));

		return count($at_winning_precedence) > 1;
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
			$macros = [];
			foreach ($level as $tid) {
				foreach (($index['by_entity'][$tid] ?? []) as $macro) {
					$macros[] = $macro;
				}
			}
			$scopes[] = ['template depth '.($depth + 1), $macros];
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
					usort($exact, [$this, 'compareMacroCandidates']);
					return ['value' => $exact[0]['value'], 'source' => $label,
						'ambiguous' => $this->macroCandidatesAmbiguous($exact)];
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
					usort($matched, [$this, 'compareMacroCandidates']);
					return ['value' => $matched[0]['value'], 'source' => $label,
						'ambiguous' => $this->macroCandidatesAmbiguous($matched)];
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
				usort($plain, [$this, 'compareMacroCandidates']);
				return ['value' => $plain[0]['value'], 'source' => $label,
					'ambiguous' => $this->macroCandidatesAmbiguous($plain)];
			}
		}

		return ['value' => null, 'source' => 'not found', 'ambiguous' => false];
	}

	/**
	 * @return array{v: ?float, src: string, fb: bool, amb: bool} percentage threshold (0..100)
	 */
	private function percentThreshold(array $macro, float $fallback): array {
		$parsed = $this->parseZabbixNumber($macro['value']);
		if ($parsed === null || $parsed < 0 || $parsed > 100) {
			return ['v' => $fallback, 'src' => sprintf(_('fallback %s%%'), $this->trimFloat($fallback)),
				'fb' => true, 'amb' => !empty($macro['ambiguous'])];
		}
		return ['v' => $parsed, 'src' => $macro['source'], 'fb' => false,
			'amb' => !empty($macro['ambiguous'])];
	}

	private function addResourceThresholdQuality(string $host, string $resource, string $label,
			array $threshold): void {
		if (!empty($threshold['amb'])) {
			$this->addQuality('Warning', $host, $resource, _('Ambiguous threshold macro'),
				sprintf(_('Multiple effective candidates matched the %1$s threshold.'), $label));
		}
		if (!empty($threshold['fb'])) {
			$this->addQuality('Warning', $host, $resource, _('Threshold fallback used'),
				$label.': '.(string) ($threshold['src'] ?? _('fallback')));
		}
	}

	/**
	 * @return array{v: ?float, src: string, fb: bool, amb: bool} byte threshold (0 = disabled)
	 */
	private function bytesThreshold(array $macro): array {
		$parsed = $this->parseZabbixNumber($macro['value']);
		if ($parsed === null || $parsed < 0) {
			return ['v' => 0.0, 'src' => _('disabled'), 'fb' => true,
				'amb' => !empty($macro['ambiguous'])];
		}
		return ['v' => $parsed, 'src' => $macro['source'], 'fb' => false,
			'amb' => !empty($macro['ambiguous'])];
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

	private function formatPct(?float $value): string {
		return $value === null ? 'N/A' : number_format($value, 1, '.', '').'%';
	}

	// -------------------------------------------------------------------------
	// Disk findings
	// -------------------------------------------------------------------------

	private function buildDiskFindings(array $hosts, array $items_by_host, array $macro_index, int $now): array {
		$findings = [];
		$idx = 0;

		foreach ($hosts as $hostid => $host) {
			$hostid = (string) $hostid; // PHP stores numeric-string keys as ints
			$current_observation_usable = !$this->isNoDataMaintenance($host);
			$families = $this->chooseFilesystemFamilies(
				$items_by_host[$hostid] ?? [],
				$now,
				(string) ($host['os'] ?? 'Unknown')
			);
			if (!$families) {
				$this->addQuality('Warning', $host['name'], _('Filesystems'),
					_('No supported filesystem items visible'),
					_('No vfs.fs.size or vfs.fs.dependent.size family was returned for this host; check collection and API permissions.'));
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

				foreach ([
					[_('used-percent warning'), $warn_pct, true],
					[_('used-percent critical'), $crit_pct, true],
					[_('free-space warning'), $warn_gb, false],
					[_('free-space critical'), $crit_gb, false]
				] as [$label, $threshold, $required]) {
					if (!empty($threshold['amb'])) {
						$this->addQuality('Warning', $host['name'], $fs, _('Ambiguous macro context'),
							sprintf(_('Multiple effective candidates matched the %1$s threshold.'), $label));
					}
					if ($required && !empty($threshold['fb'])) {
						$this->addQuality('Warning', $host['name'], $fs, _('Threshold fallback used'),
							$label.': '.$threshold['src']);
					}
				}

				if ($warn_pct['v'] !== null && $crit_pct['v'] !== null && $warn_pct['v'] >= $crit_pct['v']) {
					$this->addQuality('Warning', $host['name'], $fs, _('Invalid percentage threshold order'),
						_('Warning percentage should be lower than critical percentage.'));
					$warn_pct['v'] = null;
					$warn_pct['src'] .= '; '._('ignored because warning is not below critical');
				}
				if (($warn_gb['v'] ?? 0) > 0 && ($crit_gb['v'] ?? 0) > 0
						&& $warn_gb['v'] <= $crit_gb['v']) {
					$this->addQuality('Warning', $host['name'], $fs, _('Invalid free-space threshold order'),
						_('Warning free-space threshold should be greater than critical.'));
					$warn_gb['v'] = null;
					$warn_gb['src'] .= '; '._('ignored because warning is not above critical');
				}

				// A composed filesystem snapshot is only as current and valid as its
				// contributors. Reject impossible byte/percentage values before family
				// composition, derivation, threshold checks or forecasting.
				$current_items = [];
				$invalid_current_metrics = [];
				foreach (['total', 'used', 'free', 'pused', 'pfree'] as $metric) {
					$item = $metrics[$metric] ?? null;
					if ($item !== null && $item['state'] === 0 && $item['lastvalue'] !== null
							&& $item['lastclock'] > 0) {
						if ($this->validFilesystemMetricValue($metric, $item['lastvalue'])) {
							$current_items[$metric] = $item;
						}
						else {
							$invalid_current_metrics[$metric] = $item['lastvalue'];
						}
					}
				}
				if ($invalid_current_metrics) {
					$invalid_detail = [];
					foreach ($invalid_current_metrics as $metric => $value) {
						$invalid_detail[] = $metric.'='.(is_scalar($value) ? (string) $value : _('non-numeric'));
					}
					$this->addQuality('Warning', $host['name'], $fs, _('Invalid current filesystem value'),
						sprintf(_('Excluded invalid current metric(s): %1$s. Byte values must be finite and non-negative; percentages must be between 0 and 100.'),
							implode(', ', $invalid_detail)));
				}
				$fresh_items = [];
				$stale_items = [];
				foreach ($current_items as $metric => $item) {
					if ($now - $item['lastclock'] <= self::STALE_DISK_SECONDS) {
						$fresh_items[$metric] = $item;
					}
					else {
						$stale_items[$metric] = $item;
					}
				}

				$fresh_composition = $this->selectFilesystemComposition($fresh_items, $os);
				$selected_fresh = $fresh_composition !== null
					? array_intersect_key($fresh_items, array_fill_keys($fresh_composition['metrics'], true))
					: [];
				// Current state, thresholds and capacity must all use the exact same
				// composition. This prevents an older pused value from overriding a
				// fresher pfree contributor selected with the byte metric.
				$total = isset($selected_fresh['total']) ? $selected_fresh['total']['lastvalue'] : null;
				$used = isset($selected_fresh['used']) ? $selected_fresh['used']['lastvalue'] : null;
				$free = isset($selected_fresh['free']) ? $selected_fresh['free']['lastvalue'] : null;
				$pused = isset($selected_fresh['pused']) ? $selected_fresh['pused']['lastvalue'] : null;
				$pfree = isset($selected_fresh['pfree']) ? $selected_fresh['pfree']['lastvalue'] : null;
				if ($pused === null && $pfree !== null) {
					$pused = 100.0 - $pfree;
				}
				if ($used === null && $free !== null && $pused !== null && $pused < 100) {
					$used = max(0.0, $free * $pused / (100 - $pused));
				}
				if ($free === null && $used !== null && $pused !== null && $pused > 0) {
					$free = max(0.0, $used * (100 - $pused) / $pused);
				}
				// Linux total can include blocks reserved from ordinary workloads. Only
				// Windows volumes safely support total-used/free inference.
				if ($os === 'Windows') {
					if ($used === null && $total !== null && $free !== null && $free <= $total) {
						$used = $total - $free;
					}
					if ($free === null && $total !== null && $used !== null && $used <= $total) {
						$free = $total - $used;
					}
				}
				if ($pused === null && $used !== null && $free !== null && $used + $free > 0) {
					$pused = $used / ($used + $free) * 100;
				}

				$combined_composition = $fresh_composition;
				$needed_stale_items = [];
				if ($fresh_composition === null && $stale_items) {
					$combined_composition = $this->selectFilesystemComposition($fresh_items + $stale_items, $os);
					if ($combined_composition !== null) {
						foreach ($combined_composition['metrics'] as $metric) {
							if (isset($stale_items[$metric])) {
								$needed_stale_items[$metric] = $stale_items[$metric];
							}
						}
					}
				}
				$composition_complete = $fresh_composition !== null;
				$usable_capacity = $composition_complete
					? $this->usableFilesystemCapacity($os, $total, $used, $free, $pused)
					: null;
				[$series_item, $transform, $parameter] = $this->usedSeriesSpec(
					$metrics, array_keys($selected_fresh), $usable_capacity);
				// A valid percentage item is an independent, higher-fidelity history
				// source even when used+free is the selected current byte composition.
				// Choose pused/pfree by recency, but do not mix its lastvalue into the
				// selected composition's current threshold assessment above.
				$pct_series_metric = null;
				foreach (['pused', 'pfree'] as $metric) {
					if (isset($fresh_items[$metric]) && ($pct_series_metric === null
							|| $fresh_items[$metric]['lastclock'] > $fresh_items[$pct_series_metric]['lastclock'])) {
						$pct_series_metric = $metric;
					}
				}
				$pct_series_item = $pct_series_metric !== null ? $fresh_items[$pct_series_metric] : null;
				$pct_transform = $pct_series_metric === 'pfree' ? 'invert' : 'identity';
				$pct_parameter = $pct_transform === 'invert' ? 100.0 : null;
				$contributing_items = $selected_fresh;
				$clocks = array_map(static fn (array $item): int => $item['lastclock'], $contributing_items);
				$lastclock = $clocks ? min($clocks) : 0;

				$expected_gap = false;
				if (!$composition_complete && $needed_stale_items) {
					$status = 'Stale';
					$stale_detail = [];
					$expected_gap = true;
					foreach ($needed_stale_items as $metric => $item) {
						$stale_detail[] = $metric.': '.gmdate('Y-m-d H:i', $item['lastclock']).' UTC';
						if (!$this->maintenanceExplainsStaleValue(
								$host, (int) $item['lastclock'], self::STALE_DISK_SECONDS)) {
							$expected_gap = false;
						}
					}
					if (!$expected_gap) {
						$detail = $stale_detail
							? _('Stale metric clocks: ').implode(', ', $stale_detail)
							: _('No usable current filesystem metric was returned.');
						if ($this->isNoDataMaintenance($host)) {
							$detail .= ' '._('The host is currently in maintenance without data collection, but this gap was not proven to begin with that maintenance.');
						}
						$this->addQuality('Warning', $host['name'], $fs, _('Stale filesystem data'), $detail);
					}
				}
				elseif (!$composition_complete) {
					$status = 'Incomplete';
					$this->addQuality('Warning', $host['name'], $fs, _('Incomplete filesystem item set'),
						_('Expected a valid fresh composition of used+available, used/available+used percentage, or Windows total+one byte component.'));
				}
				else {
					$status = 'OK';
				}

				$current_snapshot_usable = $current_observation_usable && $composition_complete;
				$current_warn = $current_snapshot_usable
					&& (($pused !== null && $warn_pct['v'] !== null && $pused > $warn_pct['v'])
						|| ($free !== null && ($warn_gb['v'] ?? 0) > 0 && $free < $warn_gb['v']));
				$current_crit = $current_snapshot_usable
					&& (($pused !== null && $crit_pct['v'] !== null && $pused > $crit_pct['v'])
						|| ($free !== null && ($crit_gb['v'] ?? 0) > 0 && $free < $crit_gb['v']));
				$current_severity = $this->diskSeverity($current_warn, $current_crit,
					null, null, null, 'None', false);
				$current_reasons = [];
				if (!$current_observation_usable) {
					$current_reasons[] = _('The host is in maintenance without data collection; the last accepted values were not treated as current.');
				}
				elseif (!$composition_complete) {
					$current_reasons[] = _('No valid fresh filesystem composition was available for current threshold assessment.');
				}
				elseif ($pused !== null && $crit_pct['v'] !== null && $pused > $crit_pct['v']) {
					$current_reasons[] = sprintf(_('Current used percentage %1$s%% exceeds the critical threshold %2$s%%.'),
						$this->trimFloat($pused), $this->trimFloat($crit_pct['v']));
				}
				elseif ($pused !== null && $warn_pct['v'] !== null && $pused > $warn_pct['v']) {
					$current_reasons[] = sprintf(_('Current used percentage %1$s%% exceeds the warning threshold %2$s%%.'),
						$this->trimFloat($pused), $this->trimFloat($warn_pct['v']));
				}
				if ($current_snapshot_usable && $free !== null && ($crit_gb['v'] ?? 0) > 0
						&& $free < $crit_gb['v']) {
					$current_reasons[] = _('Current free space is below the critical absolute threshold.');
				}
				elseif ($current_snapshot_usable && $free !== null && ($warn_gb['v'] ?? 0) > 0
						&& $free < $warn_gb['v']) {
					$current_reasons[] = _('Current free space is below the warning absolute threshold.');
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
					'pct_itemid' => $pct_series_item !== null ? $pct_series_item['itemid'] : null,
					'pct_item_key' => $pct_series_item !== null ? $pct_series_item['key'] : '',
					'pct_tr' => $pct_transform,
					'pct_pr' => $pct_parameter,
					'tr' => $transform,
					'pr' => $parameter,
					'total' => $total,
					'used' => $used,
					'free' => $free,
					'usable' => $usable_capacity,
					'pused' => $pused !== null ? round($pused, 3) : null,
					'lastclock' => $lastclock ?: null,
					'stale_metrics' => array_keys($stale_items),
					'warn_pct' => $warn_pct,
					'crit_pct' => $crit_pct,
					'warn_free' => $warn_gb,
					'crit_free' => $crit_gb,
					'status' => $status,
					'expected_gap' => $expected_gap,
					'current_observation_usable' => $current_observation_usable,
					'current_severity' => $current_severity,
					'current_reasons' => $current_reasons,
					'current_recommendation' => $this->diskRecommendation($current_severity, $kind)
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
	private function chooseFilesystemFamilies(array $host_items, ?int $now = null,
			string $host_os = 'Unknown'): array {
		$now = $now ?? time();
		$families = [];
		foreach ($host_items as $item) {
			$parsed = $this->parseFsKey($item['key']);
			if ($parsed === null) {
				continue;
			}
			[$fs, $metric] = $parsed;
			$family = strpos($item['key'], '.dependent.') !== false ? 'dependent' : 'standard';
			$current = $families[$fs][$family][$metric] ?? null;
			if ($current === null || $this->itemRank($item, $metric) > $this->itemRank($current, $metric)) {
				$families[$fs][$family][$metric] = $item;
			}
		}

		$selected = [];
		foreach ($families as $fs => $by_family) {
			$os = $host_os;
			if ($os === 'Unknown') {
				$os = preg_match('/^[a-z]:/i', $fs) === 1 || strncmp($fs, '\\\\', 2) === 0
					? 'Windows'
					: ($fs !== '' && $fs[0] === '/' ? 'Linux' : 'Unknown');
			}
			$best = null;
			$best_score = null;
			foreach ($by_family as $family => $metrics) {
				$completeness = 0;
				foreach (['used', 'free', 'total', 'pused', 'pfree'] as $metric) {
					if (isset($metrics[$metric])) {
						$completeness++;
					}
				}
				$supported = 0;
				$usable = 0;
				$fresh_metrics = [];
				foreach ($metrics as $metric => $item) {
					if ($item['state'] === 0) {
						$supported++;
						if ($item['lastvalue'] !== null && $item['lastclock'] > 0
								&& $this->validFilesystemMetricValue($metric, $item['lastvalue'])) {
							$usable++;
							if ($now - $item['lastclock'] <= self::STALE_DISK_SECONDS) {
								$fresh_metrics[$metric] = $item;
							}
						}
					}
				}
				$fresh_composition = $this->selectFilesystemComposition($fresh_metrics, $os);
				// Rank the age of the actual derivable composition before optional
				// metric count. One recent optional value must not conceal an older
				// required partner.
				$freshness = $fresh_composition !== null ? $fresh_composition['oldest_clock'] : 0;
				$score = [
					$fresh_composition !== null ? 1 : 0,
					$freshness,
					$fresh_composition['preference'] ?? 0,
					count($fresh_metrics),
					$usable,
					$supported,
					$completeness,
					$family === 'dependent' ? 1 : 0
				];
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

	private function itemRank(array $item, string $metric): array {
		$has_value = $item['lastvalue'] !== null && $item['lastclock'] > 0;
		return [
			$item['state'] === 0 ? 1 : 0,
			$has_value && $this->validFilesystemMetricValue($metric, $item['lastvalue']) ? 1 : 0,
			$has_value ? 1 : 0,
			$item['lastclock'],
			(int) $item['itemid']
		];
	}

	private function validFilesystemMetricValue(string $metric, $value): bool {
		if (!is_numeric($value)) {
			return false;
		}
		$value = (float) $value;
		if (!is_finite($value)) {
			return false;
		}

		return in_array($metric, ['pused', 'pfree'], true)
			? $value >= 0.0 && $value <= 100.0
			: $value >= 0.0;
	}

	/**
	 * Select the freshest valid metric composition from which used and free bytes
	 * can both be known without Linux total/reserved-block assumptions.
	 *
	 * @return array{metrics: array<int, string>, oldest_clock: int, preference: int}|null
	 */
	private function selectFilesystemComposition(array $metrics, string $os): ?array {
		$value = static fn (string $metric): ?float => isset($metrics[$metric])
			? (float) $metrics[$metric]['lastvalue'] : null;
		$candidates = [];
		$add = static function (array $names, int $preference) use (&$candidates, $metrics): void {
			$clocks = array_map(static fn (string $name): int => (int) $metrics[$name]['lastclock'], $names);
			$candidates[] = [
				'metrics' => $names,
				'oldest_clock' => min($clocks),
				'preference' => $preference
			];
		};

		$used = $value('used');
		$free = $value('free');
		$pused = $value('pused');
		$pfree = $value('pfree');
		$total = $value('total');
		if ($used !== null && $free !== null && $used + $free > 0) {
			$add(['used', 'free'], 5);
		}
		if ($used !== null && $pused !== null && $pused > 0 && $pused <= 100
				&& $used / ($pused / 100) > 0) {
			$add(['used', 'pused'], 4);
		}
		if ($free !== null && $pused !== null && $pused >= 0 && $pused < 100
				&& $free + ($free * $pused / (100 - $pused)) > 0) {
			$add(['free', 'pused'], 3);
		}
		if ($used !== null && $pfree !== null && $pfree >= 0 && $pfree < 100
				&& $used / ((100 - $pfree) / 100) > 0) {
			$add(['used', 'pfree'], 4);
		}
		if ($free !== null && $pfree !== null && $pfree > 0 && $pfree <= 100
				&& $free / ($pfree / 100) > 0) {
			$add(['free', 'pfree'], 3);
		}
		if ($os === 'Windows' && $total !== null && $total > 0) {
			if ($free !== null && $free <= $total) {
				$add(['total', 'free'], 2);
			}
			if ($used !== null && $used <= $total) {
				$add(['total', 'used'], 1);
			}
		}
		if (!$candidates) {
			return null;
		}
		usort($candidates, static fn (array $a, array $b): int =>
			($b['oldest_clock'] <=> $a['oldest_clock'])
				?: ($b['preference'] <=> $a['preference']));

		return $candidates[0];
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

	private function usableFilesystemCapacity(string $os, ?float $total, ?float $used, ?float $free,
			?float $pused): ?float {
		if ($used !== null && $free !== null) {
			$capacity = $used + $free;
			return $capacity > 0 ? $capacity : null;
		}
		if ($used !== null && $pused !== null && $pused > 0) {
			$capacity = $used / ($pused / 100);
			return $capacity > 0 ? $capacity : null;
		}
		return $os === 'Windows' && $total !== null && $total > 0 ? $total : null;
	}

	/**
	 * Pick the best time-series item for used-bytes growth analysis.
	 *
	 * @return array{0: ?array, 1: string, 2: ?float} [item, transform, parameter]
	 */
	private function usedSeriesSpec(array $metrics, array $fresh_metrics, ?float $usable_capacity): array {
		$fresh = array_fill_keys($fresh_metrics, true);
		$usable = function (string $metric) use ($metrics): ?array {
			$item = $metrics[$metric] ?? null;
			return ($item !== null && $item['state'] === 0 && $item['lastvalue'] !== null && $item['lastclock'] > 0)
				? $item
				: null;
		};

		$used_item = isset($fresh['used']) ? $usable('used') : null;
		if ($used_item !== null) {
			return [$used_item, 'identity', null];
		}
		$free_item = isset($fresh['free']) ? $usable('free') : null;
		if ($free_item !== null && $usable_capacity !== null) {
			return [$free_item, 'invert', $usable_capacity];
		}
		$pused_item = isset($fresh['pused']) ? $usable('pused') : null;
		if ($pused_item !== null && $usable_capacity !== null) {
			return [$pused_item, 'scale', $usable_capacity / 100];
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
			$current_observation_usable = !$this->isNoDataMaintenance($host);
			$maintenance_reason = $current_observation_usable
				? []
				: [_('The host is in maintenance without data collection; the last accepted value was not treated as current.')];

			// CPU ------------------------------------------------------------
			[$cpu_item, $cpu_transform, $cpu_parameter] = $this->chooseTransformedResourceItem(
				$host_items,
				[
					[['system.cpu.util', 'vm.cpu.util'], 'identity', null],
					[['system.cpu.util[,idle]'], 'invert', 100.0]
				],
				$now
			);

			$cpu_crit = $this->percentThreshold(
				$this->resolveMacro($macro_index, $hostid, 'CPU.UTIL.CRIT', []), self::CPU_CRIT_DEFAULT);
			if ($host['os'] === 'Windows') {
				// The latest Windows template only carries CPU.UTIL.CRIT; the review
				// threshold is analytic, not a real second macro.
				$alarm = $cpu_crit['v'] ?? self::CPU_WARN_DEFAULT;
				$cpu_warn = ['v' => max(0.0, min($alarm - 10.0, self::CPU_WARN_DEFAULT)),
					'src' => _('review level (alarm − 10 pp)'), 'fb' => true, 'amb' => false];
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
					$this->addQuality('Warning', $host['name'], 'CPU', _('Invalid CPU threshold order'),
						_('The warning threshold was not below the critical threshold; a conservative review fallback was used.'));
					$cpu_warn = ['v' => min(self::CPU_WARN_DEFAULT, max(0.0, $cpu_crit['v'] - 5.0)),
						'src' => _('fallback below critical'), 'fb' => true, 'amb' => false];
				}
			}
			$this->addResourceThresholdQuality($host['name'], 'CPU', _('CPU critical'), $cpu_crit);
			if ($host['os'] !== 'Windows') {
				$this->addResourceThresholdQuality($host['name'], 'CPU', _('CPU warning'), $cpu_warn);
			}

			// lastvalue without a lastclock is a never-collected sentinel, not a value.
			$current_cpu = ($cpu_item !== null && $cpu_item['lastclock'] > 0) ? $cpu_item['lastvalue'] : null;
			if ($current_cpu !== null && $cpu_transform === 'invert') {
				$current_cpu = 100.0 - $current_cpu;
			}
			$cpu_count = $this->findCpuCount($host_items);

			$cpu_status = 'OK';
			$cpu_expected_gap = false;
			if ($cpu_item === null) {
				$cpu_status = 'Missing';
				$this->addQuality('Warning', $host['name'], 'CPU', _('CPU utilization item missing'),
					_('Expected system.cpu.util, vm.cpu.util or system.cpu.util[,idle].'));
			}
			elseif ($now - $cpu_item['lastclock'] > self::STALE_RESOURCE_SECONDS) {
				$cpu_status = 'Stale';
				$cpu_expected_gap = $this->maintenanceExplainsStaleValue(
					$host, (int) $cpu_item['lastclock'], self::STALE_RESOURCE_SECONDS);
				if (!$cpu_expected_gap) {
					$detail = $cpu_item['lastclock'] > 0
						? sprintf(_('Last value: %1$s'), gmdate('Y-m-d H:i', $cpu_item['lastclock']).' UTC')
						: _('No values have been collected.');
					if ($this->isNoDataMaintenance($host)) {
						$detail .= $cpu_item['lastclock'] > 0
							? ' '._('The host is currently in maintenance without data collection, but this value was already stale before maintenance began.')
							: ' '._('The host is currently in maintenance without data collection, but maintenance does not prove why this item has never collected a value.');
					}
					$this->addQuality('Warning', $host['name'], 'CPU', _('CPU data is stale'), $detail);
				}
			}
			if ($current_cpu !== null && ($current_cpu < 0 || $current_cpu > 100)) {
				$this->addQuality('Warning', $host['name'], 'CPU', _('Invalid current utilization'),
					sprintf(_('Received %1$s%%; expected a value from 0 through 100%%.'),
						$this->trimFloat($current_cpu)));
				$current_cpu = null;
				if ($cpu_status !== 'Stale') {
					$cpu_status = 'Invalid current value';
				}
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
				'status' => $cpu_status,
				'expected_gap' => $cpu_expected_gap,
				'current_observation_usable' => $current_observation_usable,
				'current_reasons' => $maintenance_reason
			];

			// Memory ----------------------------------------------------------
			$memory_keys = $host['os'] === 'Windows'
				? ['vm.memory.util', 'vm.memory.utilization', 'vm.mem.util', 'vm.memory.size[pused]']
				: ['vm.memory.utilization', 'vm.memory.util', 'vm.mem.util', 'vm.memory.size[pused]'];
			[$memory_item, $memory_transform, $memory_parameter] = $this->chooseTransformedResourceItem(
				$host_items,
				[
					[$memory_keys, 'identity', null],
					[['vm.memory.size[pavailable]'], 'invert', 100.0]
				],
				$now
			);
			$memory_total = $this->chooseExactItem(
				$host_items, ['vm.memory.size[total]'], $now - self::STALE_RESOURCE_SECONDS);

			$memory_alarm = $this->percentThreshold(
				$this->resolveMacro($macro_index, $hostid, 'MEMORY.UTIL.MAX', []), self::MEMORY_CRIT_DEFAULT);
			$this->addResourceThresholdQuality($host['name'], 'Memory', _('memory critical'), $memory_alarm);
			$alarm_value = $memory_alarm['v'] ?? self::MEMORY_CRIT_DEFAULT;
			$memory_review = ['v' => max(0.0, min(self::MEMORY_WARN_DEFAULT, $alarm_value - 5.0)),
				'src' => _('review level (alarm − 5 pp)'), 'fb' => true, 'amb' => false];

			$current_memory = ($memory_item !== null && $memory_item['lastclock'] > 0)
				? $memory_item['lastvalue']
				: null;
			if ($current_memory !== null && $memory_transform === 'invert') {
				$current_memory = 100.0 - $current_memory;
			}

			$memory_status = 'OK';
			$memory_expected_gap = false;
			if ($memory_item === null) {
				$memory_status = 'Missing';
				$this->addQuality('Warning', $host['name'], 'Memory', _('Memory utilization item missing'),
					_('Expected vm.memory.utilization, vm.memory.util, vm.mem.util, pused or pavailable.'));
			}
			elseif ($now - $memory_item['lastclock'] > self::STALE_RESOURCE_SECONDS) {
				$memory_status = 'Stale';
				$memory_expected_gap = $this->maintenanceExplainsStaleValue(
					$host, (int) $memory_item['lastclock'], self::STALE_RESOURCE_SECONDS);
				if (!$memory_expected_gap) {
					$detail = $memory_item['lastclock'] > 0
						? sprintf(_('Last value: %1$s'), gmdate('Y-m-d H:i', $memory_item['lastclock']).' UTC')
						: _('No values have been collected.');
					if ($this->isNoDataMaintenance($host)) {
						$detail .= $memory_item['lastclock'] > 0
							? ' '._('The host is currently in maintenance without data collection, but this value was already stale before maintenance began.')
							: ' '._('The host is currently in maintenance without data collection, but maintenance does not prove why this item has never collected a value.');
					}
					$this->addQuality('Warning', $host['name'], 'Memory', _('Memory data is stale'), $detail);
				}
			}
			if ($current_memory !== null && ($current_memory < 0 || $current_memory > 100)) {
				$this->addQuality('Warning', $host['name'], 'Memory', _('Invalid current utilization'),
					sprintf(_('Received %1$s%%; expected a value from 0 through 100%%.'),
						$this->trimFloat($current_memory)));
				$current_memory = null;
				if ($memory_status !== 'Stale') {
					$memory_status = 'Invalid current value';
				}
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
				'status' => $memory_status,
				'expected_gap' => $memory_expected_gap,
				'current_observation_usable' => $current_observation_usable,
				'current_reasons' => $maintenance_reason
			];
		}

		return $findings;
	}

	private function chooseExactItem(array $host_items, array $keys, ?int $fresh_after = null): ?array {
		$rank = array_flip($keys);
		$best = null;
		$best_score = null;
		foreach ($host_items as $item) {
			if (!isset($rank[$item['key']]) || $item['state'] !== 0) {
				continue;
			}
			$has_current = $item['lastvalue'] !== null && $item['lastclock'] > 0;
			$fresh = $has_current && ($fresh_after === null || $item['lastclock'] >= $fresh_after);
			$score = [
				$fresh ? 1 : 0,
				$has_current ? 1 : 0,
				$item['lastclock'],
				-$rank[$item['key']],
				(int) $item['itemid']
			];
			if ($best_score === null || $score > $best_score) {
				$best_score = $score;
				$best = $item;
			}
		}
		return $best;
	}

	/**
	 * Choose between direct and invertible utilization sources. A fresh usable
	 * fallback beats a stale preferred key; source preference applies only after
	 * current usability and freshness.
	 *
	 * @param array<int, array{0: array<int, string>, 1: string, 2: ?float}> $candidates
	 * @return array{0: ?array, 1: string, 2: ?float}
	 */
	private function chooseTransformedResourceItem(array $host_items, array $candidates, int $now): array {
		$best = [null, 'identity', null];
		$best_score = null;
		$fresh_after = $now - self::STALE_RESOURCE_SECONDS;
		foreach ($candidates as $preference => [$keys, $transform, $parameter]) {
			$key_rank = array_flip($keys);
			foreach ($host_items as $item) {
				if (!isset($key_rank[$item['key']]) || $item['state'] !== 0) {
					continue;
				}
				$has_current = $item['lastvalue'] !== null && $item['lastclock'] > 0;
				$fresh = $has_current && $item['lastclock'] >= $fresh_after;
				$transformed = $has_current ? (float) $item['lastvalue'] : null;
				if ($transformed !== null && $transform === 'invert') {
					$transformed = $parameter !== null ? $parameter - $transformed : null;
				}
				$plausible = $transformed !== null && is_finite($transformed)
					&& $transformed >= 0.0 && $transformed <= 100.0;
				$score = [
					$plausible ? 1 : 0,
					$fresh ? 1 : 0,
					$has_current ? 1 : 0,
					$item['lastclock'],
					-$preference,
					-$key_rank[$item['key']],
					(int) $item['itemid']
				];
				if ($best_score === null || $score > $best_score) {
					$best_score = $score;
					$best = [$item, $transform, $parameter];
				}
			}
		}

		return $best;
	}

	private function findCpuCount(array $host_items): ?float {
		$exact = $this->chooseExactItem($host_items, ['system.cpu.num']);
		if ($exact !== null && $exact['state'] === 0 && $exact['lastvalue'] !== null
				&& $exact['lastclock'] > 0) {
			return $exact['lastvalue'];
		}
		$candidates = [];
		foreach ($host_items as $item) {
			$key = strtolower((string) ($item['key'] ?? ''));
			$name = strtolower((string) ($item['name'] ?? ''));
			if (($item['state'] ?? 1) === 0 && $item['lastvalue'] !== null && $item['lastclock'] > 0
					&& (strpos($key, 'numberoflogicalprocessors') !== false
						|| strpos($name, 'logical processor') !== false)) {
				$candidates[] = $item;
			}
		}
		usort($candidates, static fn (array $a, array $b): int => $b['lastclock'] <=> $a['lastclock']);
		return $candidates ? $candidates[0]['lastvalue'] : null;
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
		$server_now = time();
		// The browser clock may be slightly ahead, but a client must never be able
		// to make a shared shard claim coverage in the future. Accept up to five
		// minutes of ordinary skew and clamp it to the authoritative server clock;
		// reject anything further ahead as an invalid request.
		if ($time_to > $server_now + 300) {
			$this->respondJsonError(_('The requested time range ends too far in the future.'), 400);
			return;
		}
		$time_to = min($time_to, $server_now);
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
			if ($spec['pct_itemid'] !== '') {
				$itemids[$spec['pct_itemid']] = true;
			}
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

		// The shared cache is initialized only after the live item authorization
		// lookup above. No cache key/path is ever derived for an item that was not
		// returned to the current Zabbix user.
		$this->force_series_refresh = (string) $this->getInput('refresh', '0') === '1'
			&& $this->hasValidRefreshToken();
		$this->series_cache = new SeriesCache(Config::cacheSettings());

		$now = time();
		$results = [];
		foreach ($specs as $spec) {
			if (!isset($item_meta[$spec['itemid']])) {
				$results[] = ['id' => $spec['id'], 'status' => 'denied'];
				continue;
			}
			[$source, $base_rows, $note] = $this->fetchSeriesRows($spec['itemid'], $item_meta[$spec['itemid']],
				$time_from, $time_to, $spec['kind'] === 'res', true);
			$rows = $this->transformRows($base_rows, $spec['tr'], $spec['pr']);
			if ($spec['kind'] === 'disk') {
				$pct_rows = [];
				$pct_source = 'none';
				$pct_note = null;
				if ($spec['pct_itemid'] !== '') {
					if (!isset($item_meta[$spec['pct_itemid']])) {
						$pct_note = _('The direct used-percentage series was unavailable to this user; percentage growth was derived from usable capacity.');
					}
					elseif ($spec['pct_itemid'] === $spec['itemid']) {
						$pct_rows = $this->transformRows($base_rows, $spec['pct_tr'], $spec['pct_pr']);
						$pct_source = $source;
						$pct_note = $note;
					}
					else {
						[$pct_source, $pct_rows, $pct_note] = $this->fetchSeriesRows(
							$spec['pct_itemid'], $item_meta[$spec['pct_itemid']], $time_from, $time_to, false, true);
						$pct_rows = $this->transformRows($pct_rows, $spec['pct_tr'], $spec['pct_pr']);
					}
				}
				$results[] = $this->forecastDisk(
					$spec, $rows, $source, $now, $note, $pct_rows, $pct_source, $pct_note);
			}
			else {
				$results[] = $this->forecastResource($spec, $rows, $source, $now, $note);
			}
		}

		$this->respondJson([
			'forecasts' => $results,
			'meta' => [
				'generated_at' => $now,
				'time_from' => $time_from,
				'time_to' => $time_to,
				'cache' => $this->forecastCacheMeta()
			]
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
			$pct_itemid = (string) ($entry['pct_itemid'] ?? '');
			$kind = (string) ($entry['kind'] ?? '');
			$tr = (string) ($entry['tr'] ?? 'identity');
			$pct_tr = (string) ($entry['pct_tr'] ?? 'identity');
			$rtype = (string) ($entry['rtype'] ?? '');
			$os = (string) ($entry['os'] ?? 'Unknown');
			$fs_kind = (string) ($entry['fs_kind'] ?? 'Local');
			$data_status = (string) ($entry['status'] ?? '');
			$current_observation_usable = $entry['current_observation_usable'] ?? true;
			if (preg_match('/^[dr]\d{1,6}$/', $id) !== 1 || !ctype_digit($itemid)
					|| ($pct_itemid !== '' && !ctype_digit($pct_itemid))
					|| !in_array($kind, ['disk', 'res'], true)
					|| !in_array($tr, ['identity', 'invert', 'scale'], true)
					|| !in_array($pct_tr, ['identity', 'invert'], true)
					|| !in_array($rtype, ['', 'CPU', 'Memory'], true)
					|| !in_array($os, ['Linux', 'Windows', 'Unknown'], true)
					|| !in_array($fs_kind, ['Local', 'Remote'], true)
					|| !is_bool($current_observation_usable)
					|| !in_array($data_status, ['', 'OK', 'Stale', 'Missing', 'Invalid current value'], true)) {
				return null;
			}
			$specs[] = [
				'id' => $id,
				'itemid' => $itemid,
				'pct_itemid' => $kind === 'disk' ? $pct_itemid : '',
				'kind' => $kind,
				'tr' => $tr,
				'pct_tr' => $kind === 'disk' ? $pct_tr : 'identity',
				'rtype' => $rtype,
				'os' => $os,
				'fs_kind' => $fs_kind,
				'data_status' => $data_status,
				'current_observation_usable' => $current_observation_usable,
				'ok' => !isset($entry['ok']) || !empty($entry['ok']),
				'lastclock' => (int) ($num($entry['lastclock'] ?? null, 0.0, 5000000000.0) ?? 0),
				'pr' => $num($entry['pr'] ?? null),
				'pct_pr' => $num($entry['pct_pr'] ?? null),
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

		// The client is told this limit via inventory meta and batches resource
		// specs separately. Enforce it server-side too: every resource spec can
		// trigger a bounded but expensive raw-history scan.
		$resource_specs = 0;
		foreach ($specs as $spec) {
			if ($spec['kind'] === 'res') {
				$resource_specs++;
			}
		}
		if ($resource_specs > self::RESOURCE_FORECAST_BATCH_MAX) {
			return null;
		}

		return $specs;
	}

	/**
	 * refresh=1 forces uncached live API loads for the whole batch. That
	 * privilege requires proof the request originated from this module's page:
	 * CSRF stays disabled for ordinary (cache-friendly) data loads, but a forced
	 * refresh without a valid per-action token silently downgrades to a normal
	 * cached load (observable via meta.cache.forced_refresh) instead of failing.
	 */
	private function hasValidRefreshToken(): bool {
		$token = (string) $this->getInput(self::csrfTokenField(), '');

		return $token !== '' && class_exists('CCsrfTokenHelper')
			&& method_exists('CCsrfTokenHelper', 'check')
			&& \CCsrfTokenHelper::check($token, 'capacity.planning.data');
	}

	/**
	 * The client submits the token under CCsrfTokenHelper::CSRF_TOKEN_NAME (the
	 * view passes that name to the browser), so the server must key on the same
	 * constant rather than a hardcoded copy of its current value.
	 */
	private static function csrfTokenField(): string {
		return class_exists('CCsrfTokenHelper') && defined('CCsrfTokenHelper::CSRF_TOKEN_NAME')
			? (string) \CCsrfTokenHelper::CSRF_TOKEN_NAME
			: '_csrf_token';
	}

	/** Return API-shaped hourly trend rows for one bounded shard. */
	private function fetchTrendRowsLive(string $itemid, int $time_from, int $time_to): array {
		$rows = API::Trend()->get([
			'output' => ['clock', 'num', 'value_min', 'value_avg', 'value_max'],
			'itemids' => [$itemid],
			'time_from' => $time_from,
			'time_till' => $time_to,
			'limit' => self::MAX_TREND_ROWS
		]);

		return is_array($rows) ? $rows : [];
	}

	/**
	 * Fetch hourly trend rows for one item (sorted; trend.get has no ORDER BY),
	 * falling back to a bounded raw-history scan bucketed to hourly rows.
	 *
	 * @return array{0: string, 1: array<int, array>, 2: ?string} [source, rows, note]
	 */
	private function fetchSeriesRows(string $itemid, int $value_type, int $time_from, int $time_to,
			bool $include_recent_history = false, bool $authorized = false): array {
		if (!in_array($value_type, [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true)) {
			return ['none', [], _('Unsupported item value type for trend analysis.')];
		}

		$trend_rows = [];
		$trend_result = $this->fetchCachedRange(
			$itemid,
			$value_type,
			'trend',
			$time_from,
			$time_to,
			$authorized,
			fn (int $range_from, int $range_to): array => $this->fetchTrendRowsLive(
				$itemid, $range_from, $range_to)
		);
		$trends = $trend_result['rows'];
		if (is_array($trends)) {
			$deduped = [];
			foreach ($trends as $row) {
				$clock = (int) ($row['clock'] ?? 0);
				$avg = $this->safeFloat($row['value_avg'] ?? null);
				$min = $this->safeFloat($row['value_min'] ?? null);
				$max = $this->safeFloat($row['value_max'] ?? null);
				if ($clock > 0 && $avg !== null && $min !== null && $max !== null) {
					$deduped[$clock] = [
						'clock' => $clock,
						'num' => max(1, (int) ($row['num'] ?? 1)),
						'min' => $min,
						'avg' => $avg,
						'max' => $max
					];
				}
			}
			ksort($deduped);
			$trend_rows = array_values($deduped);
		}
		$trend_expected = 1;
		if ($trend_rows) {
			$counts = array_map(static fn (array $row): int => $row['num'], $trend_rows);
			$trend_expected = max(1, (int) round($this->median($counts)));
		}
		foreach ($trend_rows as &$row) {
			$row['source'] = 'trend';
			$row['bucket_seconds'] = 3600;
			$row['expected_num'] = $trend_expected;
			$row['bucket_coverage_pct'] = min(100.0, $row['num'] / $trend_expected * 100);
			$row['complete'] = $row['clock'] + 3600 <= $time_to;
			$row['duration_confirmable'] = $row['num'] >= 2;
			$row['baseline_eligible'] = true;
		}
		unset($row);

		if ($include_recent_history) {
			$recent_from = max($time_from, $time_to - self::RESOURCE_HISTORY_DAYS * 86400);
			[$raw_history, $truncated] = $this->fetchRawHistoryValues(
				$itemid, $value_type, $recent_from, $time_to, true, $authorized);
			$recent_rows = $this->bucketHistoryRows($raw_history, self::RECENT_RESOURCE_BUCKET_SECONDS,
				$time_to, 'recent_history');
			if ($recent_rows) {
				$combined = $this->mergeRecentResourceRows($trend_rows, $recent_rows);
				$note = sprintf(_('Recent %1$d-day history was aggregated into five-minute buckets for saturation analysis.'),
					self::RESOURCE_HISTORY_DAYS);
				if ($truncated) {
					$note .= ' '._('The raw-history safety limit was reached; older recent samples were truncated.');
				}
				return [$trend_rows ? 'trends+5m history' : '5m history', $combined, $note];
			}
		}

		if ($trend_rows) {
			return ['trends', $trend_rows,
				$include_recent_history ? _('Recent high-resolution history was unavailable.') : null];
		}

		$fallback_from = max($time_from, $time_to - self::HISTORY_FALLBACK_DAYS * 86400);
		[$raw_history, $truncated] = $this->fetchRawHistoryValues(
			$itemid, $value_type, $fallback_from, $time_to, true, $authorized);
		$rows = $this->bucketHistoryRows($raw_history, 3600, $time_to, 'history_fallback');
		if (!$rows) {
			return ['none', [], _('No trend or history values were returned.')];
		}
		$note = _('Hourly trends were unavailable; a bounded raw-history fallback was used.');
		if ($truncated) {
			$note .= ' '._('The raw-history safety limit was reached; older samples were omitted.');
		}
		return ['history', $rows, $note];
	}

	/**
	 * Merge recent five-minute evidence with hourly trends without losing either
	 * baseline fidelity or duration evidence. Raw data replaces the baseline once
	 * it covers 75% of an hour, but replaces the trend's duration evidence only
	 * when duration-confirmable raw buckets independently cover that much time.
	 */
	private function mergeRecentResourceRows(array $trend_rows, array $recent_rows): array {
		$by_hour = [];
		foreach ($recent_rows as $index => $row) {
			$by_hour[intdiv($row['clock'], 3600)][] = $index;
		}
		$usable_hours = [];
		$duration_hours = [];
		foreach ($by_hour as $hour => $indexes) {
			$observed = 0.0;
			$duration_observed = 0.0;
			foreach ($indexes as $index) {
				$row = $recent_rows[$index];
				if (!empty($row['complete'])) {
					$seconds = max(1, (int) ($row['bucket_seconds'] ?? self::RECENT_RESOURCE_BUCKET_SECONDS))
						* min(1.0, max(0.0, (float) ($row['bucket_coverage_pct'] ?? 0)) / 100);
					$observed += $seconds;
					if (!empty($row['duration_confirmable'])) {
						$duration_observed += $seconds;
					}
				}
			}
			$required = self::SATURATION_MIN_BUCKET_COVERAGE_PCT / 100 * 3600;
			if ($observed >= $required) {
				$usable_hours[(int) $hour] = true;
			}
			if ($duration_observed >= $required) {
				$duration_hours[(int) $hour] = true;
			}
		}

		foreach ($recent_rows as &$row) {
			$row['baseline_eligible'] = isset($usable_hours[intdiv($row['clock'], 3600)]);
		}
		unset($row);
		$combined = [];
		foreach ($trend_rows as $row) {
			$hour = intdiv($row['clock'], 3600);
			if (isset($duration_hours[$hour])) {
				continue;
			}
			if (isset($usable_hours[$hour])) {
				$row['baseline_eligible'] = false;
				$row['saturation_only'] = true;
			}
			$combined[] = $row;
		}
		foreach ($recent_rows as $row) {
			$combined[] = $row;
		}
		usort($combined, static fn (array $a, array $b): int => $a['clock'] <=> $b['clock']);

		return $combined;
	}

	/** @return array{0: array<int, array{clock: int, ns: int, value: float}>, 1: bool} */
	private function fetchRawHistoryValues(string $itemid, int $value_type, int $time_from, int $time_to,
			bool $newest_first = false, bool $authorized = false): array {
		$remaining = self::MAX_HISTORY_ROWS;
		$truncated = false;
		$loader = function (int $range_from, int $range_to) use (
				$itemid, $value_type, $newest_first, &$remaining, &$truncated): array {
			if ($remaining <= 0) {
				$truncated = true;
				throw new SeriesRangeIncompleteException([], 'history_row_limit');
			}

			[$rows, $part_truncated] = $this->fetchRawHistoryValuesLive(
				$itemid, $value_type, $range_from, $range_to, $newest_first, $remaining);
			$remaining = max(0, $remaining - count($rows));
			$truncated = $truncated || $part_truncated;
			if ($part_truncated) {
				// Return the evidence already fetched, but explicitly prevent the
				// cache from recording the rest of this range as covered/empty.
				throw new SeriesRangeIncompleteException($rows, 'history_row_limit');
			}

			return $rows;
		};

		$result = $this->fetchCachedRange(
			$itemid, $value_type, 'history', $time_from, $time_to, $authorized, $loader);
		$values = $result['rows'];
		usort($values, static function (array $a, array $b) use ($newest_first): int {
			$comparison = ((int) $a['clock'] <=> (int) $b['clock'])
				?: ((int) ($a['ns'] ?? 0) <=> (int) ($b['ns'] ?? 0));
			return $newest_first ? -$comparison : $comparison;
		});
		if (count($values) > self::MAX_HISTORY_ROWS) {
			$values = array_slice($values, 0, self::MAX_HISTORY_ROWS);
			$truncated = true;
		}

		return [$values, $truncated];
	}

	/** Direct, bounded History API loader used only behind the authorized cache wrapper. */
	private function fetchRawHistoryValuesLive(string $itemid, int $value_type, int $time_from, int $time_to,
			bool $newest_first, int $max_rows): array {
		$values = [];
		$cursor = $newest_first ? $time_to : $time_from;
		$fetched = 0;
		$truncated = false;
		$max_rows = max(0, min(self::MAX_HISTORY_ROWS, $max_rows));
		while ($fetched < $max_rows) {
			$limit = min(self::HISTORY_FETCH_BATCH, $max_rows - $fetched);
			$batch = API::History()->get([
				'output' => ['clock', 'ns', 'value'],
				'history' => $value_type,
				'itemids' => [$itemid],
				'time_from' => $newest_first ? $time_from : $cursor,
				'time_till' => $newest_first ? $cursor : $time_to,
				'sortfield' => 'clock',
				'sortorder' => $newest_first ? 'DESC' : 'ASC',
				'limit' => $limit
			]);
			if (!is_array($batch) || !$batch) {
				break;
			}
			$edge_clock = $cursor;
			foreach ($batch as $row) {
				$clock = (int) ($row['clock'] ?? 0);
				$value = $this->safeFloat($row['value'] ?? null);
				if ($clock > 0 && $value !== null) {
					$values[] = [
						'clock' => $clock,
						'ns' => max(0, (int) ($row['ns'] ?? 0)),
						'value' => $value
					];
				}
				$edge_clock = $newest_first ? min($edge_clock, $clock) : max($edge_clock, $clock);
			}
			$fetched += count($batch);
			if (count($batch) < $limit) {
				break;
			}
			$cursor = $newest_first ? $edge_clock - 1 : $edge_clock + 1;
			if (($newest_first && $cursor < $time_from) || (!$newest_first && $cursor > $time_to)) {
				break;
			}
		}
		if ($max_rows > 0 && $fetched >= $max_rows) {
			$truncated = true;
		}
		return [$values, $truncated];
	}

	/**
	 * Permission-safe cache boundary. Callers must pass the result of the live
	 * item.get authorization performed in handleForecast(); SeriesCache rejects a
	 * false value before deriving an item key or opening a file.
	 */
	private function fetchCachedRange(string $itemid, int $value_type, string $series_kind, int $time_from,
			int $time_to, bool $authorized, callable $loader): array {
		if ($this->series_cache === null) {
			throw new \LogicException('Series cache boundary was used before live item authorization.');
		}

		$result = $this->series_cache->fetchRange(
			$itemid,
			$value_type,
			$series_kind,
			$time_from,
			$time_to,
			$authorized,
			$loader,
			$this->force_series_refresh
		);
		$this->recordCacheResult(is_array($result['cache'] ?? null) ? $result['cache'] : []);

		return $result;
	}

	private function recordCacheResult(array $meta): void {
		$this->cache_request_meta['requests']++;
		$this->cache_request_meta['shard_hits'] += max(0, (int) ($meta['shard_hits'] ?? 0));
		$this->cache_request_meta['shard_misses'] += max(0, (int) ($meta['shard_misses'] ?? 0));
		$this->cache_request_meta['shards_written'] += max(0, (int) ($meta['shards_written'] ?? 0));
		if (!empty($meta['live_fallback'])) {
			$this->cache_request_meta['live_fallbacks']++;
		}
		$reason = trim((string) ($meta['reason'] ?? ''));
		if ($reason !== '') {
			$this->cache_request_meta['reasons'][$reason] = true;
		}
		foreach (['enabled', 'backend_available', 'ttl_seconds', 'protection', 'cache_schema',
			'module_generation'] as $key) {
			if (array_key_exists($key, $meta)) {
				$this->cache_runtime_meta[$key] = $meta[$key];
			}
		}
	}

	private function forecastCacheMeta(): array {
		$summary = $this->cache_request_meta;
		$summary['reasons'] = array_keys($summary['reasons']);

		return array_replace($this->cache_runtime_meta, [
			'forced_refresh' => $this->force_series_refresh,
			'request' => $summary
		]);
	}

	private function bucketHistoryRows(array $values, int $bucket_seconds, int $time_to, string $source): array {
		$buckets = [];
		$clocks = [];
		foreach ($values as $sample) {
			$clock = (int) $sample['clock'];
			$value = (float) $sample['value'];
			$clocks[$clock] = true;
			$bucket = $clock - ($clock % $bucket_seconds);
			if (!isset($buckets[$bucket])) {
				$buckets[$bucket] = ['n' => 0, 'sum' => 0.0, 'min' => $value, 'max' => $value];
			}
			$buckets[$bucket]['n']++;
			$buckets[$bucket]['sum'] += $value;
			$buckets[$bucket]['min'] = min($buckets[$bucket]['min'], $value);
			$buckets[$bucket]['max'] = max($buckets[$bucket]['max'], $value);
		}
		if (!$buckets) {
			return [];
		}
		$ordered_clocks = array_keys($clocks);
		sort($ordered_clocks, SORT_NUMERIC);
		$diffs = [];
		for ($i = 1, $count = count($ordered_clocks); $i < $count; $i++) {
			$diff = $ordered_clocks[$i] - $ordered_clocks[$i - 1];
			if ($diff > 0 && $diff <= $bucket_seconds * 4) {
				$diffs[] = $diff;
			}
		}
		if ($diffs) {
			$sample_interval = max(1, (int) round($this->median($diffs)));
			$expected = max(1, (int) ceil($bucket_seconds / $sample_interval));
		}
		else {
			$bucket_counts = array_map(static fn (array $bucket): int => $bucket['n'], $buckets);
			$expected = max(1, (int) round($this->median($bucket_counts)));
		}
		ksort($buckets, SORT_NUMERIC);
		$rows = [];
		foreach ($buckets as $clock => $bucket) {
			$rows[] = [
				'clock' => (int) $clock,
				'num' => $bucket['n'],
				'min' => $bucket['min'],
				'avg' => $bucket['sum'] / $bucket['n'],
				'max' => $bucket['max'],
				'source' => $source,
				'bucket_seconds' => $bucket_seconds,
				'expected_num' => $expected,
				'bucket_coverage_pct' => min(100.0, $bucket['n'] / $expected * 100),
				'complete' => $clock + $bucket_seconds <= $time_to,
				'duration_confirmable' => $bucket['n'] >= 2,
				'baseline_eligible' => true
			];
		}
		return $rows;
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
			$transformed = $row;
			if ($transform === 'invert') {
				$transformed['min'] = $parameter - $row['max'];
				$transformed['avg'] = $parameter - $row['avg'];
				$transformed['max'] = $parameter - $row['min'];
			}
			else { // scale
				$transformed['min'] = $row['min'] * $parameter;
				$transformed['avg'] = $row['avg'] * $parameter;
				$transformed['max'] = $row['max'] * $parameter;
			}
			$out[] = $transformed;
		}
		return $out;
	}

	/** @return array{0: array, 1: int} valid percentage rows and rejected count */
	private function sanitizePercentageRows(array $rows): array {
		$valid = [];
		$rejected = 0;
		foreach ($rows as $row) {
			$minimum = $this->safeFloat($row['min'] ?? null);
			$average = $this->safeFloat($row['avg'] ?? null);
			$maximum = $this->safeFloat($row['max'] ?? null);
			if ($minimum === null || $average === null || $maximum === null
					|| $minimum < -0.5 || $maximum > 100.5
					|| $minimum > $average || $average > $maximum) {
				$rejected++;
				continue;
			}
			$row['min'] = max(0.0, $minimum);
			$row['avg'] = min(100.0, max(0.0, $average));
			$row['max'] = min(100.0, $maximum);
			$valid[] = $row;
		}
		return [$valid, $rejected];
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
			$label = ['12m' => '12 months', '6m' => '6 months', '3m' => '3 months',
				'1m' => '1 month', '2w' => '2 weeks', '1w' => '1 week'][$window];
			$cutoff = $now - $days * 86400;
			$selected = [];
			foreach ($rows as $row) {
				if ($row['clock'] >= $cutoff) {
					$selected[] = $row;
				}
			}
			if (!$selected) {
				$result[$window] = ['label' => $label, 'requested_days' => $days, 'days' => 0, 'cov' => 0.0, 'n' => 0,
					'avg' => null, 'p95' => null,
					'peak' => null, 'slope' => null, 'r2' => null, 'above_warn' => null, 'above_crit' => null,
					'start_value' => null, 'end_value' => null, 'net_change' => null,
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
				'label' => $label,
				'requested_days' => $days,
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
				'start_value' => $selected[0]['avg'],
				'end_value' => $selected[count($selected) - 1]['avg'],
				'net_change' => $selected[count($selected) - 1]['avg'] - $selected[0]['avg'],
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
			if ($stats['slope'] !== null && $stats['days'] >= 5 && $stats['cov'] >= 25.0
					&& $stats['days'] > $best_days) {
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

	private function forecastDisk(array $spec, array $rows, string $source, int $now, ?string $note,
			array $direct_pct_rows = [], string $pct_source = 'none', ?string $pct_note = null): array {
		$current_observation_usable = ($spec['current_observation_usable'] ?? true) === true;
		// Usable filesystem capacity is used+free. Linux total can include reserved
		// blocks and must not silently become the percentage denominator.
		$capacity = null;
		if ($spec['used'] !== null && $spec['free'] !== null && $spec['used'] + $spec['free'] > 0) {
			$capacity = $spec['used'] + $spec['free'];
		}
		elseif ($spec['used'] !== null && $spec['pused'] !== null && $spec['pused'] > 0) {
			$capacity = $spec['used'] / ($spec['pused'] / 100);
		}
		elseif ($spec['os'] === 'Windows' && $spec['total'] !== null && $spec['total'] > 0) {
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
		$free_ref = $capacity;
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
		[$pct_rows, $rejected_pct_rows] = $this->sanitizePercentageRows($direct_pct_rows);
		$pct_series_direct = (bool) $pct_rows;
		if (!$pct_rows && $rows && $capacity !== null && $capacity > 0) {
			$derived_pct_rows = $this->transformRows($rows, 'scale', 100 / $capacity);
			[$pct_rows, $derived_rejected] = $this->sanitizePercentageRows($derived_pct_rows);
			$rejected_pct_rows += $derived_rejected;
			$pct_source = $source.' (derived from usable capacity)';
		}
		if ($rejected_pct_rows > 0) {
			$invalid_pct_note = sprintf(_('Excluded %1$d used-percentage bucket(s) outside 0-100%%.'),
				$rejected_pct_rows);
			$pct_note = $pct_note === null ? $invalid_pct_note : $pct_note.' '.$invalid_pct_note;
		}
		$pct_windows = $this->summarizeWindows($pct_rows, $now, $spec['warn_pct'], $spec['crit_pct']);
		$selected_window = $this->selectModelWindow($windows);
		$selected = $selected_window !== null ? $windows[$selected_window] : null;
		$selected_pct_window = $this->selectModelWindow($pct_windows);
		$selected_pct = $selected_pct_window !== null ? $pct_windows[$selected_pct_window] : null;
		if ($pct_series_direct && $selected_pct_window === null && $rows && $capacity !== null && $capacity > 0) {
			$derived_pct_rows = $this->transformRows($rows, 'scale', 100 / $capacity);
			[$derived_pct_rows, $derived_rejected] = $this->sanitizePercentageRows($derived_pct_rows);
			$derived_pct_windows = $this->summarizeWindows(
				$derived_pct_rows, $now, $spec['warn_pct'], $spec['crit_pct']);
			$derived_pct_window = $this->selectModelWindow($derived_pct_windows);
			if ($derived_pct_window !== null) {
				$pct_rows = $derived_pct_rows;
				$pct_windows = $derived_pct_windows;
				$selected_pct_window = $derived_pct_window;
				$selected_pct = $pct_windows[$selected_pct_window];
				$pct_series_direct = false;
				$pct_source = $source.' (derived from usable capacity)';
				$fallback_note = _('Direct used-percentage history did not meet the model coverage requirements; percentage growth was derived from the qualified byte series and current usable capacity.');
				$pct_note = $pct_note === null ? $fallback_note : $pct_note.' '.$fallback_note;
				if ($derived_rejected > 0) {
					$derived_note = sprintf(_('Excluded %1$d derived used-percentage bucket(s) outside 0-100%%.'),
						$derived_rejected);
					$pct_note .= ' '.$derived_note;
				}
			}
		}

		$slope = $selected !== null ? $selected['slope'] : null;
		$pct_slope = $selected_pct !== null ? $selected_pct['slope'] : null;
		$accelerating = false;
		$recent = $windows['1m'] ?? null;
		$recent_pct = $pct_windows['1m'] ?? null;
		if ($slope !== null && $recent !== null && $recent['slope'] !== null
				&& $recent['days'] >= 21 && $recent['cov'] >= 70 && ($recent['r2'] ?? 0) >= 0.35
				&& $recent['slope'] > max($slope * 1.5, self::MIN_DISK_GROWTH_BYTES_DAY)) {
			$selected_window = '1m';
			$selected = $recent;
			$slope = $recent['slope'];
			if (!$pct_series_direct && $recent_pct !== null && $recent_pct['slope'] !== null) {
				$selected_pct_window = '1m';
				$selected_pct = $recent_pct;
				$pct_slope = $recent_pct['slope'];
			}
			$accelerating = true;
		}
		$byte_confidence = $this->forecastConfidence($selected, $windows);
		$pct_confidence = $this->forecastConfidence($selected_pct, $pct_windows);
		if ($slope !== null && $slope < self::MIN_DISK_GROWTH_BYTES_DAY) {
			$slope = null;
		}
		if ($pct_slope !== null && $pct_slope <= 0) {
			$pct_slope = null;
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

		$current_snapshot_usable = $current_observation_usable && ($spec['ok'] ?? false);
		$current_pused = $current_snapshot_usable ? $spec['pused'] : null;
		$current_free = $current_snapshot_usable ? $spec['free'] : null;
		$days_warn_pct_only = ($spec['warn_pct'] !== null && $current_pused !== null)
			? $eta($spec['warn_pct'] - $current_pused, $pct_slope)
			: null;
		$days_warn_gb = ($free_ref !== null && ($spec['warn_free'] ?? 0) > 0 && $current_free !== null)
			? $eta($current_free - $spec['warn_free'], $slope)
			: null;
		$days_crit_pct_only = ($spec['crit_pct'] !== null && $current_pused !== null)
			? $eta($spec['crit_pct'] - $current_pused, $pct_slope)
			: null;
		$days_crit_gb = ($free_ref !== null && ($spec['crit_free'] ?? 0) > 0 && $current_free !== null)
			? $eta($current_free - $spec['crit_free'], $slope)
			: null;
		$days_full = $current_free !== null ? $eta($current_free, $slope) : null;

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

		$current_warn_pct = $current_pused !== null && $spec['warn_pct'] !== null
			&& $current_pused > $spec['warn_pct'];
		$current_crit_pct = $current_pused !== null && $spec['crit_pct'] !== null
			&& $current_pused > $spec['crit_pct'];
		$current_warn_free = $current_free !== null && ($spec['warn_free'] ?? 0) > 0
			&& $current_free < $spec['warn_free'];
		$current_crit_free = $current_free !== null && ($spec['crit_free'] ?? 0) > 0
			&& $current_free < $spec['crit_free'];
		$pct_severity = $this->diskSeverity($current_warn_pct, $current_crit_pct,
			$days_warn_pct_only, $days_crit_pct_only, null, $pct_confidence,
			$current_snapshot_usable && $selected_pct_window !== null);
		$byte_severity = $this->diskSeverity($current_warn_free, $current_crit_free,
			$days_warn_gb, $days_crit_gb, $days_full, $byte_confidence,
			$current_snapshot_usable && $selected_window !== null);
		if (self::SEVERITY_ORDER[$pct_severity] > self::SEVERITY_ORDER[$byte_severity]) {
			$severity = $pct_severity;
			$confidence = $pct_confidence;
		}
		elseif (self::SEVERITY_ORDER[$byte_severity] > self::SEVERITY_ORDER[$pct_severity]) {
			$severity = $byte_severity;
			$confidence = $byte_confidence;
		}
		else {
			$severity = $byte_severity;
			$confidence = self::CONFIDENCE_ORDER[$byte_confidence] <= self::CONFIDENCE_ORDER[$pct_confidence]
				? $byte_confidence
				: $pct_confidence;
		}
		$notes = [];
		if (!$current_observation_usable) {
			$notes[] = _('The host is in maintenance without data collection; current threshold breaches and ETAs were not inferred from the last accepted values.');
		}
		elseif (!$current_snapshot_usable) {
			$notes[] = _('The current filesystem snapshot was incomplete or invalid; current threshold breaches and ETAs were not inferred.');
		}
		foreach ([$note, $pct_note] as $candidate_note) {
			if ($candidate_note !== null && trim($candidate_note) !== '' && !in_array($candidate_note, $notes, true)) {
				$notes[] = $candidate_note;
			}
		}

		return [
			'id' => $spec['id'],
			'status' => ($rows || $pct_rows) ? 'ok' : 'no_data',
			'source' => $source,
			'pct_source' => $pct_source,
			'pct_series_direct' => $pct_series_direct,
			'note' => $notes ? implode(' ', $notes) : null,
			'windows' => $this->roundWindows($windows),
			'pct_windows' => $this->roundWindows($pct_windows),
			'sel' => $selected_window,
			'sel_label' => $accelerating ? _('1 month (acceleration override)') : null,
			'pct_sel' => $selected_pct_window,
			'pct_sel_label' => $selected_pct !== null ? $selected_pct['label'] : null,
			'byte_confidence' => $byte_confidence,
			'pct_confidence' => $pct_confidence,
			'byte_severity' => $byte_severity,
			'pct_severity' => $pct_severity,
			'confidence' => $confidence,
			'accelerating' => $accelerating,
			'growth_day' => $slope,
			'growth_pct_day' => $pct_slope !== null ? round($pct_slope, 5) : null,
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
			'recommendation' => $this->diskRecommendation($severity, $spec['fs_kind']),
			'series' => $this->downsampleSeries($rows),
			'pct_series' => $this->downsampleSeries($pct_rows)
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

	private function diskRecommendation(string $severity, string $filesystem_kind): string {
		$remediation = $filesystem_kind === 'Remote'
			? _('coordinate with the storage/share owner on cleanup, retention or quota/capacity')
			: _('clean up safely or extend this filesystem');
		switch ($severity) {
			case 'Critical':
				return sprintf(_('Act now: validate the growth source and %1$s. Confirm backup and application impact before changes.'),
					$remediation);
			case 'High':
				return sprintf(_('Plan remediation in the current change window; identify the growth owner and %1$s.'),
					$remediation);
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

	private function forecastResource(array $spec, array $rows, string $source, int $now, ?string $note): array {
		$current_observation_usable = ($spec['current_observation_usable'] ?? true) === true;
		[$rows, $rejected_rows] = $this->sanitizePercentageRows($rows);
		$baseline_rows = array_values(array_filter($rows,
			static fn (array $row): bool => !isset($row['baseline_eligible']) || $row['baseline_eligible']));
		$windows = $this->summarizeWindows($baseline_rows, $now, $spec['warn'], $spec['crit']);
		$selected_window = $this->selectResourceWindow($windows);
		$selected = $selected_window !== null ? $windows[$selected_window] : null;
		$selected_label = $selected !== null ? $selected['label'] : null;
		$current_fresh = $current_observation_usable && $spec['lastclock'] > 0
			&& $now - $spec['lastclock'] <= self::STALE_RESOURCE_SECONDS;
		$was_stale = !$current_fresh || $spec['data_status'] === 'Stale';

		$regime = $this->detectResourceRegimeShift($baseline_rows, $now, $spec);
		if ($regime['detected'] && $regime['post_change_stats'] !== null) {
			$selected = $regime['post_change_stats'];
			$selected_window = 'regime';
			$selected_label = $selected['label'];
		}
		$confidence = $this->resourceConfidence($selected, $was_stale);
		$selected_source = $source;
		if ($selected !== null && $selected['first'] !== null && $selected['last'] !== null) {
			$selected_rows = array_values(array_filter($baseline_rows,
				static fn (array $row): bool => $row['clock'] >= $selected['first'] && $row['clock'] <= $selected['last']));
			$selected_source = $this->resourceRowsSource($selected_rows);
		}

		$baseline_reasons = [];
		$baseline_severity = $this->resourceSeverity(
			$spec, $selected, $current_fresh, $baseline_reasons);
		$historical_saturation = null;
		if ($regime['detected'] && $regime['change_clock'] !== null) {
			$historical_saturation = $this->analyzeResourceSaturation(
				$spec, $rows, $now, false, null, $regime['change_clock']);
			$saturation = $this->analyzeResourceSaturation(
				$spec, $rows, $now, $current_fresh, $regime['change_clock'], null);
		}
		else {
			$saturation = $this->analyzeResourceSaturation($spec, $rows, $now, $current_fresh);
		}
		$saturation_severity = $saturation['severity'];
		if ($baseline_severity === 'Unknown' && $saturation_severity === 'Healthy') {
			// Insufficient baseline coverage is not proof of health.
			$severity = 'Unknown';
		}
		else {
			$severity = self::SEVERITY_ORDER[$baseline_severity] >= self::SEVERITY_ORDER[$saturation_severity]
				? $baseline_severity
				: $saturation_severity;
		}
		if (self::SEVERITY_ORDER[$saturation_severity] > self::SEVERITY_ORDER[$baseline_severity]) {
			$confidence = $saturation['confidence'];
		}
		elseif ($regime['detected']) {
			$confidence = self::CONFIDENCE_ORDER[$confidence] <= self::CONFIDENCE_ORDER[$regime['confidence']]
				? $confidence
				: $regime['confidence'];
		}

		$reasons = [];
		if ($regime['detected']) {
			$reasons[] = $regime['reason'];
		}
		if ($historical_saturation !== null && $historical_saturation['reason'] !== '') {
			$reasons[] = _('Pre-change saturation retained as historical evidence and not used to escalate current risk: ')
				.$historical_saturation['reason'];
		}
		if ($saturation['reason'] !== '') {
			$reasons[] = $saturation['reason'];
		}
		foreach ($baseline_reasons as $reason) {
			$reasons[] = $reason;
		}
		if (!$current_observation_usable) {
			$reasons[] = _('The host is in maintenance without data collection; the last accepted value was not allowed to escalate the rating.');
		}
		elseif (!$current_fresh && $spec['current'] !== null) {
			$reasons[] = _('The current value is stale and was not allowed to escalate the rating.');
		}
		if ($rejected_rows > 0) {
			$invalid_note = sprintf(_('Excluded %1$d utilization bucket(s) outside the plausible 0-100%% range.'),
				$rejected_rows);
			$note = $note ? $note.' '.$invalid_note : $invalid_note;
			$reasons[] = $invalid_note;
		}

		$public_regime = $regime;
		unset($public_regime['post_change_stats'], $public_regime['prior_stats']);
		return [
			'id' => $spec['id'],
			'status' => $rows ? 'ok' : 'no_data',
			'source' => $source,
			'note' => $note,
			'windows' => $this->roundWindows($windows),
			'sel' => $selected_window,
			'sel_label' => $selected_label,
			'selected' => $this->roundWindowStats($selected),
			'selected_source' => $selected_source,
			'confidence' => $confidence,
			'growth_pct_day' => $selected !== null && $selected['slope'] !== null
				? round($selected['slope'], 5)
				: null,
			'baseline_severity' => $baseline_severity,
			'saturation_severity' => $saturation_severity,
			'severity' => $severity,
			'reasons' => $reasons,
			'regime' => $this->roundRegime($public_regime),
			'saturation' => $this->roundSaturation($saturation),
			'historical_saturation' => $historical_saturation !== null
				? $this->roundSaturation($historical_saturation)
				: null,
			'recommendation' => $this->resourceRecommendation(
				$spec['rtype'], $severity, $selected, $saturation, $regime),
			'series' => $this->downsampleSeries($baseline_rows)
		];
	}

	private function selectResourceWindow(array $windows): ?string {
		foreach (['1m', '2w', '3m', '1w', '6m', '12m'] as $window) {
			$stats = $windows[$window] ?? null;
			[$minimum_days, $minimum_coverage] = self::RESOURCE_WINDOW_REQUIREMENTS[$window];
			if ($stats !== null && $stats['n'] > 0 && $stats['days'] >= $minimum_days
					&& $stats['cov'] >= $minimum_coverage) {
				return $window;
			}
		}
		return null;
	}

	private function resourceConfidence(?array $stats, bool $stale): string {
		if ($stats === null) {
			return 'Low';
		}
		$completeness = $stats['days'] / max(1, $stats['requested_days']) * 100;
		if ($stats['cov'] >= 80 && $completeness >= 80) {
			$confidence = 'High';
		}
		elseif ($stats['cov'] >= 60 && $completeness >= 65) {
			$confidence = 'Medium';
		}
		else {
			$confidence = 'Low';
		}
		if ($stale && $confidence === 'High') {
			return 'Medium';
		}
		return $stale && $confidence === 'Medium' ? 'Low' : $confidence;
	}

	private function resourceSeverity(array $spec, ?array $stats, bool $allow_current, array &$reasons): string {
		if ($stats === null) {
			$reasons[] = _('No resource window met the minimum coverage and duration requirements.');
			if ($allow_current && $spec['warn'] !== null && $spec['current'] !== null
					&& $spec['current'] >= $spec['warn']) {
				$reasons[] = sprintf(_('Fresh current value %1$s is above the %2$s%% review threshold; duration is not established.'),
					$this->formatPct($spec['current']), $this->trimFloat($spec['warn']));
				return 'Watch';
			}
			return 'Unknown';
		}
		$warn = $spec['warn'];
		$crit = $spec['crit'];
		$label = (string) ($stats['label'] ?? 'selected window');
		if ($crit !== null && (($stats['avg'] !== null && $stats['avg'] >= $crit)
				|| (($stats['above_crit'] ?? 0) >= 10 && ($stats['p95'] ?? 0) >= $crit))) {
			$reasons[] = sprintf(_('%1$s: average %2$s, p95 %3$s, and %4$.1f%% above the %5$s%% alarm threshold.'),
				$label, $this->formatPct($stats['avg']), $this->formatPct($stats['p95']),
				$stats['above_crit'] ?? 0, $this->trimFloat($crit));
			return 'Critical';
		}
		if (($crit !== null && ($stats['p95'] ?? 0) >= $crit && ($stats['above_crit'] ?? 0) >= 2)
				|| ($warn !== null && ($stats['above_warn'] ?? 0) >= 20 && ($stats['p95'] ?? 0) >= $warn)) {
			$reasons[] = sprintf(_('%1$s: p95 %2$s with %3$.1f%% above review and %4$.1f%% above alarm.'),
				$label, $this->formatPct($stats['p95']), $stats['above_warn'] ?? 0, $stats['above_crit'] ?? 0);
			return 'High';
		}
		if ($warn !== null && ($stats['p95'] ?? 0) >= $warn && ($stats['above_warn'] ?? 0) >= 5) {
			$reasons[] = sprintf(_('%1$s: p95 %2$s and %3$.1f%% above the %4$s%% review threshold.'),
				$label, $this->formatPct($stats['p95']), $stats['above_warn'] ?? 0, $this->trimFloat($warn));
			return 'Medium';
		}
		if ($allow_current && $warn !== null && $spec['current'] !== null && $spec['current'] >= $warn) {
			$reasons[] = sprintf(_('Fresh current value %1$s is above the %2$s%% review threshold.'),
				$this->formatPct($spec['current']), $this->trimFloat($warn));
			return 'Watch';
		}
		return 'Healthy';
	}

	private function resourceIntervalStats(array $rows, int $start_clock, int $end_clock, int $days,
			string $label, ?float $warn, ?float $crit): array {
		$selected = array_values(array_filter($rows,
			static fn (array $row): bool => $row['clock'] >= $start_clock && $row['clock'] < $end_clock
				&& (!isset($row['baseline_eligible']) || $row['baseline_eligible'])));
		usort($selected, static fn (array $a, array $b): int => $a['clock'] <=> $b['clock']);
		if (!$selected) {
			return ['label' => $label, 'requested_days' => $days, 'days' => 0, 'cov' => 0.0, 'n' => 0,
				'avg' => null, 'p95' => null, 'peak' => null, 'slope' => null, 'r2' => null,
				'above_warn' => null, 'above_crit' => null, 'start_value' => null, 'end_value' => null,
				'net_change' => null, 'first' => null, 'last' => null];
		}
		$weights = [];
		$values = [];
		$total = 0;
		$weighted_sum = 0.0;
		$hours = [];
		$peak = null;
		foreach ($selected as $row) {
			$weight = max(1, (int) $row['num']);
			$weights[] = $weight;
			$values[] = (float) $row['avg'];
			$total += $weight;
			$weighted_sum += $row['avg'] * $weight;
			$hours[intdiv($row['clock'], 3600)] = true;
			$peak = $peak === null ? $row['max'] : max($peak, $row['max']);
		}
		$first = $selected[0]['clock'];
		$last = $selected[count($selected) - 1]['clock'];
		[$slope, $r2] = $this->theilSen($this->dailyPoints($selected));
		$above = static function (?float $threshold) use ($values, $weights, $total): ?float {
			if ($threshold === null || $total <= 0) {
				return null;
			}
			$count = 0;
			foreach ($values as $index => $value) {
				if ($value > $threshold) {
					$count += $weights[$index];
				}
			}
			return $count / $total * 100;
		};
		return [
			'label' => $label,
			'requested_days' => $days,
			'days' => max(1, min($days, (int) ceil(($last - $first + 3600) / 86400))),
			'cov' => min(100.0, count($hours) / ($days * 24) * 100),
			'n' => $total,
			'avg' => $weighted_sum / $total,
			'p95' => $this->weightedPercentile($values, $weights, 0.95),
			'peak' => $peak,
			'slope' => $slope,
			'r2' => $r2,
			'above_warn' => $above($warn),
			'above_crit' => $above($crit),
			'start_value' => $values[0],
			'end_value' => $values[count($values) - 1],
			'net_change' => $values[count($values) - 1] - $values[0],
			'first' => $first,
			'last' => $last
		];
	}

	private function weightedRowAverage(array $rows): ?float {
		$total = 0;
		$sum = 0.0;
		foreach ($rows as $row) {
			$weight = max(1, (int) $row['num']);
			$total += $weight;
			$sum += $row['avg'] * $weight;
		}
		return $total > 0 ? $sum / $total : null;
	}

	private function detectResourceRegimeShift(array $rows, int $now, array $spec): array {
		$empty = ['detected' => false, 'direction' => 'none', 'change_clock' => null,
			'recent_days' => 0, 'prior_days' => 0, 'recent_average' => null, 'prior_average' => null,
			'delta_pct_points' => null, 'relative_change_pct' => null, 'recent_coverage_pct' => 0.0,
			'prior_coverage_pct' => 0.0, 'confidence' => 'None', 'reason' => '',
			'post_change_stats' => null, 'prior_stats' => null];
		$best = null;
		$best_score = -1.0;
		foreach ([7, 14, 21, 28, 31] as $days) {
			$change = $now - $days * 86400;
			$prior_start = $change - $days * 86400;
			$recent_rows = array_values(array_filter($rows,
				static fn (array $row): bool => $row['clock'] >= $change && $row['clock'] < $now));
			$prior_rows = array_values(array_filter($rows,
				static fn (array $row): bool => $row['clock'] >= $prior_start && $row['clock'] < $change));
			$recent = $this->resourceIntervalStats($recent_rows, $change, $now, $days,
				'post-change '.$days.' days', $spec['warn'], $spec['crit']);
			$prior = $this->resourceIntervalStats($prior_rows, $prior_start, $change, $days,
				'prior '.$days.' days', $spec['warn'], $spec['crit']);
			$minimum_days = (int) ceil($days * 0.75);
			if ($recent['avg'] === null || $prior['avg'] === null
					|| $recent['days'] < $minimum_days || $prior['days'] < $minimum_days
					|| $recent['cov'] < self::RESOURCE_REGIME_MIN_COVERAGE_PCT
					|| $prior['cov'] < self::RESOURCE_REGIME_MIN_COVERAGE_PCT) {
				continue;
			}
			$delta = $recent['avg'] - $prior['avg'];
			$relative = abs($delta) / max(abs($prior['avg']), 1.0) * 100;
			if (abs($delta) < self::RESOURCE_REGIME_MIN_DELTA_PCT_POINTS
					|| $relative < self::RESOURCE_REGIME_MIN_RELATIVE_PCT) {
				continue;
			}
			$recent_daily = array_map(static fn (array $point): float => $point[1],
				$this->dailyPoints($recent_rows));
			$prior_daily = array_map(static fn (array $point): float => $point[1],
				$this->dailyPoints($prior_rows));
			if (count($recent_daily) < $minimum_days || count($prior_daily) < $minimum_days) {
				continue;
			}
			$direction = $delta > 0 ? 'upward' : 'downward';
			$sign = $delta > 0 ? 1.0 : -1.0;
			$boundary = $this->median($prior_daily)
				+ $sign * self::RESOURCE_REGIME_MIN_DELTA_PCT_POINTS * 0.6;
			$persistent = 0;
			foreach ($recent_daily as $value) {
				if (($sign > 0 && $value >= $boundary) || ($sign < 0 && $value <= $boundary)) {
					$persistent++;
				}
			}
			if ($persistent < (int) ceil(count($recent_daily) * 0.70)) {
				continue;
			}
			$midpoint = $change + intdiv($days * 86400, 2);
			$first_half = $this->weightedRowAverage(array_values(array_filter($recent_rows,
				static fn (array $row): bool => $row['clock'] < $midpoint)));
			$second_half = $this->weightedRowAverage(array_values(array_filter($recent_rows,
				static fn (array $row): bool => $row['clock'] >= $midpoint)));
			$half_boundary = $prior['avg'] + $sign * self::RESOURCE_REGIME_MIN_DELTA_PCT_POINTS * 0.6;
			if ($first_half === null || $second_half === null
					|| ($sign > 0 && ($first_half < $half_boundary || $second_half < $half_boundary))
					|| ($sign < 0 && ($first_half > $half_boundary || $second_half > $half_boundary))) {
				continue;
			}
			$confidence = min($recent['cov'], $prior['cov']) >= 85 ? 'High' : 'Medium';
			$reason = sprintf(_('Non-overlapping %1$d-day baselines confirm a recent %2$s regime: average %3$.1f%% to %4$.1f%% (%5$+.1f percentage points, %6$.0f%% absolute relative change).'),
				$days, $direction, $prior['avg'], $recent['avg'], $delta, $relative);
			$candidate = ['detected' => true, 'direction' => $direction, 'change_clock' => $change,
				'recent_days' => $days, 'prior_days' => $days, 'recent_average' => $recent['avg'],
				'prior_average' => $prior['avg'], 'delta_pct_points' => $delta,
				'relative_change_pct' => $delta > 0 ? $relative : -$relative,
				'recent_coverage_pct' => $recent['cov'], 'prior_coverage_pct' => $prior['cov'],
				'confidence' => $confidence, 'reason' => $reason,
				'post_change_stats' => $recent, 'prior_stats' => $prior];
			$score = abs($delta) * min($recent['cov'], $prior['cov']) / 100;
			if ($score > $best_score) {
				$best = $candidate;
				$best_score = $score;
			}
		}
		return $best ?? $empty;
	}

	private function resourceRowsSource(array $rows): string {
		$labels = ['trend' => 'trends', 'recent_history' => '5m history',
			'history_fallback' => 'history fallback'];
		$sources = [];
		foreach ($rows as $row) {
			$source = (string) ($row['source'] ?? 'unknown');
			$sources[$labels[$source] ?? $source] = true;
		}
		unset($sources['']);
		$names = array_keys($sources);
		sort($names, SORT_STRING);
		return $names ? implode('+', $names) : 'unknown';
	}

	private function analyzeResourceSaturation(array $spec, array $rows, int $now, bool $current_fresh,
			?int $analysis_start = null, ?int $analysis_end = null): array {
		$threshold = $spec['rtype'] === 'CPU' ? self::CPU_SATURATION_PCT : self::MEMORY_SATURATION_PCT;
		$configured_cutoff = $now - self::SATURATION_WINDOW_DAYS * 86400;
		$cutoff = max($configured_cutoff, $analysis_start ?? $configured_cutoff);
		$end = min($now, $analysis_end ?? $now);
		$window_seconds = max(1, $end - $cutoff);
		$window_days = max(1, (int) ceil($window_seconds / 86400));
		$recent_hours = [];
		foreach ($rows as $row) {
			if (($row['source'] ?? '') === 'recent_history' && !empty($row['baseline_eligible'])
					&& $row['clock'] >= $cutoff && $row['clock'] < $end) {
				$recent_hours[intdiv($row['clock'], 3600)] = true;
			}
		}
		$selected = array_values(array_filter($rows,
			static fn (array $row): bool => $row['clock'] >= $cutoff && $row['clock'] < $end
				&& (($row['source'] ?? '') === 'recent_history'
					|| !empty($row['saturation_only'])
					|| !isset($recent_hours[intdiv($row['clock'], 3600)]))));
		usort($selected, static fn (array $a, array $b): int => $a['clock'] <=> $b['clock']);
		$complete = array_values(array_filter($selected,
			static fn (array $row): bool => !isset($row['complete']) || $row['complete']));
		$coverage_by_hour_source = [];
		$qualified_recent_by_hour = [];
		foreach ($complete as $row) {
			$hour = intdiv($row['clock'], 3600);
			$source = (string) ($row['source'] ?? 'unknown');
			$effective_seconds = max(1, (int) ($row['bucket_seconds'] ?? 3600))
				* min(1.0, max(0.0, (float) ($row['bucket_coverage_pct'] ?? 100)) / 100);
			$coverage_by_hour_source[$hour][$source] = ($coverage_by_hour_source[$hour][$source] ?? 0.0)
				+ $effective_seconds;
			if ($source === 'recent_history' && !empty($row['baseline_eligible'])) {
				$qualified_recent_by_hour[$hour] = ($qualified_recent_by_hour[$hour] ?? 0.0)
					+ $effective_seconds;
			}
		}
		$observed_seconds = 0.0;
		foreach ($coverage_by_hour_source as $hour => $by_source) {
			$hour_start = (int) $hour * 3600;
			$available_seconds = max(0, min($end, $hour_start + 3600) - max($cutoff, $hour_start));
			$observed_seconds += min($available_seconds, max($by_source));
		}
		$qualified_recent_seconds = 0.0;
		foreach ($qualified_recent_by_hour as $hour => $seconds) {
			$hour_start = (int) $hour * 3600;
			$available_seconds = max(0, min($end, $hour_start + 3600) - max($cutoff, $hour_start));
			$qualified_recent_seconds += min($available_seconds, $seconds);
		}
		$near_full_rows = array_values(array_filter($selected,
			static fn (array $row): bool => $row['max'] >= self::RESOURCE_NEAR_FULL_PCT));
		$raw_peak_hours = [];
		foreach ($near_full_rows as $row) {
			if (($row['source'] ?? '') === 'recent_history') {
				$raw_peak_hours[intdiv($row['clock'], 3600)] = true;
			}
		}
		$max_rows = array_values(array_filter($near_full_rows,
			static fn (array $row): bool => ($row['source'] ?? '') === 'recent_history'
				|| !isset($raw_peak_hours[intdiv($row['clock'], 3600)])));
		$max_days = [];
		$unknown_rows = [];
		foreach ($max_rows as $row) {
			$max_days[intdiv($row['clock'], 86400)] = true;
			if (empty($row['complete']) || $row['min'] < self::RESOURCE_NEAR_FULL_PCT
					|| empty($row['duration_confirmable'])) {
				$unknown_rows[] = $row;
			}
		}
		$unknown_days = [];
		foreach ($unknown_rows as $row) {
			$unknown_days[intdiv($row['clock'], 86400)] = true;
		}
		$confirmed_saturation_only_hours = [];
		foreach ($complete as $row) {
			if (!empty($row['saturation_only']) && ($row['source'] ?? '') === 'trend'
					&& !empty($row['duration_confirmable'])
					&& ($row['bucket_coverage_pct'] ?? 0) >= self::SATURATION_MIN_BUCKET_COVERAGE_PCT
					&& $row['min'] >= $threshold) {
				$confirmed_saturation_only_hours[intdiv($row['clock'], 3600)] = true;
			}
		}
		$confirmed = array_values(array_filter($complete,
			static fn (array $row): bool => !empty($row['duration_confirmable'])
				&& ($row['bucket_coverage_pct'] ?? 0) >= self::SATURATION_MIN_BUCKET_COVERAGE_PCT
				&& $row['min'] >= $threshold
				&& !(($row['source'] ?? '') === 'recent_history'
					&& isset($confirmed_saturation_only_hours[intdiv($row['clock'], 3600)]))));
		$episodes = [];
		$active = [];
		foreach ($confirmed as $row) {
			if ($active) {
				$previous = $active[count($active) - 1];
				$contiguous = ($row['source'] ?? '') === ($previous['source'] ?? '')
					&& ($row['bucket_seconds'] ?? 3600) === ($previous['bucket_seconds'] ?? 3600)
					&& $row['clock'] === $previous['clock'] + ($previous['bucket_seconds'] ?? 3600);
				if (!$contiguous) {
					$episodes[] = $active;
					$active = [];
				}
			}
			$active[] = $row;
		}
		if ($active) {
			$episodes[] = $active;
		}
		$accepted = [];
		foreach ($episodes as $episode) {
			$seconds = 0;
			foreach ($episode as $row) {
				$seconds += (int) ($row['bucket_seconds'] ?? 3600);
			}
			$duration = $seconds / 60;
			if ($duration >= self::SATURATION_MIN_EPISODE_MINUTES) {
				$accepted[] = ['rows' => $episode, 'duration' => $duration];
			}
		}
		$total_minutes = 0.0;
		$longest_minutes = 0.0;
		$long_count = 0;
		$critical_count = 0;
		$episode_days = [];
		$history_count = 0;
		$trend_count = 0;
		foreach ($accepted as $episode) {
			$total_minutes += $episode['duration'];
			$longest_minutes = max($longest_minutes, $episode['duration']);
			if ($episode['duration'] >= self::SATURATION_LONG_EPISODE_MINUTES) {
				$long_count++;
			}
			if ($episode['duration'] >= self::SATURATION_CRITICAL_EPISODE_MINUTES) {
				$critical_count++;
			}
			foreach ($episode['rows'] as $row) {
				$episode_days[intdiv($row['clock'], 86400)] = true;
			}
			if (($episode['rows'][0]['source'] ?? '') === 'recent_history') {
				$history_count++;
			}
			else {
				$trend_count++;
			}
		}
		$ongoing_minutes = 0.0;
		if ($accepted && $current_fresh && $spec['current'] !== null && $spec['current'] >= $threshold
				&& $end === $now) {
			$last_episode = $accepted[count($accepted) - 1];
			$last_row = $last_episode['rows'][count($last_episode['rows']) - 1];
			$bucket_seconds = (int) ($last_row['bucket_seconds'] ?? 3600);
			$episode_end = $last_row['clock'] + $bucket_seconds;
			if ($now - $episode_end <= max(self::RECENT_RESOURCE_BUCKET_SECONDS, $bucket_seconds)) {
				$ongoing_minutes = $last_episode['duration'];
			}
		}
		$episode_count = count($accepted);
		$episode_day_count = count($episode_days);
		if ($ongoing_minutes >= self::SATURATION_CRITICAL_EPISODE_MINUTES
				|| ($critical_count >= self::SATURATION_REPEATED_EPISODE_COUNT
					&& $episode_day_count >= self::SATURATION_REPEATED_DAYS
					&& $total_minutes >= self::SATURATION_CRITICAL_TOTAL_MINUTES)) {
			$severity = 'Critical';
		}
		elseif (($long_count >= self::SATURATION_REPEATED_EPISODE_COUNT
				&& $episode_day_count >= self::SATURATION_REPEATED_DAYS)
				|| ($longest_minutes >= self::SATURATION_CRITICAL_EPISODE_MINUTES
					&& $total_minutes >= self::SATURATION_HIGH_TOTAL_MINUTES)) {
			$severity = 'High';
		}
		elseif (($episode_count >= 2 && $episode_day_count >= 2)
				|| $total_minutes >= max(60, self::SATURATION_MIN_EPISODE_MINUTES * 4)) {
			$severity = 'Medium';
		}
		elseif ($episode_count >= 1 || (count($max_rows) >= self::SATURATION_PEAK_WATCH_COUNT
				&& count($max_days) >= self::SATURATION_PEAK_WATCH_DAYS)) {
			$severity = 'Watch';
		}
		else {
			$severity = 'Healthy';
		}
		$coverage = min(100.0, $observed_seconds / $window_seconds * 100);
		$qualified_recent_coverage = min(100.0, $qualified_recent_seconds / $window_seconds * 100);
		// High confidence needs material high-resolution evidence, not merely one
		// sparse raw bucket alongside otherwise complete hourly trends. A standard
		// 31-day analysis therefore requires 24 hours; shorter custom windows require
		// at least 10% of their analyzed duration.
		$required_recent_seconds = min(86400.0, $window_seconds * 0.10);
		$has_material_recent = $qualified_recent_seconds >= $required_recent_seconds;
		$confidence = $coverage >= 80 && $has_material_recent
			? 'High'
			: (($coverage >= 50 || $episode_count > 0) ? 'Medium' : 'Low');
		$observations = [];
		if ($coverage >= 80 && !$has_material_recent) {
			$observations[] = sprintf(_('Qualified recent high-resolution coverage was %1$s hours; %2$s hours are required for High confidence'),
				$this->trimFloat($qualified_recent_seconds / 3600),
				$this->trimFloat($required_recent_seconds / 3600));
		}
		if ($episode_count > 0) {
			$observations[] = sprintf(_('%1$d confirmed >= %2$s%% episode(s) over %3$d days; longest %4$s min, total %5$s min across %6$d day(s)'),
				$episode_count, $this->trimFloat($threshold), $window_days,
				$this->trimFloat($longest_minutes), $this->trimFloat($total_minutes), $episode_day_count);
		}
		if ($max_rows) {
			$observations[] = sprintf(_('%1$d near-full max observation(s) >= %2$s%% across %3$d day(s); %4$d have unknown duration'),
				count($max_rows), $this->trimFloat(self::RESOURCE_NEAR_FULL_PCT), count($max_days), count($unknown_rows));
		}
		return [
			'threshold_pct' => $threshold,
			'near_full_threshold_pct' => self::RESOURCE_NEAR_FULL_PCT,
			'window_days' => $window_days,
			'analysis_start_clock' => $cutoff,
			'analysis_end_clock' => $end,
			'coverage_pct' => $coverage,
			'qualified_recent_coverage_pct' => $qualified_recent_coverage,
			'qualified_recent_hours' => $qualified_recent_seconds / 3600,
			'max_observation_count' => count($max_rows),
			'max_observation_days' => count($max_days),
			'duration_unknown_max_count' => count($unknown_rows),
			'duration_unknown_max_days' => count($unknown_days),
			'confirmed_episode_count' => $episode_count,
			'confirmed_episode_days' => $episode_day_count,
			'confirmed_long_episode_count' => $long_count,
			'confirmed_critical_episode_count' => $critical_count,
			'confirmed_total_minutes' => $total_minutes,
			'confirmed_longest_minutes' => $longest_minutes,
			'confirmed_ongoing_minutes' => $ongoing_minutes,
			'confirmed_history_episode_count' => $history_count,
			'confirmed_trend_episode_count' => $trend_count,
			'source' => $selected ? $this->resourceRowsSource($selected) : 'none',
			'severity' => $severity,
			'confidence' => $confidence,
			'reason' => $observations ? implode('; ', $observations).'.' : ''
		];
	}

	private function resourceRecommendation(string $rtype, string $severity, ?array $stats,
			array $saturation, array $regime): string {
		$evidence = [];
		if ($stats !== null) {
			$evidence[] = sprintf(_('%1$s: average %2$s, p95 %3$s, %4$.1f%% above review.'),
				$stats['label'] ?? 'selected window', $this->formatPct($stats['avg']),
				$this->formatPct($stats['p95']), $stats['above_warn'] ?? 0);
		}
		if ($saturation['reason'] !== '') {
			$evidence[] = $saturation['reason'];
		}
		if ($regime['detected']) {
			$evidence[] = $regime['reason'];
		}
		if ($severity === 'Critical' || $severity === 'High') {
			$action = $rtype === 'CPU'
				? _('Validate the measured intervals against run queue, workload and host or hypervisor evidence; utilization alone does not determine a vCPU change.')
				: _('Validate the measured intervals against available memory, paging/swap and working-set evidence; utilization alone does not determine a RAM change.');
		}
		elseif ($severity === 'Medium') {
			$action = _('Create a capacity review and validate recurrence before changing allocation.');
		}
		elseif ($severity === 'Watch') {
			$action = _('Continue observation; the recorded peaks do not by themselves prove duration.');
		}
		elseif ($severity === 'Unknown') {
			$action = _('Restore sufficient fresh collection and retention before sizing this resource.');
		}
		else {
			$action = _('No resource expansion is indicated by the qualified evidence.');
		}
		return trim(implode(' ', array_slice($evidence, 0, 3)).' '.$action);
	}

	// -------------------------------------------------------------------------
	// Payload helpers
	// -------------------------------------------------------------------------

	private function roundWindows(array $windows): array {
		$out = [];
		foreach ($windows as $window => $s) {
			$out[$window] = $this->roundWindowStats($s);
		}
		return $out;
	}

	private function roundWindowStats(?array $s): ?array {
		if ($s === null) {
			return null;
		}
		return [
			'label' => $s['label'] ?? '',
			'requested_days' => $s['requested_days'] ?? 0,
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
			'start_value' => $s['start_value'] !== null ? round($s['start_value'], 4) : null,
			'end_value' => $s['end_value'] !== null ? round($s['end_value'], 4) : null,
			'net_change' => $s['net_change'] !== null ? round($s['net_change'], 4) : null,
			'first' => $s['first'],
			'last' => $s['last']
		];
	}

	private function roundRegime(array $regime): array {
		foreach (['recent_average', 'prior_average', 'delta_pct_points', 'relative_change_pct',
			'recent_coverage_pct', 'prior_coverage_pct'] as $field) {
			if (isset($regime[$field]) && $regime[$field] !== null) {
				$regime[$field] = round((float) $regime[$field], 2);
			}
		}
		return $regime;
	}

	private function roundSaturation(array $saturation): array {
		foreach (['threshold_pct', 'near_full_threshold_pct', 'coverage_pct', 'qualified_recent_coverage_pct',
			'qualified_recent_hours', 'confirmed_total_minutes', 'confirmed_longest_minutes',
			'confirmed_ongoing_minutes'] as $field) {
			if (isset($saturation[$field])) {
				$saturation[$field] = round((float) $saturation[$field], 2);
			}
		}
		return $saturation;
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
				$bucket = ['clock_sum' => 0.0, 'sum' => 0.0, 'n' => 0, 'min' => $d['min'], 'max' => $d['max']];
			}
			// Sample-weighted mean clock: sparse series can group days that are far
			// apart, and stamping the group's first day would misplace its values.
			$bucket['clock_sum'] += (float) $day * $d['n'];
			$bucket['sum'] += $d['sum'];
			$bucket['n'] += $d['n'];
			$bucket['min'] = min($bucket['min'], $d['min']);
			$bucket['max'] = max($bucket['max'], $d['max']);
			$index++;
			if ($index % $group === 0) {
				$points[] = [(int) round($bucket['clock_sum'] / max(1, $bucket['n'])), round($bucket['min'], 4),
					round($bucket['sum'] / max(1, $bucket['n']), 4), round($bucket['max'], 4)];
				$bucket = null;
			}
		}
		if ($bucket !== null) {
			$points[] = [(int) round($bucket['clock_sum'] / max(1, $bucket['n'])), round($bucket['min'], 4),
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
