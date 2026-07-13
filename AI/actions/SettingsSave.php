<?php declare(strict_types = 0);

namespace Modules\AI\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\AI\Lib\AuditLogger,
    Modules\AI\Lib\Config,
    Modules\AI\Lib\Util;

class SettingsSave extends CController {

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $current = Config::get();

        try {
            $new_config = Config::buildFromPost($_POST, $current);
            Config::save($new_config);

            // Never tell the browser that a settings change succeeded until a
            // fresh database read confirms the newly written policy. Besides
            // catching a failed/no-op write, the response fields let the new UI
            // detect a mixed deployment where an older PHP-FPM worker handled
            // the save request.
            $persisted_config = Config::get();
            $requested_plaintext = Util::truthy(
                $new_config['secret_storage']['allow_plaintext_secrets'] ?? false
            );
            $persisted_plaintext = Util::truthy(
                $persisted_config['secret_storage']['allow_plaintext_secrets'] ?? false
            );
            if ($persisted_plaintext !== $requested_plaintext) {
                throw new \RuntimeException(
                    'AI settings write verification failed: the plaintext-secret compatibility setting '
                    .'did not persist. Check for a mixed module deployment or database write failure, '
                    .'reload PHP-FPM/Apache, and try again.'
                );
            }

            $log_entry = [
                'event' => 'settings.save',
                'source' => 'ai.settings.save',
                'status' => 'ok',
                'meta' => [
                    'providers' => count($new_config['providers'] ?? []),
                    'security_enabled' => !empty($new_config['security']['enabled']),
                    'logging_enabled' => !empty($new_config['logging']['enabled']),
                    'plaintext_secrets_before' => !empty(
                        $current['secret_storage']['allow_plaintext_secrets']
                    ),
                    'plaintext_secrets_after' => !empty(
                        $new_config['secret_storage']['allow_plaintext_secrets']
                    )
                ]
            ];

            AuditLogger::log($current, 'settings_changes', $log_entry);
            if (empty($current['logging']['enabled']) && !empty($new_config['logging']['enabled'])) {
                AuditLogger::log($new_config, 'settings_changes', $log_entry);
            }

            $this->respond([
                'ok' => true,
                'message' => _('AI settings updated.'),
                'settings_schema_version' => 1,
                'plaintext_secrets_enabled' => $persisted_plaintext
            ]);
        }
        catch (\Throwable $e) {
            AuditLogger::log($current, 'errors', [
                'event' => 'settings.save.failed',
                'source' => 'ai.settings.save',
                'status' => 'error',
                'message' => $e->getMessage()
            ]);

            $this->respond([
                'ok' => false,
                'error' => Util::truncate($e->getMessage(), 1000)
            ], 400);
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
