<?php
/**
 * Regression tests for the maintenance window expansion used by the calendar widget.
 *
 * 1. Known calendars: concrete expectations for typical patch windows (weekly, "second Tuesday",
 *    day 31, last Sunday across a DST jump, clipping by active since/till, merged periods).
 * 2. Server parity: for a set of maintenances in several time zones, every expanded window is
 *    compared against a line-by-line port of the Zabbix 7.0 server logic (ServerMaintenanceOracle):
 *    at each window boundary and at hourly samples both must agree on "in maintenance or not".
 *
 * Run: php tests/MaintenanceScheduleTest.php
 */
require __DIR__.'/../includes/MaintenanceSchedule.php';
require __DIR__.'/ServerMaintenanceOracle.php';
$fixtures = require __DIR__.'/fixtures.php';

use Modules\PatchCalendar\Includes\MaintenanceSchedule;

function fmt(array $windows, DateTimeZone $tz): string {
	return implode(', ', array_map(static function(array $w) use ($tz): string {
		$start = (new DateTimeImmutable('@'.$w['start']))->setTimezone($tz);
		$end = (new DateTimeImmutable('@'.$w['end']))->setTimezone($tz);

		return $start->format('Y-m-d H:i T').'..'.$end->format(($end->format('Ymd') === $start->format('Ymd')
			? '' : 'Y-m-d ').'H:i T');
	}, $windows));
}

$fail = 0;

function check(string $label, string $actual, string $expected): void {
	global $fail;

	$ok = $actual === $expected;
	printf("%s  %s\n", $ok ? 'OK  ' : 'FAIL', $label);

	if (!$ok) {
		printf("      expected: %s\n      actual:   %s\n", $expected, $actual);
		$fail++;
	}
}

// ---------------------------------------------------------------------------------------------------------------
echo "Known calendars\n";

$utc = new DateTimeZone('UTC');
$sthlm = new DateTimeZone('Europe/Stockholm');
$s_utc = new MaintenanceSchedule($utc);
$s_sthlm = new MaintenanceSchedule($sthlm);

$web_farm = maintenance(1748736000, 1830211200,
	period(['timeperiod_type' => 3, 'dayofweek' => 64, 'start_time' => 2 * H, 'period' => 2 * H])
);
check('weekly Sunday 02:00 (Web Farm), October 2026',
	fmt($s_utc->getWindows($web_farm, at('2026-10-01', $utc), at('2026-11-01', $utc)), $utc),
	'2026-10-04 02:00 UTC..04:00 UTC, 2026-10-11 02:00 UTC..04:00 UTC, 2026-10-18 02:00 UTC..04:00 UTC, '.
	'2026-10-25 02:00 UTC..04:00 UTC'
);

$beta = maintenance(1748736000, 1830211200,
	period(['timeperiod_type' => 4, 'every' => 2, 'month' => 4095, 'dayofweek' => 2, 'start_time' => 22 * H,
		'period' => 3 * H
	])
);
check('second Tuesday 22:00 for 3h (Customer Beta), Oct-Dec 2026',
	fmt($s_utc->getWindows($beta, at('2026-10-01', $utc), at('2027-01-01', $utc)), $utc),
	'2026-10-13 22:00 UTC..2026-10-14 01:00 UTC, 2026-11-10 22:00 UTC..2026-11-11 01:00 UTC, '.
	'2026-12-08 22:00 UTC..2026-12-09 01:00 UTC'
);

$day31 = maintenance(at('2026-01-01', $utc), at('2028-01-01', $utc),
	period(['timeperiod_type' => 4, 'month' => 4095, 'day' => 31, 'start_time' => 3 * H, 'period' => 2 * H])
);
check('day 31 skips 30-day months (server does not clamp)',
	fmt($s_utc->getWindows($day31, at('2026-10-01', $utc), at('2027-01-01', $utc)), $utc),
	'2026-10-31 03:00 UTC..05:00 UTC, 2026-12-31 03:00 UTC..05:00 UTC'
);

$last_sunday = maintenance(at('2026-01-01', $sthlm), at('2028-01-01', $sthlm),
	period(['timeperiod_type' => 4, 'every' => 5, 'month' => 4095, 'dayofweek' => 64, 'start_time' => 2 * H + 1800,
		'period' => 2 * H
	])
);
check('last Sunday 02:30 Stockholm: skipped on spring-forward day, kept on fall-back day',
	fmt($s_sthlm->getWindows($last_sunday, at('2026-02-01', $sthlm), at('2026-05-01', $sthlm)), $sthlm).' | '.
		fmt($s_sthlm->getWindows($last_sunday, at('2026-10-01', $sthlm), at('2026-11-01', $sthlm)), $sthlm),
	'2026-02-22 02:30 CET..04:30 CET, 2026-04-26 02:30 CEST..04:30 CEST | 2026-10-25 02:30 CEST..03:30 CET'
);

