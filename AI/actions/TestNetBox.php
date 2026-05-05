<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\HttpClient,
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
                throw new RuntimeException('NetBox token is required (enter one in the form, set the env variable, or keep the stored token).');
            }

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

        $url = trim((string) ($post['url'] ?? ''));
        $token_form = trim((string) ($post['token'] ?? ''));
        $token_env = trim((string) ($post['token_env'] ?? ''));
        $verify_peer = !empty($post['verify_peer']);
        $timeout = (int) ($post['timeout'] ?? 10);
        $clear_token = !empty($post['clear_token']);

        // Fall back to stored token if form value is empty and the user didn't
        // tick "Clear stored token".
        if ($token_form === '' && !$clear_token) {
            $config = Config::get();
            $token_form = (string) ($config['netbox']['token'] ?? '');

            if ($token_env === '') {
                $token_env = (string) ($config['netbox']['token_env'] ?? '');
            }
        }

        $token = Config::resolveSecret($token_form, $token_env);

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
