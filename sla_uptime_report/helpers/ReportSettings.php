<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Helpers;

use API;
use InvalidArgumentException;
use RuntimeException;

/** Shared report policy. Read with viewer permissions; write through Zabbix's audited module API. */
final class ReportSettings {
	public const DEFAULT_KEYS = ['agent.ping', 'icmpping', 'zabbix[host,agent,available]'];
	public const MAX_RULES = 100;
	private const MAX_FORM_ROWS = 200;
	private const CONFIG_KEY = 'availability';

	public static function defaults(): array {
		return [
			'global' => self::defaultRule(), 'groups' => [], 'hosts' => [],
			'excluded_hostids' => [], 'excluded_groupids' => [], 'cache' => ['enabled' => true]
		];
	}

	private static function defaultRule(): array {
		return ['key' => '', 'label' => '', 'mode' => 'auto', 'value' => 1.0, 'min' => 200.0, 'max' => 399.0, 'interval_seconds' => 0];
	}

	/** Preserve editable values after a rejected save; this is not validated policy and must never be stored directly. */
	public static function formValues(array $input): array {
		$text = static function($value): string {
			if (!is_scalar($value) || strlen((string) $value) > 100000) {
				throw new InvalidArgumentException('Submitted form contains an invalid field.');
			}
			return (string) $value;
		};
		$project = static function($row, array $defaults) use ($text): array {
			if (!is_array($row)) { throw new InvalidArgumentException('Submitted form contains an invalid row.'); }
			foreach ($defaults as $field => $default) {
				$defaults[$field] = $text(array_key_exists($field, $row) ? $row[$field] : $default);
			}
			return $defaults;
		};
		$out = self::defaults();
		$out['global'] = $project($input['global'] ?? [], self::defaultRule());
		$out['cache'] = $project($input['cache'] ?? [], ['enabled' => '1']);
		foreach (['groups' => 'groupid', 'hosts' => 'hostid'] as $kind => $id_field) {
			$rows = $input[$kind] ?? [];
			if (!is_array($rows) || count($rows) > self::MAX_FORM_ROWS) {
				throw new InvalidArgumentException('Submitted form has too many rows to redisplay.');
			}
			$defaults = self::defaultRule() + [$id_field => ''];
			if ($kind === 'groups') { $defaults['priority'] = 100; }
			foreach ($rows as $row) { $out[$kind][] = $project($row, $defaults); }
		}
		foreach (['excluded_hostids', 'excluded_groupids'] as $field) {
			$ids = $input[$field] ?? [];
			if (!is_array($ids) || count($ids) > 5000) {
				throw new InvalidArgumentException('Submitted form contains an invalid exclusion list.');
			}
			$out[$field] = array_values(array_map($text, $ids));
			$out[$field.'_extra'] = $text($input[$field.'_extra'] ?? '');
		}
		return $out;
	}

	public static function get(): array {
		return self::snapshot()['settings'];
	}

	public static function snapshot(): array {
		$record = self::record();
		$config = self::decode($record['config'] ?? '');
		if (array_key_exists(self::CONFIG_KEY, $config) && !is_array($config[self::CONFIG_KEY])) {
			throw new RuntimeException('Stored report settings are invalid.');
		}
		return ['settings' => self::normalize($config[self::CONFIG_KEY] ?? [], false),
			'revision' => self::revision($config)];
	}

