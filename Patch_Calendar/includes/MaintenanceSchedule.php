<?php declare(strict_types = 0);

namespace Modules\PatchCalendar\Includes;

use DateTimeImmutable,
	DateTimeZone;

/**
 * Expands maintenance time periods into concrete windows.
 *
 * Mirrors how Zabbix server 7.0 decides that a maintenance is running
 * (src/libs/zbxcacheconfig/dbconfig_maintenance.c, dc_calculate_maintenance_period()):
 *  - recurring periods are evaluated in the server's local time zone;
 *  - a start time that does not exist on a day because of a DST jump is skipped;
 *  - "every N days" is counted from the local midnight of "Active since" and "every N weeks" from the
 *    local Monday midnight of the "Active since" week, using elapsed seconds like the server does;
 *  - a window never starts before "Active since" and is cut at "Active till";
 *  - a monthly "day 31" only matches months that have a 31st, "last <weekday>" is the one whose
 *    next week falls into the next month.
 *
 * The same algorithm is implemented in JavaScript in template/patch_window.js (Script item).
 */
class MaintenanceSchedule {

	public const TYPE_ONETIME = 0;
	public const TYPE_DAILY = 2;
	public const TYPE_WEEKLY = 3;
	public const TYPE_MONTHLY = 4;

	private const SEC_PER_DAY = 86400;
	private const SEC_PER_WEEK = 604800;

	private DateTimeZone $timezone;

	public function __construct(DateTimeZone $timezone) {
		$this->timezone = $timezone;
	}

	/**
	 * Get windows of a maintenance that overlap [$from, $till).
	 *
	 * Overlapping windows produced by different time periods of the same maintenance are merged, because the
	 * server treats them as one continuous maintenance.
	 *
	 * @param array $maintenance                 Maintenance as returned by maintenance.get.
	 * @param int   $maintenance['active_since']
	 * @param int   $maintenance['active_till']
	 * @param array $maintenance['timeperiods']
	 * @param int   $from
	 * @param int   $till
	 *
	 * @return array  Sorted list of ['start' => int, 'end' => int].
	 */
	public function getWindows(array $maintenance, int $from, int $till): array {
		$active_since = (int) $maintenance['active_since'];
		$active_till = (int) $maintenance['active_till'];

		if (max($from, $active_since) >= min($till, $active_till)) {
			return [];
		}

		$windows = [];

		foreach ($maintenance['timeperiods'] as $timeperiod) {
			$windows = array_merge($windows,
				$this->getTimePeriodWindows($timeperiod, $active_since, $active_till, $from, $till)
			);
		}

		usort($windows, static fn(array $a, array $b): int => [$a['start'], $a['end']] <=> [$b['start'], $b['end']]);

		$merged = [];

		foreach ($windows as $window) {
			$last = count($merged) - 1;

			if ($last >= 0 && $window['start'] < $merged[$last]['end']) {
				$merged[$last]['end'] = max($merged[$last]['end'], $window['end']);
			}
			else {
				$merged[] = $window;
			}
		}

		return $merged;
	}

