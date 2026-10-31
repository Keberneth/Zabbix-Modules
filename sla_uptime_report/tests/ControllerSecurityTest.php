<?php declare(strict_types=1);

require __DIR__.'/bootstrap.php';

// These doubles exercise the module's authorization/revalidation decisions, not
// Zabbix's own POST/CSRF implementation (which must still be tested live).
class CController {
	public array $request = [];
	public array $access = [];
	public bool $csrf_enabled = true;
	public $response;
	public function __construct() { $this->init(); }
	protected function init() {}
	protected function disableCsrfValidation(): void { $this->csrf_enabled = false; }
	protected function getUserType(): int { return CWebUser::getType(); }
	protected function checkAccess(string $rule): bool { return in_array($rule, $this->access, true); }
	protected function getInput(string $key, $default = null) { return $this->request[$key] ?? $default; }
	protected function setResponse($response): void { $this->response = $response; }
	protected function validateInput(array $rules): bool { return true; }
}
class CRoleHelper {
	public const UI_CONFIGURATION_MAINTENANCE = 'ui.configuration.maintenance';
	public const ACTIONS_EDIT_MAINTENANCE = 'actions.edit_maintenance';
}
class CControllerResponseFatal {}
class CControllerResponseData { public function __construct(public array $data) {} }
class CControllerResponseRedirect {
	public function __construct(CUrl $url) {}
}
class CMessageHelper {
	public static string $success = '';
	public static string $error = '';
	public static function setSuccessTitle(string $message): void { self::$success = $message; }
	public static function setErrorTitle(string $message): void { self::$error = $message; }
}
class CUrl {
	public function __construct(string $url) {}
	public function setArgument(string $key, $value): self { return $this; }
}
function DBselect(string $query, int $limit = 0) { return true; }
function DBfetch($result): array { return ['moduleid' => '1', 'config' => $GLOBALS['sr_config'] ?? '{}']; }
function zbx_dbstr(string $value): string { return "'".str_replace("'", "''", $value)."'"; }

require __DIR__.'/../helpers/ReportDataHelper.php';
require __DIR__.'/../helpers/ReportSettings.php';
require __DIR__.'/../helpers/HostDetailHelper.php';
require __DIR__.'/../actions/SettingsSave.php';
require __DIR__.'/../actions/SettingsView.php';
require __DIR__.'/../actions/MaintenanceCreate.php';

use Modules\SlaUptimeReport\Actions\MaintenanceCreate;
use Modules\SlaUptimeReport\Actions\SettingsSave;
use Modules\SlaUptimeReport\Actions\SettingsView;
use Modules\SlaUptimeReport\Helpers\ReportSettings;

