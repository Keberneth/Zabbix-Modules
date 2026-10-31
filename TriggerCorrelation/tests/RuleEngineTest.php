<?php
declare(strict_types=1);

use Modules\TriggerCorrelation\Lib\CorrelationEvaluator;
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\ReceiverProvisioner;
use Modules\TriggerCorrelation\Lib\RuleDefinition;
use Modules\TriggerCorrelation\Lib\RuleEngine;
use Modules\TriggerCorrelation\Lib\ZabbixApiClient;

require_once dirname(__DIR__).'/lib/CorrelationEvaluator.php';
require_once dirname(__DIR__).'/lib/ReceiverProvisioner.php';

$DB = ['TYPE' => 'MYSQL', 'TRANSACTIONS' => 0];
$config = []; $triggers = []; $problems = []; $pushes = []; $groupRows = []; $groupVisible = true;
$pushFailure = false; $lock = false; $transactionCopy = []; $overrides = []; $overrideUpdates = [];
$informationPrototypes = []; $prototypeCreates = [];
$assertions = 0;
function check(bool $pass, string $label): void { global $assertions; $assertions++; if (!$pass) { throw new RuntimeException('FAIL: '.$label); } }
function rejects(callable $fn, string $text): void {
    try { $fn(); check(false, 'must reject '.$text); }
    catch (InvalidArgumentException $e) { check(str_contains($e->getMessage(), $text), 'validation: '.$text); }
}
function zbx_dbstr(string $value): string { return "'".$value."'"; }
function DBselect(string $sql) {
    global $config, $lock;
    if (str_contains($sql, 'GET_LOCK')) { $got = !$lock; $lock = true; return (object) ['row' => ['acquired' => $got ? 1 : 0]]; }
    if (str_contains($sql, 'RELEASE_LOCK')) { $lock = false; return (object) ['row' => []]; }
    return (object) ['row' => ['moduleid' => '1', 'id' => 'trigger_correlation', 'relative_path' => 'modules/TriggerCorrelation',
        'status' => '1', 'config' => json_encode($config)]];
}
function DBfetch($cursor) { $row = $cursor->row; $cursor->row = false; return $row; }
function DBstart(): void { global $DB, $config, $transactionCopy; $transactionCopy = $config; $DB['TRANSACTIONS'] = 1; }
function DBend(bool $commit): bool { global $DB, $config, $transactionCopy; if (!$commit) { $config = $transactionCopy; } $DB['TRANSACTIONS'] = 0; return true; }
class DB { public static function update(string $table, array $rows): void { global $config; $config = json_decode($rows[0]['values']['config'], true); } }
class API { public static function __callStatic($name, $arguments) { return new TestApi(strtolower($name)); } }
class TestApi {
    public function __construct(private string $service) {}
    public function get(array $params): array {
        global $triggers, $problems, $groupRows, $groupVisible, $overrides, $informationPrototypes;
        if ($this->service === 'trigger') {
            if (isset($params['groupids'])) { return $groupRows; }
            return array_values(array_intersect_key($triggers, array_fill_keys($params['triggerids'] ?? [], true)));
        }
        if ($this->service === 'problem') {
            return array_values(array_filter($problems, static fn(array $p): bool => in_array($p['objectid'], $params['objectids'] ?? [], true)));
        }
        if ($this->service === 'hostgroup') { return $groupVisible ? [['groupid' => '5']] : []; }
        if ($this->service === 'host') { return []; }
        if ($this->service === 'template') { return [['templateid' => '900', 'host' => 'Renamed receiver template']]; }
        if ($this->service === 'discoveryrule') { return [['itemid' => '901', 'overrides' => $overrides]]; }
        if ($this->service === 'triggerprototype') { return $informationPrototypes; }
        throw new RuntimeException('Unexpected API '.$this->service.'.get');
    }
    public function create(array $params): array {
        global $informationPrototypes, $prototypeCreates;
        if ($this->service !== 'triggerprototype') { throw new RuntimeException('Unexpected API '.$this->service.'.create'); }
        $prototypeCreates[] = $params;
        $informationPrototypes[] = ['triggerid' => '902'] + $params[0];
        return ['triggerids' => ['902']];
    }
    public function update(array $params): array {
        global $config, $overrides, $overrideUpdates;
        if ($this->service === 'module') { $config = $params[0]['config']; return ['moduleids' => ['1']]; }
        if ($this->service === 'discoveryrule') { $overrides = $params[0]['overrides']; $overrideUpdates[] = $params; return ['itemids' => ['901']]; }
        throw new RuntimeException('Unexpected API '.$this->service.'.update');
    }
    public function push(array $params): array {
        global $pushes, $pushFailure;
        $pushes[] = $params;
        return ['response' => 'success', 'data' => array_map(static fn(array $row): array => $pushFailure
            ? ['itemid' => $row['itemid'] ?? '999', 'error' => 'Injected history failure'] : ['itemid' => $row['itemid'] ?? '999'], $params)];
    }
}
function trigger(string $id, string $hostid): array {
    return ['triggerid' => $id, 'description' => 'Node unavailable', 'status' => '0', 'state' => '0',
        'items' => [['itemid' => 'item'.$id, 'status' => '0']],
        'hosts' => [['hostid' => $hostid, 'host' => 'host'.$hostid, 'name' => 'Host '.$hostid, 'status' => '0']]];
}
function rule(): array {
    return ['id' => 'R', 'name' => 'Cluster', 'enabled' => true, 'conditions' => [
        ['hostid' => '1', 'host' => 'host1', 'triggerid' => '11', 'trigger' => 'Node unavailable'],
        ['hostid' => '2', 'host' => 'host2', 'triggerid' => '12', 'trigger' => 'Node unavailable']],
        'output' => ['mode' => 'existing_item', 'itemid' => '999', 'match_mode' => 'all', 'match_value' => 4,
            'comment_correlation_problem' => false, 'comment_source_problems' => false]];
}
function resetState(): ZabbixApiClient {
    global $config, $triggers, $problems, $pushes, $groupRows, $groupVisible, $pushFailure, $lock;
    $config = ['storage_initialized' => true, 'settings' => ['push_discovery_every_eval' => false, 'ignore_suppressed' => true, 'ignore_symptoms' => true],
        'rules' => [rule()], 'severity_rules' => []];
    $triggers = ['11' => trigger('11', '1'), '12' => trigger('12', '2')];
    $problems = [['eventid' => '101', 'objectid' => '11', 'name' => 'Node unavailable', 'clock' => time() - 600, 'severity' => '2', 'suppressed' => '0', 'cause_eventid' => '0'],
        ['eventid' => '102', 'objectid' => '12', 'name' => 'Node unavailable', 'clock' => time() - 600, 'severity' => '2', 'suppressed' => '0', 'cause_eventid' => '0']];
    $pushes = []; $groupRows = array_values($triggers); $groupVisible = true; $pushFailure = $lock = false;
    return new ZabbixApiClient('', '', 'auto', true, 15, 'frontend');
}

