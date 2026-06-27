<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\NetBoxSync\Lib\Config,
    Modules\NetBoxSync\Lib\LogStore;

class LogClear extends CController {

    protected function checkInput(): bool {
        // State-changing endpoint: no extra parameters beyond the framework CSRF
        // token, which is validated separately (CSRF is NOT disabled here).
        $ret = $this->validateInput([]);

        if (!$ret) {
            $this->respond([
                'ok' => false,
                'error' => _('Invalid request parameters.')
            ], 400);
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $config = Config::get();
            $log_path = (string) ($config['runner']['log_path'] ?? '');
            $removed = (new LogStore($log_path))->clear();

            $this->respond([
                'ok' => true,
                'removed' => $removed
            ]);
        }
        catch (\InvalidArgumentException $e) {
            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
        catch (\Throwable $e) {
            error_log('NetBoxSync LogClear: '.$e->getMessage());
            $this->respond([
                'ok' => false,
                'error' => _('An internal error occurred while clearing the log. Check the server error log for details.')
            ], 500);
        }
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"ok":false,"error":"Failed to encode response."}';
        }

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => $json
            ]))->disableView()
        );
    }
}
