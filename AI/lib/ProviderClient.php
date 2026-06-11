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

    private static function chatOllama(array $provider, array $messages, float $temperature): string {
        $endpoint = trim((string) ($provider['endpoint'] ?? ''));

        if ($endpoint === '') {
            $endpoint = 'http://localhost:11434/api/chat';
        }

        // Ollama's default num_ctx is 2048 on most installs, which is far less
        // than the system prompt this module assembles (instructions + tools +
        // anti-injection rules + frontend URL block easily exceed 6k tokens).
        // Without an explicit override the tools section at the tail of the
        // prompt gets silently truncated and the model has no idea it can call
        // anything. Default to 16384; Ollama caps to the model's own maximum
        // if that's lower, so this is safe for small models too. The operator
        // can tune this per provider in the settings UI.
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

        $api_key = Config::resolveSecret($provider['api_key'] ?? '', $provider['api_key_env'] ?? '');

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

        $extra_headers = Util::decodeJsonArray($provider['headers_json'] ?? '');

        if ($extra_headers) {
            foreach ($extra_headers as $name => $value) {
                if (is_string($name)) {
                    $headers[trim($name)] = (string) $value;
                }
            }
        }

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

        $api_key = Config::resolveSecret($provider['api_key'] ?? '', $provider['api_key_env'] ?? '');

        if ($api_key !== '') {
            $headers['Authorization'] = 'Bearer '.$api_key;
        }

        $extra_headers = Util::decodeJsonArray($provider['headers_json'] ?? '');

        if ($extra_headers) {
            foreach ($extra_headers as $name => $value) {
                if (is_string($name)) {
                    $headers[trim($name)] = (string) $value;
                }
            }
        }

        return $headers;
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