function sr_invoke(object $controller, string $method) {
	$reflection = new ReflectionMethod($controller, $method);
	if (PHP_VERSION_ID < 80100) { $reflection->setAccessible(true); }
	return $reflection->invoke($controller);
}
function sr_create_action(): MaintenanceCreate {
	$controller = new MaintenanceCreate();
	$controller->access = [CRoleHelper::UI_CONFIGURATION_MAINTENANCE, CRoleHelper::ACTIONS_EDIT_MAINTENANCE];
	$controller->request = ['hostid'=>'1', 'time_from'=>1700000040, 'time_to'=>1700000999,
		'gap_start'=>1700000100, 'gap_end'=>1700000940, 'reason'=>'CHG-123 planned work', 'acknowledge'=>1];
	return $controller;
}
function sr_creation_fixture(): void {
	API::$handlers['Host'] = static fn(array $options): array => [['hostid'=>'1','host'=>'demo','name'=>'Demo',
		'status'=>0,'hostgroups'=>[['groupid'=>'10','name'=>'Linux']]]];
	API::$handlers['Item'] = static fn(array $options): array => [['itemid'=>'1','hostid'=>'1','key_'=>'agent.ping',
		'delay'=>'60','value_type'=>3,'status'=>0]];
	API::$handlers['Maintenance'] = static fn(array $options): array => [];
	API::$handlers['Maintenance.create'] = static fn(array $options): array => ['maintenanceids'=>['50']];
	sr_history([['itemid'=>'1','clock'=>1700000040,'value'=>1], ['itemid'=>'1','clock'=>1700000940,'value'=>1]]);
}
function sr_multi_creation_fixture(): array {
	sr_creation_fixture();
	$rules = [
		['hostid'=>'1','key'=>'web.test.rspcode[Homepage,Step]','mode'=>'equals','value'=>200,'label'=>'Homepage','interval_seconds'=>60],
		['hostid'=>'1','key'=>'web.test.rspcode[Redirect,Step]','mode'=>'equals','value'=>302,'label'=>'Redirect','interval_seconds'=>60],
		['hostid'=>'2','key'=>'web.test.rspcode[Other,Step]','mode'=>'equals','value'=>200,'label'=>'Other host','interval_seconds'=>60]
	];
	$GLOBALS['sr_config'] = json_encode(['availability'=>['hosts'=>$rules]]);
	API::$handlers['Item'] = static fn(array $options): array => [
		['itemid'=>'101','hostid'=>'1','key_'=>$rules[0]['key'],'delay'=>'0','value_type'=>3,'status'=>0],
		['itemid'=>'102','hostid'=>'1','key_'=>$rules[1]['key'],'delay'=>'0','value_type'=>3,'status'=>0]
	];
	$history = [['itemid'=>'101','clock'=>1700000040,'value'=>200], ['itemid'=>'101','clock'=>1700000940,'value'=>200]];
	for ($i=0;$i<16;$i++) { $history[] = ['itemid'=>'102','clock'=>1700000040+$i*60,'value'=>302]; }
	sr_history($history);
	return array_map(static fn(array $rule): string => ReportSettings::measurementId($rule['hostid'],$rule), $rules);
}