$api = resetState(); $store = new CorrelationStore();
$evaluator = new CorrelationEvaluator($store, $api);
$before = $config; $preview = $evaluator->preview(rule());
check($preview['state'] === 4 && $preview['active_count'] === 2, 'preview uses both active sources');
check(!$pushes && $before === $config, 'preview performs no writes');
$result = $evaluator->evaluate();
check($result['rules'][0]['state'] === 4 && $config['rules'][0]['last_state'] === 4, 'successful push advances applied state');
$problems = []; $pushFailure = true; $result = $evaluator->evaluate();
check($result['rules'][0]['state'] === 4 && $config['rules'][0]['last_state'] === 4, 'failed clear push retains applied state');
check($result['rules'][0]['candidate_state'] === 0 && $result['rules'][0]['status'] === 'error', 'failed push exposes candidate and failure');

$api = resetState(); $config['rules'][0]['last_state'] = 4; unset($triggers['11']);
$result = (new CorrelationEvaluator($store, $api))->evaluate();
check($result['rules'][0]['state'] === 4 && $result['rules'][0]['status'] === 'unknown' && !$pushes, 'missing source holds last state without writes');
$api = resetState(); $config['rules'][0]['last_state'] = 4; $triggers['11']['items'][0]['status'] = '1';
$result = (new CorrelationEvaluator($store, $api))->evaluate();
check($result['rules'][0]['state'] === 4 && $result['rules'][0]['status'] === 'unknown' && !$pushes, 'disabled item makes source unavailable');
$api = resetState(); $config['rules'][0]['last_state'] = 4; $config['rules'][0]['enabled'] = false; unset($triggers['11']);
$before = $config; $preview = (new CorrelationEvaluator($store, $api))->preview($config['rules'][0]);
check($preview['state'] === 0 && $preview['status'] === 'disabled', 'disabled preview predicts clear despite missing sources');
check(!$pushes && $before === $config, 'disabled preview leaves the applied state unchanged');
$result = (new CorrelationEvaluator($store, $api))->evaluate();
check($result['rules'][0]['state'] === 0 && count($pushes) === 1, 'explicit disable clears despite missing sources');