	/**
	 * Get windows of a single time period that overlap [$from, $till).
	 */
	private function getTimePeriodWindows(array $timeperiod, int $active_since, int $active_till, int $from,
			int $till): array {
		$type = (int) $timeperiod['timeperiod_type'];
		$period = (int) $timeperiod['period'];

		if ($type == self::TYPE_ONETIME) {
			$start = max((int) $timeperiod['start_date'], $active_since);
			$end = min((int) $timeperiod['start_date'] + $period, $active_till);

			return ($start < $end && $start < $till && $end > $from) ? [['start' => $start, 'end' => $end]] : [];
		}

		if (!in_array($type, [self::TYPE_DAILY, self::TYPE_WEEKLY, self::TYPE_MONTHLY])) {
			return [];
		}

		$start_time = (int) $timeperiod['start_time'];
		$every = max(1, (int) $timeperiod['every']);
		$dayofweek = (int) $timeperiod['dayofweek'];
		$month = (int) $timeperiod['month'];
		$day = (int) $timeperiod['day'];

		$since_day = $this->getDayNumber($active_since);
		$since_midnight = $this->getLocalTime($since_day, 0, false);
		$since_monday = $this->getLocalTime($since_day - self::getWeekday($since_day), 0, false);

		// A window that started before $from can still be running at $from.
		$first_day = max($since_day, $this->getDayNumber(max($from - $period, $active_since)) - 1);
		$last_day = $this->getDayNumber(min($till, $active_till));

		$windows = [];

		for ($day_number = $first_day; $day_number <= $last_day; $day_number++) {
			switch ($type) {
				case self::TYPE_DAILY:
					$matches = true;
					break;

				case self::TYPE_WEEKLY:
					$matches = ($dayofweek & (1 << self::getWeekday($day_number))) != 0;
					break;

				default:
					$matches = self::monthlyDayMatches($day_number, $month, $day, $dayofweek, $every);
			}

			if (!$matches) {
				continue;
			}

			$start = $this->getLocalTime($day_number, $start_time);

			if ($start === null || $start < $active_since || $start >= $active_till) {
				continue;
			}

			if ($type == self::TYPE_DAILY && intdiv($start - $since_midnight, self::SEC_PER_DAY) % $every != 0) {
				continue;
			}

			if ($type == self::TYPE_WEEKLY && intdiv($start - $since_monday, self::SEC_PER_WEEK) % $every != 0) {
				continue;
			}

			$end = min($start + $period, $active_till);

			if ($start < $till && $end > $from) {
				$windows[] = ['start' => $start, 'end' => $end];
			}
		}

		return $windows;
	}

	/**
	 * Check whether a day matches the month, day of month or "Nth weekday" rules of a monthly period.
	 */
	private static function monthlyDayMatches(int $day_number, int $month, int $day, int $dayofweek,
			int $every): bool {
		[$y, $m, $d, $days_in_month] = self::getDate($day_number);

		if (($month & (1 << ($m - 1))) == 0) {
			return false;
		}

		if ($day != 0) {
			return $d == $day;
		}

		if (($dayofweek & (1 << self::getWeekday($day_number))) == 0) {
			return false;
		}

		// "Last" is the occurrence that has no same weekday 7 days later in the same month.
		return $every == 5 ? $d + 7 > $days_in_month : intdiv($d - 1, 7) + 1 == $every;
	}

	/**
	 * Get the local calendar day of a timestamp as days since 1970-01-01.
	 */
	public function getDayNumber(int $clock): int {
		$date = (new DateTimeImmutable('@'.$clock))->setTimezone($this->timezone);

		return intdiv(gmmktime(0, 0, 0, (int) $date->format('n'), (int) $date->format('j'),
			(int) $date->format('Y')
		), self::SEC_PER_DAY);
	}

	/**
	 * Get the timestamp of a local wall clock time on a day.
	 *
	 * Calculated like the server's dc_subtract_time(): midnight plus the seconds, corrected by the change of the
	 * UTC offset. A time that occurs twice (DST end) resolves to the first occurrence.
	 *
	 * @param int  $day_number
	 * @param int  $seconds      Seconds since midnight.
	 * @param bool $strict       Return null if the time does not exist on that day (DST start).
	 *
	 * @return int|null
	 */
	public function getLocalTime(int $day_number, int $seconds, bool $strict = true): ?int {
		[$y, $m, $d] = self::getDate($day_number);

		$midnight = (new DateTimeImmutable('now', $this->timezone))
			->setDate($y, $m, $d)
			->setTime(0, 0)
			->getTimestamp();

		$clock = $midnight + $seconds;
		$clock -= $this->getOffset($clock) - $this->getOffset($midnight);

		if ($strict) {
			$date = (new DateTimeImmutable('@'.$clock))->setTimezone($this->timezone);

			if ((int) $date->format('G') * 3600 + (int) $date->format('i') * 60 + (int) $date->format('s')
					!= $seconds) {
				return null;
			}
		}

		return $clock;
	}

	private function getOffset(int $clock): int {
		return (new DateTimeImmutable('@'.$clock))->setTimezone($this->timezone)->getOffset();
	}

	/**
	 * @return array  [year, month (1-12), day (1-31), days in month].
	 */
	public static function getDate(int $day_number): array {
		return array_map('intval', explode(' ', gmdate('Y n j t', $day_number * self::SEC_PER_DAY)));
	}

	/**
	 * @return int  0 = Monday ... 6 = Sunday (bit number in the "dayofweek" mask).
	 */
	public static function getWeekday(int $day_number): int {
		return (($day_number + 3) % 7 + 7) % 7;
	}
}
