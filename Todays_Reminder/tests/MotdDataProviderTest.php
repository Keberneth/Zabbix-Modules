<?php
/** Focused API-contract regression tests; no Zabbix database or network required. */
if (!function_exists('_')) { function _($text) { return $text; } }
ini_set('error_log', '/dev/null');
date_default_timezone_set('Europe/Stockholm');
putenv('MOTD_MAINTENANCE_TIMEZONE=Europe/Stockholm');

class CWebUser { public static $data = ['userid' => '1', 'type' => 3, 'lang' => 'en_US', 'roleid' => '3', 'ugsetid' => '1']; }
class CSettingsHelper {
	const DEFAULT_TIMEZONE = 'default_timezone';
	const AUDITLOG_ENABLED = 'auditlog_enabled';
	const AUDITLOG_MODE = 'auditlog_mode';
	public static $settings = ['default_timezone' => 'Europe/Stockholm', 'auditlog_enabled' => 1, 'auditlog_mode' => 1];
	public static function get($name) { return self::$settings[$name]; }
}
class CUrl {
	private $path;
	private $args = [];
	public function __construct($path) { $this->path = $path; }
	public function setArgument($key, $value) { $this->args[$key] = $value; return $this; }
	public function getUrl() { return $this->path.'?'.http_build_query($this->args); }
}
class API {
	public static $handler;
	public static $calls = [];
	public static function __callStatic($name, $args) { return new StubService($name); }
}
class StubService {
	private $name;
	public function __construct($name) { $this->name = $name; }
	public function get($args) {
		API::$calls[] = [$this->name, $args];
		return call_user_func(API::$handler, $this->name, $args);
	}
}
require_once __DIR__.'/../helpers/MotdDataProvider.php';
use Modules\MessageOfTheDay\Helpers\MotdDataProvider;

