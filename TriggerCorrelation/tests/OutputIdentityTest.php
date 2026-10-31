<?php

declare(strict_types=1);

require_once dirname(__DIR__).'/lib/OutputIdentity.php';
use Modules\TriggerCorrelation\Lib\OutputIdentity;

$assertions = 0;
function expect(bool $condition, string $message): void {
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}
function expectError(callable $fn, string $message): void {
    try { $fn(); }
    catch (InvalidArgumentException $e) { expect(true, $message); return; }
    throw new RuntimeException($message);
}

$hosts = [
    ['hostid' => '51', 'host' => 'renamed-auto-receiver', 'status' => '0'],
    ['hostid' => '52', 'host' => 'other-receiver', 'status' => '0'],
    ['hostid' => '53', 'host' => 'disabled-receiver', 'status' => '1']
];
$items = [
    ['itemid' => '61', 'hostid' => '51', 'key_' => 'trigger.correlation.state[incident]', 'type' => '2', 'value_type' => '3', 'status' => '0'],
    ['itemid' => '62', 'hostid' => '51', 'key_' => 'custom.state[incident]', 'type' => '2', 'value_type' => '3', 'status' => '0'],
    ['itemid' => '63', 'hostid' => '51', 'key_' => 'not.a.trapper', 'type' => '0', 'value_type' => '3', 'status' => '0'],
    ['itemid' => '64', 'hostid' => '53', 'key_' => 'state', 'type' => '2', 'value_type' => '3', 'status' => '0']
];
$call = static function (string $method, array $params) use ($hosts, $items): array {
    if ($method === 'host.get') {
        return array_values(array_filter($hosts, static fn(array $h): bool => isset($params['hostids'])
            ? in_array($h['hostid'], $params['hostids'], true) : $h['host'] === $params['filter']['host']));
    }
    if ($method === 'item.get') {
        return array_values(array_filter($items, static fn(array $i): bool => isset($params['itemids'])
            ? in_array($i['itemid'], $params['itemids'], true)
            : in_array($i['hostid'], $params['hostids'], true) && $i['key_'] === $params['filter']['key_']));
    }
    throw new RuntimeException('Unexpected API operation '.$method);
};
$identity = new OutputIdentity($call);
$auto = ['id' => 'auto', 'name' => 'Automatic', 'enabled' => true, 'conditions' => [['hostid' => '11'], ['hostid' => '12']],
    'output' => ['mode' => 'receiver_lld', 'receiver_auto' => true, 'receiver_hostid' => '51', 'receiver_host' => 'old-name', 'correlation_id' => 'incident']];
$manual = ['id' => 'manual', 'name' => 'Manual', 'enabled' => true,
    'output' => ['mode' => 'receiver_lld', 'receiver_host' => 'renamed-auto-receiver', 'receiver_hostid' => 'bogus-stale-id', 'correlation_id' => 'incident']];
$item = ['id' => 'item', 'name' => 'Existing item', 'enabled' => true,
    'output' => ['mode' => 'existing_item', 'itemid' => '61', 'host' => 'stale-host', 'key' => 'stale-key']];

expect($identity->findConflict($auto, [$manual])['id'] === 'manual', 'Automatic host id and manual technical name must collide');
expect($identity->findConflict($manual, [$auto])['id'] === 'auto', 'Manual output must collide with a renamed automatic host');
expect($identity->findConflict($item, [$auto])['id'] === 'auto', 'Existing item id must collide with the corresponding automatic state item');
expect($identity->findConflict($auto, [$item])['id'] === 'item', 'Automatic mode must detect an existing-item alias');
expect($identity->findConflict($manual, [$item])['id'] === 'item', 'Manual receiver and existing item must share physical identity');
expect($identity->findConflict($item, [$item]) === null, 'Editing the same rule is not a collision');

$byKey = $item;
$byKey['id'] = 'by-key';
$byKey['output'] = ['mode' => 'existing_item', 'host' => 'renamed-auto-receiver', 'key' => 'trigger.correlation.state[incident]'];
expect($identity->findConflict($item, [$byKey])['id'] === 'by-key', 'Item id and host/key aliases must collide');
$custom = $item;
$custom['id'] = 'custom';
$custom['output']['itemid'] = '62';
expect($identity->findConflict($manual, [$custom], ['receiver_state_key_template' => 'custom.state[%s]'])['id'] === 'custom', 'Manual custom state key must be resolved exactly as the evaluator');
expect($identity->findConflict($auto, [$custom], ['receiver_state_key_template' => 'custom.state[%s]']) === null, 'Automatic hosts must retain their fixed state key');

$draft = $auto;
$draft['id'] = 'draft';
$draft['enabled'] = false;
unset($draft['output']['receiver_hostid'], $draft['output']['receiver_host']);
$draft['conditions'] = [['hostid' => '12'], ['hostid' => '11'], ['hostid' => '11']];
expect($identity->findConflict($draft, [$auto])['id'] === 'auto', 'Unprovisioned disabled automatic drafts must match an existing logical receiver');
$draft['output']['correlation_id'] = 'another-incident';
expect($identity->findConflict($draft, [$auto]) === null, 'Different correlation IDs are different outputs');

$dynamic = $draft;
$dynamic['id'] = 'dynamic';
$dynamic['output']['correlation_id'] = 'incident';
$dynamic['source_selector'] = ['mode' => 'group', 'groupid' => '71', 'trigger_name' => 'Node down', 'tag' => '', 'tag_value' => ''];
expect($identity->findConflict($dynamic, [$auto]) === null, 'Dynamic selector receiver must remain distinct from static same-host receiver');
$sameDynamic = $dynamic;
$sameDynamic['id'] = 'other-dynamic';
$sameDynamic['conditions'] = [['hostid' => '999']];
expect($identity->findConflict($dynamic, [$sameDynamic])['id'] === 'other-dynamic', 'Dynamic receiver identity must survive group membership changes');

$missing = $manual;
$missing['id'] = 'missing';
$missing['output']['receiver_host'] = 'deleted-host';
expect($identity->findConflict($draft, [$missing]) === null, 'Unrelated deleted old output must not block a disabled draft');
expectError(static fn() => $identity->findConflict($missing, []), 'An enabled candidate must not use a missing output host');
$missing['enabled'] = false;
expect($identity->findConflict($missing, [$auto]) === null, 'Disabling a rule with a missing output must remain possible');
$nontrapper = $item;
$nontrapper['output']['itemid'] = '63';
expectError(static fn() => $identity->findConflict($nontrapper, []), 'An enabled output must be numeric trapper');
$disabledHost = $item;
$disabledHost['output']['itemid'] = '64';
expectError(static fn() => $identity->findConflict($disabledHost, []), 'An enabled item output must have a monitored host');

echo 'OutputIdentityTest: '.$assertions." assertions passed\n";
