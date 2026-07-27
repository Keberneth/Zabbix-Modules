<?php

declare(strict_types=1);

namespace Modules\CapacityPlanning\Actions;

use CController;
use CControllerResponseData;
use Modules\CapacityPlanning\Lib\Config;

require_once dirname(__DIR__).'/lib/Config.php';

final class CapacityPlanningView extends CController {
	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		// Inputs must be declared via validateInput() or getInput() never sees them.
		// All optional; values are sanitized in doAction() before reaching the view.
		$fields = [
			'lookback' => 'string',
			'tab' => 'string'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$cache_settings = Config::cacheSettings();
		$can_manage_settings = $this->getUserType() === USER_TYPE_SUPER_ADMIN;

		$this->setResponse(new CControllerResponseData([
			'page_title' => _('Capacity Planning'),
			'data_url' => 'zabbix.php?action=capacity.planning.data',
			'settings_save_url' => 'zabbix.php?action=capacity.planning.settings.save',
			'cache_status_url' => 'zabbix.php?action=capacity.planning.cache.status',
			'cache_settings' => $cache_settings,
			// Keep ordinary report page loads cheap: the bounded filesystem usage
			// scan is requested lazily only when a Super admin opens Settings.
			'cache_status' => [
				'enabled' => $cache_settings['enabled'],
				'ttl_seconds' => $cache_settings['ttl_seconds'],
				'status_pending' => $can_manage_settings
			],
			'can_manage_settings' => $can_manage_settings,
			'csrf_name' => \CCsrfTokenHelper::CSRF_TOKEN_NAME,
			'csrf_token' => $can_manage_settings
				? \CCsrfTokenHelper::get('capacity.planning.settings.save')
				: '',
			// Proves a forced cache refresh originated from this page; without it
			// the data endpoint silently serves the cached series instead.
			'data_csrf_token' => \CCsrfTokenHelper::get('capacity.planning.data'),
			// Deep-link / restore state.
			'lookback' => $this->sanitizeLookback((string) $this->getInput('lookback', '')),
			'tab' => $this->sanitizeTab((string) $this->getInput('tab', ''))
		]));
	}

	private function sanitizeLookback(string $lookback): string {
		if ($lookback === '' || preg_match('/^\d{1,3}$/', $lookback) !== 1) {
			return '';
		}
		$days = (int) $lookback;
		return $days >= 7 && $days <= 730 ? (string) $days : '';
	}

	private function sanitizeTab(string $tab): string {
		if ($tab === 'resources') {
			return 'cpu';
		}

		return in_array($tab, ['overview', 'disks', 'cpu', 'memory', 'settings'], true) ? $tab : '';
	}
}