$checks = 0;
function check($condition, $message) {
	global $checks;
	$checks++;
	if (!$condition) throw new RuntimeException($message);
}
function privateCall($provider, $method, ...$args) {
	$reflection = new ReflectionMethod($provider, $method);
	$reflection->setAccessible(true);
	return $reflection->invoke($provider, ...$args);
}
function privateSet($provider, $property, $value) {
	$reflection = new ReflectionProperty($provider, $property);
	$reflection->setAccessible(true);
	$reflection->setValue($provider, $value);
}
function fresh(array $filters = []) {
	$provider = new MotdDataProvider();
	privateSet($provider, 'filters', MotdDataProvider::normalizeFilters($filters));
	return privateCall($provider, 'buildData');
}
function fixture(array $overrides = []) {
	$state = array_merge(['problems' => [], 'events' => [], 'recoveries' => [], 'audit' => [], 'maintenance' => [],
		'hosts' => [['hostid' => '10', 'name' => 'Alpha', 'host' => 'alpha', 'status' => 0,
			'active_available' => 1, 'hostgroups' => [['groupid' => '7', 'name' => 'Operations']], 'interfaces' => [['available' => 1]], 'tags' => ['team' => 'ops']]],
		'unsupported' => [], 'queue_items' => [], 'history' => [], 'proxies' => [], 'fail' => []], $overrides);
	API::$calls = [];
	API::$handler = static function($service, $args) use ($state) {
		if (in_array($service, $state['fail'], true)) throw new RuntimeException('fixture service denied');
		$rows = [];
		if ($service === 'HostGroup') return [['groupid' => '7', 'name' => 'Operations']];
		if ($service === 'Host') {
			$rows = array_values(array_filter($state['hosts'], static function($host) use ($args) {
				if (isset($args['hostids']) && !in_array($host['hostid'], $args['hostids'])) return false;
				if (isset($args['groupids']) && !array_intersect(array_column($host['hostgroups'], 'groupid'), $args['groupids'])) return false;
				if (isset($args['filter']['status']) && $host['status'] != $args['filter']['status']) return false;
				foreach ($args['tags'] ?? [] as $tag) {
					if (!array_key_exists($tag['tag'], $host['tags'])) return false;
					if ($tag['operator'] === 1 && $host['tags'][$tag['tag']] !== $tag['value']) return false;
				}
				return true;
			}));
		}
		elseif ($service === 'Problem') {
			$rows = array_values(array_filter($state['problems'], static function($row) use ($args) {
				return (!isset($args['hostids']) || in_array($row['hostid'], $args['hostids']))
					&& in_array($row['severity'], $args['severities'])
					&& (bool) $row['suppressed'] === (bool) $args['suppressed']
					&& (!isset($args['acknowledged']) || (bool) $row['acknowledged'] === $args['acknowledged']);
			}));
		}
		elseif ($service === 'Event') {
			if (isset($args['eventids'])) {
				$rows = array_values(array_filter($state['recoveries'], static function($r) use ($args) { return in_array($r['eventid'], $args['eventids']); }));
			}
			else {
				check(isset($args['problem_time_from'], $args['problem_time_till']), 'Resolved report must request overlapping problem lifetimes.');
				$clocks = array_column($state['recoveries'], 'clock', 'eventid');
				$rows = array_values(array_filter($state['events'], static function($row) use ($args, $clocks) {
					return (!isset($args['hostids']) || in_array($row['hostid'], $args['hostids']))
						&& $row['clock'] <= $args['problem_time_till']
						&& (!$row['r_eventid'] || !isset($clocks[$row['r_eventid']]) || $clocks[$row['r_eventid']] >= $args['problem_time_from'])
						&& (!isset($args['eventid_till']) || $row['eventid'] <= $args['eventid_till']);
				}));
			}
		}
		elseif ($service === 'AuditLog') {
			$rows = array_values(array_filter($state['audit'], static function($r) use ($args) {
				return $r['clock'] >= $args['time_from'] && $r['clock'] <= $args['time_till']
					&& (!isset($args['filter']['resourceid']) || in_array($r['resourceid'], $args['filter']['resourceid']));
			}));
		}
		elseif ($service === 'Item') {
			$rows = isset($args['filter']['key_']) ? $state['queue_items'] : array_values(array_filter($state['unsupported'], static function($item) use ($args) {
				return !isset($args['hostids']) || in_array($item['hostid'], $args['hostids']);
			}));
		}
		elseif ($service === 'History') {
			$rows = array_values(array_filter($state['history'], static function($r) use ($args) {
				return $r['clock'] >= $args['time_from'] && $r['clock'] <= $args['time_till'] && in_array($r['itemid'], $args['itemids']);
			}));
		}
		elseif ($service === 'Proxy') $rows = $state['proxies'];
		elseif ($service === 'Maintenance') $rows = $state['maintenance'];
		elseif ($service === 'Trigger') {
			$result = [];
			foreach ($args['triggerids'] as $id) $result[$id] = ['triggerid' => $id, 'hosts' => [['hostid' => '10', 'host' => 'alpha', 'name' => 'Alpha']]];
			return $result;
		}
		if (!empty($args['countOutput'])) return (string) count($rows);
		$sort = $args['sortfield'] ?? '';
		$sort = is_array($sort) ? ($sort[0] ?? '') : $sort;
		if ($sort && $rows) {
			usort($rows, static function($a, $b) use ($sort, $args) {
				$comparison = ($a[$sort] ?? '') <=> ($b[$sort] ?? '');
				return ($args['sortorder'] ?? '') === 'DESC' ? -$comparison : $comparison;
			});
		}
		return isset($args['limit']) ? array_slice($rows, 0, $args['limit']) : $rows;
	};
}

