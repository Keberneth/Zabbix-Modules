<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    CWebUser,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util;

class LogsClear extends CController {

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $config = Config::get();
            $op = strtolower((string) ($_REQUEST['op'] ?? ''));

            $userid = (string) (CWebUser::$data['userid'] ?? '');
            $username = trim((string) (CWebUser::$data['username'] ?? CWebUser::$data['alias'] ?? ''));
            if ($username === '') {
                $username = $userid !== '' ? 'user '.$userid : 'unknown';
            }

            $pending = AuditLogger::clearRequest($config);

            // Withdraw a pending request. Non-destructive, so any super admin may do it.
            if ($op === 'cancel') {
                if ($pending['pending']) {
                    AuditLogger::cancelClear($config);
                    AuditLogger::logClearAudit($config, [
                        'event' => 'logs.clear_cancelled',
                        'status' => 'ok',
                        'meta' => ['requested_by' => $pending['username'], 'cancelled_by' => $username]
                    ]);
                }

                $this->respond(['ok' => true, 'state' => 'idle']);
                return;
            }

            // No request yet → arm one. Nothing is deleted on this click.
            if (!$pending['pending']) {
                AuditLogger::armClear($config, $userid, $username);
                AuditLogger::logClearAudit($config, [
                    'event' => 'logs.clear_requested',
                    'status' => 'pending',
                    'meta' => ['requested_by' => $username, 'requested_by_userid' => $userid]
                ]);

                $this->respond([
                    'ok' => true,
                    'state' => 'pending',
                    'requested_by' => $username,
                    'mine' => true
                ]);
                return;
            }

            // A request exists, but the requester cannot approve their own clear.
            if ($pending['userid'] === $userid) {
                AuditLogger::logClearAudit($config, [
                    'event' => 'logs.clear_self_approval_blocked',
                    'status' => 'denied',
                    'meta' => ['requested_by' => $pending['username'], 'attempted_by' => $username]
                ]);

                $this->respond([
                    'ok' => true,
                    'state' => 'pending',
                    'requested_by' => $pending['username'],
                    'mine' => true,
                    'note' => 'You requested this clear. A different super admin must approve it.'
                ]);
                return;
            }

            // A different super admin approves → delete every log file now.
            $removed = AuditLogger::clear($config);
            AuditLogger::cancelClear($config);
            // Protected trail — written after clear() and never deleted by it.
            AuditLogger::logClearAudit($config, [
                'event' => 'logs.clear',
                'status' => 'ok',
                'meta' => [
                    'removed_files' => $removed,
                    'requested_by' => $pending['username'],
                    'approved_by' => $username
                ]
            ]);

            $this->respond([
                'ok' => true,
                'state' => 'cleared',
                'removed' => $removed,
                'requested_by' => $pending['username'],
                'approved_by' => $username
            ]);
        }
        catch (\Throwable $e) {
            $this->respond([
                'ok' => false,
                'error' => Util::truncate($e->getMessage(), 1000)
            ], 500);
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
