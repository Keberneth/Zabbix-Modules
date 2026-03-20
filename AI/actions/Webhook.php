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

class Webhook extends CController {

    public function init(): void {
        // This endpoint is called directly by the Zabbix webhook media type and therefore
        // must not require a frontend SID.
        $this->disableSIDValidation();
        $this->setPostContentType(self::POST_CONTENT_TYPE_JSON);
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return true;
    }

    protected function doAction(): void {
        try {
            $config = Config::get();

            if (!Util::truthy($config['webhook']['enabled'] ?? false)) {
                throw new \RuntimeException('AI webhook is disabled.');
            }

            $raw = file_get_contents('php://input');
            $decoded = json_decode((string) $raw, true);

            if (!is_array($decoded)) {
                throw new \RuntimeException('Invalid JSON payload.');
            }

            $payload = $this->normalizePayload($decoded);
            $this->validateSharedSecret($config, $payload);

            if (Util::truthy($config['webhook']['skip_resolved'] ?? false)
                    && (string) ($payload['event_value'] ?? '1') === '0') {
                $this->respond([
                    'ok' => true,
                    'result' => 'resolved event ignored'
                ]);
                return;
            }

            $provider = Config::getProvider($config, '', 'webhook');

            if ($provider === null) {
                throw new \RuntimeException('No provider is configured for webhook use.');
            }

            $context = [];
            $zabbix_api = ZabbixApiClient::fromConfig($config);

            if (!empty($payload['hostname']) && Util::truthy($config['webhook']['include_os_hint'] ?? false)
                    && $zabbix_api !== null) {
                $context['os_type'] = $zabbix_api->getOsTypeByHostname($payload['hostname']);
            }

            $netbox = NetBoxClient::fromConfig($config);

            if (!empty($payload['hostname']) && Util::truthy($config['webhook']['include_netbox'] ?? false)
                    && $netbox !== null) {
                $context['netbox_info'] = $netbox->getContextForHostname($payload['hostname']);
            }

            $system_prompt = PromptBuilder::buildSystemPrompt($config, [
                'mode' => 'webhook automation',
                'response_style' => 'Focus on safe first-line troubleshooting guidance and keep the answer operational.'
            ]);

            $user_prompt = PromptBuilder::buildWebhookUserPrompt($payload, $context);

            $reply = ProviderClient::chat($provider, [
                [
                    'role' => 'system',
                    'content' => $system_prompt
                ],
                [
                    'role' => 'user',
                    'content' => $user_prompt
                ]
            ], (float) ($config['chat']['temperature'] ?? 0.2));

            $posted_chunks = 0;

            if (Util::truthy($config['webhook']['add_problem_update'] ?? false)) {
                if (empty($payload['eventid'])) {
                    throw new \RuntimeException('Event ID is required to post an update back to Zabbix.');
                }

                if ($zabbix_api === null) {
                    throw new \RuntimeException('Zabbix API token is not configured in AI settings.');
                }

                $chunks = $zabbix_api->addProblemComment(
                    (string) $payload['eventid'],
                    $reply,
                    (int) ($config['webhook']['problem_update_action'] ?? 4),
                    (int) ($config['webhook']['comment_chunk_size'] ?? 1900)
                );

                $posted_chunks = count($chunks);
            }

            $this->respond([
                'ok' => true,
                'posted_chunks' => $posted_chunks,
                'reply' => $reply
            ]);
        }
        catch (\Throwable $e) {
            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    private function normalizePayload(array $payload): array {
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
        $normalized['event_tags_text'] = $this->normalizeEventTagsText($event_tags);

        return $normalized;
    }

    private function normalizeEventTagsText($event_tags): string {
        if (is_string($event_tags)) {
            $event_tags = trim($event_tags);

            if ($event_tags === '' || preg_match('/^\{[A-Z0-9_.]+\}$/', $event_tags)) {
                return '';
            }

            if ($this->looksLikeJson($event_tags)) {
                $decoded = json_decode($event_tags, true);

                if (is_array($decoded)) {
                    return Util::formatTags($decoded);
                }
            }

            return Util::cleanMultiline($event_tags, 4000);
        }

        return Util::formatTags($event_tags);
    }

    private function looksLikeJson(string $value): bool {
        $value = ltrim($value);

        return $value !== '' && ($value[0] === '[' || $value[0] === '{');
    }

    private function validateSharedSecret(array $config, array $payload): void {
        $expected = Config::resolveSecret(
            $config['webhook']['shared_secret'] ?? '',
            $config['webhook']['shared_secret_env'] ?? ''
        );

        if ($expected === '') {
            return;
        }

        $provided = trim((string) ($_SERVER['HTTP_X_AI_WEBHOOK_SECRET'] ?? $payload['shared_secret'] ?? ''));

        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new \RuntimeException('Invalid webhook shared secret.');
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
