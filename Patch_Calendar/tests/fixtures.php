<?php
/**
 * Maintenance fixtures shared by the PHP tests and the export for the Script item test.
 *
 * Each fixture builds a maintenance for a time zone, so wall clock times are the same everywhere.
 */

const H = 3600;
const D = 86400;

function at(string $local, DateTimeZone $tz): int {
	return (new DateTimeImmutable($local, $tz))->getTimestamp();
}

function period(array $values): array {
	return $values + [
		'timeperiod_type' => 0, 'every' => 1, 'month' => 0, 'dayofweek' => 0, 'day' => 0, 'start_time' => 0,
		'period' => H, 'start_date' => 0
	];
}

function maintenance(int $since, int $till, array ...$periods): array {
	return ['active_since' => $since, 'active_till' => $till, 'timeperiods' => $periods];
}

const FIXTURE_TIMEZONES = ['UTC', 'Europe/Stockholm', 'America/New_York', 'Australia/Sydney', 'Asia/Kolkata'];

// Ranges around both DST changes of the northern and southern hemisphere, and month/year ends.
const FIXTURE_RANGES = [['2026-03-20', '2026-04-12'], ['2026-09-26', '2026-11-08'], ['2026-12-24', '2027-01-03']];

return [
	'daily 02:00 2h' => static fn(DateTimeZone $tz): array => maintenance(at('2026-01-01', $tz), at('2027-01-01', $tz),
		period(['timeperiod_type' => 2, 'start_time' => 2 * H, 'period' => 2 * H])),
	'daily every 3, 23:00 3h, since midday' => static fn(DateTimeZone $tz): array => maintenance(
		at('2026-01-05 12:00', $tz), at('2027-01-01', $tz),
		period(['timeperiod_type' => 2, 'every' => 3, 'start_time' => 23 * H, 'period' => 3 * H])),
	'daily every 2, 02:30 1h (DST gap time)' => static fn(DateTimeZone $tz): array => maintenance(
		at('2026-01-01', $tz), at('2027-01-01', $tz),
		period(['timeperiod_type' => 2, 'every' => 2, 'start_time' => 2 * H + 1800, 'period' => H])),
	'daily 01:30 1h (repeated hour in New York)' => static fn(DateTimeZone $tz): array => maintenance(
		at('2026-01-01', $tz), at('2027-01-01', $tz),
		period(['timeperiod_type' => 2, 'start_time' => H + 1800, 'period' => H])),
	'daily every 2, 00:15 1h' => static fn(DateTimeZone $tz): array => maintenance(at('2026-01-01', $tz),
		at('2027-01-01', $tz),
		period(['timeperiod_type' => 2, 'every' => 2, 'start_time' => 900, 'period' => H])),
	'daily 00:00 24h' => static fn(DateTimeZone $tz): array => maintenance(at('2026-01-01', $tz), at('2027-01-01', $tz),
		period(['timeperiod_type' => 2, 'start_time' => 0, 'period' => D])),
	'weekly Sun 02:00 2h' => static fn(DateTimeZone $tz): array => maintenance(at('2025-06-01', $tz),
		at('2027-12-31', $tz),
		period(['timeperiod_type' => 3, 'dayofweek' => 64, 'start_time' => 2 * H, 'period' => 2 * H])),
	'weekly every 2 Mon+Thu 22:00 4h, since Wed' => static fn(DateTimeZone $tz): array => maintenance(
		at('2026-01-07', $tz), at('2027-01-01', $tz),
		period(['timeperiod_type' => 3, 'every' => 2, 'dayofweek' => 9, 'start_time' => 22 * H, 'period' => 4 * H])),
	'weekly every 3 Wed 00:30 1h' => static fn(DateTimeZone $tz): array => maintenance(at('2026-01-01', $tz),
		at('2027-01-01', $tz),
		period(['timeperiod_type' => 3, 'every' => 3, 'dayofweek' => 4, 'start_time' => 1800, 'period' => H])),
	'weekly Sat 01:30 26h' => static fn(DateTimeZone $tz): array => maintenance(at('2026-01-01', $tz),
		at('2027-01-01', $tz),
		period(['timeperiod_type' => 3, 'dayofweek' => 32, 'start_time' => H + 1800, 'period' => 26 * H])),
	'monthly day 31 03:00 2h' => static fn(DateTimeZone $tz): array => maintenance(at('2026-01-01', $tz),
		at('2027-01-01', $tz),
		period(['timeperiod_type' => 4, 'month' => 4095, 'day' => 31, 'start_time' => 3 * H, 'period' => 2 * H])),
	'monthly 2nd Tue 22:00 3h' => static fn(DateTimeZone $tz): array => maintenance(at('2025-06-01', $tz),
		at('2027-12-31', $tz),
		period(['timeperiod_type' => 4, 'every' => 2, 'month' => 4095, 'dayofweek' => 2, 'start_time' => 22 * H,
			'period' => 3 * H])),
	'monthly last Sun 02:30 2h' => static fn(DateTimeZone $tz): array => maintenance(at('2026-01-01', $tz),
		at('2027-01-01', $tz),
		period(['timeperiod_type' => 4, 'every' => 5, 'month' => 4095, 'dayofweek' => 64,
			'start_time' => 2 * H + 1800, 'period' => 2 * H])),
	'monthly last Fri, Jan/Apr/Jul/Oct, 18:00 6h' => static fn(DateTimeZone $tz): array => maintenance(
		at('2026-01-01', $tz), at('2027-01-01', $tz),
		period(['timeperiod_type' => 4, 'every' => 5, 'month' => 1 | 8 | 64 | 512, 'dayofweek' => 16,
			'start_time' => 18 * H, 'period' => 6 * H])),
	'monthly 1st Mon+Sat 04:00 1h' => static fn(DateTimeZone $tz): array => maintenance(at('2026-01-01', $tz),
		at('2027-01-01', $tz),
		period(['timeperiod_type' => 4, 'every' => 1, 'month' => 4095, 'dayofweek' => 1 | 32, 'start_time' => 4 * H,
			'period' => H])),
	'monthly day 15 01:00 30h, till mid-window' => static fn(DateTimeZone $tz): array => maintenance(
		at('2026-01-01', $tz), at('2026-10-16 03:00', $tz),
		period(['timeperiod_type' => 4, 'month' => 4095, 'day' => 15, 'start_time' => H, 'period' => 30 * H])),
	'one-time windows, clipped' => static fn(DateTimeZone $tz): array => maintenance(at('2026-03-28 12:00', $tz),
		at('2026-10-26 06:00', $tz),
		period(['start_date' => at('2026-03-28 10:00', $tz), 'period' => 30 * H]),
		period(['start_date' => at('2026-10-25 01:00', $tz), 'period' => 8 * H])),
	'daily 01:00 2h + weekly Sun 00:00 4h' => static fn(DateTimeZone $tz): array => maintenance(
		at('2026-01-01', $tz), at('2027-01-01', $tz),
		period(['timeperiod_type' => 2, 'start_time' => H, 'period' => 2 * H]),
		period(['timeperiod_type' => 3, 'dayofweek' => 64, 'start_time' => 0, 'period' => 4 * H]))
];
