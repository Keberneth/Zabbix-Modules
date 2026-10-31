<?php
declare(strict_types=1);

/** Disposable offline API/DB facade exercising the real evaluator and store. */
use Modules\TriggerCorrelation\Lib\CorrelationStore;
use Modules\TriggerCorrelation\Lib\SeverityEvaluator;
use Modules\TriggerCorrelation\Lib\ZabbixApiClient;

require_once dirname(__DIR__).'/lib/SeverityEvaluator.php';

$DB = ['TYPE' => 'MYSQL', 'TRANSACTIONS' => 0];
$config = [];
$events = [];
$sourceActive = [];
$hiddenTriggers = [];
$hiddenEvents = [];
$hiddenGroups = [];
$failWrite = false;
$failPersist = false;
$failAfterWrite = false;
$failTargetQuery = false;
$oversized = false;
$writes = [];
$lock = false;
$transactionCopy = [];

function zbx_dbstr(string $value): string { return "'".$value."'"; }
function DBselect(string $sql) {
    global $config, $lock;
    if (str_contains($sql, 'GET_LOCK')) {
        $acquired = !$lock; $lock = true;
        return (object) ['row' => ['acquired' => $acquired ? 1 : 0]];
    }
    if (str_contains($sql, 'RELEASE_LOCK')) { $lock = false; return (object) ['row' => []]; }
    return (object) ['row' => ['moduleid' => '1', 'id' => 'trigger_correlation', 'relative_path' => 'modules/TriggerCorrelation',
        'status' => '1', 'config' => json_encode($config)]];
}
function DBfetch($cursor) { $row = $cursor->row; $cursor->row = false; return $row; }
function DBstart(): void { global $DB, $config, $transactionCopy; $transactionCopy = $config; $DB['TRANSACTIONS'] = 1; }
function DBend(bool $commit): bool { global $DB, $config, $transactionCopy; if (!$commit) { $config = $transactionCopy; } $DB['TRANSACTIONS'] = 0; return true; }

class DB {
    public static function update(string $table, array $rows): void {
        global $failPersist, $config;
        if ($failPersist) { throw new RuntimeException('Injected storage failure'); }
        $config = json_decode($rows[0]['values']['config'], true);
    }
}
class API {
    public static function __callStatic($name, $arguments) { return new FakeService(strtolower($name)); }
}
class FakeService {
    public function __construct(private string $service) {}
    public function update(array $params) {
        global $config, $failPersist;
        if ($failPersist) { throw new RuntimeException('Injected storage failure'); }
        $config = $params[0]['config'];
        return ['moduleids' => ['1']];
    }
    public function get(array $params): array {
        global $events, $sourceActive, $hiddenTriggers, $hiddenEvents, $hiddenGroups, $failTargetQuery, $oversized;
        if ($this->service === 'trigger') {
            $rows = [];
            foreach ($params['triggerids'] ?? [] as $id) {
                if (isset($hiddenTriggers[$id])) { continue; }
                $rows[] = ['triggerid' => (string) $id, 'description' => 'Trigger '.$id, 'status' => '0', 'state' => '0',
                    'hosts' => [['hostid' => '1', 'host' => 'host', 'name' => 'host', 'status' => '0']]];
            }
            return $rows;
        }
        if ($this->service === 'event') {
            $rows = [];
            foreach ($params['eventids'] as $id) { if (isset($events[$id]) && !isset($hiddenEvents[$id])) { $rows[] = $events[$id]; } }
            return $rows;
        }
        if ($this->service === 'hostgroup') {
            return array_values(array_map(static fn($id): array => ['groupid' => (string) $id],
                array_filter($params['groupids'], static fn($id): bool => !isset($hiddenGroups[$id]))));
        }
        if ($this->service === 'host') {
            return array_map(static fn($id): array => ['hostid' => (string) $id], $params['hostids']);
        }
        if ($this->service === 'problem') {
            $ids = $params['objectids'] ?? [];
            $isTarget = in_array('21', $ids, true) || in_array('22', $ids, true);
            if ($isTarget && $failTargetQuery) { throw new RuntimeException('Injected target query failure'); }
            if ($isTarget && $oversized) { return array_fill(0, 1001, ['eventid' => '999', 'objectid' => '21', 'severity' => 2, 'name' => 'target']); }
            $rows = [];
            foreach ($ids as $id) {
                if (!empty($sourceActive[$id])) {
                    $rows[] = ['eventid' => 'source'.$id, 'objectid' => (string) $id, 'clock' => time() - 600,
                        'severity' => '2', 'name' => 'source', 'suppressed' => '0', 'cause_eventid' => '0'];
                }
                foreach ($events as $event) {
                    if ((string) $event['objectid'] !== (string) $id || $event['r_eventid'] !== '0') { continue; }
                    if (isset($params['suppressed']) && $params['suppressed'] === false && !empty($event['suppressed'])) { continue; }
                    $rows[] = $event;
                }
            }
            return $rows;
        }
        throw new RuntimeException('Unexpected API '.$this->service.'.get');
    }
    public function acknowledge(array $params): array {
        global $events, $writes, $config, $failWrite, $failAfterWrite, $failPersist, $DB;
        $id = (string) $params['eventids'][0];
        if (($params['action'] & 8) === 0) { return ['eventids' => [$id]]; }
        $pending = $config['severity_ledger']['events'][$id]['pending'] ?? [];
        check($pending !== [] && (int) $pending['to'] === (int) $params['severity'], 'API change has durable write-ahead intent');
        check(empty($DB['TRANSACTIONS']), 'API change occurs outside DB transaction');
        if ($failWrite) { throw new RuntimeException('Injected severity write failure'); }
        $events[$id]['severity'] = (int) $params['severity'];
        $writes[] = [$id, (int) $params['severity']];
        if ($failAfterWrite) { $failPersist = true; }
        return ['eventids' => [$id]];
    }
}

