<?php declare(strict_types = 0);

namespace Modules\AI\Lib;

use RuntimeException;

class WebhookHandler {

    public static function process(array $config, array $decoded): array {
        $config = Config::mergeWithDefaults($config);

        if (!Util::truthy($config['webhook']['enabled'] ?? false)) {
            throw new RuntimeException('AI webhook is disabled.');
        }

        $payload = self::normalizePayload($decoded);
        self::validateSecret($config, $payload);

        if (Util::truthy($config['webhook']['skip_resolved'] ?? false)
            && (string) ($payload['event_value'] ?? '1') === '0') {
            AuditLogger::log($config, 'webhook', [
                'event' => 'webhook.skip_resolved',
                'source' => 'ai.webhook',
                'status' => 'ok',
                'meta' => [
                    'eventid' => (string) ($payload['eventid'] ?? '')
                ]
            ]);

            return [
                'ok' => true,
                'result' => 'resolved event ignored',
                'posted_chunks' => 0,
                'payload' => $payload,
                'reply' => ''
            ];
        }

        $provider = Config::getProvider($config, '', 'webhook');
        if ($provider === null) {
            throw new RuntimeException('No provider is configured for webhook use.');
        }

        $context = [];
        $zabbix_api = ZabbixApiClient::fromConfig($config);

        if (!empty($payload['hostname'])
            && Util::truthy($config['webhook']['include_os_hint'] ?? false)
            && $zabbix_api !== null) {
            $context['os_type'] = $zabbix_api->getOsTypeByHostname($payload['hostname']);
        }

        $netbox = NetBoxClient::fromConfig($config);
        if (!empty($payload['hostname'])
            && Util::truthy($config['webhook']['include_netbox'] ?? false)
            && $netbox !== null) {
            $context['netbox_info'] = $netbox->getContextForHostname($payload['hostname']);
        }

        $system_prompt = PromptBuilder::buildSystemPrompt($config, [
            'mode' => 'webhook automation',
            'response_style' => 'Focus on safe first-line troubleshooting guidance and keep the answer operational.'
        ]);
        $user_prompt = PromptBuilder::buildWebhookUserPrompt($payload, $context);

        $redactor = Redactor::forEphemeral($config);
        $messages = [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $user_prompt]
        ];
        $masked_messages = $redactor->redactMessages($messages, 'webhook');
        $reply_masked = ProviderClient::chat(
            $provider,
            $masked_messages,
            (float) ($config['chat']['temperature'] ?? 0.2)
        );
        $reply = $redactor->restoreText($reply_masked);

        $posted_chunks = 0;
        if (Util::truthy($config['webhook']['add_problem_update'] ?? false)) {
            if (empty($payload['eventid'])) {
                throw new RuntimeException('Event ID is required to post an update back to Zabbix.');
            }

            if ($zabbix_api === null) {
                throw new RuntimeException('Zabbix API token is not configured in AI settings.');
            }

            $chunks = $zabbix_api->addProblemComment(
                (string) $payload['eventid'],
                $reply,
                (int) ($config['webhook']['problem_update_action'] ?? 4),
                (int) ($config['webhook']['comment_chunk_size'] ?? 1900)
            );
            $posted_chunks = count($chunks);
        }

        AuditLogger::log($config, 'translations', [
            'event' => 'redaction.apply',
            'source' => 'ai.webhook',
            'status' => 'ok',
            'provider' => self::providerInfo($provider),
            'security' => [
                'enabled' => $redactor->isEnabled(),
                'stats' => $redactor->stats(),
                'mapping_details' => $redactor->mappingDetails(100)
            ],
            'payload' => [
                'messages' => $masked_messages,
                'reply' => $reply_masked
            ]
        ]);

