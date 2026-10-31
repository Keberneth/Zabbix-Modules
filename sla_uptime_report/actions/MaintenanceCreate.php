<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Actions;

use API;
use CController;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CMessageHelper;
use CRoleHelper;
use CUrl;
use Modules\SlaUptimeReport\Helpers\HostDetailHelper;

/** Explicit POST only; native controller validates an action-bound CSRF token. */
final class MaintenanceCreate extends CController {
	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'hostid' => 'required|db hosts.hostid', 'time_from' => 'required|int32', 'time_to' => 'required|int32',
			'gap_start' => 'required|int32', 'gap_end' => 'required|int32', 'reason' => 'required|string|not_empty',
			'acknowledge' => 'required|in 1', 'return_filter' => 'string', 'measurementid' => 'string'
		]);
		if ($valid) {
			$valid = HostDetailHelper::validRange((int) $this->getInput('time_from'), (int) $this->getInput('time_to'))
				&& HostDetailHelper::validMeasurementId((string) $this->getInput('measurementid', ''))
				&& (int) $this->getInput('gap_start') >= (int) $this->getInput('time_from')
				&& (int) $this->getInput('gap_end') <= (int) $this->getInput('time_to') + 1
				&& (int) $this->getInput('gap_end') > (int) $this->getInput('gap_start')
				&& strlen(trim((string) $this->getInput('reason'))) >= 3
				&& strlen((string) $this->getInput('reason')) <= 1000;
		}
		if (!$valid) { $this->setResponse(new CControllerResponseFatal()); }
		return $valid;
	}

	protected function checkPermissions(): bool {
		if ($this->getUserType() < USER_TYPE_ZABBIX_ADMIN
				|| !$this->checkAccess(CRoleHelper::UI_CONFIGURATION_MAINTENANCE)
				|| !$this->checkAccess(CRoleHelper::ACTIONS_EDIT_MAINTENANCE)) { return false; }
		$hosts = API::Host()->get(['output' => ['hostid'], 'hostids' => [$this->getInput('hostid')], 'editable' => true]);
		return is_array($hosts) && count($hosts) === 1;
	}

	protected function doAction(): void {
		set_time_limit(300);
		$hostid = (string) $this->getInput('hostid');
		$from = (int) $this->getInput('time_from');
		$to = (int) $this->getInput('time_to');
		$start = (int) $this->getInput('gap_start');
		$end = (int) $this->getInput('gap_end');
		$measurementid = (string) $this->getInput('measurementid', '');
		$return_filter = HostDetailHelper::returnFilter((string) $this->getInput('return_filter', ''), $from, $to);
		$response = new CControllerResponseRedirect((new CUrl('zabbix.php'))->setArgument('action', 'slareport.host.view')
			->setArgument('hostid', $hostid)->setArgument('time_from', $from)->setArgument('time_to', $to)
			->setArgument('measurementid', $measurementid)
			->setArgument('return_filter', json_encode($return_filter)));
		try {
			$proposal = HostDetailHelper::maintenanceProposal($start, $end);
			if ($proposal === null) { throw new \InvalidArgumentException(_('The gap is too short for a five-minute maintenance.')); }
			$detail = (new HostDetailHelper())->build($hostid, $from, $to, $measurementid);
			if ($detail['selection_required'] || $detail['measurement'] === null || $detail['excluded']) {
				throw new \InvalidArgumentException(_('Select an eligible measurement for this host before creating maintenance.'));
			}
			$name = 'SLA gap '.$hostid.' '.gmdate('Y-m-d H:i', $proposal['start']).' '
				.substr(hash('sha256', $hostid.':'.$proposal['start'].':'.$proposal['end']), 0, 16);
			// The stable unique name also makes retries safe when the first response was lost.
			$existing = API::Maintenance()->get(['output' => ['maintenanceid'], 'filter' => ['name' => $name]]);
			if (!is_array($existing)) { throw new \RuntimeException('Cannot check existing maintenance.'); }
			if ($existing !== []) {
				CMessageHelper::setSuccessTitle(_('This gap already has a maintenance created by the report.'));
				$this->setResponse($response);
				return;
			}
			$matched = false;
			foreach ($detail['intervals'] as $gap) {
				if ($gap['kind'] === 'missing' && $gap['creatable'] && $gap['start'] === $start && $gap['end'] === $end) {
					$matched = true;
					break;
				}
			}
			if (!$matched || !$detail['complete'] || $detail['excluded']) {
				throw new \InvalidArgumentException(_('The gap is no longer eligible. Reload the evidence before creating maintenance.'));
			}
			$description = 'Retrospective maintenance recorded from SLA & Uptime Report. '
				.'Missing data is not proof of planned work. Administrator reason: '.trim((string) $this->getInput('reason'))
				."\nEvidence gap UTC: ".gmdate('Y-m-d H:i:s', $start).' to '.gmdate('Y-m-d H:i:s', $end)
				."\nMeasurement: ".$detail['measurement']['label']
				."\nItem: ".$detail['item']['key_']
				."\nThis host maintenance applies to every report measurement on the host.";
			$created = API::Maintenance()->create([
				'name' => $name, 'description' => $description, 'maintenance_type' => 0,
				'active_since' => $proposal['start'], 'active_till' => $proposal['end'],
				'hosts' => [['hostid' => $hostid]],
				'timeperiods' => [['timeperiod_type' => 0, 'start_date' => $proposal['start'],
					'period' => $proposal['end'] - $proposal['start']]]
			]);
			if (!is_array($created) || empty($created['maintenanceids'])) {
				throw new \RuntimeException('Maintenance API did not confirm creation.');
			}
			CMessageHelper::setSuccessTitle(_('Maintenance created for this host. Every measurement on the host will now exclude that window.'));
		}
		catch (\InvalidArgumentException $e) { CMessageHelper::setErrorTitle($e->getMessage()); }
		catch (\Throwable $e) {
			error_log('SLA Uptime maintenance create: '.$e->getMessage());
			CMessageHelper::setErrorTitle(_('Maintenance could not be created. Check your permissions and reload the host evidence before trying again.'));
		}
		$this->setResponse($response);
	}
}
