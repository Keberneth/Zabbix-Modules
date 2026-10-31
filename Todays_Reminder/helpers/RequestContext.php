<?php

namespace Modules\MessageOfTheDay\Helpers;

/** Shared validation and browser context for the two read-only controllers. */
final class RequestContext {
	public static function filters(array $input): array {
		foreach (['groupid', 'tag', 'tag_value'] as $key) {
			if (isset($input[$key]) && !is_string($input[$key]) && !is_int($input[$key])) {
				throw new \InvalidArgumentException(_('Invalid reminder filter.'));
			}
		}
		$groupid = trim((string) ($input['groupid'] ?? '0'));
		$groupid = $groupid === '' ? '0' : $groupid;
		if (!preg_match('/^[0-9]{1,20}$/D', $groupid)) {
			throw new \InvalidArgumentException(_('Invalid host group.'));
		}
		$groupid = ltrim($groupid, '0') ?: '0';
		if (strlen($groupid) === 20 && strcmp($groupid, '18446744073709551615') > 0) {
			throw new \InvalidArgumentException(_('Invalid host group.'));
		}
		$tag = trim((string) ($input['tag'] ?? ''));
		$value = trim((string) ($input['tag_value'] ?? ''));
		if (strlen($tag) > 255 || strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $tag.$value)) {
			throw new \InvalidArgumentException(_('Host tag filters must be at most 255 bytes and contain no control characters.'));
		}
		if ($tag === '' && $value !== '') {
			throw new \InvalidArgumentException(_('Enter a host tag name before a tag value.'));
		}
		return ['groupid' => $groupid, 'tag' => $tag, 'tag_value' => $value];
	}

	public static function decorate(array $data): array {
		global $DB;
		$data['preference_key'] = 'motd:v2:'.hash('sha256', __DIR__.'|'.json_encode([
			$DB['SERVER'] ?? '', $DB['PORT'] ?? '', $DB['DATABASE'] ?? '',
			(string) (\CWebUser::$data['userid'] ?? '0')
		]));
		$data['checked_at'] = time();
		$logout = (string) (\CWebUser::$data['autologout'] ?? '15m');
		if (preg_match('/^(\d+)([smhdw]?)$/D', $logout, $match)) {
			$units = ['' => 1, 's' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800];
			$data['idle_timeout'] = (int) $match[1] * $units[$match[2]];
		}
		else { $data['idle_timeout'] = 900; }
		$data['ui'] = self::labels();
		return $data;
	}

	public static function unavailable(array $filters, string $message): array {
		$section = ['status' => 'unavailable', 'message' => $message, 'count' => null, 'recent' => []];
		return [
			'title' => _('Today\'s Reminder'), 'status' => 'unavailable',
			'issues' => [['section' => 'reminder', 'message' => $message]],
			'summary_line' => $message, 'generated_at' => null, 'generated_at_text' => '',
			'timezone' => date_default_timezone_get(), 'filters' => $filters,
			'filter_options' => ['groups' => []], 'chips' => [], 'banner_items' => [],
			'problems' => $section + ['high_count' => null, 'critical_count' => null, 'suppressed_count' => null, 'total' => null],
			'unacked' => $section, 'longest' => $section, 'resolved' => $section + ['avg_mttr' => null, 'avg_mttr_text' => ''],
			'new_hosts' => $section,
			'health' => $section + ['stale_items_total' => null, 'stale_items_hosts' => null,
				'unreachable_hosts' => null, 'unreachable_proxies' => null, 'queue_backlog' => null,
				'unreachable_details' => [], 'field_messages' => []],
			'maintenances' => $section + ['today' => [], 'week' => [], 'today_count' => null, 'week_count' => null],
			'links' => ['module' => 'zabbix.php?action=motd.view'],
			'fingerprint' => hash('sha256', json_encode([$filters, $message]))
		];
	}

	private static function labels(): array {
		return [
			'expand' => _('Expand'), 'collapse' => _('Collapse'), 'open_details' => _('Open details'),
			'updated' => _('Updated'), 'last_checked' => _('Last checked'), 'refreshing' => _('Refreshing…'),
			'refresh' => _('Refresh'), 'refresh_failed' => _('Refresh failed. Showing the last available data.'),
			'stale' => _('Data may be outdated'), 'partial' => _('Some data is unavailable or incomplete'),
			'unavailable' => _('Unavailable'), 'snooze' => _('Snooze for 1 hour'), 'resume' => _('Resume banner'),
			'snoozed' => _('Banner snoozed until'), 'filter_saved' => _('Filters saved for this browser'),
			'filters_reset' => _('Filters reset'), 'apply_filters' => _('Apply filters'),
			'reset_filters' => _('Reset filters'), 'all_host_groups' => _('All host groups'),
			'host_group' => _('Host group'), 'tag' => _('Host tag'), 'tag_value' => _('Tag value'),
			'scope' => _('Showing data for'), 'all_hosts' => _('All accessible hosts'),
			'idle_paused' => _('Automatic refresh paused while inactive')
		];
	}
}
