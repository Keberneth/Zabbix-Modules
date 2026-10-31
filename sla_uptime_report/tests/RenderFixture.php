<?php declare(strict_types=1);
// Synthetic render fixture only: no Zabbix connection or configuration writes.
require __DIR__.'/bootstrap.php';
require __DIR__.'/../helpers/ReportDataHelper.php';
require __DIR__.'/../helpers/ReportSettings.php';
require __DIR__.'/../helpers/ChartRenderer.php';
require __DIR__.'/../helpers/ViewFormatter.php';
require __DIR__.'/../helpers/HostDetailHelper.php';
define('CSRF_TOKEN_NAME', '_csrf_token');
class CCsrfTokenHelper { public static function get(string $action): string { return 'fixture-token'; } }
class CObject { public function __construct(public string $content) {} }
class CHtmlPage {
	private string $content = '';
	public static ?array $settings = null;
	public function setTitle(string $title): self { return $this; }
	public function addItem(CObject $object): self { $this->content .= $object->content; return $this; }
	public function show(): void {
		echo '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;margin:24px;background:#f5f7fa}button,input,select{font:inherit}a{color:#0f6ad8}</style><style>';
		echo file_get_contents(__DIR__.'/../assets/css/slareport.css');
		echo file_get_contents(__DIR__.'/../assets/css/slareport.settings.css');
		echo '</style></head><body>'.$this->content.'<script>';
		if (self::$settings !== null) {
			echo 'window.__srFixtureSettings = '.json_encode(self::$settings, JSON_HEX_TAG | JSON_THROW_ON_ERROR).';';
		}
		$manifest = json_decode(file_get_contents(__DIR__.'/../manifest.json'), true, 64, JSON_THROW_ON_ERROR);
		foreach ($manifest['assets']['js'] as $asset) { echo file_get_contents(__DIR__.'/../assets/js/'.$asset); }
		echo '</script></body></html>';
	}
}
use Modules\SlaUptimeReport\Helpers\ReportDataHelper;
use Modules\SlaUptimeReport\Helpers\ReportSettings;
use Modules\SlaUptimeReport\Helpers\HostDetailHelper;
$helper = new ReportDataHelper();
$from = strtotime('2026-10-01 UTC'); $to = $from + 86400 - 1;
$view = $argv[1] ?? 'report';
if ($view === 'settings') {
	$data = ['title'=>'SLA & Uptime Report settings', 'settings'=>ReportSettings::defaults(), 'can_edit'=>true,
		'error'=>'', 'saved'=>false, 'revision'=>'fixture', 'hosts_capped'=>false,
		'groups'=>[['groupid'=>'1','name'=>'Web services']], 'hosts'=>[['hostid'=>'1','name'=>'Website <example>']]];
	$data['settings']['global'] = array_replace($data['settings']['global'],
		['key'=>'web.test.rspcode[website,home]', 'mode'=>'range','min'=>200,'max'=>399]);
	$names = ['Landshypotek Website URL Monitor','kundkannedom.landshypotek.se','secure.landshypotek.se',
		'sme.landshypotek.se','loggain.landshypotek.se','www.landshypotek.se'];
	foreach ($names as $i => $name) { $data['hosts'][] = ['hostid'=>(string) ($i + 10),'name'=>$name]; }
	$data['settings']['hosts'][] = ReportSettings::defaults()['global'] + ['hostid'=>'12'];
	if (($argv[2] ?? '') === 'stdin') {
		$data['settings'] = ReportSettings::normalize(json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR));
		$data['saved'] = true;
	}
	$data['revision'] = hash('sha256', json_encode(['availability' => $data['settings']], JSON_THROW_ON_ERROR));
	CHtmlPage::$settings = $data['settings'];
	include __DIR__.'/../views/slareport.settings.view.php';
}
elseif ($view === 'host') {
	$start = $from + 3600; $end = $start + 7200;
	$data = ['title'=>'Host availability detail', 'helper'=>$helper,'from'=>$from,'to'=>$to,
		'can_create'=>true,'error'=>null, 'return_filter'=>ReportDataHelper::getDefaultFilter(), 'detail'=>[
			'host'=>['hostid'=>'1','name'=>'Website <example>','host'=>'website'],
			'item'=>['key_'=>'web.test.rspcode[website,home]', 'rule'=>['mode'=>'equals','value'=>200,'scope'=>'host']],
			'excluded'=>false,'warnings'=>[],'source'=>'history','samples'=>1320,'interval'=>60,'fixed_cadence'=>true,
			'maintenance_seconds'=>1800,'timezone'=>'Europe/Stockholm',
			'maintenance'=>[['name'=>'Approved patching','start'=>$from+18000,'end'=>$from+19800,'maintenance_type'=>0]],
			'intervals'=>[['kind'=>'missing','start'=>$start,'end'=>$end,'seconds'=>7200,'creatable'=>true,'bracketed'=>true,
				'proposal'=>HostDetailHelper::maintenanceProposal($start,$end)]]
		]];
	$data['detail']['measurement'] = ['measurementid'=>str_repeat('a',32),'label'=>'Homepage check',
		'rule'=>$data['detail']['item']['rule']];
	$data['detail']['measurements'] = [$data['detail']['measurement'],
		['measurementid'=>str_repeat('b',32),'label'=>'Login redirect']];
	include __DIR__.'/../views/slareport.host.view.php';
}
else {
	$filter = ReportDataHelper::getDefaultFilter(); $filter['tab']='overview'; $filter['hostgroupids']=['1'];
	$report = $helper->emptyReport($filter);
	$row = ['hostid'=>'1','host'=>'Website <example>','measurementid'=>str_repeat('a',32),
		'measurement_label'=>'Homepage check','enabled'=>true,'pct'=>98.0,'state'=>'warn',
		'uptime_seconds'=>81000,'downtime_seconds'=>1800,'maintenance_seconds'=>3600,'coverage_pct'=>98.0,
		'item_key'=>'web.test.rspcode[website,home]','source'=>'history','groups'=>['Web services'],'spark'=>[100,98,100]];
	$missing = array_replace($row,['hostid'=>'2','host'=>'No samples host','pct'=>null,'state'=>'nodata','coverage_pct'=>0.0]);
	$report['period']=['from'=>$from,'to'=>$to,'label'=>'1 October 2026 (UTC)','days'=>1];
	$report['group_options']=[['groupid'=>'1','name'=>'Web services']];
	$report['selected_groupids']=['1'];
	$report['groups']=[['name'=>'Web services','token'=>'--sr-s1','hosts_total'=>2,'with_data'=>1,
		'avg'=>98.0,'worst_host'=>$row['host'],'worst_pct'=>98.0,'downtime_seconds'=>1800,'rows_total'=>2,
		'rows'=>[$row],'rows_all'=>[$row,$missing],'bands'=>['ok'=>0,'warn'=>1,'bad'=>0,'na'=>1]]];
	$report['availability_health']['nodata']=[$missing];
	$report['fleet']=array_replace($report['fleet'],['avg'=>98.0,'hosts_total'=>2,'measurements_total'=>2,'with_data'=>1,'below_target'=>1,
		'na'=>1,'downtime_seconds'=>1800,'maintenance_seconds'=>3600,'maintenance_hosts'=>1]);
	$report['cards']=[['key'=>'nodata','label'=>'Hosts without data','value'=>'1','sub'=>'No samples','tone'=>'warning']];
	$data=['title'=>'SLA & Uptime Report','helper'=>$helper,'filter'=>$filter,'report'=>$report,'time_from'=>$from,'time_to'=>$to];
	include __DIR__.'/../views/slareport.report.view.php';
}
