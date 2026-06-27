<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\NetBoxSync\Lib\Config,
    Modules\NetBoxSync\Lib\NetBoxClient,
    Modules\NetBoxSync\Lib\Util;

/**
 * Cheap NetBox reachability check for the settings page. Performs a single
 * GET /status/ using the values currently in the form (falling back to the
 * stored config for any blank field) and returns ok / generic-error.
 *
 * State-reading only, but kept as a CSRF-protected POST so the API token can
 * travel in the request body rather than the URL.
 */
class TestConnection extends CController {

    protected function checkInput(): bool {
        $fields = [
            'url' => 'string',
            'token' => 'string',
            'verify_peer' => 'in 0,1',
            'timeout' => 'int32'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->respond([
                'ok' => false,
                'error' => _('Invalid request parameters.')
            ], 400);
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        set_time_limit(60);

        try {
            $config = Config::sanitizeForRuntime(Config::get());

            $url = trim((string) ($_REQUEST['url'] ?? ''));
            if ($url === '') {
                $url = (string) ($config['netbox']['url'] ?? '');
            }

            $token = (string) ($_REQUEST['token'] ?? '');
            if (trim($token) === '') {
                $token = (string) ($config['netbox']['token'] ?? '');
            }

            $verify_peer = array_key_exists('verify_peer', $_REQUEST)
                ? Util::truthy($_REQUEST['verify_peer'])
                : !empty($config['netbox']['verify_peer']);

            $timeout = Util::cleanInt(
                $_REQUEST['timeout'] ?? ($config['netbox']['timeout'] ?? 15),
                15, 5, 300
            );

            if (trim($url) === '') {
                throw new \InvalidArgumentException(_('NetBox URL is not configured.'));
            }

            if (trim($token) === '') {
                throw new \InvalidArgumentException(_('NetBox API token is not configured.'));
            }

            $client = new NetBoxClient($url, $token, $verify_peer, $timeout);
            $status = $client->request('GET', '/status/');

            $version = '';
            if (is_array($status)) {
                $version = (string) ($status['netbox-version'] ?? $status['netbox_version'] ?? '');
            }

            $message = $version !== ''
                ? sprintf(_('Connected to NetBox %s.'), $version)
                : _('Connected to NetBox.');

            $this->respond([
                'ok' => true,
                'message' => $message
            ]);
        }
        catch (\InvalidArgumentException $e) {
            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
        catch (\Throwable $e) {
            error_log('NetBoxSync TestConnection: '.$e->getMessage());
            $this->respond([
                'ok' => false,
                'error' => _('Connection test failed. Check the URL, token, TLS setting, and the server error log for details.')
            ], 502);
        }
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"ok":false,"error":"Failed to encode response."}';
        }

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => $json
            ]))->disableView()
        );
    }
}
