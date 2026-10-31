<?php declare(strict_types = 1);

require_once __DIR__.'/../helpers/ReportSettings.php';
use Modules\SlaUptimeReport\Helpers\ReportSettings as Settings;
if (!function_exists('_')) { function _($text) { return $text; } }

define('USER_TYPE_SUPER_ADMIN', 3);
define('USER_TYPE_ZABBIX_ADMIN', 2);
class CController { protected function getUserType(): int { return CWebUser::$type; } }
final class CWebUser { public static int $type = 3; public static function getType(): int { return self::$type; } }
$GLOBALS['settings_record'] = ['moduleid' => '1', 'config' => '{}'];
function DBselect($sql, $limit) { return true; }
function DBfetch($result) { return $GLOBALS['settings_record']; }
function zbx_dbstr($value) { return "'".$value."'"; }
final class SettingsApi {
	public array $items = [];
	public array $calls = [];
	public ?array $visible_hostids = null;
	public ?array $visible_groupids = null;
	public function get(array $params): array {
		$this->calls[] = $params;
		if (isset($params['webitems'])) { return $this->items; }
		$field = in_array('hostid', $params['output'], true) ? 'hostid' : 'groupid';
		$visible = $field === 'hostid' ? $this->visible_hostids : $this->visible_groupids;
		$ids = $params[$field.'s'] ?? $visible ?? [];
		if ($visible !== null) { $ids = array_values(array_intersect($ids, $visible)); }
		return array_map(static fn($id) => [$field => $id, 'name' => 'Object '.$id, 'host' => 'Object '.$id], $ids);
	}
	public function update(array $rows): array {
		$GLOBALS['settings_record']['config'] = json_encode($rows[0]['config'], JSON_THROW_ON_ERROR);
		return ['moduleids' => ['1']];
	}
}
final class API {
	public static SettingsApi $api;
	public static function Item(): SettingsApi { return self::$api; }
	public static function Module(): SettingsApi { return self::$api; }
	public static function Host(): SettingsApi { return self::$api; }
	public static function HostGroup(): SettingsApi { return self::$api; }
}
API::$api = new SettingsApi();
$checks = 0;
function expect(bool $passed, string $message): void {
	global $checks;
	if (!$passed) { throw new RuntimeException($message); }
	$checks++;
}
function rejects(callable $callback, string $message): void {
	try { $callback(); } catch (InvalidArgumentException | RuntimeException $e) { expect(true, $message); return; }
	throw new RuntimeException($message);
}

