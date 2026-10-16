<?php
/**
 * Line-by-line port of the Zabbix 7.0 server code that decides whether a maintenance is running
 * (src/libs/zbxcacheconfig/dbconfig_maintenance.c: dc_subtract_time(), dc_calculate_maintenance_period(),
 * dc_check_maintenance_period() and the active_since/active_until check in zbx_dc_update_maintenances()).
 *
 * Used only by the tests as the reference the calendar expansion is compared against.
 */
final class ServerMaintenanceOracle {

	private const SEC_PER_DAY = 86400;
	private const SEC_PER_WEEK = 604800;

	private DateTimeZone $timezone;

	public function __construct(DateTimeZone $timezone) {
		$this->timezone = $timezone;
	}

	public function isRunning(array $maintenance, int $now): bool {
		if ($now < $maintenance['active_since'] || $now >= $maintenance['active_till']) {
			return false;
		}

		foreach ($maintenance['timeperiods'] as $period) {
			if ($this->checkPeriod($maintenance, $period, $now)) {
				return true;
			}
		}

		return false;
	}

	/** zbx_get_timezone_offset() */
	private function offset(int $clock): int {
		return (new DateTimeImmutable('@'.$clock))->setTimezone($this->timezone)->getOffset();
	}

	/** zbx_localtime() */
	private function localtime(int $clock): array {
		$date = (new DateTimeImmutable('@'.$clock))->setTimezone($this->timezone);

		return [
			'sec_of_day' => (int) $date->format('G') * 3600 + (int) $date->format('i') * 60
				+ (int) $date->format('s'),
			'wday' => (int) $date->format('w'),
			'mday' => (int) $date->format('j'),
			'mon' => (int) $date->format('n') - 1,
			'days_in_month' => (int) $date->format('t')
		];
	}

	/** dc_subtract_time() */
	private function subtract(int $minuend, int $subtrahend): int {
		$offset_min = $this->offset($minuend);
		$diff = $minuend - $subtrahend;
		$offset_diff = $this->offset($diff);

		return $diff - ($offset_diff - $offset_min);
	}

	/** dc_calculate_maintenance_period() */
	private function calculatePeriod(array $maintenance, array $period, int $start_date, ?int &$running_since,
			?int &$running_until): bool {
		$active_since = (int) $maintenance['active_since'];

		if ($period['timeperiod_type'] == 0) {
			$running_since = $period['start_date'] < $active_since ? $active_since : (int) $period['start_date'];
			$running_until = $period['start_date'] + $period['period'];

			if ($maintenance['active_till'] < $running_until) {
				$running_until = (int) $maintenance['active_till'];
			}

			return true;
		}

		switch ($period['timeperiod_type']) {
			case 2:
				if ($start_date < $active_since) {
					return false;
				}

				$tm = $this->localtime($active_since);
				$active_since = $this->subtract($active_since, $tm['sec_of_day']);

				$day = intdiv($start_date - $active_since, self::SEC_PER_DAY);
				$start_date = $this->subtract($start_date, self::SEC_PER_DAY * ($day % $period['every']));
				break;

			case 3:
				if ($start_date < $active_since) {
					return false;
				}

				$tm = $this->localtime($active_since);
				$wday = ($tm['wday'] == 0 ? 7 : $tm['wday']) - 1;
				$active_since = $this->subtract($active_since, $wday * self::SEC_PER_DAY + $tm['sec_of_day']);

				for (; $start_date >= $active_since;
						$start_date = $this->subtract($start_date, self::SEC_PER_DAY)) {
					$week = intdiv($start_date - $active_since, self::SEC_PER_WEEK);

					if ($week % $period['every'] != 0) {
						continue;
					}

					$tm = $this->localtime($start_date);
					$wday = ($tm['wday'] == 0 ? 7 : $tm['wday']) - 1;

					if (($period['dayofweek'] & (1 << $wday)) == 0) {
						continue;
					}

					break;
				}
				break;

			case 4:
				for (; $start_date >= $active_since;
						$start_date = $this->subtract($start_date, self::SEC_PER_DAY)) {
					$tm = $this->localtime($start_date);

					if (($period['month'] & (1 << $tm['mon'])) == 0) {
						continue;
					}

					if ($period['day'] != 0) {
						if ($period['day'] != $tm['mday']) {
							continue;
						}
					}
					else {
						$wday = ($tm['wday'] == 0 ? 7 : $tm['wday']) - 1;

						if (($period['dayofweek'] & (1 << $wday)) == 0) {
							continue;
						}

						$day = intdiv($tm['mday'] - 1, 7) + 1;

						if ($period['every'] == 5 && $day == 4) {
							if ($tm['mday'] + 7 <= $tm['days_in_month']) {
								continue;
							}
						}
						elseif ($period['every'] != $day) {
							continue;
						}
					}

					if ($start_date < $active_since) {
						return false;
					}

					break;
				}
				break;

			default:
				return false;
		}

		$running_since = $start_date;
		$running_until = $start_date + $period['period'];

		if ($maintenance['active_till'] < $running_until) {
			$running_until = (int) $maintenance['active_till'];
		}

		return true;
	}

	/** dc_check_maintenance_period() */
	private function checkPeriod(array $maintenance, array $period, int $now): bool {
		$tm = $this->localtime($now);
		$period_start = $this->subtract($now, $tm['sec_of_day']);
		$period_start = $this->subtract($period_start, -$period['start_time']);

		$tm = $this->localtime($period_start);

		// Skip maintenance if the time does not exist due to DST.
		if ($period['start_time'] != $tm['sec_of_day']) {
			return false;
		}

		if ($now < $period_start) {
			$period_start = $this->subtract($period_start, self::SEC_PER_DAY);
		}

		return $this->calculatePeriod($maintenance, $period, $period_start, $since, $until)
			&& $since <= $now && $now < $until;
	}
}
