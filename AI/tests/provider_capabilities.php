<?php declare(strict_types = 1);

// Offline protocol fixtures: no external requests and no Zabbix dependencies.
namespace Modules\AI\Lib {
    final class HttpClient {
        public static array $calls = [];
        public static string $fixture = 'success';

        public static function expectSuccess(string $method, string $url, array $options): array {
            self::$calls[] = ['method' => $method, 'url' => $url, 'options' => $options];
            $payload = $options['json'];
            if (self::$fixture === 'chat_failure') {
                throw new \RuntimeException('Provider refused sample-key and sample-header-secret');
            }
            $anthropic = strpos($url, '/v1/messages') !== false;
            $ollama = strpos($url, '/api/chat') !== false;
            $has_tools = !empty($payload['tools']);
            $text = 'OK';
            if (!$has_tools || self::$fixture === 'prose') {
                if ($has_tools) {
                    $text = '{"tool":"ai_connection_probe","arguments":{"nonce":"fake"}}';
                }
                if ($anthropic) {
                    return ['json' => ['content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn']];
                }
                if ($ollama) {
                    return ['json' => ['message' => ['content' => $text], 'done' => true]];
                }
                return ['json' => ['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]]];
            }

            $messages = $payload['messages'];
            preg_match('/nonce "([a-f0-9]+)"/', end($messages)['content'], $match);
            $nonce = self::$fixture === 'wrong_arguments' ? 'wrong' : ($match[1] ?? 'missing');
            if ($anthropic) {
                $call = ['type' => 'tool_use', 'id' => 'probe-1', 'name' => 'ai_connection_probe', 'input' => ['nonce' => $nonce]];
                return ['json' => [
                    'content' => self::$fixture === 'multiple' ? [$call, $call] : [$call],
                    'stop_reason' => self::$fixture === 'incomplete' ? 'max_tokens' : 'tool_use'
                ]];
            }
            $call = [
                'id' => 'probe-1',
                'type' => 'function',
                'function' => [
                    'name' => 'ai_connection_probe',
                    'arguments' => $ollama ? ['nonce' => $nonce] : json_encode(['nonce' => $nonce])
                ]
            ];
            $message = ['content' => '', 'tool_calls' => self::$fixture === 'multiple' ? [$call, $call] : [$call]];
            if ($ollama) {
                return ['json' => ['message' => $message, 'done_reason' => self::$fixture === 'incomplete' ? 'length' : 'stop']];
            }
            return ['json' => ['choices' => [[
                'message' => $message,
                'finish_reason' => self::$fixture === 'incomplete' ? 'length' : 'tool_calls'
            ]]]];
        }
    }
}

namespace {
    require_once __DIR__.'/../lib/Util.php';
    require_once __DIR__.'/../lib/ProviderClient.php';

    use Modules\AI\Lib\HttpClient;
    use Modules\AI\Lib\ProviderClient;

    function check(bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    foreach (['openai_compatible', 'anthropic', 'ollama'] as $type) {
        $provider = [
            'type' => $type,
            'model' => 'test-model',
            'api_key' => 'sample-key',
            'headers_json' => '{"X-Test-Key":"sample-header-secret"}',
            '_secrets_resolved' => true,
            'verify_peer' => true,
            'allow_insecure_http' => true,
            'timeout' => 120,
            'max_tokens' => 10000
        ];

        foreach (['success', 'prose', 'multiple', 'incomplete', 'wrong_arguments', 'chat_failure'] as $fixture) {
            HttpClient::$fixture = $fixture;
            HttpClient::$calls = [];
            $result = ProviderClient::testCapabilities($provider);
            check($result['chat']['ok'] === ($fixture !== 'chat_failure'), $type.': wrong chat status for '.$fixture);
            check($result['tools']['ok'] === ($fixture === 'success'), $type.': wrong tools status for '.$fixture);
            check(count(HttpClient::$calls) === ($fixture === 'chat_failure' ? 1 : 2), 'Probe exceeded its request bound.');
            foreach (HttpClient::$calls as $call) {
                $options = $call['options'];
                check($options['allow_insecure_http'] === true, 'Explicit HTTP policy was dropped.');
                check($options['verify_peer'] === true, 'TLS verification policy was dropped.');
                check($options['timeout'] === 30, 'Probe timeout was not bounded.');
                $payload = $options['json'];
                check(($payload['max_tokens'] ?? $payload['options']['num_predict'] ?? 0) === 512, 'Probe output budget was not bounded.');
                check($payload['model'] === 'test-model', 'Probe used the wrong model.');
                if (!empty($payload['tools'])) {
                    check(count($payload['tools']) === 1, 'Probe exposed extra tools.');
                    $definition = $payload['tools'][0];
                    check(($definition['name'] ?? $definition['function']['name'] ?? '') === 'ai_connection_probe', 'Probe exposed a real action.');
                }
            }
            if ($fixture === 'chat_failure') {
                check(!empty($result['tools']['skipped']), 'Tool probe should be skipped after chat failure.');
                $encoded = json_encode($result);
                check(strpos($encoded, 'sample-key') === false && strpos($encoded, 'sample-header-secret') === false, 'Error diagnostic leaked credentials.');
            }
        }
    }

    HttpClient::$calls = [];
    try {
        ProviderClient::testCapabilities(['model' => '']);
        throw new RuntimeException('Missing-model probe was accepted.');
    }
    catch (RuntimeException $e) {
        check(strpos($e->getMessage(), 'Select a model') !== false, 'Unexpected missing-model error.');
    }
    check(HttpClient::$calls === [], 'Missing-model probe sent a request.');
    echo "Provider capability tests passed.\n";
}