	/** Strict validation also protects report readers against corrupt stored policy. */
	public static function normalize(array $input, bool $allow_blank_rows = true): array {
		$out = self::defaults();
		$cache = $input['cache'] ?? ['enabled' => true];
		if (!is_array($cache) || !in_array($cache['enabled'] ?? true, [true, false, 0, 1, '0', '1'], true)) {
			throw new InvalidArgumentException('Invalid report cache setting.');
		}
		$out['cache']['enabled'] = (bool) ($cache['enabled'] ?? true);
		$out['global'] = self::rule(array_key_exists('global', $input) ? $input['global'] : []);
		foreach (['groups' => 'groupid', 'hosts' => 'hostid'] as $kind => $id_field) {
			$rows = array_key_exists($kind, $input) ? $input[$kind] : [];
			// Empty UI/import rows do not count against the policy limit, but parsing is still bounded.
			if (!is_array($rows) || count($rows) > ($allow_blank_rows ? self::MAX_FORM_ROWS : self::MAX_RULES)) {
				throw new InvalidArgumentException('Too many or invalid availability rules.');
			}
			$seen = [];
			foreach ($rows as $row) {
				if (!is_array($row)) {
					throw new InvalidArgumentException('Invalid availability rule.');
				}
				$id_raw = $row[$id_field] ?? '';
				if ($id_raw === '' && $allow_blank_rows) { continue; } // Blank form rows are not rules.
				$id = self::id($id_raw);
				$normalized = self::rule($row);
				if ($kind === 'groups' && isset($seen[$id])) {
					throw new InvalidArgumentException('Only one rule is allowed for each host group.');
				}
				$identity = $normalized['mode'] === 'auto' ? 'auto' : 'key:'.$normalized['key'];
				if (isset($seen[$id][$identity])) {
					throw new InvalidArgumentException('A host can only measure an item key once. Use a different key for each website or scenario.');
				}
				if (isset($seen[$id]) && ($identity === 'auto' || isset($seen[$id]['auto']))) {
					throw new InvalidArgumentException('Automatic item selection cannot be combined with other measurements on the same host. Use explicit item keys for every measurement.');
				}
				$seen[$id][$identity] = true;
				$normalized[$id_field] = $id;
				if ($kind === 'groups') {
					$priority = $row['priority'] ?? 100;
					if (!is_scalar($priority) || !preg_match('/^\d{1,5}$/D', (string) $priority)) {
						throw new InvalidArgumentException('Group priority must be an integer from 0 to 99999.');
					}
					$normalized['priority'] = (int) $priority;
				}
				$out[$kind][] = $normalized;
			}
		}
		if (count($out['groups']) + count($out['hosts']) > self::MAX_RULES) {
			throw new InvalidArgumentException('At most 100 availability overrides can be stored.');
		}
		foreach (['excluded_hostids', 'excluded_groupids'] as $field) {
			$ids = array_key_exists($field, $input) ? $input[$field] : [];
			$extra = $input[$field.'_extra'] ?? '';
			if (!is_string($extra) || strlen($extra) > 100000) {
				throw new InvalidArgumentException('Invalid additional exclusion IDs.');
			}
			if (is_array($ids) && trim($extra) !== '') {
				$ids = array_merge($ids, preg_split('/[\s,;]+/', trim($extra), -1, PREG_SPLIT_NO_EMPTY));
			}
			if (!is_array($ids) || count($ids) > 5000) {
				throw new InvalidArgumentException('Invalid exclusion list.');
			}
			$out[$field] = array_values(array_unique(array_map([self::class, 'id'], $ids)));
		}
		return $out;
	}

	private static function rule($input): array {
		if (!is_array($input)) { throw new InvalidArgumentException('Invalid measurement rule.'); }
		$rule = array_replace(self::defaultRule(), array_intersect_key($input, self::defaultRule()));
		if (!is_string($rule['key']) || strlen($rule['key']) > 2048
				|| preg_match('/[\x00-\x1f\x7f]/', $rule['key'])) {
			throw new InvalidArgumentException('Item key must be plain text, at most 2048 bytes.');
		}
		$rule['key'] = trim($rule['key']);
		if (!is_string($rule['label']) || strlen($rule['label']) > 255
				|| preg_match('/[\x00-\x1f\x7f]/', $rule['label'])) {
			throw new InvalidArgumentException('Measurement name must be plain text, at most 255 bytes.');
		}
		$rule['label'] = trim($rule['label']);
		if (!is_string($rule['mode']) || !in_array($rule['mode'], ['auto', 'binary', 'equals', 'range'], true)) {
			throw new InvalidArgumentException('Invalid healthy-value rule.');
		}
		if ($rule['mode'] === 'auto') { $rule['key'] = ''; }
		elseif ($rule['key'] === '') { throw new InvalidArgumentException('An explicit measurement rule needs an item key.'); }
		if ((!is_string($rule['interval_seconds']) && !is_int($rule['interval_seconds']))
				|| !preg_match('/^\d{1,5}$/D', (string) $rule['interval_seconds'])
				|| (int) $rule['interval_seconds'] > 86400) {
			throw new InvalidArgumentException('Expected poll interval must be an integer from 0 to 86400 seconds.');
		}
		$rule['interval_seconds'] = (int) $rule['interval_seconds'];
		foreach (['value', 'min', 'max'] as $field) {
			if ($rule[$field] === '' && (($field === 'value' && $rule['mode'] !== 'equals')
					|| ($field !== 'value' && $rule['mode'] !== 'range'))) {
				$rule[$field] = self::defaultRule()[$field];
			}
			if (!is_numeric($rule[$field]) || !is_finite((float) $rule[$field])) {
				throw new InvalidArgumentException('Healthy values must be finite numbers.');
			}
			$rule[$field] = (float) $rule[$field];
		}
		if ($rule['mode'] === 'range' && $rule['min'] > $rule['max']) {
			throw new InvalidArgumentException('The healthy range minimum must not exceed its maximum.');
		}
		return $rule;
	}

