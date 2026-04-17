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
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        if ($this->getUserType() == USER_TYPE_SUPER_ADMIN) {
            return true;
        }

        $config = Config::sanitizeForRuntime(Config::get());

        if (empty($config['runner']['enabled'])) {
            return false;
        }

        $secret = $this->getProvidedSecret();
        $expected = trim((string) ($config['runner']['shared_secret'] ?? ''));

        return $secret !== '' && $expected !== '' && hash_equals($expected, $secret);
    }

    protected function doAction(): void {
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
        catch (\Throwable $e) {
            $this->respond([
                'ok' => false,
                'error' => Util::truncate($e->getMessage(), 2000)
            ], 500);
        }
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

        $this->setResponse(
            (new CControllerResponseData([
                'main_block' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ]))->disableView()
        );
    }
}
