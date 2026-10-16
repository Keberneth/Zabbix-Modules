<?php declare(strict_types = 0);

/**
 * Patch calendar widget view.
 *
 * @var CView $this
 * @var array $data
 */

use Modules\PatchCalendar\Widget;

$state_labels = [
	'past' => _('Completed'),
	'active' => _('In progress'),
	'upcoming' => _('Upcoming')
];

$format_time = static fn(int $clock): string => zbx_date2str(TIME_FORMAT, $clock);

/**
 * Time range of a window, e.g. "10:00 PM – 01:00 AM (+1)".
 */
$format_range = static function(array $window) use ($format_time): string {
	$days_later = (int) round(
		(strtotime(zbx_date2str('Y-m-d', $window['end'] - 1)) - strtotime(zbx_date2str('Y-m-d', $window['start'])))
			/ SEC_PER_DAY
	);

	return $format_time($window['start']).' – '.$format_time($window['end'])
		.($days_later > 0 ? ' (+'.$days_later.')' : '');
};

// Countdown in whole minutes; seconds only make sense in the last minute.
$format_countdown = static fn(int $seconds): string => convertUnitsS($seconds >= SEC_PER_MIN
	? $seconds - $seconds % SEC_PER_MIN
	: max(1, $seconds)
);

$format_scope = static function(array $maintenance): string {
	return implode(', ', array_merge($maintenance['groups'], $maintenance['host_names']));
};

$make_hint = static function(array $window) use ($data, $state_labels, $format_time): CDiv {
	$maintenance = $data['maintenances'][$window['maintenanceid']];

	$same_day = zbx_date2str('Ymd', $window['start']) === zbx_date2str('Ymd', $window['end'] - 1);
	$when = zbx_date2str('D j M Y', $window['start']).', '.$format_time($window['start']).' – '
		.($same_day ? '' : zbx_date2str('D j M', $window['end']).', ').$format_time($window['end'])
		.' ('.convertUnitsS($window['end'] - $window['start']).')';

	$schedule = [];

	foreach ($maintenance['timeperiods'] as $timeperiod) {
		$schedule[] = (new CDiv(
			CMaintenanceHelper::getTimePeriodTypeNames()[$timeperiod['timeperiod_type']].': '
				.CMaintenanceHelper::getTimePeriodSchedule($timeperiod).', '
				._s('for %1$s', convertUnitsS($timeperiod['period']))
		));
	}

	if ($data['server_timezone'] !== $data['timezone']) {
		$schedule[] = (new CDiv(_s('Recurring times are in the server time zone (%1$s).', $data['server_timezone'])))
			->addClass('pc-hint-note');
	}

	$rows = [
		[_('When'), $when],
		[_('Type'), $maintenance['maintenance_type'] == MAINTENANCE_TYPE_NORMAL
			? _('With data collection')
			: _('No data collection')
		],
		[_('Schedule'), $schedule]
	];

	if ($maintenance['groups']) {
		$rows[] = [_('Host groups'), implode(', ', $maintenance['groups'])];
	}

	if ($maintenance['host_names']) {
		$host_names = array_slice($maintenance['host_names'], 0, 10);
		$more = count($maintenance['host_names']) - count($host_names);

		$rows[] = [_('Hosts'), implode(', ', $host_names).($more > 0 ? ' '._s('and %1$d more', $more) : '')];
	}

	$rows[] = [_('Active'), zbx_date2str(DATE_TIME_FORMAT, $maintenance['active_since']).' – '
		.zbx_date2str(DATE_TIME_FORMAT, $maintenance['active_till'])
	];

	if ($maintenance['description'] !== '') {
		$rows[] = [_('Description'), zbx_nl2br($maintenance['description'])];
	}

	$table = (new CDiv())->addClass('pc-hint-rows');

	foreach ($rows as [$label, $value]) {
		$table->addItem([
			(new CDiv($label))->addClass('pc-hint-label'),
			(new CDiv($value))->addClass('pc-hint-value')
		]);
	}

	return (new CDiv([
		(new CDiv([
			(new CSpan())->addClass('pc-swatch')->addClass('pc-color-'.$maintenance['color']),
			(new CSpan($maintenance['name']))->addClass('pc-hint-name'),
			(new CSpan($state_labels[$window['state']]))->addClass('pc-badge')->addClass('pc-badge-'.$window['state'])
		]))->addClass('pc-hint-header'),
		$table,
		$data['can_edit']
			? (new CDiv(
				(new CSimpleButton(_('Edit maintenance')))
					->addClass(ZBX_STYLE_BTN_LINK)
					->addClass('js-pc-edit-maintenance')
					->setAttribute('data-maintenanceid', $window['maintenanceid'])
			))->addClass('pc-hint-footer')
			: null
	]))->addClass('pc-hint');
};

/**
 * Common classes of a window element.
 */
