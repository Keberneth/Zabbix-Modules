<?php declare(strict_types = 0);

namespace Modules\AI\Lib {
    // A normal send reaches this boundary; a prepared preview must never do so.
    function curl_init($url) {
        $GLOBALS['preview_transport_calls'] = ($GLOBALS['preview_transport_calls'] ?? 0) + 1;
        throw new \RuntimeException('PREVIEW_TRANSPORT_TRAP');
    }
}

namespace {

// Offline privacy checks. Provider preview tests intercept before networking.
require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/DataPreview.php';

use Modules\AI\Lib\DataPreview;
use Modules\AI\Lib\ProviderClient;
use Modules\AI\Lib\Config;

if (!defined('USER_TYPE_ZABBIX_USER')) { define('USER_TYPE_ZABBIX_USER', 1); }
if (!defined('USER_TYPE_ZABBIX_ADMIN')) { define('USER_TYPE_ZABBIX_ADMIN', 2); }
if (!defined('USER_TYPE_SUPER_ADMIN')) { define('USER_TYPE_SUPER_ADMIN', 3); }

$checks = 0;
function previewCheck(bool $condition, string $message): void {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException($message); }
}
function previewJson(): string {
    return json_encode(DataPreview::snapshot(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
function previewConfig(string $visibility): array {
    return ['chat' => ['data_preview_visibility' => $visibility]];
}

previewCheck(Config::defaults()['chat']['data_preview_visibility'] === 'off', 'Preview is not disabled by default.');
foreach (['off', 'super_admin', 'all', 'invalid', ['all']] as $mode) {
    $saved = Config::buildFromPost(['chat' => ['data_preview_visibility' => $mode]], Config::defaults());
    $expected = in_array($mode, ['off', 'super_admin', 'all'], true) ? $mode : 'off';
    previewCheck($saved['chat']['data_preview_visibility'] === $expected, 'Preview visibility failed settings validation/roundtrip.');
}

foreach (['off', 'invalid'] as $mode) {
    previewCheck(!DataPreview::canView(previewConfig($mode), USER_TYPE_SUPER_ADMIN, false), 'Disabled/invalid policy exposed preview.');
}
previewCheck(!DataPreview::canView(previewConfig('super_admin'), USER_TYPE_ZABBIX_USER, false), 'Regular user gained privileged preview access.');
previewCheck(!DataPreview::canView(previewConfig('super_admin'), USER_TYPE_ZABBIX_ADMIN, false), 'Zabbix Admin gained Super Admin preview access.');
previewCheck(DataPreview::canView(previewConfig('super_admin'), USER_TYPE_SUPER_ADMIN, false), 'Super Admin preview was refused.');
previewCheck(DataPreview::canView(previewConfig('all'), USER_TYPE_ZABBIX_USER, false), 'Authenticated user preview was refused.');
previewCheck(!DataPreview::canView(previewConfig('all'), USER_TYPE_SUPER_ADMIN, true), 'Guest gained preview access.');
previewCheck(!DataPreview::canView(previewConfig('all'), 0, false), 'Unauthenticated user gained preview access.');

$provider = [
    'name' => 'Preview test', 'type' => 'openai_compatible', 'model' => 'offline-model',
    'endpoint' => 'https://private-endpoint.example/v1',
    'api_key' => 'credential-canary-alpha',
    'headers_json' => '{"X-Custom-Key":"credential-canary-beta"}'
];
$headers = ['Authorization' => 'Bearer credential-canary-alpha', 'X-Custom-Key' => 'credential-canary-beta'];
$messages = [
    ['role' => 'system', 'content' => 'ADMIN-POLICY-CANARY https://private-reference.example/runbook'],
    ['role' => 'developer', 'content' => 'DEVELOPER-POLICY-CANARY'],
    ['role' => 'user', 'content' => 'Inspect ai-host-001 and 192.0.2.10'],
    ['role' => 'assistant', 'content' => 'Earlier answer visible to this user']
];

DataPreview::start(false);
previewCheck(DataPreview::record($provider, ['messages' => $messages], $headers) === null, 'Disabled capture recorded a request.');
previewCheck(DataPreview::snapshot() === null, 'Disabled capture returned data.');

foreach (['openai_compatible', 'ollama', 'anthropic'] as $type) {
    DataPreview::start(true);
    DataPreview::setContext('Runtime context: ai-host-001');
    $body = [
        'model' => 'offline-model', 'messages' => $messages,
        'system' => 'ANTHROPIC-SYSTEM-CANARY', 'instructions' => 'TOP-LEVEL-POLICY-CANARY',
        'tools' => [['name' => 'example_read', 'description' => 'PRIVATE-TOOL-SCHEMA-CANARY']],
        'temperature' => 0.5
    ];
    $id = DataPreview::record(array_replace($provider, ['type' => $type]), $body, $headers);
    previewCheck(is_int($id), 'Enabled capture did not provide a request reference.');
    DataPreview::markSent($id);
    $json = previewJson();
    foreach ([
        'ADMIN-POLICY-CANARY', 'DEVELOPER-POLICY-CANARY', 'ANTHROPIC-SYSTEM-CANARY',
        'TOP-LEVEL-POLICY-CANARY', 'PRIVATE-TOOL-SCHEMA-CANARY', 'private-reference.example',
        'credential-canary-alpha', 'credential-canary-beta', 'private-endpoint.example', 'Authorization'
    ] as $forbidden) {
        previewCheck(strpos($json, $forbidden) === false, 'Preview exposed '.$forbidden.' for '.$type.'.');
    }
    previewCheck(strpos($json, 'Inspect ai-host-001') !== false, 'Preview removed authorized user data.');
    previewCheck(strpos($json, 'Runtime context: ai-host-001') !== false, 'Masked runtime context was omitted.');
}

// Captures must not survive a new turn, changed visibility or another user.
DataPreview::start(true);
DataPreview::record($provider, ['messages' => [['role' => 'user', 'content' => 'USER-A-PRIVATE-CANARY']]]);
DataPreview::setContext('USER-A-CONTEXT-CANARY');
DataPreview::start(true);
DataPreview::record($provider, ['messages' => [['role' => 'user', 'content' => 'USER-B-VISIBLE-CANARY']]]);
$json = previewJson();
previewCheck(strpos($json, 'USER-A-') === false, 'Previous request/user capture survived reset.');
previewCheck(strpos($json, 'USER-B-VISIBLE-CANARY') !== false, 'Current request was not captured.');
DataPreview::start(false);
previewCheck(DataPreview::snapshot() === null, 'Disabling capture retained prior user data.');

DataPreview::start(true);
DataPreview::setContext('Credential in context: credential-canary-alpha');
DataPreview::record($provider, ['messages' => [[
    'role' => 'user', 'content' => ['credential-canary-beta' => 'credential-canary-alpha']
]]], $headers);
$json = previewJson();
previewCheck(strpos($json, 'credential-canary-') === false, 'Known provider credential survived in content, context or JSON keys.');
DataPreview::start(true);
DataPreview::record($provider, ['messages' => [[
    'role' => 'tool', 'content' => (object) ['credential-canary-beta' => 'credential-canary-alpha']
]]], $headers);
previewCheck(strpos(previewJson(), 'credential-canary-') === false, 'Nested object bypassed credential filtering.');

// Limits must bound memory and remain JSON-encodable at UTF-8 boundaries.
DataPreview::start(true);
DataPreview::record($provider, ['messages' => [['role' => 'user', 'content' => str_repeat('å', 70000)]]]);
$json = previewJson();
previewCheck(strpos($json, 'truncated') !== false, 'Oversized string was shortened without a disclosure flag.');
previewCheck(strlen($json) < 200000, 'Per-string preview bound was not enforced.');
DataPreview::start(true);
DataPreview::record($provider, ['messages' => [['role' => 'tool', 'content' => [str_repeat('k', 1200000) => 'value']]]]);
$json = previewJson();
previewCheck(strlen($json) < 200000, 'Oversized associative key bypassed preview size bounds.');
previewCheck(DataPreview::snapshot()['truncated'], 'Oversized associative key was truncated silently.');
DataPreview::start(true);
for ($index = 0; $index < 40; ++$index) {
    DataPreview::record($provider, ['messages' => [['role' => 'user', 'content' => 'Request '.$index]]]);
}
$snapshot = DataPreview::snapshot();
previewCheck(count($snapshot['requests']) === 25, 'Per-turn request count cap was not enforced.');
previewCheck($snapshot['request_count'] === 40 && $snapshot['truncated'], 'Dropped request count was not disclosed.');
DataPreview::start(true);
for ($index = 0; $index < 40; ++$index) {
    DataPreview::record($provider, ['messages' => [['role' => 'user', 'content' => 'Request '.$index.' '.str_repeat('x', 60000)]]]);
}
$json = previewJson();
previewCheck(strlen($json) < 1200000, 'Total preview memory bound was not enforced.');
previewCheck(strpos($json, 'truncated') !== false, 'Capped requests were omitted without a disclosure flag.');
DataPreview::start(false);

// Exercise all six actual provider adapters without a transport or model call.
$native_tools = [[
    'name' => 'example_read', 'description' => 'PRIVATE-ADAPTER-SCHEMA-CANARY',
    'parameters' => ['type' => 'object', 'properties' => new \stdClass()]
]];
$controller_messages = [
    ['role' => 'system', 'content' => 'PRIVATE-ADAPTER-POLICY-CANARY'],
    ['role' => 'developer', 'content' => 'PRIVATE-ADAPTER-DEVELOPER-CANARY'],
    ['role' => 'user', 'content' => 'Inspect ai-host-001']
];
foreach (['openai_compatible', 'ollama', 'anthropic'] as $type) {
    $resolved_provider = array_replace($provider, ['type' => $type, '_secrets_resolved' => true]);
    foreach ([[], $native_tools] as $tools) {
        DataPreview::start(true);
        $before = $GLOBALS['preview_transport_calls'] ?? 0;
        $adapter_messages = $controller_messages;
        if ($tools) {
            $adapter_messages[] = ['role' => 'assistant', 'content' => '', 'tool_call' => [
                'id' => 'call_test', 'name' => 'example_read', 'arguments' => ['hostname' => 'ai-host-001']
            ]];
            $adapter_messages[] = ['role' => 'tool', 'tool_call_id' => 'call_test', 'name' => 'example_read', 'content' => 'TOOL-RESULT-CANARY'];
        }
        $snapshot = ProviderClient::previewChat($resolved_provider, $adapter_messages, $tools, 0.25);
        previewCheck(($GLOBALS['preview_transport_calls'] ?? 0) === $before, 'Prepared preview attempted provider transport.');
        previewCheck(($snapshot['kind'] ?? '') === 'preview', 'Prepared request was labelled as already sent.');
        previewCheck(($snapshot['requests'][0]['status'] ?? '') === 'preview', 'Prepared request status is inaccurate.');
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        previewCheck(strpos($json, 'Inspect ai-host-001') !== false, 'Adapter dropped previewed user data.');
        previewCheck(strpos($json, 'PRIVATE-ADAPTER-') === false, 'Adapter preview exposed administrator policy/schema.');
        previewCheck(strpos($json, 'credential-canary-') === false, 'Adapter preview exposed provider credentials.');
        if ($tools) {
            previewCheck(in_array('example_read', $snapshot['requests'][0]['tool_names'] ?? [], true), 'Enabled tool name is missing.');
            previewCheck(strpos($json, 'TOOL-RESULT-CANARY') !== false, 'Native tool-result data was absent from captured request.');
        }
    }

    // Preview interception must reset after success and after an adapter error.
    DataPreview::start(true);
    try {
        ProviderClient::previewChat(array_replace($resolved_provider, ['model' => '']), $controller_messages);
        throw new RuntimeException('An invalid provider unexpectedly produced a preview.');
    }
    catch (RuntimeException $e) {
        previewCheck(strpos($e->getMessage(), 'no model configured') !== false, 'Invalid provider error was hidden.');
    }
    $before = $GLOBALS['preview_transport_calls'] ?? 0;
    try {
        ProviderClient::chat($resolved_provider, $controller_messages);
        throw new RuntimeException('Normal provider send was unexpectedly intercepted as a preview.');
    }
    catch (RuntimeException $e) {
        previewCheck($e->getMessage() === 'PREVIEW_TRANSPORT_TRAP', 'Preview interception leaked into a later send.');
    }
    previewCheck(($GLOBALS['preview_transport_calls'] ?? 0) === $before + 1, 'Normal send did not reach transport after preview.');
    previewCheck((DataPreview::snapshot()['requests'][0]['status'] ?? '') === 'attempted', 'Failed transport was marked as delivered.');
}
DataPreview::start(false);

echo 'Data preview checks passed: '.$checks."\n";
}
