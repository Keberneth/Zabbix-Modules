<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\Healthcheck\Lib\Config,
    Modules\Healthcheck\Lib\DbConnector,
    Modules\Healthcheck\Lib\Storage;

class SettingsView extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return $this->validateInput([]);
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() == USER_TYPE_SUPER_ADMIN;
    }

    protected function doAction(): void {
        $pdo = DbConnector::connect();
        Storage::ensureSchema($pdo);

        $config = Config::sanitizeForView(Config::get($pdo));

        $runner_path = realpath(__DIR__.'/../bin/healthcheck-runner.php')
            ?: (__DIR__.'/../bin/healthcheck-runner.php');

        $response = new CControllerResponseData([
            'title' => _('Healthcheck settings'),
            'config' => $config,
            'runner_script_path' => $runner_path,
            'cron_schedule' => self::intervalToCron(
                self::shortestInterval(Config::getEnabledChecks(Config::get($pdo)))
            )
        ]);

        $this->setResponse($response);
    }

    /**
     * Return the shortest enabled check interval (seconds), or 300 as default.
     */
    private static function shortestInterval(array $checks): int {
        if ($checks === []) {
            return 300;
        }

        $min = PHP_INT_MAX;

        foreach ($checks as $check) {
            $interval = max(30, (int) ($check['interval_seconds'] ?? 300));
            if ($interval < $min) {
                $min = $interval;
            }
        }

        return $min;
    }

    /**
     * Convert an interval in seconds to the most appropriate cron schedule.
     */
    private static function intervalToCron(int $seconds): string {
        $minutes = max(1, intdiv($seconds, 60));

        if ($minutes <= 1) {
            return '* * * * *';
        }

        if ($minutes < 60) {
            return '*/'.$minutes.' * * * *';
        }

        $hours = intdiv($minutes, 60);

        if ($hours < 24) {
            return '0 */'.$hours.' * * *';
        }

        return '0 0 * * *';
    }
}
