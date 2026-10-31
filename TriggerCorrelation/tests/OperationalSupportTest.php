<?php

declare(strict_types=1);

// Offline contract tests: API callbacks expose only reads and no Zabbix writes.
class CController {}
require_once dirname(__DIR__).'/lib/RuleTransfer.php';
require_once dirname(__DIR__).'/actions/DashboardData.php';

use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\RuleTransfer;
use Modules\TriggerCorrelation\Actions\DashboardData;

$assertions = 0;
function check(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}

$hosts = [
    ['hostid' => '11', 'host' => 'web01', 'name' => 'Web server'],
    ['hostid' => '12', 'host' => 'db01', 'name' => 'Database server'],
    ['hostid' => '13', 'host' => 'receiver', 'name' => 'Receiver']
];
$triggers = [
    ['triggerid' => '21', 'description' => 'HTTP down', 'hosts' => [$hosts[0]]],
    ['triggerid' => '22', 'description' => 'Database down', 'hosts' => [$hosts[1]]],
    ['triggerid' => '23', 'description' => 'Cluster node down', 'hosts' => [$hosts[0]]],
    ['triggerid' => '24', 'description' => 'Cluster node down', 'hosts' => [$hosts[1]]]
];
$itemRows = [
    ['itemid' => '41', 'hostid' => '13', 'key_' => 'correlation.output', 'type' => '2', 'value_type' => '3', 'status' => '0', 'hosts' => [$hosts[2]]],
    ['itemid' => '42', 'hostid' => '13', 'key_' => 'trigger.correlation.state[application]', 'type' => '2', 'value_type' => '3', 'status' => '0', 'hosts' => [$hosts[2]]]
];
$calls = [];
$ambiguous = false;
$call = static function (string $method, array $params) use (&$hosts, &$triggers, $itemRows, &$calls, &$ambiguous): array {
    $calls[] = $method;
    if ($method === 'host.get') {
        return array_values(array_filter($hosts, static fn(array $h): bool => isset($params['hostids'])
            ? in_array($h['hostid'], $params['hostids'], true) : $h['host'] === ($params['filter']['host'] ?? '')));
    }
    if ($method === 'hostgroup.get') {
        return (($params['filter']['name'] ?? 'cluster') === 'cluster') ? [['groupid' => '31', 'name' => 'cluster']] : [];
    }
    if ($method === 'trigger.get') {
        $rows = array_values(array_filter($triggers, static function (array $t) use ($params): bool {
            if (isset($params['triggerids']) && !in_array($t['triggerid'], $params['triggerids'], true)) { return false; }
            if (isset($params['hostids']) && !in_array($t['hosts'][0]['hostid'], $params['hostids'], true)) { return false; }
            if (isset($params['filter']['description']) && $t['description'] !== $params['filter']['description']) { return false; }
            return true;
        }));
        if ($ambiguous && isset($params['hostids']) && $rows !== []) { $rows[] = $rows[0] + ['duplicate' => true]; }
        return $rows;
    }
    if ($method === 'item.get') {
        return array_values(array_filter($itemRows, static fn(array $i): bool => isset($params['itemids'])
            ? in_array($i['itemid'], $params['itemids'], true)
            : in_array($i['hostid'], $params['hostids'], true) && $i['key_'] === ($params['filter']['key_'] ?? '')));
    }
    throw new RuntimeException('Unexpected or mutating API call: '.$method);
};

$rule = [
    'id' => 'private-rule-id', 'enabled' => true, 'name' => 'Application unavailable', 'description' => 'Web and database',
    'conditions' => [
        ['hostid' => '11', 'host' => 'Web server', 'triggerid' => '21', 'trigger' => 'Old display name'],
        ['hostid' => '12', 'host' => 'Database server', 'triggerid' => '22', 'trigger' => 'Old display name']
    ],
    'output' => ['mode' => 'receiver_lld', 'receiver_auto' => true, 'receiver_host' => 'private-generated-host', 'receiver_hostid' => '999',
        'correlation_id' => 'application', 'match_value' => 4, 'match_mode' => 'all', 'comment_correlation_problem' => false, 'comment_source_problems' => false],
    'activation_seconds' => 120, 'recovery_seconds' => 60, 'last_state' => 4,
    'applied' => ['secret-event-id' => ['sev' => 1]], 'last_error' => 'runtime-only'
];
$config = CorrelationStore::defaults();
$config['rules'] = [$rule];
$config['settings']['api_token'] = 'sensitive-token';
$config['settings']['eval_token_hash'] = 'sensitive-hash';
$transfer = new RuleTransfer($call);
$export = $transfer->export($config);
$json = json_encode($export);
foreach (['sensitive-token', 'sensitive-hash', 'private-rule-id', 'private-generated-host', 'secret-event-id', 'runtime-only', 'last_state', 'receiver_hostid'] as $forbidden) {
    check(strpos($json, $forbidden) === false, 'Export leaked '.$forbidden);
}
check($export['rules'][0]['conditions'][0]['host'] === 'web01', 'Export must use the technical host name');
check($export['rules'][0]['conditions'][0]['trigger'] === 'HTTP down', 'Export must refresh the exact trigger description');
check($export['rules'][0]['output']['comment_correlation_problem'] === false, 'Comment preferences must survive export');
check($export['rules'][0]['enabled'] === false, 'Export must not enable imported rules');

