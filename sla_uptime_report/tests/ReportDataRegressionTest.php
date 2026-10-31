<?php declare(strict_types=1);

require __DIR__.'/bootstrap.php';
require __DIR__.'/../helpers/ReportDataHelper.php';
if (is_file(__DIR__.'/../helpers/ReportSettings.php')) {
	require __DIR__.'/../helpers/ReportSettings.php';
}
use Modules\SlaUptimeReport\Helpers\ReportDataHelper;

function sr_item(string $id = '1', int $type = ITEM_VALUE_TYPE_UINT64, array $rule = []): array {
	return ['itemid' => $id, 'hostid' => $id, 'key_' => 'agent.ping', 'delay' => '60',
		'value_type' => $type, 'rule' => $rule + ['key' => '', 'mode' => 'auto', 'value' => 1.0,
			'min' => 1.0, 'max' => 1.0]];
}
function sr_measure(string $source, array $items, int $from, int $to, array $maintenance = []): array {
	$helper = new ReportDataHelper();
	$method = new ReflectionMethod($helper, 'availabilityFrom'.$source);
	if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
	$groups = [];
	foreach ($items as $item) { $groups[(string) $item['hostid']] = 'Test group'; }
	return $method->invoke($helper, $items, $from, $to, $groups, $maintenance);
}

