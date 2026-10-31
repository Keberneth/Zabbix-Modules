<?php

declare(strict_types=1);

// CLI-only fixtures. They never connect to a Zabbix installation.
if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
	http_response_code(404);
	exit;
}

class CController {
	public array $inputs = [];
	public ?CControllerResponseData $response = null;
	public int $user_type = 1;
	protected function getInput(string $name, $default = null) { return $this->inputs[$name] ?? $default; }
	protected function setResponse(CControllerResponseData $response): void { $this->response = $response; }
	protected function getUserType(): int { return $this->user_type; }
}

class CControllerResponseData {
	public array $data;
	public function __construct(array $data) { $this->data = $data; }
}

class FixtureApiService {
	public $handler;
	public array $calls = [];
	public function get(array $params) {
		$this->calls[] = $params;
		return ($this->handler)($params);
	}
}

class API {
	public static array $services = [];
	public static function reset(): void {
		foreach (['Event', 'Host', 'HostGroup', 'Template', 'Trigger'] as $name) {
			self::$services[$name] = new FixtureApiService();
			self::$services[$name]->handler = static fn (array $params) => [];
		}
	}
	public static function Event(): FixtureApiService { return self::$services['Event']; }
	public static function Host(): FixtureApiService { return self::$services['Host']; }
	public static function HostGroup(): FixtureApiService { return self::$services['HostGroup']; }
	public static function Template(): FixtureApiService { return self::$services['Template']; }
	public static function Trigger(): FixtureApiService { return self::$services['Trigger']; }
}

if (!function_exists('_')) {
	function _(string $text): string { return $text; }
}
define('USER_TYPE_ZABBIX_USER', 1);
foreach (['NOT_CLASSIFIED', 'INFORMATION', 'WARNING', 'AVERAGE', 'HIGH', 'DISASTER'] as $severity => $name) {
	define('TRIGGER_SEVERITY_'.$name, $severity);
}

require dirname(__DIR__).'/actions/IncidentTimelineData.php';
require dirname(__DIR__).'/actions/IncidentTimelineView.php';

use Modules\IncidentTimeline\Actions\IncidentTimelineData;
use Modules\IncidentTimeline\Actions\IncidentTimelineView;

final class IncidentTimelineDataTest {
	private int $assertions = 0;
	private int $start;

	public function __construct() { $this->start = gmmktime(0, 0, 0, 10, 1, 2026); }

	private function same($expected, $actual, string $message): void {
		$this->assertions++;
		if ($expected !== $actual) {
			throw new RuntimeException($message.': expected '.var_export($expected, true).', got '.var_export($actual, true));
		}
	}

	private function invoke(object $controller, string $name, ...$args) {
		$method = new ReflectionMethod($controller, $name);
		$method->setAccessible(true);
		return $method->invoke($controller, ...$args);
	}

	private function report(array $inputs = []): array {
		$controller = new IncidentTimelineData();
		$controller->inputs = $inputs + ['time_from' => (string) $this->start, 'time_to' => (string) ($this->start + 86399)];
		$this->invoke($controller, 'doAction');
		return json_decode($controller->response->data['main_block'], true, 512, JSON_THROW_ON_ERROR);
	}

	private function event(int $id, int $clock, string $recovery = '0', string $name = 'Incident'): array {
		return ['eventid' => (string) $id, 'clock' => $clock, 'severity' => '3', 'objectid' => '10',
			'name' => $name, 'r_eventid' => $recovery];
	}

	private function installEvents(array $events, array $recoveries = []): void {
		API::Event()->handler = static function (array $params) use ($events, $recoveries) {
			if (isset($params['eventids'])) {
				return array_intersect_key($recoveries, array_fill_keys($params['eventids'], true));
			}
			$rows = array_filter($events, static function (array $event) use ($params): bool {
				return $event['clock'] >= ($params['time_from'] ?? 0)
					&& $event['clock'] <= ($params['time_till'] ?? PHP_INT_MAX)
					&& (!isset($params['severities']) || in_array((int) $event['severity'], $params['severities'], true));
			});
			if (!empty($params['countOutput'])) {
				return (string) count($rows);
			}
			return array_slice($rows, 0, $params['limit'] ?? count($rows), true);
		};
	}