	private static function id($value): string {
		if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) $value)) {
			throw new InvalidArgumentException('Invalid host or host group ID.');
		}
		return (string) $value;
	}

	public static function resolveRule(array $settings, string $hostid, array $groupids): array {
		return self::resolveRules($settings, $hostid, $groupids)[0];
	}

	/** Explicit host measurements replace the single inherited group/global rule. */
	public static function resolveRules(array $settings, string $hostid, array $groupids): array {
		$host_rules = [];
		foreach ($settings['hosts'] as $rule) {
			if ($rule['hostid'] === $hostid) { $host_rules[] = array_replace($rule, ['scope' => 'host', 'scopeid' => $hostid]); }
		}
		if ($host_rules !== []) { return $host_rules; }
		$matching = array_values(array_filter($settings['groups'], static function(array $rule) use ($groupids): bool {
			return in_array($rule['groupid'], array_map('strval', $groupids), true);
		}));
		usort($matching, static function(array $a, array $b): int {
			return ($a['priority'] <=> $b['priority'])
				?: (strlen($a['groupid']) <=> strlen($b['groupid'])) ?: strcmp($a['groupid'], $b['groupid']);
		});
		if ($matching !== []) { return [array_replace($matching[0], ['scope' => 'group', 'scopeid' => $matching[0]['groupid']])]; }
		return [array_replace($settings['global'], ['scope' => 'global', 'scopeid' => ''])];
	}

	public static function isExcluded(array $settings, string $hostid, array $groupids): bool {
		return in_array($hostid, $settings['excluded_hostids'], true)
			|| array_intersect(array_map('strval', $groupids), $settings['excluded_groupids']) !== [];
	}

	public static function sampleIsUp(float $value, array $rule): bool {
		if (!is_finite($value)) { return false; }
		if ($rule['mode'] === 'equals') { return $value === (float) $rule['value']; }
		if ($rule['mode'] === 'range') { return $value >= (float) $rule['min'] && $value <= (float) $rule['max']; }
		return $value === 1.0;
	}

	/** Legacy single-measurement mapping. Multi-measurement callers must use selectMeasurements(). */
	public static function selectItems(array $hosts, array $settings): array {
		$selected = [];
		$seen_hosts = [];
		foreach (self::selectMeasurements($hosts, $settings) as $measurement) {
			$hostid = $measurement['hostid'];
			if (isset($seen_hosts[$hostid])) { continue; }
			$seen_hosts[$hostid] = true;
			if ($measurement['item'] !== null) { $selected[$hostid] = $measurement['item']; }
		}
		return $selected;
	}

	/** Identity follows the host and selected key, not its label or healthy-value policy. */
	public static function measurementId(string $hostid, array $rule): string {
		$identity = $rule['mode'] === 'auto' ? 'auto' : 'key:'.$rule['key'];
		return substr(hash('sha256', $hostid."\0".$identity), 0, 32);
	}

	/**
	 * One API call per 250 hosts; numeric enabled web items are included.
	 * Missing explicit items retain their measurement row so missing configuration stays visible.
	 * @return array<string,array{measurementid:string,hostid:string,label:string,rule:array,item:?array}>
	 */
	public static function selectMeasurements(array $hosts, array $settings): array {
		$measurements = [];
		$by_host = [];
		foreach ($hosts as $host) {
			$hostid = (string) $host['hostid'];
			$host_label = (string) (($host['name'] ?? '') !== '' ? $host['name'] : ($host['host'] ?? $hostid));
			foreach (self::resolveRules($settings, $hostid, array_column($host['hostgroups'] ?? [], 'groupid')) as $rule) {
				$measurementid = self::measurementId($hostid, $rule);
				$label = ($rule['label'] ?? '') !== '' ? $rule['label']
					: ($rule['mode'] === 'auto' ? $host_label : $rule['key']);
				$measurements[$measurementid] = ['measurementid' => $measurementid, 'hostid' => $hostid,
					'label' => $label, 'rule' => $rule, 'item' => null];
				$by_host[$hostid][] = $measurementid;
			}
		}
		foreach (array_chunk(array_keys($by_host), 250) as $hostids) {
			$chunk_hosts = array_fill_keys(array_map('strval', $hostids), true);
			$keys = [];
			foreach ($hostids as $hostid) {
				foreach ($by_host[$hostid] as $measurementid) {
					$rule = $measurements[$measurementid]['rule'];
					$keys = array_merge($keys, $rule['mode'] === 'auto' ? self::DEFAULT_KEYS : [$rule['key']]);
				}
			}
			$items = API::Item()->get([
				'output' => ['itemid', 'hostid', 'key_', 'delay', 'value_type', 'status', 'name'],
				'hostids' => $hostids, 'webitems' => true,
				'filter' => ['key_' => array_values(array_unique($keys)), 'status' => 0, 'value_type' => [0, 3]]
			]);
			if (!is_array($items)) { throw new RuntimeException('Availability item lookup failed.'); }
			foreach ($items as $item) {
				$hostid = (string) $item['hostid'];
				if (!isset($by_host[$hostid], $chunk_hosts[$hostid]) || (int) $item['status'] !== 0
						|| !in_array((int) $item['value_type'], [0, 3], true)) { continue; }
				foreach ($by_host[$hostid] as $measurementid) {
					$rule = $measurements[$measurementid]['rule'];
					$allowed = $rule['mode'] === 'auto' ? self::DEFAULT_KEYS : [$rule['key']];
					$rank = array_search($item['key_'], $allowed, true);
					if ($rank === false) { continue; }
					$current = $measurements[$measurementid]['item'];
					if ($current === null || $rank < $current['priority']
							|| ($rank === $current['priority'] && strcmp((string) $item['itemid'], (string) $current['itemid']) < 0)) {
						$selected = $item;
						$selected['hostid'] = $hostid;
						$selected['itemid'] = (string) $item['itemid'];
						$selected['measurementid'] = $measurementid;
						$selected['rule'] = $rule;
						$selected['item_delay'] = $item['delay'];
						if ($rule['interval_seconds'] > 0) { $selected['delay'] = $rule['interval_seconds'].'s'; }
						$selected['priority'] = $rank;
						$measurements[$measurementid]['item'] = $selected;
					}
				}
			}
		}
		return $measurements;
	}

	public static function save(array $input, string $expected_revision): void {
		// Defense in depth: callers cannot use this helper to bypass module.update's permission boundary.
		if (\CWebUser::getType() !== USER_TYPE_SUPER_ADMIN) {
			throw new RuntimeException('Super administrator access is required to change shared report settings.');
		}
		$settings = self::normalize($input);
		self::checkReferences($settings);
		$record = self::record();
		$config = self::decode($record['config'] ?? '');
		if (!hash_equals(self::revision($config), $expected_revision)) {
			throw new InvalidArgumentException('Settings changed since this page was opened. Reload the settings page before saving.');
		}
		$config[self::CONFIG_KEY] = $settings;
		$result = API::Module()->update([['moduleid' => (string) $record['moduleid'], 'config' => $config]]);
		if (!is_array($result) || empty($result['moduleids'])) { throw new RuntimeException('Settings could not be saved.'); }
		if (self::snapshot()['settings'] !== $settings) {
			throw new RuntimeException('Settings changed during save. Reload and verify the stored settings.');
		}
	}

	private static function checkReferences(array $settings): void {
		foreach (['Host' => ['hosts', 'hostid', 'excluded_hostids'], 'HostGroup' => ['groups', 'groupid', 'excluded_groupids']] as $api => $fields) {
			[$kind, $field, $excluded] = $fields;
			$ids = array_values(array_unique(array_merge(array_column($settings[$kind], $field), $settings[$excluded])));
			if ($ids === []) { continue; }
			$objects = API::$api()->get(['output' => [$field], $field.'s' => $ids]);
			if (!is_array($objects) || count($objects) !== count($ids)) {
				throw new InvalidArgumentException('A selected host or host group no longer exists or is not accessible.');
			}
		}
	}

	private static function record(): array {
		if (!function_exists('DBselect') || !function_exists('DBfetch')) {
			throw new RuntimeException('Report configuration storage is unavailable.');
		}
		$result = DBselect('SELECT moduleid,config FROM module WHERE id='.zbx_dbstr('sla_uptime_report'), 1);
		if ($result === false) { throw new RuntimeException('Report configuration could not be read.'); }
		$row = DBfetch($result);
		if (!is_array($row)) { throw new RuntimeException('SLA Uptime Report module is not registered.'); }
		return $row;
	}

	private static function decode($raw): array {
		if (is_array($raw)) { return $raw; }
		if ($raw === '' || $raw === null) { return []; }
		try { $value = json_decode((string) $raw, true, 64, JSON_THROW_ON_ERROR); }
		catch (\JsonException $e) { throw new RuntimeException('Stored report configuration is invalid.', 0, $e); }
		if (!is_array($value)) { throw new RuntimeException('Stored report configuration is invalid.'); }
		return $value;
	}

	private static function revision(array $config): string {
		return hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
	}
}