$tests = [];
$tests['settings read is Admin-only and write is Super Admin-only'] = static function(): void {
	foreach ([1=>[false,false], 2=>[true,false], 3=>[true,true]] as $type => [$read,$write]) {
		CWebUser::$type = $type;
		sr_equal(sr_invoke(new SettingsView(), 'checkPermissions'), $read, 'Settings read permission for type '.$type);
		sr_equal(sr_invoke(new SettingsSave(), 'checkPermissions'), $write, 'Settings write permission for type '.$type);
	}
};
$tests['scoped Admin settings omit inaccessible hosts and groups'] = static function(): void {
	CWebUser::$type = USER_TYPE_ZABBIX_ADMIN;
	$GLOBALS['sr_config'] = json_encode(['availability'=>[
		'hosts'=>[['hostid'=>'1','mode'=>'binary','key'=>'visible.ping'], ['hostid'=>'2','mode'=>'binary','key'=>'secret.ping']],
		'groups'=>[['groupid'=>'10','mode'=>'binary','key'=>'visible.group'], ['groupid'=>'20','mode'=>'binary','key'=>'secret.group']],
		'excluded_hostids'=>['1','2'], 'excluded_groupids'=>['10','20']]]);
	API::$handlers['HostGroup'] = static fn(array $options): array => [['groupid'=>'10','name'=>'Visible group']];
	API::$handlers['Host'] = static fn(array $options): array => isset($options['hostids']) ? []
		: [['hostid'=>'1','name'=>'Visible host','host'=>'visible']];
	$data = SettingsView::viewData();
	sr_equal(array_column($data['settings']['hosts'], 'hostid'), ['1'], 'Only readable host overrides remain');
	sr_equal(array_column($data['settings']['groups'], 'groupid'), ['10'], 'Only readable group overrides remain');
	sr_equal($data['settings']['excluded_hostids'], ['1'], 'Hidden host exclusions are omitted');
	sr_equal($data['settings']['excluded_groupids'], ['10'], 'Hidden group exclusions are omitted');
	sr_assert(strpos(json_encode($data), 'secret.') === false, 'Hidden measurement keys must not leak');
	sr_equal($data['revision'], '', 'Admin does not receive shared write revision');
	sr_equal($data['can_edit'], false, 'Scoped Admin view is read-only');
};
$tests['mutating controllers preserve native CSRF protection'] = static function(): void {
	sr_assert((new SettingsSave())->csrf_enabled, 'Settings must keep default CSRF validation');
	sr_assert((new MaintenanceCreate())->csrf_enabled, 'Maintenance must keep default CSRF validation');
};
$tests['rejected bulk save retains every submitted row without changing stored settings'] = static function(): void {
	$stored = ReportSettings::normalize(['hosts'=>[['hostid'=>'1','key'=>'agent.ping','mode'=>'binary']]]);
	$GLOBALS['sr_config'] = json_encode(['availability'=>$stored]);
	API::$handlers['Host'] = static fn(array $options): array => [['hostid'=>'1','name'=>'Website'],['hostid'=>'2','name'=>'Other website']];
	API::$handlers['HostGroup'] = static fn(array $options): array => [];
	$controller = new SettingsSave();
	$controller->request = ['settings'=>['hosts'=>[
		['hostid'=>'1','key'=>'web.test.rspcode[Site,Home]','label'=>'Homepage','mode'=>'equals','value'=>'200'],
		['hostid'=>'1','key'=>'web.test.rspcode[Site,Login]','label'=>'Login','mode'=>'equals','value'=>'302'],
		['hostid'=>'2','key'=>'web.test.rspcode[Other,Home]','label'=>'Other website','mode'=>'equals','value'=>'200'],
		['hostid'=>'1','key'=>'web.test.rspcode[Site,Home]','label'=>'Duplicate to correct','mode'=>'equals','value'=>'200']
	], 'excluded_hostids_extra'=>'2'], 'revision'=>ReportSettings::snapshot()['revision'], 'form_complete'=>'1'];
	$before = $GLOBALS['sr_config'];
	sr_invoke($controller, 'doAction');
	$data = $controller->response->data;
	sr_equal(count($data['settings']['hosts']), 4, 'All submitted rows remain visible after duplicate validation fails');
	sr_equal(array_column($data['settings']['hosts'], 'label'), ['Homepage','Login','Other website','Duplicate to correct'], 'Labels identify the retained rows');
	sr_equal($data['settings']['excluded_hostids_extra'], '2', 'Additional exclusions survive validation failure');
	sr_assert($data['unsaved_settings'] && strpos($data['error'], 'item key once') !== false, 'Draft is marked unsaved and error explains the rejected duplicate');
	sr_equal($data['revision'], $controller->request['revision'], 'Draft retains original revision');
	sr_equal($GLOBALS['sr_config'], $before, 'Rejected save preserves stored settings');
	sr_assert(!in_array('Module.update', array_column(API::$calls,0),true), 'Rejected bulk save cannot perform a partial write');
};
$tests['redisplaying a stale draft cannot authorize overwriting newer settings'] = static function(): void {
	API::$handlers['Host'] = static fn(array $options): array => [['hostid'=>'1','name'=>'Website']];
	API::$handlers['HostGroup'] = static fn(array $options): array => [];
	$original = ReportSettings::snapshot()['revision'];
	$GLOBALS['sr_config'] = json_encode(['availability'=>['hosts'=>[['hostid'=>'1','label'=>'Newer saved rule']]]]);
	$controller = new SettingsSave();
	$controller->request = ['settings'=>['hosts'=>[['hostid'=>'1','label'=>'Old draft']]], 'revision'=>$original, 'form_complete'=>'1'];
	$before = $GLOBALS['sr_config'];
	sr_invoke($controller, 'doAction');
	$data = $controller->response->data;
	sr_equal($data['settings']['hosts'][0]['label'], 'Old draft', 'Stale draft remains available to review');
	sr_equal($data['revision'], $original, 'Current database revision must not be granted to a stale draft');
	$controller->request['settings'] = $data['settings'];
	$controller->request['revision'] = $data['revision'];
	sr_invoke($controller, 'doAction');
	sr_equal($GLOBALS['sr_config'], $before, 'Retry without reloading cannot overwrite a newer saved rule');
	sr_assert(!in_array('Module.update',array_column(API::$calls,0),true), 'Neither stale attempt writes configuration');
};
$tests['maintenance requires role grants and host write access'] = static function(): void {
	$controller = sr_create_action();
	API::$handlers['Host'] = static fn(array $options): array => [['hostid'=>'1']];
	CWebUser::$type = USER_TYPE_ZABBIX_USER;
	sr_equal(sr_invoke($controller, 'checkPermissions'), false, 'Ordinary user denied');
	CWebUser::$type = USER_TYPE_ZABBIX_ADMIN;
	$controller->access = [CRoleHelper::UI_CONFIGURATION_MAINTENANCE];
	sr_equal(sr_invoke($controller, 'checkPermissions'), false, 'Missing edit-maintenance action grant denied');
	$controller->access[] = CRoleHelper::ACTIONS_EDIT_MAINTENANCE;
	API::$handlers['Host'] = static fn(array $options): array => [];
	sr_equal(sr_invoke($controller, 'checkPermissions'), false, 'Read-only or inaccessible host denied');
	API::$handlers['Host'] = static function(array $options): array {
		sr_assert($options['editable'] === true, 'Host check must ask API for writable hosts');
		return [['hostid'=>'1']];
	};
	sr_equal(sr_invoke($controller, 'checkPermissions'), true, 'Authorized Admin and editable host allowed');
};
$tests['tampered interval is rejected before API create'] = static function(): void {
	sr_creation_fixture();
	$controller = sr_create_action();
	$controller->request['gap_start'] += 60;
	sr_invoke($controller, 'doAction');
	sr_assert(CMessageHelper::$error !== '', 'Changed gap must produce an error');
	sr_assert(!in_array('Maintenance.create', array_column(API::$calls, 0), true), 'No maintenance write for tampered gap');
};
$tests['confirmed gap creates exactly one host-only contained maintenance'] = static function(): void {
	sr_creation_fixture();
	$controller = sr_create_action();
	sr_invoke($controller, 'doAction');
	sr_assert(CMessageHelper::$success !== '', 'Valid gap must confirm creation');
	$writes = array_values(array_filter(API::$calls, static fn(array $call): bool => $call[0] === 'Maintenance.create'));
	sr_equal(count($writes), 1, 'One selected gap produces one API write');
	$payload = $writes[0][1];
	sr_equal($payload['hosts'], [['hostid'=>'1']], 'Maintenance cannot include additional hosts');
	sr_equal($payload['active_since'], 1700000100, 'Starts within selected missing interval');
	sr_equal($payload['active_till'], 1700000940, 'Ends within selected missing interval');
	sr_equal($payload['maintenance_type'], 0, 'Retrospective creation preserves collection');
	sr_assert(strpos($payload['description'], 'CHG-123 planned work') !== false, 'Reason is retained for audit');
};
$tests['retry of an existing gap maintenance does not create a duplicate'] = static function(): void {
	sr_creation_fixture();
	API::$handlers['Maintenance'] = static fn(array $options): array => isset($options['filter']['name'])
		? [['maintenanceid'=>'50']] : [];
	$controller = sr_create_action();
	sr_invoke($controller, 'doAction');
	sr_assert(CMessageHelper::$success !== '', 'Retry should acknowledge existing maintenance');
	sr_assert(!in_array('Maintenance.create', array_column(API::$calls, 0), true), 'Retry must not write another maintenance');
};
$tests['excluded hosts cannot receive gap maintenance from report'] = static function(): void {
	sr_creation_fixture();
	$GLOBALS['sr_config'] = json_encode(['availability'=>['excluded_hostids'=>['1']]]);
	$controller = sr_create_action();
	sr_invoke($controller, 'doAction');
	sr_assert(CMessageHelper::$error !== '', 'Excluded host is ineligible');
	sr_assert(!in_array('Maintenance.create', array_column(API::$calls, 0), true), 'Excluded host must not be changed');
};
$tests['a failed history read cannot authorize maintenance'] = static function(): void {
	sr_creation_fixture();
	API::$handlers['History'] = static fn(array $options) => false;
	$controller = sr_create_action();
	sr_invoke($controller, 'doAction');
	sr_assert(CMessageHelper::$error !== '', 'Incomplete evidence is ineligible');
	sr_assert(!in_array('Maintenance.create', array_column(API::$calls, 0), true), 'No maintenance after evidence read failure');
};
$tests['ambiguous host without selected measurement cannot create maintenance'] = static function(): void {
	sr_multi_creation_fixture();
	$controller=sr_create_action();
	sr_invoke($controller,'doAction');
	sr_assert(CMessageHelper::$error!=='','Host with several checks needs an explicit selection');
	sr_assert(!in_array('History',array_column(API::$calls,0),true),'Ambiguous selection must not choose a history stream');
	sr_assert(!in_array('Maintenance.create',array_column(API::$calls,0),true),'Ambiguous selection must not write maintenance');
};
$tests['selected check authorizes only its own missing interval'] = static function(): void {
	$ids=sr_multi_creation_fixture();
	$controller=sr_create_action(); $controller->request['measurementid']=$ids[1];
	sr_invoke($controller,'doAction');
	sr_assert(CMessageHelper::$error!=='','Healthy sibling has no eligible gap');
	sr_assert(!in_array('Maintenance.create',array_column(API::$calls,0),true),'Another check gap cannot authorize selected healthy check');
};
$tests['selected gap creates host-wide maintenance with check identity in audit reason'] = static function(): void {
	$ids=sr_multi_creation_fixture();
	$controller=sr_create_action(); $controller->request['measurementid']=$ids[0];
	sr_invoke($controller,'doAction');
	sr_assert(CMessageHelper::$success!=='','Selected check missing gap can create maintenance');
	$writes=array_values(array_filter(API::$calls,static fn(array $call): bool=>$call[0]==='Maintenance.create'));
	sr_equal(count($writes),1,'Only one host-wide maintenance is created');
	sr_equal($writes[0][1]['hosts'],[['hostid'=>'1']],'Selected check cannot change a different host');
	sr_assert(strpos($writes[0][1]['description'],'Homepage')!==false,'Audit reason identifies selected check');
	sr_assert(strpos($writes[0][1]['description'],'every report measurement')!==false,'Audit reason records host-wide effect');
};
$tests['cross-host or stale measurement cannot bypass validation through retry'] = static function(): void {
	$ids=sr_multi_creation_fixture();
	API::$handlers['Maintenance']=static fn(array $options): array=>isset($options['filter']['name'])?[['maintenanceid'=>'50']]:[];
	$controller=sr_create_action(); $controller->request['measurementid']=$ids[2];
	sr_invoke($controller,'doAction');
	sr_assert(CMessageHelper::$error!=='','Measurement from another host is rejected');
	sr_assert(CMessageHelper::$success==='','Existing name cannot turn invalid measurement into success');
	sr_assert(!in_array('History',array_column(API::$calls,0),true),'Foreign measurement must not read its history');
	sr_assert(!in_array('Maintenance.create',array_column(API::$calls,0),true),'Foreign measurement cannot write maintenance');
};

$failed = 0;
foreach ($tests as $name => $test) {
	API::reset(); CWebUser::$type = USER_TYPE_SUPER_ADMIN; $GLOBALS['sr_config'] = '{}';
	CMessageHelper::$success = ''; CMessageHelper::$error = '';
	try { $test(); echo 'PASS '.$name.PHP_EOL; }
	catch (Throwable $error) { $failed++; fwrite(STDERR, 'FAIL '.$name.': '.$error->getMessage().PHP_EOL); }
}
echo count($tests).' security scenarios, '.$failed.' failed'.PHP_EOL;
exit($failed === 0 ? 0 : 1);