expect(Settings::get() === Settings::defaults(), 'Empty registered config uses defaults.');
$rule = ['key' => 'web.test.rspcode[Site,Home]', 'mode' => 'range', 'min' => 200, 'max' => 399];
$config = Settings::normalize([
	'global' => ['mode' => 'binary', 'key' => 'global.ping'],
	'groups' => [
		['groupid' => '10', 'priority' => 10] + $rule,
		['groupid' => '2', 'priority' => 10, 'key' => 'second.ping', 'mode' => 'binary'],
		['groupid' => '20', 'priority' => 1, 'key' => 'winner.ping', 'mode' => 'binary']
	],
	'hosts' => [['hostid' => '1', 'key' => 'host.ping', 'mode' => 'equals', 'value' => 0]],
	'excluded_hostids' => ['99'], 'excluded_groupids' => ['100']
]);
expect(Settings::resolveRule($config, '1', ['2', '20'])['key'] === 'host.ping', 'Host overrides groups.');
expect(Settings::resolveRule($config, '3', ['10', '2'])['key'] === 'second.ping', 'Equal priority uses numeric group ID.');
expect(Settings::resolveRule($config, '3', ['10', '2', '20'])['key'] === 'winner.ping', 'Lowest group priority wins.');
expect(Settings::resolveRule($config, '3', [])['key'] === 'global.ping', 'Unmatched host uses global.');
expect(Settings::isExcluded($config, '99', []), 'Host exclusion.');
expect(Settings::isExcluded($config, '3', ['1', '100']), 'Membership in one excluded group excludes host.');
expect(!Settings::isExcluded($config, '3', ['1']), 'Unexcluded host remains.');
expect(Settings::normalize(['excluded_hostids' => ['1'], 'excluded_hostids_extra' => '2, 3; 1'])['excluded_hostids'] === ['1', '2', '3'], 'Additional exclusion IDs are merged and deduplicated.');
expect(Settings::sampleIsUp(200.0, $rule), 'HTTP range includes lower endpoint.');
expect(Settings::sampleIsUp(399.0, $rule), 'HTTP range includes upper endpoint.');
expect(!Settings::sampleIsUp(500.0, $rule), 'HTTP error is unhealthy.');
expect(Settings::sampleIsUp(0, $config['hosts'][0]), 'Custom zero is valid healthy value.');
expect(!Settings::sampleIsUp(2, Settings::defaults()['global']), 'Agent unavailable value 2 is unhealthy.');
expect(!Settings::sampleIsUp(INF, $rule), 'Infinite metric is unhealthy.');
rejects(static fn() => Settings::normalize(['global' => ['mode' => 'equals', 'key' => 'x', 'value' => 'bad']]), 'Reject invalid number.');
rejects(static fn() => Settings::normalize(['global' => ['mode' => 'range', 'key' => 'x', 'min' => 300, 'max' => 200]]), 'Reject inverted range.');
rejects(static fn() => Settings::normalize(['global' => ['mode' => 'binary', 'key' => '']]), 'Reject custom mode without key.');
rejects(static fn() => Settings::normalize(['global' => ['key' => ['bad']]]), 'Reject array key.');
rejects(static fn() => Settings::normalize(['groups' => [['groupid' => '1'], ['groupid' => '1']]]), 'Reject duplicate group.');
rejects(static fn() => Settings::normalize(['excluded_hostids' => ['0']]), 'Reject invalid ID.');
rejects(static fn() => Settings::normalize(['groups' => [['groupid' => '2', 'priority' => -1]]]), 'Reject negative priority.');
rejects(static fn() => Settings::normalize(['global' => ['interval_seconds' => 86401]]), 'Reject excessively large cadence.');
rejects(static fn() => Settings::normalize(['global' => ['interval_seconds' => '60.5']]), 'Reject fractional cadence.');
$GLOBALS['settings_record']['config'] = '{broken';
rejects(static fn() => Settings::get(), 'Invalid storage must not fall back to default policy.');
$GLOBALS['settings_record']['config'] = '{"availability":null}';
rejects(static fn() => Settings::get(), 'Null policy must not fall back to default policy.');
$GLOBALS['settings_record']['config'] = '{"availability":{"hosts":[{"hostid":""}]}}';
rejects(static fn() => Settings::get(), 'Corrupt stored host rule must not be discarded.');
$GLOBALS['settings_record']['config'] = '{"unrelated":{"keep":true}}';
$snapshot = Settings::snapshot();
Settings::save($config, $snapshot['revision']);
expect(Settings::get() === $config, 'Settings roundtrip.');
expect(json_decode($GLOBALS['settings_record']['config'], true)['unrelated']['keep'], 'Save retains unrelated module fields.');
rejects(static fn() => Settings::save($config, $snapshot['revision']), 'Reject stale settings revision.');
CWebUser::$type = 2;
rejects(static fn() => Settings::save($config, Settings::snapshot()['revision']), 'Admin cannot bypass Super Admin write policy.');
CWebUser::$type = 3;

