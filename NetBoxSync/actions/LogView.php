<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    CUrl,
    Modules\NetBoxSync\Lib\Config;

class LogView extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $config = Config::get();

        $response = new CControllerResponseData([
            'title' => _('NetBox sync log'),
            'fetch_url' => (new CUrl('zabbix.php'))->setArgument('action', 'netboxsync.log.fetch')->getUrl(),
            'clear_url' => (new CUrl('zabbix.php'))->setArgument('action', 'netboxsync.log.clear')->getUrl(),
            'settings_url' => (new CUrl('zabbix.php'))->setArgument('action', 'netboxsync.settings')->getUrl(),
            'log_path' => (string) ($config['runner']['log_path'] ?? '')
        ]);

        $this->setResponse($response);
    }
}
