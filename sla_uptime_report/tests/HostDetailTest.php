<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../helpers/ReportDataHelper.php';
require __DIR__.'/../helpers/ReportSettings.php';
require __DIR__.'/../helpers/HostDetailHelper.php';
foreach (['TIMEPERIOD_TYPE_ONETIME'=>0,'TIMEPERIOD_TYPE_DAILY'=>2,'TIMEPERIOD_TYPE_WEEKLY'=>3,'TIMEPERIOD_TYPE_MONTHLY'=>4] as $name=>$value) {
	if (!defined($name)) { define($name,$value); }
}
use Modules\SlaUptimeReport\Helpers\HostDetailHelper;
use Modules\SlaUptimeReport\Helpers\ReportSettings;

$stored = [];
function DBselect($sql, $limit = null) { return true; }
function DBfetch($result) { global $stored; return ['moduleid'=>'1', 'config'=>['availability'=>$stored]]; }
function zbx_dbstr($value) { return "'".$value."'"; }
function fixture(array $rows = [], string $delay = '60', array $maintenances = []): void {
	global $stored;
	$stored = [];
	API::reset();
	API::$handlers['Host'] = static fn($o) => [['hostid'=>'1', 'host'=>'test', 'name'=>'Test host', 'status'=>0,
		'hostgroups'=>[['groupid'=>'10','name'=>'Linux/DB']]]];
	API::$handlers['Item'] = static fn($o) => [['itemid'=>'11','hostid'=>'1','key_'=>'agent.ping',
		'delay'=>$delay,'value_type'=>3,'status'=>0]];
	API::$handlers['Maintenance'] = static fn($o) => $maintenances;
	sr_history(array_map(static fn($r)=>['itemid'=>'11','clock'=>$r[0],'value'=>$r[1]],$rows));
}
function detail(int $from, int $until): array { return (new HostDetailHelper())->build('1',$from,$until-1); }
$base = 1704067200; // 2024-01-01 UTC, whole minute/hour/day.
$tests = [];
$tests['minute proposal never expands evidence'] = static function(): void {
	sr_equal(HostDetailHelper::maintenanceProposal(101, 720), ['start'=>120,'end'=>720], 'Round into gap');
	sr_equal(HostDetailHelper::maintenanceProposal(101, 410), null, 'Less than five whole minutes');
	sr_equal(HostDetailHelper::subtractWindows(10,100,[[0,20],[40,70],[90,120]]), [[20,40],[70,90]],'Subtract overlapping maintenance');
};
$tests['separate gaps retain their own create choice'] = static function() use ($base): void {
	fixture([[$base,1],[$base+660,1],[$base+720,1],[$base+1380,1]]);
	$d=detail($base,$base+1440);
	sr_equal(count($d['intervals']),2,'Two distinct gaps');
	sr_equal(array_column($d['intervals'],'seconds'),[600,600],'Ten minutes per gap');
	sr_assert($d['intervals'][0]['creatable'] && $d['intervals'][1]['creatable'],'Both bracketed gaps eligible');
};
$tests['gap crossing midnight splits per day'] = static function() use ($base): void {
	fixture([[$base+23*3600,1],[$base+25*3600,1]]);
	$d=detail($base+23*3600,$base+25*3600+60);
	sr_equal(count($d['intervals']),2,'One action per day');
	sr_equal($d['intervals'][0]['end'],$base+86400,'First gap ends at midnight');
	sr_equal($d['intervals'][1]['start'],$base+86400,'Second gap begins at midnight');
};
$tests['leading trailing and zero-data gaps cannot create maintenance'] = static function() use ($base): void {
	fixture([[$base+600,1]]);
	$d=detail($base,$base+1800);
	sr_equal(count($d['intervals']),2,'Both boundaries visible');
	sr_assert(!array_filter($d['intervals'],static fn($g)=>$g['creatable']),'Boundary gaps ineligible');
	fixture(); $d=detail($base,$base+1800);
	sr_equal($d['samples'],0,'No data evidence');
	sr_assert(!$d['intervals'][0]['creatable'],'Zero data cannot authorize exclusion');
};
$tests['failed values remain separate from missing readings'] = static function() use ($base): void {
	fixture([[$base,0],[$base+60,2],[$base+120,1],[$base+780,1]]);
	$d=detail($base,$base+840);
	sr_equal(array_column($d['intervals'],'kind'),['failed','missing'],'Failure then gap');
	sr_equal($d['intervals'][0]['seconds'],120,'Failure duration uses sample cadence');
	sr_assert(!$d['intervals'][0]['creatable'],'Observed failure is not a missing-data gap');
};
$tests['missed poll with early recovery is visible while small jitter is ignored'] = static function() use ($base): void {
	fixture([[$base,1],[$base+119,1],[$base+180,1]]);
	$d=detail($base,$base+240);
	sr_equal(count($d['intervals']),1,'Only the missing poll is shown');
	sr_equal($d['intervals'][0]['seconds'],59,'Recovery one second early does not hide gap');
};
$tests['maintenance subtraction and overlapping total stay consistent'] = static function() use ($base): void {
	$maintenance = ['maintenanceid'=>'9','name'=>'Patch','maintenance_type'=>0,'active_since'=>$base,'active_till'=>$base+2000,
		'hosts'=>[['hostid'=>'1']],'hostgroups'=>[],'tags'=>[],
		'timeperiods'=>[['timeperiod_type'=>0,'start_date'=>$base+600,'period'=>600]]];
	fixture([[$base,1],[$base+1860,1]],'60',[$maintenance]);
	$d=detail($base,$base+1920);
	sr_equal($d['maintenance_seconds'],600,'Maintenance total');
	sr_equal(count($d['maintenance']),1,'Named window included');
	sr_equal(array_column($d['intervals'],'seconds'),[540,660],'Gap excludes maintenance');
};
$tests['flexible schedules cannot authorize maintenance'] = static function() use ($base): void {
	fixture([[$base,1],[$base+60,1],[$base+720,1]],'60;30/1-5,09:00-17:00');
	$d=detail($base,$base+780);
	sr_assert(!$d['fixed_cadence'],'Flexible schedule recognized');
	sr_assert(!array_filter($d['intervals'],static fn($g)=>$g['creatable']),'Estimated cadence ineligible');
};
$tests['partial history API failure disables earlier gap actions'] = static function() use ($base): void {
	fixture();
	$calls=0;
	API::$handlers['History']=static function($o) use (&$calls,$base) {
		if ($calls++ > 0) { return false; }
		$rows=[['clock'=>$base,'value'=>1],['clock'=>$base+660,'value'=>1]];
		for($i=2;$i<2000;$i++) { $rows[]=['clock'=>$base+660+$i*60,'value'=>1]; }
		return $rows;
	};
	$d=detail($base,$base+150000);
	sr_assert(!$d['complete'],'Failed page means incomplete');
	sr_assert(!array_filter($d['intervals'],static fn($g)=>$g['creatable']),'No creation from partial scan');
};
$tests['history pagination keeps final-second readings'] = static function() use ($base): void {
	$rows=[]; for($i=0;$i<2010;$i++) { $rows[]=[$base+$i*60,1]; }
	fixture($rows); $d=detail($base,$base+2010*60);
	sr_equal($d['samples'],2010,'No boundary samples lost/duplicated');
	sr_equal(count($d['intervals']),0,'No fake gaps across pages');
};
$tests['partial first trend hour is included and mixed binary is charged'] = static function() use ($base): void {
	fixture();
	sr_trends([['itemid'=>'11','clock'=>$base,'num'=>2,'value_avg'=>1,'value_min'=>0,'value_max'=>2]]);
	$d=detail($base+120,$base+8*86400);
	sr_equal($d['source'],'trends','Long binary range uses trends');
	sr_equal($d['intervals'][0]['kind'],'trend_mixed','Mixed hour visible as down');
	sr_equal($d['intervals'][0]['start'],$base+120,'First bucket clipped');
	sr_assert(!array_filter($d['intervals'],static fn($g)=>$g['creatable']),'Trends cannot create precise maintenance');
};
$tests['custom HTTP rule uses numeric history over long ranges'] = static function() use ($base): void {
	global $stored;
	fixture();
	$stored=['global'=>['mode'=>'equals','key'=>'web.test.rspcode[site,step]','value'=>200]];
	API::$handlers['Item']=static fn($o)=>[['itemid'=>'11','hostid'=>'1','key_'=>'web.test.rspcode[site,step]',
		'delay'=>'60','value_type'=>3,'status'=>0]];
	sr_history([['itemid'=>'11','clock'=>$base,'value'=>200],['itemid'=>'11','clock'=>$base+60,'value'=>500]]);
	$d=detail($base,$base+8*86400);
	sr_equal($d['source'],'history','Status codes cannot be evaluated from average');
	sr_equal($d['intervals'][0]['kind'],'failed','HTTP 500 failed');
};
$tests['excluded host blocks maintenance action without hiding evidence'] = static function() use ($base): void {
	global $stored;
	fixture([[$base,1],[$base+660,1]]); $stored=['excluded_hostids'=>['1']];
	$d=detail($base,$base+720);
	sr_assert($d['excluded'],'Exclusion surfaced');
	sr_equal(count($d['intervals']),1,'Evidence retained');
	sr_assert(!$d['intervals'][0]['creatable'],'Excluded hosts cannot authorize report adjustments');
};
$tests['explicit web polling interval enables bracketed gaps'] = static function() use ($base): void {
	global $stored;
	fixture();
	$stored=['global'=>['mode'=>'equals','key'=>'web.test.rspcode[site,step]','value'=>200,'interval_seconds'=>60]];
	API::$handlers['Item']=static fn($o)=>[['itemid'=>'11','hostid'=>'1','key_'=>'web.test.rspcode[site,step]',
		'delay'=>'0','value_type'=>3,'status'=>0]];
	sr_history([['itemid'=>'11','clock'=>$base,'value'=>200],['itemid'=>'11','clock'=>$base+660,'value'=>200]]);
	$d=detail($base,$base+720);
	sr_assert($d['fixed_cadence'] && $d['intervals'][0]['creatable'],'Explicit scenario polling cadence allows gap creation');
};
$tests['return link keeps customer scope and ignores redirect data'] = static function() use ($base): void {
	$filter=HostDetailHelper::returnFilter(json_encode(['mode'=>'days_back','days_back'=>3,'hostgroupids'=>['15'],
		'slaids'=>['6'],'target'=>'99.5','url'=>'https://example.invalid','month'=>['bad']]),$base,$base+600);
	sr_equal($filter['hostgroupids'],['15'],'Customer group filter preserved');
	sr_equal($filter['slaids'],['6'],'SLA filter preserved');
	sr_equal($filter['target'],99.5,'Target preserved');
	sr_assert(!isset($filter['url']),'No redirect URL can propagate');
};
$tests['several measurements require selection before reading history'] = static function() use ($base): void {
	global $stored;
	fixture();
	$stored=['hosts'=>[
		['hostid'=>'1','label'=>'Home','mode'=>'equals','key'=>'web.test.rspcode[home,step]','value'=>200],
		['hostid'=>'1','label'=>'Login redirect','mode'=>'equals','key'=>'web.test.rspcode[login,step]','value'=>302]
	]];
	$d=detail($base,$base+120);
	sr_assert($d['selection_required'],'A host with several websites cannot silently select its first metric');
	sr_equal($d['measurement'],null,'No implicit chosen measurement');
	sr_equal(array_column($d['measurements'],'label'),['Home','Login redirect'],'Separate named choices');
	sr_equal($d['intervals'],[],'No evidence for an unselected measurement');
	sr_assert(!array_filter(API::$calls,static fn($c)=>$c[0]==='History'),'No history read without a selection');
};
$tests['selected HTTP 200 and 302 measurements use their own item and rule'] = static function() use ($base): void {
	global $stored;
	fixture();
	$stored=['hosts'=>[
		['hostid'=>'1','label'=>'Home','mode'=>'equals','key'=>'web.test.rspcode[home,step]','value'=>200],
		['hostid'=>'1','label'=>'Login redirect','mode'=>'equals','key'=>'web.test.rspcode[login,step]','value'=>302]
	]];
	API::$handlers['Item']=static fn($o)=>[
		['itemid'=>'11','hostid'=>'1','key_'=>'web.test.rspcode[home,step]','delay'=>'60','value_type'=>3,'status'=>0],
		['itemid'=>'12','hostid'=>'1','key_'=>'web.test.rspcode[login,step]','delay'=>'60','value_type'=>3,'status'=>0]
	];
	sr_history([
		['itemid'=>'11','clock'=>$base,'value'=>200],['itemid'=>'11','clock'=>$base+60,'value'=>500],
		['itemid'=>'12','clock'=>$base,'value'=>302],['itemid'=>'12','clock'=>$base+60,'value'=>302]
	]);
	$first=ReportSettings::measurementId('1',$stored['hosts'][0]);
	$second=ReportSettings::measurementId('1',$stored['hosts'][1]);
	$a=(new HostDetailHelper())->build('1',$base,$base+119,$first);
	$b=(new HostDetailHelper())->build('1',$base,$base+119,$second);
	sr_equal($a['item']['itemid'],'11','Home item selected');
	sr_equal($b['item']['itemid'],'12','Redirect item selected');
	sr_equal(array_column($a['intervals'],'kind'),['failed'],'Home HTTP 500 is a failure');
	sr_equal($b['intervals'],[],'Expected HTTP 302 is healthy');
	sr_equal($b['measurement']['label'],'Login redirect','Selected label survives');
	sr_equal($b['measurement']['measurementid'],$second,'Stable identity survives');
};
$tests['cross-host and stale measurement selections fail before history'] = static function() use ($base): void {
	foreach ([ReportSettings::measurementId('2',['mode'=>'auto']),str_repeat('0',32),'invalid'] as $id) {
		fixture([[$base,1],[$base+660,1]]);
		$rejected=false;
		try { (new HostDetailHelper())->build('1',$base,$base+719,$id); }
		catch (InvalidArgumentException $e) { $rejected=true; }
		sr_assert($rejected,'Foreign/stale/malformed measurement must be rejected');
		sr_assert(!array_filter(API::$calls,static fn($c)=>$c[0]==='History'),'Rejected selection must not read an item');
	}
};
$tests['missing explicit measurement remains selectable with its own rule'] = static function() use ($base): void {
	global $stored;
	fixture();
	$stored=['hosts'=>[
		['hostid'=>'1','label'=>'Missing website','mode'=>'equals','key'=>'web.test.rspcode[missing,step]','value'=>302],
		['hostid'=>'1','label'=>'Other website','mode'=>'equals','key'=>'web.test.rspcode[other,step]','value'=>200]
	]];
	$id=ReportSettings::measurementId('1',$stored['hosts'][0]);
	$d=(new HostDetailHelper())->build('1',$base,$base+119,$id);
	sr_equal($d['item'],null,'No fallback item selected');
	sr_equal($d['measurement']['label'],'Missing website','Missing item keeps measurement name');
	sr_equal($d['measurement']['rule']['value'],302.0,'Missing item keeps criterion');
	sr_assert(!$d['selection_required'],'Explicit missing item is still an explicit selection');
};
foreach ($tests as $name=>$test) { $test(); echo "PASS $name\n"; }
echo count($tests)." host detail tests passed\n";
