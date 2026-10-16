<?php declare(strict_types = 0);

namespace Modules\PatchCalendar\Actions;

use API,
	CControllerDashboardWidgetView,
	CControllerResponseData,
	CRoleHelper,
	CTimezoneHelper,
	CWebUser,
	DateTimeImmutable,
	DateTimeZone;

use Modules\PatchCalendar\Includes\MaintenanceSchedule;
use Modules\PatchCalendar\Widget;

class WidgetView extends CControllerDashboardWidgetView {

	// How many months, weeks or agenda pages the calendar can be paged away from today.
	private const MAX_OFFSET = 120;

	private const COLOR_COUNT = 12;

	protected function init(): void {
		parent::init();

		$this->addValidationRules([
			'calendar_view' => 'in '.implode(',', [Widget::VIEW_MONTH, Widget::VIEW_WEEK, Widget::VIEW_AGENDA]),
			'calendar_offset' => 'int32'
		]);
	}

	protected function doAction(): void {
		$now = time();
		$timezone = new DateTimeZone(date_default_timezone_get());
		$server_timezone = $this->fields_values['server_timezone'] === ZBX_DEFAULT_TIMEZONE
			? CTimezoneHelper::getSystemTimezone()
			: $this->fields_values['server_timezone'];

		$view = (int) $this->getInput('calendar_view', $this->fields_values['view']);
		$offset = max(-self::MAX_OFFSET, min(self::MAX_OFFSET, (int) $this->getInput('calendar_offset', 0)));
		$week_start = (int) $this->fields_values['week_start'];
		$agenda_days = (int) $this->fields_values['agenda_days'];

		$today = (new DateTimeImmutable('@'.$now))->setTimezone($timezone)->setTime(0, 0);
		$range = $this->getRange($view, $offset, $today, $week_start, $agenda_days);

		$days = [];
		$day = $range['first'];

		for ($i = 0; $i < $range['count']; $i++) {
			$next = $day->modify('+1 day');

			$days[] = [
				'start' => $day->getTimestamp(),
				'end' => $next->getTimestamp(),
				'is_today' => $day == $today,
				'is_tomorrow' => $day == $today->modify('+1 day'),
				'is_weekend' => $day->format('N') >= 6,
				'in_month' => $view != Widget::VIEW_MONTH || $day->format('Ym') === $range['month'],
				'week_number' => (int) $day->format('W'),
				'windows' => []
			];

			$day = $next;
		}

		$from = $days[0]['start'];
		$till = $days[count($days) - 1]['end'];

		$maintenances = $this->getMaintenances();
		$schedule = new MaintenanceSchedule(new DateTimeZone($server_timezone));
		$windows = [];

		foreach ($maintenances as $maintenanceid => $maintenance) {
			foreach ($schedule->getWindows($maintenance, $from, $till) as $window) {
				$windows[] = [
					'maintenanceid' => $maintenanceid,
					'start' => $window['start'],
					'end' => $window['end'],
					'state' => $window['end'] <= $now ? 'past' : ($window['start'] <= $now ? 'active' : 'upcoming')
				];
			}
		}

		usort($windows, static fn(array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]
			?: strnatcasecmp($maintenances[$a['maintenanceid']]['name'], $maintenances[$b['maintenanceid']]['name'])
		);

		foreach ($windows as $index => &$window) {
			foreach ($days as &$day) {
				if ($window['start'] < $day['end'] && $window['end'] > $day['start']) {
					$day['windows'][] = $index;

					// Reminder state like the template triggers: starts later today or tomorrow.
					if (!array_key_exists('soon', $window) && $window['start'] >= $day['start']) {
						$window['soon'] = $window['state'] === 'upcoming' && $day['is_today']
							? 'today'
							: ($window['state'] === 'upcoming' && $day['is_tomorrow'] ? 'tomorrow' : null);
					}
				}
			}
			unset($day);

			$window += ['soon' => null];
		}
		unset($window);

		$used = array_flip(array_column($windows, 'maintenanceid'));

		$this->setResponse(new CControllerResponseData([
			'name' => $this->getInput('name', $this->widget->getDefaultName()),
			'view' => $view,
			'offset' => $offset,
			'title' => $range['title'],
			'subtitle' => $range['subtitle'],
			'agenda_days' => $agenda_days,
			'week_start' => $week_start,
			'show_week_numbers' => $this->fields_values['show_week_numbers'] == 1,
			'timezone' => $timezone->getName(),
			'server_timezone' => $server_timezone,
			'days' => $days,
			'windows' => $windows,
			'maintenances' => array_intersect_key($maintenances, $used),
			'now' => $now,
			'can_edit' => CWebUser::checkAccess(CRoleHelper::UI_CONFIGURATION_MAINTENANCE),
			'user' => [
				'debug_mode' => $this->getDebugMode()
			]
		]));
	}

