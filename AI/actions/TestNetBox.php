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

class TestNetBox extends CController {

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $cfg = $this->buildNetBoxConfigFromPost();

            if ($cfg['url'] === '') {
                throw new RuntimeException('NetBox URL is required.');
            }

            if ($cfg['token'] === '') {
                throw new RuntimeException('NetBox token is required (enter one, configure an env:/file: reference, or keep the stored token).');
            }

            // Block server-side requests to cloud-metadata/link-local targets
            // before attaching the (possibly stored) NetBox token to the request.
            Util::assertSafeProbeUrl($cfg['url']);

            $url = rtrim($cfg['url'], '/').'/api/status/';

            $response = HttpClient::expectSuccess('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Token '.$cfg['token']
                ],
                'timeout' => $cfg['timeout'],
                'verify_peer' => $cfg['verify_peer']
            ]);

            $version = '';

            if (is_array($response['json'])) {
                $version = (string) ($response['json']['netbox-version']
                    ?? $response['json']['django-version']
                    ?? $response['json']['version']
                    ?? '');
            }

            $message = _('Connection succeeded.');

            if ($version !== '') {
                $message = sprintf(_('Connection succeeded. NetBox version: %s'), $version);
            }

            $this->respond([
                'ok' => true,
                'message' => $message
            ]);
        }
        catch (\Throwable $e) {
            $this->respond([
                'ok' => false,
                'error' => Util::truncate($e->getMessage(), 1000)
            ], 400);
        }
    }

    private function buildNetBoxConfigFromPost(): array {
        $post = $_POST;
        $config = Config::get();

        $url = trim((string) ($post['url'] ?? ''));
        $token_form = trim((string) ($post['token'] ?? ''));
        $token_env = trim((string) ($post['token_env'] ?? ''));
        if (SecretReference::isExplicitReference($token_form)) {
            $inline_reference = SecretReference::normalize($token_form);
            if ($token_env !== '' && SecretReference::normalize($token_env) !== $inline_reference) {
                throw new RuntimeException('Choose only one NetBox token reference.');
            }
            $token_env = $inline_reference;
            $token_form = '';
        }
        $token_is_fresh = $token_form !== '';
        $verify_peer = array_key_exists('verify_peer', $post)
            ? Util::truthy($post['verify_peer'])
            : true;
        $timeout = (int) ($post['timeout'] ?? 10);
        $clear_token = !empty($post['clear_token']);

        if ($token_is_fresh) {
            // Fresh request-local material overrides a visible saved reference.
            $token_env = '';
        }

        $stored_netbox = (array) ($config['netbox'] ?? []);
        $binding_matches = trim((string) ($stored_netbox['url'] ?? '')) === $url
            && Util::truthy($stored_netbox['verify_peer'] ?? true) === $verify_peer;

        if (!$token_is_fresh && $binding_matches) {
            $stored_reference = trim((string) ($stored_netbox['token_env'] ?? ''));
            if ($token_env !== '' && $token_env !== $stored_reference) {
                throw new RuntimeException(
                    'Save the NetBox token-reference change before testing it with stored credentials.'
                );
            }
            if ($token_env === '' && !$clear_token) {
                $token_env = $stored_reference;
                if ($token_env === '') {
                    $token_form = (string) ($stored_netbox['token'] ?? '');
                }
            }
        }
        elseif (!$token_is_fresh && $token_env !== '') {
            throw new RuntimeException(
                'Save the NetBox destination and token reference before testing. '
                .'For an unsaved destination, enter a fresh inline token for this test instead.'
            );
        }

        $token = Config::resolveSecret(
            $token_form,
            $token_env,
            Config::allowsPlaintextSecrets($config) || $token_is_fresh
        );

        return [
            'url' => $url,
            'token' => $token,
            'verify_peer' => $verify_peer,
            'timeout' => max(3, min(60, $timeout))
        ];
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
