<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util,
    Modules\AI\Lib\ZabbixApiClient;

class ProblemContext extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        try {
            $eventid = Util::cleanString($_GET['eventid'] ?? $_POST['eventid'] ?? '', 128);

            if ($eventid === '') {
                throw new \RuntimeException('Event ID is required.');
            }

            $config = Config::get();
            $client = ZabbixApiClient::fromConfig($config);

            if ($client === null) {
                throw new \RuntimeException('Zabbix API is not configured.');
            }

            $context = $client->getProblemContext($eventid);

            if ($context === null) {
                throw new \RuntimeException('Problem event not found.');
            }

            $payload = [
                'ok' => true,
                'context' => $context
            ];

            // Return CSRF tokens so the inline JS can make POST calls
            // to chat/comment/execute endpoints without a separate fetch.
            $payload['csrf'] = [
                'field_name' => \CCsrfTokenHelper::CSRF_TOKEN_NAME,
                'chat_send' => \CCsrfTokenHelper::get('ai.chat.send'),
                'event_comment' => \CCsrfTokenHelper::get('ai.event.comment'),
                'chat_execute' => \CCsrfTokenHelper::get('ai.chat.execute')
            ];

            // Return default provider info.
            $providers = [];
            foreach ($config['providers'] as $provider) {
                if (Util::truthy($provider['enabled'] ?? false)) {
                    $providers[] = [
                        'id' => $provider['id'] ?? '',
                        'name' => $provider['name'] ?? ''
                    ];
                }
            }
            $payload['default_provider_id'] = (string) $config['default_chat_provider_id'];
            $payload['providers'] = $providers;

            // Pass configured settings to the JS.
            $payload['settings'] = [
                'auto_analyze' => Util::truthy($config['problem_inline']['auto_analyze'] ?? true),
                'item_history_period_hours' => (int) ($config['chat']['item_history_period_hours'] ?? 24),
                'item_history_max_rows' => (int) ($config['chat']['item_history_max_rows'] ?? 50)
            ];

            // Include item history only when explicitly requested.
            $include_history = Util::truthy($_GET['include_history'] ?? $_POST['include_history'] ?? false);

            if ($include_history) {
                $limit = max(5, min(500, (int) ($_GET['history_limit'] ?? $_POST['history_limit'] ?? $config['chat']['item_history_max_rows'] ?? 50)));
                $period = (int) ($config['chat']['item_history_period_hours'] ?? 24);

                try {
                    $payload['item_history'] = $client->getProblemItemHistory($eventid, $limit, $period);
                }
                catch (\Throwable $e) {
                    $payload['item_history'] = [];
                    $payload['item_history_error'] = $e->getMessage();
                }
            }

            $this->respond($payload);
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