$window_classes = static function(CTag $element, array $window) use ($data): CTag {
	$maintenance = $data['maintenances'][$window['maintenanceid']];

	return $element
		->addClass('pc-color-'.$maintenance['color'])
		->addClass('pc-'.$window['state'])
		->addClass($maintenance['maintenance_type'] == MAINTENANCE_TYPE_NODATA ? 'pc-nodata' : null)
		->addClass($window['soon'] !== null ? 'pc-soon pc-soon-'.$window['soon'] : null);
};

// Toolbar.

$offset = $data['offset'];

$views = (new CDiv())->addClass('pc-views');

foreach ([Widget::VIEW_MONTH => _('Month'), Widget::VIEW_WEEK => _('Week'), Widget::VIEW_AGENDA => _('Agenda')]
		as $view_value => $view_label) {
	$views->addItem(
		(new CSimpleButton($view_label))
			->addClass('js-pc-view')
			->addClass($data['view'] == $view_value ? 'pc-view-selected' : null)
			->setAttribute('data-view', $view_value)
	);
}

$toolbar = (new CDiv([
	(new CDiv([
		(new CButtonIcon(ZBX_ICON_CHEVRON_LEFT, _('Previous')))
			->addClass('js-pc-nav')
			->setAttribute('data-offset', $offset - 1)
			->setEnabled($offset > -120),
		(new CSimpleButton(_('Today')))
			->addClass(ZBX_STYLE_BTN_ALT)
			->addClass('js-pc-nav')
			->setAttribute('data-offset', 0)
			->setEnabled($offset != 0),
		(new CButtonIcon(ZBX_ICON_CHEVRON_RIGHT, _('Next')))
			->addClass('js-pc-nav')
			->setAttribute('data-offset', $offset + 1)
			->setEnabled($offset < 120)
	]))->addClass('pc-nav'),
	(new CDiv([
		(new CSpan($data['title']))->addClass('pc-title'),
		$data['subtitle'] !== '' ? (new CSpan($data['subtitle']))->addClass('pc-subtitle') : null
	]))
		->addClass('pc-heading')
		->setTitle(_s('Times are shown in %1$s.', $data['timezone'])),
	$views
]))->addClass('pc-toolbar');

// Calendar.

