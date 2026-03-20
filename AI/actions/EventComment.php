<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util,
    Modules\AI\Lib\ZabbixApiClient;

class EventComment extends CController {

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        try {
            $post = $_POST;
            $eventid = Util::cleanString($post['eventid'] ?? '', 128);
            $message = Util::cleanMultiline($post['message'] ?? '', 100000);

            if ($eventid === '') {
                throw new \RuntimeException('Event ID is required.');
            }

            if ($message === '') {
                throw new \RuntimeException('Message cannot be empty.');
            }

            $config = Config::get();
            $client = ZabbixApiClient::fromConfig($config);

            if ($client === null) {
                throw new \RuntimeException('Zabbix API token is not configured in AI settings.');
            }

            $chunks = $client->addProblemComment(
                $eventid,
                $message,
                (int) ($config['webhook']['problem_update_action'] ?? 4),
                (int) ($config['webhook']['comment_chunk_size'] ?? 1900)
            );

            $this->respond([
                'ok' => true,
                'chunks' => count($chunks)
            ]);
        }
        catch (\Throwable $e) {
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
