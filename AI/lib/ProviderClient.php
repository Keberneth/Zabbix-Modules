<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class ProviderClient {

    public static function chat(array $provider, array $messages, float $temperature = 1.0): string {
        $type = strtolower(trim((string) ($provider['type'] ?? 'openai_compatible')));

        // Per-provider temperature override: -1 or unset means use the global default.
        $provider_temp = (float) ($provider['temperature'] ?? -1);
        if ($provider_temp >= 0) {
            $temperature = $provider_temp;
        }

        switch ($type) {
            case 'ollama':
                return self::chatOllama($provider, $messages, $temperature);

            case 'anthropic':
                return self::chatAnthropic($provider, $messages, $temperature);

            case 'openai_compatible':
            default:
                return self::chatOpenAICompatible($provider, $messages, $temperature);
        }
    }

    /**
     * Send a chat request with provider-native function/tool definitions.
     *
     * The returned shape is deliberately provider-neutral:
     *   - content: assistant text (may be empty when a tool was requested)
     *   - tool_call: one native tool call, or null
     *   - assistant_message: a neutral message that can be appended to a
     *     subsequent request before a role=tool result message
     *
     * Tool calls are never recovered from assistant prose. Providers which do
     * not support their native tool protocol therefore degrade safely to a
     * normal text reply instead of making JSON-looking model text executable.
     */
    public static function chatWithTools(
        array $provider,
        array $messages,
        array $tools,
        float $temperature = 1.0
    ): array {
        if (!$tools) {
            $content = self::chat($provider, $messages, $temperature);

            return [
                'content' => $content,
                'tool_call' => null,
                'assistant_message' => ['role' => 'assistant', 'content' => $content]
            ];
        }

        $provider_temp = (float) ($provider['temperature'] ?? -1);
        if ($provider_temp >= 0) {
            $temperature = $provider_temp;
        }

        $type = strtolower(trim((string) ($provider['type'] ?? 'openai_compatible')));

        switch ($type) {
            case 'ollama':
                return self::chatOllamaWithTools($provider, $messages, $tools, $temperature);

            case 'anthropic':
                return self::chatAnthropicWithTools($provider, $messages, $tools, $temperature);

            case 'openai_compatible':
            default:
                return self::chatOpenAICompatibleWithTools($provider, $messages, $tools, $temperature);
        }
    }

    private static function chatOllama(array $provider, array $messages, float $temperature): string {
        $endpoint = trim((string) ($provider['endpoint'] ?? ''));

        if ($endpoint === '') {
            $endpoint = 'http://localhost:11434/api/chat';
        }

        // Ollama's default num_ctx is 2048 on many installs, which is often too
        // small for the policy, conversation, monitoring evidence, and native
        // tool schemas sent with an actions request. Default to 16384 so the
        // model can reliably see enough context to select a tool; Ollama caps
        // this to the model's supported maximum. Operators can tune it per
        // provider in the settings UI.
        $num_ctx = (int) ($provider['num_ctx'] ?? 0);
        if ($num_ctx <= 0) {
            $num_ctx = 16384;
        }

        $num_predict = (int) ($provider['max_tokens'] ?? 0);

        $payload = [
            'model' => trim((string) ($provider['model'] ?? '')),
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => $temperature,
                'num_ctx' => $num_ctx
            ]
        ];

        if ($num_predict > 0) {
            $payload['options']['num_predict'] = $num_predict;
        }

        if ($payload['model'] === '') {
            throw new RuntimeException('The selected Ollama provider has no model configured.');
        }

        $headers = self::buildHeaders($provider);

        $response = HttpClient::expectSuccess('POST', $endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => (int) ($provider['timeout'] ?? 120),
            // Default to verifying TLS like the OpenAI/Anthropic paths. A remote
            // HTTPS Ollama must not silently skip certificate verification. For a
            // plain-HTTP loopback endpoint (the common local install) this flag
            // has no effect, so the secure default costs nothing there.
            'verify_peer' => self::resolveVerifyPeer($provider, $endpoint)
        ]);

        if (!is_array($response['json'])) {
            throw new RuntimeException('The Ollama response was not valid JSON.');
        }

        $content = trim((string) (($response['json']['message']['content'] ?? '')));

        if ($content === '') {
            throw new RuntimeException('The Ollama response did not contain message.content.');
        }

        return $content;
    }

    private static function chatOllamaWithTools(
        array $provider,
        array $messages,
        array $tools,
        float $temperature
    ): array {
        $endpoint = trim((string) ($provider['endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'http://localhost:11434/api/chat';
        }

        $num_ctx = (int) ($provider['num_ctx'] ?? 0);
        if ($num_ctx <= 0) {
            $num_ctx = 16384;
        }

        $payload = [
            'model' => trim((string) ($provider['model'] ?? '')),
            'messages' => self::messagesForOllama($messages),
            'tools' => self::toolsForOpenAI($tools),
            'stream' => false,
            'options' => [
                'temperature' => $temperature,
                'num_ctx' => $num_ctx
            ]
        ];

        $num_predict = (int) ($provider['max_tokens'] ?? 0);
        if ($num_predict > 0) {
            $payload['options']['num_predict'] = $num_predict;
        }
        if ($payload['model'] === '') {
            throw new RuntimeException('The selected Ollama provider has no model configured.');
        }

        $response = HttpClient::expectSuccess('POST', $endpoint, [
            'headers' => self::buildHeaders($provider),
            'json' => $payload,
            'timeout' => (int) ($provider['timeout'] ?? 120),
            'verify_peer' => self::resolveVerifyPeer($provider, $endpoint)
        ]);

        if (!is_array($response['json'])) {
            throw new RuntimeException('The Ollama response was not valid JSON.');
        }

        return self::normalizeOpenAIToolResponse(
            is_array($response['json']['message'] ?? null) ? $response['json']['message'] : [],
            'Ollama',
            trim((string) ($response['json']['done_reason'] ?? (!empty($response['json']['done']) ? 'stop' : 'incomplete'))),
            ['stop', 'tool_calls']
        );
    }

    private static function chatOpenAICompatible(array $provider, array $messages, float $temperature): string {
        $endpoint = trim((string) ($provider['endpoint'] ?? ''));

        if ($endpoint === '') {
            $endpoint = 'https://api.openai.com/v1/chat/completions';
        }

        if (!preg_match('#/chat/completions/?$#', $endpoint)) {
            $endpoint = rtrim($endpoint, '/').'/chat/completions';
        }

        $payload = [
            'model' => trim((string) ($provider['model'] ?? '')),
            'messages' => $messages,
            'temperature' => $temperature
        ];

        $max_tokens = (int) ($provider['max_tokens'] ?? 0);
        if ($max_tokens > 0) {
            $payload['max_tokens'] = $max_tokens;
        }

        if ($payload['model'] === '') {
            throw new RuntimeException('The selected provider has no model configured.');
        }

        $headers = self::buildHeaders($provider, true);

        $response = HttpClient::expectSuccess('POST', $endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => (int) ($provider['timeout'] ?? 120),
            'verify_peer' => (bool) ($provider['verify_peer'] ?? true)
        ]);

        if (!is_array($response['json'])) {
            throw new RuntimeException('The provider response was not valid JSON.');
        }

        $message = $response['json']['choices'][0]['message']['content'] ?? null;
        $content = self::normalizeContent($message);

        if ($content === '') {
            throw new RuntimeException('The provider response did not contain choices[0].message.content.');
        }

        return $content;
    }

    private static function chatOpenAICompatibleWithTools(
        array $provider,
        array $messages,
        array $tools,
        float $temperature
    ): array {
        $endpoint = trim((string) ($provider['endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'https://api.openai.com/v1/chat/completions';
        }
        if (!preg_match('#/chat/completions/?$#', $endpoint)) {
            $endpoint = rtrim($endpoint, '/').'/chat/completions';
        }

        $payload = [
            'model' => trim((string) ($provider['model'] ?? '')),
            'messages' => self::messagesForOpenAI($messages),
            'tools' => self::toolsForOpenAI($tools),
            'tool_choice' => 'auto',
            'temperature' => $temperature
        ];

        $max_tokens = (int) ($provider['max_tokens'] ?? 0);
        if ($max_tokens > 0) {
            $payload['max_tokens'] = $max_tokens;
        }
        if ($payload['model'] === '') {
            throw new RuntimeException('The selected provider has no model configured.');
        }

        $response = HttpClient::expectSuccess('POST', $endpoint, [
            'headers' => self::buildHeaders($provider, true),
            'json' => $payload,
            'timeout' => (int) ($provider['timeout'] ?? 120),
            'verify_peer' => (bool) ($provider['verify_peer'] ?? true)
        ]);

        if (!is_array($response['json'])) {
            throw new RuntimeException('The provider response was not valid JSON.');
        }

        $choice = is_array($response['json']['choices'][0] ?? null)
            ? $response['json']['choices'][0]
            : [];

        return self::normalizeOpenAIToolResponse(
            is_array($choice['message'] ?? null) ? $choice['message'] : [],
            'The provider',
            trim((string) ($choice['finish_reason'] ?? '')),
            ['tool_calls']
        );
    }

    private static function chatAnthropic(array $provider, array $messages, float $temperature): string {
        $endpoint = trim((string) ($provider['endpoint'] ?? ''));

        if ($endpoint === '') {
            $endpoint = 'https://api.anthropic.com/v1/messages';
        }

        if (!preg_match('#/v1/messages/?$#', $endpoint)) {
            $endpoint = rtrim($endpoint, '/').'/v1/messages';
        }

        $model = trim((string) ($provider['model'] ?? ''));

        if ($model === '') {
            throw new RuntimeException('The selected Anthropic provider has no model configured.');
        }

        $api_key = self::providerSecretValue($provider, 'api_key', 'api_key_env');

        if ($api_key === '') {
            throw new RuntimeException('The selected Anthropic provider has no API key configured.');
        }

        // Anthropic uses system as a top-level parameter, not in the messages array.
        $system_text = '';
        $api_messages = [];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                $system_text .= ($system_text !== '' ? "\n\n" : '').trim((string) ($msg['content'] ?? ''));
                continue;
            }

            $api_messages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => (string) ($msg['content'] ?? '')
            ];
        }

        // Anthropic requires the first message to be from the user.
        if ($api_messages && ($api_messages[0]['role'] ?? '') !== 'user') {
            array_unshift($api_messages, ['role' => 'user', 'content' => 'Hello.']);
        }

        if (!$api_messages) {
            throw new RuntimeException('No user messages to send to Anthropic.');
        }

        $max_tokens = (int) ($provider['max_tokens'] ?? 0);
        if ($max_tokens <= 0) {
            $max_tokens = 4096;
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
            'messages' => $api_messages
        ];

        if ($system_text !== '') {
            $payload['system'] = $system_text;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01'
        ];

        self::mergeExtraHeaders($headers, $provider);

        $response = HttpClient::expectSuccess('POST', $endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => (int) ($provider['timeout'] ?? 120),
            'verify_peer' => (bool) ($provider['verify_peer'] ?? true)
        ]);

        if (!is_array($response['json'])) {
            throw new RuntimeException('The Anthropic response was not valid JSON.');
        }

        $content_blocks = $response['json']['content'] ?? [];
        $parts = [];

        if (is_array($content_blocks)) {
            foreach ($content_blocks as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $parts[] = trim((string) $block['text']);
                }
            }
        }

        $content = trim(implode("\n", array_filter($parts)));

        if ($content === '') {
            throw new RuntimeException('The Anthropic response did not contain any text content.');
        }

        return $content;
    }

    private static function chatAnthropicWithTools(
        array $provider,
        array $messages,
        array $tools,
        float $temperature
    ): array {
        $endpoint = trim((string) ($provider['endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'https://api.anthropic.com/v1/messages';
        }
        if (!preg_match('#/v1/messages/?$#', $endpoint)) {
            $endpoint = rtrim($endpoint, '/').'/v1/messages';
        }

        $model = trim((string) ($provider['model'] ?? ''));
        if ($model === '') {
            throw new RuntimeException('The selected Anthropic provider has no model configured.');
        }

        $api_key = self::providerSecretValue($provider, 'api_key', 'api_key_env');
        if ($api_key === '') {
            throw new RuntimeException('The selected Anthropic provider has no API key configured.');
        }

        [$system_text, $api_messages] = self::messagesForAnthropic($messages);
        if (!$api_messages) {
            throw new RuntimeException('No user messages to send to Anthropic.');
        }
        if (($api_messages[0]['role'] ?? '') !== 'user') {
            array_unshift($api_messages, ['role' => 'user', 'content' => 'Hello.']);
        }

        $max_tokens = (int) ($provider['max_tokens'] ?? 0);
        if ($max_tokens <= 0) {
            $max_tokens = 4096;
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $max_tokens,
            'temperature' => $temperature,
            'messages' => $api_messages,
            'tools' => self::toolsForAnthropic($tools)
        ];
        if ($system_text !== '') {
            $payload['system'] = $system_text;
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01'
        ];
        self::mergeExtraHeaders($headers, $provider);

        $response = HttpClient::expectSuccess('POST', $endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => (int) ($provider['timeout'] ?? 120),
            'verify_peer' => (bool) ($provider['verify_peer'] ?? true)
        ]);

        if (!is_array($response['json'])) {
            throw new RuntimeException('The Anthropic response was not valid JSON.');
        }

        return self::normalizeAnthropicToolResponse(
            is_array($response['json']['content'] ?? null) ? $response['json']['content'] : [],
            trim((string) ($response['json']['stop_reason'] ?? ''))
        );
    }

    private static function toolsForOpenAI(array $tools): array {
        $result = [];

        foreach ($tools as $tool) {
            if (!is_array($tool) || trim((string) ($tool['name'] ?? '')) === '') {
                continue;
            }
            $result[] = [
                'type' => 'function',
                'function' => [
                    'name' => (string) $tool['name'],
                    'description' => (string) ($tool['description'] ?? ''),
                    'parameters' => is_array($tool['parameters'] ?? null)
                        ? $tool['parameters']
                        : ['type' => 'object', 'properties' => new \stdClass()]
                ]
            ];
        }

        return $result;
    }

    private static function toolsForAnthropic(array $tools): array {
        $result = [];

        foreach ($tools as $tool) {
            if (!is_array($tool) || trim((string) ($tool['name'] ?? '')) === '') {
                continue;
            }
            $result[] = [
                'name' => (string) $tool['name'],
                'description' => (string) ($tool['description'] ?? ''),
                'input_schema' => is_array($tool['parameters'] ?? null)
                    ? $tool['parameters']
                    : ['type' => 'object', 'properties' => new \stdClass()]
            ];
        }

        return $result;
    }

    /** Convert neutral assistant/tool messages to the OpenAI wire shape. */
    private static function messagesForOpenAI(array $messages): array {
        $result = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = (string) ($message['role'] ?? 'user');
            if ($role === 'assistant' && is_array($message['tool_call'] ?? null)) {
                $call = $message['tool_call'];
                $call_id = self::toolCallId($call);
                $result[] = [
                    'role' => 'assistant',
                    'content' => (string) ($message['content'] ?? ''),
                    'tool_calls' => [[
                        'id' => $call_id,
                        'type' => 'function',
                        'function' => [
                            'name' => (string) ($call['name'] ?? ''),
                            'arguments' => json_encode(
                                is_array($call['arguments'] ?? null) ? $call['arguments'] : [],
                                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                            )
                        ]
                    ]]
                ];
                continue;
            }

            if ($role === 'tool') {
                $result[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($message['tool_call_id'] ?? ''),
                    'content' => (string) ($message['content'] ?? '')
                ];
                continue;
            }

            $result[] = [
                'role' => in_array($role, ['system', 'user', 'assistant'], true) ? $role : 'user',
                'content' => (string) ($message['content'] ?? '')
            ];
        }

        return $result;
    }

    /** Ollama's native /api/chat uses tool_name and object arguments. */
    private static function messagesForOllama(array $messages): array {
        $result = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = (string) ($message['role'] ?? 'user');
            if ($role === 'assistant' && is_array($message['tool_call'] ?? null)) {
                $call = $message['tool_call'];
                $result[] = [
                    'role' => 'assistant',
                    'content' => (string) ($message['content'] ?? ''),
                    'tool_calls' => [[
                        'type' => 'function',
                        'function' => [
                            'name' => (string) ($call['name'] ?? ''),
                            'arguments' => is_array($call['arguments'] ?? null)
                                ? $call['arguments']
                                : []
                        ]
                    ]]
                ];
                continue;
            }

            if ($role === 'tool') {
                $result[] = [
                    'role' => 'tool',
                    'tool_name' => (string) ($message['name'] ?? ''),
                    'content' => (string) ($message['content'] ?? '')
                ];
                continue;
            }

            $result[] = [
                'role' => in_array($role, ['system', 'user', 'assistant'], true) ? $role : 'user',
                'content' => (string) ($message['content'] ?? '')
            ];
        }

        return $result;
    }

    /** Convert neutral assistant/tool messages to Anthropic Messages blocks. */
    private static function messagesForAnthropic(array $messages): array {
        $system_text = '';
        $result = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = (string) ($message['role'] ?? 'user');
            if ($role === 'system') {
                $system_text .= ($system_text !== '' ? "\n\n" : '').trim((string) ($message['content'] ?? ''));
                continue;
            }

            if ($role === 'assistant' && is_array($message['tool_call'] ?? null)) {
                $call = $message['tool_call'];
                $blocks = [];
                $content = trim((string) ($message['content'] ?? ''));
                if ($content !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $content];
                }
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => self::toolCallId($call),
                    'name' => (string) ($call['name'] ?? ''),
                    'input' => is_array($call['arguments'] ?? null) ? $call['arguments'] : []
                ];
                $result[] = ['role' => 'assistant', 'content' => $blocks];
                continue;
            }

            if ($role === 'tool') {
                $result[] = [
                    'role' => 'user',
                    'content' => [[
                        'type' => 'tool_result',
                        'tool_use_id' => (string) ($message['tool_call_id'] ?? ''),
                        'content' => (string) ($message['content'] ?? '')
                    ]]
                ];
                continue;
            }

            $result[] = [
                'role' => $role === 'assistant' ? 'assistant' : 'user',
                'content' => (string) ($message['content'] ?? '')
            ];
        }

        return [trim($system_text), $result];
    }

    private static function normalizeOpenAIToolResponse(
        array $message,
        string $provider_label,
        string $termination_reason = '',
        array $allowed_tool_reasons = ['tool_calls']
    ): array {
        $content = self::normalizeContent($message['content'] ?? '');
        $calls = is_array($message['tool_calls'] ?? null) ? array_values($message['tool_calls']) : [];

        if (count($calls) > 1) {
            throw new RuntimeException($provider_label.' returned multiple tool calls; none was executed. Ask it to call one tool at a time.');
        }

        $tool_call = null;
        if ($calls) {
            if (!in_array($termination_reason, $allowed_tool_reasons, true)) {
                throw new RuntimeException(
                    $provider_label.' returned a native tool call with unsafe/incomplete termination reason "'
                    .($termination_reason !== '' ? $termination_reason : 'missing').'"; no tool was executed.'
                );
            }
            $raw = is_array($calls[0]) ? $calls[0] : [];
            $function = is_array($raw['function'] ?? null) ? $raw['function'] : $raw;
            $tool_call = [
                'id' => trim((string) ($raw['id'] ?? '')),
                'name' => trim((string) ($function['name'] ?? '')),
                'arguments' => self::normalizeToolArguments($function['arguments'] ?? [])
            ];
            if ($tool_call['name'] === '') {
                throw new RuntimeException($provider_label.' returned a native tool call without a function name.');
            }
            $tool_call['id'] = self::toolCallId($tool_call);
        }

        if ($content === '' && $tool_call === null) {
            throw new RuntimeException($provider_label.' response contained neither assistant text nor a native tool call.');
        }

        return [
            'content' => $content,
            'tool_call' => $tool_call,
            'assistant_message' => [
                'role' => 'assistant',
                'content' => $content,
                'tool_call' => $tool_call
            ]
        ];
    }

    private static function normalizeAnthropicToolResponse(array $blocks, string $stop_reason = ''): array {
        $text_parts = [];
        $calls = [];

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            if (($block['type'] ?? '') === 'text' && isset($block['text'])) {
                $text_parts[] = trim((string) $block['text']);
            }
            elseif (($block['type'] ?? '') === 'tool_use') {
                $calls[] = $block;
            }
        }

        if (count($calls) > 1) {
            throw new RuntimeException('Anthropic returned multiple tool calls; none was executed. Ask it to call one tool at a time.');
        }

        $content = trim(implode("\n", array_filter($text_parts)));
        $tool_call = null;
        if ($calls) {
            if ($stop_reason !== 'tool_use') {
                throw new RuntimeException(
                    'Anthropic returned a native tool call with unsafe/incomplete stop_reason "'
                    .($stop_reason !== '' ? $stop_reason : 'missing').'"; no tool was executed.'
                );
            }
            $raw = $calls[0];
            $tool_call = [
                'id' => trim((string) ($raw['id'] ?? '')),
                'name' => trim((string) ($raw['name'] ?? '')),
                'arguments' => self::normalizeToolArguments($raw['input'] ?? [])
            ];
            if ($tool_call['name'] === '') {
                throw new RuntimeException('Anthropic returned a native tool call without a function name.');
            }
            $tool_call['id'] = self::toolCallId($tool_call);
        }

        if ($content === '' && $tool_call === null) {
            throw new RuntimeException('The Anthropic response contained neither text nor a native tool call.');
        }

        return [
            'content' => $content,
            'tool_call' => $tool_call,
            'assistant_message' => [
                'role' => 'assistant',
                'content' => $content,
                'tool_call' => $tool_call
            ]
        ];
    }

    private static function normalizeToolArguments($arguments): array {
        if (is_array($arguments)) {
            return $arguments;
        }
        if (is_object($arguments)) {
            return (array) $arguments;
        }

        $arguments = trim((string) $arguments);
        if ($arguments === '') {
            return [];
        }

        $decoded = json_decode($arguments, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The provider returned invalid JSON arguments for a native tool call.');
        }

        return $decoded;
    }

    private static function toolCallId(array $call): string {
        $id = trim((string) ($call['id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        return 'call_'.substr(hash('sha256', json_encode($call)), 0, 24);
    }

    /**
     * Resolve the TLS verification flag for a provider. An explicit per-provider
     * setting is always honored; otherwise we default to verifying. The endpoint
     * is accepted for clarity at the call site — verification is irrelevant for a
     * plain-HTTP endpoint, so a secure default never breaks a local loopback
     * Ollama while still protecting any remote HTTPS endpoint.
     */
    private static function resolveVerifyPeer(array $provider, string $endpoint): bool {
        if (array_key_exists('verify_peer', $provider) && $provider['verify_peer'] !== '' && $provider['verify_peer'] !== null) {
            return (bool) $provider['verify_peer'];
        }

        return true;
    }

    private static function buildHeaders(array $provider, bool $default_json_accept = false): array {
        $headers = [];

        if ($default_json_accept) {
            $headers['Accept'] = 'application/json';
        }

        $api_key = self::providerSecretValue($provider, 'api_key', 'api_key_env');

        if ($api_key !== '') {
            $headers['Authorization'] = 'Bearer '.$api_key;
        }

        self::mergeExtraHeaders($headers, $provider);

        return $headers;
    }

    /**
     * Resolve a provider field once unless Config already supplied a trusted
     * request-local snapshot. Snapshot values are opaque credentials, not
     * syntax: a real key beginning with env:/file:/enc:v1: stays literal.
     */
    private static function providerSecretValue(
        array $provider,
        string $value_field,
        string $reference_field
    ): string {
        if (!empty($provider['_secrets_resolved'])) {
            return (string) ($provider[$value_field] ?? '');
        }

        return Config::resolveSecret(
            $provider[$value_field] ?? '',
            $provider[$reference_field] ?? '',
            !empty($provider['_allow_plaintext_secrets'])
        );
    }

    /** Extra headers may be encrypted at rest just like API keys. */
    private static function mergeExtraHeaders(array &$headers, array $provider): void {
        $resolved = self::providerSecretValue($provider, 'headers_json', 'headers_json_ref');
        $extra_headers = Util::decodeJsonArray($resolved);

        foreach ($extra_headers as $name => $value) {
            if (is_string($name) && trim($name) !== '') {
                $headers[trim($name)] = (string) $value;
            }
        }
    }

    private static function normalizeContent($message): string {
        if (is_string($message)) {
            return trim($message);
        }

        if (is_array($message)) {
            $parts = [];

            foreach ($message as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                    continue;
                }

                if (!is_array($part)) {
                    continue;
                }

                if (isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                }
                elseif (isset($part['content']) && is_string($part['content'])) {
                    $parts[] = $part['content'];
                }
            }

            return trim(implode("\n", array_filter($parts, static function($value) {
                return trim((string) $value) !== '';
            })));
        }

        return '';
    }
}
