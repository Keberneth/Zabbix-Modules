<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    CUrl,
    CWebUser,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config;

class LogsView extends CController {

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

        $clear_request = AuditLogger::clearRequest($config);
        $current_userid = (string) (CWebUser::$data['userid'] ?? '');

        $response = new CControllerResponseData([
            'title' => _('AI logs'),
            'summary' => AuditLogger::summary($config),
            'permission_note' => AuditLogger::permissionNote(),
            'clear_pending' => $clear_request['pending'],
            'clear_requested_by' => $clear_request['username'],
            'clear_requested_by_me' => $clear_request['pending'] && $clear_request['userid'] === $current_userid,
            'clear_audit' => AuditLogger::readClearAudit($config, 50),
            'fetch_url' => (new CUrl('zabbix.php'))->setArgument('action', 'ai.logs.fetch')->getUrl(),
            'clear_url' => (new CUrl('zabbix.php'))->setArgument('action', 'ai.logs.clear')->getUrl(),
            'export_url' => (new CUrl('zabbix.php'))->setArgument('action', 'ai.logs.export')->getUrl(),
            'settings_url' => (new CUrl('zabbix.php'))->setArgument('action', 'ai.settings')->getUrl(),
            'chat_url' => (new CUrl('zabbix.php'))->setArgument('action', 'ai.chat')->getUrl()
        ]);

        $this->setResponse($response);
    }
}
