<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\NetBoxClient,
    Modules\AI\Lib\PendingActionStore,
    Modules\AI\Lib\PromptBuilder,
    Modules\AI\Lib\ProviderClient,
    Modules\AI\Lib\Redactor,
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
        $started_at = microtime(true);

        try {
            $config = Config::get();
            $post = $_POST;
            $message = Util::cleanMultiline($post['message'] ?? '', 20000);
            $chat_session_id = Util::cleanId($post['chat_session_id'] ?? '', 'chat');

            if ($message === '') {
                throw new \RuntimeException('Message cannot be empty.');
            }

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

            $redactor = $this->buildRedactor($config, $chat_session_id);
            $zabbix_api = ZabbixApiClient::fromConfig($config);

            if ($redactor !== null) {
                $redactor->loadZabbixHostInventory($zabbix_api);
            }

            if ($context['eventid'] !== '' && $zabbix_api !== null) {
                try {
                    $problem_context = $zabbix_api->getProblemContext($context['eventid']);

                    if ($problem_context !== null) {
                        $context['problem_context'] = $problem_context;

                        if ($context['hostname'] === '' && !empty($problem_context['hostname'])) {
                            $context['hostname'] = $problem_context['hostname'];
                        }

                        if ($context['problem_summary'] === '' && !empty($problem_context['problem_summary'])) {
                            $context['problem_summary'] = $problem_context['problem_summary'];
                        }
                    }
                }
                catch (\Throwable $e) {
                    // Problem context enrichment is best-effort; do not break chat.
                }
            }

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
            ], $redactor, 'chat');

            $context_block = PromptBuilder::buildChatContextBlock($context);
            if ($context_block !== '') {
                // Chat context is built from untrusted runtime data (hostname,
                // problem summary, etc.), so it must always be redacted.
                $context_block_safe = $redactor !== null
                    ? $redactor->redactText($context_block, 'chat')
                    : $context_block;
                $system_prompt .= "\n\nCurrent chat context:\n".$context_block_safe;
            }

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

            $actions_provider = null;
            if ($actions_enabled) {
                $actions_provider = Config::getProvider($config, '', 'actions');
            }
            $active_provider = $actions_provider ?? $provider;
            // The system prompt has already been processed by PromptBuilder
            // (sensitive instruction segments + chat context block). Only
            // redact history and the current user turn here.
            $outbound_messages = $redactor !== null
                ? $redactor->redactNonSystemMessages($messages, 'chat')
                : $messages;

            $reply_masked = ProviderClient::chat(
                $active_provider,
                $outbound_messages,
                (float) ($config['chat']['temperature'] ?? 0.2)
            );

            if ($redactor !== null) {
                $redactor->save();
            }

            $reply = $redactor !== null ? $redactor->restoreText($reply_masked) : $reply_masked;

            AuditLogger::log($config, 'translations', [
                'event' => 'redaction.apply',
                'source' => 'ai.chat.send',
                'status' => 'ok',
                'provider' => $this->providerInfo($active_provider),
                'security' => $this->securityInfo($redactor),
                'payload' => [
                    'messages' => $outbound_messages,
                    'reply' => $reply_masked
                ],
                'meta' => [
                    'chat_session_id' => $chat_session_id,
                    'context_keys' => array_keys(array_filter($context, static function($value) {
                        return trim((string) $value) !== '';
                    }))
                ]
            ]);

            if ($actions_enabled && $zabbix_api !== null) {
                $tool_call = ZabbixActionExecutor::parseToolCall($reply);

                if ($tool_call !== null) {
                    $tool_name = $tool_call['tool'];
                    $tool_params = is_array($tool_call['params']) ? $tool_call['params'] : [];
                    $write_category = ZabbixActionExecutor::getWriteCategory($tool_name);

                    if ($write_category !== '') {
                        $permissions = $this->buildActionPermissions($actions_config);

                        if (($permissions['mode'] ?? 'read') !== 'readwrite') {
                            $this->logChatEvent($config, $active_provider, $redactor, 'denied', $started_at, [
                                'tool' => $tool_name,
                                'reason' => 'read_only_mode'
                            ]);
                            $this->respond([
                                'ok' => true,
                                'reply' => 'This action requires write access, but the current mode is read-only. An administrator can enable write mode in AI Settings > Zabbix Actions.',
                                'action_executed' => false,
                                'provider_name' => $active_provider['name'] ?? 'AI'
                            ]);
                            return;
                        }

                        if (empty($permissions['write_permissions'][$write_category])) {
                            $this->logChatEvent($config, $active_provider, $redactor, 'denied', $started_at, [
                                'tool' => $tool_name,
                                'reason' => 'category_disabled',
                                'category' => $write_category
                            ]);
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
                            $this->logChatEvent($config, $active_provider, $redactor, 'denied', $started_at, [
                                'tool' => $tool_name,
                                'reason' => 'super_admin_required'
                            ]);
                            $this->respond([
                                'ok' => true,
                                'reply' => 'Write actions are restricted to Super Admin users. Please contact your administrator.',
                                'action_executed' => false,
                                'provider_name' => $active_provider['name'] ?? 'AI'
                            ]);
                            return;
                        }

                        $confirm_msg = $tool_call['confirm_message'] !== ''
                            ? $tool_call['confirm_message']
                            : 'I want to execute the "'.$tool_name.'" action. Should I proceed?';

                        $action_id = PendingActionStore::create($config, $this->serverSessionKey(), [
                            'tool' => $tool_name,
                            'params' => $tool_params,
                            'provider_id' => (string) ($active_provider['id'] ?? ''),
                            'chat_session_id' => $chat_session_id,
                            'created_at' => time()
                        ]);

                        AuditLogger::log($config, 'writes', [
                            'event' => 'zabbix.write.pending',
                            'source' => 'ai.chat.send',
                            'status' => 'pending',
                            'tool' => $tool_name,
                            'provider' => $this->providerInfo($active_provider),
                            'security' => $this->securityInfo($redactor),
                            'payload' => [
                                'confirm_message' => $redactor !== null ? $redactor->redactText($confirm_msg, 'action_writes') : $confirm_msg
                            ],
                            'meta' => [
                                'action_id' => $action_id,
                                'category' => $write_category
                            ]
                        ]);

                        $this->respond([
                            'ok' => true,
                            'reply' => $confirm_msg,
                            'action_pending' => true,
                            'pending_action_id' => $action_id,
                            'pending_tool' => $tool_name,
                            'provider_name' => $active_provider['name'] ?? 'AI'
                        ]);
                        return;
                    }

                    try {
                        $tool_result = ZabbixActionExecutor::execute($tool_name, $tool_params, $zabbix_api);
                    }
                    catch (\Throwable $e) {
                        $tool_result = 'Error executing '.$tool_name.': '.$e->getMessage();
                    }

                    $tool_result_masked = $redactor !== null
                        ? $redactor->redactText($tool_result, 'action_reads')
                        : $tool_result;

                    $format_messages = $outbound_messages;
                    $format_messages[] = [
                        'role' => 'assistant',
                        'content' => $reply_masked
                    ];
                    $format_messages[] = [
                        'role' => 'user',
                        'content' => "Tool result for ".$tool_name.":\n\n".$tool_result_masked."\n\nPlease format this result for the user in a clear, readable way using Markdown. Do NOT output any JSON tool calls. Do NOT include any {\"tool\":...} blocks. Only output human-readable text."
                    ];

                    $formatted_masked = ProviderClient::chat(
                        $active_provider,
                        $format_messages,
                        (float) ($config['chat']['temperature'] ?? 0.2)
                    );

                    if ($redactor !== null) {
                        $redactor->save();
                    }

                    $formatted_reply = $redactor !== null ? $redactor->restoreText($formatted_masked) : $formatted_masked;

                    // Strip any remaining raw tool call JSON the AI may have leaked.
                    $formatted_reply = ZabbixActionExecutor::stripToolCalls($formatted_reply);

                    AuditLogger::log($config, 'reads', [
                        'event' => 'zabbix.read.executed',
                        'source' => 'ai.chat.send',
                        'status' => 'ok',
                        'tool' => $tool_name,
                        'provider' => $this->providerInfo($active_provider),
                        'security' => $this->securityInfo($redactor),
                        'payload' => [
                            'tool_result' => $tool_result_masked,
                            'formatted_reply' => $formatted_masked
                        ],
                        'meta' => [
                            'action_type' => 'read'
                        ]
                    ]);

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

            $this->logChatEvent($config, $active_provider, $redactor, 'ok', $started_at, [
                'reply' => $reply_masked,
                'message_count' => count($outbound_messages)
            ]);

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
            if (isset($config)) {
                AuditLogger::log($config, 'errors', [
                    'event' => 'chat.send.failed',
                    'source' => 'ai.chat.send',
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

    private function buildActionPermissions(array $actions_config): array {
        $permissions = [
            'mode' => (($actions_config['mode'] ?? 'read') === 'readwrite') ? 'readwrite' : 'read',
            'write_permissions' => [
                'maintenance' => false,
                'items' => false,
                'triggers' => false,
                'users' => false,
                'problems' => false,
                'hostgroups' => false
            ],
            'require_confirmation' => true,
            'current_user_type' => $this->getUserType()
        ];

        if (($permissions['mode'] ?? 'read') === 'readwrite') {
            foreach ($permissions['write_permissions'] as $category => $enabled) {
                $permissions['write_permissions'][$category] = Util::truthy($actions_config['write_permissions'][$category] ?? false);
            }
        }

        return $permissions;
    }

    private function buildRedactor(array $config, string $chat_session_id): ?Redactor {
        if ($chat_session_id === '') {
            return null;
        }

        return Redactor::forChatSession($config, $this->serverSessionKey(), $chat_session_id);
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

    private function logChatEvent(array $config, array $provider, ?Redactor $redactor, string $status, float $started_at, array $meta = []): void {
        AuditLogger::log($config, 'chat', [
            'event' => 'chat.send',
            'source' => 'ai.chat.send',
            'status' => $status,
            'provider' => $this->providerInfo($provider),
            'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
            'security' => $this->securityInfo($redactor),
            'payload' => [
                'reply' => $meta['reply'] ?? ''
            ],
            'meta' => $meta
        ]);
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