	public function run(): void {
		$this->testApiFailuresAreErrors();
		$this->testResolutionScopeAndCap();
		$this->testRecoveryStatusAndCoverage();
		$this->testPaginationBoundariesAndLimit();
		$this->testRegexErrorsAndValidMatching();
		$this->testInputValidationAndPermissions();
		$this->testCalendarBucketBoundaries();
		echo 'OK: '.$this->assertions." incident timeline backend checks\n";
	}

	private function testApiFailuresAreErrors(): void {
		foreach ([false, null, [], 'invalid', '-1', '1.5'] as $bad_count) {
			API::reset();
			API::Event()->handler = static fn (array $p) => $bad_count;
			$this->same(500, $this->report()['error']['code'] ?? null, 'Count failures cannot become zero incidents');
		}
		foreach (['HostGroup' => ['group' => 'Production'], 'Template' => ['template' => 'Windows'], 'Host' => ['host' => 'Database']] as $service => $inputs) {
			API::reset();
			API::$services[$service]->handler = static fn (array $p) => false;
			$this->same(500, $this->report($inputs + ['mode' => 'resolve'])['error']['code'] ?? null, $service.' failure cannot become no matches');
		}
		API::reset();
		API::Event()->handler = static fn (array $p) => false;
		$this->same(500, $this->report(['mode' => 'incidents'])['error']['code'] ?? null, 'Event page failure is an error');

		$events = array_fill(1, 2000, $this->event(1, $this->start));
		API::Event()->handler = static fn (array $p) => $p['limit'] === 2000 ? $events : false;
		$this->same(500, $this->report(['mode' => 'incidents'])['error']['code'] ?? null, 'Overflow drain failure cannot skip events');

		foreach (['incidents', 'top_triggers'] as $mode) {
			API::reset();
			$event = $this->event(1, $this->start, '50');
			API::Event()->handler = static fn (array $p) => isset($p['eventids']) ? false : [1 => $event];
			$this->same(500, $this->report(['mode' => $mode])['error']['code'] ?? null, $mode.' recovery lookup failure is an error');
		}
		API::reset();
		$this->installEvents([1 => $this->event(1, $this->start)]);
		API::Trigger()->handler = static fn (array $p) => false;
		$this->same(500, $this->report(['mode' => 'top_triggers'])['error']['code'] ?? null, 'Trigger lookup failure is an error');

		API::reset();
		API::Event()->handler = static fn (array $p) => '0';
		$this->same(0, $this->report()['meta']['total_incidents'], 'A successful zero count is retained');
		$this->same(true, $this->report(['mode' => 'resolve', 'group' => 'No match'])['empty'], 'Successful empty group lookup is retained');
	}

	private function testResolutionScopeAndCap(): void {
		API::reset();
		API::HostGroup()->handler = static fn (array $p) => [['groupid' => '7']];
		API::Template()->handler = static fn (array $p) => [['templateid' => '8']];
		$hosts = array_map(static fn (int $id) => ['hostid' => (string) $id], range(1, 5000));
		API::Host()->handler = static fn (array $p) => $hosts;
		$result = $this->report(['mode' => 'resolve', 'group' => 'Production', 'host' => 'Database', 'template' => 'Windows']);
		$this->same(false, $result['truncated'], 'Exactly 5000 matched hosts is complete');
		$this->same(5000, count($result['hostids']), 'All 5000 hosts are retained');
		$params = API::Host()->calls[0];
		$this->same(['7'], $params['groupids'], 'Host group scope is applied to the same host query');
		$this->same(['8'], $params['templateids'], 'Template scope is applied to the same host query');
		$this->same(['host' => 'Database', 'name' => 'Database'], $params['search'], 'Both visible and technical host names are searched');
		$this->same(true, $params['searchByAny'], 'Either host name can match');
		$this->same(['host' => 'Windows', 'name' => 'Windows'], API::Template()->calls[0]['search'], 'Both template names are searched');
		$this->same(true, API::Template()->calls[0]['searchByAny'], 'Either template name can match');
		$hosts[] = ['hostid' => '5001'];
		API::Host()->handler = static fn (array $p) => $hosts;
		$result = $this->report(['mode' => 'resolve', 'host' => 'Database']);
		$this->same(true, $result['truncated'], 'A sentinel host proves truncation');
		$this->same(5000, count($result['hostids']), 'Sentinel host is excluded from subsequent scope');
	}