$prepared = $transfer->prepare($export, CorrelationStore::defaults());
check($prepared['preview']['errors'] === [], 'Valid export should map cleanly');
$imported = $prepared['rules'][0];
check($imported['enabled'] === false && $imported['conditions'][0]['triggerid'] === '21', 'Import must remap local IDs and remain disabled');
check($imported['id'] !== $rule['id'] && !isset($imported['last_state']), 'Import must create a fresh rule without runtime state');
check($imported['output']['receiver_host'] === '' && !isset($imported['output']['receiver_hostid']), 'Automatic output must await explicit provisioning');
check($imported['activation_seconds'] === 120 && $imported['recovery_seconds'] === 60, 'Timing must survive transfer');
check($imported['output']['comment_source_problems'] === false, 'Comment preferences must survive normalization');

$export['rules'][0]['conditions'][0]['hostid'] = '888';
$export['rules'][0]['conditions'][0]['triggerid'] = '777';
$export['rules'][0]['enabled'] = true;
$export['rules'][0]['applied'] = ['123' => ['sev' => 5]];
$prepared = (new RuleTransfer($call))->prepare($export, CorrelationStore::defaults());
check($prepared['rules'][0]['conditions'][0]['hostid'] === '11', 'Foreign IDs must never override name mapping');
check(!$prepared['rules'][0]['enabled'] && !isset($prepared['rules'][0]['applied']), 'Untrusted enable/runtime fields must be stripped');

$skip = (new RuleTransfer($call))->prepare($export, $config, 'skip');
check($skip['rules'] === [] && count($skip['preview']['conflicts']) === 1, 'Skip must preserve an existing named rule');
$rename = (new RuleTransfer($call))->prepare($export, $config, 'rename');
check($rename['rules'][0]['name'] !== $rule['name'], 'Rename must use a unique name');
check($rename['rules'][0]['output']['correlation_id'] !== 'application', 'Rename must also avoid output collisions');
$longConfig = $config;
$longConfig['rules'][0]['output']['correlation_id'] = str_repeat('a', 120);
$longExport = $export;
$longExport['rules'][0]['output']['correlation_id'] = str_repeat('a', 120);
$longRename = (new RuleTransfer($call))->prepare($longExport, $longConfig, 'rename');
check($longRename['preview']['errors'] === [] && strlen($longRename['rules'][0]['output']['correlation_id']) <= 120
    && $longRename['rules'][0]['output']['correlation_id'] !== str_repeat('a', 120), 'Rename must fit its unique suffix inside the correlation ID length limit');

$ambiguous = true;
$bad = (new RuleTransfer($call))->prepare($export, CorrelationStore::defaults());
check($bad['rules'] === [] && count($bad['preview']['errors']) === 1, 'Ambiguous triggers must block import');
$ambiguous = false;
$badDoc = $export;
$badDoc['rules'][0]['conditions'][0]['host'] = 'missing-host';
$bad = (new RuleTransfer($call))->prepare($badDoc, CorrelationStore::defaults());
check($bad['rules'] === [] && strpos($bad['preview']['errors'][0], 'not found') !== false, 'Missing hosts must report a mapping error');

