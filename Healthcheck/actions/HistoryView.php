<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\Healthcheck\Lib\Config,
    Modules\Healthcheck\Lib\DbConnector,
    Modules\Healthcheck\Lib\Storage,
    Modules\Healthcheck\Lib\Util;

class HistoryView extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        return true;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        $pdo = DbConnector::connect();
        Storage::ensureSchema($pdo);

        $config = Config::get($pdo);
        $config = Config::mergeWithDefaults($config);
        $checks = $config['checks'];

        $selected_check_id = Util::cleanString($_REQUEST['checkid'] ?? '', 128);
        $period_days = Util::cleanInt(
            $_REQUEST['period_days'] ?? ($config['history']['default_period_days'] ?? 7),
            (int) ($config['history']['default_period_days'] ?? 7),
            1,
            365
        );

        $from_ts = time() - ($period_days * 86400);

        $stats = Storage::getStats($pdo, $from_ts, $selected_check_id);
        $runs = Storage::getRuns(
            $pdo,
            $from_ts,
            $selected_check_id,
            (int) ($config['history']['recent_runs_limit'] ?? 200)
        );
        $failures = Storage::getFailures($pdo, $from_ts, $selected_check_id, 50);

        $runids = array_map(static function(array $run): string {
            return (string) ($run['runid'] ?? '');
        }, array_slice($runs, 0, 30));
        $steps_by_runid = Storage::getStepsByRunIds($pdo, $runids);

        $response = new CControllerResponseData([
            'title' => _('Healthcheck history'),
            'config' => $config,
            'checks' => $checks,
            'selected_check_id' => $selected_check_id,
            'period_days' => $period_days,
            'stats' => $stats,
            'runs' => $runs,
            'failures' => $failures,
            'steps_by_runid' => $steps_by_runid,
            'is_super_admin' => ($this->getUserType() == USER_TYPE_SUPER_ADMIN)
        ]);

        $this->setResponse($response);
    }
}
