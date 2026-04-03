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
    Modules\AI\Lib\ZabbixActionExecutor,
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

            // Append Zabbix action tools if enabled and Zabbix API is configured.
            $actions_config = $config['zabbix_actions'] ?? [];
            $actions_enabled = Util::truthy($actions_config['enabled'] ?? false) && $zabbix_api !== null;

            if ($actions_enabled) {
                $permissions = $this->buildActionPermissions($actions_config);
                $actions_prompt = PromptBuilder::buildActionsSystemPrompt($config, $permissions);

                if ($actions_prompt !== '') {
                    $system_prompt .= "\n\n".$actions_prompt;
                }
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

            // Use the actions provider if configured and actions are enabled.
            $actions_provider = null;
            if ($actions_enabled) {
                $actions_provider = Config::getProvider($config, '', 'actions');
            }
            $active_provider = $actions_provider ?? $provider;

            $reply = ProviderClient::chat(
                $active_provider,
                $messages,
                (float) ($config['chat']['temperature'] ?? 0.2)
            );

            // Check if the AI response is a tool call.
            if ($actions_enabled && $zabbix_api !== null) {
                $tool_call = ZabbixActionExecutor::parseToolCall($reply);

                if ($tool_call !== null) {
                    $tool_name = $tool_call['tool'];
                    $tool_params = $tool_call['params'];
                    $write_category = ZabbixActionExecutor::getWriteCategory($tool_name);

                    // Check permissions for write actions.
                    if ($write_category !== '') {
                        $permissions = $this->buildActionPermissions($actions_config);

                        if (($permissions['mode'] ?? 'read') !== 'readwrite') {
                            $this->respond([
                                'ok' => true,
                                'reply' => 'This action requires write access, but the current mode is read-only. An administrator can enable write mode in AI Settings > Zabbix Actions.',
                                'action_executed' => false,
                                'provider_name' => $active_provider['name'] ?? 'AI'
                            ]);
                            return;
                        }

                        if (empty($permissions['write_permissions'][$write_category])) {
                            $this->respond([
                                'ok' => true,
                                'reply' => 'This action requires "'.$write_category.'" write permission, which is not enabled. An administrator can enable it in AI Settings > Zabbix Actions.',
                                'action_executed' => false,
                                'provider_name' => $active_provider['name'] ?? 'AI'
                            ]);
                            return;
                        }

                        if (Util::truthy($actions_config['require_super_admin_for_write'] ?? true)
                            && $this->getUserType() < USER_TYPE_SUPER_ADMIN) {
                            $this->respond([
                                'ok' => true,
                                'reply' => 'Write actions are restricted to Super Admin users. Please contact your administrator.',
                                'action_executed' => false,
                                'provider_name' => $active_provider['name'] ?? 'AI'
                            ]);
                            return;
                        }

                        // Write action — return confirmation request to user.
                        $confirm_msg = $tool_call['confirm_message'] !== ''
                            ? $tool_call['confirm_message']
                            : 'I want to execute the "'.$tool_name.'" action. Should I proceed?';

                        $this->respond([
                            'ok' => true,
                            'reply' => $confirm_msg,
                            'action_pending' => true,
                            'pending_tool' => $tool_name,
                            'pending_params' => $tool_params,
                            'provider_name' => $active_provider['name'] ?? 'AI'
                        ]);
                        return;
                    }

                    // Read action — execute immediately.
                    try {
                        $tool_result = ZabbixActionExecutor::execute($tool_name, $tool_params, $zabbix_api);
                    }
                    catch (\Throwable $e) {
                        $tool_result = 'Error executing '.$tool_name.': '.$e->getMessage();
                    }

                    // Send the result back to the AI for a human-readable summary.
                    $format_messages = $messages;
                    $format_messages[] = [
                        'role' => 'assistant',
                        'content' => $reply
                    ];
                    $format_messages[] = [
                        'role' => 'user',
                        'content' => "Tool result for ".$tool_name.":\n\n".$tool_result."\n\nPlease format this result for the user in a clear, readable way using Markdown. Do not output a JSON tool call."
                    ];

                    $formatted_reply = ProviderClient::chat(
                        $active_provider,
                        $format_messages,
                        (float) ($config['chat']['temperature'] ?? 0.2)
                    );

                    $this->respond([
                        'ok' => true,
                        'reply' => $formatted_reply,
                        'action_executed' => true,
                        'action_tool' => $tool_name,
                        'provider_name' => $active_provider['name'] ?? 'AI',
                        'context' => [
                            'os_type' => $context['os_type'] ?? '',
                            'netbox_used' => !empty($context['netbox_info'])
                        ]
                    ]);
                    return;
                }
            }

            $this->respond([
                'ok' => true,
                'reply' => $reply,
                'provider_name' => $active_provider['name'] ?? ($active_provider['id'] ?? 'AI'),
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

    /**
     * Build the effective permissions array for Zabbix actions,
     * taking user type into account.
     */
    private function buildActionPermissions(array $actions_config): array {
        $permissions = [
            'mode' => $actions_config['mode'] ?? 'read',
            'write_permissions' => $actions_config['write_permissions'] ?? []
        ];

        // If write requires super admin and user is not, downgrade to read.
        if ($permissions['mode'] === 'readwrite'
            && Util::truthy($actions_config['require_super_admin_for_write'] ?? true)
            && $this->getUserType() < USER_TYPE_SUPER_ADMIN) {
            $permissions['mode'] = 'read';
        }

        return $permissions;
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
