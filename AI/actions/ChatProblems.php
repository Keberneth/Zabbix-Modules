<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util,
    Modules\AI\Lib\ZabbixApiClient;

class ChatProblems extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        try {
            $config = Config::get();
            $zabbix_api = ZabbixApiClient::fromConfig($config);

            if ($zabbix_api === null) {
                throw new \RuntimeException('Zabbix API is not configured.');
            }

            $hostid = Util::cleanString($_GET['hostid'] ?? '', 128);
            $search = Util::cleanString($_GET['search'] ?? '', 255);
            $problems = $zabbix_api->getProblems($hostid !== '' ? $hostid : null, $search, 50);

            AuditLogger::log($config, 'reads', [
                'event' => 'zabbix.read.problems',
                'source' => 'ai.chat.problems',
                'status' => 'ok',
                'meta' => [
                    'hostid_supplied' => $hostid !== '',
                    'search_supplied' => $search !== '',
                    'problem_count' => count($problems)
                ]
            ]);

            $this->respond([
                'ok' => true,
                'problems' => $problems
            ]);
        }
        catch (\Throwable $e) {
            if (isset($config)) {
                AuditLogger::log($config, 'errors', [
                    'event' => 'zabbix.read.problems.failed',
                    'source' => 'ai.chat.problems',
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