fixture(['fail' => ['HostGroup', 'Problem', 'Event', 'Item', 'Host', 'Proxy', 'AuditLog', 'Maintenance']]);
$failed = fresh();
check($failed['status'] === 'unavailable', 'Total failure must be unavailable.');
check($failed['problems']['high_count'] === null && $failed['health']['unreachable_hosts'] === null, 'Failed counts must be null.');
check(strpos(json_encode($failed), 'All clear') === false, 'Failure must never claim all clear.');
check($failed['resolved']['count'] === null && $failed['new_hosts']['count'] === null, 'Failed reports must not become zero.');
fixture(['fail' => ['Problem', 'Event', 'Item', 'Host', 'Proxy', 'AuditLog', 'Maintenance']]);
check(fresh()['status'] === 'unavailable', 'Loading filter choices alone must not count as operational data.');
API::$handler = static function($service, $args) { return []; };
$malformed = fresh();
check($malformed['problems']['high_count'] === null, 'Malformed array count response must not cast to healthy zero.');
API::$handler = static function($service, $args) { return !empty($args['countOutput']) ? '0' : 0; };
check(fresh()['longest']['status'] === 'unavailable', 'Scalar row response must not be accepted.');

$now = time();
$problem = ['eventid' => '1', 'objectid' => '20', 'hostid' => '10', 'clock' => $now - 3600, 'name' => 'Old incident', 'severity' => 4, 'suppressed' => false, 'acknowledged' => 0];
fixture(['problems' => [$problem]]);
$first = fresh();
$problem['eventid'] = '2'; $problem['name'] = 'Replacement incident';
fixture(['problems' => [$problem]]);
$second = fresh();
check($first['problems']['high_count'] === $second['problems']['high_count'], 'Replacement fixture must preserve counts.');
check($first['fingerprint'] !== $second['fingerprint'], 'Changed displayed incidents must change fingerprint.');
check(strpos($second['problems']['recent'][0]['url'], 'eventid=2') !== false, 'Incident links target the exact event.');

$events = [];
for ($i = 1; $i <= 1201; $i++) $events[] = ['eventid' => (string) $i, 'objectid' => '20', 'hostid' => '10',
	'clock' => $now - 200000 + $i, 'name' => 'Incident '.$i, 'severity' => 4, 'r_eventid' => $i === 1 ? '5000' : '0'];
fixture(['events' => $events, 'recoveries' => [['eventid' => '5000', 'clock' => $now - 120]]]);
$report = fresh()['resolved'];
check($report['count'] === 1 && $report['recent'][0]['eventid'] === '1', 'Old start recovered today survives more than 200 newer starts.');
check($report['avg_mttr'] === 199879 && !$report['truncated'], 'Complete recovery MTTR includes old lifetime.');
check(count(array_filter(API::$calls, static function($call) { return $call[0] === 'Event' && isset($call[1]['eventid_till']); })) === 1, 'Recovery candidates paginate.');
$bounded_events = [];
for ($i = 1; $i <= 20001; $i++) $bounded_events[] = ['eventid' => (string) $i, 'objectid' => '20', 'hostid' => '10',
	'clock' => $now - 200000 + $i, 'name' => 'Incident '.$i, 'severity' => 4, 'r_eventid' => $i === 20001 ? '50000' : '0'];
fixture(['events' => $bounded_events, 'recoveries' => [['eventid' => '50000', 'clock' => $now - 120]]]);
$bounded = fresh()['resolved'];
check($bounded['count'] === 1 && $bounded['count_is_lower_bound'] && $bounded['truncated'], 'Scan limits disclose a lower-bound recovery count.');
check($bounded['candidates_scanned'] === 20000 && $bounded['avg_mttr'] === null, 'Incomplete recovery coverage cannot publish average MTTR.');
unset($bounded_events);

