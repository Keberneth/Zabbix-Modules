<?php declare(strict_types=1);

require __DIR__.'/bootstrap.php';
define('TIMEPERIOD_TYPE_ONETIME', 0);
require __DIR__.'/../helpers/ChartRenderer.php';
require __DIR__.'/../helpers/ReportDataHelper.php';
require __DIR__.'/../helpers/ReportSettings.php';
require __DIR__.'/../helpers/ViewFormatter.php';
require __DIR__.'/../helpers/HtmlExportRenderer.php';

use Modules\SlaUptimeReport\Helpers\ReportDataHelper;
use Modules\SlaUptimeReport\Helpers\ReportSettings;
use Modules\SlaUptimeReport\Helpers\HtmlExportRenderer;

function DBselect(string $query, int $limit = 0) { return true; }
function DBfetch($result): array { return ['moduleid'=>'1','config'=>$GLOBALS['mm_config'] ?? '{}']; }
function zbx_dbstr(string $value): string { return "'".str_replace("'", "''", $value)."'"; }

function mm_rules(): array {
	return [
		['hostid'=>'1','key'=>'web.test.rspcode[Homepage,Step]','mode'=>'equals','value'=>200,'label'=>'Homepage','interval_seconds'=>60],
		['hostid'=>'1','key'=>'web.test.rspcode[Redirect,Step]','mode'=>'equals','value'=>302,'label'=>'Login redirect','interval_seconds'=>60],
		['hostid'=>'1','key'=>'web.test.rspcode[Missing,Step]','mode'=>'equals','value'=>200,'label'=>'Missing endpoint','interval_seconds'=>60],
		['hostid'=>'2','key'=>'web.test.rspcode[API,Step]','mode'=>'equals','value'=>200,'label'=>'API endpoint','interval_seconds'=>60]
	];
}
function mm_fixture(bool $multiple_groups = false, array $excluded = []): void {
	$groups = [['groupid'=>'10','name'=>'Web']];
	if ($multiple_groups) { $groups[] = ['groupid'=>'20','name'=>'Shared']; }
	$hosts = [
		['hostid'=>'1','host'=>'securehost','name'=>'Secure host','status'=>0,'hostgroups'=>$groups],
		['hostid'=>'2','host'=>'aggregatehost','name'=>'Aggregate host','status'=>0,'hostgroups'=>[['groupid'=>'10','name'=>'Web']]]
	];
	$rules = mm_rules();
	$GLOBALS['mm_config'] = json_encode(['availability'=>['hosts'=>$rules,'excluded_hostids'=>$excluded]]);
	API::$handlers['HostGroup'] = static fn(array $options): array => $multiple_groups
		? [['groupid'=>'10','name'=>'Web'],['groupid'=>'20','name'=>'Shared']] : [['groupid'=>'10','name'=>'Web']];
	API::$handlers['Host'] = static fn(array $options): array => $hosts;
	$items = [];
	foreach ([0=>'101',1=>'102',3=>'201'] as $index=>$itemid) {
		$items[] = ['itemid'=>$itemid,'hostid'=>$rules[$index]['hostid'],'key_'=>$rules[$index]['key'],
			'name'=>$rules[$index]['label'],'value_type'=>3,'status'=>0,'delay'=>'0'];
	}
	API::$handlers['Item'] = static fn(array $options): array => array_values(array_filter($items,
		static fn(array $item): bool => in_array($item['hostid'],array_map('strval',$options['hostids']),true)
			&& in_array($item['key_'],$options['filter']['key_'],true)));
	$base = 1700000040;
	API::$handlers['Maintenance'] = static fn(array $options): array => [[
		'maintenanceid'=>'10','name'=>'Host patching','active_since'=>$base+120,'active_till'=>$base+240,
		'maintenance_type'=>0,'hosts'=>[['hostid'=>'1']],'hostgroups'=>[],'tags'=>[],
		'timeperiods'=>[['timeperiod_type'=>0,'start_date'=>$base+120,'period'=>120]]
	]];
	$history = [];
	for ($i=0;$i<10;$i++) {
		$history[] = ['itemid'=>'101','clock'=>$base+$i*60,'value'=>200];
		$history[] = ['itemid'=>'102','clock'=>$base+$i*60,'value'=>$i===0?302:503];
		$history[] = ['itemid'=>'201','clock'=>$base+$i*60,'value'=>302];
	}
	sr_history($history); sr_trends([]);
}
function mm_report(int $top = 50): array {
	$helper = new ReportDataHelper();
	$method = new ReflectionMethod($helper,'buildAvailability');
	if (PHP_VERSION_ID < 80100) { $method->setAccessible(true); }
	$result = $method->invoke($helper,[],1700000040,1700000639,true,99.0,$top,
		[['groupid'=>'10','name'=>'Web'],['groupid'=>'20','name'=>'Shared']],false);
	return [$helper,$result];
}
function mm_rows(array $result): array {
	$rows = [];
	foreach ($result['groups'] as $group) {
		foreach ($group['rows_all'] as $row) { $rows[$row['measurementid']] = $row; }
	}
	return $rows;
}
function mm_row(array $rows, string $hostid, string $key): array {
	$id = ReportSettings::measurementId($hostid,['mode'=>'equals','key'=>$key]);
	sr_assert(isset($rows[$id]),'Expected row missing for '.$hostid.' '.$key);
	return $rows[$id];
}

