<?php
// Local visual fixture only. Never handles a request in a deployed PHP-FPM frontend.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/assets/') === 0) { return false; }
if (!function_exists('_')) { function _($text) { return $text; } }
class CWebUser { public static $data = ['userid' => 'preview']; }
require_once __DIR__.'/../helpers/RequestContext.php';
require_once __DIR__.'/../helpers/OverviewRenderer.php';
use Modules\MessageOfTheDay\Helpers\RequestContext;
use Modules\MessageOfTheDay\Helpers\OverviewRenderer;

$now = time();
$row = ['eventid' => '101', 'triggerid' => '22', 'severity' => 4, 'severity_key' => 'high',
	'severity_label' => 'High', 'name' => 'Application backup failed', 'clock' => $now - 7200,
	'clock_text' => date('Y-m-d H:i', $now - 7200), 'age_text' => '2h ago',
	'host_text' => 'app-example', 'hosts' => ['app-example'], 'url' => '#problem-101',
	'resolved_age_text' => '10m ago', 'mttr_text' => '1h 50m'];
$maintenance = ['maintenanceid' => '50', 'name' => 'Database platform upgrade',
	'description' => 'A maintenance window spanning multiple days.', 'status' => 'ongoing',
	'status_label' => 'Ongoing', 'start_ts' => $now - 86400, 'end_ts' => $now + 7200,
	'start_text' => date('Y-m-d H:i', $now - 86400), 'end_text' => date('Y-m-d H:i', $now + 7200),
	'time_text' => date('Y-m-d H:i', $now - 86400).' – '.date('Y-m-d H:i', $now + 7200),
	'day_text' => date('D d M'), 'scope_text' => 'Hosts: db-example', 'remaining_text' => '2h remaining'];
$motd = RequestContext::decorate([
	'title' => "Today's Reminder", 'status' => 'partial',
	'issues' => [['section' => 'health', 'message' => 'Server queue data is unavailable.']],
	'generated_at' => $now, 'generated_at_text' => date('Y-m-d H:i', $now), 'timezone' => 'Europe/Stockholm',
	'summary_line' => 'Critical: 1 · High: 3 · Unacknowledged: 2 · 1 maintenance today',
	'fingerprint' => 'preview-'.intdiv($now, 60),
	'filters' => RequestContext::filters($_GET),
	'filter_options' => ['groups' => [['groupid' => '10', 'name' => 'Applications'], ['groupid' => '20', 'name' => 'Infrastructure']]],
	'links' => ['module' => 'zabbix.php?action=motd.view'],
	'chips' => [
		['label' => 'Critical', 'value' => '1', 'kind' => 'critical', 'url' => '#critical'],
		['label' => 'High', 'value' => '3', 'kind' => 'high', 'url' => '#high'],
		['label' => 'Unsupported items', 'value' => '818 items · 127 hosts', 'kind' => 'stale', 'url' => '#unsupported'],
		['label' => 'Today', 'value' => '1', 'kind' => 'maintenance', 'url' => '#maintenance']
	],
	'banner_items' => [['text' => 'app-example — Application backup failed (High)', 'url' => '#problem-101']],
	'problems' => ['status' => 'ok', 'high_count' => 3, 'critical_count' => 1, 'suppressed_count' => 0,
		'all_url' => '#problems', 'high_critical_url' => '#problems'],
	'unacked' => ['status' => 'ok', 'count' => 2, 'recent' => [$row], 'url' => '#unacked'],
	'longest' => ['status' => 'ok', 'recent' => [array_replace($row, ['severity_key' => 'critical',
		'severity_label' => 'Critical', 'age_text' => '3d ago', 'name' => 'Service is unavailable'])]],
	'resolved' => ['status' => 'ok', 'count' => 24, 'avg_mttr_text' => '2h 9m', 'recent' => [$row]],
	'health' => ['status' => 'partial', 'stale_items_total' => 818, 'stale_items_hosts' => 127,
		'unreachable_hosts' => 1, 'unreachable_proxies' => 0, 'queue_backlog' => null,
		'field_messages' => ['queue_backlog' => 'No recent server queue sample is available.'],
		'unreachable_details' => [['hostid' => '30', 'name' => 'app-example', 'url' => '#host-30']],
		'stale_url' => '#unsupported', 'unreachable_hosts_url' => '#motd-unreachable-hosts',
		'proxy_url' => '#proxies', 'queue_url' => '#queue'],
	'new_hosts' => ['status' => 'unavailable', 'count' => null, 'recent' => [], 'message' => 'Audit history is not available for this role.'],
	'maintenances' => ['status' => 'ok', 'today_count' => 1, 'week_count' => 0, 'today' => [$maintenance], 'week' => []]
]);
if (($_GET['action'] ?? '') === 'motd.banner.data') {
	header('Content-Type: application/json');
	$payload = ['ok' => true, 'data' => $motd];
	if (($_GET['overview'] ?? '') === '1') { $payload['html'] = OverviewRenderer::render($motd); }
	echo json_encode($payload);
	return;
}
class CHtmlPage {
	private $title;
	private $item;
	public function setTitle($title) { $this->title = $title; return $this; }
	public function addItem($item) { $this->item = $item; return $this; }
	public function show() {
		$manifest = json_decode(file_get_contents(__DIR__.'/../manifest.json'), true);
		echo '<!doctype html><html lang="en" theme="dark-theme"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<title>Reminder local preview</title><link rel="stylesheet" href="assets/css/'.htmlspecialchars($manifest['assets']['css'][0]).'">';
		echo '<style>body{margin:0;background:#101114;color:#dadee3;font:14px Arial,sans-serif}main{padding:20px;max-width:1500px;margin:auto}header{padding:14px 20px;background:#282a2e;font-size:22px}button,input,select{font:inherit}a{color:#93c5fd}</style></head><body><header>'.htmlspecialchars($this->title).'</header><main>';
		echo $this->item->toString().'</main><script src="assets/js/'.htmlspecialchars($manifest['assets']['js'][0]).'"></script></body></html>';
	}
}
if (($_GET['action'] ?? '') === 'problem.view') {
	(new CHtmlPage())->setTitle('Problems — local banner preview')->addItem(new class { public function toString() { return '<p>Standalone banner preview with synthetic data.</p>'; } })->show();
	return;
}
$data = ['title' => "Today's Reminder", 'motd' => $motd];
require __DIR__.'/../views/motd.view.php';