	private function testRecoveryStatusAndCoverage(): void {
		API::reset();
		$this->installEvents([
			1 => $this->event(1, $this->start, '50'),
			2 => $this->event(2, $this->start + 10, '50'),
			3 => $this->event(3, $this->start + 20, '51'),
			4 => $this->event(4, $this->start + 30),
			5 => $this->event(5, $this->start + 40, '52')
		], [50 => ['eventid' => '50', 'clock' => $this->start + 100], 52 => ['eventid' => '52', 'clock' => $this->start + 5]]);
		$result = $this->report(['mode' => 'incidents']);
		$this->same('resolved', $result['incidents'][0]['status'], 'Recovery time identifies a resolved incident');
		$this->same($this->start + 100, $result['incidents'][1]['rc'], 'Shared recovery closes each correlated problem');
		$this->same('resolved_time_unavailable', $result['incidents'][2]['status'], 'Missing recovery time does not mean ongoing');
		$this->same(null, $result['incidents'][2]['rc'], 'Unknown recovery time is explicit null');
		$this->same('51', $result['incidents'][2]['r'], 'Recovery identifier is retained');
		$this->same('ongoing', $result['incidents'][3]['status'], 'No recovery identifier means ongoing');
		$this->same(0, $result['incidents'][3]['rc'], 'Ongoing incidents retain zero recovery clock');
		$this->same('resolved_time_unavailable', $result['incidents'][4]['status'], 'Impossible negative duration is unavailable');
		$this->same(2, $result['meta']['recovery_time_unavailable'], 'Incident missing recovery coverage is counted');

		$result = $this->report(['mode' => 'top_triggers']);
		$trigger = $result['top_triggers'][0];
		$this->same(5, $trigger['count'], 'All matching incidents contribute to ranking');
		$this->same(4, $trigger['resolved'], 'Resolved count includes unavailable timestamps');
		$this->same(2, $trigger['duration_sample_count'], 'Only known nonnegative durations contribute to MTTR');
		$this->same(2, $trigger['recovery_time_unavailable'], 'Per-trigger MTTR coverage is explicit');
		$this->same(95, $trigger['mttr'], 'Shared recovery samples use each problem start');
		$this->same(2, $result['meta']['recovery_time_unavailable'], 'Top-trigger missing recovery coverage is counted');

		$this->installEvents([1 => $this->event(1, $this->start, '50')]);
		$trigger = $this->report(['mode' => 'top_triggers'])['top_triggers'][0];
		$this->same(null, $trigger['mttr'], 'MTTR is unavailable when no duration can be measured');
		$this->same(1, $trigger['resolved'], 'Resolved count remains known when MTTR is unavailable');
	}

	private function testPaginationBoundariesAndLimit(): void {
		API::reset();
		$events = [];
		for ($id = 1; $id <= 4501; $id++) {
			$clock = $id <= 1500 ? $this->start : ($id <= 4000 ? $this->start + 1 : $this->start + 2);
			$events[$id] = $this->event($id, $clock);
		}
		$this->installEvents($events);
		$controller = new IncidentTimelineData();
		$ids = [];
		$limited = $this->invoke($controller, 'paginateEvents', [], $this->start, $this->start + 2,
			static function (string $id, array $event) use (&$ids): void { $ids[] = $id; });
		$this->same(false, $limited, 'Multi-page timestamp overflow finishes without cap');
		$this->same(4501, count($ids), 'Every boundary and overflow event is visited');
		$this->same(4501, count(array_unique($ids)), 'Boundary overlap never duplicates incidents');
		$this->same('4501', end($ids), 'Pagination continues after overflow timestamp');

		$this->installEvents(array_fill(1, 200001, $this->event(1, $this->start)));
		$count = 0;
		$limited = $this->invoke($controller, 'paginateEvents', [], $this->start, $this->start,
			static function (string $id, array $event) use (&$count): void { $count++; });
		$this->same(true, $limited, 'Huge timestamp reports scan cap');
		$this->same(200000, $count, 'Huge timestamp processing is bounded');
	}

