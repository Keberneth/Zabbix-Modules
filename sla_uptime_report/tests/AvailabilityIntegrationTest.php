<?php declare(strict_types=1);

require __DIR__.'/bootstrap.php';
define('TIMEPERIOD_TYPE_ONETIME', 0);
require __DIR__.'/../helpers/ChartRenderer.php';
require __DIR__.'/../helpers/ReportDataHelper.php';
require __DIR__.'/../helpers/ReportSettings.php';
require __DIR__.'/../helpers/HostDetailHelper.php';

use Modules\SlaUptimeReport\Helpers\ReportDataHelper;
use Modules\SlaUptimeReport\Helpers\HostDetailHelper;

function DBselect(string $query, int $limit = 0) { return true; }
function DBfetch($result): array { return ['moduleid'=>'1', 'config'=>$GLOBALS['sr_config'] ?? '{}']; }
function zbx_dbstr(string $value): string { return "'".str_replace("'", "''", $value)."'"; }

function si_host(string $id, array $groups = []): array {
	return ['hostid'=>$id,'host'=>'host-'.$id,'name'=>'Host '.$id,'status'=>0,
		'hostgroups'=>$groups ?: [['groupid'=>'10','name'=>'Group A']]];
}
function si_item(string $id, string $key = 'agent.ping', int $type = 3): array {
	return ['itemid'=>$id,'hostid'=>$id,'key_'=>$key,'delay'=>'60','value_type'=>$type,'status'=>0,'name'=>$key];
}
function si_fixture(array $hosts, array $items = [], array $settings = [], array $maintenances = []): void {
	$GLOBALS['sr_config'] = json_encode(['availability'=>$settings]);
	API::$handlers['HostGroup'] = static fn(array $options): array => [['groupid'=>'10','name'=>'Group A'], ['groupid'=>'20','name'=>'Group B']];
	API::$handlers['Host'] = static fn(array $options): array => $hosts;
	API::$handlers['Item'] = static function(array $options) use ($items): array {
		return array_values(array_filter($items, static fn(array $item): bool =>
			in_array($item['hostid'], array_map('strval', $options['hostids']), true)
			&& in_array($item['key_'], $options['filter']['key_'], true)));
	};
	API::$handlers['Maintenance'] = static fn(array $options): array => $maintenances;
	sr_history([]); sr_trends([]);
}
function si_build(int $from, int $to, int $top = 50): array {
	$helper = new ReportDataHelper();
	$method = new ReflectionMethod($helper, 'buildAvailability');
	if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
	$result = $method->invoke($helper, [], $from, $to, true, 99.0, $top,
		[['groupid'=>'10','name'=>'Group A'], ['groupid'=>'20','name'=>'Group B']], false);
	$result['notes'] = $helper->getNotes();
	return $result;
}
function si_rows(array $result): array {
	$rows = [];
	foreach ($result['groups'] as $group) {
		foreach ($group['rows_all'] as $row) { $rows[(string) $row['hostid']] = $row; }
	}
	return $rows;
}
function si_maintenance(string $id, int $start, int $end, array $hosts = [], array $groups = []): array {
	return ['maintenanceid'=>$id,'name'=>'Window '.$id,'active_since'=>$start,'active_till'=>$end,
		'maintenance_type'=>0,'hosts'=>array_map(static fn(string $hostid): array => ['hostid'=>$hostid], $hosts),
		'hostgroups'=>array_map(static fn(string $name): array => ['name'=>$name], $groups),'tags'=>[],
		'timeperiods'=>[['timeperiod_type'=>0,'start_date'=>$start,'period'=>$end-$start]]];
}

