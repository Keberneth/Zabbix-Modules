<?php

declare(strict_types=1);

namespace Modules\CapacityPlanning\Actions;

use CController;
use CControllerResponseData;

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
		$this->setResponse(new CControllerResponseData([
			'page_title' => _('Capacity Planning'),
			'data_url' => 'zabbix.php?action=capacity.planning.data',
			// Deep-link / restore state.
			'lookback' => $this->sanitizeLookback((string) $this->getInput('lookback', '')),
			'tab' => $this->sanitizeTab((string) $this->getInput('tab', ''))
		]));
	}

	private function sanitizeLookback(string $lookback): string {
		return in_array($lookback, ['31', '92', '183', '365', '730'], true) ? $lookback : '';
	}

	private function sanitizeTab(string $tab): string {
		return in_array($tab, ['overview', 'disks', 'resources'], true) ? $tab : '';
	}
}
