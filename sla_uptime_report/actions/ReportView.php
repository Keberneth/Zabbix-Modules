<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Actions;

require_once __DIR__.'/../Helpers/ReportDataHelper.php';

use CController;
use CControllerResponseData;
use Modules\SlaUptimeReport\Helpers\ReportDataHelper;

class ReportView extends CController {

	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'filter_set' => 'in 1',
			'filter_mode' => 'in prev_month,specific_month,custom_range,days_back',
			'filter_month' => 'string',
			'filter_date_from' => 'string',
			'filter_date_to' => 'string',
			'filter_days_back' => 'int32',
			'filter_hostgroupids' => 'array_id',
			'filter_slaids' => 'array_id',
			'filter_exclude_disabled' => 'in 0,1'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$filter = ReportDataHelper::normalizeFilter([
			'mode' => $this->getInput('filter_mode', ReportDataHelper::getDefaultFilter()['mode']),
			'month' => $this->getInput('filter_month', ReportDataHelper::getDefaultFilter()['month']),
			'date_from' => $this->getInput('filter_date_from', ''),
			'date_to' => $this->getInput('filter_date_to', ''),
			'days_back' => $this->getInput('filter_days_back', ReportDataHelper::getDefaultFilter()['days_back']),
			'hostgroupids' => $this->getInput('filter_hostgroupids', []),
			'slaids' => $this->getInput('filter_slaids', []),
			'exclude_disabled' => $this->getInput('filter_exclude_disabled', 1)
		]);

		[$time_from, $time_to] = ReportDataHelper::resolveDateRange($filter);

		$helper = new ReportDataHelper();

		$sla_heatmap = $helper->fetchSlaHeatmap($filter['slaids'], $time_to);
		$availability = $helper->fetchAvailability(
			$filter['hostgroupids'],
			$time_from,
			$time_to,
			(bool) $filter['exclude_disabled']
		);

		$this->setResponse(new CControllerResponseData([
			'title' => _('SLA & Uptime Report'),
			'filter' => $filter,
			'time_from' => $time_from,
			'time_to' => $time_to,
			'hostgroup_options' => $helper->getHostGroupOptions(),
			'sla_options' => $helper->getSlaOptions(),
			'sla_heatmap' => $sla_heatmap,
			'availability' => $availability,
			'helper' => $helper,
			'active_tab' => 1
		]));
	}
}
