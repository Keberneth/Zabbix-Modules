<?php

declare(strict_types = 0);

namespace Modules\MessageOfTheDay\Helpers;

/** Expands maintenance periods using the Zabbix server's scheduling timezone. */
final class MaintenanceSchedule {

	/**
	 * Return occurrences overlapping the half-open reporting interval. The timezone is the
	 * server timezone used to schedule recurring periods; callers may format the returned
	 * timestamps in a different display timezone.
	 */
	public static function expand(array $maintenance, \DateTimeImmutable $range_start,
		\DateTimeImmutable $range_end, \DateTimeZone $timezone): array {
		$from = $range_start->getTimestamp();
		$till = $range_end->getTimestamp();
		$active_since = (int) ($maintenance['active_since'] ?? 0);
		$active_till = (int) ($maintenance['active_till'] ?? 0);
		$active_since = $active_since > 0 ? $active_since : $from;
		$active_till = $active_till > 0 ? $active_till : PHP_INT_MAX;

		if ($till <= $from || $active_till <= $from || $active_since >= $till
				|| $active_till <= $active_since) {
			return [];
		}

		$activation = (new \DateTimeImmutable('@'.$active_since))->setTimezone($timezone);
		$activation_clock = (int) $activation->format('G') * 3600
			+ (int) $activation->format('i') * 60 + (int) $activation->format('s');
		$anchor = self::subtractLocalSeconds($active_since, $activation_clock, $timezone);
		$anchor_week = self::subtractLocalSeconds($active_since,
			$activation_clock + ((int) $activation->format('N') - 1) * 86400, $timezone);
		$metadata = [
			'maintenanceid' => (string) ($maintenance['maintenanceid'] ?? ''),
			'name' => (string) ($maintenance['name'] ?? ''),
			'description' => (string) ($maintenance['description'] ?? ''),
			'maintenance_type' => (int) ($maintenance['maintenance_type'] ?? 0),
			'groups' => self::names($maintenance['hostgroups'] ?? []),
			'hosts' => self::names($maintenance['hosts'] ?? [])
		];
		$occurrences = [];

		foreach ($maintenance['timeperiods'] ?? [] as $timeperiod) {
			if (!is_array($timeperiod)) {
				continue;
			}
			$duration = (int) ($timeperiod['period'] ?? 0);
			$type = (int) ($timeperiod['timeperiod_type'] ?? -1);
			if ($duration <= 0) {
				continue;
			}

			if ($type === 0) {
				$start = (int) ($timeperiod['start_date'] ?? 0);
				if ($start > 0) {
					self::append($occurrences, $metadata, max($start, $active_since),
						min($start + $duration, $active_till), $from, $till);
				}
				continue;
			}

			if (!in_array($type, [2, 3, 4], true)) {
				continue;
			}
			$start_time = (int) ($timeperiod['start_time'] ?? 0);
			if ($start_time < 0 || $start_time >= 86400) {
				continue;
			}

			// Look back by elapsed duration, including periods which began before today.
			// UTC calendar dates are used only for iteration. Interval phase follows the
			// upstream scheduler's elapsed-seconds calculation below.
			$cursor = self::calendarDate(max($active_since, $from - $duration), $timezone);
			$last_date = self::calendarDate(min($till, $active_till), $timezone);
			while ($cursor <= $last_date) {
				$start = self::localStart($cursor, $start_time, $timezone);
				if ($start !== null && self::matches($cursor, $start, $anchor, $anchor_week, $type, $timeperiod)) {
					// Recurring periods before activation are skipped, rather than moved.
					if ($start >= $active_since && $start < $active_till) {
						self::append($occurrences, $metadata, $start,
							min($start + $duration, $active_till), $from, $till);
					}
				}
				$cursor = $cursor->modify('+1 day');
			}
		}

		$result = array_values($occurrences);
		usort($result, static function(array $a, array $b): int {
			return ($a['start_ts'] <=> $b['start_ts']) ?: ($a['end_ts'] <=> $b['end_ts']);
		});
		return $result;
	}

