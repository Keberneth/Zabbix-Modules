<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData;

class Webhook extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        // The legacy zabbix.php?action=ai.webhook route is intentionally dead.
        // Machine delivery must use the standalone /ai-webhook endpoint, where
        // any network-layer secret opt-out applies to that endpoint alone.
        return false;
    }

    protected function doAction(): void {
        $this->respond([
            'ok' => false,
            'error' => 'This legacy module route is disabled. Use the standalone /ai-webhook endpoint.'
        ], 410);
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]))->disableView()
        );
    }
}
