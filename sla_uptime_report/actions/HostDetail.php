<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CRoleHelper;
use Modules\SlaUptimeReport\Helpers\HostDetailHelper;
use Modules\SlaUptimeReport\Helpers\ReportDataHelper;

final class HostDetail extends CController {
	public function init(): void { $this->disableCsrfValidation(); }

	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'hostid' => 'required|db hosts.hostid', 'time_from' => 'required|int32',
			'time_to' => 'required|int32', 'return_filter' => 'string', 'measurementid' => 'string'
		]);
		if ($valid) {
			$valid = HostDetailHelper::validRange((int) $this->getInput('time_from'), (int) $this->getInput('time_to'))
				&& HostDetailHelper::validMeasurementId((string) $this->getInput('measurementid', ''));
		}
		if (!$valid) { $this->setResponse(new CControllerResponseFatal()); }
		return $valid;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		set_time_limit(300);
		$data = ['title' => _('Host availability detail'), 'helper' => new ReportDataHelper(),
			'detail' => null, 'can_create' => false, 'error' => null,
			'from' => (int) $this->getInput('time_from'), 'to' => (int) $this->getInput('time_to')];
		$data['return_filter'] = HostDetailHelper::returnFilter((string) $this->getInput('return_filter', ''), $data['from'], $data['to']);
		try {
			$data['detail'] = (new HostDetailHelper())->build((string) $this->getInput('hostid'), $data['from'], $data['to'],
				(string) $this->getInput('measurementid', ''));
			if ($this->getUserType() >= USER_TYPE_ZABBIX_ADMIN
					&& $this->checkAccess(CRoleHelper::UI_CONFIGURATION_MAINTENANCE)
					&& $this->checkAccess(CRoleHelper::ACTIONS_EDIT_MAINTENANCE)) {
				$editable = API::Host()->get(['output' => ['hostid'], 'hostids' => [$this->getInput('hostid')],
					'editable' => true]);
				$data['can_create'] = is_array($editable) && count($editable) === 1 && !$data['detail']['excluded']
					&& !$data['detail']['selection_required'] && $data['detail']['measurement'] !== null;
			}
		}
		catch (\InvalidArgumentException $e) { $data['error'] = $e->getMessage(); }
		catch (\Throwable $e) {
			error_log('SLA Uptime host detail: '.$e->getMessage());
			$data['error'] = _('Host evidence could not be loaded. Please try again or narrow the period.');
		}
		$this->setResponse(new CControllerResponseData($data));
	}
}
