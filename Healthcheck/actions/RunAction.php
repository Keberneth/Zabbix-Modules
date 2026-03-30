<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\Healthcheck\Lib\Config,
    Modules\Healthcheck\Lib\DbConnector,
    Modules\Healthcheck\Lib\Runner,
    Modules\Healthcheck\Lib\Storage,
    Modules\Healthcheck\Lib\Util;

class RunAction extends CController {

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $pdo = DbConnector::connect();
            Storage::ensureSchema($pdo);

            $config = Config::get($pdo);
            $check_id = Util::cleanString($_REQUEST['checkid'] ?? '', 128);
            $force = Util::truthy($_REQUEST['force'] ?? true);

            $result = Runner::runDueChecks($config, $pdo, $check_id, $force);

            $this->respond($result, $result['ok'] ? 200 : 200);
        }
        catch (\Throwable $e) {
            $this->respond([
                'ok' => false,
                'message' => Util::truncate($e->getMessage(), 1000)
            ], 500);
        }
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ]))->disableView()
        );
    }
}