	private static function matches(\DateTimeImmutable $date, int $start, int $anchor,
		int $anchor_week, int $type, array $period): bool {
		$every = max(1, (int) ($period['every'] ?? 1));
		// Match release/7.0 dc_calculate_maintenance_period: the day/week phase is
		// the scheduled instant minus the local activation-day/week midnight, divided
		// by 86400/604800. Including the scheduled clock time matters around DST;
		// pure calendar differences or using the occurrence day's midnight diverge.
		if ($type === 2) {
			$days = intdiv($start - $anchor, 86400);
			return $days >= 0 && $days % $every === 0;
		}

		$weekday_matches = (((int) ($period['dayofweek'] ?? 0))
			& (1 << ((int) $date->format('N') - 1))) !== 0;
		if ($type === 3) {
			$weeks = intdiv($start - $anchor_week, 604800);
			return $weekday_matches && $weeks >= 0 && $weeks % $every === 0;
		}

		if ((((int) ($period['month'] ?? 0)) & (1 << ((int) $date->format('n') - 1))) === 0) {
			return false;
		}
		$day = (int) ($period['day'] ?? 0);
		if ($day > 0) {
			// A day which does not exist in this month does not create an occurrence.
			return (int) $date->format('j') === $day;
		}
		if (!$weekday_matches) {
			return false;
		}
		return $every === 5
			? $date->modify('+7 days')->format('n') !== $date->format('n')
			: intdiv((int) $date->format('j') - 1, 7) + 1 === $every;
	}

	/** Equivalent to Zabbix's dc_subtract_time, including its timezone correction. */
	private static function subtractLocalSeconds(int $timestamp, int $seconds,
		\DateTimeZone $timezone): int {
		$initial_offset = $timezone->getOffset(new \DateTimeImmutable('@'.$timestamp));
		$result = $timestamp - $seconds;
		$result_offset = $timezone->getOffset(new \DateTimeImmutable('@'.$result));
		return $result - ($result_offset - $initial_offset);
	}

	/** A UTC date represents a calendar day, not an instant in the scheduling timezone. */
	private static function calendarDate(int $timestamp, \DateTimeZone $timezone): \DateTimeImmutable {
		$local = (new \DateTimeImmutable('@'.$timestamp))->setTimezone($timezone);
		return new \DateTimeImmutable($local->format('Y-m-d'), new \DateTimeZone('UTC'));
	}

	/** Resolve local clock time; skip spring gaps and select the first repeated autumn time. */
	private static function localStart(\DateTimeImmutable $date, int $seconds,
		\DateTimeZone $timezone): ?int {
		$clock = $date->setTime(intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
		$wanted = $clock->format('Y-m-d H:i:s');
		$nominal = $clock->getTimestamp();
		$transitions = $timezone->getTransitions($nominal - 172800, $nominal + 172800);
		$offsets = [];
		if (is_array($transitions)) {
			foreach ($transitions as $transition) {
				$offsets[(int) $transition['offset']] = true;
			}
		}
		else {
			// Fixed-offset zones do not expose a transition table.
			$offsets[$timezone->getOffset($clock)] = true;
		}
		$matches = [];
		foreach (array_keys($offsets) as $offset) {
			$candidate = $nominal - $offset;
			$local = (new \DateTimeImmutable('@'.$candidate))->setTimezone($timezone);
			if ($local->format('Y-m-d H:i:s') === $wanted) {
				$matches[] = $candidate;
			}
		}
		return $matches ? min($matches) : null;
	}

	private static function append(array &$occurrences, array $metadata, int $start, int $end,
		int $from, int $till): void {
		if ($end <= $start || $end <= $from || $start >= $till) {
			return;
		}
		$occurrences[$start.':'.$end] = $metadata + ['start_ts' => $start, 'end_ts' => $end];
	}

	private static function names(array $rows): array {
		$names = [];
		foreach ($rows as $row) {
			$name = (string) ($row['name'] ?? '');
			if ($name === '') {
				$name = (string) ($row['host'] ?? '');
			}
			if ($name !== '') {
				$names[$name] = $name;
			}
		}
		return array_values($names);
	}
}