API::$api->items = [
	['itemid' => '11', 'hostid' => '1', 'key_' => 'agent.ping', 'delay' => '60s', 'value_type' => '3', 'status' => '0'],
	['itemid' => '12', 'hostid' => '1', 'key_' => 'icmpping', 'delay' => '60s', 'value_type' => '3', 'status' => '0'],
	['itemid' => '21', 'hostid' => '2', 'key_' => 'agent.ping', 'delay' => '60s', 'value_type' => '3', 'status' => '0'],
	['itemid' => '22', 'hostid' => '2', 'key_' => 'web.test.rspcode[Site,Home]', 'delay' => '60s', 'value_type' => '3', 'status' => '0'],
	['itemid' => '31', 'hostid' => '3', 'key_' => 'agent.ping', 'delay' => '60s', 'value_type' => '3', 'status' => '1'],
	['itemid' => '32', 'hostid' => '3', 'key_' => 'icmpping', 'delay' => '60s', 'value_type' => '1', 'status' => '0']
];
$selection_config = Settings::normalize(['hosts' => [['hostid' => '2', 'interval_seconds' => 120] + $rule, ['hostid' => '4'] + $rule]]);
$items = Settings::selectItems([['hostid' => '1'], ['hostid' => '2'], ['hostid' => '3'], ['hostid' => '4']], $selection_config);
expect($items[1]['itemid'] === '11', 'Automatic selection prioritizes agent.ping.');
expect($items[2]['itemid'] === '22', 'Custom web item selected.');
expect($items[2]['delay'] === '120s' && $items[2]['item_delay'] === '60s', 'Explicit cadence overrides item delay without losing original.');
expect(!isset($items[3]), 'Disabled and nonnumeric items cannot be selected.');
expect(!isset($items[4]), 'Missing custom item never falls back.');
$query = end(API::$api->calls);
expect($query['webitems'] === true && $query['filter']['status'] === 0, 'Web scenario items included and enabled items requested.');