$audit = [];
for ($i = 0; $i < 35; $i++) $audit[] = ['auditid' => 'a'.$i, 'clock' => $now - $i, 'resourceid' => '10', 'resourcename' => 'Host '.$i];
fixture(['audit' => $audit]);
$new_hosts = fresh()['new_hosts'];
check($new_hosts['count'] === 35 && count($new_hosts['recent']) === 5, 'Audit count is complete while examples stay bounded.');
CSettingsHelper::$settings['auditlog_enabled'] = 0;
$audit_off = fresh()['new_hosts'];
check($audit_off['count'] === null && $audit_off['status'] === 'unavailable', 'Disabled audit logging is unknown coverage, not zero host additions.');
CSettingsHelper::$settings['auditlog_enabled'] = 1;
CSettingsHelper::$settings['auditlog_mode'] = 0;
$audit_partial = fresh()['new_hosts'];
check($audit_partial['count'] === 35 && $audit_partial['status'] === 'partial' && $audit_partial['count_is_lower_bound'], 'System audit disabled marks host additions as incomplete.');
CSettingsHelper::$settings['auditlog_mode'] = 1;
CWebUser::$data['type'] = 1;
$limited = fresh();
check($limited['new_hosts']['count'] === null && $limited['new_hosts']['status'] === 'unavailable', 'Restricted audit access is unavailable, not zero.');
check($limited['maintenances']['status'] === 'ok', 'Ordinary users may read permitted maintenances.');
CWebUser::$data['type'] = 3;

fixture(['problems' => [$problem], 'audit' => $audit]);
$scoped = fresh(['groupid' => '7', 'tag' => 'team', 'tag_value' => 'ops']);
check($scoped['problems']['high_count'] === 1 && $scoped['new_hosts']['count'] === 35, 'Matching host scope preserves data.');
foreach (API::$calls as $call) {
	if (in_array($call[0], ['Problem', 'Event', 'Trigger'], true)) check(($call[1]['hostids'] ?? null) === ['10'], 'Scoped operational queries must carry permitted host IDs.');
	if ($call[0] === 'AuditLog') check(($call[1]['filter']['resourceid'] ?? null) === ['10'], 'Scoped audit uses resourceid, not unsupported hostids.');
}
fixture(['problems' => [$problem]]);
$empty = fresh(['groupid' => '999']);
check($empty['problems']['high_count'] === 0 && $empty['maintenances']['today_count'] === 0, 'Empty scope is a known empty result.');
check(!array_filter(API::$calls, static function($call) { return in_array($call[0], ['Problem', 'Event', 'AuditLog', 'Maintenance'], true); }), 'Empty selected scope never calls unfiltered APIs.');
fixture(['fail' => ['Host']]);
$bad_scope = fresh(['groupid' => '7']);
check($bad_scope['problems']['high_count'] === null && $bad_scope['maintenances']['today_count'] === null, 'Scope failure must not widen or become zero.');
$rejected = false;
try { (new MotdDataProvider())->getData('invalid-filter', ['groupid' => 'not-an-id']); }
catch (InvalidArgumentException $e) { $rejected = true; }
check($rejected, 'Invalid public provider input is rejected instead of broadening to all hosts.');

$window = ['maintenanceid' => '1', 'name' => 'Parent scope', 'description' => '', 'maintenance_type' => 0,
	'active_since' => $now - 86400, 'active_till' => $now + 86400, 'hosts' => [],
	'hostgroups' => [['groupid' => '6', 'name' => 'Operations']],
	'timeperiods' => [['timeperiod_type' => 0, 'start_date' => $now - 3600, 'period' => 7200]]];
fixture(['maintenance' => [$window], 'hosts' => [['hostid' => '10', 'name' => 'Nested', 'host' => 'nested', 'status' => 0,
	'active_available' => 1, 'hostgroups' => [['groupid' => '7', 'name' => 'Operations/Production']], 'interfaces' => [], 'tags' => []]],
	'fail' => ['HostGroup']]);
$nested = fresh(['groupid' => '7']);
check($nested['maintenances']['today_count'] === 1, 'Parent-group maintenance covers scoped child-group hosts even when filter choices fail.');
$window['hostgroups'][0]['name'] = 'Operation';
fixture(['maintenance' => [$window]]);
check(fresh(['groupid' => '7'])['maintenances']['today_count'] === 0, 'Maintenance group ancestry uses a slash boundary.');

