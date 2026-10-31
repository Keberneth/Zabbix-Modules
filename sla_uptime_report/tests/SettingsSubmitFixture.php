<?php declare(strict_types=1);
// Offline browser-submit bridge: real PHP form parsing and ReportSettings persistence,
// with only the Zabbix database/API boundary replaced by an in-memory test double.
require __DIR__.'/../helpers/ReportSettings.php';
use Modules\SlaUptimeReport\Helpers\ReportSettings;
define('USER_TYPE_SUPER_ADMIN', 3);
final class CWebUser { public static function getType(): int { return USER_TYPE_SUPER_ADMIN; } }
function DBselect($query, $limit) { return true; }
function DBfetch($result): array { return $GLOBALS['submit_record']; }
function zbx_dbstr($value): string { return "'".$value."'"; }
final class SubmitApi {
	public int $writes = 0;
	public function get(array $params): array {
		$field = $params['output'][0];
		return array_map(static fn($id): array => [$field => (string) $id], $params[$field.'s']);
	}
	public function update(array $rows): array {
		$this->writes++;
		$GLOBALS['submit_record']['config'] = json_encode($rows[0]['config'], JSON_THROW_ON_ERROR);
		return ['moduleids' => ['1']];
	}
}
final class API {
	public static SubmitApi $instance;
	public static function Host(): SubmitApi { return self::$instance; }
	public static function HostGroup(): SubmitApi { return self::$instance; }
	public static function Module(): SubmitApi { return self::$instance; }
}
API::$instance = new SubmitApi();
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$GLOBALS['submit_record'] = ['moduleid' => '1',
	'config' => json_encode(['availability' => $input['stored']], JSON_THROW_ON_ERROR)];
parse_str($input['post'], $post);
if (($post['form_complete'] ?? '') !== '1') { throw new RuntimeException('Form was truncated.'); }
ReportSettings::save($post['settings'], $post['revision']);
echo json_encode(ReportSettings::snapshot() + ['writes' => API::$instance->writes,
	'parsed_host_rows' => count($post['settings']['hosts'] ?? []),
	'parsed_group_rows' => count($post['settings']['groups'] ?? [])], JSON_THROW_ON_ERROR);
