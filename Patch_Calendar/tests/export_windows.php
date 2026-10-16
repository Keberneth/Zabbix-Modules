<?php
/**
 * Prints the fixture maintenances and the windows MaintenanceSchedule expands them to, as JSON.
 * Input for PatchWindowScriptTest.cjs, which checks that the Script item's JavaScript expands them the same way.
 *
 * Run: php tests/export_windows.php > /tmp/windows.json
 */
require __DIR__.'/../includes/MaintenanceSchedule.php';
$fixtures = require __DIR__.'/fixtures.php';

use Modules\PatchCalendar\Includes\MaintenanceSchedule;

$cases = [];

foreach (FIXTURE_TIMEZONES as $tz_name) {
	$tz = new DateTimeZone($tz_name);
	$schedule = new MaintenanceSchedule($tz);

	foreach ($fixtures as $label => $make) {
		$maintenance = $make($tz);

		foreach (FIXTURE_RANGES as [$from_text, $till_text]) {
			$from = at($from_text, $tz);
			$till = at($till_text, $tz);

			$cases[] = [
				'timezone' => $tz_name,
				'label' => $label,
				'from' => $from,
				'till' => $till,
				'maintenance' => $maintenance,
				'windows' => $schedule->getWindows($maintenance, $from, $till)
			];
		}
	}
}

echo json_encode($cases, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