fixture(['hosts' => [
	['hostid' => '10', 'name' => 'Active unavailable', 'host' => 'a', 'status' => 0, 'active_available' => 2, 'interfaces' => [], 'hostgroups' => [], 'tags' => []],
	['hostid' => '11', 'name' => 'Passive unavailable', 'host' => 'b', 'status' => 0, 'active_available' => 1, 'interfaces' => [['available' => 2]], 'hostgroups' => [], 'tags' => []],
	['hostid' => '12', 'name' => 'Disabled', 'host' => 'c', 'status' => 1, 'active_available' => 2, 'interfaces' => [['available' => 2]], 'hostgroups' => [], 'tags' => []]
], 'queue_items' => [['itemid' => '40', 'hosts' => [['monitored_by' => 1]]], ['itemid' => '41', 'hosts' => [['monitored_by' => 0]]]],
	'history' => [['itemid' => '41', 'clock' => $now - 10000, 'value' => 0]]]);
$health = fresh()['health'];
check($health['unreachable_hosts'] === 2 && count($health['unreachable_details']) === 2, 'Count active and passive unavailable hosts, excluding disabled hosts.');
check($health['queue_backlog'] === null, 'Old queue history must not look current.');
check(strpos($health['stale_url'], 'state=1') !== false && strpos($health['stale_url'], 'filter_state') === false, 'Unsupported items use supported native filter.');
check(strpos($health['unreachable_hosts_url'], '#motd-monitoring-health') !== false, 'Unreachable count links to actual unavailable-host details.');
check(!empty($health['field_messages']['queue_backlog']), 'Missing queue coverage explains its reason.');
check($health['status'] === 'partial', 'Unknown queue coverage marks health partial.');

putenv('MOTD_MAINTENANCE_TIMEZONE=Not/A_Timezone');
fixture();
$invalid_zone = fresh();
check($invalid_zone['maintenances']['today_count'] === null && $invalid_zone['maintenances']['status'] === 'unavailable', 'Invalid configured recurrence timezone must not invent schedules.');
putenv('MOTD_MAINTENANCE_TIMEZONE=Europe/Stockholm');

$provider = new MotdDataProvider();
check(privateCall($provider, 'previousId', '18446744073709551615') === '18446744073709551614', 'Pagination handles unsigned 64-bit IDs.');
check(privateCall($provider, 'previousId', '1000') === '999', 'Pagination borrows decimal digits.');
$scope = 'test-'.bin2hex(random_bytes(6));
$path = privateCall($provider, 'cacheFile', $scope);
check($path !== privateCall($provider, 'cacheFile', $scope.'-other'), 'Cache separates users.');
date_default_timezone_set('UTC');
check($path !== privateCall($provider, 'cacheFile', $scope), 'Cache separates display timezones.');
date_default_timezone_set('Europe/Stockholm');
CWebUser::$data['lang'] = 'sv_SE';
check($path !== privateCall($provider, 'cacheFile', $scope), 'Cache separates languages.');
CWebUser::$data['lang'] = 'en_US';
privateSet($provider, 'filters', MotdDataProvider::normalizeFilters(['groupid' => '7']));
check($path !== privateCall($provider, 'cacheFile', $scope), 'Cache separates filters.');
fixture();
$cached_provider = new MotdDataProvider();
$cached_provider->getData($scope);
$cached_path = privateCall($cached_provider, 'cacheFile', $scope);
check(is_file($cached_path), 'Private cache can be written.');
check((fileperms($cached_path) & 0777) === 0600 && (fileperms(dirname($cached_path)) & 0777) === 0700, 'Cache files and directory are private.');
chmod($cached_path, 0644);
clearstatcache();
check(privateCall($cached_provider, 'readCache', $scope) === null, 'Insecure cache permissions are rejected before reads.');
unlink($cached_path);

