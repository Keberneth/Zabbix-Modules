<?php

require_once __DIR__.'/../helpers/MaintenanceSchedule.php';

use Modules\MessageOfTheDay\Helpers\MaintenanceSchedule;

$checks = 0;
function verify($expected, $actual, string $message): void {
	global $checks;
	$checks++;
	if ($expected !== $actual) {
		throw new RuntimeException($message.'; expected '.json_encode($expected).', got '.json_encode($actual));
	}
}

function instant(string $value, string $zone = 'Europe/Stockholm'): int {
	return (new DateTimeImmutable($value, new DateTimeZone($zone)))->getTimestamp();
}

function occurrences(array $period, string $from, string $till, array $overrides = [],
	string $zone = 'Europe/Stockholm'): array {
	$timezone = new DateTimeZone($zone);
	$maintenance = array_replace([
		'maintenanceid' => '42',
		'name' => 'Weekly patching',
		'description' => 'Fixture',
		'maintenance_type' => '1',
		'active_since' => instant('2026-01-01', $zone),
		'active_till' => instant('2027-01-01', $zone),
		'timeperiods' => [$period],
		'hostgroups' => [['name' => 'Linux'], ['name' => 'Linux']],
		'hosts' => [['name' => '', 'host' => 'app-1'], ['name' => 'Database']]
	], $overrides);
	return MaintenanceSchedule::expand($maintenance, new DateTimeImmutable($from, $timezone),
		new DateTimeImmutable($till, $timezone), $timezone);
}

function startDates(array $rows, string $zone = 'Europe/Stockholm'): array {
	return array_map(static function(array $row) use ($zone): string {
		return (new DateTimeImmutable('@'.$row['start_ts']))->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i');
	}, $rows);
}

$daily = ['timeperiod_type' => 2, 'every' => 1, 'start_time' => 3600, 'period' => 7200];
$spring = occurrences($daily, '2026-03-29', '2026-03-30');
verify(1, count($spring), 'Spring transition produces one occurrence');
verify(7200, $spring[0]['end_ts'] - $spring[0]['start_ts'], 'Spring duration is elapsed seconds');
verify(instant('2026-03-29 04:00'), $spring[0]['end_ts'], 'Spring window ends at 04:00');
$autumn = occurrences($daily, '2026-10-25', '2026-10-26');
verify(7200, $autumn[0]['end_ts'] - $autumn[0]['start_ts'], 'Autumn duration is elapsed seconds');
verify(instant('2026-10-25 01:00 UTC'), $autumn[0]['end_ts'], 'Autumn window ends after exactly two hours');
verify([], occurrences(array_replace($daily, ['start_time' => 9000]), '2026-03-29', '2026-03-30'),
	'A nonexistent spring 02:30 start is skipped');
$ambiguous = occurrences(array_replace($daily, ['start_time' => 9000]), '2026-10-25', '2026-10-26');
verify(1, count($ambiguous), 'Repeated autumn clock time produces one occurrence');
verify(instant('2026-10-25 00:30 UTC'), $ambiguous[0]['start_ts'], 'First autumn 02:30 is selected');

$every_two_days = array_replace($daily, ['every' => 2, 'start_time' => 43200, 'period' => 3600]);
verify(['2026-03-30 12:00', '2026-04-01 12:00'],
	startDates(occurrences($every_two_days, '2026-03-30', '2026-04-02')),
	'Every two days at noon matches the upstream phase across spring DST');
verify(['2026-03-31 00:00'],
	startDates(occurrences(array_replace($every_two_days, ['start_time' => 0]), '2026-03-30', '2026-04-02')),
	'Every two days at midnight matches the upstream DST phase, which differs from noon');
verify(['2026-10-26 23:30'], startDates(occurrences(array_replace($every_two_days, ['start_time' => 84600]),
	'2026-10-25', '2026-10-27', ['active_since' => instant('2026-07-01')])),
	'Late evening daily interval matches upstream phase after autumn DST');
$weekly = ['timeperiod_type' => 3, 'every' => 2, 'dayofweek' => 1, 'start_time' => 43200, 'period' => 3600];
verify(['2026-03-30 12:00', '2026-04-13 12:00'],
	startDates(occurrences($weekly, '2026-03-29', '2026-04-14', ['active_since' => instant('2026-03-16')])),
	'Every two weeks at noon matches the upstream phase across spring DST');
verify(['2026-04-06 00:00'],
	startDates(occurrences(array_replace($weekly, ['start_time' => 0]), '2026-03-29', '2026-04-14',
		['active_since' => instant('2026-03-16')])),
	'Every two weeks at midnight matches the upstream DST phase, which differs from noon');
verify(['2026-06-17 12:00', '2026-06-19 12:00'],
	startDates(occurrences(array_replace($weekly, ['every' => 1, 'dayofweek' => 21]),
		'2026-06-17', '2026-06-20', ['active_since' => instant('2026-06-17 10:00')])),
	'Weekly bitmask and an activation date in the middle of a week');

$monthly = ['timeperiod_type' => 4, 'every' => 1, 'day' => 31, 'month' => 4095,
	'dayofweek' => 0, 'start_time' => 43200, 'period' => 3600];