	private function testRegexErrorsAndValidMatching(): void {
		API::reset();
		$this->installEvents([1 => $this->event(1, $this->start, '0', str_repeat('a', 100).'b')]);
		foreach (['aggregate', 'incidents', 'top_triggers'] as $mode) {
			$result = $this->report(['mode' => $mode, 'name' => '(*LIMIT_MATCH=10)(*NO_JIT)^(a+)+$', 'name_regex' => '1']);
			$this->same(400, $result['error']['code'] ?? null, $mode.' reports regex runtime exhaustion');
			$this->same(true, strpos($result['error']['message'], 'Simplify') !== false, 'Regex failure provides corrective guidance');
		}
		$this->same(400, $this->report(['name' => '[', 'name_regex' => '1'])['error']['code'] ?? null, 'Invalid regex syntax is rejected');
		$this->same(400, $this->report(['name' => str_repeat('a', 1001), 'name_regex' => '1'])['error']['code'] ?? null, 'Overlong regex is rejected');
		$this->installEvents([1 => $this->event(1, $this->start, '0', 'CPU high'), 2 => $this->event(2, $this->start, '0', 'Disk high')]);
		foreach (['aggregate', 'incidents', 'top_triggers'] as $mode) {
			$this->same(1, $this->report(['mode' => $mode, 'name' => '^cpu', 'name_regex' => '1'])['meta']['total_incidents'], $mode.' valid regex still matches case-insensitively');
		}
	}

	private function testInputValidationAndPermissions(): void {
		API::reset();
		foreach (['123junk', '1e3', '123.4', '-1', '0', '999999999999999999999999', '253402300800'] as $value) {
			$this->same(400, $this->report(['time_from' => $value])['error']['code'] ?? null, 'Malformed timestamp is rejected: '.$value);
		}
		$this->same(400, $this->report(['time_to' => (string) ($this->start - 1)])['error']['code'] ?? null, 'Reversed range is rejected');
		$this->same(400, $this->report(['time_to' => (string) ($this->start + 769 * 86400)])['error']['code'] ?? null, 'Excess range is rejected');
		foreach ([['hostids' => 'bad'], ['groupids' => '0'], ['hostids' => '1,bad'], ['severities' => '-1'], ['severities' => '3,7']] as $input) {
			$this->same(400, $this->report($input)['error']['code'] ?? null, 'Malformed filter cannot broaden result scope');
		}
		foreach ([new IncidentTimelineData(), new IncidentTimelineView()] as $controller) {
			$controller->user_type = 0;
			$this->same(false, $this->invoke($controller, 'checkPermissions'), 'Unauthenticated user is denied');
			$controller->user_type = 1;
			$this->same(true, $this->invoke($controller, 'checkPermissions'), 'Authenticated Zabbix user is allowed');
		}
		$view = new IncidentTimelineView();
		foreach (['2026-02-29', '2026-13-01', '2026-04-31', '0000-01-01', "2026-01-01\n"] as $date) {
			$this->same('', $this->invoke($view, 'sanitizeDate', $date), 'Impossible calendar date is discarded');
		}
		$this->same('2024-02-29', $this->invoke($view, 'sanitizeDate', '2024-02-29'), 'Leap day is accepted');
		$this->same('', $this->invoke($view, 'sanitizeMonth', '2026-13'), 'Impossible month is discarded');
		$this->same('', $this->invoke($view, 'sanitizeMonth', '0000-01'), 'Year zero is discarded');
		$this->same('2026-10', $this->invoke($view, 'sanitizeMonth', '2026-10'), 'Valid month bookmark is accepted');
	}

	private function testCalendarBucketBoundaries(): void {
		API::reset();
		$from = gmmktime(12, 0, 0, 2, 28, 2024);
		$to = gmmktime(12, 0, 0, 3, 1, 2024);
		$this->installEvents([
			1 => $this->event(1, $from - 1), 2 => $this->event(2, $from),
			3 => $this->event(3, gmmktime(0, 0, 0, 2, 29, 2024)),
			4 => $this->event(4, $to), 5 => $this->event(5, $to + 1)
		]);
		foreach (['day', 'week', 'month'] as $bucket) {
			$result = $this->report(['time_from' => (string) $from, 'time_to' => (string) $to, 'bucket' => $bucket]);
			$this->same(3, $result['meta']['total_incidents'], $bucket.' respects inclusive selected range');
			$this->same(3, array_sum(array_column($result['buckets'], 'sev_3')), $bucket.' buckets never include outside events or duplicate boundaries');
		}
	}
}

(new IncidentTimelineDataTest())->run();
