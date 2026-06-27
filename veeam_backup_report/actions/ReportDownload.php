<?php declare(strict_types = 1);

namespace Modules\VeeamBackupReport\Actions;

use CController;
use CControllerResponseFatal;
use Modules\VeeamBackupReport\Helpers\ReportDataHelper;

class ReportDownload extends CController {

    public function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'format' => 'in html,daily_csv,hosts_csv,repositories_csv,objects_csv',
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

        try {
            $this->streamReport();
        }
        catch (\InvalidArgumentException $e) {
            error_log('VeeamBackupReport: '.$e->getMessage());
            $this->respondError(400, _('The download request was invalid.'));
        }
        catch (\Throwable $e) {
            error_log('VeeamBackupReport: '.$e->getMessage());
            $this->respondError(500, _('An internal error occurred while generating the download.'));
        }
    }

    private function streamReport(): void {
        $helper = new ReportDataHelper();

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

        $metric_slug = $filter['metric'] === 'size31d' ? '31d' : '24h';
        $period_slug = date('Ymd', $time_from).'_'.date('Ymd', $time_to);
        $format = (string) $this->getInput('format', 'html');

        switch ($format) {
            case 'daily_csv':
                $this->outputCsv(
                    'veeam_backup_daily_'.$metric_slug.'_'.$period_slug.'.csv',
                    [
                        'Date',
                        'Total 24h (bytes)',
                        'Total 24h (human)',
                        'Total 31d (bytes)',
                        'Total 31d (human)',
                        'Assigned 31d (bytes)',
                        'Assigned 31d (human)',
                        'Shared 31d (bytes)',
                        'Shared 31d (human)',
                        'Coverage pct',
                        'Coverage human',
                        'Hosts with data'
                    ],
                    $helper->flattenDailyRows($report['daily'])
                );
                break;

            case 'hosts_csv':
                $this->outputCsv(
                    'veeam_backup_hosts_'.$metric_slug.'_'.$period_slug.'.csv',
                    [
                        'Veeam host',
                        'Metric start (bytes)',
                        'Metric start (human)',
                        'Metric end (bytes)',
                        'Metric end (human)',
                        'Metric change (bytes)',
                        'Metric change (human)',
                        'Metric average (bytes)',
                        'Metric average (human)',
                        'Metric peak (bytes)',
                        'Metric peak (human)',
                        'Days',
                        'Repository capacity GB',
                        'Repository capacity human',
                        'Repository used GB',
                        'Repository used human',
                        'Repository free GB',
                        'Repository free human',
                        'Repositories online',
                        'Repositories offline',
                        'Assigned 31d (bytes)',
                        'Assigned 31d (human)',
                        'Shared 31d (bytes)',
                        'Shared 31d (human)',
                        'Coverage pct',
                        'Coverage human',
                        'Last clock'
                    ],
                    $helper->flattenSourceHostRows($report['source_hosts'])
                );
                break;

            case 'repositories_csv':
                $this->outputCsv(
                    'veeam_backup_repositories_'.$metric_slug.'_'.$period_slug.'.csv',
                    [
                        'Veeam host',
                        'Repository',
                        'Metric start (bytes)',
                        'Metric start (human)',
                        'Metric end (bytes)',
                        'Metric end (human)',
                        'Metric change (bytes)',
                        'Metric change (human)',
                        'Metric average (bytes)',
                        'Metric average (human)',
                        'Metric peak (bytes)',
                        'Metric peak (human)',
                        'Days',
                        'Backup files 31d',
                        'Capacity GB',
                        'Capacity human',
                        'Used GB',
                        'Used human',
                        'Free GB',
                        'Free human',
                        'Free pct',
                        'Free pct human',
                        'Online',
                        'Out of date',
                        'Last clock'
                    ],
                    $helper->flattenRepositoryRows($report['repositories'])
                );
                break;

            case 'objects_csv':
                $this->outputCsv(
                    'veeam_backup_objects_'.$metric_slug.'_'.$period_slug.'.csv',
                    [
                        'Veeam host',
                        'Protected object',
                        'Platform',
                        'Metric start (bytes)',
                        'Metric start (human)',
                        'Metric end (bytes)',
                        'Metric end (human)',
                        'Metric change (bytes)',
                        'Metric change (human)',
                        'Metric average (bytes)',
                        'Metric average (human)',
                        'Metric peak (bytes)',
                        'Metric peak (human)',
                        'Days',
                        'Restore points 31d',
                        'Backup files 31d',
                        'Last backup',
                        'Repositories',
                        'Attribution',
                        'Last clock'
                    ],
                    $helper->flattenObjectRows($report['objects'])
                );
                break;

            case 'html':
            default:
                $filename = 'veeam_backup_report_'.$metric_slug.'_'.$period_slug.'.html';
                header('Content-Type: text/html; charset=UTF-8');
                header('Content-Disposition: attachment; filename="'.$filename.'"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                echo $helper->renderStandaloneHtml($filter, $report, $time_from, $time_to);
                exit;
        }
    }

    private function outputCsv(string $filename, array $header, array $rows): void {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $fp = fopen('php://output', 'wb');
        if ($fp === false) {
            exit;
        }

        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, array_map([$this, 'csvSafe'], $header));

        foreach ($rows as $row) {
            fputcsv($fp, array_map([$this, 'csvSafe'], $row));
        }

        fclose($fp);
        exit;
    }

    /**
     * Neutralize CSV formula injection: any cell that begins with a spreadsheet
     * formula trigger (=, +, -, @) or a control character (tab, CR) is prefixed
     * with an apostrophe so spreadsheet apps treat it as literal text.
     */
    private function csvSafe($value): string {
        $value = (string) $value;

        if ($value === '') {
            return $value;
        }

        $first = $value[0];
        // Always neutralise the unambiguous formula/control prefixes.
        if ($first === '=' || $first === '@' || $first === "\t" || $first === "\r") {
            return "'".$value;
        }
        // A leading +/- is only dangerous when the cell is not a plain number;
        // negative metrics (e.g. -1024) must stay numeric for spreadsheets.
        if (($first === '+' || $first === '-') && !is_numeric($value)) {
            return "'".$value;
        }

        return $value;
    }

    private function respondError(int $code, string $message): void {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/plain; charset=UTF-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
        }

        echo $message;
        exit;
    }
}
