<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util;

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
        $filters = [
            'source' => Util::cleanString($_GET['source'] ?? '', 128),
            'status' => Util::cleanString($_GET['status'] ?? '', 32),
            'search' => Util::cleanString($_GET['search'] ?? '', 255)
        ];

        $response = new CControllerResponseData([
            'title' => _('AI logs'),
            'summary' => AuditLogger::summary($config),
            'entries' => AuditLogger::listEntries($config, $filters, 200),
            'filters' => $filters,
            'permission_note' => AuditLogger::permissionNote()
        ]);

        $this->setResponse($response);
    }
}