$dynamic = $rule;
$dynamic['source_selector'] = ['mode' => 'group', 'groupid' => '31', 'group' => 'cluster', 'trigger_name' => 'Cluster node down', 'tag' => '', 'tag_value' => ''];
$dynamic['conditions'] = [['hostid' => 'old', 'triggerid' => 'old', 'host' => 'removed', 'trigger' => 'removed']];
$dynamicConfig = CorrelationStore::defaults();
$dynamicConfig['rules'] = [$dynamic];
$dynamicExport = (new RuleTransfer($call))->export($dynamicConfig);
check($dynamicExport['rules'][0]['conditions'] === [], 'Dynamic export must omit old membership snapshots');
$dynamicImport = (new RuleTransfer($call))->prepare($dynamicExport, CorrelationStore::defaults());
check($dynamicImport['preview']['errors'] === [] && count($dynamicImport['rules'][0]['conditions']) === 2, 'Dynamic import must resolve current destination membership: '.json_encode($dynamicImport['preview']));
check($dynamicImport['rules'][0]['source_selector']['groupid'] === '31', 'Dynamic group must be mapped locally');
$dynamicExport['rules'][0]['name'] = 'Dynamic application';
$besideStatic = (new RuleTransfer($call))->prepare($dynamicExport, $config, 'skip');
check($besideStatic['preview']['conflicts'] === [] && count($besideStatic['rules']) === 1, 'Dynamic receivers must remain distinct from static receivers with the same current hosts');

$severity = $export['rules'][0];
unset($severity['output']);
$severity['name'] = 'Escalate database';
$severity['targets'] = [['scope' => 'host', 'host' => 'db01', 'trigger' => 'Database down', 'triggerid' => 'foreign', 'hostid' => 'foreign']];
$severity['severity'] = 5;
$severity['match_mode'] = 'all';
$sevDoc = ['format' => RuleTransfer::FORMAT, 'version' => 1, 'rules' => [], 'severity_rules' => [$severity]];
$sev = (new RuleTransfer($call))->prepare($sevDoc, CorrelationStore::defaults());
check($sev['preview']['errors'] === [] && $sev['severity_rules'][0]['targets'][0]['triggerid'] === '22', 'Severity targets must resolve locally');
check(!$sev['severity_rules'][0]['enabled'], 'Severity imports must not immediately change live problems');

$itemRule = $export['rules'][0];
$itemRule['name'] = 'Existing output';
$itemRule['output'] = ['mode' => 'existing_item', 'host' => 'receiver', 'key' => 'correlation.output', 'itemid' => 'foreign', 'match_value' => 4];
$itemDoc = ['format' => RuleTransfer::FORMAT, 'version' => 1, 'rules' => [$itemRule], 'severity_rules' => []];
$item = (new RuleTransfer($call))->prepare($itemDoc, CorrelationStore::defaults());
check($item['preview']['errors'] === [] && $item['rules'][0]['output']['itemid'] === '41', 'Existing output must resolve by host and item key');
$itemConfig = CorrelationStore::defaults();
$itemConfig['rules'] = $item['rules'];
$itemCollision = (new RuleTransfer($call))->prepare($itemDoc, $itemConfig, 'rename');
check($itemCollision['rules'] === [] && count($itemCollision['preview']['errors']) === 1, 'Import cannot rename a real existing item to escape a conflict');
$autoConfig = CorrelationStore::defaults();
$autoConfig['rules'] = [$rule];
$autoConfig['rules'][0]['output']['receiver_hostid'] = '13';
$autoConfig['rules'][0]['output']['receiver_host'] = 'receiver';
$manualImport = $export['rules'][0];
$manualImport['name'] = 'Manual alias';
$manualImport['output']['receiver_auto'] = false;
$manualImport['output']['receiver_host'] = 'receiver';
$manualDoc = ['format' => RuleTransfer::FORMAT, 'version' => 1, 'rules' => [$manualImport], 'severity_rules' => []];
$manualConflict = (new RuleTransfer($call))->prepare($manualDoc, $autoConfig, 'skip');
check($manualConflict['rules'] === [] && count($manualConflict['preview']['conflicts']) === 1, 'Manual imports must detect an existing automatic receiver alias');
$manualRename = (new RuleTransfer($call))->prepare($manualDoc, $autoConfig, 'rename');
check($manualRename['preview']['errors'] === [] && $manualRename['rules'][0]['output']['correlation_id'] !== 'application', 'Manual output alias should get a new correlation ID when rename is requested');
$aliasItem = $itemRule;
$aliasItem['name'] = 'Alias existing item';
$aliasItem['output']['key'] = 'trigger.correlation.state[application]';
$aliasDoc = ['format' => RuleTransfer::FORMAT, 'version' => 1, 'rules' => [$aliasItem], 'severity_rules' => []];
$aliasConflict = (new RuleTransfer($call))->prepare($aliasDoc, $autoConfig, 'skip');
check($aliasConflict['rules'] === [] && count($aliasConflict['preview']['conflicts']) === 1, 'Existing-item imports must detect an automatic receiver state item');
$anchorConfig = $autoConfig;
$anchorConfig['rules'][0]['output']['correlation_id'] = 'anchor';
$existingManual = $rule;
$existingManual['id'] = 'manual-alias';
$existingManual['name'] = 'Manual output';
$existingManual['output'] = ['mode' => 'receiver_lld', 'receiver_auto' => false, 'receiver_host' => 'receiver', 'correlation_id' => 'application'];
$anchorConfig['rules'][] = $existingManual;
$autoDoc = $export;
$autoDoc['rules'][0]['name'] = 'New automatic alias';
$autoConflict = (new RuleTransfer($call))->prepare($autoDoc, $anchorConfig, 'skip');
check($autoConflict['rules'] === [] && count($autoConflict['preview']['conflicts']) === 1, 'Automatic drafts must resolve their existing receiver before checking a manual alias');
$fixedConfig = $itemConfig;
$fixedConfig['settings']['receiver_state_key_template'] = 'correlation.output';
$fixedConflict = (new RuleTransfer($call))->prepare($manualDoc, $fixedConfig, 'rename');
check(count($fixedConflict['preview']['errors']) === 1 && $fixedConflict['rules'] === [], 'A fixed item key must reject rename instead of looping forever');
$missingConfig = CorrelationStore::defaults();
$missingConfig['rules'] = [$existingManual];
$missingConfig['rules'][0]['output']['receiver_host'] = 'deleted-output-host';
$noConflict = (new RuleTransfer($call))->prepare($manualDoc, $missingConfig, 'skip');
check($noConflict['preview']['errors'] === [] && count($noConflict['rules']) === 1, 'An unrelated deleted old output must not block disabled import');
check(strpos($noConflict['preview']['warnings'][0], 'Review, enable and save') !== false, 'Import guidance must describe activation-time provisioning');
check(array_diff(array_unique($calls), ['host.get', 'hostgroup.get', 'trigger.get', 'item.get']) === [], 'Transfer must use read-only API methods');
$fullConfig = CorrelationStore::defaults();
$fullConfig['reserved_runtime'] = str_repeat('x', 58000);
$tooLarge = (new RuleTransfer($call))->prepare($export, $fullConfig);
check(count($tooLarge['preview']['errors']) > 0 && $tooLarge['preview']['storage']['bytes'] > $tooLarge['preview']['storage']['max_bytes'], 'Import preview must reject storage overflow before mutation');

