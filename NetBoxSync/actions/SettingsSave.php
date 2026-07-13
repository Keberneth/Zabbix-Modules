<?php declare(strict_types = 0);

namespace Modules\NetBoxSync\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\NetBoxSync\Lib\Config;

class SettingsSave extends CController {

    protected function checkInput(): bool {
        // The settings tree is a deep nested structure (netbox/runner/vm/device/
        // services/standard_syncs/custom_mappings) that Config::buildFromPost reads
        // and sanitizes field by field. Declare the top-level groups as arrays so
        // CController accepts the structured POST while still dropping anything that
        // is not one of these whitelisted keys.
        $fields = [
            'netbox' => 'array',
            'zabbix_api' => 'array',
            'runner' => 'array',
            'vm' => 'array',
            'services' => 'array',
            'device' => 'array',
            'standard_syncs' => 'array',
            'custom_mappings' => 'array'
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
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        try {
            $current = Config::get();
            $new_config = Config::buildFromPost($_POST, $current);
            Config::save($new_config);

            $this->respond([
                'ok' => true,
                'message' => _('NetBox Sync settings updated.')
            ]);
        }
        catch (\InvalidArgumentException $e) {
            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
        catch (\Throwable $e) {
            error_log('NetBoxSync SettingsSave: '.$e->getMessage());
            $this->respond([
                'ok' => false,
                'error' => _('An internal error occurred while saving the settings. Check the server error log for details.')
            ], 500);
        }
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