$assertions = 0;
function check(bool $pass, string $label): void { global $assertions; $assertions++; if (!$pass) { throw new RuntimeException('FAIL: '.$label); } }
function rule(string $id = 'A', string $source = '11', int $severity = 4): array {
    return ['id' => $id, 'name' => 'Rule '.$id, 'enabled' => true, 'conditions' => [['hostid' => '1', 'host' => 'host', 'triggerid' => $source, 'trigger' => 'source']],
        'targets' => [['scope' => 'host', 'hostid' => '1', 'host' => 'host', 'triggerid' => '21', 'trigger' => 'target']],
        'match_mode' => 'all', 'severity' => $severity, 'only_raise' => true, 'comment_source' => false];
}
function resetState(array $rules = []): SeverityEvaluator {
    global $config, $events, $sourceActive, $hiddenTriggers, $hiddenEvents, $hiddenGroups, $failWrite, $failPersist,
        $failAfterWrite, $failTargetQuery, $oversized, $writes, $lock, $DB;
    $config = ['storage_initialized' => true, 'settings' => ['ignore_suppressed' => true], 'rules' => [], 'severity_rules' => $rules ?: [rule()]];
    $events = ['100' => ['eventid' => '100', 'objectid' => '21', 'name' => 'target', 'severity' => 2, 'r_eventid' => '0', 'suppressed' => 0]];
    $sourceActive = ['11' => true, '12' => true]; $hiddenTriggers = []; $hiddenEvents = []; $hiddenGroups = [];
    $failWrite = $failPersist = $failAfterWrite = $failTargetQuery = $oversized = $lock = false; $writes = []; $DB['TRANSACTIONS'] = 0;
    return new SeverityEvaluator(new CorrelationStore(), new ZabbixApiClient('', '', 'auto', true, 15, 'frontend'));
}

$evaluator = resetState();
$before = $config;
$preview = $evaluator->preview(rule());
check($preview['valid'] && $preview['targets_count'] === 1, 'preview reports target');
check($config === $before && !$writes && $events['100']['severity'] === 2, 'preview has no writes');

$evaluator->evaluate();
check($events['100']['severity'] === 4, 'initial escalation');
$sourceActive['11'] = false; $failWrite = true;
$result = $evaluator->evaluate();
check(!empty($config['severity_ledger']['events']['100']['pending']), 'failed restore intent retained');
check($result['rules'][0]['error'] !== '', 'restore failure visible');
$result = $evaluator->evaluate();
check($result['rules'][0]['error'] !== '' && !empty($config['severity_rules'][0]['applied']['100']['pending_restore']), 'repeated failed restoration stays visible and retryable');
$failWrite = false; $evaluator->evaluate();
check($events['100']['severity'] === 2 && !$config['severity_ledger']['events'], 'failed restore retried next cycle');

