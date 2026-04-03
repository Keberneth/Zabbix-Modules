<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class ProviderClient {

    public static function chat(array $provider, array $messages, float $temperature = 0.2): string {
        $type = strtolower(trim((string) ($provider['type'] ?? 'openai_compatible')));

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

        $payload = [
            'model' => trim((string) ($provider['model'] ?? '')),
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => $temperature
            ]
        ];

        if ($payload['model'] === '') {
            throw new RuntimeException('The selected Ollama provider has no model configured.');
        }

        $headers = self::buildHeaders($provider);

        $response = HttpClient::expectSuccess('POST', $endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => (int) ($provider['timeout'] ?? 60),
            'verify_peer' => (bool) ($provider['verify_peer'] ?? false)
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
            throw new RuntimeException('The selected provider has no endpoint configured.');
        }

        if (!preg_match('#/chat/completions/?$#', $endpoint)) {
            $endpoint = rtrim($endpoint, '/').'/chat/completions';
        }

        $payload = [
            'model' => trim((string) ($provider['model'] ?? '')),
            'messages' => $messages,
            'temperature' => $temperature
        ];

        if ($payload['model'] === '') {
            throw new RuntimeException('The selected provider has no model configured.');
        }

        $headers = self::buildHeaders($provider, true);

        $response = HttpClient::expectSuccess('POST', $endpoint, [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => (int) ($provider['timeout'] ?? 60),
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

        $payload = [
            'model' => $model,
            'max_tokens' => (int) ($provider['max_tokens'] ?? 4096),
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
