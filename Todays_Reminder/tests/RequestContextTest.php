<?php

if (!function_exists('_')) { function _($text) { return $text; } }
class CWebUser { public static $data = ['userid' => '7']; }
require_once __DIR__.'/../helpers/RequestContext.php';
use Modules\MessageOfTheDay\Helpers\RequestContext;

$checks = 0;
function check($condition, $message) {
	global $checks;
	$checks++;
	if (!$condition) { throw new RuntimeException($message); }
}
$defaults = ['groupid' => '0', 'tag' => '', 'tag_value' => ''];
check(RequestContext::filters([]) === $defaults, 'Empty filters are explicit and canonical.');
check(RequestContext::filters(['groupid' => '0007', 'tag' => ' Team ', 'tag_value' => ' Operations '])
	=== ['groupid' => '7', 'tag' => 'Team', 'tag_value' => 'Operations'], 'Canonical filters trim values and group ID zeros.');
check(RequestContext::filters(['tag' => 'service'])['tag_value'] === '', 'Tag existence filter is supported.');
foreach ([['groupid' => '-1'], ['groupid' => '1 OR 1=1'], ['groupid' => '18446744073709551616'],
	['groupid' => []], ['tag' => []], ['tag_value' => []], ['tag_value' => 'orphan'],
	['tag' => str_repeat('a', 256)], ['tag' => 'x', 'tag_value' => "a\x00b"]] as $input) {
	$rejected = false;
	try { RequestContext::filters($input); } catch (InvalidArgumentException $e) { $rejected = true; }
	check($rejected, 'Malformed filter rejected: '.json_encode($input));
}
$DB = ['SERVER' => 'db-a', 'DATABASE' => 'zabbix'];
$first = RequestContext::decorate([]);
$second = RequestContext::decorate([]);
check($first['preference_key'] === $second['preference_key'], 'Preferences persist for the same user and installation.');
CWebUser::$data['userid'] = '8';
check($first['preference_key'] !== RequestContext::decorate([])['preference_key'], 'Different users do not share preferences.');
CWebUser::$data['userid'] = '7';
$DB['DATABASE'] = 'another';
check($first['preference_key'] !== RequestContext::decorate([])['preference_key'], 'Different installations do not share preferences.');
check(abs(time() - $first['checked_at']) <= 1, 'Checked timestamp describes this request.');
check(isset($first['ui']['refresh_failed'], $first['ui']['snooze'], $first['ui']['expand']), 'Client labels are supplied.');
check($first['idle_timeout'] === 900, 'Missing idle configuration pauses polling conservatively.');
CWebUser::$data['autologout'] = '30m';
check(RequestContext::decorate([])['idle_timeout'] === 1800, 'User automatic logout is parsed.');
CWebUser::$data['autologout'] = '0';
check(RequestContext::decorate([])['idle_timeout'] === 0, 'Explicitly disabled automatic logout supports kiosk polling.');
$failed = RequestContext::unavailable($defaults, 'Unavailable fixture');
check($failed['status'] === 'unavailable', 'Top-level failure remains unavailable.');
check(stripos($failed['summary_line'], 'all clear') === false, 'Failure never says all clear.');
foreach (['problems', 'unacked', 'longest', 'resolved', 'new_hosts', 'health', 'maintenances'] as $section) {
	check($failed[$section]['status'] === 'unavailable', 'Unavailable state for '.$section);
}
check($failed['problems']['total'] === null && $failed['health']['unreachable_hosts'] === null,
	'Unknown metrics are null, not zero.');
check($failed['generated_at'] === null && $failed['generated_at_text'] === '', 'Failure has no successful generation time.');
echo 'RequestContextTest: '.$checks." assertions passed\n";
