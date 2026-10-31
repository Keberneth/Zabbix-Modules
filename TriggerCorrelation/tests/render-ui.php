<?php
// Minimal frontend shell for browser regression checks; no Zabbix instance required.
if (!function_exists('_')) { function _($value) { return $value; } }
class CWebUser { public static $data = ['userid' => 'ui-test']; }
class CCsrfTokenHelper { const CSRF_TOKEN_NAME = '_csrf_token'; public static function get($action) { return 'csrf-'.$action; } }
class CUrl {
    private $path; private $query = [];
    public function __construct($path) { $this->path = $path; }
    public function setArgument($key, $value) { $this->query[$key] = $value; return $this; }
    public function getUrl() { return $this->path.'?'.http_build_query($this->query); }
}
class CHtmlPage {
    private $item;
    public function setTitle($title) { return $this; }
    public function addItem($item) { $this->item = $item; return $this; }
    public function show() { echo $this->item->toString(); }
}
$conditions = [
    ['hostid' => '1', 'host' => 'web01', 'triggerid' => '11', 'trigger' => 'Service down'],
    ['hostid' => '2', 'host' => 'db01', 'triggerid' => '22', 'trigger' => 'Database down']
];
$data = ['title' => 'Trigger Correlation', 'config' => ['settings' => [], 'rules' => [
    ['id' => 'r1', 'name' => 'Web outage', 'conditions' => $conditions, 'enabled' => true,
     'output' => ['mode' => 'receiver_lld', 'receiver_auto' => true, 'match_mode' => 'all', 'match_value' => 4, 'correlation_id' => 'web'], 'last_state' => 0]
], 'severity_rules' => [
    ['id' => 's1', 'name' => 'Raise database', 'conditions' => [$conditions[0]], 'enabled' => true,
     'targets' => [['scope' => 'host', 'trigger' => 'Database down', 'triggerid' => '22', 'hostid' => '2', 'host' => 'db01']], 'severity' => 5]
]]];
require dirname(__DIR__).'/views/triggercorrelation.view.php';
