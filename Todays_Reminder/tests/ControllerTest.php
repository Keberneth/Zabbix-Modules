<?php
// Exercises real controllers/provider/renderer with API boundaries replaced by empty fixtures.
if (!function_exists('_')) { function _($text) { return $text; } }
define('USER_TYPE_ZABBIX_USER', 1);
class CWebUser { public static $data = ['userid' => 'controller-fixture', 'type' => 3, 'autologout' => '15m']; }
class CUrl {
	private $url; private $args = [];
	public function __construct($url) { $this->url = $url; }
	public function setArgument($name, $value) { $this->args[$name] = $value; return $this; }
	public function getUrl() { return $this->url.'?'.http_build_query($this->args); }
}
class API {
	public static $calls = 0;
	public static $fail = false;
	public static function __callStatic($name, $arguments) { return new ApiFixture; }
}
class ApiFixture {
	public function get($options) {
		API::$calls++;
		if (API::$fail) { throw new RuntimeException('SYNTHETIC_INTERNAL_DETAIL'); }
		return !empty($options['countOutput']) ? '0' : [];
	}
}
class CControllerResponseData {
	public $data;
	public function __construct($data) { $this->data = $data; }
	public function disableView() { return $this; }
}
class CController {
	public $input = []; public $response;
	public function getInput($key, $default = null) { return $this->input[$key] ?? $default; }
	public function hasInput($key) { return array_key_exists($key, $this->input); }
	public function getUserType() { return CWebUser::$data['type']; }
	public function setResponse($response) { $this->response = $response; }
	public function disableCsrfValidation() {}
	public function validateInput($fields) { return true; }
}
require_once __DIR__.'/../actions/BannerData.php';
require_once __DIR__.'/../actions/BannerView.php';
use Modules\MessageOfTheDay\Actions\BannerData;
use Modules\MessageOfTheDay\Actions\BannerView;
use Modules\MessageOfTheDay\Helpers\RequestContext;
use Modules\MessageOfTheDay\Helpers\OverviewRenderer;
$DB = ['SERVER' => 'controller-test-'.getmypid(), 'DATABASE' => 'synthetic'];
$checks = 0;
function check($condition, $message) {
	global $checks;
	$checks++;
	if (!$condition) { throw new RuntimeException($message); }
}
function invoke($controller, $method) {
	$reflection = new ReflectionMethod($controller, $method);
	$reflection->setAccessible(true);
	return $reflection->invoke($controller);
}
function endpoint(array $input = []): array {
	$controller = new BannerData;
	$controller->input = $input;
	http_response_code(200);
	invoke($controller, 'doAction');
	return json_decode($controller->response->data['main_block'], true, 512, JSON_THROW_ON_ERROR);
}
foreach ([new BannerData, new BannerView] as $controller) {
	CWebUser::$data['type'] = 0;
	check(invoke($controller, 'checkPermissions') === false, 'Unauthenticated user denied.');
	CWebUser::$data['type'] = 1;
	check(invoke($controller, 'checkPermissions') === true, 'Standard Zabbix user can access the module.');
}
CWebUser::$data['type'] = 3;
$initial = endpoint(['overview' => '1']);
check($initial['ok'] === true && is_array($initial['data']), 'Data endpoint returns a structured payload.');
check(isset($initial['html']) && strpos($initial['html'], 'motd-grid') !== false, 'Overview endpoint renders the shared body.');
check(isset($initial['data']['ui'], $initial['data']['preference_key'], $initial['data']['idle_timeout']), 'Client context is included.');
$unchanged = endpoint(['fingerprint' => $initial['data']['fingerprint'], 'overview' => '1']);
check(!empty($unchanged['not_modified']), 'Unchanged payload suppresses redundant HTML.');
check(!isset($unchanged['html']) && isset($unchanged['checked_at'], $unchanged['ui'], $unchanged['idle_timeout']),
	'Unchanged response retains freshness, localization and session policy.');
$before = API::$calls;
$invalid = endpoint(['groupid' => 'bad']);
check($invalid['ok'] === false && http_response_code() === 400, 'Bad filters return a client error.');
check(API::$calls === $before, 'Invalid filter cannot widen into an unfiltered query.');
$view = new BannerView;
$view->input = ['tag_value' => 'orphan'];
invoke($view, 'doAction');
check($view->response->data['motd']['status'] === 'unavailable', 'Invalid overview filters show unavailable data.');
check(API::$calls === $before, 'Invalid overview filters never query all hosts.');
API::$fail = true;
CWebUser::$data['userid'] = 'failing-fixture';
$failed = endpoint(['overview' => '1']);
check($failed['data']['status'] === 'unavailable', 'Complete API failure is unavailable.');
check($failed['data']['problems']['high_count'] === null, 'Failed problem count is unknown.');
check(strpos(json_encode($failed), 'SYNTHETIC_INTERNAL_DETAIL') === false, 'Internal exception details stay out of responses.');
check(strpos($failed['html'], 'No unresolved High/Critical') === false, 'Failed overview does not render a reassuring empty state.');
$escaped = OverviewRenderer::render(RequestContext::unavailable([], '<script>synthetic</script>'));
check(strpos($escaped, '<script>synthetic</script>') === false && strpos($escaped, '&lt;script&gt;') !== false,
	'Server-rendered messages are HTML escaped.');
$coverage = RequestContext::unavailable([], 'Partial fixture');
$coverage['new_hosts'] = ['status' => 'partial', 'message' => 'System logging disabled.',
	'count' => 35, 'count_is_lower_bound' => true, 'coverage_note' => 'Recorded additions only.', 'recent' => []];
$coverage_html = OverviewRenderer::render($coverage);
check(strpos($coverage_html, '35+') !== false, 'The overview preserves lower-bound audit counts.');
check(strpos($coverage_html, 'Recorded additions only.') !== false, 'The overview discloses audit coverage.');
echo 'ControllerTest: '.$checks." assertions passed\n";
