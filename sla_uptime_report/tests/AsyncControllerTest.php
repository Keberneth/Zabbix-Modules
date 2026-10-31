<?php declare(strict_types=1);
require __DIR__.'/bootstrap.php';
define('ZBX_SLA_STATUS_ENABLED', 1);
require __DIR__.'/../helpers/ReportDataHelper.php';
require __DIR__.'/../helpers/ReportSettings.php';
require __DIR__.'/../helpers/ChartRenderer.php';
require __DIR__.'/../helpers/ViewFormatter.php';

class CController {
	public array $request = [];
	public array $validated = [];
	public bool $valid = true;
	public bool $csrf_enabled = true;
	public $response;
	public function __construct() { $this->init(); }
	protected function init(): void {}
	protected function disableCsrfValidation(): void { $this->csrf_enabled = false; }
	protected function getUserType(): int { return CWebUser::getType(); }
	protected function getInput(string $name, $default=null) { return $this->request[$name] ?? $default; }
	protected function setResponse($response): void { $this->response = $response; }
	protected function validateInput(array $fields): bool { $this->validated = $fields; return $this->valid; }
}
class CControllerResponseData { public function __construct(public array $data) {} }
class CControllerResponseFatal {}
function DBselect($sql, $limit=0) { return true; }
function DBfetch($result): array { return ['moduleid'=>'1','config'=>'{}']; }
function zbx_dbstr($value): string { return "'".$value."'"; }
require __DIR__.'/../actions/ReportView.php';
require __DIR__.'/../actions/ReportData.php';

use Modules\SlaUptimeReport\Actions\ReportView;
use Modules\SlaUptimeReport\Actions\ReportData;
function invoke(object $object, string $name) {
	$method = new ReflectionMethod($object, $name); $method->setAccessible(true); return $method->invoke($object);
}
API::$handlers['HostGroup'] = static fn(array $options): array => [['groupid'=>'1','name'=>'Web services']];
API::$handlers['Sla'] = static fn(array $options): array => [];
API::$handlers['Host'] = static fn(array $options): array => [];
API::$handlers['Item'] = static fn(array $options): array => [];
$checks = 0;
function check(bool $condition, string $message): void { global $checks; sr_assert($condition,$message); $checks++; }

$shell = new ReportView();
invoke($shell,'doAction');
check($shell->response->data['async'] === true, 'Normal view returns the asynchronous shell');
check(array_unique(array_column(API::$calls,0)) === ['HostGroup','Sla'], 'Initial page only loads filter options, not hosts/history/trends/SLI');
check($shell->response->data['report']['fleet']['hosts_total'] === 0, 'Shell does not invent measured availability');
API::$calls = [];
$fallback = new ReportView(); $fallback->request = ['full'=>'1'];
invoke($fallback,'doAction');
check($fallback->response->data['async'] === false, 'Explicit full=1 renders the no-JavaScript report');
check(in_array('Host',array_column(API::$calls,0),true), 'Full fallback builds actual report data');

$data = new ReportData(); $data->request = ['refresh'=>'1'];
check(invoke($data,'checkInput'), 'Data request input is accepted');
check(isset($data->validated['refresh']), 'Forced refresh is declared for Zabbix request filtering');
invoke($data,'doAction');
$payload = json_decode($data->response->data['main_block'],true,64,JSON_THROW_ON_ERROR);
check(isset($payload['html'],$payload['filter'],$payload['cache']), 'Data response contains escaped report fragment and metadata');
check(strpos($payload['html'],'data-sr-report-results') !== false, 'Response contains the expected results slot');
check(!$data->csrf_enabled, 'Read-only data loads do not require a mutable-action CSRF token');
CWebUser::$type = 0;
check(!invoke($data,'checkPermissions'), 'Unauthenticated data access is rejected');
CWebUser::$type = USER_TYPE_ZABBIX_USER;
check(invoke($data,'checkPermissions'), 'Authenticated viewer can request permission-filtered data');
$data->valid = false;
check(!invoke($data,'checkInput'), 'Invalid filters do not execute the data action');
check(isset(json_decode($data->response->data['main_block'],true)['error']), 'Invalid input produces a JSON error');
echo "$checks asynchronous controller checks passed.\n";
