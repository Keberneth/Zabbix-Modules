<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\NetBoxSync\Lib\Config,
    Modules\NetBoxSync\Lib\SyncEngine,
    Modules\NetBoxSync\Lib\Util;

class RunSync extends CController {

    public function init(): void {
        // The runner (cron/systemd) cannot carry a CSRF token, so it authenticates
        // with the shared secret instead. The interactive super-admin "Run now"
        // button has no secret and MUST be CSRF-protected. Therefore disable CSRF
        // validation ONLY when a valid shared secret is supplied; otherwise let the
        // framework validate the _csrf_token the UI sends.
        if ($this->hasValidSharedSecret()) {
            $this->disableCsrfValidation();
        }
    }

    protected function checkInput(): bool {
        $fields = [
            'force' => 'in 0,1',
            'secret' => 'string'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->respond([
                'ok' => false,
                'error' => _('Invalid request parameters.')
            ], 400);
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        if ($this->getUserType() == USER_TYPE_SUPER_ADMIN) {
            return true;
        }

        return $this->hasValidSharedSecret();
    }

    protected function doAction(): void {
        set_time_limit(300);

        try {
            $config = Config::get();
            $is_super_admin = ($this->getUserType() == USER_TYPE_SUPER_ADMIN);

            $summary = SyncEngine::run($config, [
                'force' => Util::truthy($_REQUEST['force'] ?? false),
                'source' => $is_super_admin ? 'ui' : 'runner'
            ]);

            $this->respond([
                'ok' => true,
                'summary' => $summary
            ]);
        }
        catch (\InvalidArgumentException $e) {
            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
        catch (\Throwable $e) {
            error_log('NetBoxSync RunSync: '.$e->getMessage());
            $this->respond([
                'ok' => false,
                'error' => _('An internal error occurred while running the sync. Check the server error log for details.')
            ], 500);
        }
    }

    /**
     * True when the request carries a non-empty shared secret that matches the
     * configured runner secret (constant-time compare). Used to gate both CSRF
     * and the non-super-admin permission path.
     */
    private function hasValidSharedSecret(): bool {
        try {
            $config = Config::sanitizeForRuntime(Config::get());
        }
        catch (\Throwable $e) {
            return false;
        }

        if (empty($config['runner']['enabled'])) {
            return false;
        }

        $secret = $this->getProvidedSecret();
        $expected = trim((string) ($config['runner']['shared_secret'] ?? ''));

        return $secret !== '' && $expected !== '' && hash_equals($expected, $secret);
    }

    private function getProvidedSecret(): string {
        $candidates = [
            $_SERVER['HTTP_X_NETBOX_SYNC_SECRET'] ?? '',
            $_SERVER['HTTP_X_NETBOXSYNC_SECRET'] ?? '',
            $_REQUEST['secret'] ?? ''
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"ok":false,"error":"Failed to encode response."}';
        }

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => $json
            ]))->disableView()
        );
    }
}
