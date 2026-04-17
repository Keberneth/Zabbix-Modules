<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    CUrl,
    Modules\NetBoxSync\Lib\Config,
    Modules\NetBoxSync\Lib\StateStore;

class SettingsView extends CController {

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
        $runner = $config['runner'] ?? [];
        $store = new StateStore(
            (string) ($runner['state_path'] ?? ''),
            (string) ($runner['log_path'] ?? '')
        );

        $response = new CControllerResponseData([
            'title' => _('NetBox sync settings'),
            'config' => Config::sanitizeForView($config),
            'standard_sync_catalog' => Config::standardSyncCatalog(),
            'last_summary' => $store->getLastSummary(),
            'settings_save_url' => (new CUrl('zabbix.php'))->setArgument('action', 'netboxsync.settings.save')->getUrl(),
            'run_url' => (new CUrl('zabbix.php'))->setArgument('action', 'netboxsync.run')->getUrl(),
            'runner_url' => (new CUrl('zabbix.php'))->setArgument('action', 'netboxsync.run')->getUrl(),
            'log_url' => (new CUrl('zabbix.php'))->setArgument('action', 'netboxsync.log')->getUrl(),
            'linux_plugin_url' => 'https://github.com/Keberneth/Zabbix-Plugins/tree/main/Linux/linux_service_listening_port',
            'windows_plugin_url' => 'https://github.com/Keberneth/Zabbix-Plugins/tree/main/Windows/windows_service_listening_port',
            'modules_repo_url' => 'https://github.com/Keberneth/Zabbix-Modules',
            'zabbix_module_examples_url' => 'https://github.com/Keberneth/Zabbix-Modules'
        ]);

        $this->setResponse($response);
    }
}