$web_rules = [];
API::$api->items = [];
for ($index = 0; $index < 10; $index++) {
	$key = 'web.test.rspcode[Website '.$index.',Page]';
	$web_rules[] = ['hostid' => '10914', 'key' => $key, 'label' => 'Website '.$index,
		'mode' => 'equals', 'value' => $index < 2 ? 302 : 200, 'interval_seconds' => 60];
	API::$api->items[] = ['itemid' => (string) (100 + $index), 'hostid' => '10914', 'key_' => $key,
		'delay' => '0', 'value_type' => '3', 'status' => '0'];
}
$web_settings = Settings::normalize(['hosts' => $web_rules]);
expect(count(Settings::resolveRules($web_settings, '10914', [])) === 10, 'One host can have ten independent website measurements.');
$calls_before = count(API::$api->calls);
$web_measurements = Settings::selectMeasurements([['hostid' => '10914', 'name' => 'Web server']], $web_settings);
expect(count($web_measurements) === 10 && count(API::$api->calls) === $calls_before + 1, 'Ten websites are resolved in one batched item query.');
expect(count(array_unique(array_keys($web_measurements))) === 10, 'Each website has a distinct stable measurement ID.');
$first_id = Settings::measurementId('10914', $web_settings['hosts'][0]);
expect(preg_match('/^[a-f0-9]{32}$/D', $first_id) === 1, 'Measurement identity uses a bounded hexadecimal token.');
expect($web_measurements[$first_id]['label'] === 'Website 0' && $web_measurements[$first_id]['hostid'] === '10914', 'Website label retains its real parent host.');
expect($web_measurements[$first_id]['item']['itemid'] === '100', 'Each measurement selects its own matching item.');
expect(Settings::sampleIsUp(302, $web_measurements[$first_id]['rule']), 'Expected HTTP 302 is healthy for its website.');
expect(!Settings::sampleIsUp(200, $web_measurements[$first_id]['rule']), 'Unexpected HTTP 200 does not silently pass an exact 302 rule.');
$renamed = $web_settings['hosts'][0];
$renamed['label'] = 'New label';
$renamed['mode'] = 'range';
$renamed['min'] = 200;
$renamed['max'] = 302;
expect(Settings::measurementId('10914', $renamed) === $first_id, 'Label and healthy-policy changes retain measurement identity.');
expect(Settings::measurementId('20000', $renamed) !== $first_id, 'Identical item keys on different hosts have distinct identities.');
$reversed = $web_settings;
$reversed['hosts'] = array_reverse($reversed['hosts']);
$reversed_measurements = Settings::selectMeasurements([['hostid' => '10914']], $reversed);
expect(isset($reversed_measurements[$first_id]), 'Reordering measurements preserves links.');
array_pop(API::$api->items);
$missing_measurements = Settings::selectMeasurements([['hostid' => '10914']], $web_settings);
$missing_id = Settings::measurementId('10914', $web_settings['hosts'][9]);
expect(count($missing_measurements) === 10 && $missing_measurements[$missing_id]['item'] === null, 'A missing website item retains its own visible measurement row.');
expect($missing_measurements[$missing_id]['label'] === 'Website 9', 'Missing item retains its configured label.');
rejects(static fn() => Settings::normalize(['hosts' => [$web_rules[0], $web_rules[0]]]), 'Reject duplicate item key on one host.');
rejects(static fn() => Settings::normalize(['hosts' => [['hostid' => '10914'], $web_rules[0]]]), 'Reject automatic plus explicit measurement.');
rejects(static fn() => Settings::normalize(['hosts' => [$web_rules[0], ['hostid' => '10914']]]), 'Reject explicit plus automatic measurement in reverse order.');
rejects(static fn() => Settings::normalize(['hosts' => [array_replace($web_rules[0], ['label' => "bad\nlabel"])]]), 'Reject control characters in display name.');
$blank_label = Settings::normalize(['hosts' => [array_replace($web_rules[0], ['label' => ''])]]);
expect(array_values(Settings::selectMeasurements([['hostid' => '10914']], $blank_label))[0]['label'] === $web_rules[0]['key'], 'Unnamed explicit measurement uses exact item key.');
$auto_measurements = Settings::selectMeasurements([['hostid' => '20000', 'name' => 'Visible host']], Settings::defaults());
expect(array_values($auto_measurements)[0]['label'] === 'Visible host', 'Automatic measurement retains visible host name.');
$max_rules = [];
for ($index = 1; $index <= Settings::MAX_RULES; $index++) { $max_rules[] = ['hostid' => (string) $index]; }
$max_rules[] = ['hostid' => ''];
expect(count(Settings::normalize(['hosts' => $max_rules])['hosts']) === 100, 'One blank form row does not prevent saving the maximum supported rules.');
expect(count(Settings::normalize(['hosts' => array_merge($max_rules, array_fill(0, 25, ['hostid' => '']))])['hosts']) === 100,
	'Multiple blank import and form rows do not count as measurements.');
rejects(static fn() => Settings::normalize(['hosts' => array_merge($max_rules, [['hostid' => '101']])]),
	'Reject 101 populated rules even when blank rows are present.');
rejects(static fn() => Settings::normalize(['hosts' => array_fill(0, 201, ['hostid' => ''])]),
	'Reject excessive raw form rows even when all are blank.');
$scope_settings = $web_settings;
$scope_settings['hosts'][0]['label'] = 'global';
$scope_settings['hosts'][0]['scope'] = 'global';
$scope_settings['hosts'][0]['scopeid'] = 'wrong';
$resolved_scope = Settings::resolveRules($scope_settings, '10914', [])[0];
expect($resolved_scope['scope'] === 'host' && $resolved_scope['scopeid'] === '10914' && $resolved_scope['label'] === 'global',
	'Measurement label or stale rule metadata cannot override its computed host scope.');

