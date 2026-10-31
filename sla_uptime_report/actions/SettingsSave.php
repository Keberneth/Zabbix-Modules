<?php declare(strict_types = 1);

namespace Modules\SlaUptimeReport\Actions;

use CController;
use CControllerResponseData;
use CControllerResponseFatal;
use CControllerResponseRedirect;
use CUrl;
use Modules\SlaUptimeReport\Helpers\ReportSettings;

/** POST only, with Zabbix's default action-bound CSRF validation enabled. */
class SettingsSave extends CController {
	protected function checkInput(): bool {
		$valid = $this->validateInput([
			'settings' => 'required|array', 'revision' => 'required|string', 'form_complete' => 'required|in 1'
		]);
		if (!$valid) { $this->setResponse(new CControllerResponseFatal()); }
		return $valid;
	}

	protected function checkPermissions(): bool { return $this->getUserType() === USER_TYPE_SUPER_ADMIN; }

	protected function doAction(): void {
		try {
			ReportSettings::save($this->getInput('settings'), $this->getInput('revision'));
			$this->setResponse(new CControllerResponseRedirect(
				(new CUrl('zabbix.php'))->setArgument('action', 'slareport.settings.view')->setArgument('saved', 1)
			));
		}
		catch (\InvalidArgumentException $e) {
			$this->showSubmittedSettings($e->getMessage().' '._('Changes were not saved. Your submitted rows have been retained.'));
		}
		catch (\Throwable $e) {
			error_log('SLA Uptime Report settings save: '.$e->getMessage());
			$this->showSubmittedSettings(_('The settings save could not be confirmed. Your submitted entries are shown below. Check the server log and verify the stored settings before trying again.'));
		}
	}

	private function showSubmittedSettings(string $message): void {
		$data = SettingsView::viewData(false, $message);
		try {
			$data['settings'] = ReportSettings::formValues($this->getInput('settings'));
			// Keep the original revision: redisplaying a stale draft must not authorize overwriting newer changes.
			$data['revision'] = $this->getInput('revision');
			$data['unsaved_settings'] = true;
		}
		catch (\InvalidArgumentException $e) {
			$data['error'] .= ' '._('The malformed or oversized submission could not be redisplayed. Stored settings are shown.');
		}
		$this->setResponse(new CControllerResponseData($data));
	}
}