$api = resetState(); $config['settings']['clear_disabled_rules'] = false;
$config['rules'][0]['enabled'] = false; $config['rules'][0]['last_state'] = 4;
$evaluator = new CorrelationEvaluator($store, $api); $preview = $evaluator->preview($config['rules'][0]);
$result = $evaluator->evaluate();
check($preview['state'] === 4 && $result['rules'][0]['state'] === 4 && !$pushes, 'disabled preview and run honor retained-output setting');
$api = resetState(); $unprovisioned = rule(); $unprovisioned['enabled'] = false; $unprovisioned['last_state'] = 4;
$unprovisioned['output'] = ['mode' => 'receiver_lld', 'receiver_auto' => true, 'match_value' => 4];
check((new CorrelationEvaluator($store, $api))->preview($unprovisioned)['state'] === 4, 'disabled unprovisioned output preview predicts no write');

$api = resetState(); $problems[0]['clock'] = time() - 10;
$inspection = (new RuleEngine($api, ['min_active_seconds' => 60]))->inspectSources(rule());
check($inspection['valid'] && $inspection['active_count'] === 1, 'global minimum age filters only young problem');
check(str_contains($inspection['results'][0]['reason'], 'minimum source problem age'), 'age exclusion reason visible');
$problems[0]['clock'] = time() - 600; $problems[0]['suppressed'] = '1'; $problems[1]['cause_eventid'] = '777';
$inspection = (new RuleEngine($api, ['ignore_suppressed' => true, 'ignore_symptoms' => true]))->inspectSources(rule());
check($inspection['active_count'] === 0 && $inspection['results'][0]['reason'] === 'Suppressed'
    && $inspection['results'][1]['reason'] === 'Symptom excluded', 'suppression and symptom reasons distinct');

$api = resetState(); $dynamic = rule();
$dynamic['source_selector'] = ['mode' => 'group', 'groupid' => '5', 'trigger_name' => 'Node unavailable'];
$inspection = (new RuleEngine($api))->inspectSources($dynamic);
check($inspection['valid'] && $inspection['total'] === 2, 'dynamic source group expansion');
$groupRows = []; $inspection = (new RuleEngine($api))->inspectSources($dynamic);
check(!$inspection['valid'] && str_contains(implode(' ', $inspection['errors']), 'No visible triggers'), 'zero dynamic members is unknown');
$groupRows = [trigger('11', '1'), trigger('12', '1')]; $inspection = (new RuleEngine($api))->inspectSources($dynamic);
check(!$inspection['valid'] && str_contains(implode(' ', $inspection['errors']), 'More than one'), 'ambiguous group trigger is rejected');
$groupRows = []; for ($i = 1; $i <= 101; $i++) { $groupRows[] = trigger((string) (1000 + $i), (string) $i); }
$inspection = (new RuleEngine($api))->inspectSources($dynamic);
check(!$inspection['valid'] && str_contains(implode(' ', $inspection['errors']), '100 triggers'), '101 members refuses partial selection');
$groupVisible = false; $inspection = (new RuleEngine($api))->inspectSources($dynamic);
check(!$inspection['valid'] && str_contains(implode(' ', $inspection['errors']), 'cannot see'), 'hidden group is unknown');

$timed = ['last_state' => 0, 'activation_seconds' => 60, 'recovery_seconds' => 120];
$first = RuleEngine::timedState($timed, 4, 1000);
check($first['state'] === 0 && $first['pending_seconds'] === 60 && $first['status'] === 'pending_activation', 'activation starts pending');
$timed = array_merge($timed, ['pending_state' => 4, 'pending_since' => 1000, 'last_evaluated' => 1000]);
check(RuleEngine::timedState($timed, 4, 1059)['state'] === 0 && RuleEngine::timedState($timed, 4, 1060)['state'] === 4, 'activation boundary exact');
check(RuleEngine::timedState($timed, 0, 1030)['pending_since'] === 0, 'condition reversal cancels pending activation');
$down = ['last_state' => 5, 'activation_seconds' => 10, 'recovery_seconds' => 120];
$recovery = RuleEngine::timedState($down, 4, 1000);
check($recovery['state'] === 5 && $recovery['pending_seconds'] === 120 && $recovery['status'] === 'pending_recovery', 'downward tier uses recovery delay');
$down['pending_state'] = 0; $down['pending_since'] = 1000; $down['last_evaluated'] = 1060;
check(RuleEngine::timedState($down, 0, 1120)['state'] === 0, 'recovery completes after stable interval');
$outage = RuleEngine::timedState($timed, 4, 1300);
check($outage['state'] === 0 && $outage['pending_since'] === 1300, 'outage restarts pending timer');
$timed['source_signature'] = 'old'; $timed['_source_signature'] = 'new';
check(RuleEngine::timedState($timed, 4, 1060)['pending_since'] === 1060, 'membership changes reset pending timer');
$down['enabled'] = false;
check(RuleEngine::timedState($down, 4, 1060)['state'] === 0, 'explicit disable ignores delay');

