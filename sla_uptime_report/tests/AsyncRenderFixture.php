<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../helpers/ReportDataHelper.php';
require __DIR__.'/../helpers/ReportSettings.php';
require __DIR__.'/../helpers/ChartRenderer.php';
require __DIR__.'/../helpers/ViewFormatter.php';
require __DIR__.'/../helpers/ReportRenderer.php';

use Modules\SlaUptimeReport\Helpers\ReportDataHelper;
use Modules\SlaUptimeReport\Helpers\ReportRenderer;

$helper = new ReportDataHelper();
$filter = ReportDataHelper::getDefaultFilter();
$filter['days_back'] = (int) ($argv[2] ?? 30);
$filter['hostgroupids'] = ['1'];
$from = 1790812800;
$to = $from + $filter['days_back'] * 86400 - 1;
$report = $helper->emptyReport($filter);
$report['period'] = ['from'=>$from,'to'=>$to,'days'=>$filter['days_back'],'label'=>$filter['days_back'].' day fixture'];
$report['group_options'] = [['groupid'=>'1','name'=>'Web services']];
$report['selected_groupids'] = ['1'];
$report['cards'] = [];
$report['attention'] = [];
$data = ['title'=>'SLA & Uptime Report','helper'=>$helper,'report'=>$report,'filter'=>$filter,
	'time_from'=>$from,'time_to'=>$to,'async'=>($argv[1] ?? 'shell') === 'shell'];
$html = ReportRenderer::render($data);
if (!$data['async']) {
	echo json_encode(['html'=>$html,'filter'=>$filter,'cache'=>['enabled'=>true,'available'=>true,
		'rows_reused'=>1000,'rows_fetched'=>5,'forced'=>false]], JSON_THROW_ON_ERROR);
	exit;
}
echo '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:Arial;margin:24px}button,input,select{font:inherit}</style><style>';
echo file_get_contents(__DIR__.'/../assets/css/slareport.css');
echo '</style></head><body>'.$html.'<script>';
$manifest = json_decode(file_get_contents(__DIR__.'/../manifest.json'), true, 64, JSON_THROW_ON_ERROR);
foreach ($manifest['assets']['js'] as $asset) { echo file_get_contents(__DIR__.'/../assets/js/'.$asset); }
echo '</script></body></html>';
