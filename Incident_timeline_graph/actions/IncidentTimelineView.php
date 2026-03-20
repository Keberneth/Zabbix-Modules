<?php

declare(strict_types=1);

namespace Modules\IncidentTimeline\Actions;

use CController;
use CControllerResponseData;

final class IncidentTimelineView extends CController {
	public function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		return true;
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$month = $this->sanitizeMonth((string) $this->getInput('month', ''));

		$this->setResponse(new CControllerResponseData([
			'page_title' => _('Incident Timeline'),
			'data_url' => 'zabbix.php?action=incident.timeline.data',
			'month' => $month
		]));
	}

	private function sanitizeMonth(string $month): string {
		return preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : '';
	}
}
