<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Actions;

use CController;
use CControllerResponseFatal;
use Modules\SlaUptimeReport\Helpers\HtmlExportRenderer;
use Modules\SlaUptimeReport\Helpers\ReportDataHelper;

class ReportDownload extends CController {

	private const FORMATS = ['html', 'sla_csv', 'availability_csv', 'daily_csv'];

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'format' => 'in '.implode(',', self::FORMATS),
			'filter_mode' => 'in prev_month,specific_month,custom_range,days_back',
			'filter_month' => 'string',
			'filter_date_from' => 'string',
			'filter_date_to' => 'string',
			'filter_days_back' => 'int32',
			'filter_hostgroupids' => 'array_id',
			'filter_slaids' => 'array_id',
			'filter_exclude_disabled' => 'in 0,1',
			'filter_target' => 'string',
			'filter_top' => 'int32',
			'filter_tab' => 'in overview,slas,availability'
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
			error_log('SLA Uptime Report: '.$e->getMessage());
			$this->respondError(400, _('The download request was invalid.'));
		}
		catch (\Throwable $e) {
			error_log('SLA Uptime Report: '.$e->getMessage());
			$this->respondError(500, _('An internal error occurred while generating the download.'));
		}
	}

	private function streamReport(): void {
		$helper = new ReportDataHelper();
		$defaults = ReportDataHelper::getDefaultFilter();

		$filter = ReportDataHelper::normalizeFilter([
			'mode' => $this->getInput('filter_mode', $defaults['mode']),
			'month' => $this->getInput('filter_month', $defaults['month']),
			'date_from' => $this->getInput('filter_date_from', ''),
			'date_to' => $this->getInput('filter_date_to', ''),
			'days_back' => $this->getInput('filter_days_back', $defaults['days_back']),
			'hostgroupids' => $this->getInput('filter_hostgroupids', []),
			'slaids' => $this->getInput('filter_slaids', []),
			'exclude_disabled' => $this->getInput('filter_exclude_disabled', $defaults['exclude_disabled']),
			'target' => $this->getInput('filter_target', $defaults['target']),
			'top' => $this->getInput('filter_top', $defaults['top']),
			'tab' => $this->getInput('filter_tab', $defaults['tab'])
		]);

		[$time_from, $time_to] = ReportDataHelper::resolveDateRange($filter);
		$report = $helper->buildReport($filter, $time_from, $time_to);

		$period_slug = gmdate('Ymd', $time_from).'_'.gmdate('Ymd', $time_to);
		$format = (string) $this->getInput('format', 'html');

		switch ($format) {
			case 'sla_csv':
				$this->outputCsv(
					'sla_report_'.$period_slug.'.csv',
					['SLA ID', 'SLA name', 'Service ID', 'Service name', 'SLO pct', 'Month', 'SLI pct'],
					$helper->flattenSlaRows($report['slas'])
				);
				break;

			case 'availability_csv':
				$this->outputCsv(
					'availability_report_'.$period_slug.'.csv',
					[
						'Host group', 'Host', 'Availability pct', 'State',
						'Uptime seconds', 'Downtime seconds', 'Item key',
						'Window start UTC', 'Window end UTC'
					],
					$helper->flattenAvailabilityRows($report['groups'], $time_from, $time_to)
				);
				break;

			case 'daily_csv':
				$header = ['Date'];
				foreach ($report['daily']['series'] as $series) {
					$header[] = $series['label'].' downtime (min)';
				}
				$header[] = 'Total downtime (min)';

				$this->outputCsv(
					'downtime_daily_'.$period_slug.'.csv',
					$header,
					$helper->flattenDailyRows($report['daily'])
				);
				break;

			case 'html':
			default:
				$filename = 'sla_uptime_report_'.$period_slug.'.html';
				header('Content-Type: text/html; charset=UTF-8');
				header('Content-Disposition: attachment; filename="'.$filename.'"');
				header('Cache-Control: no-cache, no-store, must-revalidate');
				echo (new HtmlExportRenderer($helper))->render($filter, $report, $time_from, $time_to);
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
	 * formula trigger (=, +, -, @) or a control character is prefixed with an
	 * apostrophe so spreadsheet apps treat it as literal text.
	 */
	private function csvSafe($value): string {
		if (is_bool($value)) {
			return $value ? 'Yes' : 'No';
		}

		$value = (string) $value;

		if ($value === '') {
			return $value;
		}

		$first = $value[0];
		if ($first === '=' || $first === '@' || $first === "\t" || $first === "\r") {
			return "'".$value;
		}
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

		echo htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		exit;
	}
}