$clipped = maintenance(at('2026-10-10 12:00', $utc), at('2026-10-12 06:00', $utc),
	period(['start_date' => at('2026-10-10 10:00', $utc), 'period' => 4 * H]),
	period(['start_date' => at('2026-10-12 05:00', $utc), 'period' => 4 * H])
);
check('one-time windows clipped by active since/till',
	fmt($s_utc->getWindows($clipped, at('2026-10-01', $utc), at('2026-11-01', $utc)), $utc),
	'2026-10-10 12:00 UTC..14:00 UTC, 2026-10-12 05:00 UTC..06:00 UTC'
);

$merged = maintenance(at('2026-10-01', $utc), at('2026-11-01', $utc),
	period(['timeperiod_type' => 2, 'start_time' => 1 * H, 'period' => 2 * H]),
	period(['timeperiod_type' => 3, 'dayofweek' => 64, 'start_time' => 0, 'period' => 4 * H])
);
check('overlapping periods of one maintenance are merged',
	fmt($s_utc->getWindows($merged, at('2026-10-10', $utc), at('2026-10-12', $utc)), $utc),
	'2026-10-10 01:00 UTC..03:00 UTC, 2026-10-11 00:00 UTC..04:00 UTC'
);

$every3 = maintenance(at('2026-10-05 12:00', $utc), at('2026-11-01', $utc),
	period(['timeperiod_type' => 2, 'every' => 3, 'start_time' => 23 * H, 'period' => 3 * H])
);
check('daily every 3 days, counted from the active since day',
	fmt($s_utc->getWindows($every3, at('2026-10-01', $utc), at('2026-10-15', $utc)), $utc),
	'2026-10-05 23:00 UTC..2026-10-06 02:00 UTC, 2026-10-08 23:00 UTC..2026-10-09 02:00 UTC, '.
	'2026-10-11 23:00 UTC..2026-10-12 02:00 UTC, 2026-10-14 23:00 UTC..2026-10-15 02:00 UTC'
);

$biweekly = maintenance(at('2026-01-07', $utc), at('2026-03-01', $utc),
	period(['timeperiod_type' => 3, 'every' => 2, 'dayofweek' => 1 | 8, 'start_time' => 22 * H, 'period' => 4 * H])
);
check('every 2 weeks Mon+Thu, active since a Wednesday',
	fmt($s_utc->getWindows($biweekly, at('2026-01-01', $utc), at('2026-01-31', $utc)), $utc),
	'2026-01-08 22:00 UTC..2026-01-09 02:00 UTC, 2026-01-19 22:00 UTC..2026-01-20 02:00 UTC, '.
	'2026-01-22 22:00 UTC..2026-01-23 02:00 UTC'
);

check('window that started before the range is returned',
	fmt($s_utc->getWindows($beta, at('2026-10-14 00:30', $utc), at('2026-10-15', $utc)), $utc),
	'2026-10-13 22:00 UTC..2026-10-14 01:00 UTC'
);

// ---------------------------------------------------------------------------------------------------------------
echo "\nServer parity (window boundaries and hourly samples vs. port of dc_check_maintenance_period)\n";

$started = microtime(true);
$total_checks = 0;

foreach (FIXTURE_TIMEZONES as $tz_name) {
	$tz = new DateTimeZone($tz_name);
	$schedule = new MaintenanceSchedule($tz);
	$oracle = new ServerMaintenanceOracle($tz);
	$mismatches = [];
	$checks = 0;

	foreach ($fixtures as $label => $make) {
		$maintenance = $make($tz);

		foreach (FIXTURE_RANGES as [$from_text, $till_text]) {
			$from = at($from_text, $tz);
			$till = at($till_text, $tz);
			$windows = $schedule->getWindows($maintenance, $from, $till);

			$inside = static function(int $clock) use ($windows): bool {
				foreach ($windows as $window) {
					if ($window['start'] <= $clock && $clock < $window['end']) {
						return true;
					}
				}

				return false;
			};

			$samples = range($from, $till - 1, H);

			foreach ($windows as $window) {
				array_push($samples, $window['start'] - 1, $window['start'], $window['end'] - 1, $window['end']);
			}

			foreach (array_unique($samples) as $clock) {
				if ($clock < $from || $clock >= $till) {
					continue;
				}

				$checks++;
				$expected = $oracle->isRunning($maintenance, $clock);

				if ($expected !== $inside($clock)) {
					$mismatches[] = sprintf('%s @ %s: server=%s calendar=%s', $label,
						(new DateTimeImmutable('@'.$clock))->setTimezone($tz)->format('Y-m-d H:i:s T'),
						$expected ? 'running' : 'idle', $expected ? 'idle' : 'running'
					);
				}
			}
		}
	}

	$total_checks += $checks;
	printf("%s  %-18s %6d checks, %d mismatches\n", $mismatches ? 'FAIL' : 'OK  ', $tz_name, $checks,
		count($mismatches)
	);

	foreach (array_slice($mismatches, 0, 8) as $mismatch) {
		echo '      '.$mismatch."\n";
	}

	if ($mismatches) {
		$fail++;
	}
}

printf("\n%d parity checks in %.1fs\n", $total_checks, microtime(true) - $started);
echo $fail === 0 ? "ALL TESTS PASSED\n" : "$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
