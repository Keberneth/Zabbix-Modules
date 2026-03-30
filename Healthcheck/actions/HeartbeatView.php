<?php declare(strict_types = 0);

namespace Modules\Healthcheck\Actions;

require_once __DIR__.'/../lib/bootstrap.php';

use CController,
    CControllerResponseData,
    Modules\Healthcheck\Lib\Config,
    Modules\Healthcheck\Lib\DbConnector,
    Modules\Healthcheck\Lib\Storage,
    Modules\Healthcheck\Lib\Util;

class HeartbeatView extends CController {

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
        $checks = Config::mergeWithDefaults($config)['checks'];

        $selected_check_id = Util::cleanString($_REQUEST['checkid'] ?? '', 128);
        if ($selected_check_id === '' && $checks !== []) {
            $selected_check_id = (string) ($checks[0]['id'] ?? '');
        }

        $latest_runs = [];
        foreach ($checks as $check) {
            $check_id = (string) ($check['id'] ?? '');
            $latest_runs[$check_id] = Storage::getLatestRunByCheckId($pdo, $check_id);
        }

        $selected_run = null;
        $selected_steps = [];
        $selected_recent_runs = [];

        if ($selected_check_id !== '') {
            $selected_run = $latest_runs[$selected_check_id] ?? null;
            if ($selected_run !== null && !empty($selected_run['runid'])) {
                $selected_steps = Storage::getStepsByRunId($pdo, (string) $selected_run['runid']);
            }

            $selected_recent_runs = Storage::getLatestRuns(
                $pdo,
                min(20, (int) ($config['history']['recent_runs_limit'] ?? 20)),
                $selected_check_id
            );
        }

        $recent_failures = Storage::getRecentFailures($pdo, 20);

        $response = new CControllerResponseData([
            'title' => _('Healthcheck heartbeat'),
            'config' => $config,
            'checks' => $checks,
            'latest_runs' => $latest_runs,
            'selected_check_id' => $selected_check_id,
            'selected_run' => $selected_run,
            'selected_steps' => $selected_steps,
            'selected_recent_runs' => $selected_recent_runs,
            'recent_failures' => $recent_failures,
            'is_super_admin' => ($this->getUserType() == USER_TYPE_SUPER_ADMIN)
        ]);

        $this->setResponse($response);
    }
}
