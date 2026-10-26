<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';
require_once __DIR__.'/../lib/SetupHealth.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\SetupHealth,
    Modules\AI\Lib\ZabbixActionExecutor;

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
        $raw_config = Config::get();
        $health = SetupHealth::check($raw_config);
        $config = Config::sanitizeForView($raw_config);

        AuditLogger::log($config, 'user_activity', [
            'event' => 'settings.view',
            'source' => 'ai.settings',
            'status' => 'ok'
        ]);

        $response = new CControllerResponseData([
            'title' => _('AI settings'),
            'config' => $config,
            'health' => $health,
            'log_summary' => AuditLogger::summary($config),
            'permission_note' => AuditLogger::permissionNote(),
            'actions_catalog' => ZabbixActionExecutor::allTools()
        ]);

        $this->setResponse($response);
    }
}