$tests = [];
$tests['same-host checks remain independent and HTTP302 exception is scoped'] = static function(): void {
	mm_fixture(); [$helper,$report] = mm_report(); $rows = mm_rows($report);
	sr_equal(count($rows),4,'Four configured checks produce four independent rows');
	sr_equal(mm_row($rows,'1','web.test.rspcode[Homepage,Step]')['pct'],100.0,'Homepage stays healthy');
	sr_equal(mm_row($rows,'1','web.test.rspcode[Redirect,Step]')['pct'],12.5,'Redirect has one healthy302 of eight eligible samples');
	sr_equal(mm_row($rows,'2','web.test.rspcode[API,Step]')['pct'],0.0,'302 is unhealthy on the200-only API check');
	$missing = mm_row($rows,'1','web.test.rspcode[Missing,Step]');
	sr_equal($missing['pct'],null,'Missing item cannot inherit sibling measurements');
	sr_equal($missing['state'],'noitem','Missing item remains explicit');
};
$tests['physical-host maintenance and counts are not multiplied by checks'] = static function(): void {
	mm_fixture(); [$helper,$report] = mm_report();
	sr_equal($report['fleet']['hosts_total'],2,'Physical hosts counted once');
	sr_equal($report['fleet']['measurements_total'],4,'Measurements counted separately');
	sr_equal($report['fleet']['maintenance_hosts'],1,'One maintained real host');
	sr_equal($report['fleet']['maintenance_seconds'],120,'Host maintenance summed once despite three checks');
	foreach (mm_rows($report) as $row) {
		sr_equal($row['maintenance_seconds'],$row['hostid']==='1'?120:0,'Each row shows actual applicable host maintenance');
	}
};
$tests['group membership does not duplicate fleet measurements or chart losses'] = static function(): void {
	mm_fixture(true); [$helper,$report] = mm_report();
	sr_equal($report['fleet']['hosts_total'],2,'Physical host count ignores duplicate group membership');
	sr_equal($report['fleet']['measurements_total'],4,'Measurement count ignores duplicate group membership');
	sr_equal($report['fleet']['maintenance_seconds'],120,'Maintenance not multiplied by group membership');
	$chart_minutes=0.0;
	foreach ($report['daily']['series'] as $series) { $chart_minutes+=array_sum($series['values']); }
	sr_equal($chart_minutes,17.0,'Seven failed redirect minutes plus ten API minutes count once');
	sr_equal($report['fleet']['downtime_seconds'],1020,'Aggregate downtime is the explained sum across checks');
};
$tests['missing check survives table cap without swallowing its healthy sibling'] = static function(): void {
	mm_fixture(); [$helper,$report] = mm_report(1);
	sr_equal(count($report['groups'][0]['rows']),1,'Display cap still applies');
	sr_equal(count($report['groups'][0]['rows_all']),4,'Complete exports retain all checks');
	sr_equal(count($report['health']['nodata']),1,'Only the missing check appears without data');
	$missing = reset($report['health']['nodata']);
	sr_equal($missing['measurementid'],ReportSettings::measurementId('1',['mode'=>'equals','key'=>'web.test.rspcode[Missing,Step]']),
		'Without-data link identifies the specific missing check');
};
$tests['host exclusion removes every sibling measurement'] = static function(): void {
	mm_fixture(false,['1']); [$helper,$report] = mm_report();
	sr_equal($report['fleet']['hosts_total'],1,'Excluded physical host is absent');
	sr_equal($report['fleet']['measurements_total'],1,'All three sibling checks are excluded together');
	sr_equal($report['fleet']['maintenance_seconds'],0,'Excluded host maintenance does not remain in fleet');
	sr_equal(array_unique(array_column(mm_rows($report),'hostid')),['2'],'Only retained host is measured');
};
$tests['CSV and HTML exports retain distinct check labels'] = static function(): void {
	mm_fixture(); [$helper,$availability] = mm_report();
	$csv = $helper->flattenAvailabilityRows($availability['groups'],1700000040,1700000639);
	sr_equal(count($csv),4,'CSV has one row per configured check');
	$filter = ReportDataHelper::getDefaultFilter();
	$report = $helper->emptyReport($filter);
	$report['period'] = ['from'=>1700000040,'to'=>1700000639,'days'=>1,'label'=>'Test period'];
	foreach (['groups','fleet','daily','maintenance','source_used'] as $key) { $report[$key]=$availability[$key]; }
	$report['availability_health']=$availability['health'];
	$html = (new HtmlExportRenderer($helper))->render($filter,$report,1700000040,1700000639);
	foreach (['Homepage','Login redirect','Missing endpoint','API endpoint'] as $label) {
		sr_assert(strpos(json_encode($csv),$label)!==false,'CSV is missing check label '.$label);
		sr_assert(strpos($html,$label)!==false,'HTML is missing check label '.$label);
	}
};

$failed=0;
foreach ($tests as $name=>$test) {
	API::reset(); $GLOBALS['mm_config']='{}';
	try { $test(); echo 'PASS '.$name.PHP_EOL; }
	catch (Throwable $error) { $failed++; fwrite(STDERR,'FAIL '.$name.': '.$error->getMessage().PHP_EOL); }
}
echo count($tests).' multi-measurement scenarios, '.$failed.' failed'.PHP_EOL;
exit($failed===0?0:1);
