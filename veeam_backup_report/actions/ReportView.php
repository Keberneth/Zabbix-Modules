<?php declare(strict_types = 1);

namespace Modules\VeeamBackupReport\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\VeeamBackupReport\Helpers\ReportDataHelper;

class ReportView extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'filter_mode' => 'in prev_month,specific_month,custom_range,days_back',
            'filter_month' => 'string',
            'filter_date_from' => 'string',
            'filter_date_to' => 'string',
            'filter_days_back' => 'int32',
            'filter_hostids' => 'array_id',
            'filter_source' => 'in auto,history,trends',
            'filter_metric' => 'in size24h,size31d',
            'filter_top' => 'int32',
            'filter_object_search' => 'string',
            'filter_repo_search' => 'string'
        ];

        $ret = $this->validateInput($fields);

        if (!$ret) {
            $this->setResponse(new CControllerResponseFatal());
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
    }

    protected function doAction(): void {
        set_time_limit(300);

        $helper = new ReportDataHelper();

        try {
            $filter = ReportDataHelper::normalizeFilter([
                'mode' => $this->getInput('filter_mode', ReportDataHelper::getDefaultFilter()['mode']),
                'month' => $this->getInput('filter_month', ReportDataHelper::getDefaultFilter()['month']),
                'date_from' => $this->getInput('filter_date_from', ''),
                'date_to' => $this->getInput('filter_date_to', ''),
                'days_back' => $this->getInput('filter_days_back', ReportDataHelper::getDefaultFilter()['days_back']),
                'hostids' => $this->getInput('filter_hostids', []),
                'source' => $this->getInput('filter_source', ReportDataHelper::getDefaultFilter()['source']),
                'metric' => $this->getInput('filter_metric', ReportDataHelper::getDefaultFilter()['metric']),
                'top' => $this->getInput('filter_top', ReportDataHelper::getDefaultFilter()['top']),
                'object_search' => $this->getInput('filter_object_search', ''),
                'repo_search' => $this->getInput('filter_repo_search', '')
            ]);

            [$time_from, $time_to] = ReportDataHelper::resolveDateRange($filter);

            $report = $helper->buildReport($filter, $time_from, $time_to);

            $this->setResponse(new CControllerResponseData([
                'title' => _('Veeam Backup Report'),
                'filter' => $filter,
                'time_from' => $time_from,
                'time_to' => $time_to,
                'report' => $report,
                'helper' => $helper
            ]));
        }
        catch (\InvalidArgumentException $e) {
            error_log('VeeamBackupReport: '.$e->getMessage());
            $this->setResponse($this->makeErrorResponse(
                $helper,
                _('The report request was invalid. Please adjust the filter and try again.')
            ));
        }
        catch (\Throwable $e) {
            error_log('VeeamBackupReport: '.$e->getMessage());
            $this->setResponse($this->makeErrorResponse(
                $helper,
                _('An internal error occurred while building the report. Please try again later.')
            ));
        }
    }

    /**
     * Build a degraded-but-renderable response so a failure surfaces a generic
     * warning on the page instead of leaking internals via a framework fatal.
     */
    private function makeErrorResponse(ReportDataHelper $helper, string $message): CControllerResponseData {
        $filter = ReportDataHelper::getDefaultFilter();
        [$time_from, $time_to] = ReportDataHelper::resolveDateRange($filter);

        $report = [
            'host_options' => [],
            'selected_hostids' => [],
            'source_requested' => $filter['source'],
            'source_used' => $filter['source'],
            'summary' => [],
            'daily' => [],
            'source_hosts' => [],
            'repositories' => [],
            'objects' => [],
            'objects_total' => 0,
            'objects_filtered' => 0,
            'objects_shown' => 0,
            'warnings' => [$message],
            'error' => $message
        ];

        return new CControllerResponseData([
            'title' => _('Veeam Backup Report'),
            'filter' => $filter,
            'time_from' => $time_from,
            'time_to' => $time_to,
            'report' => $report,
            'helper' => $helper
        ]);
    }
}
