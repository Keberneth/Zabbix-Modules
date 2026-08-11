<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\SlaUptimeReport\Helpers\ReportDataHelper;

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

		$helper = new ReportDataHelper();

		try {
			$filter = ReportDataHelper::normalizeFilter($this->readFilterInput());

			[$time_from, $time_to] = ReportDataHelper::resolveDateRange($filter);

			$report = $helper->buildReport($filter, $time_from, $time_to);

			$this->setResponse(new CControllerResponseData([
				'title' => _('SLA & Uptime Report'),
				'filter' => $filter,
				'time_from' => $time_from,
				'time_to' => $time_to,
				'report' => $report,
				'helper' => $helper
			]));
		}
		catch (\InvalidArgumentException $e) {
			error_log('SLA Uptime Report: '.$e->getMessage());
			$this->setResponse($this->makeErrorResponse(
				$helper,
				_('The report request was invalid. Please adjust the filter and try again.')
			));
		}
		catch (\Throwable $e) {
			error_log('SLA Uptime Report: '.$e->getMessage());
			$this->setResponse($this->makeErrorResponse(
				$helper,
				_('An internal error occurred while building the report. Please try again later.')
			));
		}
	}

	private function readFilterInput(): array {
		$defaults = ReportDataHelper::getDefaultFilter();

		return [
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
		];
	}

	/**
	 * Build a degraded-but-renderable response so a failure surfaces a generic
	 * warning on the page instead of leaking internals via a framework fatal.
	 */
	private function makeErrorResponse(ReportDataHelper $helper, string $message): CControllerResponseData {
		$filter = ReportDataHelper::getDefaultFilter();
		[$time_from, $time_to] = ReportDataHelper::resolveDateRange($filter);

		$report = $helper->emptyReport($filter);
		$report['period'] = [
			'from' => $time_from,
			'to' => $time_to,
			'days' => max(1, (int) ceil(($time_to - $time_from + 1) / 86400)),
			'label' => $helper->formatPeriodLabel($time_from, $time_to)
		];
		$report['warnings'] = [$message];
		$report['error'] = $message;

		return new CControllerResponseData([
			'title' => _('SLA & Uptime Report'),
			'filter' => $filter,
			'time_from' => $time_from,
			'time_to' => $time_to,
			'report' => $report,
			'helper' => $helper
		]);
	}
}