$tests = [];
$tests['raw missing polls and measured failures are both charged'] = static function(): void {
	sr_history([['itemid'=>'1','clock'=>0,'value'=>1], ['itemid'=>'1','clock'=>60,'value'=>0],
		['itemid'=>'1','clock'=>180,'value'=>1]]);
	$row = sr_measure('History', [sr_item()], 0, 239)['hosts']['1'];
	sr_equal($row['pct'], 50.0, 'Two successful samples out of four expected');
	sr_equal($row['downtime_seconds'], 120, 'Failure and missing poll total');
};
$tests['zero samples stays unknown'] = static function(): void {
	sr_history([]);
	$row = sr_measure('History', [sr_item()], 0, 3599)['hosts']['1'];
	sr_equal($row['pct'], null, 'No evidence must not invent 0%');
	sr_equal($row['downtime_seconds'], 0, 'No evidence must not invent downtime');
};
$tests['raw maintenance removes missing samples without crediting uptime'] = static function(): void {
	sr_history([['itemid'=>'1','clock'=>0,'value'=>1], ['itemid'=>'1','clock'=>180,'value'=>1]]);
	$row = sr_measure('History', [sr_item()], 0, 239, ['1'=>[[60,180]]])['hosts']['1'];
	sr_equal($row['pct'], 100.0, 'Two expected samples outside accepted window');
	sr_equal($row['uptime_seconds'], 120, 'Accepted window must not count as uptime');
	sr_equal($row['maintenance_seconds'], 120, 'Exact maintenance duration');
};
$tests['whole period maintenance is neither no-data nor uptime'] = static function(): void {
	sr_history([]);
	$row = sr_measure('History', [sr_item()], 0, 3599, ['1'=>[[0,3600]]])['hosts']['1'];
	sr_equal($row['pct'], null, 'No availability denominator remains');
	sr_assert($row['maintenance_only'], 'Whole period must be maintenance');
	sr_equal($row['maintenance_seconds'], 3600, 'Whole period is accepted time');
};
$tests['agent unavailable value two is down'] = static function(): void {
	sr_history([['itemid'=>'1','clock'=>0,'value'=>1], ['itemid'=>'1','clock'=>60,'value'=>2]]);
	$item = sr_item(); $item['key_'] = 'zabbix[host,agent,available]';
	sr_equal(sr_measure('History', [$item], 0, 119)['hosts']['1']['pct'], 50.0,
		'Agent unavailable must not be clamped into uptime');
};
$tests['HTTP response-code rule measures success values'] = static function(): void {
	sr_history([['itemid'=>'1','clock'=>0,'value'=>200], ['itemid'=>'1','clock'=>60,'value'=>503]]);
	$item = sr_item('1', ITEM_VALUE_TYPE_UINT64, ['mode'=>'equals','key'=>'web.test.rspcode[site,step]','value'=>200.0]);
	$row = sr_measure('History', [$item], 0, 119)['hosts']['1'];
	sr_equal($row['pct'], 50.0, 'HTTP 200 succeeds and 503 fails');
};
$tests['float and unsigned metrics read their respective history tables'] = static function(): void {
	sr_history([['itemid'=>'1','clock'=>0,'value'=>0.25,'type'=>0], ['itemid'=>'1','clock'=>60,'value'=>2.5,'type'=>0],
		['itemid'=>'2','clock'=>0,'value'=>1,'type'=>3], ['itemid'=>'2','clock'=>60,'value'=>1,'type'=>3]]);
	$items = [sr_item('1', ITEM_VALUE_TYPE_FLOAT, ['mode'=>'range','min'=>0.0,'max'=>1.0]), sr_item('2')];
	$result = sr_measure('History', $items, 0, 119);
	sr_equal($result['hosts']['1']['pct'], 50.0, 'Float threshold has one passing sample');
	sr_equal($result['hosts']['2']['pct'], 100.0, 'Unsigned samples remain separate');
	$types = array_unique(array_map(static fn(array $call): int => (int) $call[1]['history'],
		array_values(array_filter(API::$calls, static fn(array $call): bool => $call[0] === 'History'))));
	sort($types);
	sr_equal($types, [0,3], 'Read both numeric history types');
};
$tests['full history page retains the final report second'] = static function(): void {
	$rows = [];
	for ($clock = 0; $clock < ReportDataHelper::HISTORY_PAGE_SIZE; $clock++) { $rows[] = ['itemid'=>'1','clock'=>$clock,'value'=>1]; }
	sr_history($rows);
	$item = sr_item(); $item['delay'] = '1';
	sr_equal(sr_measure('History', [$item], 0, ReportDataHelper::HISTORY_PAGE_SIZE - 1)['hosts']['1']['pct'], 100.0,
		'Inclusive last second must be replayed after full page');
};
$tests['sparse trends only attribute time covered by evidence'] = static function(): void {
	sr_trends([['itemid'=>'1','clock'=>0,'value_avg'=>1,'value_min'=>1,'value_max'=>1,'num'=>60],
		['itemid'=>'1','clock'=>3600,'value_avg'=>0,'value_min'=>0,'value_max'=>0,'num'=>60]]);
	$result = sr_measure('Trends', [sr_item()], 0, 30 * 86400 - 1);
	$row = $result['hosts']['1'];
	sr_equal($row['pct'], 50.0, 'Availability applies to the two covered hours');
	sr_equal($row['uptime_seconds'], 3600, 'One covered hour up');
	sr_equal($row['downtime_seconds'], 3600, 'One covered hour down, not fifteen days');
	sr_equal(array_sum($result['group_daily']['Test group']), 60.0, 'Chart agrees with host downtime');
};
$tests['partial trend hours are clipped to requested period'] = static function(): void {
	sr_trends([['itemid'=>'1','clock'=>0,'value_avg'=>0,'value_min'=>0,'value_max'=>0,'num'=>60],
		['itemid'=>'1','clock'=>3600,'value_avg'=>1,'value_min'=>1,'value_max'=>1,'num'=>60]]);
	$result = sr_measure('Trends', [sr_item()], 1800, 4499);
	$row = $result['hosts']['1'];
	sr_equal($row['downtime_seconds'], 1800, 'First bucket contributes only thirty minutes');
	sr_equal($row['uptime_seconds'], 900, 'Last bucket contributes only fifteen minutes');
	sr_equal($row['pct'], 100.0/3.0, 'Availability is weighted by clipped covered seconds');
};
$tests['trend maintenance hour exclusion does not inflate remaining time'] = static function(): void {
	sr_trends([['itemid'=>'1','clock'=>0,'value_avg'=>0,'value_min'=>0,'value_max'=>0,'num'=>60],
		['itemid'=>'1','clock'=>3600,'value_avg'=>1,'value_min'=>1,'value_max'=>1,'num'=>60]]);
	$row = sr_measure('Trends', [sr_item()], 0, 7199, ['1'=>[[600,900]]])['hosts']['1'];
	sr_equal($row['maintenance_seconds'], 300, 'Show actual maintenance, not excluded trend bucket');
	sr_equal($row['uptime_seconds'], 3600, 'Only untouched hour can count as uptime');
	sr_equal($row['downtime_seconds'], 0, 'Touched hour must not be charged as downtime');
};
$tests['mixed unknown and unavailable agent trends cannot average into uptime'] = static function(): void {
	sr_trends([['itemid'=>'1','clock'=>0,'value_avg'=>1,'value_min'=>0,'value_max'=>2,'num'=>60]]);
	$item = sr_item(); $item['key_'] = 'zabbix[host,agent,available]';
	$row = sr_measure('Trends', [$item], 0, 3599)['hosts']['1'];
	sr_equal($row['pct'], 0.0, 'An average of unknown and unavailable is not available');
	sr_equal($row['downtime_seconds'], 3600, 'Ambiguous legacy trend is conservatively charged');
};
$tests['a full same-second page stops safely without invented availability'] = static function(): void {
	$calls = 0;
	API::$handlers['History'] = static function(array $options) use (&$calls): array {
		if (++$calls > 3) { throw new RuntimeException('History pagination made no progress'); }
		return array_fill(0, ReportDataHelper::HISTORY_PAGE_SIZE, ['itemid'=>'1','clock'=>0,'value'=>1]);
	};
	$row = sr_measure('History', [sr_item()], 0, 3599)['hosts']['1'];
	sr_equal($row['pct'], null, 'Incomplete same-clock scan stays unknown');
	sr_equal($row['downtime_seconds'], 0, 'Truncation must not manufacture downtime');
	sr_assert($calls <= 2, 'Pagination must stop promptly when cursor cannot advance');
};

$failed = 0;
foreach ($tests as $name => $test) {
	API::reset();
	try { $test(); echo 'PASS '.$name.PHP_EOL; }
	catch (Throwable $error) { $failed++; fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage().PHP_EOL); }
}
echo count($tests).' scenarios, '.$failed.' failed'.PHP_EOL;
exit($failed === 0 ? 0 : 1);
