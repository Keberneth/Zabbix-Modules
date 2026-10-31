<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Actions;

use API;
use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use Modules\SlaUptimeReport\Helpers\ReportSettings;

class SettingsView extends CController {
	public function init(): void { $this->disableCsrfValidation(); }

	protected function checkInput(): bool {
		$valid = $this->validateInput(['saved' => 'in 0,1']);
		if (!$valid) { $this->setResponse(new CControllerResponseFatal()); }
		return $valid;
	}

	protected function checkPermissions(): bool { return $this->getUserType() >= USER_TYPE_ZABBIX_ADMIN; }

	protected function doAction(): void {
		$this->setResponse(new CControllerResponseData(self::viewData((bool) $this->getInput('saved', 0))));
	}

	public static function viewData(bool $saved = false, string $error = ''): array {
		$can_edit = \CWebUser::getType() === USER_TYPE_SUPER_ADMIN;
		$data = ['title' => _('SLA & Uptime Report settings'), 'settings' => ReportSettings::defaults(),
			'groups' => [], 'hosts' => [], 'revision' => '', 'can_edit' => $can_edit,
			'saved' => $saved, 'error' => $error, 'hosts_capped' => false];
		try {
			$snapshot = ReportSettings::snapshot();
			$settings = $snapshot['settings'];
			$groups = API::HostGroup()->get(['output' => ['groupid', 'name'], 'sortfield' => 'name']);
			$hosts = API::Host()->get(['output' => ['hostid', 'name', 'host'], 'sortfield' => 'name', 'limit' => 2001]);
			if (!is_array($groups) || !is_array($hosts)) { throw new \RuntimeException('Settings objects could not be loaded.'); }
			$data['hosts_capped'] = count($hosts) > 2000;
			$hosts = array_slice($hosts, 0, 2000);
			// Keep existing configured hosts available even when they fall beyond the suggestion cap.
			$extra_ids = array_values(array_diff(array_unique(array_merge(
				array_column($settings['hosts'], 'hostid'), $settings['excluded_hostids'])), array_column($hosts, 'hostid')));
			if ($extra_ids !== []) {
				$extra = API::Host()->get(['output' => ['hostid', 'name', 'host'], 'hostids' => $extra_ids]);
				if (!is_array($extra)) { throw new \RuntimeException('Configured hosts could not be loaded.'); }
				$hosts = array_merge($hosts, $extra);
			}
			$groupids = array_map('strval', array_column($groups, 'groupid'));
			$hostids = array_map('strval', array_column($hosts, 'hostid'));
			// A scoped Admin must never receive IDs, names or rules of inaccessible objects.
			if (!$can_edit) {
				$settings['groups'] = array_values(array_filter($settings['groups'],
					static fn(array $rule): bool => in_array($rule['groupid'], $groupids, true)));
				$settings['hosts'] = array_values(array_filter($settings['hosts'],
					static fn(array $rule): bool => in_array($rule['hostid'], $hostids, true)));
				$settings['excluded_groupids'] = array_values(array_intersect($settings['excluded_groupids'], $groupids));
				$settings['excluded_hostids'] = array_values(array_intersect($settings['excluded_hostids'], $hostids));
			}
			$data['settings'] = $settings;
			$data['groups'] = $groups;
			$data['hosts'] = $hosts;
			$data['revision'] = $can_edit ? $snapshot['revision'] : '';
		}
		catch (\Throwable $e) {
			error_log('SLA Uptime Report settings: '.$e->getMessage());
			$data['error'] = _('Report settings could not be loaded. Check the server log before trying again.');
			$data['can_edit'] = false;
		}
		return $data;
	}
}