// Refresh cost: the recovery report scans event history, so only the overview builds it, it runs last,
// it makes no separate count query, and a successful report is reused for five minutes.
$recovered = ['events' => $events, 'recoveries' => [['eventid' => '5000', 'clock' => $now - 120]]];
$event_calls = static function(): array {
	return array_values(array_filter(API::$calls, static function($call) { return $call[0] === 'Event'; }));
};
fixture($recovered + ['problems' => [$problem]]);
$banner_provider = new MotdDataProvider();
privateSet($banner_provider, 'filters', MotdDataProvider::normalizeFilters([]));
privateSet($banner_provider, 'with_recovery', false);
$banner = privateCall($banner_provider, 'buildData');
check(!$event_calls(), 'Banner data never scans event history.');
check($banner['resolved']['status'] === 'skipped' && $banner['resolved']['count'] === null
	&& !array_filter($banner['issues'], static function($issue) { return $issue['section'] === 'resolved'; }),
	'The banner skips the recovery report without reporting it as missing data.');
$full = fresh();
check($banner['summary_line'] === $full['summary_line'] && $banner['chips'] === $full['chips']
	&& $banner['banner_items'] === $full['banner_items'], 'Skipping the recovery report changes nothing the banner shows.');
check(!array_filter($event_calls(), static function($call) { return !empty($call[1]['countOutput']); }),
	'The recovery report does not scan event history a second time just to count.');

fixture($recovered + ['problems' => [$problem], 'maintenance' => [$window]]);
$slow = new MotdDataProvider();
privateSet($slow, 'filters', MotdDataProvider::normalizeFilters([]));
$inner = API::$handler;
API::$handler = static function($service, $args) use ($inner, $slow) {
	if ($service === 'Event') privateSet($slow, 'deadline', microtime(true) - 1); // the scan uses up the budget
	return $inner($service, $args);
};
$budget = privateCall($slow, 'buildData');
check($budget['problems']['high_count'] === 1 && $budget['maintenances']['today_count'] === 1,
	'A slow recovery scan cannot cost the banner its counts or maintenance windows.');
check(in_array('Alpha', $budget['problems']['recent'][0]['hosts'], true), 'Problem rows keep their hosts when the scan uses up the budget.');
check($budget['resolved']['status'] !== 'ok' && $budget['resolved']['truncated'], 'A recovery scan cut short is disclosed.');

$reuse_scope = 'recovery-'.bin2hex(random_bytes(6));
fixture($recovered);
(new MotdDataProvider())->getData($reuse_scope);
$reuse = new MotdDataProvider();
unlink(privateCall($reuse, 'cacheFile', $reuse_scope)); // the 45-second page cache has expired
fixture($recovered);
$reused = $reuse->getData($reuse_scope);
check(!$event_calls() && API::$calls, 'Overview refreshes reuse a recent recovery report and refresh everything else.');
check($reused['resolved']['count'] === 1 && $reused['resolved']['recent'][0]['eventid'] === '1'
	&& $reused['resolved']['avg_mttr'] === 199879, 'A reused recovery report keeps its incidents and MTTR.');
fixture($recovered);
$banner_data = (new MotdDataProvider())->getData($reuse_scope, [], false);
check(!$event_calls() && $banner_data['resolved']['status'] === 'skipped', 'Banner requests use their own cache entry and skip the scan.');

$retry_scope = 'recovery-retry-'.bin2hex(random_bytes(6));
fixture(['fail' => ['Event']]);
(new MotdDataProvider())->getData($retry_scope);
$retry = new MotdDataProvider();
unlink(privateCall($retry, 'cacheFile', $retry_scope));
fixture($recovered);
check($retry->getData($retry_scope)['resolved']['count'] === 1 && $event_calls(), 'A failed recovery scan is retried, never cached.');
foreach ([$reuse_scope, $retry_scope] as $test_scope) {
	foreach (['', '|recovery', '|banner'] as $suffix) @unlink(privateCall($retry, 'cacheFile', $test_scope.$suffix));
}

echo 'MotdDataProviderTest: '.$checks." checks passed.\n";
