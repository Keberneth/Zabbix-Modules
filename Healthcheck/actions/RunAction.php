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
        $fields = [
            'checkid' => 'string',
            'force' => 'in 0,1'
        ];

        return $this->validateInput($fields);
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            $pdo = DbConnector::connect();
            Storage::ensureSchema($pdo);

            $config = Config::get($pdo);
            $check_id = Util::cleanString($this->getInput('checkid', ''), 128);
            $force = Util::truthy($this->getInput('force', '0'));

            $result = Runner::runDueChecks($config, $pdo, $check_id, $force);

            $this->respond($result, 200);
        }
        catch (\InvalidArgumentException $e) {
            $this->respond([
                'ok' => false,
                'message' => $e->getMessage()
            ], 400);
        }
        catch (\Throwable $e) {
            error_log('Healthcheck RunAction: '.$e->getMessage());

            $this->respond([
                'ok' => false,
                'message' => _('An internal error occurred while running the health check.')
            ], 500);
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
            $json = '{"ok":false,"message":"Failed to encode response."}';
        }

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => $json
            ]))->disableView()
        );
    }
}
