<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\NetBoxClient,
    Modules\AI\Lib\PromptBuilder,
    Modules\AI\Lib\ProviderClient,
    Modules\AI\Lib\Util,
    Modules\AI\Lib\ZabbixApiClient;

class ChatSend extends CController {

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        try {
            $post = $_POST;
            $message = Util::cleanMultiline($post['message'] ?? '', 20000);

            if ($message === '') {
                throw new \RuntimeException('Message cannot be empty.');
            }

            $config = Config::get();
            $provider = Config::getProvider($config, $post['provider_id'] ?? '', 'chat');

            if ($provider === null) {
                throw new \RuntimeException('No provider is configured.');
            }

            $history = Util::normalizeMessages(
                Util::decodeJsonArray($post['history_json'] ?? '[]'),
                (int) ($config['chat']['max_history_messages'] ?? 12)
            );

            $context = [
                'eventid' => Util::cleanString($post['eventid'] ?? '', 128),
                'hostname' => Util::cleanString($post['hostname'] ?? '', 255),
                'problem_summary' => Util::cleanMultiline($post['problem_summary'] ?? '', 2000),
                'extra_context' => Util::cleanMultiline($post['extra_context'] ?? '', 6000)
            ];

            $zabbix_api = ZabbixApiClient::fromConfig($config);

            if ($context['hostname'] !== '' && $zabbix_api !== null) {
                $context['os_type'] = $zabbix_api->getOsTypeByHostname($context['hostname']);
            }

            $netbox = NetBoxClient::fromConfig($config);

            if ($context['hostname'] !== '' && $netbox !== null) {
                $context['netbox_info'] = $netbox->getContextForHostname($context['hostname']);
            }

            $system_prompt = PromptBuilder::buildSystemPrompt($config, [
                'mode' => 'interactive chat',
                'response_style' => 'Reply in Markdown. Be concise but operationally useful.'
            ]);

            $context_block = PromptBuilder::buildChatContextBlock($context);

            if ($context_block !== '') {
                $system_prompt .= "\n\nCurrent chat context:\n".$context_block;
            }

            $messages = [[
                'role' => 'system',
                'content' => $system_prompt
            ]];

            foreach ($history as $item) {
                $messages[] = $item;
            }

            $messages[] = [
                'role' => 'user',
                'content' => $message
            ];

            $reply = ProviderClient::chat(
                $provider,
                $messages,
                (float) ($config['chat']['temperature'] ?? 0.2)
            );

            $this->respond([
                'ok' => true,
                'reply' => $reply,
                'provider_name' => $provider['name'] ?? ($provider['id'] ?? 'AI'),
                'context' => [
                    'os_type' => $context['os_type'] ?? '',
                    'netbox_used' => !empty($context['netbox_info'])
                ]
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
