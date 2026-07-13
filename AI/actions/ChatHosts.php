<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    CWebUser,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\ZabbixApiClient;

class ChatHosts extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER && !CWebUser::isGuest();
    }

    protected function doAction(): void {
        try {
            $config = Config::get();
            $zabbix_api = ZabbixApiClient::fromFrontendOrConfig($config);

            if ($zabbix_api === null) {
                throw new \RuntimeException('Zabbix API is not available. Configure the Zabbix API token or run this from a valid Zabbix frontend session.');
            }

            $hosts = $zabbix_api->getHosts();

            AuditLogger::log($config, 'reads', [
                'event' => 'zabbix.read.hosts',
                'source' => 'ai.chat.hosts',
                'status' => 'ok',
                'meta' => [
                    'host_count' => count($hosts)
                ]
            ]);

            $this->respond([
                'ok' => true,
                'hosts' => $hosts
            ]);
        }
        catch (\Throwable $e) {
            if (isset($config)) {
                AuditLogger::log($config, 'errors', [
                    'event' => 'zabbix.read.hosts.failed',
                    'source' => 'ai.chat.hosts',
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }

            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
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
