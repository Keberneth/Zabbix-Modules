<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\HttpClient,
    Modules\AI\Lib\SecretReference,
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

            // Block server-side requests to cloud-metadata/link-local targets.
            // Only a custom endpoint is validated; an empty endpoint falls back to
            // the vendor's public API URL inside the list* helpers.
            $endpoint = trim((string) ($provider['endpoint'] ?? ''));
            if ($endpoint !== '') {
                Util::assertSafeProbeUrl($endpoint);
            }

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
        $config = Config::get();

        $id = (string) ($post['id'] ?? '');
        $type = (string) ($post['type'] ?? 'openai_compatible');
        $endpoint = (string) ($post['endpoint'] ?? '');
        $api_key_form = trim((string) ($post['api_key'] ?? ''));
        $api_key_env = trim((string) ($post['api_key_env'] ?? ''));
        $verify_peer = array_key_exists('verify_peer', $post)
            ? Util::truthy($post['verify_peer'])
            : true;
        $timeout = (int) ($post['timeout'] ?? 60);
        $headers_json = (string) ($post['headers_json'] ?? '');
        $headers_json_ref = trim((string) ($post['headers_json_ref'] ?? ''));

        if (SecretReference::isExplicitReference($api_key_form)) {
            $inline_reference = SecretReference::normalize($api_key_form);
            if ($api_key_env !== '' && SecretReference::normalize($api_key_env) !== $inline_reference) {
                throw new RuntimeException('Choose only one provider API-key reference.');
            }
            $api_key_env = $inline_reference;
            $api_key_form = '';
        }
        if (SecretReference::isExplicitReference(trim($headers_json))) {
            $inline_reference = SecretReference::normalize(trim($headers_json));
            if ($headers_json_ref !== ''
                    && SecretReference::normalize($headers_json_ref) !== $inline_reference) {
                throw new RuntimeException('Choose only one provider custom-header reference.');
            }
            $headers_json_ref = $inline_reference;
            $headers_json = '';
        }
        $api_key_is_fresh = $api_key_form !== '';
        $headers_json_is_fresh = trim($headers_json) !== '';

        $api_key = $api_key_form;

        // A freshly typed value is request-local and deliberately overrides a
        // visible saved reference for this test. It is never persisted here.
        if ($api_key_is_fresh) {
            $api_key_env = '';
        }
        if ($headers_json_is_fresh) {
            $headers_json_ref = '';
        }

        // Stored write-only material is bound to its saved destination/type/TLS
        // policy. Never combine it with an unsaved endpoint change, which would
        // let a settings test disclose a database/vault credential elsewhere.
        $stored_provider = null;
        if ($id !== '') {
            foreach (($config['providers'] ?? []) as $p) {
                if (($p['id'] ?? '') === $id) {
                    $stored_provider = $p;
                    break;
                }
            }
        }

        $binding_matches = is_array($stored_provider)
            && strtolower(trim((string) ($stored_provider['type'] ?? 'openai_compatible')))
                === strtolower(trim($type))
            && trim((string) ($stored_provider['endpoint'] ?? '')) === trim($endpoint)
            && Util::truthy($stored_provider['verify_peer'] ?? true) === $verify_peer;

        if ($binding_matches) {
            if (!$api_key_is_fresh) {
                $stored_reference = trim((string) ($stored_provider['api_key_env'] ?? ''));
                if ($api_key_env !== '' && $api_key_env !== $stored_reference) {
                    throw new RuntimeException(
                        'Save the provider secret-reference change before testing it with stored credentials.'
                    );
                }
                if ($api_key_env === '' && empty($post['clear_api_key'])) {
                    $api_key_env = $stored_reference;
                    if ($api_key_env === '') {
                        $api_key = (string) ($stored_provider['api_key'] ?? '');
                    }
                }
            }

            if (!$headers_json_is_fresh) {
                $stored_reference = trim((string) ($stored_provider['headers_json_ref'] ?? ''));
                if ($headers_json_ref !== '' && $headers_json_ref !== $stored_reference) {
                    throw new RuntimeException(
                        'Save the custom-header secret-reference change before testing it with stored credentials.'
                    );
                }
                if ($headers_json_ref === '' && empty($post['clear_headers_json'])) {
                    $headers_json_ref = $stored_reference;
                    if ($headers_json_ref === '') {
                        $headers_json = (string) ($stored_provider['headers_json'] ?? '');
                    }
                }
            }
        }
        elseif ($api_key_env !== '' || $headers_json_ref !== '') {
            throw new RuntimeException(
                'Save the provider destination and secret reference before testing. '
                .'For an unsaved destination, enter a fresh inline credential for this test instead.'
            );
        }

        if ($headers_json_is_fresh && !$api_key_is_fresh
                && ($api_key !== '' || $api_key_env !== '')) {
            throw new RuntimeException(
                'Fresh custom headers cannot be combined with a stored API key/reference. '
                .'Enter a fresh API key too, or save the header change before testing.'
            );
        }

        return [
            'id' => $id,
            'type' => $type,
            'endpoint' => $endpoint,
            'api_key' => $api_key,
            'api_key_env' => $api_key_env,
            'verify_peer' => $verify_peer,
            'timeout' => max(5, min(60, $timeout)),
            'headers_json' => $headers_json,
            'headers_json_ref' => $headers_json_ref,
            '_allow_plaintext_secrets' => Config::allowsPlaintextSecrets($config),
            // Fresh POST values exist only for this connection test and are
            // never persisted; stored fallback values remain subject to the
            // configured plaintext-at-rest policy.
            '_api_key_is_fresh' => $api_key_is_fresh,
            '_headers_json_is_fresh' => $headers_json_is_fresh
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
        $api_key = Config::resolveSecret(
            $provider['api_key'] ?? '',
            $provider['api_key_env'] ?? '',
            !empty($provider['_allow_plaintext_secrets']) || !empty($provider['_api_key_is_fresh'])
        );

        if ($api_key !== '') {
            $headers['Authorization'] = 'Bearer '.$api_key;
        }

        $this->mergeExtraHeaders($headers, $provider);

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
        $api_key = Config::resolveSecret(
            $provider['api_key'] ?? '',
            $provider['api_key_env'] ?? '',
            !empty($provider['_allow_plaintext_secrets']) || !empty($provider['_api_key_is_fresh'])
        );

        if ($api_key !== '') {
            $headers['Authorization'] = 'Bearer '.$api_key;
        }

        $this->mergeExtraHeaders($headers, $provider);

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

        $api_key = Config::resolveSecret(
            $provider['api_key'] ?? '',
            $provider['api_key_env'] ?? '',
            !empty($provider['_allow_plaintext_secrets']) || !empty($provider['_api_key_is_fresh'])
        );

        if ($api_key === '') {
            throw new RuntimeException('An Anthropic API key is required to list models.');
        }

        $headers = [
            'Accept' => 'application/json',
            'x-api-key' => $api_key,
            'anthropic-version' => '2023-06-01'
        ];

        $this->mergeExtraHeaders($headers, $provider);

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

    private function mergeExtraHeaders(array &$headers, array $provider): void {
        $extra = Util::decodeJsonArray(Config::resolveSecret(
            (string) ($provider['headers_json'] ?? ''),
            (string) ($provider['headers_json_ref'] ?? ''),
            !empty($provider['_allow_plaintext_secrets']) || !empty($provider['_headers_json_is_fresh'])
        ));

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
