<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\Healthcheck\Lib\Config,
    Modules\Healthcheck\Lib\DbConnector,
    Modules\Healthcheck\Lib\Storage,
    Modules\Healthcheck\Lib\Util;

class SettingsSave extends CController {

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

            $current = Config::get($pdo);
            $new_config = Config::buildFromPost($_POST, $current);
            Config::save($new_config, $pdo);

            $this->respond([
                'ok' => true,
                'message' => _('Healthcheck settings updated.')
            ]);
        }
        catch (\Throwable $e) {
            $this->respond([
                'ok' => false,
                'error' => Util::truncate($e->getMessage(), 1000)
            ], 400);
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
