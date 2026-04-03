<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\PendingActionStore,
    Modules\AI\Lib\PromptBuilder,
    Modules\AI\Lib\ProviderClient,
    Modules\AI\Lib\Redactor,
    Modules\AI\Lib\Util,
    Modules\AI\Lib\ZabbixActionExecutor,
    Modules\AI\Lib\ZabbixApiClient;

class ChatExecute extends CController {

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $started_at = microtime(true);

        try {
            $config = Config::get();
            $post = $_POST;
            $chat_session_id = Util::cleanId($post['chat_session_id'] ?? '', 'chat');
            $action_id = Util::cleanId($post['action_id'] ?? '', 'action');

            if ($action_id !== '') {
                $pending = PendingActionStore::consume($config, $this->serverSessionKey(), $action_id);
                $tool_name = Util::cleanString($pending['tool'] ?? '', 64);
                $tool_params = is_array($pending['params'] ?? null) ? $pending['params'] : [];
                $provider_id = Util::cleanString($pending['provider_id'] ?? '', 128);
                if ($chat_session_id === '') {
                    $chat_session_id = Util::cleanId($pending['chat_session_id'] ?? '', 'chat');
                }
            }
            else {
                // Legacy fallback. Prefer action_id from server-side pending storage.
                $tool_name = Util::cleanString($post['tool'] ?? '', 64);
                $tool_params = Util::decodeJsonArray($post['params_json'] ?? '{}');
                $provider_id = Util::cleanString($post['provider_id'] ?? '', 128);
            }

            if ($tool_name === '') {
                throw new \RuntimeException('Tool name is required.');
            }

            $all_tools = ZabbixActionExecutor::allTools();
            if (!isset($all_tools[$tool_name])) {
                throw new \RuntimeException('Unknown tool: '.$tool_name);
            }

            $actions_config = $config['zabbix_actions'] ?? [];
            if (!Util::truthy($actions_config['enabled'] ?? false)) {
                throw new \RuntimeException('Zabbix actions are not enabled.');
            }

            $zabbix_api = ZabbixApiClient::fromConfig($config);
            if ($zabbix_api === null) {
                throw new \RuntimeException('Zabbix API is not configured.');
            }

            $write_category = ZabbixActionExecutor::getWriteCategory($tool_name);
            if ($write_category !== '') {
                if (($actions_config['mode'] ?? 'read') !== 'readwrite') {
                    throw new \RuntimeException('Write access is not enabled.');
                }

                $wp = $actions_config['write_permissions'] ?? [];
                if (empty($wp[$write_category])) {
                    throw new \RuntimeException('Write permission for "'.$write_category.'" is not enabled.');
                }

                if (Util::truthy($actions_config['require_super_admin_for_write'] ?? true)
                    && $this->getUserType() < USER_TYPE_SUPER_ADMIN) {
                    throw new \RuntimeException('Write actions require Super Admin privileges.');
                }
            }

            $redactor = $chat_session_id !== ''
                ? Redactor::forChatSession($config, $this->serverSessionKey(), $chat_session_id)
                : null;

            $tool_result = ZabbixActionExecutor::execute($tool_name, $tool_params, $zabbix_api);
            $tool_result_masked = $redactor !== null
                ? $redactor->redactText($tool_result, 'action_formatting')
                : $tool_result;

            $provider = Config::getProvider($config, $provider_id, 'actions');
            if ($provider === null) {
                $provider = Config::getProvider($config, '', 'chat');
            }

            if ($provider !== null) {
                $system_prompt = PromptBuilder::buildSystemPrompt($config, [
                    'mode' => 'interactive chat',
                    'response_style' => 'Reply in Markdown. Be concise but operationally useful.'
                ]);

                $messages = [
                    ['role' => 'system', 'content' => $redactor !== null ? $redactor->redactText($system_prompt, 'action_formatting') : $system_prompt],
                    ['role' => 'user', 'content' => "The following Zabbix action was executed successfully.\n\nTool: "
                        .$tool_name
                        ."\nResult:\n"
                        .$tool_result_masked
                        ."\n\nPlease summarize this result for the user in a clear, readable way using Markdown. Do not output a JSON tool call."]
                ];

                try {
                    $formatted_masked = ProviderClient::chat(
                        $provider,
                        $messages,
                        (float) ($config['chat']['temperature'] ?? 0.2)
                    );
                    $formatted = $redactor !== null ? $redactor->restoreText($formatted_masked) : $formatted_masked;
                }
                catch (\Throwable $e) {
                    $formatted_masked = $tool_result_masked;
                    $formatted = $tool_result;
                }
            }
            else {
                $formatted_masked = $tool_result_masked;
                $formatted = $tool_result;
            }

            if ($redactor !== null) {
                $redactor->save();
            }

            AuditLogger::log($config, 'writes', [
                'event' => 'zabbix.write.executed',
                'source' => 'ai.chat.execute',
                'status' => 'ok',
                'tool' => $tool_name,
                'provider' => $this->providerInfo($provider),
                'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
                'security' => $this->securityInfo($redactor),
                'payload' => [
                    'tool_result' => $tool_result_masked,
                    'formatted_reply' => $formatted_masked
                ],
                'meta' => [
                    'category' => $write_category,
                    'action_id' => $action_id
                ]
            ]);

            $this->respond([
                'ok' => true,
                'reply' => $formatted,
                'action_executed' => true,
                'action_tool' => $tool_name
            ]);
        }
        catch (\Throwable $e) {
            if (isset($config)) {
                AuditLogger::log($config, 'errors', [
                    'event' => 'chat.execute.failed',
                    'source' => 'ai.chat.execute',
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'duration_ms' => (int) round((microtime(true) - $started_at) * 1000)
                ]);
            }

            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    private function providerInfo(?array $provider): array {
        if (!is_array($provider)) {
            return [];
        }

        return array_filter([
            'id' => (string) ($provider['id'] ?? ''),
            'name' => (string) ($provider['name'] ?? ''),
            'type' => (string) ($provider['type'] ?? ''),
            'model' => (string) ($provider['model'] ?? '')
        ], static function($value) {
            return trim((string) $value) !== '';
        });
    }

    private function securityInfo(?Redactor $redactor): array {
        if ($redactor === null) {
            return [];
        }

        return [
            'enabled' => $redactor->isEnabled(),
            'stats' => $redactor->stats(),
            'mapping_details' => $redactor->mappingDetails(100)
        ];
    }

    private function serverSessionKey(): string {
        $sid = (string) session_id();
        if ($sid !== '') {
            return $sid;
        }

        if (class_exists('CWebUser') && isset(\CWebUser::$data) && is_array(\CWebUser::$data)) {
            $uid = (string) (\CWebUser::$data['userid'] ?? '');
            if ($uid !== '') {
                return 'user:'.$uid;
            }
        }

        return 'remote:'.Util::cleanString($_SERVER['REMOTE_ADDR'] ?? 'unknown', 128);
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