$base = 1700000040;
$tests = [];
$tests['host and group exclusions precede cap counts and charts'] = static function() use ($base): void {
	$hosts = [];
	for ($i=1; $i<=2000; $i++) {
		$hosts[] = si_host((string) $i, [['groupid'=>'10','name'=>'Group A'], ['groupid'=>'20','name'=>'Group B']]);
	}
	$hosts[] = si_host('2001'); $hosts[] = si_host('2002');
	si_fixture($hosts, [si_item('2001'),si_item('2002')], ['excluded_groupids'=>['20'],'excluded_hostids'=>['2001']]);
	sr_history([['itemid'=>'2001','clock'=>$base,'value'=>0], ['itemid'=>'2002','clock'=>$base,'value'=>0],
		['itemid'=>'2002','clock'=>$base+60,'value'=>0]]);
	$result = si_build($base, $base+119);
	sr_equal($result['fleet']['hosts_total'], 1, 'Excluded hosts do not consume report capacity');
	sr_equal(array_map('strval', array_keys(si_rows($result))), ['2002'], 'Host beyond initial 2000 survives exclusion-before-cap');
	sr_equal($result['fleet']['downtime_seconds'], 120, 'Excluded hosts do not contribute downtime');
	sr_equal(count($result['groups']), 1, 'Empty excluded group is absent');
	$chart = 0.0;
	foreach ($result['daily']['series'] as $series) { $chart += array_sum($series['values']); }
	sr_equal($chart, 2.0, 'Chart counts only included host');
	foreach (API::$calls as [$name, $options]) {
		if ($name === 'Item') { sr_equal(array_map('strval', $options['hostids']), ['2002'], 'Excluded hosts never reach item scan'); }
	}
};
$tests['no-item host retains clipped overlapping maintenance union'] = static function() use ($base): void {
	si_fixture([si_host('1')], [], [], [si_maintenance('1',$base-120,$base+240,['1']),
		si_maintenance('2',$base+180,$base+480,[],['Group A'])]);
	$result = si_build($base, $base+599);
	$row = si_rows($result)['1'];
	sr_equal($row['item_key'], null, 'Host has no availability item');
	sr_equal($row['maintenance_seconds'], 480, 'Overlap counted once and clipped to report');
	sr_equal($result['fleet']['maintenance_seconds'], 480, 'Fleet maintenance includes no-item hosts');
	sr_equal($row['state'], 'noitem', 'Uncovered remainder stays without item');
	sr_equal($result['fleet']['na'], 1, 'Partially maintained no-item host remains in missing-data list');
};
$tests['no-item host wholly in maintenance leaves without-data count'] = static function() use ($base): void {
	si_fixture([si_host('1')], [], [], [si_maintenance('1',$base,$base+600,['1'])]);
	$result = si_build($base, $base+599);
	sr_equal(si_rows($result)['1']['state'], 'maint', 'Full accepted period is maintenance');
	sr_equal($result['fleet']['maintenance_only'], 1, 'Full maintenance counted explicitly');
	sr_equal($result['fleet']['na'], 0, 'Fully maintained host is not without data');
	sr_equal($result['health']['nodata'], [], 'Missing-data drilldown excludes fully maintained host');
};
$tests['long report combines default trends with custom raw-history metric'] = static function(): void {
	$from = strtotime('2023-11-14 00:00:00 UTC');
	si_fixture([si_host('1'),si_host('2')], [si_item('1'),si_item('2','web.test.rspcode[Site,Step]')],
		['hosts'=>[['hostid'=>'2','mode'=>'equals','key'=>'web.test.rspcode[Site,Step]','value'=>200,'interval_seconds'=>60]]]);
	sr_history([['itemid'=>'2','clock'=>$from,'value'=>200], ['itemid'=>'2','clock'=>$from+60,'value'=>503]]);
	sr_trends([['itemid'=>'1','clock'=>$from,'value_avg'=>1,'value_min'=>1,'value_max'=>1,'num'=>60]]);
	$result = si_build($from, $from+10*86400-1);
	$rows = si_rows($result);
	sr_equal($result['source_used'], 'mixed', 'Overall source is mixed');
	sr_equal($rows['1']['source'], 'trends', 'Default numeric ping uses trends');
	sr_equal($rows['2']['source'], 'history', 'Response code never uses averaged codes');
	sr_equal($rows['1']['uptime_seconds'], 3600, 'Trend evidence does not stretch over ten days');
	sr_assert($rows['2']['pct'] > 0 && $rows['2']['pct'] < 100, 'HTTP success and missing samples affect raw percentage');
	foreach (API::$calls as [$name,$options]) {
		if ($name === 'History') { sr_equal(array_map('strval',$options['itemids']), ['2'], 'Only custom host uses raw path'); }
		if ($name === 'Trend') { sr_equal(array_map('strval',$options['itemids']), ['1'], 'Only default host uses trend path'); }
	}
};
$tests['without-data hosts survive display row cap'] = static function() use ($base): void {
	si_fixture([si_host('1'),si_host('2'),si_host('3')], [si_item('1'),si_item('3')]);
	sr_history([['itemid'=>'1','clock'=>$base,'value'=>0], ['itemid'=>'1','clock'=>$base+60,'value'=>0]]);
	$result = si_build($base, $base+119, 1);
	sr_equal(count($result['groups'][0]['rows']), 1, 'Display rows obey cap');
	sr_equal(count($result['groups'][0]['rows_all']), 3, 'Export rows retain every host');
	sr_equal($result['fleet']['na'], 2, 'Missing-data count covers every host');
	sr_equal(array_column($result['health']['nodata'], 'hostid'), ['2','3'], 'Drilldown includes no-item and no-history hosts beyond cap');
};
$tests['future report end is clipped and fully future period rejected'] = static function(): void {
	$before = time();
	$filter = ReportDataHelper::getDefaultFilter();
	$filter['mode'] = 'custom_range';
	$filter['date_from'] = gmdate('Y-m-d', $before-86400);
	$filter['date_to'] = gmdate('Y-m-d', $before+86400);
	[$from,$to] = ReportDataHelper::resolveDateRange($filter);
	sr_assert($to >= $before && $to <= time(), 'Future end is clipped to current instant');
	sr_assert(HostDetailHelper::validRange($from,$to), 'Resolved range is valid in host drilldown');
	$filter['date_from'] = gmdate('Y-m-d', time()+86400*7);
	$filter['date_to'] = gmdate('Y-m-d', time()+86400*8);
	$rejected = false;
	try { ReportDataHelper::resolveDateRange($filter); } catch (InvalidArgumentException $e) { $rejected = true; }
	sr_assert($rejected, 'Wholly future period must not manufacture missing data');
};
$tests['maximum report range remains usable in host drilldown'] = static function(): void {
	$filter = ReportDataHelper::getDefaultFilter();
	$filter['mode'] = 'custom_range';
	$filter['date_from'] = gmdate('Y-m-d', time()-900*86400);
	$filter['date_to'] = gmdate('Y-m-d', time()-86400);
	[$from,$to] = ReportDataHelper::resolveDateRange($filter);
	sr_equal($to-$from, ReportDataHelper::MAX_RANGE_DAYS*86400, 'Report range is clamped to documented limit');
	sr_assert(HostDetailHelper::validRange($from,$to), 'Same maximum range is accepted in host drilldown');
};

$failed = 0;
foreach ($tests as $name => $test) {
	API::reset(); $GLOBALS['sr_config'] = '{}';
	try { $test(); echo 'PASS '.$name.PHP_EOL; }
	catch (Throwable $error) { $failed++; fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage().PHP_EOL); }
}
echo count($tests).' integration scenarios, '.$failed.' failed'.PHP_EOL;
exit($failed === 0 ? 0 : 1);