require_once __DIR__.'/../actions/SettingsView.php';
require_once __DIR__.'/../actions/SettingsSave.php';
API::$api->visible_hostids = ['1'];
API::$api->visible_groupids = ['2'];
CWebUser::$type = 2;
$view_data = \Modules\SlaUptimeReport\Actions\SettingsView::viewData();
expect(array_column($view_data['settings']['groups'], 'groupid') === ['2'], 'Admin sees only accessible group rules.');
expect($view_data['settings']['excluded_hostids'] === [] && $view_data['settings']['excluded_groupids'] === [], 'Admin cannot see inaccessible exclusions.');
expect(!$view_data['can_edit'] && $view_data['revision'] === '', 'Admin receives no editable configuration revision.');
$view_permission = new ReflectionMethod(\Modules\SlaUptimeReport\Actions\SettingsView::class, 'checkPermissions');
$save_permission = new ReflectionMethod(\Modules\SlaUptimeReport\Actions\SettingsSave::class, 'checkPermissions');
expect($view_permission->invoke(new \Modules\SlaUptimeReport\Actions\SettingsView()), 'Admin may read settings.');
expect(!$save_permission->invoke(new \Modules\SlaUptimeReport\Actions\SettingsSave()), 'Admin may not save global settings.');
CWebUser::$type = 1;
expect(!$view_permission->invoke(new \Modules\SlaUptimeReport\Actions\SettingsView()), 'Regular user cannot access settings route.');
CWebUser::$type = 3;
expect($save_permission->invoke(new \Modules\SlaUptimeReport\Actions\SettingsSave()), 'Super Admin may save shared settings.');
$draft = Settings::formValues(['global'=>['min'=>'500','max'=>'200','mode'=>'range','key'=>'invalid.range'],
	'hosts'=>[4=>['hostid'=>'10914','mode'=>'equals','key'=>'web.test.rspcode[Site,Home]','value'=>'200','label'=>'<img src=x>'],
		8=>['hostid'=>'10914','mode'=>'equals','key'=>'web.test.rspcode[Site,Home]','value'=>'302']],
	'excluded_hostids'=>['1'], 'excluded_hostids_extra'=>'2, 3']);
expect(count($draft['hosts']) === 2 && array_keys($draft['hosts']) === [0,1], 'Rejected form keeps duplicate rows with contiguous form indices.');
expect($draft['global']['min'] === '500' && $draft['global']['max'] === '200', 'Invalid range is retained for correction.');
expect($draft['hosts'][0]['label'] === '<img src=x>', 'Draft display values are retained for the view to HTML escape.');
expect($draft['excluded_hostids_extra'] === '2, 3' && $draft['excluded_hostids'] === ['1'], 'Draft keeps all exclusion inputs.');
rejects(static fn() => Settings::normalize($draft), 'Displayable draft is still subject to strict save validation.');
rejects(static fn() => Settings::formValues(['hosts'=>[['hostid'=>['invalid']]]]), 'Nested fields cannot reach the view.');
rejects(static fn() => Settings::formValues(['hosts'=>array_fill(0,201,[])]), 'Rejected-form redisplay is bounded.');
expect(Settings::normalize([])['cache']['enabled'] === true, 'Existing configurations enable measurement caching on upgrade.');
expect(Settings::normalize(['cache'=>['enabled'=>'0']])['cache']['enabled'] === false, 'Unchecked cache checkbox disables caching.');
expect(Settings::normalize(['cache'=>['enabled'=>'1']])['cache']['enabled'] === true, 'Checked cache checkbox enables caching.');
expect(Settings::formValues(['cache'=>['enabled'=>'0']])['cache']['enabled'] === '0', 'Rejected saves preserve the cache toggle.');
rejects(static fn() => Settings::normalize(['cache'=>['enabled'=>'false']]), 'Reject ambiguous cache settings instead of enabling by string coercion.');
rejects(static fn() => Settings::normalize(['cache'=>['enabled'=>[]]]), 'Reject nonscalar cache setting.');
echo "Report settings: $checks checks passed.\n";
