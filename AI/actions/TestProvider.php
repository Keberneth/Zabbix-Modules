<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\HttpClient,
    Modules\AI\Lib\Util;
use RuntimeException;

class TestProvider extends CController {

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $provider = $this->buildProviderFromPost();
            $type = strtolower(trim((string) ($provider['type'] ?? 'openai_compatible')));

            switch ($type) {
                case 'ollama':
                    $models = $this->listOllamaModels($provider);
                    break;

                case 'anthropic':
                    $models = $this->listAnthropicModels($provider);
                    break;

                case 'openai_compatible':
                default:
                    $models = $this->listOpenAIModels($provider);
                    break;
            }

            $this->respond([
                'ok' => true,
                'models' => $models,
                'message' => sprintf(_('Connection succeeded. Found %d model(s).'), count($models))
            ]);
        }
        catch (\Throwable $e) {
            $this->respond([
                'ok' => false,
                'error' => Util::truncate($e->getMessage(), 1000)
            ], 400);
        }
    }

    private function buildProviderFromPost(): array {
        $post = $_POST;

        $id = (string) ($post['id'] ?? '');
        $type = (string) ($post['type'] ?? 'openai_compatible');
        $endpoint = (string) ($post['endpoint'] ?? '');
        $api_key_form = trim((string) ($post['api_key'] ?? ''));
        $api_key_env = trim((string) ($post['api_key_env'] ?? ''));
        $verify_peer = !empty($post['verify_peer']);
        $timeout = (int) ($post['timeout'] ?? 60);
        $headers_json = (string) ($post['headers_json'] ?? '');

        $api_key = $api_key_form;

        // If the form did not provide a fresh key, fall back to the stored secret
        // for this provider id (if any). This lets the user test without re-entering
        // the key after it has been saved.
        if ($api_key === '' && $id !== '') {
            $config = Config::get();
            foreach (($config['providers'] ?? []) as $p) {
                if (($p['id'] ?? '') === $id) {
                    if (empty($post['clear_api_key'])) {
                        $api_key = (string) ($p['api_key'] ?? '');
                    }
                    if ($api_key_env === '' && !empty($p['api_key_env'])) {
                        $api_key_env = (string) $p['api_key_env'];
                    }
                    break;
                }
            }
        }

        return [
            'id' => $id,
            'type' => $type,
            'endpoint' => $endpoint,
            'api_key' => $api_key,
            'api_key_env' => $api_key_env,
            'verify_peer' => $verify_peer,
            'timeout' => max(5, min(60, $timeout)),
            'headers_json' => $headers_json
        ];
    }

    private function listOpenAIModels(array $provider): array {
        $endpoint = trim((string) $provider['endpoint']);

        if ($endpoint === '') {
            $endpoint = 'https://api.openai.com/v1';
        }

        $endpoint = preg_replace('#/chat/completions/?$#', '', $endpoint);
        $url = rtrim($endpoint, '/').'/models';

        $headers = ['Accept' => 'application/json'];
        $api_key = Config::resolveSecret($provider['api_key'] ?? '', $provider['api_key_env'] ?? '');

        if ($api_key !== '') {
            $headers['Authorization'] = 'Bearer '.$api_key;
        }

        $this->mergeExtraHeaders($headers, $provider['headers_json'] ?? '');

        $response = HttpClient::expectSuccess('GET', $url, [
            'headers' => $headers,
            'timeout' => (int) $provider['timeout'],
            'verify_peer' => !empty($provider['verify_peer'])
        ]);

        if (!is_array($response['json'])) {
            throw new RuntimeException('The provider response was not valid JSON.');
        }

        $list = $response['json']['data'] ?? $response['json']['models'] ?? [];

        return $this->normalizeModelList($list);
    }

    private function listOllamaModels(array $provider): array {
        $endpoint = trim((string) $provider['endpoint']);

        if ($endpoint === '') {
            $endpoint = 'http://localhost:11434';
        }

        $endpoint = preg_replace('#/api/(chat|generate|tags)/?$#', '', $endpoint);
        $url = rtrim($endpoint, '/').'/api/tags';

        $headers = ['Accept' => 'application/json'];
        $api_key = Config::resolveSecret($provider['api_key'] ?? '', $provider['api_key_env'] ?? '');

        if ($api_key !== '') {
            $headers['Authorization'] = 'Bearer '.$api_key;
        }

        $this->mergeExtraHeaders($headers, $provider['headers_json'] ?? '');

        $response = HttpClient::expectSuccess('GET', $url, [
            'headers' => $headers,
            'timeout' => (int) $provider['timeout'],
            'verify_peer' => !empty($provider['verify_peer'])
        ]);

        if (!is_array($response['json'])) {
            throw new RuntimeException('The Ollama response was not valid JSON.');
        }

        $list = $response['json']['models'] ?? [];

        return $this->normalizeModelList($list, 'name');
    }

    private function listAnthropicModels(array $provider): array {
        $endpoint = trim((string) $provider['endpoint']);

        if ($endpoint === '') {
            $endpoint = 'https://api.anthropic.com';
        }

        $endpoint = preg_replace('#/v1/messages/?$#', '', $endpoint);
        $url = rtrim($endpoint, '/').'/v1/models';

        $api_key = Config::resolveSecret($provider['api_key'] ?? '', $provider['api_key_env'] ?? '');

        if ($api_key === '') {
            throw new RuntimeException('An Anthropic API key is required to list models.');
        }

        $headers = [
            'Accept' => 'application/json',
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01'
        ];

        $this->mergeExtraHeaders($headers, $provider['headers_json'] ?? '');

        $response = HttpClient::expectSuccess('GET', $url, [
            'headers' => $headers,
            'timeout' => (int) $provider['timeout'],
            'verify_peer' => !empty($provider['verify_peer'])
        ]);

        if (!is_array($response['json'])) {
            throw new RuntimeException('The Anthropic response was not valid JSON.');
        }

        $list = $response['json']['data'] ?? $response['json']['models'] ?? [];

        return $this->normalizeModelList($list);
    }

    private function normalizeModelList(array $list, string $primary_key = 'id'): array {
        $out = [];

        foreach ($list as $entry) {
            if (is_array($entry)) {
                $value = (string) ($entry[$primary_key] ?? $entry['id'] ?? $entry['name'] ?? $entry['model'] ?? '');
            }
            else {
                $value = (string) $entry;
            }

            $value = trim($value);

            if ($value !== '') {
                $out[$value] = true;
            }
        }

        $out = array_keys($out);
        sort($out, SORT_NATURAL | SORT_FLAG_CASE);

        return $out;
    }

    private function mergeExtraHeaders(array &$headers, $headers_json): void {
        $extra = Util::decodeJsonArray((string) $headers_json);

        if (!$extra) {
            return;
        }

        foreach ($extra as $name => $value) {
            if (is_string($name)) {
                $headers[trim($name)] = (string) $value;
            }
        }
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]))->disableView()
        );
    }
}