	/**
	 * Get the days to show for a view and page offset.
	 *
	 * @return array  ['first' => first day, 'count' => number of days, 'title', 'subtitle', 'month' => 'Ym'].
	 */
	private function getRange(int $view, int $offset, DateTimeImmutable $today, int $week_start,
			int $agenda_days): array {
		switch ($view) {
			case Widget::VIEW_MONTH:
				$month = $today->modify('first day of this month')->modify(sprintf('%+d months', $offset));
				$last = $month->modify('last day of this month');
				$first = $month->modify(sprintf('-%d days', ((int) $month->format('w') - $week_start + 7) % 7));
				$end = $last->modify(sprintf('+%d days', 6 - ((int) $last->format('w') - $week_start + 7) % 7));

				return [
					'first' => $first,
					'count' => (int) $first->diff($end)->days + 1,
					'title' => zbx_date2str('F Y', $month->getTimestamp()),
					'subtitle' => '',
					'month' => $month->format('Ym')
				];

			case Widget::VIEW_WEEK:
				$first = $today
					->modify(sprintf('-%d days', ((int) $today->format('w') - $week_start + 7) % 7))
					->modify(sprintf('%+d weeks', $offset));
				$last = $first->modify('+6 days');
				$thursday = $first->modify(sprintf('+%d days', (4 - $week_start + 7) % 7));

				return [
					'first' => $first,
					'count' => 7,
					'title' => _s('Week %1$s', $thursday->format('W')),
					'subtitle' => $this->formatDateRange($first, $last),
					'month' => ''
				];

			default:
				$first = $today->modify(sprintf('%+d days', $offset * $agenda_days));
				$last = $first->modify(sprintf('+%d days', $agenda_days - 1));

				return [
					'first' => $first,
					'count' => $agenda_days,
					'title' => $offset == 0 ? _('Upcoming') : _('Agenda'),
					'subtitle' => $this->formatDateRange($first, $last),
					'month' => ''
				];
		}
	}

	private function formatDateRange(DateTimeImmutable $first, DateTimeImmutable $last): string {
		if ($first->format('Y') !== $last->format('Y')) {
			return zbx_date2str('j M Y', $first->getTimestamp()).' – '.zbx_date2str('j M Y', $last->getTimestamp());
		}

		if ($first->format('m') !== $last->format('m')) {
			return zbx_date2str('j M', $first->getTimestamp()).' – '.zbx_date2str('j M Y', $last->getTimestamp());
		}

		return $first->format('j').' – '.zbx_date2str('j M Y', $last->getTimestamp());
	}

	/**
	 * Get maintenances matching the widget filter, with the data needed for the calendar and its hints.
	 *
	 * Host groups select maintenances of the groups or of hosts in them (subgroups included). Hosts select
	 * maintenances of the hosts or of any of their groups, i.e. everything that puts the host into maintenance.
	 */
	private function getMaintenances(): array {
		$options = [
			'output' => ['maintenanceid', 'name', 'description', 'maintenance_type', 'active_since', 'active_till'],
			'selectHostGroups' => ['groupid', 'name'],
			'selectHosts' => ['hostid', 'name'],
			'selectTimeperiods' => ['timeperiod_type', 'every', 'month', 'dayofweek', 'day', 'start_time', 'period',
				'start_date'
			],
			'preservekeys' => true
		];

		if ($this->fields_values['maintenance_name'] !== '') {
			$options['search'] = ['name' => $this->fields_values['maintenance_name']];
		}

		if ($this->isTemplateDashboard()) {
			if (!$this->fields_values['override_hostid']) {
				return [];
			}

			$groupids = [];
			$hostids = $this->fields_values['override_hostid'];
		}
		else {
			$groupids = $this->fields_values['groupids'] ? getSubGroups($this->fields_values['groupids']) : [];
			$hostids = $this->fields_values['override_hostid'] ?: $this->fields_values['hostids'];
		}

		if ($hostids) {
			$hosts = API::Host()->get([
				'output' => [],
				'selectHostGroups' => ['groupid'],
				'hostids' => $hostids,
				'groupids' => $groupids ?: null,
				'preservekeys' => true
			]);

			if (!$hosts) {
				return [];
			}

			$host_groupids = [];

			foreach ($hosts as $host) {
				foreach ($host['hostgroups'] as $group) {
					$host_groupids[$group['groupid']] = true;
				}
			}

			$maintenances = API::Maintenance()->get($options + ['groupids' => array_keys($host_groupids)]);

			$maintenances = array_filter($maintenances, static function(array $maintenance) use ($hosts,
					$host_groupids): bool {
				return array_intersect_key(array_column($maintenance['hosts'], null, 'hostid'), $hosts)
					|| array_intersect_key(array_column($maintenance['hostgroups'], null, 'groupid'), $host_groupids);
			});
		}
		elseif ($groupids) {
			$maintenances = API::Maintenance()->get($options + ['groupids' => $groupids]);
		}
		else {
			$maintenances = API::Maintenance()->get($options);
		}

		foreach ($maintenances as $maintenanceid => &$maintenance) {
			$maintenance['color'] = $maintenanceid % self::COLOR_COUNT;

			$maintenance['groups'] = array_column($maintenance['hostgroups'], 'name');
			$maintenance['host_names'] = array_column($maintenance['hosts'], 'name');
			natcasesort($maintenance['groups']);
			natcasesort($maintenance['host_names']);
		}
		unset($maintenance);

		return $maintenances;
	}
}
