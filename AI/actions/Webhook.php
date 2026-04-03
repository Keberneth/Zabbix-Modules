<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util,
    Modules\AI\Lib\WebhookHandler;

class Webhook extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return true;
    }

    protected function doAction(): void {
        $config = Config::get();

        try {
            $raw = file_get_contents('php://input');
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON payload.');
            }

            $result = WebhookHandler::process($config, $decoded);
            $this->respond([
                'ok' => true,
                'posted_chunks' => (int) ($result['posted_chunks'] ?? 0),
                'reply' => (string) ($result['reply'] ?? ''),
                'result' => (string) ($result['result'] ?? '')
            ]);
        }
        catch (\Throwable $e) {
            AuditLogger::log($config, 'errors', [
                'event' => 'webhook.failed',
                'source' => 'ai.webhook',
                'status' => 'error',
                'message' => Util::truncate($e->getMessage(), 1000)
            ]);

            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
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