switch ($data['view']) {
	case Widget::VIEW_MONTH:
		$calendar = (new CDiv())
			->addClass('pc-month')
			->addClass($data['show_week_numbers'] ? 'pc-with-week-numbers' : null);

		$head = (new CDiv($data['show_week_numbers'] ? (new CDiv())->addClass('pc-week-number') : null))
			->addClass('pc-month-row')
			->addClass('pc-month-head');

		foreach (array_slice($data['days'], 0, 7) as $day) {
			$head->addItem(
				(new CDiv(zbx_date2str('D', $day['start'])))
					->addClass('pc-weekday')
					->addClass($day['is_weekend'] ? 'pc-weekend' : null)
			);
		}

		$weeks = (new CDiv())->addClass('pc-month-weeks');
		$thursday_index = (4 - $data['week_start'] + 7) % 7;

		foreach (array_chunk($data['days'], 7) as $week) {
			$row = (new CDiv(
				$data['show_week_numbers']
					? (new CDiv($week[$thursday_index]['week_number']))
						->addClass('pc-week-number')
						->setTitle(_s('Week %1$s', $week[$thursday_index]['week_number']))
					: null
			))->addClass('pc-month-row');

			foreach ($week as $day) {
				$entries = (new CDiv())->addClass('pc-day-entries');
				$day_list = (new CDiv())->addClass('pc-hint-day-list');

				foreach ($day['windows'] as $index) {
					$window = $data['windows'][$index];
					$maintenance = $data['maintenances'][$window['maintenanceid']];
					$starts = $window['start'] >= $day['start'];
					$ends = $window['end'] <= $day['end'];

					$time = $starts
						? $format_time($window['start'])
						: '→ '.($ends ? $format_time($window['end']) : '');

					$entries->addItem(
						$window_classes(
							(new CDiv([
								(new CSpan($time))->addClass('pc-chip-time'),
								(new CSpan($maintenance['name']))->addClass('pc-chip-name')
							]))
								->addClass('pc-chip')
								->addClass($starts ? null : 'pc-continued')
								->setHint($make_hint($window)),
							$window
						)
					);

					$day_list->addItem(
						$window_classes(
							(new CDiv([
								(new CSpan($format_range($window)))->addClass('pc-chip-time'),
								(new CSpan($maintenance['name']))->addClass('pc-chip-name')
							]))->addClass('pc-chip'),
							$window
						)
					);
				}

				if ($day['windows']) {
					$entries->setAttribute('data-day-hint', (new CDiv([
						(new CDiv(zbx_date2str('l j M', $day['start'])))->addClass('pc-hint-name'),
						$day_list
					]))->addClass('pc-hint')->toString());
				}

				$number = (int) zbx_date2str('j', $day['start']) == 1
					? zbx_date2str('j M', $day['start'])
					: zbx_date2str('j', $day['start']);

				$row->addItem(
					(new CDiv([
						(new CDiv((new CSpan($number))->addClass('pc-day-number')))->addClass('pc-day-head'),
						$entries
					]))
						->addClass('pc-day')
						->addClass($day['in_month'] ? null : 'pc-outside')
						->addClass($day['is_today'] ? 'pc-today' : null)
						->addClass($day['is_weekend'] ? 'pc-weekend' : null)
				);
			}

			$weeks->addItem($row);
		}

		$calendar->addItem([$head, $weeks]);
		break;

	case Widget::VIEW_WEEK:
		$calendar = (new CDiv())->addClass('pc-week');

		foreach ($data['days'] as $day) {
			$entries = (new CDiv())->addClass('pc-week-entries');

			foreach ($day['windows'] as $index) {
				$window = $data['windows'][$index];
				$maintenance = $data['maintenances'][$window['maintenanceid']];
				$starts = $window['start'] >= $day['start'];

				$entries->addItem(
					$window_classes(
						(new CDiv([
							(new CDiv($starts
								? $format_range($window)
								: '→ '.($window['end'] <= $day['end'] ? $format_time($window['end']) : _('All day'))
							))->addClass('pc-entry-time'),
							(new CDiv($maintenance['name']))->addClass('pc-entry-name'),
							(new CDiv($format_scope($maintenance)))->addClass('pc-entry-scope')
						]))
							->addClass('pc-entry')
							->addClass($starts ? null : 'pc-continued')
							->setHint($make_hint($window)),
						$window
					)
				);
			}

			$calendar->addItem(
				(new CDiv([
					(new CDiv([
						(new CSpan(zbx_date2str('D', $day['start'])))->addClass('pc-week-day-name'),
						(new CSpan(zbx_date2str('j', $day['start'])))->addClass('pc-week-day-number')
					]))->addClass('pc-week-day-head'),
					$entries
				]))
					->addClass('pc-week-day')
					->addClass($day['is_today'] ? 'pc-today' : null)
					->addClass($day['is_weekend'] ? 'pc-weekend' : null)
			);
		}
		break;

	default:
		$calendar = (new CDiv())->addClass('pc-agenda');
		$first_day = reset($data['days']);

		foreach ($data['days'] as $day) {
			$rows = [];

			foreach ($day['windows'] as $index) {
				$window = $data['windows'][$index];

				// List a window on the day it starts, or on the first day if it started earlier.
				if ($window['start'] < $day['start'] && $day !== $first_day) {
					continue;
				}

				$maintenance = $data['maintenances'][$window['maintenanceid']];

				switch ($window['state']) {
					case 'upcoming':
						$status = _s('in %1$s', $format_countdown($window['start'] - $data['now']));
						break;

					case 'active':
						$status = _s('ends in %1$s', $format_countdown($window['end'] - $data['now']));
						break;

					default:
						$status = $state_labels['past'];
				}

				$rows[] = $window_classes(
					(new CDiv([
						(new CDiv($format_range($window)))->addClass('pc-entry-time'),
						(new CDiv([
							(new CDiv($maintenance['name']))->addClass('pc-entry-name'),
							(new CDiv($format_scope($maintenance)))->addClass('pc-entry-scope')
						]))->addClass('pc-entry-main'),
						(new CDiv($status))->addClass('pc-entry-status')
					]))
						->addClass('pc-entry')
						->setHint($make_hint($window)),
					$window
				);
			}

			if (!$rows) {
				continue;
			}

			$calendar->addItem(
				(new CDiv([
					(new CDiv([
						(new CDiv($day['is_today']
							? _('Today')
							: ($day['is_tomorrow'] ? _('Tomorrow') : zbx_date2str('l', $day['start']))
						))->addClass('pc-agenda-weekday'),
						(new CDiv(zbx_date2str('j M', $day['start'])))->addClass('pc-agenda-date')
					]))->addClass('pc-agenda-day-head'),
					(new CDiv($rows))->addClass('pc-agenda-entries')
				]))
					->addClass('pc-agenda-day')
					->addClass($day['is_today'] ? 'pc-today' : null)
			);
		}

		if (!$data['windows']) {
			$calendar->addItem(
				(new CDiv(_s('No maintenance windows from %1$s to %2$s.',
					zbx_date2str('j M', $first_day['start']),
					zbx_date2str('j M Y', end($data['days'])['start'])
				)))->addClass('pc-empty')
			);
		}
}

(new CWidgetView($data))
	->addItem(
		(new CDiv([$toolbar, (new CDiv($calendar))->addClass('pc-body')]))
			->addClass('patch-calendar')
	)
	->setVar('calendar_view', $data['view'])
	->setVar('calendar_offset', $data['offset'])
	->show();
