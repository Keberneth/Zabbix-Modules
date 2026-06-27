<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\Healthcheck\Lib\Config,
    Modules\Healthcheck\Lib\DbConnector,
    Modules\Healthcheck\Lib\Storage;

class SettingsSave extends CController {

    protected function checkInput(): bool {
        $fields = [
            'checks' => 'array',
            'history' => 'array'
        ];

        return $this->validateInput($fields);
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            $pdo = DbConnector::connect();
            Storage::ensureSchema($pdo);

            $current = Config::get($pdo);
            $post = [
                'checks' => $this->getInput('checks', []),
                'history' => $this->getInput('history', [])
            ];
            $new_config = Config::buildFromPost($post, $current);
            Config::save($new_config, $pdo);

            $this->respond([
                'ok' => true,
                'message' => _('Healthcheck settings updated.')
            ]);
        }
        catch (\InvalidArgumentException $e) {
            $this->respond([
                'ok' => false,
                'error' => $e->getMessage()
            ], 400);
        }
        catch (\Throwable $e) {
            error_log('Healthcheck SettingsSave: '.$e->getMessage());

            $this->respond([
                'ok' => false,
                'error' => _('An internal error occurred while saving settings.')
            ], 500);
        }
    }

    private function respond(array $payload, int $http_status = 200): void {
        http_response_code($http_status);
        header('Content-Type: application/json; charset=UTF-8');

        $json = json_encode(
            $payload,
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

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
