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
		// Inputs must be declared via validateInput() or getInput() never sees them.
		// All optional; values are sanitized in doAction() before reaching the view.
		$fields = [
			'month' => 'string',
			'from' => 'string',
			'to' => 'string',
			'bucket' => 'string'
		];

		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$this->setResponse(new CControllerResponseData([
			'page_title' => _('Incident Timeline'),
			'data_url' => 'zabbix.php?action=incident.timeline.data',
			// Deep-link / restore state. `month` is kept as a backward-compatible
			// fallback for old bookmarks; `from`/`to`/`bucket` are the new state.
			'month' => $this->sanitizeMonth((string) $this->getInput('month', '')),
			'from' => $this->sanitizeDate((string) $this->getInput('from', '')),
			'to' => $this->sanitizeDate((string) $this->getInput('to', '')),
			'bucket' => $this->sanitizeBucket((string) $this->getInput('bucket', ''))
		]));
	}

	private function sanitizeMonth(string $month): string {
		if (preg_match('/^(\d{4})-(\d{2})$/D', $month, $parts) !== 1) {
			return '';
		}
		return checkdate((int) $parts[2], 1, (int) $parts[1]) ? $month : '';
	}

	private function sanitizeDate(string $date): string {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts) !== 1) {
			return '';
		}
		return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $date : '';
	}

	private function sanitizeBucket(string $bucket): string {
		return in_array($bucket, ['auto', 'day', 'week', 'month'], true) ? $bucket : '';
	}
}
