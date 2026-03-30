<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\Healthcheck\Lib\Config,
    Modules\Healthcheck\Lib\DbConnector,
    Modules\Healthcheck\Lib\Storage;

class SettingsView extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $pdo = DbConnector::connect();
        Storage::ensureSchema($pdo);

        $config = Config::sanitizeForView(Config::get($pdo));

        $response = new CControllerResponseData([
            'title' => _('Healthcheck settings'),
            'config' => $config,
            'runner_script_path' => realpath(__DIR__.'/../bin/healthcheck-runner.php') ?: (__DIR__.'/../bin/healthcheck-runner.php')
        ]);

        $this->setResponse($response);
    }
}