$evaluator = resetState([rule('A', '11', 4), rule('B', '12', 5)]);
$evaluator->evaluate();
check($events['100']['severity'] === 5, 'overlapping rules choose highest contribution');
$lowerDraft = $config['severity_rules'][0]; $lowerDraft['only_raise'] = false;
$preview = $evaluator->preview($lowerDraft);
check($preview['targets'][0]['effective_severity'] === 5 && !$preview['targets'][0]['would_change'], 'preview respects other rule contribution');
$sourceActive['12'] = false; $evaluator->evaluate();
check($events['100']['severity'] === 4, 'one rule clears without restoring below other contribution');
$sourceActive['11'] = false; $evaluator->evaluate();
check($events['100']['severity'] === 2, 'last owner restores single baseline');

$evaluator = resetState(); $evaluator->evaluate();
$events['100']['severity'] = 3; $evaluator->evaluate();
check($events['100']['severity'] === 3 && $config['severity_ledger']['events']['100']['manual_override'], 'operator override retained');
check($config['severity_rules'][0]['applied']['100']['manual_override'] && $config['severity_rules'][0]['applied']['100']['to'] === 3, 'dashboard receives current manual severity and flag');
$sourceActive['11'] = false; $evaluator->evaluate();
check($events['100']['severity'] === 3 && !$config['severity_ledger']['events'], 'clearing owners does not undo manual override');

$evaluator = resetState(); $evaluator->evaluate();
$offDraft = $config['severity_rules'][0]; $offDraft['enabled'] = false;
$preview = $evaluator->preview($offDraft);
check($preview['restorations_count'] === 1 && $preview['restorations'][0]['effective_severity'] === 2, 'disable preview explains restoration');
$config['severity_rules'][0]['targets'][0]['triggerid'] = '22';
$evaluator->evaluate();
check($events['100']['severity'] === 2, 'retargeting restores former target');

$evaluator = resetState(); $evaluator->evaluate(); $events['100']['suppressed'] = 1;
$evaluator->evaluate();
check($events['100']['severity'] === 2, 'excluded suppressed target is restored');

$evaluator = resetState(); $evaluator->evaluate(); $failTargetQuery = true;
$result = $evaluator->evaluate();
check($events['100']['severity'] === 4 && isset($config['severity_ledger']['events']['100']['owners']['A']), 'target query failure retains ownership');
check($result['rules'][0]['error'] !== '', 'target query failure visible');
$failTargetQuery = false; $sourceActive['11'] = false; $evaluator->evaluate();
check($events['100']['severity'] === 2, 'recovery after target query failure');

$evaluator = resetState(); $hiddenTriggers['21'] = true;
$result = $evaluator->evaluate();
check(!$writes && str_contains($result['rules'][0]['error'], 'target trigger is unavailable'), 'invisible new target is unknown instead of zero matches');
$evaluator = resetState(); $events = []; $result = $evaluator->evaluate();
check(!$writes && $result['rules'][0]['error'] === '' && $result['rules'][0]['targets_count'] === 0, 'visible target with no open events is a valid zero');
$wrongHostRule = rule(); $wrongHostRule['targets'][0]['hostid'] = '2';
$evaluator = resetState([$wrongHostRule]); $result = $evaluator->evaluate();
check(!$writes && str_contains($result['rules'][0]['error'], 'selected host'), 'target trigger must belong to selected host');
$evaluator = resetState(); $evaluator->evaluate(); $hiddenTriggers['21'] = true;
$result = $evaluator->evaluate();
check($events['100']['severity'] === 4 && isset($config['severity_ledger']['events']['100']['owners']['A'])
    && $result['rules'][0]['error'] !== '', 'lost target visibility retains existing owner and severity');
$sourceActive['11'] = false; $evaluator->evaluate();
check($events['100']['severity'] === 2, 'clear rule can restore a visible event despite unavailable target selector');
$groupRule = rule(); $groupRule['targets'] = [['scope' => 'hostgroup', 'groupid' => '5', 'trigger' => 'target', 'match' => 'exact']];
$evaluator = resetState([$groupRule]); $hiddenGroups['5'] = true;
$result = $evaluator->evaluate();
check(!$writes && str_contains($result['rules'][0]['error'], 'target host group is unavailable'), 'invisible group target is unknown');

