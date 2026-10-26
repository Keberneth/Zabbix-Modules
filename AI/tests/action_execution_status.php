<?php declare(strict_types = 1);

require_once __DIR__.'/../lib/bootstrap.php';

use Modules\AI\Lib\ZabbixActionExecutor;
use Modules\AI\Lib\ZabbixApiClient;

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class StatusProbeApi extends ZabbixApiClient {
    public array $calls = [];

    public function __construct() {
        parent::__construct('', '', true, 15, 'frontend', 'frontend');
    }

    public function call(string $method, array $params = []): array {
        $this->calls[] = $method;
        if ($method === 'problem.get') {
            return [[
                'eventid' => '1', 'name' => 'Error: application connection failed',
                'severity' => '3', 'acknowledged' => '0', 'clock' => '1', 'objectid' => '2'
            ]];
        }
        return [];
    }
}

$api = new StatusProbeApi();
$params = ['trigger_id' => '123', 'changes' => ['comments' => 'Reviewed']];
check(ZabbixActionExecutor::validateWriteParams('update_trigger', $params) === [], 'Fixture must pass parameter validation.');
check(strpos(ZabbixActionExecutor::execute('update_trigger', $params, $api), 'Error:') === 0, 'Fixture did not hit a legacy returned error.');
try {
    ZabbixActionExecutor::executeChecked('update_trigger', $params, $api);
    throw new RuntimeException('Failed write was accepted as successful.');
}
catch (RuntimeException $e) {
    check($e->getMessage() === 'Error: trigger ID 123 not found.', 'Failed write did not preserve the useful failure reason.');
}
check(!in_array('trigger.update', $api->calls, true), 'Rejected write reached the mutation API.');

$result = ZabbixActionExecutor::executeChecked('get_problems', [], $api);
check(strpos($result, 'Error: application connection failed') !== false, 'A successful report containing an error description was rejected.');

try {
    ZabbixActionExecutor::executeChecked('get_alerts_for_event', [], $api);
    throw new RuntimeException('Failed read was accepted as successful.');
}
catch (RuntimeException $e) {
    check($e->getMessage() === 'Error: eventid is required.', 'Failed read did not preserve its failure reason.');
}

echo "Action execution status tests passed.\n";
