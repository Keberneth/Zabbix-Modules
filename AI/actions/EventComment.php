<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Redactor,
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
            $config = Config::get();
            $post = $_POST;
            $eventid = Util::cleanString($post['eventid'] ?? '', 128);
            $message = Util::cleanMultiline($post['message'] ?? '', 100000);
            $chat_session_id = Util::cleanId($post['chat_session_id'] ?? '', 'chat');

            if ($eventid === '') {
                throw new \RuntimeException('Event ID is required.');
            }

            if ($message === '') {
                throw new \RuntimeException('Message cannot be empty.');
            }

            $redactor = $chat_session_id !== ''
                ? Redactor::forChatSession($config, $this->serverSessionKey(), $chat_session_id)
                : null;
            $message_to_post = $redactor !== null ? $redactor->restoreText($message) : $message;

            $client = ZabbixApiClient::fromConfig($config);
            if ($client === null) {
                throw new \RuntimeException('Zabbix API token is not configured in AI settings.');
            }

            $chunks = $client->addProblemComment(
                $eventid,
                $message_to_post,
                (int) ($config['webhook']['problem_update_action'] ?? 4),
                (int) ($config['webhook']['comment_chunk_size'] ?? 1900)
            );

            AuditLogger::log($config, 'writes', [
                'event' => 'event.comment.posted',
                'source' => 'ai.event.comment',
                'status' => 'ok',
                'payload' => [
                    'message' => $redactor !== null ? $redactor->redactText($message_to_post, 'action_writes') : $message_to_post
                ],
                'meta' => [
                    'eventid' => $eventid,
                    'chunks' => count($chunks)
                ]
            ]);

            $this->respond([
                'ok' => true,
                'chunks' => count($chunks)
            ]);
        }
        catch (\Throwable $e) {
            if (isset($config)) {
                AuditLogger::log($config, 'errors', [
                    'event' => 'event.comment.failed',
                    'source' => 'ai.event.comment',
                    'status' => 'error',
                    'message' => $e->getMessage()
                ]);
            }

            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
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