$evaluator = resetState(); $failPersist = true;
try { $evaluator->evaluate(); check(false, 'storage failure must throw'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'storage failure'), 'storage failure reported'); }
check(!$writes && $events['100']['severity'] === 2, 'storage failure prevents first API effect');

$evaluator = resetState(); $failAfterWrite = true;
try { $evaluator->evaluate(); check(false, 'post-write commit failure must throw'); } catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'storage failure'), 'post-write failure reported'); }
check($events['100']['severity'] === 4 && isset($config['severity_ledger']['events']['100']['pending']), 'successful API write retains earlier durable intent');
$failAfterWrite = $failPersist = false; $sourceActive['11'] = false; $evaluator->evaluate();
check($events['100']['severity'] === 2, 'recovery after failed post-write commit');

$evaluator = resetState(); $oversized = true; $result = $evaluator->evaluate();
check(!$writes && str_contains($result['rules'][0]['error'], '1000'), 'oversized target refuses partial escalation');

$evaluator = resetState(); $evaluator->evaluate(); $hiddenTriggers['11'] = true;
$result = $evaluator->evaluate();
check($events['100']['severity'] === 4 && $result['rules'][0]['error'] !== '', 'invisible source is unknown and preserves escalation');

$evaluator = resetState(); $evaluator->evaluate(); $sourceActive['11'] = false; $hiddenEvents['100'] = true;
$result = $evaluator->evaluate();
check($events['100']['severity'] === 4 && $result['rules'][0]['error'] !== '', 'invisible event is not assumed resolved');
$hiddenEvents = []; $evaluator->evaluate(); check($events['100']['severity'] === 2, 'restores after event visibility returns');

$evaluator = resetState(); $evaluator->evaluate(); $failWrite = true;
$result = $evaluator->deleteRule('A');
check($result['pending'] && count($config['severity_rules']) === 1 && !$config['severity_rules'][0]['enabled'], 'delete failure leaves disabled retryable rule');
$failWrite = false; $evaluator->evaluate();
check($events['100']['severity'] === 2 && !$config['severity_rules'], 'heartbeat finishes pending deletion');

$legacy = rule(); $legacy['applied'] = ['100' => ['sev' => 2, 'to' => 4, 'host' => 'host', 'name' => 'target']];
$evaluator = resetState([$legacy]); $events['100']['severity'] = 4; $sourceActive['11'] = false;
$evaluator->evaluate();
check($events['100']['severity'] === 2 && $config['severity_ledger']['version'] === 1, 'legacy baseline migrated before recovery');

$legacyA = rule('A', '11', 2); $legacyA['only_raise'] = false;
$legacyA['applied'] = ['100' => ['sev' => 4, 'to' => 2, 'host' => 'host', 'name' => 'target']];
$legacyB = rule('B', '12', 3); $legacyB['applied'] = ['100' => ['sev' => 2, 'to' => 3, 'host' => 'host', 'name' => 'target']];
$evaluator = resetState([$legacyA, $legacyB]); $events['100']['severity'] = 3;
$events['100']['acknowledges'] = [['action' => 12, 'message' => '[TC severity] Raised by B', 'old_severity' => 2, 'new_severity' => 3],
    ['action' => 12, 'message' => '[TC severity] Raised by A', 'old_severity' => 4, 'new_severity' => 2]];
$sourceActive = []; $evaluator->evaluate();
check($events['100']['severity'] === 4, 'legacy lowering overlap recovers original baseline from event history');
$evaluator = resetState([$legacyA, $legacyB]); $events['100']['severity'] = 3; $sourceActive = [];
$result = $evaluator->evaluate();
check(!$writes && str_contains($result['rules'][0]['error'], 'conflicting legacy'), 'ambiguous legacy baseline is not guessed');

$evaluator = resetState(); $lock = true;
try { $evaluator->evaluate(); check(false, 'concurrent run must reject'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Another severity'), 'concurrent run blocked by shared lock'); }
check(!$writes, 'concurrent run produces no event effects');

echo 'Severity reliability: '.$assertions." assertions passed.\n";
