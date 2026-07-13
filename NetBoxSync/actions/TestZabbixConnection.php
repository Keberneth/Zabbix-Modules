<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\NetBoxSync\Lib\Config,
    Modules\NetBoxSync\Lib\Util,
    Modules\NetBoxSync\Lib\ZabbixApiClient;

/** Verify the exact token-authenticated Zabbix API path used by unattended runs. */
class TestZabbixConnection extends CController {

    protected function checkInput(): bool {
        $ret = $this->validateInput([
            'url' => 'string',
            'token' => 'string',
            'token_env' => 'string',
            'verify_peer' => 'in 0,1',
            'timeout' => 'int32'
        ]);

        if (!$ret) {
            $this->respond(['ok' => false, 'error' => _('Invalid request parameters.')], 400);
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        set_time_limit(310);

        try {
            $stored = Config::get();
            $runtime = Config::sanitizeForRuntime($stored);
            $api = is_array($stored['zabbix_api'] ?? null) ? $stored['zabbix_api'] : [];

            $url = trim((string) ($_REQUEST['url'] ?? ''));
            if ($url === '') {
                $url = (string) ($api['url'] ?? '');
            }

            $token = trim((string) ($_REQUEST['token'] ?? ''));
            $token_env = Util::cleanString($_REQUEST['token_env'] ?? '', 128);
            if ($token === '' && $token_env !== '') {
                $env_value = getenv($token_env);
                if ($env_value === false || trim((string) $env_value) === '') {
                    throw new \InvalidArgumentException(sprintf(
                        _('Environment variable "%s" is not available to the PHP frontend process.'),
                        $token_env
                    ));
                }
                $token = trim((string) $env_value);
            }
            if ($token === '') {
                $token = (string) ($runtime['zabbix_api']['token'] ?? '');
            }

            $test_config = $runtime;
            $test_config['zabbix_api'] = [
                'url' => $url,
                'token' => $token,
                'token_env' => '',
                'verify_peer' => array_key_exists('verify_peer', $_REQUEST)
                    ? Util::truthy($_REQUEST['verify_peer'])
                    : !empty($api['verify_peer']),
                'timeout' => Util::cleanInt(
                    $_REQUEST['timeout'] ?? ($api['timeout'] ?? 15),
                    15, 5, 300
                )
            ];

            $hosts = ZabbixApiClient::fromConfig($test_config)->getAllHosts(1);
            $message = $hosts === []
                ? _('Connected to the Zabbix API, but this token currently sees no hosts. Check its host-group permissions.')
                : _('Connected to the Zabbix API and verified token-based host access.');

            $this->respond(['ok' => true, 'message' => $message]);
        }
        catch (\InvalidArgumentException $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);
        }
        catch (\Throwable $e) {
            error_log('NetBoxSync TestZabbixConnection: '.$e->getMessage());
            $this->respond([
                'ok' => false,
                'error' => Util::truncate($e->getMessage(), 500)
            ], 502);
        }
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $json = json_encode(
            $payload,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            $json = '{"ok":false,"error":"Failed to encode response."}';
        }

        $this->setResponse(
            (new CControllerResponseData(['main_block' => $json]))->disableView()
        );
    }
}