verify([], occurrences($monthly, '2026-04-01', '2026-05-01'), 'Day 31 is absent in April');
verify(['2026-05-31 12:00'], startDates(occurrences($monthly, '2026-05-01', '2026-06-01')),
	'Day 31 remains present in May');
$leap = array_replace($monthly, ['day' => 29, 'month' => 2]);
verify([], occurrences($leap, '2026-02-01', '2026-03-01'), 'February 29 is absent in a non-leap year');
verify(['2028-02-29 12:00'], startDates(occurrences($leap, '2028-02-01', '2028-03-01', [
	'active_since' => instant('2028-01-01'), 'active_till' => instant('2029-01-01')
])), 'February 29 is present in a leap year');
verify([], occurrences(array_replace($monthly, ['day' => 1, 'month' => 1]), '2026-04-01', '2026-05-01'),
	'Month bitmask excludes unselected months');
$nth = array_replace($monthly, ['day' => 0, 'dayofweek' => 1, 'every' => 2]);
verify(['2026-06-08 12:00'], startDates(occurrences($nth, '2026-06-01', '2026-07-01')),
	'Monthly second Monday');
verify(['2026-06-29 12:00'], startDates(occurrences(array_replace($nth, ['every' => 5]), '2026-06-01', '2026-07-01')),
	'Monthly last Monday in a five-Monday month');
verify(['2026-04-27 12:00'], startDates(occurrences(array_replace($nth, ['every' => 5]), '2026-04-01', '2026-05-01')),
	'Monthly last Monday in a four-Monday month');

$long = array_replace($weekly, ['every' => 1, 'dayofweek' => 16, 'start_time' => 82800, 'period' => 345600]);
$overlap = occurrences($long, '2026-06-15', '2026-06-16');
verify(['2026-06-12 23:00'], startDates($overlap), 'A long weekly period beginning before today is included');
verify(instant('2026-06-16 23:00'), $overlap[0]['end_ts'], 'Reporting boundary does not truncate an ongoing window');
$overnight = occurrences(array_replace($daily, ['start_time' => 82800, 'period' => 10800]), '2026-06-15', '2026-06-16');
verify(['2026-06-14 23:00', '2026-06-15 23:00'], startDates($overnight), 'Both overlapping overnight daily windows are returned');

$once = ['timeperiod_type' => 0, 'start_date' => instant('2026-06-15 00:00'), 'period' => 14400];
$clipped = occurrences($once, '2026-06-15', '2026-06-16', ['active_since' => instant('2026-06-15 02:00')]);
verify(['2026-06-15 02:00'], startDates($clipped), 'One-time window start is clipped at activation');
verify(instant('2026-06-15 04:00'), $clipped[0]['end_ts'], 'Clipping a one-time start does not extend its end');
verify([], occurrences($daily, '2026-06-15', '2026-06-16', ['active_since' => instant('2026-06-15 02:00')]),
	'Recurring period before activation is skipped even if it would overlap');
$end_clip = occurrences($daily, '2026-06-15', '2026-06-16', ['active_till' => instant('2026-06-15 02:00')]);
verify(instant('2026-06-15 02:00'), $end_clip[0]['end_ts'], 'Occurrence ends at maintenance expiration');
verify([], occurrences($daily, '2026-06-15', '2026-06-16', ['active_till' => instant('2026-06-15')]),
	'Maintenance ending at the reporting start does not overlap');
verify([], occurrences($daily, '2026-06-15', '2026-06-16', ['active_since' => instant('2026-06-16')]),
	'Maintenance starting at the reporting end does not overlap');
verify([], occurrences($once, '2026-06-15 04:00', '2026-06-16'), 'Occurrence ending exactly at range start is excluded');
verify([], occurrences($daily, '2026-06-15', '2026-06-15'), 'Empty reporting interval is empty');

$duplicate = occurrences($daily, '2026-06-15', '2026-06-16', ['timeperiods' => [$daily, $daily]]);
verify(1, count($duplicate), 'Duplicate identical periods are deduplicated');
verify(['Linux'], $duplicate[0]['groups'], 'Group names are deduplicated');
verify(['app-1', 'Database'], $duplicate[0]['hosts'], 'Host display name falls back to technical name');
verify(1, $duplicate[0]['maintenance_type'], 'Maintenance metadata is preserved');
verify(['2026-06-15 01:00'], startDates(occurrences($daily, '2026-06-15', '2026-06-16', [], '+02:00'), '+02:00'),
	'Fixed-offset scheduling timezone works without transitions');
verify([], occurrences(array_replace($daily, ['start_time' => 86400]), '2026-06-15', '2026-06-16'),
	'Invalid clock time is ignored');
verify([], occurrences(array_replace($daily, ['period' => 0]), '2026-06-15', '2026-06-16'),
	'Invalid duration is ignored');
verify([], occurrences(array_replace($daily, ['timeperiod_type' => 99]), '2026-06-15', '2026-06-16'),
	'Unknown recurrence type is ignored');

echo 'MaintenanceScheduleTest: '.$checks." assertions passed.\n";
