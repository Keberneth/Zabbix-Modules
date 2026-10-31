<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController;
use CControllerResponseData;
use Modules\NetBoxSync\Lib\Config;
use Modules\NetBoxSync\Lib\StateStore;

/** Read-only status for the authenticated settings page. */
class RunStatus extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $valid = $this->validateInput(['run_id' => 'string']);
        if ($valid && array_key_exists('run_id', $_REQUEST)) {
            $valid = preg_match('/\A[0-9a-f]{32}\z/i', (string) $_REQUEST['run_id']) === 1;
        }
        if (!$valid) {
            $this->respond(['ok' => false, 'error' => _('Invalid sync run identifier.')], 400);
        }
        return $valid;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        try {
            $runner = Config::get()['runner'] ?? [];
            $store = new StateStore((string) ($runner['state_path'] ?? ''), (string) ($runner['log_path'] ?? ''));
            $this->respond(['ok' => true, 'progress' => $store->getProgress((string) ($_REQUEST['run_id'] ?? ''))]);
        }
        catch (\Throwable $e) {
            error_log('NetBoxSync RunStatus: '.$e->getMessage());
            $this->respond(['ok' => false, 'error' => _('Unable to read sync progress. Check state-directory permissions and the server error log.')], 500);
        }
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->setResponse((new CControllerResponseData(['main_block' => $json === false ? '{"ok":false}' : $json]))->disableView());
    }
}
