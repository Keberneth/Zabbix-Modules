<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\PromptBuilder,
    Modules\AI\Lib\ProviderClient,
    Modules\AI\Lib\Util,
    Modules\AI\Lib\ZabbixActionExecutor,
    Modules\AI\Lib\ZabbixApiClient;

/**
 * Executes a confirmed Zabbix write action.
 *
 * The user has already seen the confirmation message from ChatSend and clicked
 * "Confirm". This endpoint re-validates permissions and executes the tool.
 */
class ChatExecute extends CController {

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        try {
            $post = $_POST;

            $tool_name = Util::cleanString($post['tool'] ?? '', 64);
            $tool_params = Util::decodeJsonArray($post['params_json'] ?? '{}');

            if ($tool_name === '') {
                throw new \RuntimeException('Tool name is required.');
            }

            $all_tools = ZabbixActionExecutor::allTools();

            if (!isset($all_tools[$tool_name])) {
                throw new \RuntimeException('Unknown tool: '.$tool_name);
            }

            $config = Config::get();
            $actions_config = $config['zabbix_actions'] ?? [];

            if (!Util::truthy($actions_config['enabled'] ?? false)) {
                throw new \RuntimeException('Zabbix actions are not enabled.');
            }

            $zabbix_api = ZabbixApiClient::fromConfig($config);

            if ($zabbix_api === null) {
                throw new \RuntimeException('Zabbix API is not configured.');
            }

            // Check write permissions.
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

            // Execute the tool.
            $tool_result = ZabbixActionExecutor::execute($tool_name, $tool_params, $zabbix_api);

            // Send the result to the AI for formatting.
            $provider = Config::getProvider($config, $post['provider_id'] ?? '', 'actions');

            if ($provider === null) {
                $provider = Config::getProvider($config, '', 'chat');
            }

            if ($provider !== null) {
                $system_prompt = PromptBuilder::buildSystemPrompt($config, [
                    'mode' => 'interactive chat',
                    'response_style' => 'Reply in Markdown. Be concise but operationally useful.'
                ]);

                $messages = [
                    ['role' => 'system', 'content' => $system_prompt],
                    ['role' => 'user', 'content' => "The following Zabbix action was executed successfully.\n\nTool: ".$tool_name."\nResult:\n".$tool_result."\n\nPlease summarize this result for the user in a clear, readable way using Markdown. Do not output a JSON tool call."]
                ];

                try {
                    $formatted = ProviderClient::chat(
                        $provider,
                        $messages,
                        (float) ($config['chat']['temperature'] ?? 0.2)
                    );
                }
                catch (\Throwable $e) {
                    $formatted = $tool_result;
                }
            }
            else {
                $formatted = $tool_result;
            }

            $this->respond([
                'ok' => true,
                'reply' => $formatted,
                'action_executed' => true,
                'action_tool' => $tool_name,
                'raw_result' => $tool_result
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