        AuditLogger::log($config, 'webhook', [
            'event' => 'webhook.processed',
            'source' => 'ai.webhook',
            'status' => 'ok',
            'provider' => self::providerInfo($provider),
            'payload' => [
                'request' => $redactor->redactText(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'webhook'),
                'reply' => $reply_masked
            ],
            'meta' => [
                'eventid' => (string) ($payload['eventid'] ?? ''),
                'posted_chunks' => $posted_chunks,
                'has_netbox' => !empty($context['netbox_info']),
                'has_os_hint' => !empty($context['os_type'])
            ],
            'security' => [
                'enabled' => $redactor->isEnabled(),
                'stats' => $redactor->stats(),
                'mapping_details' => $redactor->mappingDetails(100)
            ]
        ]);

        return [
            'ok' => true,
            'payload' => $payload,
            'reply' => $reply,
            'reply_masked' => $reply_masked,
            'posted_chunks' => $posted_chunks
        ];
    }

    public static function loadConfigFromPdo(\PDO $pdo): array {
        $module_id = Config::MODULE_ID;

        $stmt = $pdo->prepare('SELECT config FROM module WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $module_id]);
        $row = $stmt->fetch();

        if (!$row || empty($row['config'])) {
            return Config::mergeWithDefaults([]);
        }

        $decoded = json_decode($row['config'], true);

        return Config::mergeWithDefaults(is_array($decoded) ? $decoded : []);
    }

    public static function normalizePayload(array $payload): array {
        if (isset($payload['message']) && is_string($payload['message'])) {
            $message_payload = json_decode($payload['message'], true);

            if (is_array($message_payload)) {
                $payload = array_replace($payload, $message_payload);
            }
        }

        $normalized = [
            'eventid' => Util::cleanString($payload['eventid'] ?? $payload['event_id'] ?? '', 128),
            'event_value' => Util::cleanString($payload['event_value'] ?? $payload['value'] ?? '1', 16),
            'trigger_name' => Util::cleanMultiline(
                $payload['trigger_name'] ?? $payload['problem_name'] ?? $payload['subject'] ?? $payload['name'] ?? '',
                2000
            ),
            'hostname' => Util::cleanString(
                $payload['hostname'] ?? $payload['host'] ?? $payload['host_name'] ?? '',
                255
            ),
            'severity' => Util::cleanString(
                $payload['severity'] ?? $payload['severity_name'] ?? $payload['trigger_severity'] ?? '',
                128
            ),
            'opdata' => Util::cleanMultiline($payload['opdata'] ?? $payload['operational_data'] ?? '', 4000),
            'event_url' => Util::cleanUrl($payload['event_url'] ?? $payload['url'] ?? ''),
            'shared_secret' => Util::cleanString($payload['shared_secret'] ?? '', 512)
        ];

        $event_tags = $payload['event_tags'] ?? $payload['tags'] ?? $payload['event_tags_json'] ?? [];
        $normalized['event_tags_text'] = self::normalizeTags($event_tags);

        return $normalized;
    }

    public static function normalizeTags($event_tags): string {
        if (is_string($event_tags)) {
            $event_tags = trim($event_tags);

            if ($event_tags === '' || preg_match('/^\{[A-Z0-9_.]+\}$/', $event_tags)) {
                return '';
            }

            $trimmed = ltrim($event_tags);
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                $decoded = json_decode($event_tags, true);
                if (is_array($decoded)) {
                    return Util::formatTags($decoded);
                }
            }

            return Util::cleanMultiline($event_tags, 4000);
        }

        return Util::formatTags($event_tags);
    }

    public static function validateSecret(array $config, array $payload): void {
        $expected = Config::resolveSecret(
            $config['webhook']['shared_secret'] ?? '',
            $config['webhook']['shared_secret_env'] ?? ''
        );

        if ($expected === '') {
            return;
        }

        $provided = trim((string) ($_SERVER['HTTP_X_AI_WEBHOOK_SECRET'] ?? $payload['shared_secret'] ?? ''));
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new RuntimeException('Invalid webhook shared secret.');
        }
    }

    private static function providerInfo(?array $provider): array {
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
}