$inspection = ['valid' => true, 'errors' => []];
$runtime = ['enabled' => true, 'last_state' => 4, 'last_evaluated' => 990, 'last_successful' => 990];
$status = DashboardData::runtimeStatus($runtime, $inspection, 0, 1000);
check($status['stored_state'] === 4 && $status['candidate_state'] === 0 && $status['status'] === 'awaiting_evaluation', 'Live observations must not overwrite applied state');
$status = DashboardData::runtimeStatus($runtime, ['valid' => false, 'errors' => ['token cannot see source']], null, 1000);
check($status['status'] === 'error' && $status['candidate_state'] === null && $status['stored_state'] === 4, 'Unknown coverage must retain applied state and report unknown candidate');
$status = DashboardData::runtimeStatus($runtime, $inspection, 4, 2000);
check($status['status'] === 'stale', 'Old evaluations must not appear healthy');
$status = DashboardData::runtimeStatus($runtime + ['pending_state' => 0, 'pending_since' => 980, 'recovery_seconds' => 60], $inspection, 0, 1000);
check($status['status'] === 'pending_recovery' && $status['pending_seconds'] === 40, 'Pending recovery must show the correct remaining duration');
$runtime['enabled'] = false;
$status = DashboardData::runtimeStatus($runtime, $inspection, 0, 1000);
check($status['status'] === 'disabled' && $status['stored_state'] === 4, 'Disabled rules may still have an applied result awaiting cleanup');
$status = DashboardData::runtimeStatus(['enabled' => true], $inspection, 4, 1000);
check($status['status'] === 'never', 'Unrun rules must be identified');
$status = DashboardData::runtimeStatus(['enabled' => true, 'last_state' => 1, 'last_evaluated' => 990, 'manual_override_count' => 1], $inspection, 1, 1000);
check($status['status'] === 'manual_override', 'Operator overrides must not appear as ordinary applied escalation');

echo 'OperationalSupportTest: '.$assertions." assertions passed\n";