$definition = rule(); $definition['conditions'][] = $definition['conditions'][0];
check(count(RuleDefinition::normalize($definition)['conditions']) === 2, 'duplicate source normalization');
$definition['conditions'] = [$definition['conditions'][0], $definition['conditions'][0]];
rejects(static fn() => RuleDefinition::normalize($definition), 'distinct');
$definition = rule(); $definition['output']['match_mode'] = 'count'; $definition['output']['severity_tiers'] = [['min' => 3, 'value' => 5]];
rejects(static fn() => RuleDefinition::normalize($definition), 'reachable');
$definition['output']['severity_tiers'] = [['min' => 1, 'value' => 4], ['min' => 1, 'value' => 5]];
rejects(static fn() => RuleDefinition::normalize($definition), 'one severity tier');
$dynamic['conditions'] = [['hostid' => '1', 'triggerid' => '']];
check(RuleDefinition::normalize($dynamic)['conditions'] === [], 'dynamic mode ignores incomplete hidden static fields');
$definition = rule(); $definition['activation_seconds'] = 86401;
rejects(static fn() => RuleDefinition::normalize($definition), 'Timing');
check(RuleDefinition::selectedSeverities(['match_mode' => 'count', 'severity_tiers' => [['value' => 5], ['value' => 4], ['value' => 5]]]) === [4, 5], 'selected severities sorted and unique');

$api = resetState(); $config['settings']['push_discovery_every_eval'] = true;
$config['rules'][0]['output'] = ['mode' => 'receiver_lld', 'receiver_host' => 'receiver', 'correlation_id' => 'cluster',
    'match_mode' => 'count', 'severity_tiers' => [['min' => 1, 'value' => 4], ['min' => 2, 'value' => 5]],
    'comment_correlation_problem' => false, 'comment_source_problems' => false];
(new CorrelationEvaluator($store, $api))->evaluate();
$lld = json_decode($pushes[0][0]['value'], true);
check($lld['data'][0]['{#CORRELATION.SEVERITIES}'] === '4,5', 'LLD carries exactly selected severity levels');
$config['rules'][0]['output']['match_mode'] = 'all'; $config['rules'][0]['output']['match_value'] = 1; $pushes = [];
(new CorrelationEvaluator($store, $api))->evaluate();
check(json_decode($pushes[0][0]['value'], true)['data'][0]['{#CORRELATION.SEVERITIES}'] === '1', 'LLD includes Information without unrelated levels');

$api = resetState();
$custom = ['name' => 'Customer override', 'step' => '1', 'stop' => '1', 'lld_overrideid' => '800',
    'filter' => ['evaltype' => '0', 'formula' => '', 'eval_formula' => 'A', 'conditions' => [
        ['macro' => '{#CORRELATION.ID}', 'operator' => '8', 'value' => '^critical', 'formulaid' => 'A', 'lld_override_conditionid' => '801']]],
    'operations' => [['operationobject' => '1', 'operator' => '8', 'value' => '.*', 'opstatus' => ['status' => '1'], 'lld_override_operationid' => '802']]];
$overrides = [$custom]; $overrideUpdates = [];
$informationPrototypes = []; $prototypeCreates = [];
(new ReceiverProvisioner($api, []))->ensureTemplate(ReceiverProvisioner::AUTO_TEMPLATE);
check(count($overrideUpdates) === 1 && count($overrides) === 6, 'legacy LLD receives severity selection upgrade');
check(count($prototypeCreates) === 1 && $prototypeCreates[0][0]['priority'] === 1
    && str_contains($prototypeCreates[0][0]['expression'], '/Renamed receiver template/'), 'old template receives missing Information prototype using current template name');
$kept = $overrides[5];
check($kept['name'] === $custom['name'] && $kept['stop'] === $custom['stop']
    && $kept['filter']['conditions'][0]['value'] === '^critical' && $kept['operations'][0]['opstatus']['status'] === '1', 'customer override semantics preserved');
check(!isset($kept['lld_overrideid']) && !isset($kept['filter']['eval_formula'])
    && !isset($kept['operations'][0]['lld_override_operationid']), 'read-only API override fields removed before update');
(new ReceiverProvisioner($api, []))->ensureTemplate(ReceiverProvisioner::AUTO_TEMPLATE);
check(count($overrideUpdates) === 1, 'severity override migration idempotent across requests');
check(count($prototypeCreates) === 1, 'existing Information prototype is not duplicated');

echo 'Rule engine: '.$assertions." assertions passed.\n";
